<?php
/**
 * API: Alokacja wpisu PZF do projektów.
 *
 * Guardrail: operuje TYLKO na sales_noninvoice_allocations i sales_noninvoice_entries.
 * NIE dotyka invoice_sale_allocations, project_revenues, Fakturowni.
 *
 * GET action=allocations&entry_id=X  → lista alokacji wpisu
 * GET action=entry&entry_id=X        → dane wpisu (amount_net)
 * GET action=nodes&project_id=X      → etapy / węzły kosztowe projektu
 * GET action=projects                → lista aktywnych projektów
 *
 * POST action=add    entry_id, project_id, amount_net, cost_node_id?, description?
 * POST action=delete allocation_id
 * POST action=set    entry_id, project_id, cost_node_id?  (cała kwota na 1 projekt)
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'allocations') {
        $entryId = (int)($_GET['entry_id'] ?? 0);
        if ($entryId <= 0) { echo json_encode([]); exit; }

        $stmt = $pdo->prepare("
            SELECT a.id, a.project_id, p.name AS project_name,
                   a.cost_node_id, pcn.name AS node_name,
                   a.amount_net, a.description
            FROM sales_noninvoice_allocations a
            JOIN projects p ON p.id = a.project_id
            LEFT JOIN project_cost_nodes pcn ON pcn.id = a.cost_node_id
            WHERE a.entry_id = ?
            ORDER BY a.id
        ");
        $stmt->execute([$entryId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'entry') {
        $entryId = (int)($_GET['entry_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT e.id, e.entry_number, e.amount_net, e.currency,
                   COALESCE(SUM(a.amount_net), 0) AS allocated_net
            FROM sales_noninvoice_entries e
            LEFT JOIN sales_noninvoice_allocations a ON a.entry_id = e.id
            WHERE e.id = ?
            GROUP BY e.id
        ");
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: ['error' => 'not found']);
        exit;
    }

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

    if ($action === 'projects') {
        $stmt = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active','in_progress') ORDER BY name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $entryId    = (int)($_POST['entry_id']    ?? 0);
        $projectId  = (int)($_POST['project_id']  ?? 0);
        $amountNet  = (float)($_POST['amount_net'] ?? 0);
        $costNodeId = (int)($_POST['cost_node_id'] ?? 0);
        $desc       = trim((string)($_POST['description'] ?? ''));

        if ($entryId <= 0 || $projectId <= 0 || $amountNet <= 0) {
            echo json_encode(['success' => false, 'error' => 'Brak wymaganych pól']);
            exit;
        }

        // Weryfikacja: suma alokacji nie może przekroczyć amount_net wpisu
        $netStmt = $pdo->prepare("SELECT amount_net FROM sales_noninvoice_entries WHERE id = ?");
        $netStmt->execute([$entryId]);
        $entryNet = (float)$netStmt->fetchColumn();

        $usedStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_net), 0) FROM sales_noninvoice_allocations WHERE entry_id = ?");
        $usedStmt->execute([$entryId]);
        $usedNet = (float)$usedStmt->fetchColumn();

        if (($usedNet + $amountNet) > ($entryNet + 0.005)) {
            echo json_encode([
                'success' => false,
                'error'   => "Suma alokacji ({$usedNet} + {$amountNet}) przekracza kwotę wpisu ({$entryNet})."
            ]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO sales_noninvoice_allocations
                (entry_id, project_id, cost_node_id, amount_net, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $entryId,
            $projectId,
            $costNodeId > 0 ? $costNodeId : null,
            $amountNet,
            $desc ?: null,
            $_SESSION['user_id'] ?? null
        ]);

        logEvent("Alokacja PZF ID:{$entryId} → projekt ID:{$projectId}, kwota: {$amountNet}", 'INFO');
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'delete') {
        $allocId = (int)($_POST['allocation_id'] ?? 0);
        if ($allocId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Brak allocation_id']);
            exit;
        }
        $pdo->prepare("DELETE FROM sales_noninvoice_allocations WHERE id = ?")->execute([$allocId]);
        logEvent("Usunięto alokację PZF ID:{$allocId}", 'INFO');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'set') {
        $entryId    = (int)($_POST['entry_id'] ?? 0);
        $projectId  = (int)($_POST['project_id'] ?? 0);
        $costNodeId = (int)($_POST['cost_node_id'] ?? 0);

        if ($entryId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Brak entry_id']);
            exit;
        }

        $pdo->prepare("DELETE FROM sales_noninvoice_allocations WHERE entry_id = ?")->execute([$entryId]);

        if ($projectId > 0) {
            $entryStmt = $pdo->prepare("SELECT amount_net FROM sales_noninvoice_entries WHERE id = ?");
            $entryStmt->execute([$entryId]);
            $entryNet = (float)$entryStmt->fetchColumn();

            $pdo->prepare("
                INSERT INTO sales_noninvoice_allocations
                    (entry_id, project_id, cost_node_id, amount_net, created_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $entryId,
                $projectId,
                $costNodeId > 0 ? $costNodeId : null,
                $entryNet,
                $_SESSION['user_id'] ?? null
            ]);

            logEvent("Alokacja PZF ID:{$entryId} -> projekt ID:{$projectId} (cała kwota {$entryNet})", 'INFO');
        } else {
            logEvent("Odpięto PZF ID:{$entryId} od wszystkich projektów", 'INFO');
        }

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (Exception $e) {
    error_log("PZF assign-project error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}
