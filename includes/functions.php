<?php
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));
session_start();

/**
 * 允许上传的图片格式：扩展名 => 真实 MIME
 */
function allowedImageMap() {
    return [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];
}

/**
 * MIME => 落盘时统一使用的规范扩展名
 */
function imageExtByMime($mime) {
    $map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    return $map[$mime] ?? null;
}

define('UPLOAD_IMAGE_MAX_SIZE', 5 * 1024 * 1024);

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/**
 * 校验上传图片：上传状态、大小、真实图片类型、扩展名与类型一致性
 *
 * @param array $file $_FILES 中的单文件
 * @return array [0 => bool 通过, 1 => string 错误原因, 2 => string|null 规范扩展名]
 */
function validateImageUpload($file) {
    if (!isset($file['error']) || !is_array($file)) {
        return [false, '请选择有效的图片文件', null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMap = [
            UPLOAD_ERR_INI_SIZE   => '图片大小超过服务器允许的上限',
            UPLOAD_ERR_FORM_SIZE  => '图片大小超过表单允许的上限',
            UPLOAD_ERR_PARTIAL    => '图片上传不完整，请重新选择后重试',
            UPLOAD_ERR_NO_FILE    => '未选择图片文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录缺失，请联系管理员',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败，请稍后重试',
            UPLOAD_ERR_EXTENSION  => '图片被服务器扩展拦截，请更换图片后重试',
        ];
        return [false, $errorMap[$file['error']] ?? '图片上传失败，请重新选择后重试', null];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return [false, '图片来源无效，请重新选择', null];
    }

    if ($file['size'] <= 0) {
        return [false, '图片文件为空，请重新选择', null];
    }
    if ($file['size'] > UPLOAD_IMAGE_MAX_SIZE) {
        return [false, '图片大小不能超过 5MB', null];
    }

    // 以文件内容为准检测真实图片类型，浏览器提供的 type 可被伪造
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo) finfo_close($finfo);

    $allowedMimes = array_unique(array_values(allowedImageMap()));
    if (!$realMime || !in_array($realMime, $allowedMimes, true)) {
        return [false, '仅支持 JPG、PNG、GIF、WebP 格式的图片，当前文件不是有效图片', null];
    }

    // 扩展名必须与真实类型一致，避免“内容是 A、后缀是 B”导致详情图对不上
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $map = allowedImageMap();
    if ($ext === '' || !isset($map[$ext]) || $map[$ext] !== $realMime) {
        $labels = ['image/jpeg' => 'JPG', 'image/png' => 'PNG', 'image/gif' => 'GIF', 'image/webp' => 'WebP'];
        return [false, '图片实际格式为 ' . ($labels[$realMime] ?? $realMime) . '，但文件后缀名不匹配，请转换或改名为 .' . imageExtByMime($realMime) . ' 后重新上传', null];
    }

    // getimagesize 兜底：非图片内容即使 MIME 伪造也无法通过
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [false, '图片文件已损坏，无法读取，请更换图片', null];
    }

    return [true, '', imageExtByMime($realMime)];
}

/**
 * 保存已通过校验的上传图片，返回相对站点根目录的路径（如 uploads/xxx.jpg）
 *
 * @throws Exception 落盘失败时抛出，调用方据此中止业务
 */
function saveUploadedImage($file, $ext) {
    $uploadDir = APP_ROOT . '/uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('上传目录不可用，请稍后重试');
    }

    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('图片保存失败，请稍后重试');
    }

    return 'uploads/' . $filename;
}

/**
 * 删除留言关联图片（静默处理，文件缺失不算错误）
 */
