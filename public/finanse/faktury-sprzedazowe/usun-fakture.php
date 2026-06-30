<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Faktury Sprzedażowej
 * 
 * Usuwa fakturę sprzedażową wraz z:
 * - Pozycjami faktury (jeśli są)
 * - Płatnościami (jeśli są)
 * - Plikiem faktury (jeśli istnieje)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/includes/sales_invoice_correction_helper.php';
$sprutexInvoiceAuditHelper = dirname(dirname(__DIR__)) . '/includes/invoice_audit_helper.php';
if (is_file($sprutexInvoiceAuditHelper)) {
    require_once $sprutexInvoiceAuditHelper;
}

if (!function_exists('invoiceAuditLog')) {
    function invoiceAuditLog(
        PDO $pdo,
        ?int $invoiceSaleId,
        string $action,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null,
        ?string $reason = null,
        string $source = 'erp',
        ?int $externalFakturowniaId = null,
        ?string $externalGovId = null
    ): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO invoice_audit_log
                    (invoice_sale_id, action, old_values, new_values, user_id, reason, source, external_fakturownia_id, external_gov_id)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $invoiceSaleId ?: null,
                mb_substr($action, 0, 80),
                $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $userId ?: null,
                $reason ? mb_substr($reason, 0, 500) : null,
                mb_substr($source ?: 'erp', 0, 80),
                $externalFakturowniaId ?: null,
                $externalGovId ?: null,
            ]);
        } catch (Throwable $e) {
            if (function_exists('logEvent')) {
                logEvent('Invoice audit skipped: ' . $e->getMessage(), 'WARNING');
            }
        }
    }
}

if (!function_exists('invoiceFakturowniaMappingBySaleId')) {
    function invoiceFakturowniaMappingBySaleId(PDO $pdo, int $invoiceSaleId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM fakturownia_invoices
            WHERE erp_invoice_sale_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$invoiceSaleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
$deleteReason = trim((string)($_POST['delete_reason'] ?? 'Usunięcie/anulowanie z UI'));

if (!$invoice_id) {
    header("Location: " . url('finanse.faktury-sprzedazowe'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfVerify()) {
    header("Location: " . url('finanse.faktury-sprzedazowe', ['error' => 'csrf']));
    exit;
}

try {
    // Pobierz dane faktury
    $stmt = $pdo->prepare("SELECT * FROM invoices_sale WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        header("Location: " . url('finanse.faktury-sprzedazowe', ['error' => 'not_found']));
        exit;
    }
    
    $pdo->beginTransaction();

    $mapping = invoiceFakturowniaMappingBySaleId($pdo, $invoice_id);
    $hasExternal = $mapping && (
        !empty($mapping['fakturownia_id'])
        || !empty($mapping['gov_id'])
        || !empty($mapping['gov_status'])
    );
    
    $attentionNote = $hasExternal
        ? 'Anulowanie lokalne faktury z powiązaniem Fakturownia/KSeF - wymaga weryfikacji księgowej'
        : 'Anulowanie lokalne faktury bez mapowania Fakturownia/KSeF';

    $archivedNumber = null;
    if (!$hasExternal && ($invoice['status'] ?? '') === 'draft') {
        $archivedNumber = sprutexArchiveLocalInvoiceNumber($pdo, $invoice_id, $deleteReason);
    } else {
        $stmt = $pdo->prepare("
            UPDATE invoices_sale
            SET status = 'cancelled',
                deleted_at = COALESCE(deleted_at, NOW()),
                deleted_by = ?,
                delete_reason = ?,
                sync_attention_required = CASE WHEN ? = 1 THEN 1 ELSE sync_attention_required END,
                sync_attention_note = CASE WHEN ? = 1 THEN ? ELSE sync_attention_note END
            WHERE id = ?
        ");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            mb_substr($deleteReason, 0, 500),
            $hasExternal ? 1 : 0,
            $hasExternal ? 1 : 0,
            $attentionNote,
            $invoice_id,
        ]);
    }

    invoiceAuditLog(
        $pdo,
        $invoice_id,
        'invoice_soft_deleted',
        [
            'status' => $invoice['status'] ?? null,
            'deleted_at' => $invoice['deleted_at'] ?? null,
            'invoice_number' => $invoice['invoice_number'] ?? null,
        ],
        [
            'status' => 'cancelled',
            'deleted_at' => date('Y-m-d H:i:s'),
            'has_external_mapping' => $hasExternal,
            'delete_reason' => $deleteReason,
            'archived_invoice_number' => $archivedNumber,
        ],
        $_SESSION['user_id'] ?? null,
        $deleteReason,
        'invoice_delete_ui',
        $mapping && !empty($mapping['fakturownia_id']) ? (int)$mapping['fakturownia_id'] : null,
        $mapping && !empty($mapping['gov_id']) ? (string)$mapping['gov_id'] : null
    );
    
    $pdo->commit();
    
    logEvent("Oznaczono fakturę sprzedażową jako anulowaną/usuniętą lokalnie: {$invoice['invoice_number']} (ID: {$invoice_id})", 'WARNING');
    
    header("Location: " . url('finanse.faktury-sprzedazowe', ['success' => 'soft_deleted']));
    exit;
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd usuwania faktury sprzedażowej ID {$invoice_id}: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.faktury-sprzedazowe', ['error' => 'delete_failed']));
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd ogólny usuwania faktury sprzedażowej ID {$invoice_id}: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.faktury-sprzedazowe', ['error' => 'delete_failed']));
    exit;
}
