<?php
/**
 * API: Lista produktów z lokalnego katalogu Fakturowni
 * GET:
 * - q (opcjonalnie): fraza do wyszukiwania po nazwie/kodzie
 * - limit (opcjonalnie): 1..300, domyślnie 100
 * - include_inactive=1 (opcjonalnie): zwróć też nieaktywne
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();

    $q = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 300) {
        $limit = 300;
    }

    $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1';

    $where = [];
    $params = [];

    if (!$includeInactive) {
        $where[] = 'is_active = 1';
    }

    if ($q !== '') {
        $where[] = '(name LIKE :q OR code LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $sql = "
        SELECT
            id,
            fakturownia_product_id,
            code,
            name,
            unit,
            tax,
            price_net,
            price_gross,
            currency,
            is_active,
            synced_at
        FROM fakturownia_products
    ";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY name ASC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'count' => count($items),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    logEvent('Błąd list-fakturownia-products API: ' . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'error' => 'Nie udało się pobrać katalogu produktów',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
