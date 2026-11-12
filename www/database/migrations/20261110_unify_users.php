<?php
/** Run from the CLI while the application is stopped. Keeps a full SQL backup. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function rl_users_backup(PDO $pdo, string $path): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
        throw new RuntimeException('Cannot create the database backup directory.');
    }
    if (file_exists($path)) throw new RuntimeException('The database backup already exists.');
    $file = fopen($path, 'wb');
    if (!$file) throw new RuntimeException('Cannot create a new database backup.');
    $write = static function (string $sql) use ($file): void {
        if (fwrite($file, $sql) !== strlen($sql)) throw new RuntimeException('Database backup write failed.');
    };
    try {
        $write("-- REDLINE backup before consolidating users\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $name = '`' . str_replace('`', '``', $table) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $name")->fetch(PDO::FETCH_NUM)[1];
            $write("DROP TABLE IF EXISTS $name;\n$create;\n");
            $rows = $pdo->query("SELECT * FROM $name");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = implode(',', array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row)));
                $values = implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
                $write("INSERT INTO $name ($columns) VALUES ($values);\n");
            }
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($file);
    }
}

/** Staff IDs stay fixed. Portal/customer IDs are remapped, never assumed equivalent. */
function rl_unify_users(PDO $pdo, ?string $backupPath = null, bool $apply = false): array
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('customers', $tables, true) && !in_array('portal_users', $tables, true)) {
        $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_customer', $columns, true)) throw new RuntimeException('Unrecognized users schema.');
        return ['already_migrated' => true];
    }
    foreach (['users', 'customers', 'portal_users'] as $table) {
        if (!in_array($table, $tables, true)) throw new RuntimeException('Incomplete legacy schema: ' . $table);
    }
    foreach (['users_unified', 'users_before_unify'] as $table) {
        if (in_array($table, $tables, true)) throw new RuntimeException('A previous migration needs recovery: ' . $table);
    }
    $users = [];
    $maps = ['users' => [], 'portal_users' => [], 'customers' => []];
    $nextId = 1;
    $defaults = ['role_id' => null, 'username' => null, 'password_hash' => null,
        'full_name' => '', 'email' => null, 'phone' => null, 'address' => null, 'notes' => null,
        'is_customer' => 1, 'is_active' => 1, 'last_login_at' => null,
        'created_at' => null, 'updated_at' => null, 'zalo' => null, 'contact_method' => 'phone',
        'notify_repair' => 1, 'notify_reminder' => 1, 'notify_offers' => 0, 'password_changed_at' => null];
    $emailKey = static fn($value) => mb_strtolower(trim((string) $value), 'UTF-8');
    $phoneKey = static fn($value) => preg_replace('/\D/', '', (string) $value);
    foreach ($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $users[$id] = array_replace($defaults, array_intersect_key($row, $defaults), [
            'id' => $id, 'password_hash' => $row['password'], 'is_customer' => 0,
            'email' => trim((string) $row['email']) ?: null,
        ]);
        $maps['users'][$id] = $id;
        $nextId = max($nextId, $id + 1);
    }
    foreach (['portal_users', 'customers'] as $source) {
        foreach ($pdo->query("SELECT * FROM `$source` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $matches = [];
            $email = $emailKey($row['email']);
            $phone = $phoneKey($row['phone']);
            if ($source === 'customers' && !empty($row['portal_user_id'])) {
                $linked = $maps['portal_users'][(int) $row['portal_user_id']] ?? null;
                if (!$linked) throw new RuntimeException('Customer links to a missing portal account.');
                $matches[$linked] = true;
            } else {
                foreach ($users as $id => $user) {
                    if (($email !== '' && $email === $emailKey($user['email']))
                        || ($source === 'customers' && $user['is_customer'] && $phone !== '' && $phone === $phoneKey($user['phone']))) {
                        $matches[$id] = true;
                    }
                }
            }
            if (count($matches) > 1) throw new RuntimeException("Ambiguous $source identity at ID {$row['id']}; resolve duplicate contact details first.");
            $id = $matches ? (int) array_key_first($matches) : $nextId++;
            if (!isset($users[$id])) {
                $users[$id] = array_replace($defaults, array_intersect_key($row, $defaults), ['id' => $id]);
            } else {
                // Keep the staff login/password and portal profile; enrich missing workshop fields.
                foreach ($defaults as $column => $default) {
                    if (($users[$id][$column] === null || $users[$id][$column] === '') && isset($row[$column])) {
                        $users[$id][$column] = $row[$column];
                    }
                }
                $users[$id]['is_active'] = (int) ($users[$id]['is_active'] && $row['is_active']);
                $users[$id]['created_at'] = min($users[$id]['created_at'], $row['created_at']);
                if ($source === 'portal_users') {
                    foreach (['last_login_at', 'notify_repair', 'notify_reminder', 'notify_offers', 'zalo', 'contact_method'] as $column) {
                        if (array_key_exists($column, $row)) $users[$id][$column] = $row[$column];
                    }
                }
            }
            $users[$id]['is_customer'] = 1;
            $users[$id]['email'] = trim((string) $users[$id]['email']) ?: null;
            $users[$id]['updated_at'] ??= $row['created_at'];
            $maps[$source][(int) $row['id']] = $id;
        }
    }
    // MySQL validates uniqueness in the shadow table before any original data changes.
    $sql = file_get_contents(dirname(__DIR__) . '/redlinev1.sql');
    if (!preg_match('/CREATE TABLE(?: IF NOT EXISTS)? `users` \(.*?\) ENGINE=.*?;/s', $sql, $match)) {
        throw new RuntimeException('Unified users definition not found.');
    }
    $create = preg_replace('/CREATE TABLE(?: IF NOT EXISTS)? `users`/', 'CREATE TABLE `users_unified`', $match[0]);
    $create = str_replace('`fk_users_role`', '`fk_users_unified_role`', $create);
    $fks = $pdo->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IN ('users','customers','portal_users')")->fetchAll(PDO::FETCH_ASSOC);
    $refs = [];
    foreach ($fks as $fk) $refs[$fk['TABLE_NAME'] . '.' . $fk['COLUMN_NAME']] = $fk['REFERENCED_TABLE_NAME'];
    // These legacy columns have no foreign keys, but still carry identity IDs.
    $refs['repair_orders.customer_id'] = 'customers';
    $refs['service_requests.user_id'] = 'portal_users';
    foreach ($refs as $ref => $source) {
        [$table, $column] = explode('.', $ref);
        foreach ($pdo->query("SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!isset($maps[$source][(int) $id])) throw new RuntimeException("Orphaned reference in $ref: $id");
        }
    }
    $report = ['source_counts' => array_map('count', $maps), 'unified_count' => count($users), 'id_maps' => $maps];
    if (!$apply) return $report;
    if (!$backupPath) throw new RuntimeException('A backup path is required.');
    rl_users_backup($pdo, $backupPath);
    if (file_put_contents($backupPath . '.ids.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot save the identity ID mappings.');
    }
    $pdo->exec($create);
    $columns = array_keys(reset($users) ?: array_merge($defaults, ['id' => null]));
    $insert = $pdo->prepare('INSERT INTO users_unified (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    try {
        foreach ($users as $user) $insert->execute(array_map(static fn($c) => $user[$c], $columns));
    } catch (Throwable $ex) {
        $pdo->exec('DROP TABLE users_unified');
        throw $ex;
    }
    foreach ($fks as $fk) $pdo->exec("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
    $pdo->beginTransaction();
    try {
        foreach ($refs as $ref => $source) {
            if ($source === 'users') continue;
            [$table, $column] = explode('.', $ref);
            if (!$maps[$source]) continue;
            $cases = [];
            foreach ($maps[$source] as $old => $new) $cases[] = "WHEN $old THEN $new";
            // ID remapping must not change modification dates or reorder service history.
            $preserve = '';
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $definition) {
                if (stripos($definition['Extra'], 'on update') !== false) {
                    $name = $definition['Field'];
                    $preserve .= ", `$name` = `$name`";
                }
            }
            $pdo->exec("UPDATE `$table` SET `$column` = CASE `$column` " . implode(' ', $cases) . " ELSE `$column` END$preserve");
        }
        // Existing cookies must not restore a session under a different identity after ID remapping.
        $pdo->exec('DELETE FROM remember_tokens');
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    $pdo->exec('RENAME TABLE users TO users_before_unify, users_unified TO users');
    foreach ($fks as $fk) {
        $pdo->exec("ALTER TABLE `{$fk['TABLE_NAME']}` ADD CONSTRAINT `{$fk['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fk['COLUMN_NAME']}`) REFERENCES users (id) ON DELETE {$fk['DELETE_RULE']} ON UPDATE {$fk['UPDATE_RULE']}");
    }
    $pdo->exec('ALTER TABLE repair_orders MODIFY customer_id INT UNSIGNED NULL, ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON UPDATE CASCADE');
    $pdo->exec('ALTER TABLE service_requests ADD CONSTRAINT fk_service_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE');
    $pdo->exec('DROP TABLE customers, portal_users, users_before_unify');
    $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_unified_role, ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON UPDATE CASCADE');
    return $report + ['backup' => $backupPath];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once dirname(__DIR__, 2) . '/src/config/db.php';
    $options = getopt('', ['apply', 'database:']);
    $database = $options['database'] ?? DB_NAME;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) throw new RuntimeException('Invalid database name.');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $database), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $backup = dirname(__DIR__, 3) . '/.local/backups/' . $database . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    echo json_encode(rl_unify_users($pdo, $backup, array_key_exists('apply', $options)), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
