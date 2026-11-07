<?php
require __DIR__ . '/lib/db.php';

$ranks = $GLOBALS['AIBI_RANKS'];

$q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$el     = isset($_GET['el']) ? trim((string)$_GET['el']) : '';
$rarity = isset($_GET['rarity']) ? trim((string)$_GET['rarity']) : '';
$lineup = isset($_GET['lineup']) ? trim((string)$_GET['lineup']) : '';
$rank   = isset($_GET['rank']) ? trim((string)$_GET['rank']) : '';
$sort   = isset($_GET['sort']) ? (string)$_GET['sort'] : 'rank';
$allowed_sorts = array('rank', 'votes', 'name', 'hp', 'atk');
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'rank';
}

$filter = array('q' => $q, 'el' => $el, 'rarity' => $rarity, 'lineup' => $lineup, 'rank' => $rank, 'sort' => $sort);
$all    = query_abby($filter);
$total  = count($all);

$per   = 48;
$pages = max(1, (int)ceil($total / $per));
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page  = min($page, $pages);
$rows  = array_slice($all, ($page - 1) * $per, $per);

$facets = facet_counts();
$rank_counts = array();
$rank_max = 1;
foreach ($facets['rank'] as $r) {
    $rank_counts[$r['v']] = (int)$r['n'];
    $rank_max = max($rank_max, (int)$r['n']);
}
foreach ($ranks as $r) {
    if (!isset($rank_counts[$r])) {
        $rank_counts[$r] = 0;
    }
}

/* 阶梯注解：前几档的覆盖率 —— 让这一屏除了列举之外还有一句发现 */
$site_all = total_abby();
$top3 = 0;
foreach (array('T0', 'T0.5', 'T1') as $r) {
    $top3 += $rank_counts[$r];
}
$share = $site_all > 0 ? (int)round($top3 / $site_all * 100) : 0;

$season   = meta_get('season_label', '');
$cutoff   = meta_get('cutoff_label', '');
$updated  = meta_get('generated', '');

/** 保留当前筛选、只改几个参数，生成链接 */
function url_with($over = array())
{
    $base = array(
        'q' => isset($_GET['q']) ? $_GET['q'] : '',
        'el' => isset($_GET['el']) ? $_GET['el'] : '',
        'rarity' => isset($_GET['rarity']) ? $_GET['rarity'] : '',
        'lineup' => isset($_GET['lineup']) ? $_GET['lineup'] : '',
        'rank' => isset($_GET['rank']) ? $_GET['rank'] : '',
        'sort' => isset($_GET['sort']) ? $_GET['sort'] : '',
    );
    $m = array_merge($base, $over);
    $pairs = array();
    foreach ($m as $k => $v) {
        if ($v !== '' && $v !== null) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
        }
    }
    return './' . ($pairs ? '?' . implode('&', $pairs) : '');
}

