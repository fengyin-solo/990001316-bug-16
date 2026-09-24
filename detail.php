<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);

$pageTitle = '留言详情 - 社区便民留言板';
$currentPage = '';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$msg = null;
$invalidId = $id <= 0;

if (!$invalidId) {
    try {
        $db = getDB();

        // 先查留言，避免给不存在/未通过的留言刷浏览量
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();

        if ($msg && (int)$msg['status'] === 1) {
            $db->prepare("UPDATE messages SET views = views + 1 WHERE id = ? AND status = 1")->execute([$id]);
            $msg['views'] = (int)$msg['views'] + 1;
        }
    } catch (Exception $e) {
        $msg = null;
        $loadError = true;
    }
}

include __DIR__ . '/includes/header.php';

// 异常：数据库查询失败
if (!empty($loadError)):
?>
<section class="detail-section">
    <div class="container">
        <div class="state-card">
            <div class="state-icon">⚠️</div>
            <h2 class="state-title">详情加载失败</h2>
            <p class="state-desc">网络异常或服务暂时不可用，请稍后重试。</p>
            <div class="state-actions">
                <a href="javascript:location.reload()" class="btn btn-primary">重新加载</a>
                <a href="index.php" class="btn btn-secondary">返回首页</a>
            </div>
        </div>
    </div>
</section>
<?php
// 参数非法
elseif ($invalidId || !$msg):
?>
<section class="detail-section">
    <div class="container">
        <div class="state-card">
            <div class="state-icon">🔍</div>
            <h2 class="state-title">留言不存在</h2>
            <p class="state-desc">该留言可能已被删除，或链接地址有误。</p>
            <div class="state-actions">
                <a href="index.php" class="btn btn-primary">返回首页</a>
                <a href="submit.php" class="btn btn-secondary">发布留言</a>
            </div>
        </div>
    </div>
</section>
<?php
// 待审核：提交后跳转或本人查看时给出明确状态，而不是“找不到”
elseif ((int)$msg['status'] === 0):
?>
<section class="detail-section">
    <div class="container">
        <div class="state-card">
            <div class="state-icon">⏳</div>
            <h2 class="state-title">留言审核中</h2>
            <p class="state-desc">您的留言「<?= cleanInput($msg['title']) ?>」已提交成功，正在等待管理员审核，审核通过后将公开展示。</p>
            <p class="state-sub">提交时间：<?= cleanInput($msg['created_at']) ?></p>
            <div class="state-actions">
                <a href="index.php" class="btn btn-primary">返回首页</a>
                <a href="submit.php" class="btn btn-secondary">再发布一条</a>
            </div>
        </div>
    </div>
</section>
<?php
// 已拒绝：明确说明原因状态，不暴露详情内容
elseif ((int)$msg['status'] === 2):
?>
<section class="detail-section">
    <div class="container">
        <div class="state-card">
            <div class="state-icon">🚫</div>
            <h2 class="state-title">留言未通过审核</h2>
            <p class="state-desc">该留言因不符合社区规范未通过审核，暂不对外展示。如有疑问可重新发布并补充说明。</p>
            <div class="state-actions">
                <a href="index.php" class="btn btn-primary">返回首页</a>
                <a href="submit.php" class="btn btn-secondary">重新发布</a>
            </div>
        </div>
    </div>
</section>
<?php
else:
    // 图片记录与文件严格同步：文件缺失时不显示图片区域
    $imagePath = existingImagePath($msg['image']);
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                <div class="detail-meta">
                    <span>👤 <?= cleanInput($msg['nickname']) ?></span>
                    <span>🕐 <?= cleanInput($msg['created_at']) ?></span>
                    <span>👁 <?= (int)$msg['views'] ?> 次浏览</span>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <div class="detail-content">
                <?= nl2br(cleanInput($msg['content'])) ?>
            </div>

            <?php if ($imagePath): ?>
            <div class="detail-image">
                <img src="<?= cleanInput($imagePath) ?>" alt="留言图片" onclick="window.open(this.src)">
            </div>
            <?php endif; ?>

            <?php if ($msg['phone']): ?>
            <div class="detail-contact">
                <span>📞 联系方式：<?= cleanInput($msg['phone']) ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-actions">
                <a href="index.php" class="btn btn-secondary">← 返回列表</a>
                <?php $isFav = isFavorited($msg['id']); ?>
                <button class="btn favorite-detail-btn <?= $isFav ? 'btn-warning' : 'btn-secondary' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= $isFav ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= $isFav ? '已收藏' : '收藏' ?></span>
                </button>
                <?php $hasReported = hasReported($msg['id']); ?>
                <button class="btn <?= $hasReported ? 'btn-secondary' : 'btn-danger' ?> report-btn" data-message-id="<?= $msg['id'] ?>" onclick="openReportModal(<?= $msg['id'] ?>)" <?= $hasReported ? 'disabled' : '' ?>>
                    <span>🚩</span>
                    <span class="report-text"><?= $hasReported ? '已举报' : '举报' ?></span>
                </button>
                <a href="submit.php" class="btn btn-primary">发布留言</a>
            </div>
        </div>
    </div>
</section>

<!-- 举报弹窗 -->
<div class="modal" id="reportModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🚩 举报留言</h3>
            <button class="modal-close" onclick="closeReportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reportForm">
                <input type="hidden" id="reportMessageId" name="message_id">
                <div class="form-group">
                    <label>举报类型 <span class="required">*</span></label>
                    <div class="report-type-options">
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="spam" required>
                            <span>🗑️ 垃圾信息</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="abuse">
                            <span>😡 辱骂攻击</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="illegal">
                            <span>⚖️ 违法违规</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="porn">
                            <span>🔞 色情低俗</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="other">
                            <span>📝 其他</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reportDescription">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="reportDescription" name="description" rows="4" maxlength="500" placeholder="请描述具体的违规内容，帮助我们更好地处理..."></textarea>
                    <span class="char-count"><span id="reportDescCount">0</span>/500</span>
                </div>
                <div class="form-tip">
                    <p>⚠️ 恶意举报将被限制功能使用，请如实填写举报内容。</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()">取消</button>
                    <button type="submit" class="btn btn-danger" id="reportSubmitBtn">提交举报</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
