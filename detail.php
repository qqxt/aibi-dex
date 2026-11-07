<?php
require __DIR__ . '/lib/db.php';

$name = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
$row  = $name !== '' ? get_abby($name) : null;

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>找不到这只艾比 · <?php echo h(AIBI_TITLE); ?></title>
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#f5f5f7">
</head>
<body>

<a class="skip" href="#main">跳到主要内容</a>

<header class="topbar"><div class="topbar-in"><div class="brand">
  <svg class="mark" viewBox="0 0 22 22" fill="none" aria-hidden="true">
    <rect x="1.5" y="5.5" width="13" height="15" rx="2.2" stroke="currentColor" stroke-width="1.4"/>
    <rect x="7" y="1.5" width="13" height="15" rx="2.2" stroke="currentColor" stroke-width="1.4" fill="#f5f5f3"/>
  </svg>
  <span class="brand-title"><?php echo h(AIBI_TITLE); ?></span>
</div><a class="toplink" href="admin/">后台</a></div></header>

<main class="wrap" id="main">
  <div class="empty">
    <h1>找不到这只艾比</h1>
    <p>可能是名字写错了，或者它还没被收录。</p>
    <p><a class="btn-line" href="./">回到图鉴</a></p>
  </div>
</main>

</body>
</html>
    <?php
    exit;
}

$img   = $row['img'] !== '' ? img_url($row['img']) : '';
$has_img = $img !== '' && is_file(__DIR__ . '/' . $img);
$dims  = $GLOBALS['AIBI_DIMS'];
$maxes = stat_max_each();

$has_stats = false;
foreach (array_keys($dims) as $k) {
    if ($row[$k] !== null && $row[$k] !== '') {
        $has_stats = true;
    }
}

$gskills = array();
if (!empty($row['general_skills'])) {
    $tmp = json_decode((string)$row['general_skills'], true);
    if (is_array($tmp)) {
        $gskills = $tmp;
    }
}

$related = related_abby($row, 8);

$votes_delta = (int)$row['votes'] - (int)$row['votes_prev'];
$season = meta_get('season_label', '');
$is_admin = aibi_is_admin();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($row['name']); ?> · <?php echo h($row['element']); ?>属性<?php echo $row['rank'] !== '' ? ' ' . h($row['rank']) : ''; ?> · <?php echo h(AIBI_TITLE); ?></title>
<meta name="description" content="<?php echo h(mb_substr((string)$row['intro'], 0, 80, 'UTF-8')); ?>">
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#171719">

</head>
<body>

<header class="topbar">
  <div class="topbar-in">
    <div class="brand">
      <svg class="mark" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <rect x="1.5" y="5.5" width="13" height="15" rx="2.2" stroke="currentColor" stroke-width="1.4"/>
        <rect x="7" y="1.5" width="13" height="15" rx="2.2" stroke="currentColor" stroke-width="1.4" fill="#f5f5f3"/>
        <path d="M10.5 7.2h6M10.5 10.2h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
      </svg>
      <span class="brand-title"><?php echo h(AIBI_TITLE); ?></span>
      <span class="scope"><b><?php echo total_abby(); ?></b> 只 · <?php echo h($season); ?></span>
    </div>
    <form class="search" id="sf" method="get" action="./" role="search">
      <svg class="ic" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.4"/>
        <path d="M10.4 10.4 14 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
      <input type="search" name="q" value="" placeholder="搜名字、技能、糖果、信物…" aria-label="搜索艾比" autocomplete="off" spellcheck="false" enterkeyhint="search">
      <button class="go" type="submit">
        <span class="go-t">搜索</span>
        <svg class="go-ic" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8h9M8.5 3.5 13 8l-4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
    </form>
    <a class="toplink" href="admin/">后台</a>
  </div>
</header>

