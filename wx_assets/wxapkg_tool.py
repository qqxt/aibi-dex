#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
wxapkg_tool.py —— 电脑版微信小程序素材图片提取工具（零依赖，纯 Python 3.8+）

功能
----
1. scan     : 自动发现本机微信（3.x / 4.x，Windows）里缓存的小程序，列出 appid / wxapkg / 缓存图片数
2. unpack   : 解密 V1MMWX 加密的 .wxapkg -> 解析包内文件表 -> 解出全部文件（默认只挑图片）
              同时收割小程序运行时缓存目录里的图片（usr/abby_img_v3、static_icon_v1、temp、store/images ...）
3. images   : 只做"图片清单 + 去重 + 尺寸"导出（manifest.csv / report.json）

wxapkg 解密算法（PC 微信自定义，特征头 "V1MMWX"）
    key      = PBKDF2-HMAC-SHA1(password=appid, salt=b"saltiest", iter=1000, dkLen=32)
    iv       = b"the iv: 16 bytes"
    plain[0:1024]      = AES_256_CBC_decrypt(cipher[6:1030], key, iv)
    plain[1024:]       = cipher[1030:] XOR ord(appid[-2])
    若 appid 长度 < 2，则 XOR key = 0x66

用法
----
    python wxapkg_tool.py scan
    python wxapkg_tool.py unpack                     # 全部小程序，默认输出到 ./out
    python wxapkg_tool.py unpack --appid wxe5fd36fdf4332e8b --out .\out
    python wxapkg_tool.py unpack --all-files          # 连 js/wxml/json 一起解出来
    python wxapkg_tool.py unpack --file a.wxapkg --appid wx1234567890abcdef --out .\out
