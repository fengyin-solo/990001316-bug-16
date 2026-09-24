<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '123456');
define('DB_NAME', 'community_board');
define('DB_CHARSET', 'utf8mb4');

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
            error_log('数据库连接失败: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            // 开发期可在错误信息中查看原因；生产环境仅提示稍后重试
            $isApi = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false)
                || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
                || (strpos($_SERVER['REQUEST_URI'] ?? '', 'api.php') !== false);
            if ($isApi) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['code' => 500, 'msg' => '服务暂时不可用，请稍后重试'], JSON_UNESCAPED_UNICODE);
            } else {
                echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>服务暂时不可用</title>'
                    . '<style>body{font-family:sans-serif;text-align:center;padding:60px 20px;color:#374151}'
                    . '.box{max-width:480px;margin:0 auto}.icon{font-size:3rem;margin-bottom:12px}'
                    . 'a{display:inline-block;margin-top:16px;color:#2563eb}</style></head><body>'
                    . '<div class="box"><div class="icon">🛠️</div><h2>服务暂时不可用</h2>'
                    . '<p>数据库连接失败，请稍后重试；若持续出现请联系管理员。</p>'
                    . '<a class="a" href="javascript:location.reload()">🔄 重新加载</a></div></body></html>';
            }
            exit;
        }
    }
    return $pdo;
}
