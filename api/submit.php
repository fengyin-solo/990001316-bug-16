<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$nickname = trim($_POST['nickname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$type = $_POST['type'] ?? 'help';
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');
$idempotencyKey = preg_replace('/[^a-f0-9]/i', '', (string)($_POST['submit_token'] ?? ''));

// 验证
if ($nickname === '') jsonResponse(1, '请输入昵称');
if (mb_strlen($nickname) > 50) jsonResponse(1, '昵称不能超过50个字符');
if ($phone !== '' && !preg_match('/^[0-9+\-\s]{3,20}$/', $phone)) jsonResponse(1, '联系电话格式不正确（3-20位数字、空格或+-）');
if ($title === '') jsonResponse(1, '请输入标题');
if (mb_strlen($title) > 100) jsonResponse(1, '标题不能超过100个字符');
if ($content === '') jsonResponse(1, '请输入内容');
if (mb_strlen($content) > 2000) jsonResponse(1, '内容不能超过2000个字符');
if (!in_array($type, ['help', 'suggest', 'lost'], true)) jsonResponse(1, '无效的留言类型');
if ($idempotencyKey === '' || strlen($idempotencyKey) !== 64) {
    jsonResponse(1, '页面已过期，请刷新后重试（请勿重复提交）');
}

// 上传错误码（即使没有选择文件也要保证记录与图片一致）
$uploadErrorMessages = [
    UPLOAD_ERR_INI_SIZE   => '图片大小超过服务器限制（最大5MB），请压缩后重新选择',
    UPLOAD_ERR_FORM_SIZE  => '图片大小超过限制（最大5MB），请压缩后重新选择',
    UPLOAD_ERR_PARTIAL    => '图片上传不完整，网络可能已中断，请重新选择图片后重试',
    UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录缺失，请联系管理员',
    UPLOAD_ERR_CANT_WRITE => '图片写入服务器失败，请稍后重试',
    UPLOAD_ERR_EXTENSION  => '图片被服务器扩展拦截，请更换图片后重试',
];

$imagePath = null;
$hasImageUpload = isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;

if ($hasImageUpload) {
    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(1, $uploadErrorMessages[$file['error']] ?? ('图片上传失败（错误码：' . $file['error'] . '），请重新选择图片后重试'));
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        jsonResponse(1, '图片上传无效，请重新选择图片后重试');
    }

    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        jsonResponse(1, '图片大小不能超过5MB');
    }

    // 以文件真实内容为准识别格式，不信任浏览器提供的 MIME 和扩展名
    $imageInfo = @getimagesize($file['tmp_name']);
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if ($imageInfo === false || empty($imageInfo['mime']) || !isset($mimeToExt[$imageInfo['mime']])) {
        jsonResponse(1, '图片格式不符合要求，仅支持 JPG、PNG、GIF、WebP，请重新选择图片（文字内容已保留）');
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        jsonResponse(1, '上传目录不可用，请稍后重试或联系管理员');
    }

    $ext = $mimeToExt[$imageInfo['mime']];
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        jsonResponse(1, '图片保存失败，请重新选择图片后重试');
    }

    $imagePath = 'uploads/' . $filename;
}

// 入库（事务 + 幂等键，保证重试只落地一条，且图片与记录同步）
$db = getDB();
// DDL 必须在事务外执行（MySQL DDL 会隐式提交），且只需检查一次
ensureSubmissionTokensTable($db);

try {
    $db->beginTransaction();

    // 1) 幂等：同一 token 只允许成功插入一次；网络中断后重试直接返回首次结果
    $stmt = $db->prepare("SELECT message_id FROM submission_tokens WHERE token = ? FOR UPDATE");
    $stmt->execute([$idempotencyKey]);
    $existing = $stmt->fetch();

    if ($existing && $existing['message_id']) {
        $db->commit();
        if ($imagePath !== null && is_file(__DIR__ . '/../' . $imagePath)) {
            @unlink(__DIR__ . '/../' . $imagePath);
        }
        jsonResponse(0, '留言已提交，请勿重复提交（保留首次提交）', [
            'message_id' => (int)$existing['message_id'],
            'duplicate'  => true,
        ]);
    }

    // 2) 兜底去重：token 缺失/被清空时，同一访客 10 分钟内内容完全相同的待审留言也不重复落地
    $fingerprint = hash('sha256', implode("\x1f", [$nickname, (string)$phone, $type, $title, $content]));
    $stmt = $db->prepare(
        "SELECT id FROM messages
         WHERE status = 0 AND content_fingerprint = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         LIMIT 1"
    );
    $stmt->execute([$fingerprint]);
    $dupRow = $stmt->fetch();

    if ($dupRow) {
        $db->commit();
        if ($imagePath !== null && is_file(__DIR__ . '/../' . $imagePath)) {
            @unlink(__DIR__ . '/../' . $imagePath);
        }
        jsonResponse(0, '相同内容的留言已在审核队列中，请勿重复提交', [
            'message_id' => (int)$dupRow['id'],
            'duplicate'  => true,
        ]);
    }

    $stmt = $db->prepare(
        "INSERT INTO messages (nickname, phone, type, title, content, image, status, content_fingerprint)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
    );
    $stmt->execute([$nickname, $phone !== '' ? $phone : null, $type, $title, $content, $imagePath, $fingerprint]);
    $messageId = (int)$db->lastInsertId();

    // 登记幂等键（token 唯一键兜底并发竞态）
    if (!$existing) {
        $db->prepare("INSERT INTO submission_tokens (token, message_id, created_at) VALUES (?, ?, NOW())")
           ->execute([$idempotencyKey, $messageId]);
    } else {
        $db->prepare("UPDATE submission_tokens SET message_id = ? WHERE token = ?")
           ->execute([$messageId, $idempotencyKey]);
    }

    $db->commit();

    jsonResponse(0, '留言提交成功，等待审核', ['message_id' => $messageId, 'duplicate' => false]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('留言提交失败: ' . $e->getMessage());
    // 记录未落地：删除已上传的图片，避免孤立文件
    if ($imagePath !== null) {
        $imgFile = __DIR__ . '/../' . $imagePath;
        if (is_file($imgFile)) {
            @unlink($imgFile);
        }
    }
    jsonResponse(500, '服务器错误，提交未完成，请重试（已填内容不会丢失）');
}

/**
 * 幂等令牌表（随首次提交自动创建，兼容已部署环境）
 */
function ensureSubmissionTokensTable(PDO $db) {
    static $checked = false;
    if ($checked) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `submission_tokens` (
        `token` CHAR(64) NOT NULL COMMENT '提交幂等令牌',
        `message_id` INT UNSIGNED DEFAULT NULL COMMENT '对应留言ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        PRIMARY KEY (`token`),
        INDEX `idx_message_id` (`message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言提交幂等令牌表'");

    // 旧库可能没有 content_fingerprint 列，自动补齐（每步独立容错，避免列存在导致索引漏建）
    try {
        $db->exec("ALTER TABLE `messages` ADD COLUMN `content_fingerprint` CHAR(64) DEFAULT NULL COMMENT '内容指纹（去重）' AFTER `image`");
    } catch (Exception $e) {
        // 列已存在，忽略
    }
    try {
        $db->exec("ALTER TABLE `messages` ADD INDEX `idx_fingerprint_status` (`content_fingerprint`, `status`)");
    } catch (Exception $e) {
        // 索引已存在，忽略
    }
    $checked = true;
}
