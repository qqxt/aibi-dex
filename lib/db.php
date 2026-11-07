<?php
/**
 * SQLite 访问层：建表 + 查询 + 后台写入
 */
require_once __DIR__ . '/config.php';

function db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = dirname(AIBI_DB);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $pdo = new PDO('sqlite:' . AIBI_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        $pdo->exec('PRAGMA journal_mode=WAL');
    } catch (Exception $e) {
        // 个别环境不允许 WAL，忽略
    }
    ensure_schema($pdo);
    return $pdo;
}

/** 表里所有可写字段（后台表单、导入脚本共用） */
function abby_fields()
{
    return array(
        'name', 'element', 'element2', 'rarity', 'lineup', 'skill_name', 'main_stats', 'img',
        'rank', 'votes', 'rank_prev', 'votes_prev', 'short_hint',
        'hp', 'atk', 'dfn', 'sp_atk', 'sp_def', 'stat_source',
        'love_snack', 'hate_snack',
        'token_name', 'token_category', 'token_hint', 'token_detail', 'token_world',
        'skill_type', 'skill_type_label', 'skill_cd', 'skill_detail',
        'general_skills', 'fruit_label', 'fruit_apt', 'likes',
        'info_practicality', 'info_maps', 'shelf_status', 'shelf_paid',
        'intro', 'intro_locked', 'source', 'has_extra',
    );
}

function abby_float_fields()
{
    return array('hp', 'atk', 'dfn', 'sp_atk', 'sp_def');
}

function abby_int_fields()
{
    return array('votes', 'votes_prev', 'skill_cd', 'likes', 'intro_locked', 'has_extra');
}