$has_filter = ($q !== '' || $el !== '' || $rarity !== '' || $lineup !== '' || $rank !== '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h(AIBI_TITLE); ?><?php echo $has_filter ? ' · 筛选结果' : ' · 全部 ' . $total . ' 只'; ?></title>
<link rel="stylesheet" href="assets/style.css">
<meta name="theme-color" content="#171719">

</head>
<body>

<a class="skip" href="#main">跳到主要内容</a>


<header class="topbar">
  <div class="topbar-in">
    <div class="brand">
      <svg class="mark" viewBox="0 0 22 22" fill="none" aria-hidden="true">
        <rect x="1.5" y="5.5" width="13" height="15" rx="2.2" stroke="currentColor" stroke-width="1.4"/>
        <rect x="7" y="1.5" width="13" height="15" rx="2.2" stroke="currentColor" stroke-width="1.4" fill="#f5f5f3"/>
        <path d="M10.5 7.2h6M10.5 10.2h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
      </svg>
      <h1><?php echo h(AIBI_TITLE); ?></h1>
      <span class="scope"><b><?php echo $site_all; ?></b> 只 · <?php echo h($season); ?></span>
    </div>

    <form class="search" id="sf" method="get" action="./" role="search">
      <svg class="ic" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.4"/>
        <path d="M10.4 10.4 14 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
      <input type="search" name="q" value="<?php echo h($q); ?>" placeholder="搜名字、技能、糖果、信物…" aria-label="搜索艾比" autocomplete="off" spellcheck="false" enterkeyhint="search">
      <?php if ($el !== ''): ?><input type="hidden" name="el" value="<?php echo h($el); ?>"><?php endif; ?>
      <?php if ($rarity !== ''): ?><input type="hidden" name="rarity" value="<?php echo h($rarity); ?>"><?php endif; ?>
      <?php if ($lineup !== ''): ?><input type="hidden" name="lineup" value="<?php echo h($lineup); ?>"><?php endif; ?>
      <?php if ($rank !== ''): ?><input type="hidden" name="rank" value="<?php echo h($rank); ?>"><?php endif; ?>
      <?php if ($sort !== 'rank'): ?><input type="hidden" name="sort" value="<?php echo h($sort); ?>"><?php endif; ?>
      <button class="go" type="submit">
        <span class="go-t">搜索</span>
        <svg class="go-ic" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8h9M8.5 3.5 13 8l-4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
    </form>

    <a class="toplink" href="admin/">后台</a>
  </div>
</header>

<main class="wrap" id="main">
  <div class="layout">

    <nav class="pane" id="filters" aria-label="筛选" tabindex="-1">
      <div class="pane-head">
        <h2>筛选</h2>
        <?php if ($has_filter): ?>
          <a class="clear" href="./">清空</a>
        <?php endif; ?>
      </div>

      <?php
      $groups = array(
          array('title' => '属性', 'key' => 'el', 'field' => 'element', 'cur' => $el),
          array('title' => '稀有度', 'key' => 'rarity', 'field' => 'rarity', 'cur' => $rarity),
          array('title' => '定位', 'key' => 'lineup', 'field' => 'lineup', 'cur' => $lineup),
      );
      foreach ($groups as $g):
          // 同字段的计数：属性按 element 统计，其余按自身
          $counts = array();
          foreach ($facets[$g['field']] as $r) {
              $counts[$r['v']] = (int)$r['n'];
          }
          $keys = array_keys($counts);
          usort($keys, function ($a, $b) use ($counts) {
              return $counts[$b] - $counts[$a];
          });
      ?>
      <section class="facet">
        <h3><?php echo h($g['title']); ?></h3>
        <ul>
          <?php foreach ($keys as $k): ?>
          <li>
            <a href="<?php echo h(url_with(array($g['key'] => $g['cur'] === $k ? '' : $k, 'page' => ''))); ?>"
               <?php echo $g['cur'] === $k ? 'aria-current="true"' : ''; ?>>
              <span class="lab"><?php echo h($k); ?></span>
              <span class="n"><?php echo $counts[$k]; ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endforeach; ?>
    </nav>

    <section class="results">

      <div class="resbar">
        <p>
          <?php if ($has_filter): ?>
            筛选出 <span class="sum"><?php echo $total; ?></span> 只 / 全库 <span class="sum"><?php echo $site_all; ?></span>
          <?php else: ?>
            共 <span class="sum"><?php echo $total; ?></span> 只
          <?php endif; ?>
          <span class="sep">·</span> <?php echo h($season !== '' ? $season : '不按赛季'); ?>
          <?php $snap = meta_get('votes_updated_at', meta_get('generated', '')); ?>
          <?php if ($snap !== ''): ?>
            <span class="sep">·</span> 票数快照 <?php echo h(mb_substr($snap, 0, 10, 'UTF-8')); ?>
          <?php endif; ?>
        </p>
        <div class="tools">
          <?php $active_n = ($el !== '' ? 1 : 0) + ($rarity !== '' ? 1 : 0) + ($lineup !== '' ? 1 : 0) + ($rank !== '' ? 1 : 0); ?>
          <button class="filter-btn" type="button" aria-expanded="false" aria-controls="filters">
            筛选<?php if ($active_n > 0): ?><span class="dot"><?php echo $active_n; ?></span><?php endif; ?>
          </button>
          <form class="sortsel" method="get" action="./">
            <?php foreach (array('q' => $q, 'el' => $el, 'rarity' => $rarity, 'lineup' => $lineup, 'rank' => $rank) as $k => $v): ?>
              <?php if ($v !== ''): ?><input type="hidden" name="<?php echo h($k); ?>" value="<?php echo h($v); ?>"><?php endif; ?>
            <?php endforeach; ?>
            <label for="sort">排序</label>
            <select id="sort" name="sort" onchange="this.form.submit()">
              <option value="rank"<?php echo $sort === 'rank' ? ' selected' : ''; ?>>按评级</option>
              <option value="votes"<?php echo $sort === 'votes' ? ' selected' : ''; ?>>按票数</option>
              <option value="name"<?php echo $sort === 'name' ? ' selected' : ''; ?>>按名字</option>
              <option value="hp"<?php echo $sort === 'hp' ? ' selected' : ''; ?>>按生命成长</option>
              <option value="atk"<?php echo $sort === 'atk' ? ' selected' : ''; ?>>按物攻成长</option>
            </select>
          </form>
        </div>
      </div>

      <div class="ladder">
        <div class="ladder-top">
          <h2>评级阶梯</h2>
          <span class="note"><?php echo h($season); ?> · T0–T1 共 <b><?php echo $top3; ?></b> 只，占 <b><?php echo $share; ?>%</b></span>
        </div>
        <div class="ladder-bars">
          <?php foreach ($ranks as $r):
              $n = $rank_counts[$r];
              $hp = max(3, (int)round($n / $rank_max * 44));
              $cur = ($rank === $r);
          ?>
          <a class="tier" href="<?php echo h(url_with(array('rank' => $cur ? '' : $r, 'page' => ''))); ?>"
             <?php echo $cur ? 'aria-current="true"' : ''; ?>
             aria-label="评级 <?php echo h($r); ?>，<?php echo $n; ?> 只">
            <span class="b"><span class="v"><?php echo $n; ?></span><i style="height:<?php echo $hp; ?>px"></i></span>
            <span class="k"><?php echo h($r); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!$rows): ?>
        <div class="empty">
          没有符合条件的艾比。<a href="./">清空筛选</a>看看全部 <?php echo $site_all; ?> 只。
        </div>
      <?php else: ?>

      <div class="list">
        <div class="lhead">
          <span></span>
          <span>名字</span>
          <span>主词条</span>
          <span>信物</span>
          <span class="r">票数</span>
          <span class="r">评级</span>
        </div>

        <?php foreach ($rows as $r):
            $img = $r['img'] !== '' ? img_url($r['img']) : '';
            $has_img = $img !== '' && is_file(__DIR__ . '/' . $img);
            $token = $r['token_name'] !== '' ? $r['token_name'] : ($r['token_hint'] !== '' ? $r['token_hint'] : '—');
        ?>
        <a class="row" href="detail.php?name=<?php echo rawurlencode($r['name']); ?>">
          <span class="pic<?php echo $has_img ? '' : ' none'; ?>">
            <?php if ($has_img): ?>
              <img src="<?php echo h($img); ?>" alt="<?php echo h($r['name']); ?> 立绘" loading="lazy" width="44" height="44">
            <?php else: ?>
              <?php echo h(mb_substr($r['name'], 0, 1, 'UTF-8')); ?>
            <?php endif; ?>
          </span>
          <span class="who">
            <span class="nm"><?php echo h($r['name']); ?></span>
            <span class="sub">
              <?php $ico = element_icon($r['element']); ?>
              <?php if ($ico !== '' && is_file(__DIR__ . '/' . $ico)): ?>
                <img src="<?php echo h($ico); ?>" alt="" width="12" height="12">
              <?php endif; ?>
              <?php echo h($r['element']); ?><?php echo $r['element2'] !== '' ? '/' . h($r['element2']) : ''; ?>
              <span class="sep">·</span><span class="rs" style="color:<?php echo rarity_color($r['rarity']); ?>"><?php echo h($r['rarity']); ?></span>
              <span class="sep">·</span><?php echo h($r['lineup']); ?>
              <span class="subvotes"><?php echo $r['votes'] > 0 ? number_format((int)$r['votes']) . ' 票' : ''; ?></span>
            </span>
          </span>
          <span class="cell"><span class="v"><?php echo $r['main_stats'] !== '' ? h($r['main_stats']) : '—'; ?></span></span>
          <span class="cell tok"><?php echo $r['token_name'] !== ''
              ? '<span class="tag tag--plain">' . h($r['token_name']) . '</span>'
              : ($r['token_hint'] !== '' ? '<span class="tag tag--plain">' . h($r['token_hint']) . '</span>' : '<span class="tag tag--warn">未公开</span>'); ?></span>
          <span class="votes"><?php echo $r['votes'] > 0 ? number_format((int)$r['votes']) : '—'; ?></span>
          <span class="rk" style="--rk:<?php echo rank_color($r['rank']); ?>;color:<?php echo rank_color($r['rank']); ?>"><?php echo $r['rank'] !== '' ? h($r['rank']) : '—'; ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if ($pages > 1):
          $from = ($page - 1) * $per + 1;
          $to   = min($total, $page * $per);
      ?>
      <div class="pager">
        <span class="range"><?php echo $from; ?>–<?php echo $to; ?> / <?php echo $total; ?></span>
        <span class="sp"></span>
        <a class="<?php echo $page <= 1 ? 'off' : ''; ?>" href="<?php echo h(url_with(array('page' => $page - 1))); ?>" rel="prev">上一页</a>
        <a class="<?php echo $page >= $pages ? 'off' : ''; ?>" href="<?php echo h(url_with(array('page' => $page + 1))); ?>" rel="next">下一页</a>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </section>
  </div>
</main>

<footer class="foot">
  <div class="in">
    数据取自「艾比手册」小程序与官方接口，一次性抓取后全部落到本地 SQLite（<?php echo $site_all; ?> 只 · 更新于 <?php echo h($updated !== '' ? $updated : '—'); ?>）。
    页面不请求任何外部地址。
  </div>
</footer>

<div class="scrim" aria-hidden="true"></div>

<script src="assets/app.js" defer></script>

</body>
</html>
