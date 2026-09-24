<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '发布留言 - 社区便民留言板';
$currentPage = 'submit';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

// 幂等令牌：同一次填写多次重试共用，提交成功后刷新页面换新令牌
$submitToken = bin2hex(random_bytes(32));

include __DIR__ . '/includes/header.php';
?>

<section class="submit-section">
    <div class="container">
        <div class="submit-card">
            <h2 class="submit-title">📝 发布留言</h2>
            <div class="form-message" id="formMessage" role="alert" style="display:none;"></div>
            <form id="submitForm" class="submit-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="submit_token" value="<?= $submitToken ?>">
                <div class="form-group">
                    <label for="nickname">昵称 <span class="required">*</span></label>
                    <input type="text" id="nickname" name="nickname" placeholder="请输入您的昵称" maxlength="50" required>
                </div>

                <div class="form-group">
                    <label for="phone">联系电话</label>
                    <input type="tel" id="phone" name="phone" placeholder="选填，方便联系" maxlength="20">
                </div>

                <div class="form-group">
                    <label for="type">留言类型 <span class="required">*</span></label>
                    <div class="type-selector">
                        <label class="type-option">
                            <input type="radio" name="type" value="help" checked>
                            <span class="type-btn type-help">🆘 居民求助</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="type" value="suggest">
                            <span class="type-btn type-suggest">💡 意见建议</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="type" value="lost">
                            <span class="type-btn type-lost">🔍 失物招领</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="title">标题 <span class="required">*</span></label>
                    <input type="text" id="title" name="title" placeholder="请简要描述您的留言主题" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="content">详细内容 <span class="required">*</span></label>
                    <textarea id="content" name="content" rows="6" placeholder="请详细描述您的留言内容..." maxlength="2000" required></textarea>
                    <span class="char-count"><span id="charCount">0</span>/2000</span>
                </div>

                <div class="form-group">
                    <label for="image">上传图片</label>
                    <div class="upload-area" id="uploadArea">
                        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                        <div class="upload-placeholder" id="uploadPlaceholder" onclick="document.getElementById('image').click()">
                            <div class="upload-icon">📷</div>
                            <p>点击上传图片</p>
                            <span class="upload-hint">支持 JPG、PNG、GIF、WebP，最大 5MB</span>
                        </div>
                        <div class="upload-preview" id="uploadPreview" style="display:none;">
                            <img id="previewImg" src="" alt="预览">
                            <button type="button" class="remove-image" onclick="removeImage()">✕ 移除</button>
                        </div>
                    </div>
                    <div class="upload-error" id="imageError" style="display:none;"></div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">提交留言</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">返回首页</a>
                </div>
            </form>
        </div>
    </div>
</section>

<script>
// 字数统计
document.getElementById('content').addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

// 允许的图片魔数（按文件真实内容判断，不信任扩展名和浏览器 MIME）
const IMAGE_SIGNATURES = [
    {mime: 'image/jpeg', ext: 'JPG',  match: b => b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF},
    {mime: 'image/png',  ext: 'PNG',  match: b => b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4E && b[3] === 0x47},
    {mime: 'image/gif',  ext: 'GIF',  match: b => b[0] === 0x47 && b[1] === 0x49 && b[2] === 0x46},
    {mime: 'image/webp', ext: 'WebP', match: b => b[0] === 0x52 && b[1] === 0x49 && b[2] === 0x46 && b[3] === 0x46 && b[8] === 0x57 && b[9] === 0x45 && b[10] === 0x42 && b[11] === 0x50},
];

function detectImageType(file) {
    return new Promise(resolve => {
        const reader = new FileReader();
        reader.onload = function(ev) {
            const bytes = new Uint8Array(ev.target.result);
            const found = IMAGE_SIGNATURES.some(s => s.match(bytes));
            resolve(found);
        };
        reader.onerror = () => resolve(false);
        // 只读前 12 字节即可识别
        reader.readAsArrayBuffer(file.slice(0, 12));
    });
}

