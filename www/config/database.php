<?php
require_once __DIR__ . '/config.php';

/**
 * Kết nối database (PDO).
 * Laragon mặc định: user "root", mật khẩu rỗng.
 */

// Local Laragon defaults; set REDLINE_DB_* in the server environment for deployment.
function rl_db_env(string $key, string $default): string {
    $value = getenv('REDLINE_DB_' . $key);
    return $value === false ? $default : $value;
}
define('DB_HOST', rl_db_env('HOST', '127.0.0.1'));
define('DB_PORT', (int) rl_db_env('PORT', '3306'));
define('DB_NAME', rl_db_env('NAME', 'redlinev1'));
define('DB_USER', rl_db_env('USER', 'root'));
define('DB_PASS', rl_db_env('PASS', ''));

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Match PHP calendar dates and MySQL NOW()/CURDATE() to Vietnam time.
        $pdo->exec("SET time_zone = '+07:00'");
    }

    return $pdo;
}
