<?php
/**
 * 导入数据：把 data/aibi.json 写进 SQLite
 *
 * 用法（命令行）：
 *   php build/import.php                 清空后重灌
 *   php build/import.php --keep          保留后台改过的内容（按名字对上的行不动）
 *   php build/import.php --file=xx.json  指定数据文件
 *   php build/import.php --votes-only    只刷新票数 / 评级（其余字段与后台手改都不动）
 *
 * 注意：清空重灌会覆盖后台手改的字段；想让手改内容不被冲掉，导入时加 --keep，
 * 或者先在后台把自己改的条目标记「锁定简介」之外再备份数据库文件 data/aibi.db。
 */
require_once dirname(__DIR__) . '/lib/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('仅限命令行执行：php build/import.php');
}

$json = dirname(__DIR__) . '/data/aibi.json';
$keep = false;
$votes_only = false;
foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $json = substr($arg, 7);
    } elseif ($arg === '--keep') {
        $keep = true;
    } elseif ($arg === '--votes-only') {
        $votes_only = true;
    }
}
if (!is_file($json)) {
    fwrite(STDERR, "找不到数据文件: {$json}\n先跑 python build/export.py\n");
    exit(1);
}
$data = json_decode(file_get_contents($json), true);
if (!is_array($data) || !isset($data['list'])) {
    fwrite(STDERR, "数据文件格式不对（需要 {\"meta\":{},\"list\":[]}）\n");
    exit(1);
}
$rows = $data['list'];
$meta = isset($data['meta']) ? $data['meta'] : array();

if ($votes_only) {
    $pdo = db();
    $pdo->beginTransaction();
    // votes_prev = 上赛季票数（固定基线，由 export.py 从 s12 榜单写入）
    $st  = $pdo->prepare('UPDATE abby SET rank = :rank, votes = :votes, votes_prev = :prev,
                          updated_at = :now WHERE name = :name');
    $n = 0;
    $moved = 0;
    foreach ($rows as $r) {
        if (empty($r['name'])) {
            continue;
        }
        $old = get_abby($r['name']);
        if (!$old) {
            continue;
        }
        if ((int)$old['votes'] !== (int)$r['votes']) {
            $moved++;
        }
        $st->execute(array(
            ':rank'  => isset($r['rank']) ? $r['rank'] : $old['rank'],
            ':votes' => isset($r['votes']) ? (int)$r['votes'] : (int)$old['votes'],
            ':prev'  => isset($r['votes_prev']) ? (int)$r['votes_prev'] : (int)$old['votes_prev'],
            ':now'   => date('Y-m-d H:i:s'),
            ':name'  => $old['name'],
        ));
        $n++;
    }
    foreach (array('season', 'season_label', 'season_start', 'cutoff_label', 'generated') as $k) {
        if (isset($meta[$k]) && $meta[$k] !== '') {
            meta_set($k, is_array($meta[$k]) ? json_encode($meta[$k], JSON_UNESCAPED_UNICODE) : $meta[$k]);
        }
    }
    meta_set('votes_updated_at', date('Y-m-d H:i:s'));
    $pdo->commit();
    echo "票数刷新完成：对齐 {$n} 只，票数有变化 {$moved} 只\n";
    echo '  赛季 ' . meta_get('season_label', '') . ' ' . meta_get('cutoff_label', '') . "\n";
    exit(0);
}

$pdo = db();
$pdo->beginTransaction();
if (!$keep) {
    $pdo->exec('DELETE FROM abby');
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'abby'");
}

$n = 0;
$skip = 0;
foreach ($rows as $r) {
    if ($keep) {
        $old = get_abby($r['name']);
        if ($old) {
            $skip++;
            continue;
        }
    }
    $row = array();
    foreach (abby_fields() as $f) {
        if ($f === 'intro_locked' && $keep) {
            $row[$f] = 0;
        } elseif ($f === 'source' && !isset($r[$f])) {
            $row[$f] = 'import';
        } else {
            $row[$f] = isset($r[$f]) ? $r[$f] : '';
        }
    }
    if ($row['intro'] === '') {
        $row['intro'] = '';
    }
    abby_save($row, 0);
    $n++;
}

$pdo->exec('DELETE FROM meta');
foreach ($meta as $k => $v) {
    meta_set($k, is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
}
meta_set('imported_at', date('Y-m-d H:i:s'));
$pdo->commit();

$full = (int)$pdo->query('SELECT COUNT(*) FROM abby WHERE hp IS NOT NULL')->fetchColumn();
$tok = (int)$pdo->query('SELECT COUNT(*) FROM abby WHERE token_name != ""')->fetchColumn();
echo "导入完成：新增 {$n} 条" . ($skip ? "，跳过已有 {$skip} 条（--keep）" : '') . "\n";
echo "  带五维 {$full} 只 / 带信物 {$tok} 只 / 总计 " . total_abby() . " 只\n";
echo '  数据库：' . AIBI_DB . "\n";
