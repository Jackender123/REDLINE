<?php
/** Run from the CLI while application requests are stopped. Preview is the default. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../migration_helpers.php';

function rl_consolidation_table_exists(PDO $pdo, string $name): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $statement->execute([$name]);
    return (bool) $statement->fetchColumn();
}

function rl_consolidation_table_count(PDO $pdo, string $name): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . $name . '`')->fetchColumn();
}

function rl_consolidation_columns(PDO $pdo, string $table): array
{
    return $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
}

/** Copy catalog metadata into workshop parts before retiring the legacy tables. */
function rl_consolidate_tables(PDO $pdo, ?string $backupPath = null, bool $apply = false): array
{
    $dropCandidates = ['quote_items', 'quotes', 'service_orders', 'order_parts', 'order_services'];
    $allRetired = array_merge($dropCandidates, ['catalog_products']);

    // Preserve catalog display values independently from workshop stock and prices.
    $fields = [
        'catalog_product_id' => ['id', 'INT NULL'],
        'catalog_name' => ['name', 'VARCHAR(255) NULL'],
        'catalog_price' => ['price', 'INT NULL'],
        'catalog_stock' => ['stock', 'INT NULL'],
        'catalog_slug' => ['slug', 'VARCHAR(255) NULL'],
        'catalog_category_id' => ['category_id', 'INT NULL'],
        'catalog_brand_id' => ['brand_id', 'INT NULL'],
        'part_number' => ['part_number', 'VARCHAR(255) NULL'],
        'sale_price' => ['sale_price', 'INT NULL'],
        'currency' => ['currency', 'VARCHAR(255) NULL'],
        'rating' => ['rating', 'DECIMAL(10,2) NULL'],
        'review_count' => ['review_count', 'INT NULL'],
        'sold_count' => ['sold_count', 'INT NULL'],
        'warranty_months' => ['warranty_months', 'INT NULL'],
        'origin' => ['origin', 'VARCHAR(255) NULL'],
        'item_condition' => ['condition', 'VARCHAR(255) NULL'],
        'is_universal' => ['is_universal', 'TINYINT(1) NULL'],
        'is_featured' => ['is_featured', 'TINYINT(1) NULL'],
        'weight_kg' => ['weight_kg', 'DECIMAL(10,2) NULL'],
        'description' => ['description', 'TEXT NULL'],
        'specs' => ['specs', 'JSON NULL'],
        'images' => ['images', 'JSON NULL'],
        'catalog_created_at' => ['created_at', 'DATE NULL'],
    ];

    if (!rl_consolidation_table_exists($pdo, 'parts')) {
        throw new RuntimeException('Missing parts table. Import the original schema first.');
    }
    $columns = rl_consolidation_columns($pdo, 'parts');
    $hasCatalog = rl_consolidation_table_exists($pdo, 'catalog_products');
    $report = [
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'retired_tables' => [],
        'catalog_products' => 0,
    ];
    $blocked = [];
    foreach ($dropCandidates as $name) {
        if (!rl_consolidation_table_exists($pdo, $name)) {
            continue;
        }
        $rows = rl_consolidation_table_count($pdo, $name);
        $report['retired_tables'][$name] = $rows;
        if ($rows > 0) {
            $blocked[] = "{$name} ({$rows} rows)";
        }
    }

    if (!$hasCatalog) {
        $missing = array_diff(array_keys($fields), $columns);
        if ($missing) {
            $blocked[] = 'catalog_products missing, but parts lacks fields: ' . implode(', ', $missing);
        }
    } else {
        $report['catalog_products'] = rl_consolidation_table_count($pdo, 'catalog_products');
        $unmatched = (int) $pdo->query('SELECT COUNT(*) FROM catalog_products cp LEFT JOIN parts p ON p.part_code = cp.sku WHERE p.id IS NULL')->fetchColumn();
        if ($unmatched > 0) {
            $blocked[] = "catalog_products ({$unmatched} unmatched SKUs)";
        }
        if (!rl_consolidation_table_exists($pdo, 'catalog_categories') || !rl_consolidation_table_exists($pdo, 'catalog_brands')) {
            $blocked[] = 'catalog lookup tables are missing';
        }
        if (in_array('catalog_product_id', $columns, true)) {
            $conflicts = (int) $pdo->query('SELECT COUNT(*) FROM parts p JOIN catalog_products cp ON cp.sku = p.part_code WHERE p.catalog_product_id IS NOT NULL AND p.catalog_product_id <> cp.id')->fetchColumn();
            if ($conflicts > 0) {
                $blocked[] = 'conflicting catalog_product_id links';
            }
        }
    }

    // Check incoming references before changing any data or schema.
    $list = implode(',', array_fill(0, count($allRetired), '?'));
    $statement = $pdo->prepare("SELECT TABLE_NAME, REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME IN ($list)");
    $statement->execute($allRetired);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $reference) {
        if (!in_array($reference['TABLE_NAME'], $allRetired, true)) {
            $blocked[] = 'external foreign key: ' . $reference['TABLE_NAME'] . ' -> ' . $reference['REFERENCED_TABLE_NAME'];
        }
    }
    if ($blocked) {
        throw new RuntimeException('Preflight failed. Resolve these records or references before applying: ' . implode(', ', $blocked));
    }
    if (!$hasCatalog && !$report['retired_tables']) {
        return $report + ['already_migrated' => true];
    }
    if (!$apply) {
        return $report + ['preview' => true];
    }
    if (!$backupPath) {
        throw new RuntimeException('A full SQL backup path is required before consolidating tables.');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Run the table consolidation outside an existing transaction.');
    }
    rl_users_backup($pdo, $backupPath);

    if ($hasCatalog) {
        $add = [];
        foreach ($fields as $target => [, $type]) {
            if (!in_array($target, $columns, true)) {
                $add[] = "ADD COLUMN `{$target}` {$type}";
            }
        }
        if ($add) {
            $pdo->exec('ALTER TABLE `parts` ' . implode(', ', $add));
        }

        $assignments = [];
        $verification = [];
        foreach ($fields as $target => [$source]) {
            $assignments[] = "p.`{$target}` = cp.`{$source}`";
            $verification[] = "p.`{$target}` <=> cp.`{$source}`";
        }
        // Preserve modification dates when an installation has added audit columns.
        foreach ($pdo->query('SHOW COLUMNS FROM parts')->fetchAll(PDO::FETCH_ASSOC) as $definition) {
            if (stripos((string) $definition['Extra'], 'on update') !== false) {
                $column = '`' . str_replace('`', '``', $definition['Field']) . '`';
                $assignments[] = "p.$column = p.$column";
            }
        }
        $pdo->exec('UPDATE parts p JOIN catalog_products cp ON cp.sku = p.part_code SET ' . implode(', ', $assignments));
        $bad = (int) $pdo->query('SELECT COUNT(*) FROM catalog_products cp JOIN parts p ON p.part_code = cp.sku WHERE NOT (' . implode(' AND ', $verification) . ')')->fetchColumn();
        if ($bad > 0) {
            throw new RuntimeException("{$bad} catalog rows did not verify after copying. The source catalog is available for recovery.");
        }

        $indexes = $pdo->query('SHOW INDEX FROM parts')->fetchAll(PDO::FETCH_ASSOC);
        $haveIndex = array_column($indexes, 'Key_name');
        foreach ([
            'uq_parts_catalog_product' => 'ADD UNIQUE KEY `uq_parts_catalog_product` (`catalog_product_id`)',
            'idx_parts_catalog_category' => 'ADD KEY `idx_parts_catalog_category` (`catalog_category_id`)',
            'idx_parts_catalog_brand' => 'ADD KEY `idx_parts_catalog_brand` (`catalog_brand_id`)',
        ] as $name => $definition) {
            if (!in_array($name, $haveIndex, true)) {
                $pdo->exec('ALTER TABLE parts ' . $definition);
            }
        }
        $foreignKeys = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'parts'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fk_parts_catalog_category', $foreignKeys, true)) {
            $pdo->exec('ALTER TABLE parts ADD CONSTRAINT `fk_parts_catalog_category` FOREIGN KEY (`catalog_category_id`) REFERENCES `catalog_categories` (`id`) ON DELETE SET NULL');
        }
        if (!in_array('fk_parts_catalog_brand', $foreignKeys, true)) {
            $pdo->exec('ALTER TABLE parts ADD CONSTRAINT `fk_parts_catalog_brand` FOREIGN KEY (`catalog_brand_id`) REFERENCES `catalog_brands` (`id`) ON DELETE SET NULL');
        }
        $pdo->exec('DROP TABLE `catalog_products`');
    }

    foreach ($dropCandidates as $name) {
        if (!isset($report['retired_tables'][$name])) {
            continue;
        }
        if (rl_consolidation_table_count($pdo, $name) !== 0) {
            throw new RuntimeException('Table ' . $name . ' became nonempty. Stopped before DROP.');
        }
        $pdo->exec('DROP TABLE `' . $name . '`');
    }
    return $report + ['applied' => true, 'backup' => $backupPath];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = getopt('', ['apply', 'database:']);
        $pdo = rl_migration_connection($options['database'] ?? null);
        $backup = rl_migration_backup_path($pdo, 'consolidate-tables');
        echo json_encode(rl_consolidate_tables($pdo, $backup, array_key_exists('apply', $options)), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, 'STOP: ' . $error->getMessage() . PHP_EOL);
        fwrite(STDERR, 'MySQL DDL is not transactional. Retain the backup and rerun preview after resolving the error.' . PHP_EOL);
        exit(1);
    }
}
