<?php
/**
 * 后台：新增 / 编辑一只艾比
 */
require_once __DIR__ . '/_boot.php';
admin_require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
// 也允许按名字进来：edit.php?name=芬里尔
if (!$id && isset($_GET['name']) && trim((string)$_GET['name']) !== '') {
    $tmp = get_abby(trim((string)$_GET['name']));
    if ($tmp) {
        $id = (int)$tmp['id'];
    }
}
$row = $id ? get_abby_id($id) : null;
if ($id && !$row) {
    http_response_code(404);
    exit('没有这条记录');
}

$v = function ($k, $def = '') use ($row) {
    if ($row && array_key_exists($k, $row) && $row[$k] !== null) {
        return (string)$row[$k];
    }
    return $def;
};

$els = array_keys($GLOBALS['AIBI_ELEMENT_CODE']);
$lineups = array('输出', '战士', '射手', '辅助', '守卫', '刺客');
$rarities = array('常见', '稀有', '史诗', '传说');

function opts($list, $cur)
{
    $s = '';
    foreach ($list as $o) {
        $s .= '<option value="' . h($o) . '"' . ((string)$o === (string)$cur ? ' selected' : '') . '>' . h($o) . '</option>';
    }
    return $s;
}

admin_head($id ? '编辑 · ' . $v('name') : '新增艾比', $id ? '' : 'new');
$flash = flash_get();
if ($flash) {
    echo '<p class="flash ' . h($flash['kind']) . '">' . h($flash['msg']) . '</p>';
}
?>
<form class="edit" method="post" action="save.php" enctype="multipart/form-data">
<?php echo csrf_field(); ?>
<input type="hidden" name="id" value="<?php echo (int)$id; ?>">

<section class="card">
  <h2>基本资料</h2>
  <div class="grid2">
    <label>名字 *<input type="text" name="name" value="<?php echo h($v('name')); ?>" required maxlength="20"></label>
    <label>属性 *<select name="element" required><option value="">选择</option><?php echo opts($els, $v('element')); ?></select></label>
    <label>副属性<select name="element2"><option value="">无</option><?php echo opts($els, $v('element2')); ?></select></label>
    <label>稀有度<select name="rarity"><?php echo opts($rarities, $v('rarity')); ?></select></label>
    <label>定位<select name="lineup"><option value="">未标注</option><?php echo opts($lineups, $v('lineup')); ?></select></label>
    <label>强度评级<select name="rank"><option value="">未上榜</option><?php echo opts($GLOBALS['AIBI_RANKS'], $v('rank')); ?></select></label>
    <label>票数<input type="number" name="votes" value="<?php echo h($v('votes', '0')); ?>" min="0"></label>
    <label>主词条<input type="text" name="main_stats" value="<?php echo h($v('main_stats')); ?>" placeholder="物攻、法攻"></label>
  </div>
</section>

<section class="card">
  <h2>立绘</h2>
  <div class="imgrow">
    <div class="thumb">
      <?php if ($v('img')): ?>
        <img src="../<?php echo h(img_url($v('img'))); ?>" alt="">
      <?php else: ?>
        <span>暂无</span>
      <?php endif; ?>
    </div>
    <div class="imgfields">
      <label>数据库里的路径<input type="text" name="img" value="<?php echo h($v('img')); ?>" placeholder="abby/火_枫洛可.png"></label>
      <label>或上传一张新的（jpg/png/webp，≤2MB）<input type="file" name="img_file" accept="image/png,image/jpeg,image/webp"></label>
      <p class="hint">上传会保存成 <code>img/abby/upload_名字.扩展名</code>，并自动填进上面的路径。</p>
    </div>
  </div>
</section>

<section class="card">
  <h2>五维</h2>
  <p class="hint">填 Lv.20 资质成长值；留空表示这只还没有数据，站上会显示「暂无五维数据」。</p>
  <div class="grid5">
    <?php foreach ($GLOBALS['AIBI_DIMS'] as $col => $label): ?>
      <label><?php echo h($label); ?><input type="number" step="0.01" min="0" name="<?php echo h($col); ?>" value="<?php echo h($v($col)); ?>"></label>
    <?php endforeach; ?>
  </div>
  <label>数值来源说明<input type="text" name="stat_source" value="<?php echo h($v('stat_source')); ?>" placeholder="Lv.20 资质成长"></label>
</section>

<section class="card">
  <h2>糖果偏好</h2>
  <div class="grid2">
    <label>喜欢的零食<input type="text" name="love_snack" value="<?php echo h($v('love_snack')); ?>" placeholder="火辣辣"></label>
    <label>讨厌的零食<input type="text" name="hate_snack" value="<?php echo h($v('hate_snack')); ?>" placeholder="干巴巴（没有就留空）"></label>
  </div>
