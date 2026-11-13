<?php
/** Inventory query checks write only to a unique isolated database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/inventory.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'redline_inventory_test_' . bin2hex(random_bytes(5));
$checks = 0;
function inventory_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $pdo->exec(<<<'SQL'
CREATE TABLE catalog_categories (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL);
CREATE TABLE catalog_brands (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL);
CREATE TABLE catalog_products (id INT PRIMARY KEY, sku VARCHAR(255) NOT NULL UNIQUE, category_id INT NOT NULL, brand_id INT NOT NULL);
CREATE TABLE part_categories (id INT UNSIGNED PRIMARY KEY, name VARCHAR(100) NOT NULL);
CREATE TABLE parts (id INT UNSIGNED PRIMARY KEY, category_id INT UNSIGNED NOT NULL, part_code VARCHAR(30) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, stock_quantity INT NOT NULL, min_stock_alert INT NOT NULL, is_active TINYINT NOT NULL DEFAULT 1);
INSERT INTO catalog_categories VALUES (10,'Catalog brakes'),(20,'Catalog filters'),(30,'Unused category');
INSERT INTO catalog_brands VALUES (100,'Brembo'),(200,'Bosch'),(300,'Unused brand');
INSERT INTO part_categories VALUES (1,'Workshop brakes'),(2,'Workshop standalone');
SQL);
    $insertPart = $pdo->prepare('INSERT INTO parts (id, category_id, part_code, name, stock_quantity, min_stock_alert, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertProduct = $pdo->prepare('INSERT INTO catalog_products (id, sku, category_id, brand_id) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= 67; ++$i) {
        $code = sprintf('PART-%03d', $i);
        $insertPart->execute([$i, $i <= 60 ? 1 : 2, $code, 'Inventory item ' . $i, $i <= 5 ? 5 : 20, 5, 1]);
        if ($i <= 60) $insertProduct->execute([1000 + $i, $code, $i <= 30 ? 10 : 20, $i <= 30 ? 100 : 200]);
    }
    $insertPart->execute([68, 2, 'SPECIAL-PERCENT', 'Literal 100% performance', 12, 5, 1]);
    $insertPart->execute([69, 2, 'SPECIAL-UNDERSCORE', 'Literal under_score', 12, 5, 1]);
    $insertPart->execute([70, 2, 'SPECIAL-EXCLAIM', 'Literal bang! and slash\\', 12, 5, 1]);
    $insertPart->execute([71, 2, 'INACTIVE', 'Hidden item', 0, 5, 0]);
    $insertProduct->execute([1071, 'INACTIVE', 30, 300]);

    $first = rl_inventory_list($pdo);
    inventory_check($first['total'] === 70 && $first['filtered_total'] === 70 && count($first['parts']) === 25 && $first['pages'] === 3, 'All active inventory is counted while the first response contains 25 rows.');
    inventory_check($first['stock_counts'] === ['all' => 70, 'low' => 5, 'available' => 65], 'Stock summaries count all active parts and include the low-stock alert boundary.');
    inventory_check($first['from'] === 1 && $first['to'] === 25 && $first['page'] === 1, 'First page displays the correct item range.');
    inventory_check(array_slice(array_column($first['parts'], 'id'), 0, 5) === [5, 4, 3, 2, 1], 'Low stock sorts before normal stock with stable descending IDs.');
    $second = rl_inventory_list($pdo, ['page' => '2']);
    $last = rl_inventory_list($pdo, ['page' => '9999']);
    inventory_check($second['from'] === 26 && $second['to'] === 50 && count($second['parts']) === 25, 'Second page is bounded to 25 rows.');
    inventory_check($last['page'] === 3 && count($last['parts']) === 20 && $last['from'] === 51 && $last['to'] === 70, 'Out-of-range pages clamp to the final available page.');
    $allIds = array_merge(array_column($first['parts'], 'id'), array_column($second['parts'], 'id'), array_column($last['parts'], 'id'));
    inventory_check(count(array_unique($allIds)) === 70 && !in_array(71, $allIds, true), 'Pagination returns each active part once and excludes inactive parts.');
    inventory_check(array_column($first['categories'], 'value') === ['catalog:10', 'catalog:20', 'part:2'], 'Category choices use catalog IDs and expose workshop categories only for uncatalogued parts.');
    inventory_check(array_column($first['brands'], 'id') === [200, 100], 'Brand choices come only from active inventory and sort by name.');
    inventory_check(array_column($first['categories'], 'count') === [30, 30, 10], 'Category counts include active catalog and workshop-only parts exactly once.');
    inventory_check(array_column($first['brands'], 'count') === [30, 30], 'Brand counts include active linked inventory and exclude inactive parts.');

    $brakes = rl_inventory_list($pdo, ['category' => 'catalog:10']);
    inventory_check($brakes['filtered_total'] === 30 && $brakes['parts'][0]['category_name'] === 'Catalog brakes' && $brakes['parts'][0]['brand_name'] === 'Brembo', 'Catalog categories and brands are resolved by SKU rather than matching category IDs.');
    inventory_check(rl_inventory_list($pdo, ['category' => 'part:1'])['filtered_total'] === 0, 'Workshop category filters never include catalog-linked parts.');
    inventory_check(rl_inventory_list($pdo, ['category' => 'part:2'])['filtered_total'] === 10, 'Workshop-only parts remain available through their own category.');
    inventory_check(rl_inventory_list($pdo, ['brand' => '200'])['filtered_total'] === 30, 'A brand filter selects its linked inventory.');
    inventory_check(rl_inventory_list($pdo, ['brand' => 'none'])['filtered_total'] === 10, 'No-brand filtering includes only uncatalogued inventory.');
    inventory_check(rl_inventory_list($pdo, ['stock' => 'low'])['filtered_total'] === 5, 'Low-stock filtering includes the alert boundary.');
    inventory_check(rl_inventory_list($pdo, ['stock' => 'available'])['filtered_total'] === 65, 'Available-stock filtering includes quantities above the alert boundary.');
    inventory_check(rl_inventory_list($pdo, ['q' => 'bReMbO'])['filtered_total'] === 30, 'Search finds catalog brand names with database case-insensitive matching.');
    inventory_check(rl_inventory_list($pdo, ['q' => 'PART-001'])['filtered_total'] === 1, 'Search finds part codes.');
    inventory_check(rl_inventory_list($pdo, ['q' => 'Inventory item 27'])['filtered_total'] === 1, 'Search finds part names.');
    inventory_check(rl_inventory_list($pdo, ['q' => '%'])['filtered_total'] === 1, 'Percent signs are searched literally.');
    inventory_check(rl_inventory_list($pdo, ['q' => '_'])['filtered_total'] === 1, 'Underscores are searched literally.');
    inventory_check(rl_inventory_list($pdo, ['q' => '!'])['filtered_total'] === 1, 'The LIKE escape character is searched literally.');
    inventory_check(rl_inventory_list($pdo, ['q' => '\\'])['filtered_total'] === 1, 'Backslashes are searched literally.');
    $empty = rl_inventory_list($pdo, ['q' => "' OR 1=1 --", 'page' => '4']);
    inventory_check($empty['filtered_total'] === 0 && $empty['parts'] === [] && $empty['page'] === 1 && $empty['from'] === 0 && $empty['to'] === 0, 'Quoted input remains literal and empty results have a coherent page and range.');
    $combined = rl_inventory_list($pdo, ['q' => 'item', 'category' => 'catalog:10', 'brand' => '100', 'stock' => 'low']);
    inventory_check($combined['filtered_total'] === 5, 'Search, category, brand and stock filters are combined.');
    inventory_check($combined['stock_counts'] === $first['stock_counts'] && $combined['categories'] === $first['categories'] && $combined['brands'] === $first['brands'], 'Stock and option summaries describe the full inventory independently of current filters.');
    $invalid = rl_inventory_list($pdo, ['q' => [], 'category' => 'catalog:1 OR 1=1', 'brand' => '200 OR 1=1', 'stock' => 'invalid', 'page' => '-2']);
    inventory_check($invalid['filters'] === ['q' => '', 'category' => '', 'brand' => '', 'stock' => '', 'page' => 1], 'Malformed filters and non-scalar query input normalize safely.');
    $url = rl_inventory_url(['q' => 'A&B %', 'category' => 'catalog:10', 'brand' => '100', 'stock' => 'low', 'page' => 1], ['page' => 2, 'ignored' => 'x']);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $roundTrip);
    inventory_check($roundTrip === ['q' => 'A&B %', 'category' => 'catalog:10', 'brand' => '100', 'stock' => 'low', 'page' => '2'], 'Pagination URLs preserve and encode only known inventory filters.');
    inventory_check((int) $pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn() === 71, 'Inventory reads leave database records unchanged.');
    $pdo->exec('UPDATE parts SET is_active = 0');
    $noActiveParts = rl_inventory_list($pdo);
    inventory_check($noActiveParts['stock_counts'] === ['all' => 0, 'low' => 0, 'available' => 0] && $noActiveParts['categories'] === [] && $noActiveParts['brands'] === [], 'Empty inventory has numeric zero summaries and no stale category or brand options.');
    echo "PASS: $checks inventory query checks\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
