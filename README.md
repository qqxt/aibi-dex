# 艾比图鉴（PHP + SQLite · Apple 风格版）

[![CI](https://github.com/qqxt/aibi-dex/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/qqxt/aibi-dex/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/qqxt/aibi-dex?style=flat-square)](LICENSE)
[![Release](https://img.shields.io/github/v/release/qqxt/aibi-dex?style=flat-square&color=2e6b5e)](https://github.com/qqxt/aibi-dex/releases)
[![Stars](https://img.shields.io/github/stars/qqxt/aibi-dex?style=flat-square&color=0071e3)](https://github.com/qqxt/aibi-dex/stargazers)

「艾比手册」小程序资料的查询站。首页左侧是筛选（属性 / 稀有度 / 定位，带数量）+ 评级阶梯图 + 资料列表，点进去是单只艾比的完整档案：立绘、评级与票数、资质成长五维、糖果偏好、信物与掉落方式、专属技能、通用技能、资质果、上架状态与简介。

视觉走 iOS 分组列表那一套：半透明顶栏、白卡片配柔和阴影、行内分隔线、评级用胶囊、唯一的强调色只出现在「要做的事」上（搜索按钮、选中态）。详情页是「对象头 + 左速览 + 右内容流」两栏：头卡放封面、名字、属性、评级块和几条高亮 chip，左边 sticky 速览（五维、糖果），右边是信物、技能、简介、基础资料长文。动效遵循 Apple 的手感：按下即反馈、抽屉 1:1 跟手、松手按手指速度做动量投影、可反向取消、动画从当前屏幕值出发而不是从头播放。

- 访问地址：`http://localhost:8080/`（程序直接跑在站点根目录）
- 运行环境：PHP 8.2 + SQLite，**零第三方依赖、零外链**
- 规模：184 只（170 只来自小程序包内 `AIBI_CATALOG`，14 只是官方接口新增、包内还没有的）
- 数据完整度：五维 184/184、技能详解 184、通用技能 172、糖果与信物说明 184、信物名 170/184

页面跑起来之后**不访问任何外部地址**——所有资料在 `data/aibi.db`，所有图片在 `img/`。只有你想重新抓数据时，`build/export.py` 才会去读小程序缓存和官方接口。

## 目录结构

站点根目录（把仓库放到任意 Web 根下都能跑，仓库自身就是站点根）：

```
aibi-dex\
├── index.php             首页：筛选条 + 卡片网格
├── detail.php            详情页 detail.php?name=阿罗斯（找不到返回 404）
├── lib/
│   ├── config.php        标题、属性/稀有度/评级配色、信物码与地图码翻译、五维标签
│   └── db.php            SQLite 连接、建表与老库迁移、查询、统计、增删改
├── assets/
│   ├── style.css         前台全部样式（配色收在 :root 变量里）
│   ├── app.js            移动端筛选抽屉：开关、Esc、点选自动收起、拖拽关闭
│   ├── fonts/            Public Sans 本地 woff2（不联网取字体）
│   └── admin.css         后台样式
├── admin/                后台（增删改、图片上传）
│   ├── index.php         首访设密码 / 登录 / 资料列表
│   ├── edit.php          单只编辑：edit.php?id=1 或 edit.php?name=芬里尔
│   ├── save.php          保存（CSRF、重名校验、图片校验）
│   ├── delete.php        删除
│   └── _boot.php         会话、密码、CSRF、页头页脚
├── data/
│   ├── aibi.json         导出产物：184 条完整资料（导入用）
│   ├── aibi.db           SQLite 数据库（页面真正读的东西）
│   └── admin.json        后台密码 hash（首次进后台时生成，删掉即重设）
├── img/
│   ├── abby/             184 张立绘（170 png + 14 jpg）
│   ├── element/          10 张属性图标 cao/shui/huo/dian/yan/pu/long/guang/an/chao
│   └── lineup/           6 张定位图标 刺客/守卫/射手/战士/辅助/输出
├── build/
│   ├── export.py         抓数据 + 拷图片 → data/aibi.json
│   ├── import.php        导入 json → data/aibi.db
│   ├── cache/api.json    接口缓存（重跑不必再请求）
└── aibi/                 旧的二级目录入口，只剩两个 301 跳转壳
```

外观相关的资料（`error/`、`wx_assets/`）也在根目录，本程序不依赖它们。

## 数据从哪来

三层，全部在构建时抓完落到本地：

1. **小程序包内本地表**（`wx_assets/out/wxe5fd36fdf4332e8b/pkg/APP/app-service.js`）
   - `exports.AIBI_CATALOG` 170 条：名字、属性、副属性、稀有度、定位、专属技能名、主词条
   - `exports.TOKENS` 170 条：信物名、一句话记法、掉落方式全文、类别、地图、喜欢/讨厌的糖果
   - `exports.AIBI_APTITUDE_GROWTH` 171 条：Lv.20 五维（顺序 生命/物攻/法攻/法防/物防）
2. **官方接口**（`https://api.i425.com.cn/aibi/api/...`，构建时调一次，结果缓存在 `build/cache/api.json`）
   - `abby/catalog/extra`：包里没有的 14 只完整资料
   - `tier/list`：184 只的赛季评级与票数（比包内 s13 文件更全）
   - `info-card/list`、`shelf/list`：资料卡、上架状态
   - 逐只：`skill/summary`、`general-skill/by-abby`、`fruit/summary`、`like/summary`
3. **图片**：立绘与图标来自小程序 CDN 与本地缓存，已全部复制到 `img/`

简介（`intro`）没有官方文案字段，导入时按结构化字段自动汇总一段；想改就在后台改写并勾选「锁定简介」，重跑导入不会覆盖。

## 日常操作

### 改数据（推荐：后台）

1. 浏览器打开 `http://localhost:8080/admin/`
2. 第一次进会要求设密码（只存 `password_hash` 到 `data/admin.json`；忘了就删掉这个文件重设）
3. 列表页可按名字/属性筛，每行右侧「编辑 / 删除」；右上「＋ 新增艾比」
4. 编辑页包含：基本资料、立绘（填路径或上传 ≤2MB 的 png/jpg/webp）、五维与来源、糖果、信物（名/类别/地图/一句话/全文）、技能（专属 + 通用技能 JSON）、资质果与上架状态、一句话提示、详细简介（可锁定）

保存即生效，不用碰 json，也不用跑导入。

### 用哪个脚本更新

| 想做的事 | 用这个 | 花多久 |
| --- | --- | --- |
| **只更新票数 / 评级**（最常用） | 双击 `build\update_votes.bat`，或跑下面两条命令 | 约 2 秒 |
| **全量重抓**（五维、信物、技能、糖果、简介、立绘全部按官方最新） | 双击 `build\export_all.bat` | 15-20 分钟，要联网 |
| 全量重抓但**保留后台手改**（只补新增的艾比） | `build\export_all.bat keep` | 同上 |
| 完全离线重建（只用本地缓存 + 包内数据） | `python build\export.py --no-api` 然后 `php build\import.php --keep` | 约 1 分钟 |
| 只改某一只的某个字段 | 直接进 `/admin/` 编辑，不用跑脚本 | 几秒 |

两个 `.bat` 调用的是 `php`（要求在 PATH 里）。如果 PHP 没进 PATH，把脚本里那一行换成 `php.exe` 的完整路径即可。

### 刷新票数与评级（几秒，不动其它字段）

票数来自官方赛季榜单接口，站点本身完全不联网，所以库里存的是**快照**。想更新：

```bash
python build/export.py --votes-only     # 只拉 /aibi/api/tier/list，更新 data/aibi.json 里的 rank / votes / votes_prev
php build/import.php --votes-only       # 写回数据库，只改 rank / votes / votes_prev / updated_at
```

两条都只碰票数和评级，五维、信物、技能、简介、以及后台手改的内容一个字都不会动，可以随便反复跑。刷新时间会记在 `meta.votes_updated_at`，详情页「基础资料 → 数据快照」和列表页底部显示的就是它。

想每天自动刷新，用 Windows 计划任务调一个 `.bat`：

```bat
cd /d D:\path\to\aibi-dex
python build\export.py --votes-only
php build\import.php --votes-only
```

### 重新抓数据（会覆盖手工改动，慎用）

```powershell
cd D:\path\to\aibi-dex
python build\export.py              # 用 build/cache/api.json 里已有的接口数据，只重读小程序包
python build\export.py --refresh    # 强制重新请求接口（约 17 分钟，184 只 × 4 个请求）
python build\export.py --no-api     # 完全不联网，只用包内数据
php build\import.php                # 清空重灌 184 条
php build\import.php --keep         # 跳过数据库里已存在的名字，只补新艾比
```

> 重新导入会覆盖后台改过的内容。只想补新艾比时用 `--keep`。

### 改配色

前台配色只在 `assets/style.css` 顶部 `:root`：

```css
--bg: #f5f5f7;        /* 页面底色（iOS 系统灰） */
--surface: #ffffff;   /* 卡片 / 面板 / 列表 */
--fill: rgba(118,118,128,.12);   /* 次要底：筛选胶囊、按钮、搜索框 */
--sep: rgba(60,60,67,.13);       /* 行分隔线 */
--label: #1d1d1f;     /* 主文字 */
--label-2: #6e6e73;   /* 次要文字（≥4.5:1） */
--tint: #0071e3;      /* 强调色：搜索按钮、选中态、可点的字 */
--green: #1f7a35; --red: #d70015;   /* 涨 / 跌 */
--r-sm: 10px; --r-lg: 18px;         /* 控件 / 卡片圆角 */
--ease: cubic-bezier(.32,.72,0,1);  /* 所有位移用的同一条曲线 */
```

动效只用在「反馈」上，不加入场动画：按钮 `:active` 缩到 0.97、列表行按下去底色加深、抽屉拖拽时 `transition: none` 直接跟手、松手后交给 `--ease` 收尾。`prefers-reduced-motion` / `-reduced-transparency` / `-contrast` 三个媒体查询都有对应降级。

属性 / 稀有度 / 评级的颜色不在这里，而在 `lib/config.php` 的 `AIBI_ELEMENT_COLOR` / `AIBI_RARITY_COLOR` / `AIBI_RANK_COLOR`——列表的评级胶囊、详情页的评级行都取这三个数组，改一处全站生效。评级是「用颜色区分信息」的主要位置：T0 红、T0.5 橙、T1 琥珀、T1.5 橄榄、T2 绿、T3 青、T4 蓝、T5 灰。

**语义高亮**（详情页头卡的 chip、基础资料里的状态）用 `.tag` 系列，不新造颜色，只借上面那几支：

| 用在哪 | 类 | 颜色 |
| --- | --- | --- |
| 已下架 / 缺数据 / 信物名未公开 | `.tag--warn` | 琥珀 |
| 在售 / 有完整数据 | `.tag--ok` | 绿 |
| 玩家点赞、可点的信息 | `.tag--info` | 蓝（tint） |
| 属性、稀有度 | `.tag--el` / `.tag--rarity` | 取 `lib/config.php` 的同名数组 |
| 普通说明 | `.tag--plain` | 灰（`--fill`） |

字体是本地的 `assets/fonts/public-sans-latin*.woff2`（`@font-face`，中文自动落到系统字体），换字体改 `--sans` 一行。

## 已知缺口

- **14 只没有信物名**（工程蚁/线团团/浮力喵/睡莲狸/蛋壳头/罐居灵/鹿咖咖/樱桃苔球/奇乐迪/松果龙/国王猫/电浆蚊/卷卷蛹/铃兰宝）：它们是接口新增的，官方没公开 `tokenName`，但掉落方式（`token_detail`）有。后台可以直接补名字。
- **5 只没有「喜欢」糖果**（石板板/鲸雷/电电蛛/百特蝠/奶龙）、**10 只没有「讨厌」糖果**：官方数据本身就没写。
- 官方接口里 `fruit/list`、`articles/*`、`tier/vote-stats` 还有料，暂未纳入。
- 票数 / 评级是快照（不是实时），刷新见上面「刷新票数与评级」。
- **换季**：接口的 `totalVotes` 是「本赛季」票数，新赛季一来会清零重算。`export.py --votes-only` 会比对 `data.seasonStart`，发现换季就自动把上赛季最终票数写进 `votes_prev`、赛季号 +1（第十三赛季 → 第十四赛季），所以「较上赛季」一直有意义。这次换季就发生在 2026-10-02 之后：芬里尔上赛季 972 票，本赛季从 0 重新计，现在是 231 票。

## 对比度与圆角（定稿时改过的值）

页面定稿后按「对比度 / 圆角 / 行宽 / 间距」逐项量过一遍，改掉的是这些：

| 位置 | 原值 | 现用 | 原因 |
| --- | --- | --- | --- |
| 二级文字 `--label-2` | `#6e6e73` | `#5f5f66` | 搜索占位符在灰底上只有 4.1:1 |
| 浅底蓝字 | `--tint #0071e3` | 新增 `--tint-ink #0058b8` | 灰底上 3.9:1；`--tint` 从此只用于填充与图标 |
| 翻页禁用态 | `opacity:.6` | `#66666d`，去 opacity | 叠透明度后掉到 2.2:1 |
| 电 / 传说 | `#A9761A` | `#8A5C0F` | 白底 4.0:1 |
| 光 | `#A67C0F` | `#7F6610` | 同上 |
| 超 | `#B0447E` | `#9C3468` | 4.49:1，差一点 |
| T0.5 | `#C2410C` | `#A93A0A` | 4.4:1 |
| 卡片圆角 `--r-lg` | `18px` | `16px` | 卡片圆角惯例 12–16px |
| 正文行宽 | 不限 | `.de-main .prose p` / `.skill p` 收 68ch | 一行 90+ 字读起来累 |

改完两页都没有对比度违例；布局检查列表页全部通过，详情页只剩一条「同一行卡片底边不等高」的提示——这是刻意的，同排面板内容长度本来就不同，强行等高会在短面板里留大片空白。

## 交互与无障碍

- 桌面（>1100px）：左侧筛选栏常驻，点选项即时筛选（服务端渲染，地址栏可收藏/分享）。
- 手机顶栏压成一行：品牌 + 内嵌搜索按钮的搜索框 + 「后台」；搜索按钮窄屏只留箭头图标，走 `enterkeyhint="search"`。
- **「编辑资料」只在管理员登录后出现**：`lib/config.php` 的 `aibi_is_admin()` 只在请求带 `aibi_sid` cookie 时才 `session_start()`，匿名访客既不查 session 也不会被 Set-Cookie。
- 窄屏（≤1100px）：筛选栏变成底部抽屉，**没有投影**（只用 `.5px` 顶线 + 圆角跟页面分层），「筛选」按钮上带已选条件数；抽屉里三组条件是可换行 chips，一屏看完；点遮罩、Esc、或选中任一项都会收起；从顶上把手往下拖时 1:1 跟手，松手按手指速度投影，往回拖可取消关闭。
- 键盘：`Tab` 有可见焦点环；首行是「跳到主要内容」；抽屉打开时焦点进入面板、Esc 关闭后焦点回到触发按钮。
- 其他：`prefers-reduced-motion` 关闭位移与拖拽；`prefers-reduced-transparency` 关掉顶栏毛玻璃；`prefers-contrast: more` 加深分隔线与次要文字。

## 实现里踩过的坑

- **微信小程序 wxapkg 是加密的**（头 `V1MMWX`），AES-256-CBC + 异或，密钥由 appid 派生；细节在 `wx_assets/README.md`。抓图工具是 `wx_assets/wxapkg_tool.py`、`wx_assets/aibi_assets.py`。
- **14 只接口新增艾比的立绘其实是 JPEG**（`FF D8 FF`），按 `.png` 后缀存也能正常显示，`sniff_ext()` 按魔数判断类型。
- **percent-encoded 文件名会破图**：本地文件名用原文，只有 URL 里 `quote`，别二次编码（阵容图标曾落成 `%E5%88%BA%E5%AE%A2.png`）。
- **SQLite 老库迁移**：`ensure_schema()` 用 `PRAGMA table_info` 逐列 `ALTER TABLE ... ADD COLUMN`，加了新字段不用手工删库。
- **后台保存只写提交上来的字段**，没提交的列保持原值；`abby_fields()` 是唯一的字段白名单，加字段记得同时改它、`admin/edit.php` 的表单和 `export.py` 的输出。
- 导入器的逐只请求是顺序的，184 只约 17 分钟；`stdout` 走管道时会被缓冲，看不到进度就盯 `build/cache/api.json` 的大小。
- **`[data-sheet="open"]` 的抽屉在 DOM 里是同一个 `.pane`**：桌面是 `position: static` 的栏，≤1100 才变 `fixed`；改样式时两边都要看，否则桌面会冒出遮罩。
- **`.mini > span` 这种「直接子元素」选择器会误伤**：相关推荐卡里的名字容器也是 `span`，曾被 38px 的占位方块压成 38px 宽，名字全截断；现在占位用 `.mini > span.ph`。
- **改 `detail.php` 别用 `<main class="wrap" id="main">` 做定位**：这个文件里 404 分支也有一个 `<main>`，`content.index()` 会命中前面那个，结果新布局被塞进 404 分支、页面里出现两份完整 HTML。要么按行号切片，要么先把 404 分支换成不含 `<main>` 的写法。
- **截图别用 Edge 的 `--window-size=430` 判断手机布局**：Edge headless 有 ~500px 最小布局宽度，430 是裁切。真实 390 布局要用能设 `deviceMetrics` 覆盖的无头浏览器（Playwright / CDP），截出来才是真机布局。

## 常见问题

**票数为什么是「快照」，怎么更新？**
站点运行时不联网，票数和评级是导入那一刻的快照，会随时间过期。想刷新只动这两列：

```bash
python build/export.py --votes-only
php build/import.php --votes-only
```

五维、信物、技能、简介和后台手改的内容都不会被覆盖，可以反复跑；刷新时间会写进详情页「数据快照」那一行。

**后台密码忘了？**
删掉 `data/admin.json`，再打开 `/admin/` 就会回到「设置密码」页，重新设一个即可。密码只存 `password_hash` 结果。

**重新导入会不会冲掉我手改的字段？**
会。`php build/import.php` 默认清空重灌；加 `--keep` 则只补库里还没有的名字，已存在的记录整体跳过。

**图片和资料能商用吗？**
代码是 MIT，但 `img/` 里的立绘与 `data/aibi.db` 里的资料来自游戏与小程序的公开接口，**不在授权范围内**，商用或再分发请自行取得授权。

---

## 版权与数据来源

**代码**：MIT，见 [LICENSE](LICENSE)。

**数据与素材**：`data/aibi.db` 里的资料、`img/` 里的立绘整理自「艾比手册」小程序及其公开接口，
仅用于个人查询与学习，**不包含在本仓库的授权范围内**；相关素材版权归原权利人所有。
如果你要商用或再分发素材，请自行取得授权。