function ensure_schema(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS abby (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        element TEXT DEFAULT "",
        element2 TEXT DEFAULT "",
        rarity TEXT DEFAULT "",
        lineup TEXT DEFAULT "",
        skill_name TEXT DEFAULT "",
        main_stats TEXT DEFAULT "",
        img TEXT DEFAULT "",
        rank TEXT DEFAULT "",
        votes INTEGER DEFAULT 0,
        rank_prev TEXT DEFAULT "",
        votes_prev INTEGER DEFAULT 0,
        hp REAL, atk REAL, dfn REAL, sp_atk REAL, sp_def REAL,
        stat_source TEXT DEFAULT "",
        love_snack TEXT DEFAULT "",
        hate_snack TEXT DEFAULT "",
        token_name TEXT DEFAULT "",
        token_category TEXT DEFAULT "",
        token_hint TEXT DEFAULT "",
        token_detail TEXT DEFAULT "",
        token_world TEXT DEFAULT "",
        skill_type TEXT DEFAULT "",
        skill_type_label TEXT DEFAULT "",
        skill_cd INTEGER DEFAULT 0,
        skill_detail TEXT DEFAULT "",
        general_skills TEXT DEFAULT "",
        fruit_label TEXT DEFAULT "",
        fruit_apt TEXT DEFAULT "",
        likes INTEGER DEFAULT 0,
        info_practicality TEXT DEFAULT "",
        info_maps TEXT DEFAULT "",
        shelf_status TEXT DEFAULT "",
        shelf_paid TEXT DEFAULT "",
        intro TEXT DEFAULT "",
        intro_locked INTEGER DEFAULT 0,
        source TEXT DEFAULT "",
        has_extra INTEGER DEFAULT 0,
        created_at TEXT DEFAULT "",
        updated_at TEXT DEFAULT ""
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_abby_element ON abby(element)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_abby_rank ON abby(rank)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');

    // 老库升级：缺哪列补哪列
    $have = array();
    foreach ($pdo->query('PRAGMA table_info(abby)') as $c) {
        $have[$c['name']] = strtoupper((string)$c['type']);
    }
    $want = array(
        'element2' => 'TEXT', 'stat_source' => 'TEXT', 'token_name' => 'TEXT', 'token_detail' => 'TEXT',
        'skill_type' => 'TEXT', 'skill_type_label' => 'TEXT', 'skill_cd' => 'INTEGER',
        'skill_detail' => 'TEXT', 'general_skills' => 'TEXT', 'fruit_label' => 'TEXT',
        'fruit_apt' => 'TEXT', 'likes' => 'INTEGER', 'info_practicality' => 'TEXT',
        'info_maps' => 'TEXT', 'shelf_status' => 'TEXT', 'shelf_paid' => 'TEXT',
        'intro' => 'TEXT', 'intro_locked' => 'INTEGER', 'source' => 'TEXT',
        'created_at' => 'TEXT', 'updated_at' => 'TEXT', 'has_extra' => 'INTEGER',
    );
    foreach ($want as $col => $type) {
        if (!isset($have[$col])) {
            $pdo->exec('ALTER TABLE abby ADD COLUMN ' . $col . ' ' . $type . ' DEFAULT ""');
        }
    }
}

function meta_all()
{
    $out = array();
    try {
        foreach (db()->query('SELECT k, v FROM meta') as $r) {
            $out[$r['k']] = $r['v'];
        }
    } catch (Exception $e) {
        // 空库
    }
    return $out;
}

function meta_get($k, $def = '')
{
    $m = meta_all();
    return isset($m[$k]) ? $m[$k] : $def;
}

function meta_set($k, $v)
{
    $st = db()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $st->execute(array((string)$k, (string)$v));
}

/** 评级排序用的 SQL 片段 */
function rank_order_sql($col = 'rank')
{
    return 'CASE ' . $col . ' WHEN "T0" THEN 0 WHEN "T0.5" THEN 1 WHEN "T1" THEN 2'
        . ' WHEN "T1.5" THEN 3 WHEN "T2" THEN 4 WHEN "T3" THEN 5 WHEN "T4" THEN 6'
        . ' WHEN "T5" THEN 7 ELSE 99 END';
}

/**
 * 图鉴查询
 * $f: q / el / rarity / lineup / rank / sort(rank|votes|name|hp|atk)
 */
function query_abby($f)
{
    $where = array();
    $args = array();
    if (isset($f['q']) && $f['q'] !== '') {
        $cols = array('name', 'skill_name', 'main_stats', 'love_snack', 'hate_snack',
            'token_name', 'token_hint', 'token_detail', 'intro');
        $sub = array();
        foreach ($cols as $c) {
            $sub[] = $c . ' LIKE ?';
            $args[] = '%' . $f['q'] . '%';
        }
        $where[] = '(' . implode(' OR ', $sub) . ')';
    }
    foreach (array('el' => 'element', 'rarity' => 'rarity', 'lineup' => 'lineup', 'rank' => 'rank') as $key => $col) {
        if (isset($f[$key]) && $f[$key] !== '') {
            $where[] = $col . ' = ?';
            $args[] = $f[$key];
        }
    }
    $sql = 'SELECT * FROM abby' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $sort = isset($f['sort']) ? $f['sort'] : 'rank';
    switch ($sort) {
        case 'votes':
            $sql .= ' ORDER BY votes DESC, name ASC';
            break;
        case 'name':
            $sql .= ' ORDER BY name ASC';
            break;
        case 'hp':
            $sql .= ' ORDER BY IFNULL(hp,0) DESC, name ASC';
            break;
        case 'atk':
            $sql .= ' ORDER BY IFNULL(atk,0) DESC, name ASC';
            break;
        default:
            $sql .= ' ORDER BY ' . rank_order_sql() . ', votes DESC, name ASC';
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function facet_counts()
{
    $out = array();
    foreach (array('element', 'rarity', 'lineup', 'rank') as $col) {
        $out[$col] = db()->query('SELECT ' . $col . ' AS v, COUNT(*) AS n FROM abby
            WHERE ' . $col . ' != "" GROUP BY ' . $col . ' ORDER BY n DESC')->fetchAll();
    }
    return $out;
}

function get_abby($name)
{
    $st = db()->prepare('SELECT * FROM abby WHERE name = ?');
    $st->execute(array($name));
    $r = $st->fetch();
    return $r ? $r : null;
}

function get_abby_id($id)
{
    $st = db()->prepare('SELECT * FROM abby WHERE id = ?');
    $st->execute(array((int)$id));
    $r = $st->fetch();
    return $r ? $r : null;
}

function total_abby()
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM abby')->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/** 五维最大值（用于把条形归一化） */
function stat_max()
{
    try {
        $r = db()->query('SELECT MAX(MAX(IFNULL(hp,0), IFNULL(atk,0), IFNULL(dfn,0), IFNULL(sp_atk,0), IFNULL(sp_def,0))) FROM abby')->fetchColumn();
        return $r ? (float)$r : 100.0;
    } catch (Exception $e) {
        return 100.0;
    }
}

/** 全站五维各项最大值，详情页按维度归一化更准确 */
function stat_max_each()
{
    $out = array();
    try {
        $r = db()->query('SELECT MAX(IFNULL(hp,0)) hp, MAX(IFNULL(atk,0)) atk, MAX(IFNULL(dfn,0)) dfn,
            MAX(IFNULL(sp_atk,0)) sp_atk, MAX(IFNULL(sp_def,0)) sp_def FROM abby')->fetch();
        $out = $r ? $r : array();
    } catch (Exception $e) {
        $out = array();
    }
    foreach (array('hp', 'atk', 'dfn', 'sp_atk', 'sp_def') as $k) {
        $out[$k] = max(1.0, isset($out[$k]) ? (float)$out[$k] : 1.0);
    }
    return $out;
}

function related_abby($row, $limit = 8)
{
    $st = db()->prepare('SELECT name, img, element, rarity, lineup, rank FROM abby
        WHERE name != ? AND (element = ? OR lineup = ?)
        ORDER BY ' . rank_order_sql() . ', votes DESC LIMIT ' . (int)$limit);
    $st->execute(array($row['name'], $row['element'], $row['lineup']));
    return $st->fetchAll();
}

/** 后台：保存（有 id 就更新，没有就插入） */
function abby_save($data, $id = 0)
{
    $fields = abby_fields();
    $cols = array();
    $vals = array();
    foreach ($fields as $f) {
        if (!array_key_exists($f, $data)) {
            continue;
        }
        $cols[] = $f;
        if (in_array($f, abby_float_fields(), true)) {
            $vals[] = ($data[$f] === '' || $data[$f] === null) ? null : (float)$data[$f];
        } elseif (in_array($f, abby_int_fields(), true)) {
            $vals[] = (int)$data[$f];
        } else {
            $vals[] = (string)$data[$f];
        }
    }
    $now = date('Y-m-d H:i:s');
    if ($id > 0) {
        $set = array();
        foreach ($cols as $c) {
            $set[] = $c . ' = ?';
        }
        $set[] = 'updated_at = ?';
        $vals[] = $now;
        $vals[] = (int)$id;
        $st = db()->prepare('UPDATE abby SET ' . implode(', ', $set) . ' WHERE id = ?');
        $st->execute($vals);
        return (int)$id;
    }
    $cols[] = 'created_at';
    $vals[] = $now;
    $cols[] = 'updated_at';
    $vals[] = $now;
    $st = db()->prepare('INSERT INTO abby (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')');
    $st->execute($vals);
    return (int)db()->lastInsertId();
}

function abby_delete($id)
{
    $st = db()->prepare('DELETE FROM abby WHERE id = ?');
    $st->execute(array((int)$id));
}

/** 反查：这个属性有没有别的艾比用了同一张图 */
function img_in_use($rel, $except_id = 0)
{
    $st = db()->prepare('SELECT COUNT(*) FROM abby WHERE img = ? AND id != ?');
    $st->execute(array($rel, (int)$except_id));
    return (int)$st->fetchColumn();
}
