<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2'], true)) {
    $where .= " AND status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'], true)) {
    $where .= " AND type = ?";
    $params[] = $type;
}
if ($keyword) {
    $where .= " AND (title LIKE ? OR content LIKE ? OR nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$messages = [];
$total = 0;
$totalPages = 0;
$pendingCount = 0;
$loadError = false;

try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($total / 15);

    $sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT 15 OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    // 审核队列看到的图片记录与前台列表/详情严格一致：文件缺失视为无图
    foreach ($messages as &$am) {
        $am['image'] = existingImagePath($am['image']);
    }
    unset($am);

    // 统计
    $pendingCount = (int)$db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
} catch (Exception $e) {
    $loadError = true;
}

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <?php if ($loadError): ?>
            <div class="state-card">
                <div class="state-icon">⚠️</div>
                <h2 class="state-title">数据加载失败</h2>
                <p class="state-desc">网络异常或数据库暂时不可用，请稍后重试。</p>
                <div class="state-actions">
                    <a href="javascript:location.reload()" class="btn btn-primary">重新加载</a>
                </div>
            </div>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>图片</th>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="9" class="text-center empty-row">暂无数据，请调整筛选条件后再试</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= (int)$msg['id'] ?></td>
                        <td><span class="badge badge-<?= cleanInput($msg['type']) ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td class="text-center"><?php if ($msg['image']): ?><a href="../<?= cleanInput($msg['image']) ?>" target="_blank" title="查看图片">📷</a><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= (int)$msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= (int)$msg['id'] ?>)">查看</button>
                            <?php if ((int)$msg['status'] !== 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= (int)$msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ((int)$msg['status'] !== 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= (int)$msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= (int)$msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<script>
function adminPost(body, onSuccess) {
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(data) {
        if (data.code === 0) {
            onSuccess(data);
        } else {
            alert(data.msg || '操作失败，请重试');
        }
    })
    .catch(function() {
        if (confirm('网络异常，操作未完成。是否重试？\n点击“确定”重试，点击“取消”留在当前页面。')) {
            adminPost(body, onSuccess);
        }
    });
}

function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    if (!confirm('确定要' + action + '这条留言吗？')) return;
    adminPost('action=audit&id=' + id + '&status=' + status, function() {
        alert('操作成功');
        location.reload();
    });
}

function deleteMessage(id) {
    if (!confirm('确定要删除这条留言吗？关联图片将一并删除，此操作不可恢复！')) return;
    adminPost('action=delete&id=' + id, function() {
        alert('删除成功');
        location.reload();
    });
}

function viewMessage(id) {
    const modal = document.getElementById('viewModal');
    const body = document.getElementById('modalBody');
    modal.style.display = 'flex';
    body.innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id)
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(data) {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><a href="../' + d.image + '" target="_blank"><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></a></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            body.innerHTML = html;
        } else {
            body.innerHTML = '<div class="state-inline"><p>⚠️ ' + (data.msg || '加载失败') + '</p><button class="btn btn-primary btn-sm" onclick="viewMessage(' + id + ')">重试</button></div>';
        }
    })
    .catch(function() {
        body.innerHTML = '<div class="state-inline"><p>⚠️ 网络异常，详情加载失败。</p><button class="btn btn-primary btn-sm" onclick="viewMessage(' + id + ')">重试</button></div>';
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
