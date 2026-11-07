<?php
/**
 * 艾比图鉴 —— 全局配置
 * 站点标题、数据库位置、属性/稀有度/评级色、后台账号都在这里
 */
define('AIBI_TITLE', '艾比图鉴');
define('AIBI_SUB', '数据取自「艾比手册」小程序，已全部落到本地数据库');
define('AIBI_DB', dirname(__DIR__) . '/data/aibi.db');
define('AIBI_IMG_DIR', dirname(__DIR__) . '/img');

/** 后台：账号名固定，密码以 password_hash 存在 data/admin.json（首次进后台会让你设置） */
define('AIBI_ADMIN_USER', 'admin');
define('AIBI_ADMIN_FILE', dirname(__DIR__) . '/data/admin.json');

/** 属性 -> 英文码（图标文件名） */
$GLOBALS['AIBI_ELEMENT_CODE'] = array(
    '草' => 'cao', '水' => 'shui', '火' => 'huo', '电' => 'dian', '岩' => 'yan',
    '普' => 'pu', '龙' => 'long', '光' => 'guang', '暗' => 'an', '超' => 'chao',
);

/** 属性主色（深色底上用的亮色，只用于圆点、图标旁的文字、卡片微光） */
$GLOBALS['AIBI_ELEMENT_COLOR'] = array(
    '草' => '#3F7A4A', '水' => '#2A6FB0', '火' => '#C0392B', '电' => '#8A5C0F',
    '岩' => '#7A5A34', '普' => '#5A6470', '龙' => '#8E3B6E', '光' => '#7F6610',
    '暗' => '#4A5060', '超' => '#9C3468',
);

/** 稀有度色阶 */
$GLOBALS['AIBI_RARITY_COLOR'] = array(
    '常见' => '#6B7280', '稀有' => '#1F6FB0', '史诗' => '#A0306B', '传说' => '#8A5C0F',
);

/** 评级色阶（T0 最强 → T5 最弱） */
$GLOBALS['AIBI_RANK_COLOR'] = array(
    'T0' => '#C62D42', 'T0.5' => '#A93A0A', 'T1' => '#B45309', 'T1.5' => '#6B7A0E',
    'T2' => '#2E7D32', 'T3' => '#0F766E', 'T4' => '#1D4ED8', 'T5' => '#6B7280',
);

$GLOBALS['AIBI_RANKS'] = array('T0', 'T0.5', 'T1', 'T1.5', 'T2', 'T3', 'T4', 'T5');

/** 信物类别：接口/打包数据里是英文码 */
$GLOBALS['AIBI_TOKEN_CAT'] = array(
    'meet' => '多只相聚', 'feed' => '投喂', 'pet' => '抚摸互动', 'stay' => '停留晒太阳',
    'guard' => '农场护卫', 'run' => '跑步行走', 'jump' => '跳跃', 'sleep' => '睡觉躺下',
    'dance' => '跳舞乐器', 'music' => '听音乐', 'place' => '带至地点', 'battle' => '擂台切磋',
    'other' => '其他互动',
);
$GLOBALS['AIBI_TOKEN_WORLD'] = array('bofeng' => '泊风港', 'zhuoao' => '浊奥大陆');

function token_cat($code)
{
    if ($code === '') {
        return '未标注';
    }
    return isset($GLOBALS['AIBI_TOKEN_CAT'][$code]) ? $GLOBALS['AIBI_TOKEN_CAT'][$code] : $code;
}

function token_world($code)
{
    if ($code === '') {
        return '';
    }
    return isset($GLOBALS['AIBI_TOKEN_WORLD'][$code]) ? $GLOBALS['AIBI_TOKEN_WORLD'][$code] : $code;
}

/** 统一的 HTML 转义 */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function element_icon($el)
{
    $code = isset($GLOBALS['AIBI_ELEMENT_CODE'][$el]) ? $GLOBALS['AIBI_ELEMENT_CODE'][$el] : '';
    return $code ? 'img/element/' . $code . '.png' : '';
}

function element_color($el)
{
    return isset($GLOBALS['AIBI_ELEMENT_COLOR'][$el]) ? $GLOBALS['AIBI_ELEMENT_COLOR'][$el] : '#6f7a7c';
}

function rarity_color($r)
{
    return isset($GLOBALS['AIBI_RARITY_COLOR'][$r]) ? $GLOBALS['AIBI_RARITY_COLOR'][$r] : '#8b968f';
}

function rank_color($r)
{
    return isset($GLOBALS['AIBI_RANK_COLOR'][$r]) ? $GLOBALS['AIBI_RANK_COLOR'][$r] : '#6b7280';
}

/** 图片路径：库里存的是 img/ 下的相对路径 */
function img_url($rel)
{
    return 'img/' . ltrim((string)$rel, '/');
}

/** 五维：库里列名 -> 展示名 */
$GLOBALS['AIBI_DIMS'] = array(
    'hp' => '生命', 'atk' => '物攻', 'sp_atk' => '法攻', 'sp_def' => '法防', 'dfn' => '物防',
);

/**
 * 前台判断当前访客是不是已经登录的后台管理员。
 * 只有带了后台会话 cookie 时才启动会话，匿名访客不会拿到多余的 Set-Cookie。
 */
function aibi_is_admin()
{
    if (!isset($_COOKIE['aibi_sid']) || $_COOKIE['aibi_sid'] === '') {
        return false;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(array('httponly' => true, 'samesite' => 'Lax'));
        session_name('aibi_sid');
        @session_start();
    }
    return !empty($_SESSION['aibi_admin']);
}
