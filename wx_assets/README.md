# 微信小程序素材图片提取（PC 微信 4.x / RadiumWMPF）— 「艾比手册」全量抓图

零依赖 Python 工具，直接把**电脑版微信**里小程序的素材图抠到本地。三层来源全部覆盖：

| 层 | 来源 | 这次的量 |
|---|---|---|
| ① 包内静态图 | `*.wxapkg` 解包（含新版 `V1MMWX` 加密包） | 83 张 |
| ② 运行时缓存图 | `local\<appid>\usr\abby_img_v3` 等落盘缓存 | 203 张 |
| ③ 远端 CDN 图 | 从包内 JS 还原出的规则（`api.i425.com.cn`）+ 包内写死的静态 URL | 186 + 7 张 |

合计 **479 张 / 4.25 MB**，同一张图在包内、缓存、CDN 之间重复出现已按 SHA-256 合并。

目标小程序：**艾比手册** `wxe5fd36fdf4332e8b`。CDN 域 `https://api.i425.com.cn`。

## 用法

```powershell
cd wx_assets
$env:PYTHONIOENCODING='utf-8'          # 只为控制台不乱码

# 0) 看看本机缓存了哪些小程序
python wxapkg_tool.py scan

# 1) 解包 + 收割缓存 + 去重（--all-files 连 js/json 一起解，CDN 规则要从代码里挖）
python wxapkg_tool.py unpack --appid wxe5fd36fdf4332e8b --all-files --out out

# 2) 抓包内写死的远端图片 URL
python wxapkg_tool.py remote  --appid wxe5fd36fdf4332e8b --out out

# 3) 按还原出的 CDN 规则全量补齐立绘/属性图标/阵容图标
python aibi_assets.py --out out

# 4) 体检：分包是否下全、代码引用的本地图片有没有缺
python wxapkg_tool.py check --appid wxe5fd36fdf4332e8b --out out

# 5) 生成画廊
python wxapkg_tool.py gallery --out out
```

画廊：<http://localhost:8080/wx_assets/out/index.html>（也可直接双击 `out\index.html`）

## 图片地址（你要的原始 URL）

规则来自解包后的 `out\wxe5fd36fdf4332e8b\pkg\APP\app-service.js`：

| 类型 | URL 规则 | 例子 |
|---|---|---|
| 艾比立绘（170 张） | `https://api.i425.com.cn/aibi/static/abby/abby_<属性>_<名字>.png` | `https://api.i425.com.cn/aibi/static/abby/abby_龙_阿罗斯.png` |
| 属性图标（10 张） | `https://api.i425.com.cn/aibi/static/shuxing/<代码>.png` | `https://api.i425.com.cn/aibi/static/shuxing/huo.png` |
| 阵容类型图标（6 张） | `https://api.i425.com.cn/aibi/static/lineup-type/<urlencode 名称>.png` | `https://api.i425.com.cn/aibi/static/lineup-type/刺客.png` |
| 道具图 | `https://api.i425.com.cn/aibi/static/abby_items/item-<urlencode 名称>.jpg` | — |
| 静态头图（7 张） | `https://api.i425.com.cn/aibi/static/head-{bbh,mmm,my,snn,tll,yxts}.jpg`、`wx-code.jpg` | `head-my.jpg` |

属性代码表：`草=cao 水=shui 火=huo 电=dian 岩=yan 普=pu 龙=long 光=guang 暗=an 超=chao`。
名字别名（页面写法 → 实际文件名）：花梨朵→花莉朵、椰子鹅鹅→椰子鹈鹕、焦虑鸭→蕉绿鸭、锅凯龟→锅铠龟、美源螺螺→美螺螈螈、锤头鹅→锤头獾、橘福猫→橘蝠猫。

> **文件名与 URL 的关系**：本地一律存原始中文名（`cdn\lineup-type\刺客.png`、`cdn\abby\abby_龙_阿罗斯.png`），URL 里的 `%E5%88%BA%E5%AE%A2.png` 只是同一个名字的 percent-encoding。
> 注意别再手动套一层编码：`index.html` 里的 `src` 已经编码过一次（浏览器会解码一次），如果本地文件名本身就是 `%E5%88%BA%E5%AE%A2.png`，浏览器解完会去找「刺客.png」→ 破图。早期版本踩过这个坑，现已修：文件名去编码、`src` 统一 `quote(safe="/")`、字段统一 HTML 转义。

170 条艾比的「名字 / 属性 / 副属性 / CDN 地址 / 本地文件」全表：`out\wxe5fd36fdf4332e8b\abby_list.csv`。
属性分布：草 27、普 22、水 20、火 20、岩 18、暗 17、电 15、超 15、光 14、龙 2。

## 输出目录

