<?php

function ksCompanyCostDefaultCategories(): array
{
    return [
        'flota' => 'Flota',
        'media' => 'Media',
        'ubezpieczenia' => 'Ubezpieczenia',
        'certyfikaty' => 'Certyfikaty',
        'podatki' => 'Podatki',
        'narzedzia' => 'Narzędzia',
        'inne' => 'Inne',
    ];
}

function ksCompanyCostNormalizeKey(string $name): string
{
    $key = trim(mb_strtolower($name, 'UTF-8'));
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        if ($converted !== false) {
            $key = $converted;
        }
    }
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim((string)$key, '_');
    return mb_substr($key !== '' ? $key : 'kategoria', 0, 50);
}

function ksCompanyCostHumanizeKey(string $key): string
{
    $defaults = ksCompanyCostDefaultCategories();
    if (isset($defaults[$key])) {
        return $defaults[$key];
    }
    return mb_convert_case(str_replace('_', ' ', $key), MB_CASE_TITLE, 'UTF-8');
}

function ksCompanyCostEnsureSchema(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM company_cost_categories LIMIT 1");
        $pdo->query("SELECT 1 FROM company_cost_subcategories LIMIT 1");

        $sort = 10;
        $stmt = $pdo->prepare("
            INSERT INTO company_cost_categories (category_key, name, is_system, is_active, sort_order, created_at, updated_at)
            VALUES (?, ?, 1, 1, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                is_system = GREATEST(is_system, VALUES(is_system)),
                sort_order = LEAST(sort_order, VALUES(sort_order)),
                updated_at = NOW()
        ");
        foreach (ksCompanyCostDefaultCategories() as $key => $name) {
            $stmt->execute([$key, $name, $sort]);
            $sort += 10;
        }
        return true;
    } catch (PDOException $e) {
        error_log('FINANSE: Blad przygotowania slownika kategorii kosztow: ' . $e->getMessage());
        return false;
    }
}

function ksCompanyCostFetchRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

function ksCompanyCostExecSafe(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        return 0;
    }
}

function ksCompanyCostEmptyCategory(string $key, string $name, bool $system = false, bool $dictionary = false): array
{
    return [
        'key' => $key,
        'name' => $name,
        'system' => $system,
        'dictionary' => $dictionary,
        'usage' => 0,
        'subcategories' => [],
    ];
}

