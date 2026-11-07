<?php
/**
 * 后台：保存一只艾比（新增或更新）
 */
require_once __DIR__ . '/_boot.php';
admin_require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_check();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
if ($name === '') {
    flash_set('名字不能为空', 'err');
    header('Location: edit.php' . ($id ? '?id=' . $id : ''));
    exit;
}

// 名字不能撞车（名字同时是前台详情页的地址）
$st = db()->prepare('SELECT id FROM abby WHERE name = ? AND id != ?');
$st->execute(array($name, $id));
if ($st->fetch()) {
    flash_set('已经有一只叫「' . $name . '」的了，换个名字', 'err');
    header('Location: edit.php' . ($id ? '?id=' . $id : ''));
    exit;
}

$data = array();
foreach (abby_fields() as $f) {
    if ($f === 'name') {
        $data['name'] = $name;
        continue;
    }
    if ($f === 'intro_locked') {
        $data[$f] = isset($_POST[$f]) ? 1 : 0;
        continue;
    }
    if ($f === 'has_extra') {
        $data[$f] = isset($_POST[$f]) ? 1 : 0;
        continue;
    }
    $data[$f] = isset($_POST[$f]) ? trim((string)$_POST[$f]) : '';
}

// 图片上传：换成新图后，旧图如果没人用就删掉（只删 upload_ 前缀的，原始素材保留）
$old = $id ? get_abby_id($id) : null;
if (!empty($_FILES['img_file']['tmp_name']) && is_uploaded_file($_FILES['img_file']['tmp_name'])) {
    $f = $_FILES['img_file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash_set('上传失败（错误码 ' . (int)$f['error'] . '）', 'err');
        header('Location: edit.php' . ($id ? '?id=' . $id : ''));
        exit;
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        flash_set('图片超过 2MB，压一下再传', 'err');
        header('Location: edit.php' . ($id ? '?id=' . $id : ''));
        exit;
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info) {
        flash_set('这不是一张能识别的图片', 'err');
        header('Location: edit.php' . ($id ? '?id=' . $id : ''));
        exit;
    }
    $map = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp');
    if (!isset($map[$info[2]])) {
        flash_set('只支持 png / jpg / webp', 'err');
        header('Location: edit.php' . ($id ? '?id=' . $id : ''));
        exit;
    }
    $ext = $map[$info[2]];
    $base = preg_replace('/[^\x{4e00}-\x{9fa5}A-Za-z0-9_-]+/u', '', $name);
    if ($base === '') {
        $base = 'abby';
    }
    $rel = 'abby/upload_' . $base . '.' . $ext;
    $dst = AIBI_IMG_DIR . '/' . $rel;
    if (!is_dir(dirname($dst))) {
        mkdir(dirname($dst), 0777, true);
    }
    if (!move_uploaded_file($f['tmp_name'], $dst)) {
        flash_set('图片存不下来，检查 img/abby 目录权限', 'err');
        header('Location: edit.php' . ($id ? '?id=' . $id : ''));
        exit;
    }
    $data['img'] = $rel;
    if ($old && $old['img'] && $old['img'] !== $rel
        && strpos($old['img'], 'upload_') !== false && !img_in_use($old['img'], $id)) {
        @unlink(AIBI_IMG_DIR . '/' . $old['img']);
    }
}

if ($data['img'] === '' && $old) {
    $data['img'] = $old['img'];
}
if ($data['source'] === '' && $old) {
    $data['source'] = $old['source'];
}
if ($data['source'] === '') {
    $data['source'] = 'manual';
}

$newId = abby_save($data, $id);
flash_set(($id ? '已保存「' : '已新增「') . $name . '」');
header('Location: edit.php?id=' . $newId);
exit;