<main class="wrap" id="main">

  <nav class="back-wrap" aria-label="位置">
    <a class="back" href="./">
      <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M9.5 3 4.5 8l5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      图鉴
    </a>
    <span class="back">/</span>
    <a class="back" href="./?el=<?php echo rawurlencode($row['element']); ?>"><?php echo h($row['element']); ?>属性</a>
    <span class="back">/</span>
    <span class="back"><?php echo h($row['name']); ?></span>
  </nav>

  <div class="de">

    <header class="de-hero" style="--el:<?php echo element_color($row['element']); ?>;--rc:<?php echo rarity_color($row['rarity']); ?>">
      <div class="de-art">
        <?php if ($has_img): ?>
          <img src="<?php echo h($img); ?>" alt="<?php echo h($row['name']); ?> 立绘" width="240" height="240">
        <?php else: ?>
          <span class="de-art-ph">暂无立绘</span>
        <?php endif; ?>
      </div>

      <div class="de-info">
        <div class="de-headline">
          <h1><?php echo h($row['name']); ?></h1>
          <?php if ($is_admin): ?>
            <a class="btn-line" href="admin/edit.php?name=<?php echo rawurlencode($row['name']); ?>">编辑资料</a>
          <?php endif; ?>
        </div>

        <div class="de-tags">
          <span class="tag tag--el">
            <?php $ico = element_icon($row['element']); ?>
            <?php if ($ico !== '' && is_file(__DIR__ . '/' . $ico)): ?>
              <img src="<?php echo h($ico); ?>" alt="" width="13" height="13">
            <?php endif; ?>
            <?php echo h($row['element']); ?>属性
          </span>
          <span class="tag tag--rarity"><?php echo h($row['rarity']); ?></span>
          <span class="tag"><?php echo h($row['lineup']); ?></span>
          <span class="tag tag--plain">主词条 <?php echo $row['main_stats'] !== '' ? h($row['main_stats']) : '未标注'; ?></span>
        </div>

        <div class="de-rank" style="--rk:<?php echo rank_color($row['rank']); ?>">
          <span class="rk-lg"><?php echo $row['rank'] !== '' ? h($row['rank']) : '—'; ?></span>
          <span class="rank-sub">
            <b><?php echo h($season); ?>强度评级</b>
            <span>
              <span class="num"><?php echo number_format((int)$row['votes']); ?></span> 票
              <span class="sep">·</span> 较上赛季
              <?php if ($votes_delta > 0): ?>
                <i class="pos">+<?php echo number_format($votes_delta); ?></i>
              <?php elseif ($votes_delta < 0): ?>
                <i class="neg"><?php echo number_format($votes_delta); ?></i>
              <?php else: ?>
                <i>±0</i>
              <?php endif; ?>
            </span>
          </span>
        </div>

        <div class="de-hl">
          <?php if ($row['shelf_status'] !== ''): ?>
            <?php $off = (mb_strpos((string)$row['shelf_status'], '下架') !== false); ?>
            <span class="tag <?php echo $off ? 'tag--warn' : 'tag--ok'; ?>">
              <?php echo $off ? '已下架' : h($row['shelf_status']); ?><?php echo $row['shelf_paid'] !== '' ? ' · ' . h($row['shelf_paid']) : ''; ?>
            </span>
          <?php endif; ?>
          <?php if ($row['token_name'] === ''): ?>
            <span class="tag tag--warn">信物名官方未公开</span>
          <?php endif; ?>
          <?php if ($row['token_category'] !== ''): ?>
            <span class="tag">信物 · <?php echo h(token_cat($row['token_category'])); ?></span>
          <?php endif; ?>
          <?php if ($row['fruit_label'] !== ''): ?>
            <span class="tag">资质果 · <?php echo h($row['fruit_label']); ?></span>
          <?php endif; ?>
          <?php if ((int)$row['likes'] > 0): ?>
            <span class="tag tag--info"><?php echo number_format((int)$row['likes']); ?> 人点赞</span>
          <?php endif; ?>
          <?php if (!$has_stats): ?>
            <span class="tag tag--warn">缺五维数据</span>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <div class="de-grid">

      <aside class="de-side">
        <section class="panel">
          <h2>资质成长 <span class="more">Lv.20 · 归一化条形</span></h2>
          <?php if ($has_stats): ?>
            <div class="bars">
              <?php foreach ($dims as $k => $label):
                  $v = $row[$k] !== null ? (float)$row[$k] : null;
                  $pct = ($v !== null && $maxes[$k] > 0) ? min(100, $v / $maxes[$k] * 100) : 0;
              ?>
              <div class="bar-row">
                <span class="k"><?php echo h($label); ?></span>
                <span class="track"><span class="fill" style="width:<?php echo round($pct, 1); ?>%"></span></span>
                <span class="v"><?php echo $v === null ? '—' : number_format($v, 2); ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <p class="note">
              数值来源：<?php echo $row['stat_source'] !== '' ? h($row['stat_source']) : '官方五维原始值'; ?>。条形按全库该项最高值等比缩放。
            </p>
          <?php else: ?>
            <p class="note">这只还没有五维数据，可以在后台补。</p>
          <?php endif; ?>
        </section>

        <section class="panel">
          <h2>糖果偏好</h2>
          <div class="kv">
            <div>
              <span class="k">喜欢</span>
              <span class="v"><?php echo $row['love_snack'] !== '' ? '<span class="tag tag--ok">' . h($row['love_snack']) . '</span>' : '<span class="dash">—</span>'; ?></span>
            </div>
            <div>
              <span class="k">讨厌</span>
              <span class="v"><?php echo $row['hate_snack'] !== '' ? '<span class="tag tag--warn">' . h($row['hate_snack']) . '</span>' : '<span class="dash">—</span>'; ?></span>
            </div>
            <div>
              <span class="k">资质果</span>
              <span class="v"><?php
                if ($row['fruit_label'] !== '') {
                    echo '<span class="tag tag--info">' . h($row['fruit_label']);
                    echo $row['fruit_apt'] !== '' ? ' · ' . h($row['fruit_apt']) : '';
                    echo '</span>';
                } else {
                    echo '<span class="dash">—</span>';
                } ?></span>
            </div>
          </div>
          <p class="note">投喂对应的糖果，亲密度涨得更快；喂到讨厌的口味不会有加成。</p>
        </section>
      </aside>

      <div class="de-main">

        <section class="panel">
          <h2>信物 / 获取方式</h2>
          <div class="skill-head">
            <?php if ($row['token_name'] !== ''): ?>
              <span class="nm"><?php echo h($row['token_name']); ?></span>
            <?php else: ?>
              <span class="tag tag--warn">信物名官方未公开</span>
            <?php endif; ?>
            <?php if ($row['token_category'] !== ''): ?>
              <span class="pill on"><?php echo h(token_cat($row['token_category'])); ?></span>
            <?php endif; ?>
            <?php if ($row['token_world'] !== ''): ?>
              <span class="pill"><?php echo h(token_world($row['token_world'])); ?></span>
            <?php endif; ?>
          </div>

          <?php if ($row['token_detail'] !== ''): ?>
            <?php foreach (preg_split('/\n+/u', trim((string)$row['token_detail'])) as $p): ?>
              <p class="prose"><?php echo h($p); ?></p>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="prose">暂无记录。</p>
          <?php endif; ?>

          <?php if ($row['token_hint'] !== ''): ?>
            <p class="note">一句话记法：<?php echo h($row['token_hint']); ?></p>
          <?php endif; ?>
        </section>

        <section class="panel">
          <h2>专属技能</h2>
          <?php if ($row['skill_name'] !== ''): ?>
            <div class="skill">
              <div class="skill-head">
                <span class="nm"><?php echo h($row['skill_name']); ?></span>
                <?php if ($row['skill_type_label'] !== ''): ?>
                  <span class="pill on"><?php echo h($row['skill_type_label']); ?></span>
                <?php endif; ?>
                <?php if ((int)$row['skill_cd'] > 0): ?>
                  <span class="cd">冷却 <?php echo (int)$row['skill_cd']; ?> 秒</span>
                <?php endif; ?>
              </div>
              <?php if ($row['skill_detail'] !== ''): ?>
                <?php foreach (preg_split('/\n+/u', trim((string)$row['skill_detail'])) as $p): ?>
                  <p><?php echo h($p); ?></p>
                <?php endforeach; ?>
              <?php else: ?>
                <p>暂无技能详解。</p>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p class="prose">暂无专属技能记录。</p>
          <?php endif; ?>
        </section>

        <section class="panel">
          <h2>通用技能推荐 <?php if ($gskills): ?><span class="more"><?php echo count($gskills); ?> 个</span><?php endif; ?></h2>
          <?php if ($gskills): ?>
            <?php foreach (array_slice($gskills, 0, 3) as $gs):
                $gs_name = isset($gs['skillName']) ? $gs['skillName'] : (isset($gs['name']) ? $gs['name'] : '');
                if ($gs_name === '') {
                    continue;
                }
            ?>
            <div class="skill">
              <div class="skill-head">
                <span class="nm"><?php echo h($gs_name); ?></span>
                <?php if (!empty($gs['skillTypeLabel'])): ?>
                  <span class="pill"><?php echo h($gs['skillTypeLabel']); ?></span>
                <?php endif; ?>
                <?php if (!empty($gs['element'])): ?>
                  <span class="pill"><?php echo h($gs['element']); ?>属性</span>
                <?php endif; ?>
                <?php if (!empty($gs['cooldownSec'])): ?>
                  <span class="cd">冷却 <?php echo (int)$gs['cooldownSec']; ?> 秒</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($gs['detail'])): ?>
                <p><?php echo h($gs['detail']); ?></p>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="prose">官方还没给这只配推荐通用技能。</p>
          <?php endif; ?>
        </section>

        <section class="panel">
          <h2>图鉴简介</h2>
          <?php if ($row['intro'] !== ''): ?>
            <p class="prose"><?php echo h($row['intro']); ?></p>
          <?php else: ?>
            <p class="prose">这只的简介还空着，可以在后台补一段。</p>
          <?php endif; ?>
        </section>

        <section class="panel">
          <h2>基础资料</h2>
          <div class="kv">
            <div>
              <span class="k">实用性</span>
              <span class="v"><?php echo $row['info_practicality'] !== '' ? '<span class="tag tag--plain">' . h($row['info_practicality']) . '</span>' : '<span class="tag tag--warn">未收录</span>'; ?></span>
            </div>
            <div>
              <span class="k">推荐地图</span>
              <span class="v"><?php echo $row['info_maps'] !== '' ? '<span class="tag tag--plain">' . h($row['info_maps']) . '</span>' : '<span class="tag tag--warn">未收录</span>'; ?></span>
            </div>
            <div>
              <span class="k">上架状态</span>
              <span class="v"><?php
                if ($row['shelf_status'] !== '') {
                    $off = (mb_strpos((string)$row['shelf_status'], '下架') !== false);
                    echo '<span class="tag ' . ($off ? 'tag--warn' : 'tag--ok') . '">' . h($row['shelf_status']) . '</span>';
                    echo $row['shelf_paid'] !== '' ? ' <span class="dash">·</span> ' . h($row['shelf_paid']) : '';
                } else {
                    echo '<span class="dash">—</span>';
                } ?></span>
            </div>
            <div>
              <span class="k">玩家点赞</span>
              <span class="v"><?php echo (int)$row['likes'] > 0
                  ? '<span class="tag tag--info">' . number_format((int)$row['likes']) . ' 人点赞</span>'
                  : '<span class="dash">—</span>'; ?></span>
            </div>
            <div>
              <span class="k">一句话提示</span>
              <span class="v"><?php echo $row['short_hint'] !== '' ? h($row['short_hint']) : '<span class="tag tag--warn">未收录</span>'; ?></span>
            </div>
            <div>
              <span class="k">数据快照</span>
              <span class="v"><span class="tag tag--plain"><?php echo h(meta_get('votes_updated_at', meta_get('imported_at', ''))); ?></span>
                <span class="dash">·</span> 本站离线快照，不联网</span>
            </div>
          </div>
        </section>

        <section class="panel">
          <h2>同属性 / 同定位</h2>
          <?php if ($related): ?>
            <div class="related">
              <?php foreach ($related as $r2):
                  $ri = $r2['img'] !== '' ? img_url($r2['img']) : '';
                  $rhas = $ri !== '' && is_file(__DIR__ . '/' . $ri);
              ?>
              <a class="mini" href="detail.php?name=<?php echo rawurlencode($r2['name']); ?>">
                <?php if ($rhas): ?><img src="<?php echo h($ri); ?>" alt="" loading="lazy" width="40" height="40"><?php else: ?><span class="ph"></span><?php endif; ?>
                <span class="t"><b><?php echo h($r2['name']); ?></b><span><?php echo h($r2['rank']); ?></span></span>
              </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="prose">没有同类可推荐。</p>
          <?php endif; ?>
        </section>

      </div>
    </div>
  </div>
</main>

<footer class="foot">
  <div class="in">
    <?php if ($has_img): ?>
      <a href="<?php echo h($img); ?>" target="_blank" rel="noopener">查看「<?php echo h($row['name']); ?>」立绘原图</a> ·
    <?php endif; ?>
    资料来自本地数据库，最后更新 <?php echo h($row['updated_at'] !== '' ? $row['updated_at'] : '—'); ?>
  </div>
</footer>

</body>
</html>