<?php
require dirname(__DIR__, 2) . '/www/database/migrations/20261110_unify_users.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database = 'redline_seed_test_' . bin2hex(random_bytes(5));
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $sql = file_get_contents(__DIR__ . '/legacy_seed.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `redlinev1`.*?;/s', '', $sql);
    $sql = str_replace('USE `redlinev1`;', "USE `$database`;", $sql);
    $pdo->exec($sql);
    $report = rl_unify_users($pdo, __DIR__ . '/seed-backup-' . bin2hex(random_bytes(4)) . '.sql', true);
    $path = dirname(__DIR__, 2) . '/www/database/schema.sql';
    $sql = file_get_contents($path);
    foreach (['users', 'vehicles', 'repair_orders', 'portal_vehicles'] as $table) {
        $insert = '';
        foreach ($pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns = implode('`,`', array_keys($row));
            $values = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
            $insert .= "INSERT INTO `$table` (`$columns`) VALUES ($values);\n";
        }
        $sql = preg_replace('/INSERT INTO `' . $table . '` (?:VALUES|\().*?;\R/s', '', $sql);
        $sql = preg_replace_callback('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=.*?;\R/s', fn($m) => $m[0] . "\n" . $insert, $sql);
    }
    file_put_contents($path, $sql);
    echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
