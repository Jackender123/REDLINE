<?php
$base = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$pdo = new PDO($base, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database = 'redline_verify_test_' . bin2hex(random_bytes(5));
$backup = __DIR__ . '/backups/redlinev1-20261110-151548-9a89ec.sql';
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $pdo->exec(file_get_contents($backup));
    $maps = json_decode(file_get_contents($backup . '.ids.json'), true)['id_maps'];
    // Recover the two vehicle dates changed by the first migration before the date-preservation fix.
    $pdo->exec("UPDATE redlinev1.vehicles live JOIN `$database`.vehicles original ON original.id = live.id
        SET live.updated_at = original.updated_at
        WHERE live.updated_at = '2026-11-10 22:15:48' AND live.customer_id = 7");
    $pdo->exec('USE redlinev1');
    $references = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users'")->fetchAll();
    foreach ($references as $ref) {
        $orphans = $pdo->query("SELECT COUNT(*) FROM `{$ref['TABLE_NAME']}` r LEFT JOIN users u ON r.`{$ref['COLUMN_NAME']}`=u.id WHERE r.`{$ref['COLUMN_NAME']}` IS NOT NULL AND u.id IS NULL")->fetchColumn();
        if ($orphans) throw new RuntimeException('Orphaned identity reference: ' . $ref['TABLE_NAME']);
    }
    foreach ($pdo->query("SELECT id,username,password FROM `$database`.users")->fetchAll() as $old) {
        $new = $pdo->query('SELECT username,password_hash FROM users WHERE id=' . (int) $old['id'])->fetch();
        if (!$new || $old['password'] !== $new['password_hash'] || $old['username'] !== $new['username']) throw new RuntimeException('Staff credentials changed.');
    }
    foreach (['vehicles','appointments','repair_orders','service_requests','portal_vehicles'] as $table) {
        $before = $pdo->query("SELECT COUNT(*) FROM `$database`.`$table`")->fetchColumn();
        $after = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($before !== $after) throw new RuntimeException('Record count changed for ' . $table);
    }
    echo 'PASS: Live database has intact staff credentials, unchanged operational record counts, and ' . count($references) . " valid user foreign keys.\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
