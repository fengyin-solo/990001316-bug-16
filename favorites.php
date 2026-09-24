<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '我的收藏 - 社区便民留言板';
$currentPage = 'favorites';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$db = getDB();
$visitorId = getVisitorId();

$type = $_GET['type'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 10;
$offset = ($page - 1) * $pageSize;

$favorites = [];
$total = 0;
$totalPages = 0;
$stats = ['total' => 0, 'help_count' => 0, 'suggest_count' => 0, 'lost_count' => 0];
$loadError = false;

try {
    $where = "WHERE f.visitor_id = ? AND m.status = 1";
    $params = [$visitorId];

    if ($type && in_array($type, ['help', 'suggest', 'lost'], true)) {
        $where .= " AND m.type = ?";
        $params[] = $type;
    }

    $countSql = "SELECT COUNT(*) FROM favorites f INNER JOIN messages m ON f.message_id = m.id $where";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($total / $pageSize);

    $sql = "SELECT m.id, m.nickname, m.type, m.title, m.content, m.image, m.views, m.created_at, f.created_at as favorited_at
            FROM favorites f
            INNER JOIN messages m ON f.message_id = m.id
            $where
            ORDER BY f.created_at DESC
            LIMIT $pageSize OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $favorites = $stmt->fetchAll();

    // 与列表/详情保持同一口径：图片文件真实存在才显示“有图”
    foreach ($favorites as &$fv) {
        $fv['image'] = existingImagePath($fv['image']);
    }
    unset($fv);

    $favoritedIds = getFavoritedMessageIds();
    $favoritedIds = array_flip($favoritedIds);

    $statsStmt = $db->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN m.type='help' THEN 1 ELSE 0 END) as help_count,
        SUM(CASE WHEN m.type='suggest' THEN 1 ELSE 0 END) as suggest_count,
        SUM(CASE WHEN m.type='lost' THEN 1 ELSE 0 END) as lost_count
        FROM favorites f INNER JOIN messages m ON f.message_id = m.id
        WHERE f.visitor_id = ? AND m.status = 1");
    $statsStmt->execute([$visitorId]);
    $row = $statsStmt->fetch();
    $stats = [
        'total' => (int)($row['total'] ?? 0),
        'help_count' => (int)($row['help_count'] ?? 0),
        'suggest_count' => (int)($row['suggest_count'] ?? 0),
        'lost_count' => (int)($row['lost_count'] ?? 0),
    ];
} catch (Exception $e) {
    $loadError = true;
}

include __DIR__ . '/includes/header.php';
?>

<section class="favorites-section">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">⭐ 我的收藏</h1>
            <p class="page-subtitle">共收藏 <?= $stats['total'] ?? 0 ?> 条留言</p>
        </div>

        <div class="favorites-stats">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
                <div class="stat-label">全部收藏</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $stats['help_count'] ?? 0 ?></div>
                <div class="stat-label">🆘 求助</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $stats['suggest_count'] ?? 0 ?></div>
                <div class="stat-label">💡 建议</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $stats['lost_count'] ?? 0 ?></div>
                <div class="stat-label">🔍 失物</div>
            </div>
        </div>

        <div class="filter-section">
            <div class="filter-types">
                <a href="favorites.php" class="filter-tag <?= !$type ? 'active' : '' ?>">全部</a>
                <a href="favorites.php?type=help" class="filter-tag <?= $type === 'help' ? 'active' : '' ?>">🆘 求助</a>
                <a href="favorites.php?type=suggest" class="filter-tag <?= $type === 'suggest' ? 'active' : '' ?>">💡 建议</a>
                <a href="favorites.php?type=lost" class="filter-tag <?= $type === 'lost' ? 'active' : '' ?>">🔍 失物招领</a>
            </div>
        </div>
    </div>
</section>

<section class="message-list-section">
    <div class="container">
        <?php if ($loadError): ?>
        <div class="state-card">
            <div class="state-icon">⚠️</div>
            <h2 class="state-title">收藏加载失败</h2>
            <p class="state-desc">网络异常或服务暂时不可用，请稍后重试。</p>
            <div class="state-actions">
                <a href="javascript:location.reload()" class="btn btn-primary">重新加载</a>
                <a href="index.php" class="btn btn-secondary">去浏览留言</a>
            </div>
        </div>
        <?php elseif (empty($favorites)): ?>
        <div class="empty-state">
            <div class="empty-icon">⭐</div>
            <p><?= $type ? '该分类下暂无收藏的留言' : '暂无收藏的留言' ?></p>
            <a href="index.php" class="btn btn-primary">去浏览留言</a>
        </div>
        <?php else: ?>
        <div class="message-list">
            <?php foreach ($favorites as $msg): ?>
            <div class="message-card">
                <a href="detail.php?id=<?= $msg['id'] ?>" class="card-link">
                    <div class="card-header">
                        <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                        <span class="card-time">收藏于 <?= timeAgo($msg['favorited_at']) ?></span>
                    </div>
                    <h3 class="card-title"><?= cleanInput($msg['title']) ?></h3>
                    <p class="card-content"><?= cleanInput(mb_substr($msg['content'], 0, 80)) ?><?= mb_strlen($msg['content']) > 80 ? '...' : '' ?></p>
                    <div class="card-footer">
                        <span class="card-author">👤 <?= cleanInput($msg['nickname']) ?></span>
                        <?php if ($msg['image']): ?>
                        <span class="card-image">📷 有图</span>
                        <?php endif; ?>
                        <span class="card-views">👁 <?= $msg['views'] ?></span>
                    </div>
                </a>
                <button class="favorite-btn favorited" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon">⭐</span>
                    <span class="favorite-text">已收藏</span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="favorites.php?page=<?= $page - 1 ?>&type=<?= $type ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="favorites.php?page=<?= $i ?>&type=<?= $type ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="favorites.php?page=<?= $page + 1 ?>&type=<?= $type ?>" class="page-btn">下一页</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