"""

import argparse
import hashlib
import html as _html
import io
import json
import os
import re
import struct
import sys
import time
import urllib.parse


def html_escape(s):
    return _html.escape(str(s), quote=True)


# ----------------------------------------------------------------------------
# 纯 Python AES-256 解密（只实现 CBC decrypt，够用且不依赖 pycryptodome）
# ----------------------------------------------------------------------------


def _gmul(a, b):
    p = 0
    for _ in range(8):
        if b & 1:
            p ^= a
        hi = a & 0x80
        a = (a << 1) & 0xFF
        if hi:
            a ^= 0x1B
        b >>= 1
    return p


def _rotl8(x, n):
    return ((x << n) | (x >> (8 - n))) & 0xFF


def _build_sbox():
    inv = [0] * 256
    for x in range(1, 256):
        for y in range(1, 256):
            if _gmul(x, y) == 1:
                inv[x] = y
                break
    sbox = [0] * 256
    for x in range(256):
        b = inv[x]
        s = b ^ _rotl8(b, 1) ^ _rotl8(b, 2) ^ _rotl8(b, 3) ^ _rotl8(b, 4) ^ 0x63
        sbox[x] = s
    rsbox = [0] * 256
    for i, v in enumerate(sbox):
        rsbox[v] = i
    return sbox, rsbox


SBOX, RSBOX = _build_sbox()
RCON = [0x01, 0x02, 0x04, 0x08, 0x10, 0x20, 0x40, 0x80, 0x1B, 0x36, 0x6C, 0xD8]
_MUL = {}


def _mul_table(n):
    if n not in _MUL:
        _MUL[n] = [_gmul(i, n) for i in range(256)]
    return _MUL[n]


M9, M11, M13, M14 = _mul_table(9), _mul_table(11), _mul_table(13), _mul_table(14)


def _expand_key(key):
    """AES-256 key schedule -> 60 words(4 bytes each)"""
    nk, nr = 8, 14
    w = [list(key[4 * i:4 * i + 4]) for i in range(nk)]
    for i in range(nk, 4 * (nr + 1)):
        temp = list(w[i - 1])
        if i % nk == 0:
            temp = temp[1:] + temp[:1]
            temp = [SBOX[b] for b in temp]
            temp[0] ^= RCON[i // nk - 1]
        elif i % nk == 4:
            temp = [SBOX[b] for b in temp]
        w.append([w[i - nk][j] ^ temp[j] for j in range(4)])
    return w


def _decrypt_block(block, w):
    s = [list(block[4 * c:4 * c + 4]) for c in range(4)]  # s[c][r]
    for c in range(4):
        for r in range(4):
            s[c][r] ^= w[56 + c][r]
    for rnd in range(13, -1, -1):
        # InvShiftRows
        for r in range(1, 4):
            row = [s[c][r] for c in range(4)]
            row = row[-r:] + row[:-r]
            for c in range(4):
                s[c][r] = row[c]
        # InvSubBytes
        for c in range(4):
            for r in range(4):
                s[c][r] = RSBOX[s[c][r]]
        # AddRoundKey
        for c in range(4):
            for r in range(4):
                s[c][r] ^= w[4 * rnd + c][r]
        if rnd == 0:
            break
        # InvMixColumns
        for c in range(4):
            a0, a1, a2, a3 = s[c]
            s[c][0] = M14[a0] ^ M11[a1] ^ M13[a2] ^ M9[a3]
            s[c][1] = M9[a0] ^ M14[a1] ^ M11[a2] ^ M13[a3]
            s[c][2] = M13[a0] ^ M9[a1] ^ M14[a2] ^ M11[a3]
            s[c][3] = M11[a0] ^ M13[a1] ^ M9[a2] ^ M14[a3]
    out = bytearray(16)
    for c in range(4):
        for r in range(4):
            out[4 * c + r] = s[c][r]
    return bytes(out)


def aes256_cbc_decrypt(key, iv, data):
    if len(data) % 16:
        data = data + b"\x00" * (16 - len(data) % 16)
    w = _expand_key(key)
    out = bytearray()
    prev = iv
    for i in range(0, len(data), 16):
        blk = data[i:i + 16]
        plain = _decrypt_block(blk, w)
        out += bytes(x ^ y for x, y in zip(plain, prev))
        prev = blk
    return bytes(out)


def _selftest_aes():
    key = bytes(range(32))
    pt = bytes.fromhex("00112233445566778899aabbccddeeff")
    # 已知密文（FIPS-197 C.3, AES-256）用 CBC 单块 + 零 IV 构造不方便，这里直接自检解密：
    ct = bytes.fromhex("8ea2b7ca516745bfeafc49904b496089")
    assert _decrypt_block(ct, _expand_key(key)) == pt, "AES-256 self-test failed"
    return True


# ----------------------------------------------------------------------------
# wxapkg 解密 / 解包
# ----------------------------------------------------------------------------

WXAPKG_FLAG = b"V1MMWX"
SALT = b"saltiest"
IV = b"the iv: 16 bytes"


def xor_key_for(appid):
    if appid and len(appid) >= 2:
        return ord(appid[-2])
    return 0x66


MAGICS = (
    b"\x89PNG\r\n\x1a\n", b"\xff\xd8\xff", b"GIF87a", b"GIF89a", b"RIFF",
    b"BM", b"\x00\x00\x01\x00", b"<!DOCTYPE", b"<html", b"<?xml", b"<svg",
)


def _blob_plausible(blob):
    """单个文件体是否像真实文件：已知格式魔数，或可读文本，或二进制但非全 0/全乱"""
    if not blob:
        return True
    for m in MAGICS:
        if blob[:len(m)] == m:
            return True
    head = blob[:64]
    printable = sum(1 for b in head if 9 <= b <= 13 or 32 <= b < 127)
    if printable >= len(head) * 0.9:
        return True
    return False


def _score_plain(buf):
    """给一份候选明文打分：结构合法 + 每个文件体头部像真文件"""
    if len(buf) < 18 or buf[0] != 0xBE or buf[13] != 0xED:
        return -1, None
    try:
        files = parse_wxapkg(buf)
    except Exception:
        return -1, None
    hits = sum(1 for _, b in files if _blob_plausible(b))
    return hits, files


def decrypt_wxapkg(data, appid):
    """返回 (明文, 说明)。非加密包原样返回。

    拼接点有两种流传说法（AES 解出的前 1024 字节取满，还是只取 1023 字节再接
    异或段）。两者开头都一样，差别在于文件体是否整体错位 1 字节，因此这里
    两种都试，用"包内每个文件体头部是否像真文件"来判胜负。
    """
    if data[:6] != WXAPKG_FLAG:
        return data, "plain"
    if not _selftest_aes.called:
        _selftest_aes()
        _selftest_aes.called = True
    key = hashlib.pbkdf2_hmac("sha1", appid.encode("utf-8"), SALT, 1000, 32)
    head = aes256_cbc_decrypt(key, IV, data[6:6 + 1024])
    k = xor_key_for(appid)
    tail = bytes(b ^ k for b in data[6 + 1024:])
    best = None
    for cut in (1023, 1024):
        cand = head[:cut] + tail
        score, files = _score_plain(cand)
        if best is None or score > best[0]:
            best = (score, cut, cand, files)
    score, cut, cand, files = best
    if score <= 0:
        # 结构都对不上，多半是 appid 不对
        return head[:1024] + tail, "decrypted(unverified,xor=0x%02x)" % k
    return cand, "decrypted(cut=%d,xor=0x%02x,score=%d/%d)" % (cut, k, score, len(files))


_selftest_aes.called = False


def parse_wxapkg(buf):
    """解析文件表 -> [(name, bytes)]，自动识别 offset 基准"""
    if len(buf) < 18 or buf[0] != 0xBE or buf[13] != 0xED:
        raise ValueError("not a wxapkg (bad magic)")
    file_count = struct.unpack(">I", buf[14:18])[0]
    off = 18
    entries = []
    for _ in range(file_count):
        if off + 4 > len(buf):
            break
        nlen = struct.unpack(">I", buf[off:off + 4])[0]
        off += 4
        if nlen > 1024 or off + nlen + 8 > len(buf):
            break
        name = buf[off:off + nlen].decode("utf-8", "replace")
        off += nlen
        o, size = struct.unpack(">II", buf[off:off + 8])
        off += 8
        entries.append((name, o, size))
    if not entries:
        raise ValueError("empty file table")
    index_end = off
    # 两种 offset 基准都试一下，选"边界吻合度"最高的
    best = None
    for base in (index_end, 0, 14 + struct.unpack(">I", buf[5:9])[0]):
        ok = 0
        for name, o, size in entries:
            s, e = base + o, base + o + size
            if s >= 0 and e <= len(buf) and size >= 0:
                ok += 1
        score = (ok, -abs((base + entries[-1][1] + entries[-1][2]) - len(buf)))
        if best is None or score > best[0]:
            best = (score, base)
    base = best[1]
    files = []
    for name, o, size in entries:
        s = base + o
        blob = buf[s:s + size]
        files.append((name, blob))
    return files


# ----------------------------------------------------------------------------
# 图片识别 / 尺寸
# ----------------------------------------------------------------------------

IMG_EXT = {".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".bmp", ".ico", ".avif", ".tiff", ".apng"}
TEXT_EXT = {".js", ".wxml", ".wxss", ".json", ".wxs", ".ts", ".map"}
IMG_EXTS = {"png", "jpg", "jpeg", "gif", "webp", "bmp", "ico", "svg", "avif"}


def sniff_image(data):
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if data[:3] == b"\xff\xd8\xff":
        return "jpg"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data[:2] == b"BM":
        return "bmp"
    if data[:4] == b"\x00\x00\x01\x00":
        return "ico"
    head = data[:512].lstrip()
    if head[:5].lower() == b"<?xml" or head[:4].lower() == b"<svg":
        if b"<svg" in data[:2048].lower():
            return "svg"
    if data[:4] == b"RIFF" and data[8:12] == b"AVIF":
        return "avif"
    return None


def image_size(data, kind=None):
    try:
        if data[:8] == b"\x89PNG\r\n\x1a\n":
            return struct.unpack(">II", data[16:24])
        if kind == "gif" or data[:6] in (b"GIF87a", b"GIF89a"):
            w, h = struct.unpack("<HH", data[6:10])
            return w, h
        if data[:2] == b"\xff\xd8":
            i = 2
            while i < len(data) - 9:
                if data[i] != 0xFF:
                    i += 1
                    continue
                m = data[i + 1]
                if m in (0xD8, 0x01) or 0xD0 <= m <= 0xD7:
                    i += 2
                    continue
                seg = struct.unpack(">H", data[i + 2:i + 4])[0]
                if m in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
                    h, w = struct.unpack(">HH", data[i + 5:i + 9])
                    return w, h
                i += 2 + seg
            return None
        if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
            fmt = data[12:16]
            if fmt == b"VP8X":
                w = 1 + int.from_bytes(data[24:27], "little")
                h = 1 + int.from_bytes(data[27:30], "little")
                return w, h
            if fmt == b"VP8 ":
                w, h = struct.unpack("<HH", data[26:30])
                return w & 0x3FFF, h & 0x3FFF
            if fmt == b"VP8L":
                b = int.from_bytes(data[21:25], "little")
                return (b & 0x3FFF) + 1, ((b >> 14) & 0x3FFF) + 1
            return None
        if data[:2] == b"BM":
            w, h = struct.unpack("<ii", data[18:26])
            return abs(w), abs(h)
        # svg
        txt = data[:4096].decode("utf-8", "replace")
        m = re.search(r'<svg[^>]*width="([\d.]+)', txt)
        m2 = re.search(r'<svg[^>]*height="([\d.]+)', txt)
        if m and m2:
            return int(float(m.group(1))), int(float(m2.group(1)))
        m3 = re.search(r'viewBox="[\d.\-]+[ ,]+[\d.\-]+[ ,]+([\d.]+)[ ,]+([\d.]+)"', txt)
        if m3:
            return int(float(m3.group(1))), int(float(m3.group(2)))
    except Exception:
        return None
    return None


# ----------------------------------------------------------------------------
# 发现本机小程序缓存
# ----------------------------------------------------------------------------

APPLET_ROOTS = [
    os.path.expandvars(r"%APPDATA%\Tencent\xwechat\radium\users"),      # 微信 4.x
    os.path.expandvars(r"%APPDATA%\Tencent\WeChat\radium\users"),
    os.path.expandvars(r"%USERPROFILE%\Documents\WeChat Files"),        # 微信 3.x
    os.path.expandvars(r"%USERPROFILE%\Documents\xwechat_files"),
]


def find_applet_dirs(override=None):
    """返回 [applet_dir, ...]；override 指定时直接用它"""
    if override:
        o = os.path.abspath(os.path.expanduser(override))
        if not os.path.isdir(o):
            raise SystemExit("指定的 --applet-root 不存在或不是目录: %s" % o)
        return [o]
    found = []
    for root in APPLET_ROOTS:
        if not os.path.isdir(root):
            continue
        for dirpath, dirnames, _ in os.walk(root):
            for d in list(dirnames):
                if d.lower() == "applet":
                    found.append(os.path.join(dirpath, d))
            if len(dirpath) - len(root) > 120:
                dirnames[:] = []
    # 去重
    seen, out = set(), []
    for p in found:
        rp = os.path.realpath(p)
        if rp not in seen:
            seen.add(rp)
            out.append(p)
    return out


APPID_RE = re.compile(r"^wx[0-9a-zA-Z]{8,20}$")


def discover(override=None):
    """扫描出所有小程序：{appid: {pkgs: [...], cache: applet_dir, data_dirs: [...]}}"""
    apps = {}
    for applet in find_applet_dirs(override):
        for dirpath, _, files in os.walk(applet):
            for fn in files:
                if not fn.lower().endswith(".wxapkg"):
                    continue
                path = os.path.join(dirpath, fn)
                appid = None
                p = dirpath
                for _ in range(5):
                    if APPID_RE.match(os.path.basename(p)):
                        appid = os.path.basename(p)
                        break
                    p = os.path.dirname(p)
                if appid is None:
                    appid = "publicLib" if "publiclib" in path.lower() else "unknown"
                e = apps.setdefault(appid, {"appid": appid, "applet": applet, "pkgs": [], "cache_dirs": []})
                e["pkgs"].append(path)
        # 运行时缓存目录（图片真正落盘的地方）
        local = os.path.join(applet, "local")
        if os.path.isdir(local):
            for name in os.listdir(local):
                d = os.path.join(local, name)
                if os.path.isdir(d) and APPID_RE.match(name):
                    apps.setdefault(name, {"appid": name, "applet": applet, "pkgs": [], "cache_dirs": []})
                    apps[name]["cache_dirs"].append(d)
    return apps


CACHE_IMG_DIRS = ("usr", "temp", "store", "images", "img", "avatar")


def harvest_cache(cache_dir, out_root, manifest, dedupe, appid):
    """递归收割运行时缓存里的图片文件（按 magic 判定，不认扩展名）

    temp/ 里是同一张图的临时副本，usr/ 下才是稳定命名的那份，所以先去重
    temp：排序时把 usr/、static_icon/ 这些稳定目录排在前面，重复项映射到它们。
    """
    n = 0
    pool = []
    for dirpath, _, files in os.walk(cache_dir):
        for fn in files:
            src = os.path.join(dirpath, fn)
            rel = os.path.relpath(src, cache_dir).replace("\\", "/")
            pool.append((rel, src))

    def rank(rel):
        low = rel.lower()
        if low.startswith("usr/") and "temp" not in low:
            return 0
        if "temp" in low or low.startswith("tmp"):
            return 2
        return 1

    pool.sort(key=lambda t: (rank(t[0]), t[0]))
    for rel, src in pool:
        try:
            with open(src, "rb") as f:
                data = f.read()
        except OSError:
            continue
        kind = sniff_image(data)
        if not kind or len(data) < 64:
            continue
        # 缓存里有不带扩展名的文件（store/images/<hash>），浏览器认不出类型，补一个
        if os.path.splitext(rel)[1].lower().lstrip(".") not in IMG_EXTS:
            rel = rel + "." + kind
        dest = os.path.join(out_root, "cache", rel)
        digest = hashlib.sha256(data).hexdigest()
        if digest in dedupe:
            manifest.append(dict(appid=appid, source="cache", src=rel, out=dedupe[digest],
                                 ext=kind, bytes=len(data), sha256=digest, dup_of=dedupe[digest]))
            continue
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        with open(dest, "wb") as f:
            f.write(data)
        size = image_size(data, kind)
        entry = dict(appid=appid, source="cache", src=rel, out=dest, ext=kind,
                     bytes=len(data), sha256=digest, w=size[0] if size else None,
                     h=size[1] if size else None)
        manifest.append(entry)
        dedupe[digest] = dest
        n += 1
    return n


def safe_join(root, rel):
    rel = rel.replace("\\", "/").lstrip("/")
    rel = re.sub(r"^[A-Za-z]:", "", rel)
    parts = [p for p in rel.split("/") if p not in ("", ".", "..")]
    return os.path.join(root, *parts) if parts else None


def unpack_file(path, appid, out_root, manifest, dedupe, all_files=False):
    with open(path, "rb") as f:
        raw = f.read()
    plain, how = decrypt_wxapkg(raw, appid)
    files = parse_wxapkg(plain)
    base_name = os.path.splitext(os.path.basename(path))[0].strip("_") or "pkg"
    pkg_root = os.path.join(out_root, "pkg", base_name)
    n_img = n_all = 0
    for name, blob in files:
        ext = os.path.splitext(name)[1].lower()
        kind = sniff_image(blob)
        is_img = bool(kind) or ext in IMG_EXT
        if not (is_img or all_files):
            continue
        n_all += 1
        dest = safe_join(pkg_root, name)
        if not dest:
            continue
        if not ext and kind:
            dest += "." + kind
        digest = hashlib.sha256(blob).hexdigest()
        if not is_img:
            # 非图片（js/json/wxml）只落地，不进 manifest.csv
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            with open(dest, "wb") as f:
                f.write(blob)
            continue
        if digest in dedupe:
            manifest.append(dict(appid=appid, source="pkg:" + base_name, src=name,
                                 out=dedupe[digest], ext=kind or ext.lstrip("."),
                                 bytes=len(blob), sha256=digest, dup_of=dedupe[digest]))
            continue
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        with open(dest, "wb") as f:
            f.write(blob)
        size = image_size(blob, kind) if is_img else None
        manifest.append(dict(appid=appid, source="pkg:" + base_name, src=name, out=dest,
                             ext=kind or ext.lstrip("."), bytes=len(blob), sha256=digest,
                             w=size[0] if size else None, h=size[1] if size else None))
        dedupe[digest] = dest
        if is_img:
            n_img += 1
    return how, len(files), n_img, n_all


def cmd_scan(args):
    apps = discover(getattr(args, "applet_root", None))
    if not apps:
        print("未发现小程序缓存。请先在电脑版微信里打开一次目标小程序。")
        return 1
    print("%-24s %-6s %-10s %s" % ("APPID", "包数", "包体积", "运行时缓存"))
    print("-" * 100)
    for appid, info in sorted(apps.items()):
        total = 0
        for p in info["pkgs"]:
            try:
                total += os.path.getsize(p)
            except OSError:
                pass
        ncach = 0
        for d in info["cache_dirs"]:
            for _, _, fl in os.walk(d):
                ncach += len(fl)
        cache = ("%d 个文件" % ncach) if info["cache_dirs"] else "-"
        print("%-24s %-6d %-10s %s" % (appid, len(info["pkgs"]), human(total), cache))
        for p in info["pkgs"]:
            print("    %10s  %s" % (human(os.path.getsize(p)), p))
    return 0


def human(n):
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return "%.1f%s" % (n, unit) if unit != "B" else "%d%s" % (n, unit)
        n /= 1024.0


def cmd_unpack(args):
    out_root = os.path.abspath(args.out)
    os.makedirs(out_root, exist_ok=True)
    manifest, dedupe = [], {}
    targets = []
    if args.file:
        appid = args.appid or "unknown"
        targets.append((appid, [os.path.abspath(args.file)], []))
    else:
        apps = discover(getattr(args, "applet_root", None))
        for appid, info in apps.items():
            if args.appid and appid != args.appid:
                continue
            if not info["pkgs"] and not info["cache_dirs"]:
                continue
            targets.append((appid, info["pkgs"], info["cache_dirs"]))
        if not targets:
            print("没有匹配的目标。先跑 `python wxapkg_tool.py scan` 看看有哪些 appid。")
            return 1
    summary = []
    for appid, pkgs, caches in targets:
        app_out = os.path.join(out_root, appid)
        n_img = n_all = n_cache = 0
        pkg_notes = []
        for p in pkgs:
            try:
                how, total, imgs, taken = unpack_file(p, appid, app_out, manifest, dedupe, args.all_files)
                n_img += imgs
                n_all += total
                pkg_notes.append("%s -> %s, %d 个文件, 图片 %d" %
                                 (os.path.basename(p), how, total, imgs))
            except Exception as e:
                pkg_notes.append("%s -> 失败: %s" % (os.path.basename(p), e))
        for c in caches:
            n_cache += harvest_cache(c, app_out, manifest, dedupe, appid)
        summary.append(dict(appid=appid, pkg_images=n_img, cache_images=n_cache,
                            pkg_files=n_all, notes=pkg_notes))
        print("[%s] 包内图片 %d，缓存图片 %d" % (appid, n_img, n_cache))
        for note in pkg_notes:
            print("    " + note)
    rows = [m for m in manifest if not m.get("dup_of")]
    dups = len(manifest) - len(rows)
    with open(os.path.join(out_root, "manifest.csv"), "w", encoding="utf-8-sig", newline="") as f:
        f.write("appid,source,src,out,ext,bytes,w,h,sha256\n")
        for m in manifest:
            f.write("%s,%s,%s,%s,%s,%s,%s,%s,%s\n" % (
                m["appid"], m["source"], csv_safe(m["src"]), csv_safe(m.get("out", "")),
                m["ext"], m["bytes"], m.get("w", ""), m.get("h", ""), m["sha256"]))
    with open(os.path.join(out_root, "report.json"), "w", encoding="utf-8") as f:
        json.dump(dict(generated=time.strftime("%Y-%m-%d %H:%M:%S"), output=out_root,
                       unique_images=len(rows), duplicates_removed=dups,
                       total_entries=len(manifest), apps=summary), f, ensure_ascii=False, indent=2)
    print("\n唯一图片 %d 张，去重 %d 张；清单: %s" % (len(rows), dups, os.path.join(out_root, "manifest.csv")))
    print("报告: %s" % os.path.join(out_root, "report.json"))
    return 0


def cmd_gallery(args):
    """把已抽出的图片生成一个可筛选的本地网页画廊（index.html）"""
    out_root = os.path.abspath(args.out)
    man = os.path.join(out_root, "manifest.csv")
    if not os.path.isfile(man):
        print("找不到 %s，先跑 unpack" % man)
        return 1
    rows = []
    import csv as _csv
    with open(man, encoding="utf-8-sig") as f:
        for r in _csv.DictReader(f):
            if r.get("dup_of") or not r.get("out"):
                continue
            try:
                if os.path.getsize(r["out"]) == 0:
                    continue
            except OSError:
                continue
            rows.append(r)
    seen, uniq = set(), []
    for r in rows:
        if r["out"] in seen:
            continue
        seen.add(r["out"])
        uniq.append(r)
    cards = []
    for r in uniq:
        src = os.path.relpath(r["out"], out_root).replace("\\", "/")
        # 浏览器会对 src 做一次 percent-decoding，所以这里必须先编码（中文名/空格/#/% 才不会被吃）
        url = urllib.parse.quote(src, safe="/")
        w, h = r.get("w") or "", r.get("h") or ""
        size = int(r["bytes"])
        label = os.path.basename(r["src"])
        cards.append(
            '<figure class="c" data-k="%s" data-w="%s" data-app="%s" data-src="%s">'
            '<a href="%s" target="_blank"><img loading="lazy" src="%s" alt="%s"></a>'
            '<figcaption><b>%s</b><br>%s×%s · %s KB<br><span class="p">%s</span></figcaption></figure>'
            % (html_escape((r["appid"] + " " + label).lower()), w or 0, r["appid"],
               html_escape(r["source"]), url, url, html_escape(label),
               html_escape(label), w or "?", h or "?", round(size / 1024, 1), html_escape(src)))
    html = """<!doctype html><html lang="zh-CN"><meta charset="utf-8">
