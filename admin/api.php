<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在或已被删除');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['status_class'] = getStatusClass($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        $msg['phone'] = $msg['phone'] !== null ? cleanInput($msg['phone']) : '';
        $msg['image_url'] = publicImageUrl($msg['image']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(405, '不支持的请求方式');
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        if (!in_array($status, [1, 2], true)) jsonResponse(1, '无效的审核状态');

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT status FROM messages WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                $db->rollBack();
                jsonResponse(1, '留言不存在或已被删除，审核队列可能已刷新，请重新查看');
            }
            if ((int)$row['status'] === $status) {
                $db->rollBack();
                jsonResponse(1, '该留言已是「' . getStatusLabel($status) . '」状态，无需重复操作');
            }
            $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $db->commit();
            jsonResponse(0, $status === 1 ? '已通过审核' : '已拒绝该留言');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(500, '审核操作失败，数据未变更，请重试');
        }
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(405, '不支持的请求方式');
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        try {
            $deleted = deleteMessageCascade($db, $id);
            if (!$deleted) jsonResponse(1, '留言不存在或已被删除');
            jsonResponse(0, '删除成功，留言及关联图片已同步清理');
        } catch (Exception $e) {
            error_log('删除留言失败 id=' . $id . ': ' . $e->getMessage());
            jsonResponse(500, '删除失败，数据未变更，请重试');
        }
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的举报ID');
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, m.status as message_status, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['message_image_url'] = publicImageUrl($report['message_image']);
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(405, '不支持的请求方式');
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if ($id <= 0) jsonResponse(1, '无效的举报ID');
        if (!in_array($status, [1, 2, 3], true)) jsonResponse(1, '无效状态');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理备注不能超过500字');

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) {
                $db->rollBack();
                jsonResponse(1, '举报不存在或已处理，列表可能已刷新');
            }

            if ($status === 1) {
                // 先确认留言仍存在（FOR UPDATE 锁定，避免与前台/其他管理员操作竞态）
                $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? FOR UPDATE");
                $stmt->execute([$report['message_id']]);
                $target = $stmt->fetch();

                if ($target) {
                    // 在同一事务内删除留言主体；收藏显式清理，举报稍后统一更新
                    $db->prepare("DELETE FROM favorites WHERE message_id = ?")->execute([$report['message_id']]);
                    $db->prepare("DELETE FROM submission_tokens WHERE message_id = ?")->execute([$report['message_id']]);

                    // 先取出图片路径，提交后再删磁盘文件
                    $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                    $stmt->execute([$report['message_id']]);
                    $targetMsg = $stmt->fetch();
                    $imagePath = $targetMsg ? $targetMsg['image'] : null;

                    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
                } else {
                    $imagePath = null;
                }
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            // 同一条留言的其他待处理举报同步标记，避免待处理队列残留指向已删除留言的记录
            if ($status === 1) {
                $db->prepare(
                    "UPDATE reports
                     SET status = 1, processed_by = ?, processed_at = NOW(),
                         process_note = CONCAT('随举报 #', ?, ' 一并处理')
                     WHERE message_id = ? AND status = 0 AND id <> ?"
                )->execute([$_SESSION['admin_id'], $id, $report['message_id'], $id]);
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('处理举报失败 report_id=' . $id . ': ' . $e->getMessage());
            jsonResponse(500, '操作失败，数据未变更，请重试');
        }

        // 事务提交成功后删除磁盘图片
        if ($status === 1 && !empty($imagePath)) {
            $imgFile = __DIR__ . '/../' . ltrim($imagePath, '/');
            if (is_file($imgFile) && !@unlink($imgFile)) {
                error_log('无法删除留言图片（记录已删除）: ' . $imgFile);
            }
        }

        $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
        jsonResponse(0, $statusMsg[$status] . '成功');
        break;

    default:
        jsonResponse(1, '未知操作');
}
