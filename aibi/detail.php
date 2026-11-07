<?php
/**
 * 旧地址兼容：detail.php?name=xxx → 根目录同名页面
 */
$name = isset($_GET['name']) ? (string)$_GET['name'] : '';
header('Location: /detail.php' . ($name !== '' ? '?name=' . rawurlencode($name) : ''), true, 301);
exit;
