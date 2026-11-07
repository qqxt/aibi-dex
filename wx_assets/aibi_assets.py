#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
aibi_assets.py —— 「艾比手册」小程序（appid wxe5fd36fdf4332e8b）图片全量补齐

包内图片由 wxapkg_tool.py unpack 解出；但立绘 / 属性图标 / 阵容类型 / 道具图
是运行时从 CDN 拉的，不在包里。这个脚本从解出来的代码里把 CDN 地址规则和
艾比清单（AIBI_CATALOG，340 条）挖出来，然后把它们全部下载到本地。

用法：
    python aibi_assets.py                 # 补齐全部 CDN 图片（跳过已存在的）
    python aibi_assets.py --only abby     # 只补立绘
    python aibi_assets.py --dry-run       # 只打印将要下载的 URL 清单

下载目录：out\\<appid>\\cdn\\...，并追加进 out\\manifest.csv（source=cdn）
"""
import argparse
import concurrent.futures as futures
import csv
import hashlib
import io
import json
import os
import re
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import wxapkg_tool as T  # 复用 sniff_image / image_size / manifest 读写

APPID = "wxe5fd36fdf4332e8b"
ORIGIN = "https://api.i425.com.cn"
UA = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                    "(KHTML, like Gecko) Chrome/120 Safari/537.36"}
# 属性 -> 英文码（来自 aibi-element.js 里的 {"草":"cao", ...}）
ELEMENT_CODE = {"草": "cao", "水": "shui", "火": "huo", "电": "dian", "岩": "yan",
                "普": "pu", "龙": "long", "光": "guang", "暗": "an", "超": "chao"}
LINEUP_TYPES = ["刺客", "守卫", "射手", "战士", "辅助", "输出"]
# 名字别名：页面里可能写成左值，真实静态文件用右值
ALIASES = {"花梨朵": "花莉朵", "椰子鹅鹅": "椰子鹈鹕", "焦虑鸭": "蕉绿鸭", "锅凯龟": "锅铠龟",
           "美源螺螺": "美螺螈螈", "锤头鹅": "锤头獾", "橘福猫": "橘蝠猫"}


def load_code(out_root):
    app_dir = os.path.join(out_root, APPID)
    p = os.path.join(app_dir, "pkg", "APP", "app-service.js")
    if not os.path.isfile(p):
        raise SystemExit("找不到 %s，先跑：python wxapkg_tool.py unpack --appid %s --all-files"
                         % (p, APPID))
    return io.open(p, encoding="utf-8", errors="replace").read()


def parse_catalog(code):
    """从打包后的 bundle 里还原 AIBI_CATALOG：[{name, element, elementSecondary}]"""
    rows, seen = [], set()
    for name, el, el2 in re.findall(
            r'name:"([^"]{1,14})",element:"([^"]{1,6})"(?:,elementSecondary:"([^"]{1,6})")?', code):
        if name in seen:
            continue
        seen.add(name)
        rows.append(dict(name=name, element=el, elementSecondary=el2 or None))
    return rows


def build_targets(catalog):
    """生成 (kind, url, local_relpath) 列表"""
    out = []
    for a in catalog:
        nm, el = a["name"], a["element"]
        rel = "abby/abby_%s_%s.png" % (el, nm)
        out.append(("abby", ORIGIN + "/aibi/static/" + urllib.parse.quote(rel), rel, nm))
    for el, code in ELEMENT_CODE.items():
        rel = "shuxing/%s.png" % code
        out.append(("shuxing", ORIGIN + "/aibi/static/" + rel, rel, el))
    for t in LINEUP_TYPES:
        # 本地文件名保持原样中文（刺客.png），percent-encoding 只用于 URL
        rel = "lineup-type/%s.png" % t
        out.append(("lineup", ORIGIN + "/aibi/static/" + urllib.parse.quote(rel), rel, t))
    return out


def fetch(url, timeout=25):
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def rebuild_manifest(out_root):
    """按磁盘实际文件重写 manifest.csv（pkg / cache / remote / cdn 四类来源）"""
    app_dir = os.path.join(out_root, APPID)
    pkg_dir = os.path.join(app_dir, "pkg")
    rows = []
    for dp, _, fs in os.walk(app_dir):
        for f in sorted(fs):
            p = os.path.join(dp, f)
            if os.path.splitext(f)[1].lower().lstrip(".") not in T.IMG_EXTS:
                continue
            rel = os.path.relpath(p, app_dir).replace("\\", "/")
            parts = rel.split("/")
            if parts[0] == "pkg":
                source = "pkg:" + (parts[1] if len(parts) > 1 else "pkg")
                src = "/".join(parts[2:])
            elif parts[0] in ("cache", "remote", "cdn"):
                source = parts[0]
                src = "/".join(parts[2:]) if parts[0] == "cache" else "/".join(parts[1:])
            else:
                source, src = "other", rel
            data = io.open(p, "rb").read()
            kind = T.sniff_image(data) or os.path.splitext(f)[1].lstrip(".").lower()
            sz = T.image_size(data, kind) or (None, None)
            rows.append(dict(appid=APPID, source=source, src=src, out=p, ext=kind,
                             bytes=len(data), w=sz[0], h=sz[1],
                             sha256=hashlib.sha256(data).hexdigest()))
    T._write_manifest(out_root, rows)
    return rows


def one(job, dest_root, dry):
    kind, url, rel, name = job
    dest = os.path.join(dest_root, rel.replace("/", os.sep))
    if os.path.isfile(dest) and os.path.getsize(dest) > 0:
        return ("skip", rel, 0)
    if dry:
        return ("dry", url, 0)
    last = None
    for attempt in range(3):
        try:
            data = fetch(url)
            break
        except Exception as e:
            last = e
            data = None
    if data is None:
        return ("fail", "%s (%s)" % (url, repr(last)[:70]), 0)
    if not T.sniff_image(data):
        return ("notimg", url, 0)
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    with open(dest, "wb") as f:
        f.write(data)
    return ("ok", rel, len(data))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="out")
    ap.add_argument("--only", choices=["abby", "shuxing", "lineup", "all"], default="all")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--workers", type=int, default=8)
    args = ap.parse_args()

    out_root = os.path.abspath(args.out)
    app_dir = os.path.join(out_root, APPID)
    code = load_code(out_root)
    catalog = parse_catalog(code)
    print("清单解析：AIBI_CATALOG %d 条" % len(catalog))
    jobs = build_targets(catalog)
    if args.only != "all":
        jobs = [j for j in jobs if j[0] == args.only]
    print("待处理 URL: %d 个（%s）" % (len(jobs), "dry-run" if args.dry_run else "下载"))

    dest_root = os.path.join(app_dir, "cdn")
    results = []
    with futures.ThreadPoolExecutor(max_workers=args.workers) as ex:
        futs = [ex.submit(one, j, dest_root, args.dry_run) for j in jobs]
        for i, f in enumerate(futures.as_completed(futs), 1):
            results.append(f.result())
            if i % 40 == 0:
                print("  ... %d/%d" % (i, len(jobs)))

    ok = [r for r in results if r[0] == "ok"]
    skip = [r for r in results if r[0] == "skip"]
    fails = [r for r in results if r[0] not in ("ok", "skip", "dry")]
    print("\n成功 %d，已存在跳过 %d，失败 %d" % (len(ok), len(skip), len(fails)))
    for r in fails[:15]:
        print("   FAIL %s" % r[1])

    if args.dry_run:
        for r in results[:5]:
            print("   ", r[1])
        return 0

    if ok or skip:
        rows = rebuild_manifest(out_root)
        print("manifest.csv 已按磁盘实际文件重建：%d 张" % len(rows))

    # 汇总
    total = 0
    for dp, _, fs in os.walk(app_dir):
        for f in fs:
            if os.path.splitext(f)[1].lower().lstrip(".") in T.IMG_EXTS:
                total += 1
    print("该小程序现有图片文件合计：%d 张" % total)
    return 0


if __name__ == "__main__":
    sys.exit(main())
