<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '发布留言 - 社区便民留言板';
$currentPage = 'submit';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="submit-section">
    <div class="container">
        <div class="submit-card">
            <h2 class="submit-title">📝 发布留言</h2>
            <form id="submitForm" class="submit-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" id="clientToken" name="client_token">
                <div class="form-alert" id="formAlert" style="display:none;" role="alert"></div>

                <div class="form-group">
                    <label for="nickname">昵称 <span class="required">*</span></label>
                    <input type="text" id="nickname" name="nickname" placeholder="请输入您的昵称" maxlength="50" required>
                    <span class="field-error" id="nicknameError"></span>
                </div>

                <div class="form-group">
                    <label for="phone">联系电话</label>
                    <input type="tel" id="phone" name="phone" placeholder="选填，方便联系" maxlength="20">
                    <span class="field-error" id="phoneError"></span>
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
                    <span class="field-error" id="titleError"></span>
                </div>

                <div class="form-group">
                    <label for="content">详细内容 <span class="required">*</span></label>
                    <textarea id="content" name="content" rows="6" placeholder="请详细描述您的留言内容..." maxlength="2000" required></textarea>
                    <span class="char-count"><span id="charCount">0</span>/2000</span>
                    <span class="field-error" id="contentError"></span>
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
                    <span class="field-error" id="imageError"></span>
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
(function() {
    'use strict';

    var ALLOWED_TYPES = {
        'image/jpeg': ['jpg', 'jpeg'],
        'image/png':  ['png'],
        'image/gif':  ['gif'],
        'image/webp': ['webp']
    };
    var MAX_SIZE = 5 * 1024 * 1024;
    var submitting = false;

    // 每次进入页面生成唯一提交凭证；网络中断重试沿用同一凭证，服务端据此保证只落地一次
    function generateToken() {
        if (window.crypto && window.crypto.getRandomValues) {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            var hex = '';
            for (var i = 0; i < bytes.length; i++) {
                hex += ('00' + bytes[i].toString(16)).slice(-2);
            }
            return 'tok_' + hex;
        }
        return 'tok_' + Date.now().toString(36) + Math.random().toString(36).slice(2);
    }
    document.getElementById('clientToken').value = generateToken();

    function showFieldError(id, message) {
        var el = document.getElementById(id);
        if (el) el.textContent = message || '';
    }

    function showFormAlert(message, type) {
        var box = document.getElementById('formAlert');
        box.className = 'form-alert ' + (type === 'success' ? 'form-alert-success' : 'form-alert-error');
        box.textContent = message || '';
        box.style.display = message ? 'block' : 'none';
    }

    function setSubmitting(state) {
        submitting = state;
        var btn = document.getElementById('submitBtn');
        btn.disabled = state;
        btn.textContent = state ? '提交中...' : '提交留言';
    }

    // 字数统计
    document.getElementById('content').addEventListener('input', function() {
        document.getElementById('charCount').textContent = this.value.length;
    });

    // 图片预览与前端校验
    var imageInput = document.getElementById('image');
    imageInput.addEventListener('change', function(e) {
        var file = e.target.files[0];
        showFieldError('imageError', '');
        if (!file) {
            removeImage();
            return;
        }

        if (!ALLOWED_TYPES.hasOwnProperty(file.type)) {
            showFieldError('imageError', '仅支持 JPG、PNG、GIF、WebP 格式的图片，当前文件格式为「' + (file.type || '未知') + '」，请更换图片。已填的文字内容不会丢失。');
            this.value = '';
            return;
        }

        var dotIdx = file.name.lastIndexOf('.');
        var ext = dotIdx >= 0 ? file.name.slice(dotIdx + 1).toLowerCase() : '';
        if (ALLOWED_TYPES[file.type].indexOf(ext) === -1) {
            showFieldError('imageError', '图片实际格式与文件后缀名「.' + ext + '」不一致，请改名为 .' + ALLOWED_TYPES[file.type][0] + ' 或转换格式后重新上传。');
            this.value = '';
            return;
        }

        if (file.size > MAX_SIZE) {
            showFieldError('imageError', '图片大小为 ' + (file.size / 1024 / 1024).toFixed(1) + 'MB，不能超过 5MB，请压缩或更换图片。');
            this.value = '';
            return;
        }

        var reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('previewImg').src = ev.target.result;
            document.getElementById('uploadPlaceholder').style.display = 'none';
            document.getElementById('uploadPreview').style.display = 'flex';
        };
        reader.onerror = function() {
            showFieldError('imageError', '图片读取失败，请重新选择。');
            imageInput.value = '';
        };
        reader.readAsDataURL(file);
    });

    window.removeImage = function() {
        imageInput.value = '';
        document.getElementById('uploadPlaceholder').style.display = 'flex';
        document.getElementById('uploadPreview').style.display = 'none';
        document.getElementById('previewImg').src = '';
        showFieldError('imageError', '');
    };

    // 表单校验（失败时明确指出字段原因，不清空任何已填内容）
    function validateForm() {
        var ok = true;
        var nickname = document.getElementById('nickname').value.trim();
        var title = document.getElementById('title').value.trim();
        var content = document.getElementById('content').value.trim();
        var phone = document.getElementById('phone').value.trim();

        showFieldError('nicknameError', '');
        showFieldError('titleError', '');
        showFieldError('contentError', '');
        showFieldError('phoneError', '');

        if (!nickname) {
            showFieldError('nicknameError', '请输入昵称');
            ok = false;
        } else if (nickname.length > 50) {
            showFieldError('nicknameError', '昵称不能超过50个字符');
            ok = false;
        }
        if (phone && !/^[0-9+\-\s()]{5,20}$/.test(phone)) {
            showFieldError('phoneError', '联系电话格式不正确');
            ok = false;
        }
        if (!title) {
            showFieldError('titleError', '请输入标题');
            ok = false;
        } else if (title.length > 100) {
            showFieldError('titleError', '标题不能超过100个字符');
            ok = false;
        }
        if (!content) {
            showFieldError('contentError', '请输入内容');
            ok = false;
        } else if (content.length > 2000) {
            showFieldError('contentError', '内容不能超过2000个字符');
            ok = false;
        }
        return ok;
    }

    // 表单提交：失败保留全部内容；网络中断重试沿用同一凭证，只产生一条待审记录
    document.getElementById('submitForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (submitting) return;
        showFormAlert('');

        if (!validateForm()) {
            showFormAlert('请检查标红的表单项后重新提交，已填内容均会保留。', 'error');
            return;
        }

        setSubmitting(true);

        var formData = new FormData(this);
        fetch('api/submit.php', { method: 'POST', body: formData })
            .then(function(r) {
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            })
            .then(function(data) {
                if (data.code === 0) {
                    showFormAlert(data.msg || '留言提交成功，等待审核', 'success');
                    var messageId = data.data && data.data.message_id;
                    setTimeout(function() {
                        window.location.href = messageId
                            ? 'detail.php?id=' + messageId
                            : 'index.php';
                    }, 600);
                } else {
                    // 服务端校验未过（如图片格式不符）：明确原因，保留全部已填文字
                    setSubmitting(false);
                    showFormAlert(data.msg || '提交失败，请根据提示修改后重试', 'error');
                }
            })
            .catch(function() {
                // 网络中断：保留全部已填内容与同一提交凭证，用户可直接重试，服务端保证只落地一次
                setSubmitting(false);
                showFormAlert('网络连接中断，提交未确认。请检查网络后点击“提交留言”重试，重复提交不会产生多条记录。', 'error');
            });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
