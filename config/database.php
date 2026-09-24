<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'community_board');
define('DB_CHARSET', 'utf8mb4');

/**
 * 数据库连接失败时，按请求场景给出可读提示（API 返回 JSON，页面返回说明页）
 */
function dbConnectionError($message) {
    $isJsonApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');

    http_response_code(500);
    if ($isJsonApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 500,
            'msg' => '网络异常，服务暂时不可用，请稍后重试',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $reason = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>服务暂时不可用 - 社区便民留言板</title>
<style>
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f6fa;margin:0;padding:40px 16px;color:#374151;}
.box{max-width:520px;margin:60px auto;background:#fff;border-radius:12px;padding:40px 32px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.06);}
.icon{font-size:48px;margin-bottom:16px;}
h2{margin:0 0 12px;font-size:1.25rem;}
p{color:#6b7280;line-height:1.7;margin:0 0 8px;}
.reason{font-size:.85rem;color:#9ca3af;word-break:break-all;}
.actions{margin-top:24px;display:flex;gap:12px;justify-content:center;}
.btn{display:inline-block;padding:10px 24px;border-radius:8px;text-decoration:none;font-size:.95rem;}
.btn-primary{background:#3b82f6;color:#fff;}
.btn-secondary{background:#f3f4f6;color:#374151;}
</style>
</head>
<body>
<div class="box">
    <div class="icon">⚠️</div>
    <h2>网络异常，服务暂时不可用</h2>
    <p>数据加载失败，请检查网络连接后重试。若问题持续存在，请稍后再试。</p>
    <p class="reason">错误原因：{$reason}</p>
    <div class="actions">
        <a href="javascript:location.reload()" class="btn btn-primary">重新加载</a>
        <a href="index.php" class="btn btn-secondary">返回首页</a>
    </div>
</div>
</body>
</html>
HTML;
    }
    exit;
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            dbConnectionError($e->getMessage());
        }
    }
    return $pdo;
}
