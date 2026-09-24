<?php
session_start();

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
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $visitorId = md5(uniqid('visitor_', true) . $remoteAddr . $userAgent);
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
 * 完整删除一条留言及其关联数据与图片文件
 *
 * 在事务中删除 favorites / reports / messages，保证列表、详情、审核队列、
 * 图片记录四处同步；DB 全部成功后才删除磁盘文件，任何一步失败都整体回滚，
 * 不残留孤立记录或孤立图片。
 *
 * @param PDO $db
 * @param int $id
 * @param int|null $adminId 若由举报处理触发，关联举报将被标记为"已处理-已删除"而非删除
 * @param string $note 处理备注
 * @return bool 是否删除成功（留言不存在返回 false）
 * @throws Exception 失败时抛出，事务已回滚
 */
function deleteMessageCascade(PDO $db, $id, $adminId = null, $note = '') {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();

        if (!$msg) {
            $db->rollBack();
            return false;
        }

        // 显式清理关联数据（即使外键级联存在，也保证各队列计数同步刷新）
        $db->prepare("DELETE FROM favorites WHERE message_id = ?")->execute([$id]);

        if ($adminId !== null) {
            // 从举报处理流程进入：保留举报记录作为审计轨迹，避免待处理队列残留
            $db->prepare(
                "UPDATE reports
                 SET status = 1, processed_by = ?, processed_at = NOW(), process_note = ?
                 WHERE message_id = ?"
            )->execute([$adminId, $note !== '' ? $note : '留言已删除', $id]);
        } else {
            $db->prepare("DELETE FROM reports WHERE message_id = ?")->execute([$id]);
        }

        $db->prepare("DELETE FROM submission_tokens WHERE message_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // 数据库已提交后再删图片文件；文件缺失不影响数据一致性，删除失败仅记录告警
    if (!empty($msg['image'])) {
        $imgFile = __DIR__ . '/../' . ltrim($msg['image'], '/');
        if (is_file($imgFile) && !@unlink($imgFile)) {
            error_log('无法删除留言图片（记录已删除）: ' . $imgFile);
        }
    }

    return true;
}

/**
 * 校验图片路径是否为当前站点 uploads 目录下的合法相对路径
 * 防止详情页/管理后台输出被篡改的路径
 */
function isValidImagePath($path) {
    if (!is_string($path) || $path === '') {
        return false;
    }
    if (strpos($path, 'uploads/') !== 0) {
        return false;
    }
    // 不允许目录穿越、反斜杠、空字节
    if (strpos($path, '..') !== false || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
        return false;
    }
    return (bool) preg_match('#^uploads/[A-Za-z0-9_/.-]+\.(jpe?g|png|gif|webp)$#i', $path);
}

/**
 * 输出安全的图片地址；路径非法或文件不存在时返回 null（列表“有图”标记与详情展示保持一致）
 */
function publicImageUrl($path) {
    if (!isValidImagePath($path)) {
        return null;
    }
    $full = __DIR__ . '/../' . $path;
    if (!is_file($full)) {
        return null;
    }
    return $path;
}
