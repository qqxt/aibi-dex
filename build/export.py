#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
export.py —— 把「艾比手册」小程序里的资料汇总成 data/aibi.json，供站点导入 SQLite

为什么要抓一次：
    小程序把大量资料写死在 app-service.js 里（主表/信物/资质成长），另有一部分
    只存在于官方接口。这里一次性取下来落成 json + 本地图片，站点运行时只读
    本地 SQLite，不再访问任何外部地址。

数据来源
    本地打包（离线，随时可重跑）
        pkg\\APP\\app-service.js
            exports.AIBI_CATALOG           170 条：名字 / 属性 / 稀有度 / 定位 / 技能 / 主词条
            exports.TOKENS                 170 条：信物名 / 获取提示 / 详解 / 类别 / 喜欢讨厌糖果
            exports.AIBI_APTITUDE_GROWTH   171 条：五维资质成长（Lv.20，部分带实测 Lv.0）
        pkg\\APP\\pages\\tierList\\data\\tier-history-s13.json
                                           强度评级 + 票数（s12 用于看涨跌）
    官方接口（初次抓取后写进 build\\cache\\api.json，之后 --no-api 可完全离线）

输出
    data\\aibi.json
    img\\abby\\*  img\\element\\*  img\\lineup\\*

用法
    python build/export.py              # 有缓存就用缓存，缺的才联网
    python build/export.py --refresh    # 强制重抓接口
    python build/export.py --no-api     # 只用本地缓存 + 打包数据，完全不联网
