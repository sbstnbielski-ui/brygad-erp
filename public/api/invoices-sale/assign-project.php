<?php
/**
 * API: Alokacja FV sprzedażowej do projektów (kwotowo).
 *
 * GET: action=nodes&project_id=X  →  etapy projektu
 * GET: action=allocations&invoice_id=X  →  aktualne alokacje
 * GET: action=invoice&invoice_id=X  →  dane FV (amount_net)
 *
 * POST action=add:    invoice_id, project_id, amount_net, cost_node_id?, description?
 * POST action=delete: allocation_id
 * POST action=set:    invoice_id, project_id, cost_node_id?  (cała kwota na 1 projekt)
 */
require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/includes/sales_invoice_correction_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// === GET handlers ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'nodes') {
        $pid = (int)($_GET['project_id'] ?? 0);
        if ($pid <= 0) { echo json_encode([]); exit; }
        $stmt = $pdo->prepare("
            SELECT id, parent_id, name
            FROM project_cost_nodes
            WHERE project_id = ? AND is_active = 1
            ORDER BY parent_id IS NULL DESC, sort_order, name
        ");
        $stmt->execute([$pid]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'allocations') {
        $invId = (int)($_GET['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT a.id, a.project_id, p.name AS project_name, a.cost_node_id,
                   pcn.name AS node_name, a.amount_net, a.description
            FROM invoice_sale_allocations a
            JOIN projects p ON p.id = a.project_id
            LEFT JOIN project_cost_nodes pcn ON pcn.id = a.cost_node_id
            WHERE a.invoice_id = ?
            ORDER BY a.id
        ");
        $stmt->execute([$invId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'invoice') {
        $invId = (int)($_GET['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, invoice_number, amount_net, financial_effect_kind, correction_effect_net,
                   correction_effect_vat, correction_effect_gross, fakturownia_options_json, document_kind
            FROM invoices_sale
            WHERE id = ?
        ");
        $stmt->execute([$invId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['document_amount_net'] = (float)($row['amount_net'] ?? 0);
            $row['is_correction'] = sprutexInvoiceSaleIsCorrection($row);
            $row['amount_net'] = sprutexEffectiveInvoiceSaleNet($row);
        }
        echo json_encode($row ?: ['error' => 'not found']);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// === POST handlers ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? 'set';

try {
    if ($action === 'add') {
        $invoiceId  = (int)($_POST['invoice_id'] ?? 0);
        $projectId  = (int)($_POST['project_id'] ?? 0);
        $amountNet  = (float)($_POST['amount_net'] ?? 0);
        $costNodeId = (int)($_POST['cost_node_id'] ?? 0);
        $desc       = trim((string)($_POST['description'] ?? ''));

        if ($invoiceId <= 0 || $projectId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Brak wymaganych pól']);
            exit;
        }

        $invStmt = $pdo->prepare("
            SELECT id, amount_net, financial_effect_kind, correction_effect_net, fakturownia_options_json, document_kind
            FROM invoices_sale
            WHERE id = ?
        ");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            echo json_encode(['success' => false, 'error' => 'Nie znaleziono faktury']);
            exit;
        }

        $isCorrection = sprutexInvoiceSaleIsCorrection($invoice);
        if (!$isCorrection && $amountNet <= 0) {
            echo json_encode(['success' => false, 'error' => 'Kwota alokacji zwykłej faktury musi być dodatnia']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO invoice_sale_allocations (invoice_id, project_id, cost_node_id, amount_net, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceId,
            $projectId,
            $costNodeId > 0 ? $costNodeId : null,
            $amountNet,
            $desc ?: null,
            $_SESSION['user_id'] ?? null
        ]);

        logEvent("Alokacja FV ID:{$invoiceId} → projekt ID:{$projectId}, kwota: {$amountNet}", 'INFO');
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'delete') {
        $allocId = (int)($_POST['allocation_id'] ?? 0);
        if ($allocId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Brak allocation_id']);
            exit;
        }
        $pdo->prepare("DELETE FROM invoice_sale_allocations WHERE id = ?")->execute([$allocId]);
        logEvent("Usunięto alokację ID:{$allocId}", 'INFO');
        echo json_encode(['success' => true]);
        exit;
    }

    // action=set — cała kwota na 1 projekt (shortcut, kompatybilność)
    $invoiceId  = (int)($_POST['invoice_id'] ?? 0);
    $projectId  = (int)($_POST['project_id'] ?? 0);
    $costNodeId = (int)($_POST['cost_node_id'] ?? 0);

    if ($invoiceId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Brak invoice_id']);
        exit;
    }

    $pdo->prepare("DELETE FROM invoice_sale_allocations WHERE invoice_id = ?")->execute([$invoiceId]);

    if ($projectId > 0) {
        $invStmt = $pdo->prepare("
            SELECT amount_net, financial_effect_kind, correction_effect_net, fakturownia_options_json, document_kind
            FROM invoices_sale
            WHERE id = ?
        ");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            echo json_encode(['success' => false, 'error' => 'Nie znaleziono faktury']);
            exit;
        }
        $invNet = sprutexEffectiveInvoiceSaleNet($invoice);

        $pdo->prepare("
            INSERT INTO invoice_sale_allocations (invoice_id, project_id, cost_node_id, amount_net, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $invoiceId,
            $projectId,
            $costNodeId > 0 ? $costNodeId : null,
            $invNet,
            $_SESSION['user_id'] ?? null
        ]);
        logEvent("Alokacja FV ID:{$invoiceId} → projekt ID:{$projectId} (cała kwota {$invNet})", 'INFO');
    } else {
        logEvent("Odpięto FV ID:{$invoiceId} od wszystkich projektów", 'INFO');
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("assign-project error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}
