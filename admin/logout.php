<?php
/** 后台：退出登录 */
require_once __DIR__ . '/_boot.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}
admin_logout();
header('Location: index.php');
exit;
