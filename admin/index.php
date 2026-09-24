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

if ($status !== '' && in_array($status, ['0', '1', '2'])) {
    $where .= " AND status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
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

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

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
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="8" class="text-center">
                        <div style="padding:28px 12px;color:var(--gray-500);">
                            <div style="font-size:2rem;margin-bottom:8px;">🗂️</div>
                            <?php if ($status === '0'): ?>
                            <p>暂无待审核留言，新提交的留言会出现在这里</p>
                            <?php elseif ($status === '1'): ?>
                            <p>暂无已通过的留言</p>
                            <?php elseif ($status === '2'): ?>
                            <p>暂无已拒绝的留言</p>
                            <?php elseif ($keyword !== '' || $type !== ''): ?>
                            <p>没有符合筛选条件的留言，请尝试调整关键词或筛选条件</p>
                            <a href="index.php" class="btn btn-secondary btn-sm" style="margin-top:8px;">清除筛选</a>
                            <?php else: ?>
                            <p>暂无留言数据</p>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>">
                            <?= cleanInput(mb_substr($msg['title'], 0, 20)) ?>
                            <?php if (publicImageUrl($msg['image'])): ?> 📷<?php elseif ($msg['image']): ?> ⚠️<?php endif; ?>
                        </td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= $msg['id'] ?>)">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= $msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= $msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= $msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
function apiPost(body) {
    return fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    }).then(r => r.json());
}

function handleAction(promiseFactory, confirmText, {retryable = true} = {}) {
    if (!window.confirm(confirmText)) return Promise.resolve();
    const run = () => promiseFactory().then(data => {
        if (data.code === 0) {
            window.alert(data.msg || '操作成功');
            location.reload();
        } else {
            const retry = retryable && window.confirm('操作失败：' + (data.msg || '未知错误') + '\n\n点击“确定”重试，点击“取消”留在当前页面（数据未变更）。');
            if (retry) return run();
        }
    }).catch(() => {
        const retry = window.confirm('网络错误，操作未完成，数据未变更。\n\n点击“确定”重试，点击“取消”留在当前页面。');
        if (retry) return run();
    });
    return run();
}

function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    handleAction(
        () => apiPost('action=audit&id=' + encodeURIComponent(id) + '&status=' + status),
        '确定要' + action + '这条留言吗？'
    );
}

function deleteMessage(id) {
    handleAction(
        () => apiPost('action=delete&id=' + encodeURIComponent(id)),
        '确定要删除这条留言吗？关联的图片、收藏与举报记录将一并清理，此操作不可恢复！'
    );
}

function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function viewMessage(id) {
    const modal = document.getElementById('viewModal');
    const body = document.getElementById('modalBody');
    modal.style.display = 'flex';
    body.innerHTML = '<div class="text-center" style="padding:24px;">加载中...</div>';
    fetch('api.php?action=detail&id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>类型：</strong>' + escapeHtml(d.type_label) + '</p>';
            html += '<p><strong>标题：</strong>' + escapeHtml(d.title) + '</p>';
            html += '<p><strong>昵称：</strong>' + escapeHtml(d.nickname) + '</p>';
            html += '<p><strong>电话：</strong>' + escapeHtml(d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image_url) {
                html += '<p><strong>图片：</strong><br><img src="../' + encodeURI(d.image_url) + '" style="max-width:100%;margin-top:8px;" onerror="this.closest(\'p\').innerHTML=\'图片文件缺失（记录与文件不同步，请删除该留言）\'"></p>';
            } else if (d.image) {
                html += '<p class="text-muted">图片文件缺失或路径异常（数据库记录：' + escapeHtml(d.image) + '）</p>';
            }
            html += '<p><strong>状态：</strong><span class="status-badge status-' + escapeHtml(d.status_class || '') + '">' + escapeHtml(d.status_label) + '</span></p>';
            html += '<p><strong>浏览量：</strong>' + escapeHtml(d.views) + '</p>';
            html += '<p><strong>时间：</strong>' + escapeHtml(d.created_at) + '</p>';
            html += '</div>';
            body.innerHTML = html;
        } else {
            body.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><p>' + escapeHtml(data.msg || '加载失败') + '</p><button type="button" class="btn btn-secondary" onclick="viewMessage(' + id + ')">重试</button> <button type="button" class="btn btn-primary" onclick="location.reload()">刷新列表</button></div>';
        }
    })
    .catch(() => {
        body.innerHTML = '<div class="empty-state"><div class="empty-icon">📡</div><p>网络错误，详情加载失败</p><button type="button" class="btn btn-primary" onclick="viewMessage(' + id + ')">重试</button></div>';
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
