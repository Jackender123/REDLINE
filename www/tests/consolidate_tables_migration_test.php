<?php
/** Consolidation checks write only to a unique, isolated database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/database/migrations/20261116_consolidate_tables.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$database = 'redline_consolidation_test_' . bin2hex(random_bytes(5));
$backups = [];
$checks = 0;

function consolidation_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}

function consolidation_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        // MySQL spells out this redundant charset when restoring some column definitions.
        $definition = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        $snapshot[$table] = [
            'definition' => str_replace(' CHARACTER SET utf8mb4 COLLATE utf8mb4_', ' COLLATE utf8mb4_', $definition),
            'rows' => $pdo->query("SELECT * FROM `$table` ORDER BY 1")->fetchAll(),
        ];
    }
    return $snapshot;
}

function consolidation_backup_path(string $suffix): string
{
    global $database, $backups;
    $path = sys_get_temp_dir() . '/' . $database . '-' . $suffix . '.sql';
    $backups[] = $path;
    return $path;
}

function consolidation_restore(PDO $pdo, string $backup): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `$table`");
    }
    $pdo->exec(file_get_contents($backup));
}

function consolidation_cli(string $scriptName, string $database, bool $apply = false): array
{
    global $backups;
    $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
        '-d', 'extension=pdo_mysql', dirname(__DIR__) . '/database/migrations/' . $scriptName,
        '--database=' . $database];
    if ($apply) $command[] = '--apply';
    $environment = array_replace(getenv(), [
        'REDLINE_DB_HOST' => '127.0.0.1', 'REDLINE_DB_PORT' => '3306',
        'REDLINE_DB_USER' => 'root', 'REDLINE_DB_PASS' => '',
        'REDLINE_DB_NAME' => 'redline_unused_default_' . bin2hex(random_bytes(4)),
    ]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start the migration CLI fixture.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) throw new RuntimeException('Migration CLI failed: ' . $error . $output);
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (isset($report['backup'])) $backups[] = $report['backup'];
    return $report;
}

function consolidation_expect_blocked(PDO $pdo, string $message, string $backup): void
{
    $before = consolidation_snapshot($pdo);
    foreach ([false, true] as $apply) {
        $error = null;
        try {
            rl_consolidate_tables($pdo, $backup, $apply);
        } catch (RuntimeException $exception) {
            $error = $exception;
        }
        consolidation_check($error !== null && str_contains($error->getMessage(), $message),
            'Preflight identifies ' . $message . ' in preview and apply.');
        consolidation_check(consolidation_snapshot($pdo) === $before && !is_file($backup),
            'Blocked consolidation preserves every table and row before creating a backup.');
    }
}

try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $schema = preg_replace('/CREATE DATABASE IF NOT EXISTS `redlinev1`.*?;/s', '', $schema);
    $schema = str_replace('USE `redlinev1`;', "USE `$database`;", $schema);
    $pdo->exec($schema);

    $fresh = consolidation_snapshot($pdo);
    $freshBackup = consolidation_backup_path('fresh');
    consolidation_check(rl_consolidate_tables($pdo, $freshBackup, true)['already_migrated'],
        'A fresh schema already satisfies consolidation.');
    consolidation_check(consolidation_snapshot($pdo) === $fresh && !is_file($freshBackup),
        'A fresh installation needs no changes or backup.');
    foreach (['20261110_unify_users.php', '20261112_customer_role.php', '20261116_consolidate_tables.php'] as $scriptName) {
        consolidation_check(consolidation_cli($scriptName, $database)['already_migrated'],
            'The ' . $scriptName . ' CLI selects the explicit fixture database and returns valid JSON.');
    }
    consolidation_check(consolidation_snapshot($pdo) === $fresh,
        'All CLI previews preserve a fresh installation.');

    // Reconstruct the legacy catalog and empty retired tables from an upgraded schema.
    $pdo->exec(<<<'SQL'
ALTER TABLE parts
    DROP FOREIGN KEY fk_parts_catalog_category,
    DROP FOREIGN KEY fk_parts_catalog_brand,
    DROP COLUMN catalog_product_id, DROP COLUMN catalog_name,
    DROP COLUMN catalog_price, DROP COLUMN catalog_stock,
    DROP COLUMN catalog_slug, DROP COLUMN catalog_category_id,
    DROP COLUMN catalog_brand_id, DROP COLUMN part_number,
    DROP COLUMN sale_price, DROP COLUMN currency, DROP COLUMN rating,
    DROP COLUMN review_count, DROP COLUMN sold_count, DROP COLUMN warranty_months,
    DROP COLUMN origin, DROP COLUMN item_condition, DROP COLUMN is_universal,
    DROP COLUMN is_featured, DROP COLUMN weight_kg, DROP COLUMN description,
    DROP COLUMN specs, DROP COLUMN images, DROP COLUMN catalog_created_at,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
INSERT INTO part_categories (id, name) VALUES (1, 'Workshop category');
INSERT INTO catalog_categories (id, name, slug) VALUES (10, 'Catalog category', 'catalog-category');
INSERT INTO catalog_brands (id, name, slug) VALUES (20, 'Catalog brand', 'catalog-brand');
INSERT INTO parts (id, category_id, part_code, name, import_price, selling_price, stock_quantity, updated_at)
    VALUES (100, 1, 'FIXTURE-SKU', 'Workshop display name', 120, 200, 7, '2020-01-01');
CREATE TABLE catalog_products (
    id INT PRIMARY KEY, sku VARCHAR(255) NOT NULL UNIQUE, name VARCHAR(255),
    price INT, stock INT, slug VARCHAR(255), category_id INT, brand_id INT,
    part_number VARCHAR(255), sale_price INT, currency VARCHAR(255),
    rating DECIMAL(10,2), review_count INT, sold_count INT, warranty_months INT,
    origin VARCHAR(255), `condition` VARCHAR(255), is_universal TINYINT(1),
    is_featured TINYINT(1), weight_kg DECIMAL(10,2), description TEXT,
    specs JSON, images JSON, created_at DATE,
    FOREIGN KEY (category_id) REFERENCES catalog_categories(id),
    FOREIGN KEY (brand_id) REFERENCES catalog_brands(id)
) ENGINE=InnoDB;
INSERT INTO catalog_products VALUES (
    9, 'FIXTURE-SKU', 'Catalog display name', 999, 50, 'fixture-product', 10, 20,
    'PART-9', NULL, 'VND', 4.75, 12, 34, 24, 'Vietnam', 'new', 0, 1, 1.25,
    'Keep catalog description', '{"material":"alloy"}', '["catalog.jpg"]', '2021-02-03'
);
CREATE TABLE quotes (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE quote_items (id INT PRIMARY KEY, quote_id INT,
    FOREIGN KEY (quote_id) REFERENCES quotes(id)) ENGINE=InnoDB;
CREATE TABLE service_orders (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE order_parts (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE order_services (id INT PRIMARY KEY) ENGINE=InnoDB;
SQL);

    $before = consolidation_snapshot($pdo);
    $workshop = $pdo->query('SELECT * FROM parts WHERE id=100')->fetch();
    $catalog = $pdo->query('SELECT * FROM catalog_products WHERE id=9')->fetch();
    $backup = consolidation_backup_path('legacy');
    $preview = rl_consolidate_tables($pdo, $backup);
    consolidation_check($preview['preview'] && $preview['database'] === $database
        && $preview['catalog_products'] === 1 && count($preview['retired_tables']) === 5,
        'Preview reports the selected database, catalog count and retired tables.');
    consolidation_check(consolidation_snapshot($pdo) === $before && !is_file($backup),
        'Preview performs no schema changes, row updates or backup writes.');

    $missingBackup = null;
    try {
        rl_consolidate_tables($pdo, null, true);
    } catch (RuntimeException $exception) {
        $missingBackup = $exception;
    }
    consolidation_check($missingBackup !== null && consolidation_snapshot($pdo) === $before,
        'Applying without a backup destination stops before changing schema or rows.');

    $report = rl_consolidate_tables($pdo, $backup, true);
    consolidation_check($report['applied'] && $report['backup'] === $backup
        && is_file($backup) && filesize($backup) > 1000,
        'Apply creates a full SQL backup before consolidating.');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    consolidation_check(!array_intersect(['catalog_products', 'quotes', 'quote_items',
        'service_orders', 'order_parts', 'order_services'], $tables),
        'Consolidation removes the copied catalog and empty legacy tables.');
    $part = $pdo->query('SELECT * FROM parts WHERE id=100')->fetch();
    consolidation_check(array_intersect_key($part, $workshop) === $workshop,
        'Workshop identity, prices, stock, name and modification date survive catalog consolidation.');
    $mapped = [
        'id' => 'catalog_product_id', 'sku' => 'part_code', 'name' => 'catalog_name',
        'price' => 'catalog_price', 'stock' => 'catalog_stock', 'slug' => 'catalog_slug',
        'category_id' => 'catalog_category_id', 'brand_id' => 'catalog_brand_id',
        'condition' => 'item_condition', 'created_at' => 'catalog_created_at',
    ];
    foreach ($catalog as $source => $value) {
        $target = $mapped[$source] ?? $source;
        consolidation_check($part[$target] === $value, 'Catalog field ' . $source . ' is preserved.');
    }
    $after = consolidation_snapshot($pdo);
    $rerunBackup = consolidation_backup_path('rerun');
    consolidation_check(rl_consolidate_tables($pdo, $rerunBackup, true)['already_migrated']
        && consolidation_snapshot($pdo) === $after && !is_file($rerunBackup),
        'Rerunning a completed consolidation preserves schema and rows without creating another backup.');

    consolidation_restore($pdo, $backup);
    consolidation_check(consolidation_snapshot($pdo) === $before,
        'The backup restores all legacy tables, catalog data and workshop records.');

    $pdo->exec("UPDATE catalog_products SET sku='MISSING-SKU' WHERE id=9");
    consolidation_expect_blocked($pdo, 'unmatched', consolidation_backup_path('unmatched'));
    $pdo->exec("UPDATE catalog_products SET sku='FIXTURE-SKU' WHERE id=9");
    $pdo->exec('INSERT INTO quotes VALUES (1)');
    consolidation_expect_blocked($pdo, 'quotes', consolidation_backup_path('nonempty'));
    $pdo->exec('DELETE FROM quotes');
    $pdo->exec('CREATE TABLE retained_links (id INT PRIMARY KEY, quote_id INT,
        FOREIGN KEY (quote_id) REFERENCES quotes(id)) ENGINE=InnoDB');
    consolidation_expect_blocked($pdo, 'foreign key', consolidation_backup_path('reference'));
    $pdo->exec('DROP TABLE retained_links');
    $pdo->exec('ALTER TABLE parts ADD COLUMN catalog_product_id INT NULL');
    $pdo->exec('UPDATE parts SET catalog_product_id=999, updated_at=updated_at WHERE id=100');
    consolidation_expect_blocked($pdo, 'catalog_product_id', consolidation_backup_path('conflict'));

    // Resume from a partially added metadata schema with the original catalog intact.
    $pdo->exec('UPDATE parts SET catalog_product_id=NULL, updated_at=updated_at WHERE id=100');
    $pdo->exec('ALTER TABLE parts ADD COLUMN catalog_name VARCHAR(255) NULL');
    $partialBackup = consolidation_backup_path('partial');
    rl_consolidate_tables($pdo, $partialBackup, true);
    consolidation_check(consolidation_snapshot($pdo) === $after,
        'A partial metadata upgrade completes with the same schema and saved data.');

    consolidation_restore($pdo, $backup);
    $cliPreview = consolidation_cli('20261116_consolidate_tables.php', $database);
    consolidation_check($cliPreview['preview'] && $cliPreview['database'] === $database
        && consolidation_snapshot($pdo) === $before,
        'The consolidation CLI previews a legacy fixture without modifying saved data.');
    $cliApply = consolidation_cli('20261116_consolidate_tables.php', $database, true);
    consolidation_check($cliApply['applied'] && is_file($cliApply['backup'])
        && consolidation_snapshot($pdo) === $after,
        'The consolidation CLI applies the selected fixture and creates its automatic backup.');
    consolidation_restore($pdo, $cliApply['backup']);
    consolidation_check(consolidation_snapshot($pdo) === $before,
        'The CLI backup restores the complete legacy fixture.');

    echo "PASS: $checks table consolidation checks\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($backups as $path) {
        if (is_file($path)) unlink($path);
    }
}
