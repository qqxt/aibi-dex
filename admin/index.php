<?php
/**
 * 后台首页：未登录显示登录 / 首次设置密码；已登录显示资料列表
 */
require_once __DIR__ . '/_boot.php';

$acc = admin_account();
$error = '';

// ---- 首次使用：设置密码
if (!$acc) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $p1 = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
        $p2 = isset($_POST['pass2']) ? (string)$_POST['pass2'] : '';
        if (strlen($p1) < 6) {
            $error = '密码至少 6 位';
        } elseif ($p1 !== $p2) {
            $error = '两次输入的密码不一致';
        } else {
            admin_set_password($p1);
            admin_login(AIBI_ADMIN_USER, $p1);
            header('Location: index.php');
            exit;
        }
    }
    admin_head('初始化');
    echo '<section class="card narrow">';
    echo '<h1>设置后台密码</h1>';
    echo '<p class="hint">第一次进入后台，先给自己设一个密码。密码只保存 <code>password_hash</code> 结果，'
        . '文件在 <code>data/admin.json</code>，删掉它就回到这里重设。</p>';
    if ($error) {
        echo '<p class="err">' . h($error) . '</p>';
    }
    echo '<form method="post">' . csrf_field();
    echo '<label>新密码<input type="password" name="pass" autocomplete="new-password" required></label>';
    echo '<label>再输一遍<input type="password" name="pass2" autocomplete="new-password" required></label>';
    echo '<button type="submit" class="primary">保存密码并登录</button>';
    echo '</form></section>';
    admin_foot();
    exit;
}

// ---- 登录
if (!admin_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = isset($_POST['user']) ? (string)$_POST['user'] : '';
    $p = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
    if (admin_login($u, $p)) {
        header('Location: index.php');
        exit;
    }
    $error = '账号或密码不对';
    sleep(1);
}

if (!admin_logged_in()) {
    admin_head('登录');
    echo '<section class="card narrow">';
    echo '<h1>后台登录</h1>';
    if ($error) {
        echo '<p class="err">' . h($error) . '</p>';
    }
    echo '<form method="post">' . csrf_field();
    echo '<label>账号<input type="text" name="user" value="' . h(AIBI_ADMIN_USER) . '" autocomplete="username" required></label>';
    echo '<label>密码<input type="password" name="pass" autocomplete="current-password" required></label>';
    echo '<button type="submit" class="primary">登录</button>';
    echo '</form></section>';
    admin_foot();
    exit;
}

// ---- 列表
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$el = isset($_GET['el']) ? (string)$_GET['el'] : '';
$rows = query_abby(array('q' => $q, 'el' => $el, 'rarity' => '', 'lineup' => '', 'rank' => '', 'sort' => 'rank'));
$facets = facet_counts();
$flash = flash_get();

admin_head('资料列表', 'list');
if ($flash) {
    echo '<p class="flash ' . h($flash['kind']) . '">' . h($flash['msg']) . '</p>';
}
echo '<section class="head">';
echo '<div><h1>资料列表</h1><p class="hint">共 <b>' . total_abby() . '</b> 只 · 五维 '
    . (int)db()->query('SELECT COUNT(*) FROM abby WHERE hp IS NOT NULL')->fetchColumn()
    . ' 只 · 信物 ' . (int)db()->query('SELECT COUNT(*) FROM abby WHERE token_name != ""')->fetchColumn()
    . ' 只 · 数据更新于 ' . h(meta_get('imported_at', '—')) . '</p></div>';
echo '<a class="primary btn" href="edit.php">＋ 新增艾比</a>';
echo '</section>';

echo '<form class="filters" method="get">';
echo '<input type="search" name="q" value="' . h($q) . '" placeholder="搜名字 / 技能 / 信物 / 简介">';
echo '<select name="el"><option value="">全部属性</option>';
foreach ($facets['element'] as $f) {
    echo '<option value="' . h($f['v']) . '"' . ($el === $f['v'] ? ' selected' : '') . '>'
        . h($f['v']) . '（' . (int)$f['n'] . '）</option>';
}
echo '</select><button type="submit">筛选</button>';
if ($q !== '' || $el !== '') {
    echo '<a class="link" href="index.php">清空</a>';
}
echo '</form>';

echo '<table class="list"><thead><tr>';
echo '<th>名字</th><th>属性</th><th>稀有度</th><th>定位</th><th>评级</th><th>五维</th><th>信物</th><th>来源</th><th></th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $missing = array();
    if ($r['hp'] === null) {
        $missing[] = '五维';
    }
    if ($r['token_name'] === '') {
        $missing[] = '信物';
    }
    if ($r['love_snack'] === '') {
        $missing[] = '糖果';
    }
    echo '<tr>';
    echo '<td class="name"><a href="../detail.php?name=' . rawurlencode($r['name']) . '" target="_blank">'
        . h($r['name']) . '</a></td>';
    echo '<td>' . h($r['element'] . ($r['element2'] ? '/' . $r['element2'] : '')) . '</td>';
    echo '<td>' . h($r['rarity']) . '</td>';
    echo '<td>' . h($r['lineup']) . '</td>';
    echo '<td>' . h($r['rank']) . '</td>';
    echo '<td class="num">' . ($r['hp'] === null ? '<span class="miss">缺</span>'
        : sprintf('%.1f / %.1f / %.1f / %.1f / %.1f', $r['hp'], $r['atk'], $r['sp_atk'], $r['sp_def'], $r['dfn'])) . '</td>';
    echo '<td>' . ($r['token_name'] === '' ? '<span class="miss">缺</span>' : h($r['token_name'])) . '</td>';
    echo '<td class="dim">' . ($missing ? implode('、', $missing) : '齐') . '</td>';
    echo '<td class="ops"><a href="edit.php?id=' . (int)$r['id'] . '">编辑</a>';
    echo '<form method="post" action="delete.php" class="inline" onsubmit="return confirm(\'删除 ' . h($r['name']) . '？\')">'
        . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . '<button class="link danger" type="submit">删除</button></form></td>';
    echo '</tr>';
}
if (!$rows) {
    echo '<tr><td colspan="9" class="empty">没有符合条件的艾比</td></tr>';
}
echo '</tbody></table>';
admin_foot();
