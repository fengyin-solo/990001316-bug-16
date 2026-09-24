<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$imageFile = $_FILES['image'] ?? null;

// 未选择图片时 PHP 不会生成该字段，构造一个统一的“无文件”标记
if ($imageFile === null) {
    $imageFile = ['error' => UPLOAD_ERR_NO_FILE];
}

try {
    $result = submitMessage($_POST, $imageFile);

    if ($result['duplicated']) {
        // 网络中断后重试：第一次其实已成功，不重复落地，明确告知用户
        jsonResponse(0, '该留言已提交成功，无需重复提交，当前状态：' . $result['status_text'], [
            'message_id' => $result['message_id'],
            'duplicated' => true,
        ]);
    }

    jsonResponse(0, '留言提交成功，等待审核', [
        'message_id' => $result['message_id'],
        'duplicated' => false,
    ]);
} catch (Exception $e) {
    // 校验类错误（含图片格式不符）明确返回原因；前端保留用户已填全部内容
    jsonResponse(1, $e->getMessage());
}