<title>小程序素材 · %d 张</title>
<style>
body{margin:0;background:#12141a;color:#e8eaf0;font:14px/1.5 system-ui,"Microsoft YaHei",sans-serif}
header{position:sticky;top:0;z-index:9;background:#1b1f28;padding:12px 18px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;border-bottom:1px solid #2b3140}
h1{font-size:16px;margin:0 8px 0 0;font-weight:600}
input,select{background:#252b36;border:1px solid #333c4c;color:#e8eaf0;padding:6px 10px;border-radius:6px;font-size:13px}
main{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;padding:18px}
figure.c{margin:0;background:#1b1f28;border:1px solid #262d3a;border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
figure.c img{width:100%%;height:150px;object-fit:contain;background:repeating-conic-gradient(#2a3140 0%% 25%%,#232936 0%% 50%%) 50%%/16px 16px;display:block}
figcaption{font-size:11px;color:#9aa4b6;padding:7px 9px;line-height:1.45;overflow:hidden;text-overflow:ellipsis}
figcaption b{color:#dfe4ee;font-weight:600}
.p{color:#5f6b80;word-break:break-all}
#n{color:#8b94a7;font-size:12px}
</style>
<header><h1>小程序素材图片</h1>
<input id="q" placeholder="过滤文件名 / 路径" size="26">
<select id="app"><option value="">全部小程序</option>%s</select>
<select id="min"><option value="0">任意尺寸</option><option value="32">≥32px</option><option value="100">≥100px</option><option value="200">≥200px</option></select>
<label><input type="checkbox" id="photo"> 只看照片类(≥100KB)</label>
<span id="n"></span></header>
<main id="g">%s</main>
<script>
const q=document.getElementById('q'),app=document.getElementById('app'),mn=document.getElementById('min'),
      ph=document.getElementById('photo'),cards=[...document.querySelectorAll('.c')],n=document.getElementById('n');
function apply(){const s=q.value.trim().toLowerCase(),a=app.value,min=+mn.value,onlyPhoto=ph.checked;let c=0;
 cards.forEach(el=>{const k=el.dataset.k,w=+el.dataset.w,bytes=+el.querySelector('figcaption').textContent.match(/([\\d.]+) KB/)[1];
  const ok=(!s||k.includes(s)||el.dataset.src.toLowerCase().includes(s))&&(!a||el.dataset.app===a)&&w>=min&&(!onlyPhoto||bytes>=100);
  el.style.display=ok?'':'none';if(ok)c++;});n.textContent=c+' / '+cards.length+' 张';}
q.oninput=app.onchange=mn.onchange=ph.onchange=apply;apply();
</script></html>""" % (len(uniq),
                        "".join('<option value="%s">%s</option>' % (a, a) for a in sorted({r["appid"] for r in uniq})),
                        "".join(cards))
    dest = os.path.join(out_root, "index.html")
    with open(dest, "w", encoding="utf-8") as f:
        f.write(html)
    print("画廊已生成: %s（%d 张，双击用浏览器打开）" % (dest, len(uniq)))
    return 0


def csv_safe(s):
    s = str(s)
    if any(ch in s for ch in ',"\n'):
        return '"' + s.replace('"', '""') + '"'
    return s


IMG_REF_RE = re.compile(r"[^\s\"'()\[\],;<>]+?\.(?:png|jpe?g|gif|webp|svg)")
REMOTE_RE = re.compile(r"https?://[^\s\"'()<>]+?\.(?:png|jpe?g|gif|webp|svg)", re.I)
TEXT_SUFFIX = (".js", ".json", ".wxml", ".wxss", ".wxs", ".html")


def _text_files(root):
    for dp, _, fs in os.walk(root):
        for f in fs:
            if f.lower().endswith(TEXT_SUFFIX):
                yield os.path.join(dp, f)


def _read_manifest(out_root):
    man = os.path.join(out_root, "manifest.csv")
    if not os.path.isfile(man):
        return []
    import csv as _csv
    with open(man, encoding="utf-8-sig") as f:
        return list(_csv.DictReader(f))


COLUMNS = ["appid", "source", "src", "out", "ext", "bytes", "w", "h", "sha256"]


def _write_manifest(out_root, rows):
    with open(os.path.join(out_root, "manifest.csv"), "w", encoding="utf-8-sig", newline="") as f:
        f.write(",".join(COLUMNS) + "\n")
        for r in rows:
            f.write("%s,%s,%s,%s,%s,%s,%s,%s,%s\n" % tuple(
                csv_safe(r.get(k, "")) if k in ("src", "out") else r.get(k, "") for k in COLUMNS))


def cmd_remote(args):
    """从解出来的 js/json/wxss 里找远端图片 URL，直接下载补齐（CDN 上的素材不在包里）"""
    import urllib.request
    out_root = os.path.abspath(args.out)
    rows = _read_manifest(out_root)
    have = {r["sha256"]: r["out"] for r in rows if r.get("sha256")}
    apps = [args.appid] if args.appid else sorted(
        d for d in os.listdir(out_root) if os.path.isdir(os.path.join(out_root, d, "pkg")))
    total = 0
    for appid in apps:
        pkg_root = os.path.join(out_root, appid, "pkg")
        if not os.path.isdir(pkg_root):
            print("[%s] 没有 pkg/ 目录（先 unpack --all-files）" % appid)
            continue
        urls = set()
        for p in _text_files(pkg_root):
            try:
                txt = io.open(p, encoding="utf-8", errors="replace").read()
            except OSError:
                continue
            urls.update(REMOTE_RE.findall(txt))
        urls = sorted({u.rstrip(".,;)") for u in urls})
        if not urls:
            print("[%s] 代码里没有远端图片 URL" % appid)
            continue
        dest_dir = os.path.join(out_root, appid, "remote")
        os.makedirs(dest_dir, exist_ok=True)
        ok = dup = fail = 0
        for u in urls:
            name = u.split("?")[0].rstrip("/").split("/")[-1] or "image"
            try:
                req = urllib.request.Request(u, headers={"User-Agent": "Mozilla/5.0"})
                data = urllib.request.urlopen(req, timeout=20).read()
            except Exception as e:
                print("    FAIL %s (%s)" % (u, repr(e)[:60]))
                fail += 1
                continue
            kind = sniff_image(data)
            if not kind:
                print("    跳过（不是图片）%s" % u)
                fail += 1
                continue
            sha = hashlib.sha256(data).hexdigest()
            if sha in have:
                print("    重复 %s -> %s" % (name, os.path.basename(have[sha])))
                dup += 1
                continue
            dest = os.path.join(dest_dir, name)
            with open(dest, "wb") as f:
                f.write(data)
            sz = image_size(data, kind) or (None, None)
            rows.append(dict(appid=appid, source="remote", src=u, out=dest, ext=kind,
                             bytes=len(data), w=sz[0], h=sz[1], sha256=sha))
            have[sha] = dest
            print("    OK   %-22s %6d B  %sx%s" % (name, len(data), sz[0], sz[1]))
            ok += 1
        total += ok
        print("[%s] 远端图片 %d 个: 新下载 %d，重复 %d，失败 %d" % (appid, len(urls), ok, dup, fail))
    if total:
        _write_manifest(out_root, rows)
        print("\n已写入 manifest.csv，新增 %d 张；跑 `gallery` 刷新画廊" % total)
    return 0


def cmd_check(args):
    """体检：报告有没有漏（未下载的分包 / 代码里引用了但本地没有的图）"""
    out_root = os.path.abspath(args.out)
    rows = _read_manifest(out_root)
    apps = [args.appid] if args.appid else sorted(
        d for d in os.listdir(out_root) if os.path.isdir(os.path.join(out_root, d, "pkg")))
    for appid in apps:
        pkg_root = os.path.join(out_root, appid, "pkg")
        if not os.path.isdir(pkg_root):
            print("[%s] 没有 pkg/ 目录（先 unpack --all-files）" % appid)
            continue
        rels = set()
        for dp, _, fs in os.walk(pkg_root):
            for f in fs:
                rels.add(os.path.relpath(os.path.join(dp, f), pkg_root).replace("\\", "/").lower())
        top = {p.split("/")[0].lower() for p in rels}
        print("== %s ==" % appid)
        print("  包内文件 %d 个；清单里该小程序图片 %d 张" % (
            len(rels), len({r["out"] for r in rows if r["appid"] == appid})))
        # 1) 分包完整性（按解出来的顶层目录判断，比找字符串可靠）
        cfg_path = None
        for dp, _, fs in os.walk(pkg_root):
            for f in fs:
                if f.lower() in ("app-config.json", "app.json"):
                    cfg_path = os.path.join(dp, f)
                    break
            if cfg_path:
                break
        if cfg_path:
            try:
                cfg = json.loads(io.open(cfg_path, encoding="utf-8", errors="replace").read())
            except Exception:
                cfg = {}
            decl = [str(x.get("root", "")).strip("/") for x in
                    (cfg.get("subPackages") or cfg.get("subpackages") or [])]
            if decl:
                miss = []
                for d in decl:
                    if not d:
                        continue
                    cand = {d.replace("/", "_").lower(), d.replace("/", "").lower(),
                            d.split("/")[0].lower()}
                    if not (cand & top) and not any(p.startswith(d.lower() + "/") for p in rels):
                        miss.append(d)
                print("  分包：声明 %d 个，本地已下载 %d 个" % (len(decl), len(decl) - len(miss)))
                if miss:
                    print("  分包缺失（在小程序里打开对应页面后微信才会下载）：")
                    for i in range(0, len(miss), 3):
                        print("      " + "  ".join("%-22s" % m for m in miss[i:i + 3]))
        # 2) 本地图片引用是否齐全（过滤模板串 / 非 ASCII 的说明文字）
        refs, missing = set(), set()
        for p in _text_files(pkg_root):
            txt = io.open(p, encoding="utf-8", errors="replace").read()
            refs.update(IMG_REF_RE.findall(txt))
        clean = set()
        for r in refs:
            r = r.strip().lstrip("/")
            if r.lower().startswith(("http", "data:")):
                continue
            if "$" in r or "{" in r or "?" in r or not r.isascii():
                continue
            if not re.match(r"^[\w\-./]+\.(?:png|jpe?g|gif|webp|svg)$", r, re.I):
                continue
            clean.add(r)
        for r in clean:
            base = os.path.basename(r).lower()
            if r.lower() in rels:
                continue
            if any(x == base or x.endswith("/" + base) for x in rels):
                continue
            missing.add(r)
        print("  代码引用的本地图片 %d 个，本地找不到 %d 个" % (len(clean), len(missing)))
        for m in sorted(missing)[:20]:
            print("      MISS %s" % m)
    return 0


def main(argv=None):
    ap = argparse.ArgumentParser(description="电脑版微信小程序 wxapkg 素材图片提取（零依赖）")
    sub = ap.add_subparsers(dest="cmd")

    sc = sub.add_parser("scan", help="列出本机缓存的小程序")
    sc.add_argument("--applet-root", help="手动指定 applet 缓存目录")

    up = sub.add_parser("unpack", help="解密解包并抽取图片")
    up.add_argument("--applet-root", help="手动指定 applet 缓存目录")
    up.add_argument("--appid", help="只处理指定 appid")
    up.add_argument("--file", help="直接指定一个 wxapkg 文件")
    up.add_argument("--out", default="out", help="输出目录（默认 ./out）")
    up.add_argument("--all-files", action="store_true", help="连同 js/wxml/json 一起解出")

    gal = sub.add_parser("gallery", help="为已抽出的图片生成本地网页画廊")
    gal.add_argument("--out", default="out", help="unpack 的输出目录")

    rm = sub.add_parser("remote", help="下载代码里引用的远端 CDN 图片（包里没有的那些）")
    rm.add_argument("--out", default="out", help="unpack 的输出目录")
    rm.add_argument("--appid", help="只处理指定 appid")

    ck = sub.add_parser("check", help="体检：未下载的分包 / 引用了但本地缺失的图")
    ck.add_argument("--out", default="out", help="unpack 的输出目录")
    ck.add_argument("--appid", help="只处理指定 appid")

    args = ap.parse_args(argv)
    if args.cmd == "scan" or not args.cmd:
        return cmd_scan(args)
    if args.cmd == "unpack":
        return cmd_unpack(args)
    if args.cmd == "gallery":
        return cmd_gallery(args)
    if args.cmd == "remote":
        return cmd_remote(args)
    if args.cmd == "check":
        return cmd_check(args)
    ap.print_help()
    return 2


if __name__ == "__main__":
    sys.exit(main())