function ksCompanyCostLoadDictionary(PDO $pdo): array
{
    $schemaReady = ksCompanyCostEnsureSchema($pdo);
    $categories = [];
    $inactive = [];

    if ($schemaReady) {
        $rows = ksCompanyCostFetchRows($pdo, "
            SELECT category_key, name, is_system, is_active
            FROM company_cost_categories
            ORDER BY sort_order, name
        ");
        foreach ($rows as $row) {
            $key = trim((string)$row['category_key']);
            if ($key === '') {
                continue;
            }
            if ((int)$row['is_active'] !== 1) {
                $inactive[$key] = true;
                continue;
            }
            $categories[$key] = ksCompanyCostEmptyCategory(
                $key,
                trim((string)$row['name']) !== '' ? trim((string)$row['name']) : ksCompanyCostHumanizeKey($key),
                (int)$row['is_system'] === 1,
                true
            );
        }
    }

    if (!$schemaReady) {
        foreach (ksCompanyCostDefaultCategories() as $key => $name) {
            $categories[$key] = ksCompanyCostEmptyCategory($key, $name, true, false);
        }
    }

    foreach (ksCompanyCostFetchRows($pdo, "
        SELECT category, COUNT(*) AS usage_count
        FROM finance_items
        WHERE item_type = 'FIXED_COST'
          AND category IS NOT NULL
          AND TRIM(category) <> ''
        GROUP BY category
    ") as $row) {
        $key = trim((string)$row['category']);
        if ($key === '' || isset($inactive[$key])) {
            continue;
        }
        if (isset($categories[$key])) {
            $categories[$key]['usage'] += (int)$row['usage_count'];
        }
    }

    foreach (ksCompanyCostFetchRows($pdo, "
        SELECT company_category AS category, COUNT(*) AS usage_count
        FROM worker_expenses
        WHERE company_category IS NOT NULL
          AND TRIM(company_category) <> ''
        GROUP BY company_category
    ") as $row) {
        $key = trim((string)$row['category']);
        if ($key === '' || isset($inactive[$key])) {
            continue;
        }
        if (isset($categories[$key])) {
            $categories[$key]['usage'] += (int)$row['usage_count'];
        }
    }

    foreach (ksCompanyCostFetchRows($pdo, "
        SELECT company_cost_category AS category, COUNT(*) AS usage_count
        FROM fakturownia_cost_allocations
        WHERE company_cost_category IS NOT NULL
          AND TRIM(company_cost_category) <> ''
        GROUP BY company_cost_category
    ") as $row) {
        $key = trim((string)$row['category']);
        if ($key === '' || isset($inactive[$key])) {
            continue;
        }
        if (isset($categories[$key])) {
            $categories[$key]['usage'] += (int)$row['usage_count'];
        }
    }

    if ($schemaReady) {
        foreach (ksCompanyCostFetchRows($pdo, "
            SELECT category_key, name
            FROM company_cost_subcategories
            ORDER BY category_key, sort_order, name
        ") as $row) {
            $key = trim((string)$row['category_key']);
            $name = trim((string)$row['name']);
            if ($key === '' || $name === '' || !isset($categories[$key])) {
                continue;
            }
            $categories[$key]['subcategories'][$name] = ['name' => $name, 'usage' => 0, 'dictionary' => true];
        }
    }

    foreach (ksCompanyCostFetchRows($pdo, "
        SELECT category, subcategory, COUNT(*) AS usage_count
        FROM finance_items
        WHERE item_type = 'FIXED_COST'
          AND category IS NOT NULL
          AND TRIM(category) <> ''
          AND subcategory IS NOT NULL
          AND TRIM(subcategory) <> ''
        GROUP BY category, subcategory
    ") as $row) {
        $key = trim((string)$row['category']);
        $name = trim((string)$row['subcategory']);
        if ($key === '' || $name === '' || isset($inactive[$key])) {
            continue;
        }
        if (!isset($categories[$key])) {
            continue;
        }
        if (!isset($categories[$key]['subcategories'][$name])) {
            continue;
        }
        $categories[$key]['subcategories'][$name]['usage'] += (int)$row['usage_count'];
    }

    foreach (ksCompanyCostFetchRows($pdo, "
        SELECT company_category AS category, company_subcategory AS subcategory, COUNT(*) AS usage_count
        FROM worker_expenses
        WHERE company_category IS NOT NULL
          AND TRIM(company_category) <> ''
          AND company_subcategory IS NOT NULL
          AND TRIM(company_subcategory) <> ''
        GROUP BY company_category, company_subcategory
    ") as $row) {
        $key = trim((string)$row['category']);
        $name = trim((string)$row['subcategory']);
        if ($key === '' || $name === '' || isset($inactive[$key])) {
            continue;
        }
        if (!isset($categories[$key])) {
            continue;
        }
        if (!isset($categories[$key]['subcategories'][$name])) {
            continue;
        }
        $categories[$key]['subcategories'][$name]['usage'] += (int)$row['usage_count'];
    }

    foreach (ksCompanyCostFetchRows($pdo, "
        SELECT company_cost_category AS category, company_cost_subcategory AS subcategory, COUNT(*) AS usage_count
        FROM fakturownia_cost_allocations
        WHERE company_cost_category IS NOT NULL
          AND TRIM(company_cost_category) <> ''
          AND company_cost_subcategory IS NOT NULL
          AND TRIM(company_cost_subcategory) <> ''
        GROUP BY company_cost_category, company_cost_subcategory
    ") as $row) {
        $key = trim((string)$row['category']);
        $name = trim((string)$row['subcategory']);
        if ($key === '' || $name === '' || isset($inactive[$key])) {
            continue;
        }
        if (!isset($categories[$key])) {
            continue;
        }
        if (!isset($categories[$key]['subcategories'][$name])) {
            continue;
        }
        $categories[$key]['subcategories'][$name]['usage'] += (int)$row['usage_count'];
    }

    uasort($categories, function (array $a, array $b): int {
        return strnatcasecmp($a['name'], $b['name']);
    });
    foreach ($categories as &$category) {
        uasort($category['subcategories'], function (array $a, array $b): int {
            return strnatcasecmp($a['name'], $b['name']);
        });
    }
    unset($category);

    return ['schema_ready' => $schemaReady, 'categories' => $categories];
}

function ksCompanyCostCategoryLabels(array $dictionary): array
{
    $labels = [];
    foreach (($dictionary['categories'] ?? []) as $key => $category) {
        $labels[$key] = $category['name'];
    }
    return $labels;
}

function ksCompanyCostSubcategoryNames(array $dictionary, ?string $categoryKey = null): array
{
    $names = [];
    foreach (($dictionary['categories'] ?? []) as $key => $category) {
        if ($categoryKey !== null && $categoryKey !== '' && $key !== $categoryKey) {
            continue;
        }
        foreach (($category['subcategories'] ?? []) as $subcategory) {
            $names[$subcategory['name']] = $subcategory['name'];
        }
    }
    natcasesort($names);
    return array_values($names);
}

function ksCompanyCostSubcategoriesByCategory(array $dictionary): array
{
    $result = [];
    foreach (($dictionary['categories'] ?? []) as $key => $category) {
        $result[$key] = array_values(array_map(static function (array $subcategory): string {
            return $subcategory['name'];
        }, $category['subcategories'] ?? []));
    }
    return $result;
}
