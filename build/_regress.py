# -*- coding: utf-8 -*-
"""全量回归。

本地（把站点跑起来后）：
    python build/_regress.py
CI（用 PHP 内置服务器）：
    AIBI_BASE=http://127.0.0.1:8099 python build/_regress.py
"""
import os
import sqlite3
import sys
import urllib.error
import urllib.request
from urllib.parse import quote, urlparse

BASE = os.environ.get('AIBI_BASE', 'http://localhost:8080').rstrip('/')
HOST = urlparse(BASE).netloc
HDRS = {'Host': HOST, 'User-Agent': 'regress'}


def get(path):
    req = urllib.request.Request(BASE + path, headers=HDRS)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def main():
    fails = []
    conn = sqlite3.connect('data/aibi.db')
    names = [r[0] for r in conn.execute('SELECT name FROM abby ORDER BY name')]
    conn.close()
    print('db names:', len(names))

    imgs = set()
    for n in names:
        code, body = get('/detail.php?name=' + quote(n))
        text = body.decode('utf-8', 'replace')
        if code != 200:
            fails.append('detail %s -> %s' % (n, code))
            continue
        if '暂无立绘' in text:
            fails.append('detail %s 缺立绘' % n)
        if '编辑资料' in text:
            fails.append('detail %s 匿名可见编辑按钮' % n)
        for seg in text.split('src="img/')[1:]:
            imgs.add('img/' + seg.split('"')[0])
    print('detail pages checked:', len(names), '| unique images:', len(imgs))

    for p in sorted(imgs):
        code, body = get('/' + quote(p, safe='/'))
        if code != 200 or len(body) < 200:
            fails.append('image %s -> %s (%d B)' % (p, code, len(body)))
    print('images checked:', len(imgs))

    conn = sqlite3.connect('data/aibi.db')
    pairs = [('/?el=%E7%81%AB', "element = '火'"),
             ('/?rank=T0', "rank = 'T0'"),
             ('/?rarity=%E4%BC%A0%E8%AF%B4', "rarity = '传说'"),
             ('/?lineup=%E5%88%BA%E5%AE%A2', "lineup = '刺客'")]
    for path, where in pairs:
        want = conn.execute('SELECT COUNT(*) FROM abby WHERE ' + where).fetchone()[0]
        code, body = get(path)
        got = body.decode('utf-8', 'replace').count('class="row"')
        if code != 200 or got != want:
            fails.append('filter %s -> %s rows=%d (db %d)' % (path, code, got, want))
    # 搜索：只断言「搜出来比全库少且非空」
    total = conn.execute('SELECT COUNT(*) FROM abby').fetchone()[0]
    conn.close()
    code, body = get('/?q=%E9%98%BF%E7%BD%97%E6%96%AF')
    got = body.decode('utf-8', 'replace').count('class="row"')
    if code != 200 or not (0 < got < total):
        fails.append('search 阿罗斯 -> %s rows=%d' % (code, got))
    print('filters checked:', len(pairs))

    code, body = get('/?page=4')
    rows = body.decode('utf-8', 'replace').count('class="row"')
    print('page 4 rows:', rows)
    if code != 200 or rows != 40:
        fails.append('page 4 -> %s rows=%d' % (code, rows))

    code, body = get('/?el=%E4%B8%8D%E5%AD%98%E5%9C%A8')
    if code != 200 or '没有符合条件的艾比' not in body.decode('utf-8', 'replace'):
        fails.append('空态异常')

    code, _ = get('/detail.php?name=nope')
    if code != 404:
        fails.append('404 -> %s' % code)

    for path in ('/', '/admin/', '/assets/style.css', '/assets/app.js',
                 '/assets/fonts/public-sans-latin.woff2'):
        code, body = get(path)
        if code != 200 or len(body) < 100:
            fails.append('static %s -> %s (%d B)' % (path, code, len(body)))
    print('statics checked')

    print()
    for f in fails:
        print('FAIL', f)
    print('FAILS:', 'none' if not fails else len(fails))
    return 0 if not fails else 1


if __name__ == '__main__':
    sys.exit(main())
