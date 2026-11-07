<?php
/**
 * 后台公共引导：会话、登录校验、CSRF、页面骨架
 */
require_once dirname(__DIR__) . '/lib/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(array('httponly' => true, 'samesite' => 'Lax'));
    session_name('aibi_sid');
    session_start();
}

function admin_json_path()
{
    return AIBI_ADMIN_FILE;
}

/** 读后台账号密码文件：['user'=>..., 'hash'=>...] */
function admin_account()
{
    $p = admin_json_path();
    if (!is_file($p)) {
        return null;
    }
    $d = json_decode(file_get_contents($p), true);
    return (is_array($d) && !empty($d['hash'])) ? $d : null;
}

function admin_set_password($pass)
{
    $dir = dirname(admin_json_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $d = array('user' => AIBI_ADMIN_USER, 'hash' => password_hash($pass, PASSWORD_DEFAULT));
    file_put_contents(admin_json_path(), json_encode($d, JSON_UNESCAPED_UNICODE));
    @chmod(admin_json_path(), 0600);
}

function admin_logged_in()
{
    return !empty($_SESSION['aibi_admin']);
}

function admin_login($user, $pass)
{
    $acc = admin_account();
    if (!$acc) {
        return false;
    }
    if (!hash_equals((string)$acc['user'], (string)$user)) {
        return false;
    }
    if (!password_verify((string)$pass, (string)$acc['hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['aibi_admin'] = $acc['user'];
    return true;
}

function admin_logout()
{
    $_SESSION = array();
    session_destroy();
}

function admin_require_login()
{
    if (!admin_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check()
{
    $ok = isset($_POST['_csrf']) && !empty($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['_csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('表单已过期，请返回重试。');
    }
}

/** 后台页面外壳 */
function admin_head($title, $active = '')
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' · 艾比图鉴后台</title>';
    echo '<link rel="stylesheet" href="../assets/admin.css">';
    echo '</head><body>';
    echo '<header class="bar"><a class="brand" href="index.php">艾比图鉴<span>后台</span></a>';
    if (admin_logged_in()) {
        echo '<nav>';
        echo '<a href="index.php"' . ($active === 'list' ? ' class="on"' : '') . '>资料列表</a>';
        echo '<a href="edit.php"' . ($active === 'new' ? ' class="on"' : '') . '>新增艾比</a>';
        echo '<a href="../index.php" target="_blank">看站点</a>';
        echo '<form method="post" action="logout.php" class="inline">' . csrf_field()
            . '<button type="submit" class="link">退出</button></form>';
        echo '</nav>';
    }
    echo '</header><main class="wrap">';
}

function admin_foot()
{
    echo '</main></body></html>';
}

/** flash 提示（一次性的） */
function flash_set($msg, $kind = 'ok')
{
    $_SESSION['flash'] = array('msg' => $msg, 'kind' => $kind);
}

function flash_get()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}
