<?php
/**
 * API: Dodaj pozycję do faktury sprzedażowej
 * POST: invoice_id, project_id, cost_node_id, item_name, quantity, unit, unit_price_net, vat_rate
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    // Walidacja danych wejściowych
    $invoice_id = $_POST['invoice_id'] ?? null;
    $project_id = $_POST['project_id'] ?? null;
    $cost_node_id = $_POST['cost_node_id'] ?? null;
    $item_name = trim($_POST['item_name'] ?? '');
    $quantity = floatval($_POST['quantity'] ?? 1);
    $unit = trim($_POST['unit'] ?? 'szt');
    $unit_price_net = floatval($_POST['unit_price_net'] ?? 0);
    $vat_rate = trim($_POST['vat_rate'] ?? '23');
    $product_id = (int)($_POST['product_id'] ?? 0);
    $product_code = trim($_POST['product_code'] ?? '');
    $gtu_code = trim($_POST['gtu_code'] ?? '');
    $discount_kind = trim($_POST['discount_kind'] ?? 'none');
    $discount_percent = max(0, floatval($_POST['discount_percent'] ?? 0));
    $discount_amount = max(0, floatval($_POST['discount'] ?? 0));
    
    if (!$invoice_id || !$item_name || $quantity <= 0 || $unit_price_net < 0) {
        echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe']);
        exit;
    }
    
    // Sprawdź czy faktura istnieje i jest w statusie draft
    $stmt = $pdo->prepare("SELECT status FROM invoices_sale WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        echo json_encode(['success' => false, 'error' => 'Faktura nie istnieje']);
        exit;
    }
    
    if ($invoice['status'] !== 'draft') {
        echo json_encode(['success' => false, 'error' => 'Można edytować tylko faktury w statusie szkic']);
        exit;
    }
    
    // Oblicz kwoty
    $amount_net_base = round($quantity * $unit_price_net, 2);
    $discount_net = 0;
    if ($discount_kind === 'percent_unit') {
        $discount_net = round($amount_net_base * min(100, $discount_percent) / 100, 2);
    } elseif ($discount_kind === 'amount') {
        $discount_net = round($discount_amount, 2);
    }
    if ($discount_net > $amount_net_base) {
        $discount_net = $amount_net_base;
    }
    $amount_net = round($amount_net_base - $discount_net, 2);
    
    // Oblicz VAT
    $vat_multiplier = 0;
    if ($vat_rate === '23') $vat_multiplier = 0.23;
    elseif ($vat_rate === '8') $vat_multiplier = 0.08;
    elseif ($vat_rate === '5') $vat_multiplier = 0.05;
    elseif ($vat_rate === '0' || $vat_rate === 'zw' || $vat_rate === 'np') $vat_multiplier = 0;
    
    $amount_vat = round($amount_net * $vat_multiplier, 2);
    $amount_gross = $amount_net + $amount_vat;
    
    // Pobierz ostatni sort_order
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM invoice_sale_items WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $sort_order = $stmt->fetchColumn();
    
    // Dodaj pozycję
    $stmt = $pdo->prepare("
        INSERT INTO invoice_sale_items 
        (invoice_id, project_id, cost_node_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat, amount_gross, fakturownia_item_options_json, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $itemOptions = [
        'product_id' => $product_id > 0 ? $product_id : null,
        'product_code' => $product_code !== '' ? $product_code : null,
        'gtu_code' => $gtu_code !== '' ? $gtu_code : null,
        'discount_percent' => $discount_percent,
        'discount' => $discount_amount,
    ];
    
    $stmt->execute([
        $invoice_id,
        $project_id ?: null,
        $cost_node_id ?: null,
        $item_name,
        $quantity,
        $unit,
        $unit_price_net,
        $vat_rate,
        $amount_net,
        $amount_vat,
        $amount_gross,
        json_encode($itemOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $sort_order
    ]);
    
    $item_id = $pdo->lastInsertId();
    
    // Przelicz sumy faktury
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount_net), 0) as total_net,
            COALESCE(SUM(amount_vat), 0) as total_vat,
            COALESCE(SUM(amount_gross), 0) as total_gross
        FROM invoice_sale_items
        WHERE invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $totals = $stmt->fetch();
    
    // Aktualizuj nagłówek faktury
    $stmt = $pdo->prepare("
        UPDATE invoices_sale 
        SET amount_net = ?, amount_vat = ?, amount_gross = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $totals['total_net'],
        $totals['total_vat'],
        $totals['total_gross'],
        $invoice_id
    ]);
    
    logEvent("Dodano pozycję do faktury sprzedażowej ID: {$invoice_id}, pozycja: {$item_name}", 'INFO');
    
    echo json_encode([
        'success' => true,
        'item_id' => $item_id,
        'totals' => [
            'net' => $totals['total_net'],
            'vat' => $totals['total_vat'],
            'gross' => $totals['total_gross']
        ]
    ]);
    
} catch (PDOException $e) {
    logEvent("Błąd dodawania pozycji faktury: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}
