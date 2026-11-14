<?php
declare(strict_types=1);

/** Read inventory in bounded pages, using catalog metadata matched by SKU. */
function rl_inventory_list(PDO $pdo, array $input = []): array
{
    $text = static fn(string $key): string => isset($input[$key]) && is_scalar($input[$key])
        ? trim((string) $input[$key]) : '';
    $q = $text('q');
    $category = $text('category');
    if (!preg_match('/^(catalog|part):([1-9][0-9]*)$/D', $category, $categoryMatch)
        || filter_var($categoryMatch[2], FILTER_VALIDATE_INT) === false) {
        $category = '';
    }
    $brand = $text('brand');
    if ($brand !== 'none' && (!preg_match('/^[1-9][0-9]*$/D', $brand)
        || filter_var($brand, FILTER_VALIDATE_INT) === false)) {
        $brand = '';
    }
    $stock = $text('stock');
    if (!in_array($stock, ['', 'low', 'available'], true)) $stock = '';
    $page = filter_var($text('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $perPage = 25;

    $joins = ' FROM parts p
        LEFT JOIN catalog_products cp ON cp.sku = p.part_code
        LEFT JOIN catalog_brands cb ON cb.id = cp.brand_id
        LEFT JOIN catalog_categories cc ON cc.id = cp.category_id
        LEFT JOIN part_categories pc ON pc.id = p.category_id';
    $where = ['p.is_active = 1'];
    $params = [];
    if ($q !== '') {
        // A separate escape character keeps %, _ and backslashes literal in user searches.
        $pattern = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = "(p.part_code LIKE :search_code ESCAPE '!' OR p.name LIKE :search_name ESCAPE '!' OR cb.name LIKE :search_brand ESCAPE '!')";
        $params['search_code'] = $pattern;
        $params['search_name'] = $pattern;
        $params['search_brand'] = $pattern;
    }
    if ($category !== '') {
        [$source, $categoryId] = explode(':', $category, 2);
        $where[] = $source === 'catalog' ? 'cp.category_id = :category' : '(cp.id IS NULL AND p.category_id = :category)';
        $params['category'] = (int) $categoryId;
    }
    if ($brand === 'none') {
        $where[] = 'cp.id IS NULL';
    } elseif ($brand !== '') {
        $where[] = 'cp.brand_id = :brand';
        $params['brand'] = (int) $brand;
    }
    if ($stock === 'low') $where[] = 'p.stock_quantity <= p.min_stock_alert';
    if ($stock === 'available') $where[] = 'p.stock_quantity > p.min_stock_alert';
    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $stockSummary = $pdo->query(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(stock_quantity <= min_stock_alert), 0) AS low,
                COALESCE(SUM(stock_quantity > min_stock_alert), 0) AS available
         FROM parts WHERE is_active = 1'
    )->fetch(PDO::FETCH_ASSOC);
    $total = (int) $stockSummary['total'];
    $stockCounts = ['all' => $total, 'low' => (int) $stockSummary['low'], 'available' => (int) $stockSummary['available']];
    $count = $pdo->prepare('SELECT COUNT(*)' . $joins . $whereSql);
    $count->execute($params);
    $filteredTotal = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($filteredTotal / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $query = $pdo->prepare('SELECT p.*, COALESCE(cc.name, pc.name) AS category_name, cb.name AS brand_name'
        . $joins . $whereSql
        . ' ORDER BY (p.stock_quantity <= p.min_stock_alert) DESC, p.id DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    $parts = $query->fetchAll(PDO::FETCH_ASSOC);

    $categories = $pdo->query(
        "SELECT cc.id AS id, CONCAT('catalog:', cc.id) AS value, cc.name AS name, COUNT(*) AS `count`"
        . $joins . ' WHERE p.is_active = 1 AND cc.id IS NOT NULL'
        . ' GROUP BY cc.id, cc.name'
        . " UNION SELECT pc.id AS id, CONCAT('part:', pc.id) AS value, pc.name AS name, COUNT(*) AS `count`"
        . $joins . ' WHERE p.is_active = 1 AND cp.id IS NULL AND pc.id IS NOT NULL'
        . ' GROUP BY pc.id, pc.name'
        . ' ORDER BY name, value'
    )->fetchAll(PDO::FETCH_ASSOC);
    $brands = $pdo->query('SELECT cb.id, cb.name, COUNT(*) AS `count`' . $joins
        . ' WHERE p.is_active = 1 AND cb.id IS NOT NULL GROUP BY cb.id, cb.name ORDER BY cb.name, cb.id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categories as &$option) $option['count'] = (int) $option['count'];
    unset($option);
    foreach ($brands as &$option) $option['count'] = (int) $option['count'];
    unset($option);

    return [
        'parts' => $parts,
        'categories' => $categories,
        'brands' => $brands,
        'filters' => compact('q', 'category', 'brand', 'stock', 'page'),
        'total' => $total,
        'stock_counts' => $stockCounts,
        'filtered_total' => $filteredTotal,
        'page' => $page,
        'pages' => $pages,
        'from' => $filteredTotal > 0 ? $offset + 1 : 0,
        'to' => $filteredTotal > 0 ? min($offset + $perPage, $filteredTotal) : 0,
        'per_page' => $perPage,
    ];
}

/** Preserve the current inventory filters in pagination and action links. */
function rl_inventory_url(array $filters, array $overrides = []): string
{
    $query = array_intersect_key(array_replace($filters, $overrides), array_flip(['q', 'category', 'brand', 'stock', 'page']));
    $query = array_filter($query, static fn($value): bool => is_scalar($value) && (string) $value !== '');
    return 'list.php' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}