python build/export.py --votes-only # 只刷新票数 / 评级（几秒，不动其它字段）
"""
import io
import json
import os
import re
import shutil
import sys
import time
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.abspath(os.path.join(HERE, ".."))                    # 仓库根 = 站点根
WX = os.path.join(APP, "wx_assets", "out", "wxe5fd36fdf4332e8b")   # 抓出来的小程序素材
APP_SERVICE = os.path.join(WX, "pkg", "APP", "app-service.js")
TIER_DIR = os.path.join(WX, "pkg", "APP", "pages", "tierList", "data")
CDN_ABBY = os.path.join(WX, "cdn", "abby")
CDN_ELEMENT = os.path.join(WX, "cdn", "shuxing")
CDN_LINEUP = os.path.join(WX, "cdn", "lineup-type")
CACHE = os.path.join(HERE, "cache", "api.json")
BASE = "https://api.i425.com.cn"

ELEMENT_CODE = {"草": "cao", "水": "shui", "火": "huo", "电": "dian", "岩": "yan",
                "普": "pu", "龙": "long", "光": "guang", "暗": "an", "超": "chao"}

OPT_REFRESH = "--refresh" in sys.argv
OPT_NO_API = "--no-api" in sys.argv


# ---------------------------------------------------------------- 读 bundle

def read(p):
    return io.open(p, encoding="utf-8", errors="replace").read()


def js_array(s, marker):
    """从 `marker=[` 开始按括号配平抠出一个 JS 数组，并转成合法 JSON（给 key 补引号）"""
    i = s.find(marker)
    if i < 0:
        return []
    j = s.index("[", i)
    depth, k, q, esc = 0, j, None, False
    while k < len(s):
        c = s[k]
        if q:
            if esc:
                esc = False
            elif c == "\\":
                esc = True
            elif c == q:
                q = None
        else:
            if c in "\"'":
                q = c
            elif c == "[":
                depth += 1
            elif c == "]":
                depth -= 1
                if depth == 0:
                    break
        k += 1
    raw = s[j:k + 1]
    raw = re.sub(r"([\[{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:", r'\1"\2":', raw)
    try:
        return json.loads(raw, strict=False)
    except Exception as e:
        print("   ! %s 解析失败（%r），返回空" % (marker, e), file=sys.stderr)
        return []


def extract_catalog(src):
    out, seen = [], set()
    for d in js_array(src, "exports.AIBI_CATALOG=["):
        n = (d.get("name") or "").strip()
        if n and n not in seen:
            seen.add(n)
            out.append(d)
    return out


def extract_tokens(src):
    """信物表：aibiName / tokenName / shortHint / detail / category / loveSnack / hateSnack / world"""
    out = {}
    for d in js_array(src, "exports.TOKENS=["):
        n = (d.get("aibiName") or "").strip()
        if n and n not in out:
            out[n] = d
    return out


def extract_growth(src):
    """
    资质成长表：u("名字",[Lv.20 五维]) 或 u("名字",[Lv.20],[实测 Lv.0])
    数组顺序 [生命, 物攻, 法攻, 法防, 物防]
    """
    i = src.find("exports.AIBI_APTITUDE_GROWTH=[")
    if i < 0:
        return {}
    j = src.find("];var i,l=Object.create(null)", i)
    body = src[i:j if j > 0 else len(src)]
    out = {}
    for m in re.finditer(r'u\("([^"]+)",\[([^\]]*)\](?:,\[([^\]]*)\])?\)', body):
        name = m.group(1).strip()
        try:
            at20 = [float(x) for x in m.group(2).split(",") if x.strip()]
        except ValueError:
            continue
        if len(at20) != 5:
            continue
        at0 = None
        if m.group(3):
            try:
                v = [float(x) for x in m.group(3).split(",") if x.strip()]
                at0 = v if len(v) == 5 else None
            except ValueError:
                at0 = None
        out[name] = dict(at20=at20, at0=at0)
    return out


def load_tier(fname):
    p = os.path.join(TIER_DIR, fname)
    if not os.path.isfile(p):
        return None
    d = json.load(io.open(p, encoding="utf-8"))
    rank, votes = {}, {}
    for tier, items in (d.get("sections") or {}).items():
        for it in items or []:
            if it.get("name"):
                rank[it["name"]] = tier
                votes[it["name"]] = it.get("totalVotes", 0)
    return dict(meta=d, rank=rank, votes=votes)


# ---------------------------------------------------------------- 接口

def api(path, body=None, retry=3):
    data = None
    headers = {"User-Agent": "Mozilla/5.0", "Accept": "application/json"}
    if body is not None:
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        headers["Content-Type"] = "application/json"
    for attempt in range(retry):
        try:
            req = urllib.request.Request(BASE + path, data=data, headers=headers,
                                         method="POST" if data else "GET")
            with urllib.request.urlopen(req, timeout=25) as r:
                return json.loads(r.read().decode("utf-8"))
        except Exception as e:
            if attempt == retry - 1:
                print("   ! 接口失败 %s: %r" % (path, e), file=sys.stderr)
                return None
            time.sleep(1.2 * (attempt + 1))
    return None


def load_cache():
    if os.path.isfile(CACHE):
        try:
            return json.load(io.open(CACHE, encoding="utf-8"))
        except Exception:
            pass
    return {}


def save_cache(c):
    os.makedirs(os.path.dirname(CACHE), exist_ok=True)
    with io.open(CACHE, "w", encoding="utf-8") as f:
        json.dump(c, f, ensure_ascii=False, indent=1)


class Cache:
    """接口缓存：一次抓取后落到 build/cache/api.json，之后重跑不再联网"""

    def __init__(self):
        self.d = load_cache()
        self.dirty = False

    def get(self, key, path, body=None):
        if not OPT_REFRESH and key in self.d:
            return self.d[key]
        if OPT_NO_API:
            return self.d.get(key)
        val = api(path, body)
        if val is not None:
            self.d[key] = val
            self.dirty = True
        return self.d.get(key)

    def flush(self):
        if self.dirty:
            save_cache(self.d)


def data_of(resp, default=None):
    if isinstance(resp, dict):
        return resp.get("data", default)
    return default


# ---------------------------------------------------------------- 图片

def sniff_ext(data):
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if data[:3] == b"\xff\xd8\xff":
        return "jpg"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data[:4] in (b"GIF8",):
        return "gif"
    return ""


def ensure_image(element, name):
    """本地没有立绘时按 CDN 规则补一张；返回 img/ 下的相对路径"""
    for ext in ("png", "jpg", "webp"):
        rel = "abby/%s_%s.%s" % (element, name, ext)
        if os.path.isfile(os.path.join(APP, "img", rel)):
            return rel
    url = BASE + "/aibi/static/abby/" + urllib.parse.quote("abby_%s_%s.png" % (element, name))
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
        with urllib.request.urlopen(req, timeout=20) as r:
            data = r.read()
        ext = sniff_ext(data)
        if not ext:
            print("   ! 立绘格式不认识 %s（%d 字节）" % (name, len(data)), file=sys.stderr)
            return ""
        rel = "abby/%s_%s.%s" % (element, name, ext)
        dst = os.path.join(APP, "img", rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, "wb") as f:
            f.write(data)
        return rel
    except Exception as e:
        print("   ! 立绘下载失败 %s: %r" % (name, e), file=sys.stderr)
        return ""


def copy_images(catalog):
    pairs = []
    for c in catalog:
        if c.get("element") and c.get("name"):
            n = "%s_%s.png" % (c["element"], c["name"])
            pairs.append(("abby_" + n, n))
    os.makedirs(os.path.join(APP, "img", "abby"), exist_ok=True)
    n1 = 0
    for src_name, dst_name in pairs:
        src = os.path.join(CDN_ABBY, src_name)
        if os.path.isfile(src):
            shutil.copy2(src, os.path.join(APP, "img", "abby", dst_name))
            n1 += 1
    n2 = 0
    os.makedirs(os.path.join(APP, "img", "element"), exist_ok=True)
    for code in ELEMENT_CODE.values():
        src = os.path.join(CDN_ELEMENT, code + ".png")
        if os.path.isfile(src):
            shutil.copy2(src, os.path.join(APP, "img", "element", code + ".png"))
            n2 += 1
    n3 = 0
    os.makedirs(os.path.join(APP, "img", "lineup"), exist_ok=True)
    for t in ("刺客", "守卫", "射手", "战士", "辅助", "输出"):
        src = os.path.join(CDN_LINEUP, t + ".png")
        if os.path.isfile(src):
            shutil.copy2(src, os.path.join(APP, "img", "lineup", t + ".png"))
            n3 += 1
    return n1, n2, n3


# ---------------------------------------------------------------- 组装

def num(v):
    try:
        f = float(v)
    except (TypeError, ValueError):
        return None
    return f if f > 0 else None


def parse_snacks(text):
    """从「喜欢X，讨厌Y」这类句子里兜底解析糖果偏好"""
    love = hate = ""
    if not text:
        return love, hate
    m = re.search(r"喜欢\s*([^\s，。,；;]+)", text)
    if m:
        love = m.group(1)
    m = re.search(r"讨厌\s*([^\s，。,；;]+)", text)
    if m:
        hate = m.group(1)
        if hate in ("的", "无", "没有"):
            hate = ""
    return love, hate


def make_intro(r):
    """结构化字段拼一段简介（官方没有简介字段，这里是汇总文案，后台可改写）"""
    bits = []
    el = r["element"] + (("·" + r["element2"]) if r.get("element2") else "")
    head = "「%s」是 %s 属性的%s艾比" % (r["name"], el, r["rarity"])
    if r["lineup"]:
        head += "，定位%s" % r["lineup"]
    if r["main_stats"]:
        head += "，主词条%s" % r["main_stats"].replace("、", "、")
    bits.append(head + "。")
    if r["rank"]:
        bits.append("第十三赛季强度评级 %s%s。" % (r["rank"], ("，%d 票" % r["votes"]) if r["votes"] else ""))
    if r["hp"]:
        bits.append("资质成长（Lv.20）：生命 %.2f、物攻 %.2f、法攻 %.2f、法防 %.2f、物防 %.2f。"
                    % (r["hp"], r["atk"] or 0, r["sp_atk"] or 0, r["sp_def"] or 0, r["dfn"] or 0))
    if r["token_name"]:
        t = "信物「%s」" % r["token_name"]
        if r["token_hint"]:
            t += "：%s" % r["token_hint"]
        bits.append(t + "。")
    if r["love_snack"] or r["hate_snack"]:
        s = []
        if r["love_snack"]:
            s.append("喜欢%s" % r["love_snack"])
        if r["hate_snack"]:
            s.append("讨厌%s" % r["hate_snack"])
        bits.append("零食偏好：" + "、".join(s) + "。")
    return "".join(bits)


CN_DIGIT = "零一二三四五六七八九"


def to_cn(n):
    """14 -> 十四，23 -> 二十三（赛季号用）"""
    n = int(n)
    if n < 10:
        return CN_DIGIT[n]
    if n < 20:
        return "十" + (CN_DIGIT[n % 10] if n % 10 else "")
    if n < 100:
        return CN_DIGIT[n // 10] + "十" + (CN_DIGIT[n % 10] if n % 10 else "")
    return str(n)


def refresh_votes_only():
    """只拉强度榜接口，更新 data/aibi.json 里的 rank / votes / votes_prev。

    其它字段（五维、信物、技能、简介…）一个字都不动，所以可以随时跑：
        python build/export.py --votes-only && php build/import.php --votes-only
    """
    out = os.path.join(APP, "data", "aibi.json")
    if not os.path.isfile(out):
        raise SystemExit("找不到 %s，先跑一次完整导出" % out)
    with io.open(out, encoding="utf-8") as f:
        doc = json.load(f)

    print("== 只刷票数 ==")
    resp = api("/aibi/api/tier/list", {})
    data = data_of(resp, {}) or {}
    listing = data.get("list") or []
    if not listing:
        raise SystemExit("接口没返回榜单（检查网络或接口是否改了）")
    tier_map = {x.get("name"): x for x in listing if x.get("name")}

    meta = doc.get("meta") or {}
    season_start = data.get("seasonStart") or ""
    rolled = False
    if season_start and meta.get("season_start") and season_start != meta["season_start"]:
        # 换季：当前票数变成「上赛季最终票数」基线，赛季号 +1
        rolled = True
        old_start = meta["season_start"]
        for r in doc.get("list") or []:
            r["votes_prev"] = int(r.get("votes") or 0)
        try:
            num = int(meta.get("season") or 0) + 1
        except (TypeError, ValueError):
            num = 0
        if num:
            meta["season"] = num
            meta["season_label"] = "第%s赛季" % to_cn(num)
        # 旧赛季的结束时间 = 新赛季的开始时间
        bits = old_start.split(" ")[0].split("-")
        if len(bits) == 3:
            meta["cutoff_label"] = "截止 %s.%s.%s 00:00 前" % (bits[0], int(bits[1]), int(bits[2]))
        meta["season_start"] = season_start
        print("   检测到换季：%s 开始，票数基线已切到上赛季最终票数"
              % season_start)

    changed = 0
    for r in doc.get("list") or []:
        t = tier_map.get(r.get("name"))
        if not t:
            continue
        votes = int(t.get("totalVotes") or 0)
        old = int(r.get("votes") or 0)
        if votes and votes != old:
            r["votes"] = votes
            changed += 1
        if t.get("tier"):
            r["rank"] = t["tier"]

    meta["generated"] = time.strftime("%Y-%m-%d %H:%M:%S")
    if season_start and not meta.get("season_start"):
        meta["season_start"] = season_start
    meta["votes_updated_at"] = meta["generated"]
    for src_key, dst in (("season", "season"), ("seasonLabel", "season_label"),
                         ("cutoffLabel", "cutoff_label")):
        if data.get(src_key) is not None:
            meta[dst] = data[src_key]
    doc["meta"] = meta

    with io.open(out, "w", encoding="utf-8") as f:
        json.dump(doc, f, ensure_ascii=False, indent=1)
    print("   票数有变化 %d 只%s，写回 %s" % (changed, "（已换季）" if rolled else "", out))
    print("   赛季 %s %s" % (meta.get("season_label", ""), meta.get("cutoff_label", "")))


def main():
    if "--votes-only" in sys.argv:
        refresh_votes_only()
        return
    print("== 1/5 读本地打包数据 ==")
    src = read(APP_SERVICE)
    catalog = extract_catalog(src)
    tokens = extract_tokens(src)
    growth = extract_growth(src)
    print("   主表 %d 条、信物 %d 条、资质成长 %d 条" % (len(catalog), len(tokens), len(growth)))

    t13 = load_tier("tier-history-s13.json")
    t12 = load_tier("tier-history-s12.json")
    if t13:
        print("   强度榜 %s（%d 只，%s 票动作）"
              % (t13["meta"].get("seasonLabel"), t13["meta"].get("abbyCount"),
                 t13["meta"].get("voteActionCount")))

    print("== 2/5 取接口补充资料（有缓存就用缓存） ==")
    ck = Cache()
    extra = {}
    resp = ck.get("catalog_extra", "/aibi/api/abby/catalog/extra")
    for e in (data_of(resp, {}) or {}).get("list", []) or []:
        if e.get("name"):
            extra[e["name"]] = e
    print("   catalog/extra：%d 条" % len(extra))

    tier_api = ck.get("tier_list", "/aibi/api/tier/list", {})
    tier_rows = (data_of(tier_api, {}) or {}).get("list", []) or []
    tier_api_map = {x.get("name"): x for x in tier_rows if x.get("name")}

    info_cards = {}
    resp = ck.get("info_card", "/aibi/api/abby/info-card/list", {})
    for it in (data_of(resp, {}) or {}).get("items", []) or []:
        if it.get("abbyName"):
            info_cards[it["abbyName"]] = it

    shelves = {}
    resp = ck.get("shelf", "/aibi/api/abby/shelf/list", {})
    for it in (data_of(resp, {}) or {}).get("items", []) or []:
        if it.get("abbyName"):
            shelves[it["abbyName"]] = it
    print("   tier/list %d 条、info-card %d 条、shelf %d 条"
          % (len(tier_rows), len(info_cards), len(shelves)))

    # 逐只补：技能 / 通用技能 / 资质果 / 点赞数
    names = [c["name"] for c in catalog] + [n for n in extra if n not in {c["name"] for c in catalog}]
    perp = ck.d.get("perp", {})
    todo = [n for n in names if n not in perp]
    if todo and not OPT_NO_API:
        print("   逐只抓取 %d 只（技能/通用技能/资质果/点赞），可能要一分钟…" % len(todo))
        for idx, n in enumerate(todo, 1):
            pack = {}
            s = data_of(ck.get("skill:" + n, "/aibi/api/abby/skill/summary", {"abbyName": n}), {})
            if s.get("skill"):
                pack["skill"] = s["skill"]
            g = data_of(ck.get("gen:" + n, "/aibi/api/abby/general-skill/by-abby", {"abbyName": n}), {})
            if g.get("skills"):
                pack["general"] = g["skills"]
            f = data_of(ck.get("fruit:" + n, "/aibi/api/abby/fruit/summary", {"abbyName": n}), {})
            if f.get("fruit"):
                pack["fruit"] = f["fruit"]
            l = data_of(ck.get("like:" + n, "/aibi/api/abby/like/summary", {"abbyName": n}), {})
            if l:
                pack["likes"] = l.get("likeCount", 0)
            perp[n] = pack
            ck.d["perp"] = perp
            if idx % 25 == 0:
                ck.flush()
                print("     … %d/%d" % (idx, len(todo)))
        ck.d["perp"] = perp
        ck.flush()
    print("   逐只资料：%d 只" % len(perp))

    print("== 3/5 复制图片 ==")
    n1, n2, n3 = copy_images(catalog)
    print("   立绘 %d、属性图标 %d、阵容图标 %d" % (n1, n2, n3))

    print("== 4/5 合并 ==")
    rows, seen = [], set()

    def build(name, cat=None, ex=None):
        ex = ex or extra.get(name) or {}
        cat = cat or {}
        tok = tokens.get(name) or {}
        gr = growth.get(name) or {}
        pp = perp.get(name) or {}
        ti = tier_api_map.get(name) or {}
        el = cat.get("element") or ex.get("element") or ""
        el2 = cat.get("elementSecondary") or ex.get("elementSecondary") or ""
        img = ("abby/%s_%s.png" % (el, name)) if el else ""
        if not os.path.isfile(os.path.join(APP, "img", img)):
            img = ensure_image(el, name) if el else ""
        fd = ex.get("fiveDimensions") or {}
        at20 = gr.get("at20")
        if at20:
            hp, atk, sp_atk, sp_def, dfn = at20
        else:
            hp, atk, sp_atk, sp_def, dfn = (num(fd.get("hp")), num(fd.get("atk")),
                                            num(fd.get("spAtk")), num(fd.get("spDef")), num(fd.get("def")))
        like = tok.get("loveSnack") or ex.get("loveSnack") or ""
        hate = tok.get("hateSnack") or ex.get("hateSnack") or ""
        if not like or not hate:
            l2, h2 = parse_snacks(tok.get("detail") or ex.get("detail") or "")
            like = like or l2
            hate = hate or h2
        skill = (pp.get("skill") or {})
        general = pp.get("general") or []
        fruit = (pp.get("fruit") or {})
        info = info_cards.get(name) or {}
        shelf = shelves.get(name) or {}
        row = dict(
            name=name,
            element=el, element2=el2,
            rarity=cat.get("rarity") or ex.get("rarity") or "",
            lineup=cat.get("lineup") or ex.get("lineup") or "",
            skill_name=skill.get("skillName") or cat.get("skillName") or ex.get("skillName") or "",
            main_stats="、".join(cat.get("mainStats") or ex.get("mainStats") or []),
            img=img,
            rank=ti.get("tier") or (t13 or {}).get("rank", {}).get(name) or ex.get("strengthRank") or "",
            votes=int(ti.get("totalVotes") or (t13 or {}).get("votes", {}).get(name, 0) or 0),
            rank_prev=(t12 or {}).get("rank", {}).get(name, ""),
            votes_prev=int((t12 or {}).get("votes", {}).get(name, 0) or 0),
            hp=num(hp), atk=num(atk), dfn=num(dfn), sp_atk=num(sp_atk), sp_def=num(sp_def),
            stat_source=("Lv.20 资质成长" if at20 else ("官方五维" if fd else "")),
            love_snack=like, hate_snack=hate,
            token_name=tok.get("tokenName") or ex.get("tokenName") or "",
            token_category=tok.get("category") or ex.get("tokenCategory") or "",
            token_hint=tok.get("shortHint") or ex.get("shortHint") or ex.get("tokenHint") or "",
            token_detail=tok.get("detail") or ex.get("detail") or "",
            token_world=tok.get("world") or ex.get("tokenWorld") or "",
            skill_type=skill.get("skillType") or "",
            skill_type_label=skill.get("skillTypeLabel") or "",
            skill_cd=skill.get("cooldownSec") or 0,
            skill_detail=skill.get("detail") or "",
            general_skills=json.dumps(general, ensure_ascii=False) if general else "",
            fruit_label=fruit.get("fruitLabel") or "",
            fruit_apt=fruit.get("apt") or "",
            likes=int(pp.get("likes") or 0),
            info_practicality=info.get("practicality") or "",
            info_maps="、".join(info.get("maps") or []) if isinstance(info.get("maps"), list) else (info.get("maps") or ""),
            shelf_status=shelf.get("statusLabel") or "",
            shelf_paid=shelf.get("payLabel") or "",
            has_extra=1 if ex else 0,
            source=",".join(["pack"] + (["api"] if ex else []) + (["api"] if pp else [])),
        )
        row["intro"] = make_intro(row)
        return row

    for c in catalog:
        if c["name"] in seen:
            continue
        seen.add(c["name"])
        rows.append(build(c["name"], cat=c))
    added = 0
    for name in extra:
        if name in seen:
            continue
        seen.add(name)
        rows.append(build(name, ex=extra[name]))
        added += 1
    if added:
        print("   接口独有、包里没有的艾比 %d 条" % added)

    missing = [r["name"] for r in rows if r["hp"] is None]
    no_tok = [r["name"] for r in rows if not r["token_name"]]
    print("   五维缺失 %d 只、信物缺失 %d 只" % (len(missing), len(no_tok)))
    if missing:
        print("     五维缺：%s" % "、".join(missing[:12]))
    if no_tok:
        print("     信物缺：%s" % "、".join(no_tok[:12]))

    rows.sort(key=lambda r: (r["rank"] or "ZZ", -r["votes"], r["name"]))
    meta = dict(
        generated=time.strftime("%Y-%m-%d %H:%M:%S"),
        source_wxapp="wxe5fd36fdf4332e8b",
        total=len(rows),
        season=(t13 or {}).get("meta", {}).get("season"),
        season_label=(t13 or {}).get("meta", {}).get("seasonLabel", ""),
        cutoff_label=(t13 or {}).get("meta", {}).get("cutoffLabel", ""),
        extra_count=len(extra),
        elements=list(ELEMENT_CODE.keys()),
        element_code=ELEMENT_CODE,
    )
    os.makedirs(os.path.join(APP, "data"), exist_ok=True)
    out = os.path.join(APP, "data", "aibi.json")
    with io.open(out, "w", encoding="utf-8") as f:
        json.dump(dict(meta=meta, list=rows), f, ensure_ascii=False, indent=1)

    print("== 5/5 完成 ==")
    print("   %s（%d 条；五维 %d、信物 %d、技能详解 %d、通用技能 %d）"
          % (out, len(rows), sum(1 for r in rows if r["hp"]), sum(1 for r in rows if r["token_name"]),
             sum(1 for r in rows if r["skill_detail"]), sum(1 for r in rows if r["general_skills"])))


if __name__ == "__main__":
    main()