</section>

<section class="card">
  <h2>信物 / 获取方式</h2>
  <div class="grid2">
    <label>信物名<input type="text" name="token_name" value="<?php echo h($v('token_name')); ?>" placeholder="神裁之羽"></label>
    <label>获取类别<select name="token_category"><option value="">未标注</option>
      <?php foreach ($GLOBALS['AIBI_TOKEN_CAT'] as $k => $label): ?>
        <option value="<?php echo h($k); ?>"<?php echo $v('token_category') === $k ? ' selected' : ''; ?>><?php echo h($label); ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>一句话提示<input type="text" name="token_hint" value="<?php echo h($v('token_hint')); ?>" placeholder="小镇/庄园 · 点击停留"></label>
    <label>地点码<input type="text" name="token_world" value="<?php echo h($v('token_world')); ?>" placeholder="bofeng（泊风港）"></label>
  </div>
  <label>掉落方式详解<textarea name="token_detail" rows="4"><?php echo h($v('token_detail')); ?></textarea></label>
</section>

<section class="card">
  <h2>技能</h2>
  <div class="grid2">
    <label>技能名<input type="text" name="skill_name" value="<?php echo h($v('skill_name')); ?>"></label>
    <label>技能类型<input type="text" name="skill_type_label" value="<?php echo h($v('skill_type_label')); ?>" placeholder="物伤 / 法伤 / 增益"></label>
    <label>类型码<input type="text" name="skill_type" value="<?php echo h($v('skill_type')); ?>" placeholder="physical"></label>
    <label>冷却（秒）<input type="number" name="skill_cd" value="<?php echo h($v('skill_cd', '0')); ?>" min="0"></label>
  </div>
  <label>技能详解<textarea name="skill_detail" rows="4"><?php echo h($v('skill_detail')); ?></textarea></label>
  <label>推荐的通用技能（接口原样存的 JSON，可留空）
    <textarea name="general_skills" rows="6" class="mono"><?php echo h($v('general_skills')); ?></textarea></label>
</section>

<section class="card">
  <h2>其它资料</h2>
  <div class="grid2">
    <label>资质果<input type="text" name="fruit_label" value="<?php echo h($v('fruit_label')); ?>" placeholder="活力果"></label>
    <label>资质果加成<input type="text" name="fruit_apt" value="<?php echo h($v('fruit_apt')); ?>" placeholder="生命"></label>
    <label>实用性<input type="text" name="info_practicality" value="<?php echo h($v('info_practicality')); ?>" placeholder="对决强势"></label>
    <label>常见地图<input type="text" name="info_maps" value="<?php echo h($v('info_maps')); ?>" placeholder="旧港区、泊风港"></label>
    <label>上架状态<input type="text" name="shelf_status" value="<?php echo h($v('shelf_status')); ?>" placeholder="已上架"></label>
    <label>获取方式/付费<input type="text" name="shelf_paid" value="<?php echo h($v('shelf_paid')); ?>" placeholder="免费 / 氪金"></label>
    <label>点赞数<input type="number" name="likes" value="<?php echo h($v('likes', '0')); ?>" min="0"></label>
  </div>
</section>

<section class="card">
  <h2>详细简介</h2>
  <p class="hint">导入时按结构化字段自动汇总了一段，你可以直接改写。锁定后重跑导入不会覆盖它（导入加 <code>--keep</code> 时整条都不动）。</p>
  <label>一句话提示<input type="text" name="short_hint" value="<?php echo h($v('short_hint')); ?>" placeholder="显示在详情页名字下面，可留空"></label>
  <textarea name="intro" rows="7"><?php echo h($v('intro')); ?></textarea>
  <label class="check"><input type="checkbox" name="intro_locked" value="1"<?php echo $v('intro_locked') === '1' ? ' checked' : ''; ?>> 锁定这段简介</label>
</section>

<section class="card">
  <p class="hint">来源：<?php echo h($v('source', $id ? '' : 'manual')); ?>
    <?php if ($v('has_extra') === '1'): ?> · 含官方接口补充资料<?php endif; ?>
    <?php if ($row && $row['updated_at']): ?> · 最后修改 <?php echo h($row['updated_at']); ?><?php endif; ?></p>
  <div class="actions">
    <button type="submit" class="primary">保存</button>
    <a class="btn" href="index.php">返回列表</a>
    <?php if ($id): ?><a class="btn" href="../detail.php?name=<?php echo rawurlencode($v('name')); ?>" target="_blank">看前台</a><?php endif; ?>
  </div>
</section>
</form>
<?php admin_foot(); ?>