```
out\
├── index.html                       # 画廊（479 张，可搜索/按尺寸/按体积筛）
├── manifest.csv                     # 每张图：appid, source, src, out, ext, bytes, w, h, sha256
├── report.json                      # 各小程序包/缓存统计与解密日志
└── wxe5fd36fdf4332e8b\
    ├── abby_list.csv                # 170 条艾比清单 + CDN 地址
    ├── pkg\APP\images\...           # 包内静态图（原始目录层级）
    ├── pkg\pages_xxx\...            # 已下载分包的图
    ├── cache\usr\abby_img_v3\*.png  # 运行时缓存图（命名稳定那份）
    ├── cdn\abby\abby_<属性>_<名字>.png   # CDN 立绘 170 张
    ├── cdn\shuxing\*.png            # 属性图标 10 张
    ├── cdn\lineup-type\*.png        # 阵容图标 6 张
    └── remote\*.jpg                 # 代码里写死的静态头图 7 张
```

## 还差什么（为什么不是 100%）

1. **16 个分包本机没下载**：`app-config.json` 声明 24 个分包，微信只下载「打开过的页面」所在的包。已下 8 个（personality、fruitGuide、qizhen、abbyLikes、news、packageMine、redeemPlaza、systemMessages），缺 16 个：`pages/fight`、`packageAi`、`pages/calculator`、`pages/captureCalc`、`pages/abbyCompare`、`pages/breakthrough`、`pages/atlas`、`pages/traits`、`pages/abbyItems`、`pages/shelfStatus`、`pages/infoCard`、`pages/lineupRecommend`、`pages/skillAdjust`、`pages/roleWorkshop`、`pages/feedback`、`pages/homeFavConfig`。
   想补齐：在微信里把这些页面各点开一次，再跑一遍上面 1→5 步即可（`unpack` 会追加新包，`check` 会掉数字）。
2. **2 张包内引用缺失**：`images/qizhen/gulao-juanzhou.png`、`images/qizhen/shenmi-wandou.png` 被 qizhen 分包代码引用，但该包只有 8 个 js、没有图（发行包本身就没带）。
3. **道具图未抓**：`/aibi/static/abby_items/item-<名称>.jpg` 的规则已还原，但道具名称清单不在本地包里（服务端接口下发），没有名单无法枚举；页面上看到道具图后再抓即可。

## wxapkg 解密（Windows 微信 4.x 实测）

加密包特征：文件头 6 字节 `V1MMWX`。

```
key   = PBKDF2-HMAC-SHA1(appid, salt=b"saltiest", iters=1000, dkLen=32)   # AES-256-CBC
iv    = b"the iv: 16 bytes"
plain[0:1023] = AES-256-CBC-decrypt(cipher[6:1030])[0:1023]               # 只取 1023 字节！
plain[1023:]  = cipher[1030:] XOR ord(appid[-2])                          # appid<2 位时用 0x66
```

- 那个 **1023** 是最容易踩的坑：AES 解出 1024 字节，但明文只用前 1023 字节，其后紧接着 XOR 段；按 1024 拼会整体错位、索引表全乱。工具用「索引表能否完整对齐」打分自动验证。
- 明文结构：`0xBE` + u32 + u32 indexLen + u32 bodyLen + `0xED` + u32 fileCount，随后是 `u32 nameLen + name + u32 offset + u32 size` 条目表。

## 它去哪找图

自动探测，可用 `--applet-root` 覆盖：

```
%APPDATA%\Tencent\xwechat\radium\users\<hash>\applet
├── packages\<appid>\<版本>\*.wxapkg        ← 解包抽图（主包 + 已下载分包）
├── publicLib\<ver>\publicLib.wxapkg         ← 框架包（无素材，且无 appid 可解）
└── local\<appid>\
    ├── usr\abby_img_v3\*.png                ← 运行时缓存素材（命名稳定，优先保留）
    ├── usr\static_icon_v1\*
    ├── temp\*                               ← 同图临时副本，去重时让位给 usr
    └── store\images\<hash>
```

## 已知限制

- `publicLib.wxapkg` 解不开：密钥同样由 appid 派生，但框架包路径里没有 appid（试过 `wxapplet`、`wx9975a07ec7a1b7b5`、`wx614456d4b1bdb92f` 均不匹配）。里面是框架 JS，没有素材图。
- 只能抓**已在本机打开过**的小程序；没点开过的本地没有包也没有图。
- 微信版本升级可能更换加密方式或缓存路径，届时 `scan` 会显示解密失败（score 很低）。
- 图片只做「原样抽取」，不改名不重编码，文件名可能带 hash，用 `manifest.csv` 的 `src` 字段对照原始路径。
- 小程序活着的时候还会继续下载分包/缓存，重跑 `unpack` 数量会变多。

## 常见问题

- **PowerShell 报「禁止运行脚本」**：本工具是 `.py`，直接 `python xxx.py` 跑。
- **控制台中文乱码**：`$env:PYTHONIOENCODING='utf-8'`，只影响显示，文件本身是 UTF-8。
- **缓存目录非默认值**（改过微信文件保存位置/多账号）：`--applet-root "X:\...\applet"`。