function showImageError(msg) {
    const el = document.getElementById('imageError');
    el.textContent = '⚠️ ' + msg;
    el.style.display = 'block';
}
function clearImageError() {
    const el = document.getElementById('imageError');
    el.textContent = '';
    el.style.display = 'none';
}

function showFormMessage(msg, type) {
    const box = document.getElementById('formMessage');
    box.className = 'form-message alert alert-' + (type === 'success' ? 'success' : 'error');
    box.textContent = msg;
    box.style.display = 'block';
}
function clearFormMessage() {
    const box = document.getElementById('formMessage');
    box.style.display = 'none';
    box.textContent = '';
}

// 图片预览（格式/大小不符时明确指出原因，文字内容原样保留，只清掉不合规的图片）
document.getElementById('image').addEventListener('change', async function(e) {
    clearImageError();
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        showImageError('图片大小 ' + (file.size / 1024 / 1024).toFixed(1) + 'MB，超过 5MB 限制，请压缩后重新选择');
        removeImage();
        return;
    }

    const valid = await detectImageType(file);
    if (!valid) {
        showImageError('图片格式不符合要求，仅支持 JPG、PNG、GIF、WebP（已按文件真实内容检测），请重新选择图片');
        removeImage();
        return;
    }

    const reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('previewImg').src = ev.target.result;
        document.getElementById('uploadPlaceholder').style.display = 'none';
        document.getElementById('uploadPreview').style.display = 'flex';
    };
    reader.onerror = function() {
        showImageError('图片读取失败，文件可能已损坏，请重新选择');
        removeImage();
    };
    reader.readAsDataURL(file);
});

function removeImage() {
    const input = document.getElementById('image');
    input.value = '';
    document.getElementById('uploadPlaceholder').style.display = 'flex';
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('previewImg').src = '';
}

let submitting = false;

// 表单提交：按钮防重复点击；网络/服务器错误时保留全部已填内容并可原地重试（同一令牌，只落地一次）
document.getElementById('submitForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (submitting) return;

    clearFormMessage();

    // 前端必填校验（失败不丢内容）
    const nickname = document.getElementById('nickname').value.trim();
    const title = document.getElementById('title').value.trim();
    const content = document.getElementById('content').value.trim();
    if (!nickname) { showFormMessage('请输入昵称', 'error'); document.getElementById('nickname').focus(); return; }
    if (!title)    { showFormMessage('请输入标题', 'error'); document.getElementById('title').focus(); return; }
    if (!content)  { showFormMessage('请输入详细内容', 'error'); document.getElementById('content').focus(); return; }

    const btn = document.getElementById('submitBtn');
    const setLoading = (loading) => {
        submitting = loading;
        btn.disabled = loading;
        btn.textContent = loading ? '提交中...' : '提交留言';
    };

    setLoading(true);
    const formData = new FormData(this);

    try {
        const resp = await fetch('api/submit.php', { method: 'POST', body: formData });
        let data = null;
        try {
            data = await resp.json();
        } catch (parseErr) {
            throw new Error('服务器响应异常（HTTP ' + resp.status + '），请检查网络后重试');
        }

        if (data.code === 0) {
            showFormMessage((data.data && data.data.duplicate)
                ? '该留言已在审核队列中，无需重复提交，即将返回首页...'
                : '留言提交成功，等待审核，即将返回首页...', 'success');
            btn.textContent = '提交成功';
            setTimeout(() => { window.location.href = 'index.php'; }, 800);
        } else {
            // 失败：保留全部已填内容（含已选图片），原地重试
            showFormMessage(data.msg || '提交失败，请重试', 'error');
            setLoading(false);
        }
    } catch (err) {
        // 网络中断等：服务器可能已收到，重试时由幂等令牌保证只落地一条
        showFormMessage('网络错误，提交可能未完成。请点击“提交留言”重试，系统会自动避免重复记录（已填内容已保留）', 'error');
        setLoading(false);
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
