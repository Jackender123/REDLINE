<?php
/** Run from the CLI while application requests are stopped. Preview is the default. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/20261110_unify_users.php';

/** Reserve role 4 for customers and role 5 for cashiers without changing user IDs. */
function rl_migrate_customer_role(PDO $pdo, ?string $backupPath = null, bool $apply = false): array
{
    $roles = [];
    $cashierDescription = null;
    foreach ($pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $role) {
        $id = (int) $role['id'];
        $name = strtolower(trim((string) $role['name']));
        if (in_array($name, ['customer', 'customers'], true) && $id !== 4) {
            throw new RuntimeException('Conflicting customer role at ID ' . $id . '; resolve it before migration.');
        }
        if ($name === 'cashier' && !in_array($id, [4, 5], true)) {
            throw new RuntimeException('Conflicting Cashier role at ID ' . $id . '; resolve it before migration.');
        }
        if ($id === 4 && !in_array($name, ['customer', 'customers', 'cashier'], true)) {
            throw new RuntimeException('Conflicting role at ID 4; expected Customers or the former Cashier role.');
        }
        if ($id === 5 && $name !== 'cashier') {
            throw new RuntimeException('Conflicting role at ID 5; it must be available for Cashier.');
        }
        if ($name === 'cashier') $cashierDescription = $role['description'] ?? null;
        $roles[$id] = $role;
    }
    $columns = [];
    foreach (['roles', 'users'] as $table) {
        $columns[$table] = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    }
    if (array_diff(['role_id', 'is_customer'], array_column($columns['users'], 'Field'))) {
        throw new RuntimeException('Run the unified users migration before assigning customer roles.');
    }
    $oldCashier = isset($roles[4]) && strtolower(trim((string) $roles[4]['name'])) === 'cashier';
    $nullCustomers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role_id IS NULL AND is_customer = 1')->fetchColumn();
    $cashiersToMove = $oldCashier ? (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 4')->fetchColumn() : 0;
    $customersToMark = !$oldCashier ? (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 4 AND is_customer <> 1')->fetchColumn() : 0;
    $report = [
        'customer_role_id' => 4,
        'cashier_role_id' => 5,
        'create_customer_role' => !isset($roles[4]),
        'rename_customer_role' => isset($roles[4]) && $roles[4]['name'] !== 'Customers',
        'create_cashier_role' => !isset($roles[5]),
        'rename_cashier_role' => isset($roles[5]) && $roles[5]['name'] !== 'Cashier',
        'cashiers_to_move' => $cashiersToMove,
        'customers_to_assign' => $nullCustomers,
        'customers_to_mark' => $customersToMark,
    ];
    $changed = $report['create_customer_role'] || $report['rename_customer_role']
        || $report['create_cashier_role'] || $report['rename_cashier_role']
        || $cashiersToMove || $nullCustomers || $customersToMark;
    if (!$changed) return $report + ['already_migrated' => true];
    if (!$apply) return $report + ['preview' => true];
    if (!$backupPath) throw new RuntimeException('A full SQL backup path is required before applying role changes.');
    if ($pdo->inTransaction()) throw new RuntimeException('Run the role migration outside an existing transaction.');
    rl_users_backup($pdo, $backupPath);

    // Explicit assignments keep ON UPDATE timestamps unchanged during identity corrections.
    $preserve = [];
    foreach ($columns as $table => $definitions) {
        $preserve[$table] = '';
        foreach ($definitions as $definition) {
            if (stripos((string) $definition['Extra'], 'on update') !== false) {
                $column = '`' . str_replace('`', '``', $definition['Field']) . '`';
                $preserve[$table] .= ", $column = $column";
            }
        }
    }
    $hasDescription = in_array('description', array_column($columns['roles'], 'Field'), true);
    $pdo->beginTransaction();
    try {
        // Free the unique Cashier name; the transaction keeps the role and user move atomic.
        if ($oldCashier) {
            $pdo->exec("UPDATE roles SET name = 'Customers'{$preserve['roles']} WHERE id = 4");
        }
        if (!isset($roles[5])) {
            $sql = $hasDescription ? 'INSERT INTO roles (id, name, description) VALUES (5, ?, ?)' : 'INSERT INTO roles (id, name) VALUES (5, ?)';
            $pdo->prepare($sql)->execute($hasDescription ? ['Cashier', $cashierDescription] : ['Cashier']);
        } elseif ($report['rename_cashier_role']) {
            $pdo->exec("UPDATE roles SET name = 'Cashier'{$preserve['roles']} WHERE id = 5");
        }
        if ($cashiersToMove) {
            $pdo->exec("UPDATE users SET role_id = 5{$preserve['users']} WHERE role_id = 4");
        }
        if (!isset($roles[4])) {
            $pdo->exec("INSERT INTO roles (id, name) VALUES (4, 'Customers')");
        } elseif (!$oldCashier && $report['rename_customer_role']) {
            $pdo->exec("UPDATE roles SET name = 'Customers'{$preserve['roles']} WHERE id = 4");
        }
        $pdo->exec("UPDATE users SET role_id = 4{$preserve['users']} WHERE role_id IS NULL AND is_customer = 1");
        $pdo->exec("UPDATE users SET is_customer = 1{$preserve['users']} WHERE role_id = 4 AND is_customer <> 1");
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return $report + ['applied' => true, 'backup' => $backupPath];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once dirname(__DIR__, 2) . '/src/config/db.php';
    $options = getopt('', ['apply', 'database:']);
    $database = $options['database'] ?? DB_NAME;
    if (!is_string($database) || !preg_match('/^[a-zA-Z0-9_]+$/', $database)) throw new RuntimeException('Invalid database name.');
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $database), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $backup = dirname(__DIR__, 3) . '/.local/backups/' . $database . '-customer-role-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
    echo json_encode(rl_migrate_customer_role($pdo, $backup, array_key_exists('apply', $options)), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
