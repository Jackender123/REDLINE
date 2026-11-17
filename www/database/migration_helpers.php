<?php
/** Shared CLI migration connections and full SQL backups. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function rl_migration_connection(?string $database = null): PDO
{
    require_once dirname(__DIR__) . '/config/database.php';
    $database ??= DB_NAME;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
        throw new RuntimeException('Invalid database name.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $database);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function rl_migration_backup_path(PDO $pdo, string $label): string
{
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
        throw new RuntimeException('Invalid database name.');
    }

    return __DIR__ . '/../../tools/local/backups/' . $database . '-' . $label
        . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
}

function rl_users_backup(PDO $pdo, string $path): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the database backup directory.');
    }
    if (file_exists($path)) {
        throw new RuntimeException('The database backup already exists.');
    }
    $file = @fopen($path, 'xb');
    if (!$file) {
        throw new RuntimeException('Cannot create a new database backup.');
    }
    $write = static function (string $sql) use ($file): void {
        if (fwrite($file, $sql) !== strlen($sql)) {
            throw new RuntimeException('Database backup write failed.');
        }
    };
    try {
        $write("-- REDLINE backup before database migration\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $name = '`' . str_replace('`', '``', $table) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $name")->fetch(PDO::FETCH_NUM)[1];
            $write("DROP TABLE IF EXISTS $name;\n$create;\n");
            $rows = $pdo->query("SELECT * FROM $name");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = implode(',', array_map(static fn($column) => '`' . str_replace('`', '``', $column) . '`', array_keys($row)));
                $values = implode(',', array_map(static fn($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row)));
                $write("INSERT INTO $name ($columns) VALUES ($values);\n");
            }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($file);
    }
}