function deleteMessageImage($imagePath) {
    if (empty($imagePath)) return;
    // 仅允许删除 uploads 目录内的文件，杜绝路径穿越
    if (strpos($imagePath, 'uploads/') !== 0) return;

    $fullPath = APP_ROOT . '/' . $imagePath;
    $real = realpath($fullPath);
    $base = realpath(APP_ROOT . '/uploads');
    if ($real && $base && strpos($real, $base . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
        @unlink($real);
    }
}

/**
 * 只有图片文件真实存在时才返回路径，否则返回 null
 * 保证列表「有图」标记、详情图、后台查看三处与图片记录严格同步
 */
function existingImagePath($imagePath) {
    if (empty($imagePath) || strpos($imagePath, 'uploads/') !== 0) return null;
    $full = APP_ROOT . '/' . $imagePath;
    return is_file($full) ? $imagePath : null;
}

/**
 * 提交留言（幂等）
 *
 * 同一 client_token 的重复提交（网络中断重试、双击）只落地一条待审记录；
 * 图片落盘与数据库入库在事务内完成，失败时清理已上传文件，不留孤儿。
 *
 * @return array [message_id, duplicated(bool), status_text]
 * @throws Exception 校验或入库失败
 */
function submitMessage(array $input, $imageFile) {
    $nickname = trim($input['nickname'] ?? '');
    $phone    = trim($input['phone'] ?? '');
    $type     = $input['type'] ?? 'help';
    $title    = trim($input['title'] ?? '');
    $content  = trim($input['content'] ?? '');
    $clientToken = trim($input['client_token'] ?? '');

    if ($nickname === '') throw new Exception('请输入昵称');
    if (mb_strlen($nickname) > 50) throw new Exception('昵称不能超过50个字符');
    if ($title === '') throw new Exception('请输入标题');
    if (mb_strlen($title) > 100) throw new Exception('标题不能超过100个字符');
    if ($content === '') throw new Exception('请输入内容');
    if (mb_strlen($content) > 2000) throw new Exception('内容不能超过2000个字符');
    if (!in_array($type, ['help', 'suggest', 'lost'], true)) throw new Exception('无效的留言类型');
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{5,20}$/', $phone)) throw new Exception('联系电话格式不正确');
    if ($clientToken === '' || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $clientToken)) throw new Exception('提交凭证缺失，请刷新页面后重试');

    // 图片先校验，未通过时原样保留用户已填的全部文字（由前端负责不跳转）
    $imagePath = null;
    $hasImage = isset($imageFile['error']) && $imageFile['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasImage) {
        list($valid, $reason, $ext) = validateImageUpload($imageFile);
        if (!$valid) throw new Exception($reason);
    }

    $db = getDB();

    // 幂等：同一提交凭证已成功入库时直接返回原记录，绝不重复插入
    $stmt = $db->prepare("SELECT id, status FROM messages WHERE client_token = ? LIMIT 1");
    $stmt->execute([$clientToken]);
    $existing = $stmt->fetch();
    if ($existing) {
        return [
            'message_id' => (int)$existing['id'],
            'duplicated' => true,
            'status_text' => getStatusLabel($existing['status']),
        ];
    }

    $db->beginTransaction();
    $savedPath = null;
    try {
        if ($hasImage) {
            // 在事务内落盘，若入库失败立即清理，避免“图片记录不同步/孤儿文件”
            $imagePath = saveUploadedImage($imageFile, $ext);
            $savedPath = $imagePath;
        }

        $stmt = $db->prepare(
            "INSERT INTO messages (nickname, phone, type, title, content, image, status, client_token)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $stmt->execute([$nickname, $phone !== '' ? $phone : null, $type, $title, $content, $imagePath, $clientToken]);
        $messageId = (int)$db->lastInsertId();

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        if ($savedPath) deleteMessageImage($savedPath);

        // 唯一键并发竞态：另一个请求已用同一凭证成功提交
        if ((($e->errorInfo[1] ?? null) == 1062) || $e->getCode() === '23000') {
            $stmt = $db->prepare("SELECT id, status FROM messages WHERE client_token = ? LIMIT 1");
            $stmt->execute([$clientToken]);
            $existing = $stmt->fetch();
            if ($existing) {
                return [
                    'message_id' => (int)$existing['id'],
                    'duplicated' => true,
                    'status_text' => getStatusLabel($existing['status']),
                ];
            }
        }
        throw new Exception('提交失败，请稍后重试');
    } catch (Exception $e) {
        $db->rollBack();
        if ($savedPath) deleteMessageImage($savedPath);
        throw $e;
    }

    return ['message_id' => $messageId, 'duplicated' => false, 'status_text' => '待审核'];
}
