<?php
require __DIR__ . '/../../www/config/database.php';
echo json_encode(db()->query('SELECT id, name FROM roles ORDER BY id')->fetchAll(), JSON_PRETTY_PRINT), PHP_EOL;
echo json_encode(db()->query('SELECT role_id, is_customer, COUNT(*) AS total FROM users GROUP BY role_id, is_customer')->fetchAll(), JSON_PRETTY_PRINT), PHP_EOL;
