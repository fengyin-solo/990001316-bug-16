<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

try {
switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在或已被删除');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        // 图片文件缺失时不同步展示，避免后台能看到图而前台详情打不开
        $msg['image'] = existingImagePath($msg['image']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');
        if (!in_array($status, [1, 2], true)) jsonResponse(1, '无效状态');

        // 行级校验：目标必须存在，且不能重复审核为同一状态，杜绝重复/失效操作
        $stmt = $db->prepare("SELECT id, status FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) jsonResponse(1, '留言不存在或已被删除');
        if ((int)$target['status'] === $status) {
            jsonResponse(1, $status === 1 ? '该留言已通过审核，无需重复操作' : '该留言已被拒绝，无需重复操作');
        }

        $affected = $db->prepare("UPDATE messages SET status = ? WHERE id = ? AND status <> ?")
            ->execute([$status, $id, $status]);
        if ($affected === 0) jsonResponse(1, '操作未生效，留言状态可能已被其他管理员变更，请刷新后查看');
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) jsonResponse(1, '无效的留言ID');

        // 事务内删除记录并同步清理图片；收藏/举报通过外键 ON DELETE CASCADE 一并清理，不留孤立记录
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT image FROM messages WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $msg = $stmt->fetch();
            if (!$msg) {
                $db->rollBack();
                jsonResponse(1, '留言不存在或已被删除');
            }

            $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(500, '删除失败，请稍后重试');
        }

        // 记录删除成功后再清理磁盘文件；文件缺失不算失败
        deleteMessageImage($msg['image']);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
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
        // 图片记录全系统同一口径：磁盘文件存在才返回
        $report['message_image'] = $report['message_image'] ? existingImagePath($report['message_image']) : null;
        $report['description'] = $report['description'] !== null && $report['description'] !== '' ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] !== null && $report['process_note'] !== '' ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if ($id <= 0) jsonResponse(1, '无效的举报ID');
        if (!in_array($status, [1, 2, 3], true)) jsonResponse(1, '无效状态');

        $imageToDelete = null;
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) {
                $db->rollBack();
                jsonResponse(1, '举报不存在或已处理，请刷新页面');
            }

            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ? FOR UPDATE");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg) {
                    // 收藏/举报外键 ON DELETE CASCADE 同步清理，不留孤立记录
                    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
                    $imageToDelete = $msg['image'];
                }
                // 留言已被其他渠道删除时不阻断举报处理流程
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ? AND status = 0");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '操作失败，请稍后重试');
        }

        // 事务提交成功后清理磁盘图片
        if ($imageToDelete) deleteMessageImage($imageToDelete);

        $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
        jsonResponse(0, $statusMsg[$status] . '成功');
        break;

    default:
        jsonResponse(1, '未知操作');
}
} catch (Exception $e) {
    jsonResponse(500, '服务器繁忙，请稍后重试');
}
