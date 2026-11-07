<?php
/**
 * 旧地址兼容：图鉴已挪到站点根目录，这里 301 过去（保留查询参数）
 * 等没有旧链接要兜了，直接删掉 aibi 这个目录即可
 */
$qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
header('Location: /' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
