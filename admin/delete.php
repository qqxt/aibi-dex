<?php
/** 后台：删除一只艾比 */
require_once __DIR__ . '/_boot.php';
admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_check();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$row = $id ? get_abby_id($id) : null;
if ($row) {
    abby_delete($id);
    $img = (string)$row['img'];
    if ($img !== '' && strpos($img, 'upload_') !== false && !img_in_use($img)) {
        @unlink(AIBI_IMG_DIR . '/' . $img);
    }
    flash_set('已删除「' . $row['name'] . '」');
} else {
    flash_set('记录不存在，可能已经被删了', 'err');
}
header('Location: index.php');
exit;
