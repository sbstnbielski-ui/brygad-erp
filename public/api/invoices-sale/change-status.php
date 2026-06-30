<?php
/**
 * BRYGAD ERP - API: Zmiana statusu faktury sprzedażowej
 *
 * Zmienia status w ERP i propaguje do Fakturowni (jeśli FV ma mapowanie).
 * Wywoływane ajaxem z UI (lista FV, edycja FV).
 * Zwraca JSON.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaClient.php';

header('Content-Type: application/json; charset=utf-8');

startSecureSession();
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

$pdo = getDbConnection();

$invoiceId = (int)($_POST['invoice_id'] ?? 0);
$newStatus = trim((string)($_POST['new_status'] ?? ''));
$allowedStatuses = ['draft', 'issued', 'paid', 'partially_paid', 'cancelled'];

if ($invoiceId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe parametry']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, status, amount_net, invoice_number FROM invoices_sale WHERE id = ?');
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Faktura nie znaleziona']);
        exit;
    }

    $oldStatus = $invoice['status'];
    if ($oldStatus === $newStatus) {
        echo json_encode(['success' => true, 'changed' => false, 'status' => $newStatus]);
        exit;
    }

    $paymentDate = null;
    if ($newStatus === 'paid') {
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    }

    $updateSql = 'UPDATE invoices_sale SET status = :status';
    $params = [':status' => $newStatus, ':id' => $invoiceId];

    if ($paymentDate) {
        $updateSql .= ', payment_date = :payment_date';
        $params[':payment_date'] = $paymentDate;
    }

    $updateSql .= ' WHERE id = :id';
    $pdo->prepare($updateSql)->execute($params);

    logEvent("Status FV #{$invoice['invoice_number']}: {$oldStatus} → {$newStatus}", 'INFO');

    $fkSynced = false;
    $fkError = null;

    $fkStmt = $pdo->prepare(
        'SELECT fakturownia_id FROM fakturownia_invoices WHERE erp_invoice_sale_id = ? LIMIT 1'
    );
    $fkStmt->execute([$invoiceId]);
    $fkId = (int)$fkStmt->fetchColumn();

    if ($fkId > 0) {
        try {
            $client = new FakturowniaClient($pdo);

            if ($newStatus === 'partially_paid') {
                $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_net), 0) FROM invoice_sale_payments WHERE invoice_id = ?');
                $sumStmt->execute([$invoiceId]);
                $totalPaidNet = (float)$sumStmt->fetchColumn();

                $netTotal = (float)($invoice['amount_net'] ?? 0);
                $grossStmt = $pdo->prepare('SELECT amount_gross FROM invoices_sale WHERE id = ?');
                $grossStmt->execute([$invoiceId]);
                $grossTotal = (float)$grossStmt->fetchColumn();
                $paidGross = ($netTotal > 0) ? round($totalPaidNet * ($grossTotal / $netTotal), 2) : $totalPaidNet;

                $client->put('/invoices/' . $fkId . '.json', [
                    'invoice' => [
                        'status' => 'partial',
                        'paid'   => number_format($paidGross, 2, '.', ''),
                    ]
                ]);
            } else {
                $fkStatusMap = ['paid' => 'paid', 'issued' => 'issued', 'sent' => 'sent', 'cancelled' => 'rejected', 'draft' => 'issued'];
                $fkStatus = $fkStatusMap[$newStatus] ?? null;
                if ($fkStatus) {
                    $client->changeInvoiceStatus($fkId, $fkStatus);
                }
            }
            $fkSynced = true;

            $bridgeStatus = in_array($newStatus, ['paid'], true) ? 'paid' : 'sent';
            $pdo->prepare(
                "UPDATE fakturownia_invoices SET status = :st, synced_at = NOW() WHERE fakturownia_id = :fk_id"
            )->execute([':st' => $bridgeStatus, ':fk_id' => $fkId]);
        } catch (FakturowniaAuthException $e) {
            $fkError = 'Błąd autoryzacji Fakturownia';
        } catch (Throwable $e) {
            $fkError = 'Błąd synchronizacji z Fakturownią: ' . $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'changed' => true,
        'status' => $newStatus,
        'old_status' => $oldStatus,
        'fk_synced' => $fkSynced,
        'fk_error' => $fkError,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd: ' . $e->getMessage()]);
}
