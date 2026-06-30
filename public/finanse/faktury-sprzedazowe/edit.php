<?php
/**
 * BRYGAD ERP - Edycja / Podgląd Faktury Sprzedażowej (v4)
 *
 * - Draft: pełna edycja WSZYSTKICH pól + pozycji, podgląd Fakturownia-style
 * - Issued: readonly, status KSeF, zmiana statusu, PDF
 * - Paid: readonly
 * - Dwa rozwijane kafelki: Dane dokumentu + Płatności/KSeF
 * - Logo wyrównane z tytułem w hero
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/includes/sales_invoice_correction_helper.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaService.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaClient.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

function syncStatusToFakturownia(PDO $pdo, int $invoiceId, string $erpStatus, float $paymentAmount = 0): void {
    try {
        $stmt = $pdo->prepare('SELECT fakturownia_id FROM fakturownia_invoices WHERE erp_invoice_sale_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $fkId = (int)$stmt->fetchColumn();
        if ($fkId <= 0) return;
        $client = new FakturowniaClient($pdo);

        if ($erpStatus === 'partially_paid') {
            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_net), 0) FROM invoice_sale_payments WHERE invoice_id = ?');
            $sumStmt->execute([$invoiceId]);
            $totalPaidNet = (float)$sumStmt->fetchColumn();

            $invStmt = $pdo->prepare('SELECT amount_net, amount_gross FROM invoices_sale WHERE id = ?');
            $invStmt->execute([$invoiceId]);
            $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
            $netTotal = (float)($inv['amount_net'] ?? 0);
            $grossTotal = (float)($inv['amount_gross'] ?? 0);
            $paidGross = ($netTotal > 0) ? round($totalPaidNet * ($grossTotal / $netTotal), 2) : $totalPaidNet;

            $client->put('/invoices/' . $fkId . '.json', [
                'invoice' => [
                    'status' => 'partial',
                    'paid'   => number_format($paidGross, 2, '.', ''),
                ]
            ]);
        } else {
            if ($paymentAmount > 0) {
                $client->post('/banking/payments.json', [
                    'banking_payment' => [
                        'name'       => 'Wpłata ERP',
                        'price'      => $paymentAmount,
                        'invoice_id' => $fkId,
                        'paid'       => true,
                        'kind'       => 'api',
                        'provider'   => 'transfer',
                    ]
                ]);
            }

            $fkMap = ['paid' => 'paid', 'issued' => 'issued', 'cancelled' => 'rejected', 'draft' => 'issued'];
            if (isset($fkMap[$erpStatus])) {
                $client->changeInvoiceStatus($fkId, $fkMap[$erpStatus]);
            }
        }
    } catch (Throwable $e) {
        logEvent("FK sync error FV ID:{$invoiceId}: " . $e->getMessage(), 'WARNING');
    }
}

function recalcInvoicePaymentStatus(PDO $pdo, int $invoiceId): string {
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_net), 0) FROM invoice_sale_payments WHERE invoice_id = ?');
    $sumStmt->execute([$invoiceId]);
    $totalPaid = (float)$sumStmt->fetchColumn();

    $invStmt = $pdo->prepare('SELECT amount_net, status FROM invoices_sale WHERE id = ?');
    $invStmt->execute([$invoiceId]);
    $row = $invStmt->fetch(PDO::FETCH_ASSOC);
    $invTotal = (float)($row['amount_net'] ?? 0);
    $currentStatus = $row['status'] ?? 'issued';

    if ($totalPaid >= $invTotal && $invTotal > 0) {
        $status = 'paid';
    } elseif ($totalPaid > 0) {
        $status = 'partially_paid';
    } else {
        return $currentStatus;
    }

    if ($status === 'paid') {
        $pdo->prepare("UPDATE invoices_sale SET status = ?, payment_date = COALESCE(payment_date, ?) WHERE id = ?")
            ->execute([$status, date('Y-m-d'), $invoiceId]);
    } else {
        $pdo->prepare("UPDATE invoices_sale SET status = ? WHERE id = ?")
            ->execute([$status, $invoiceId]);
    }

    return $status;
}

function invoiceNeedsBankAccount(array $invoice): bool {
    return (($invoice['payment_method'] ?? '') === 'transfer') || !empty($invoice['split_payment']);
}

$invoice_id = $_GET['id'] ?? null;
if (!$invoice_id) { header("Location: " . url('finanse.faktury-sprzedazowe')); exit; }

$stmt = $pdo->prepare("
    SELECT inv.*, i.name as client_name, i.nip as client_nip, i.address as client_address, i.email as client_email
    FROM invoices_sale inv
    LEFT JOIN investors i ON inv.client_id = i.id
    WHERE inv.id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) { header("Location: " . url('finanse.faktury-sprzedazowe', ['error' => 'not_found'])); exit; }

$fakturowniaOptions = [];
if (!empty($invoice['fakturownia_options_json'])) {
    $decoded = json_decode((string)$invoice['fakturownia_options_json'], true);
    if (is_array($decoded)) $fakturowniaOptions = $decoded;
}

$items_stmt = $pdo->prepare("
    SELECT isi.* FROM invoice_sale_items isi
    WHERE isi.invoice_id = ? ORDER BY isi.sort_order, isi.id
");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

$companyStmt = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1");
$company = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Dla faktury korygującej — pobierz numer faktury korygowanej
$correctionOfInvoiceNumber = null;
$correctionOfInvoiceId = (int)($invoice['correction_of_invoice_id'] ?? ($fakturowniaOptions['correction_of_invoice_id'] ?? 0));

if ($correctionOfInvoiceId > 0) {
    // Wystawiona z ERP — correction_of_invoice_id to invoices_sale.id oryginału
    $corrOrigStmt = $pdo->prepare("SELECT invoice_number FROM invoices_sale WHERE id = ? LIMIT 1");
    $corrOrigStmt->execute([$correctionOfInvoiceId]);
    $corrOrigRow = $corrOrigStmt->fetch(PDO::FETCH_ASSOC);
    if ($corrOrigRow) $correctionOfInvoiceNumber = $corrOrigRow['invoice_number'];
}

$correctionPaymentTerms = $correctionOfInvoiceId > 0
    ? sprutexCorrectionPaymentTerms($pdo, $correctionOfInvoiceId)
    : [];
if (!empty($correctionPaymentTerms['due_date'])) {
    $invoice['due_date'] = $correctionPaymentTerms['due_date'];
}
if (array_key_exists('payment_days', $correctionPaymentTerms)) {
    $invoice['payment_days'] = (int)$correctionPaymentTerms['payment_days'];
}
if (!empty($correctionPaymentTerms['payment_method'])) {
    $invoice['payment_method'] = $correctionPaymentTerms['payment_method'];
}

// Fallback dla faktur importowanych z Fakturowni: szukaj po from_invoice_id zapisanym w fakturownia_options_json
if ($correctionOfInvoiceNumber === null) {
    $fromFakturowniaId = (int)($fakturowniaOptions['from_invoice_id'] ?? $fakturowniaOptions['invoice_id'] ?? 0);
    if ($fromFakturowniaId > 0) {
        $fromFkStmt = $pdo->prepare(
            "SELECT inv.invoice_number
             FROM fakturownia_invoices fi
             JOIN invoices_sale inv ON inv.id = fi.erp_invoice_sale_id
             WHERE fi.fakturownia_id = ?
             LIMIT 1"
        );
        $fromFkStmt->execute([$fromFakturowniaId]);
        $fromFkRow = $fromFkStmt->fetch(PDO::FETCH_ASSOC);
        if ($fromFkRow) $correctionOfInvoiceNumber = $fromFkRow['invoice_number'];
    }
}

$autoIssue    = ($_GET['auto_issue'] ?? '') === '1';
$autoIssueJst = ($_GET['auto_issue_jst'] ?? '') === '1';
$autoIssueConfirmed = ($_GET['confirmed'] ?? '') === '1';

// Sprawdź czy faktura ma dane JST
$jstData = null;
try {
    $jstStmt = $pdo->prepare('SELECT * FROM invoice_sale_jst_data WHERE invoice_sale_id = ? LIMIT 1');
    $jstStmt->execute([$invoice_id]);
    $jstData = $jstStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

// ═══════ POST HANDLING ═══════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrfVerify()) $errors[] = 'Nieprawidłowy token sesji (CSRF).';
    $action = $_POST['action'];

    if (empty($errors) && $action === 'issue_jst' && $invoice['status'] === 'draft') {
        if (count($items) === 0) {
            $errors[] = "Nie można wystawić faktury bez pozycji.";
        } elseif (invoiceNeedsBankAccount($invoice) && trim((string)($invoice['bank_account'] ?? '')) === '') {
            $errors[] = "Nie można wystawić faktury przelewowej bez numeru konta bankowego.";
        } else {
            $service = new FakturowniaService($pdo);
            $result = $service->createJstInvoiceFromSalesInvoice((int)$invoice_id, false);
            if ($result['success']) {
                $stmt = $pdo->prepare("UPDATE invoices_sale SET status = 'issued' WHERE id = ?");
                $stmt->execute([$invoice_id]);
                logEvent("Utworzono fakturę JST w Fakturowni bez wysyłki do KSeF: {$invoice['invoice_number']}", 'INFO');
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'fakturownia_ready_jst'])); exit;
            }
            $errors[] = !empty($result['auth_failure'])
                ? 'Błąd autoryzacji Fakturownia API.'
                : 'Nie udało się wystawić faktury JST: ' . ($result['error'] ?? 'nieznany błąd');
        }
    } elseif (empty($errors) && $action === 'issue' && $invoice['status'] === 'draft') {
        if (count($items) === 0) {
            $errors[] = "Nie można wystawić faktury bez pozycji.";
        } elseif (invoiceNeedsBankAccount($invoice) && trim((string)($invoice['bank_account'] ?? '')) === '') {
            $errors[] = "Nie można wystawić faktury przelewowej bez numeru konta bankowego.";
        } else {
            $service = new FakturowniaService($pdo);
            $result = $service->createInvoiceFromSalesInvoice((int)$invoice_id, false);
            if ($result['success']) {
                $stmt = $pdo->prepare("UPDATE invoices_sale SET status = 'issued' WHERE id = ?");
                $stmt->execute([$invoice_id]);
                logEvent("Utworzono fakturę w Fakturowni bez wysyłki do KSeF: {$invoice['invoice_number']}", 'INFO');
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'fakturownia_ready'])); exit;
            }
            $errors[] = !empty($result['auth_failure'])
                ? 'Błąd autoryzacji Fakturownia API.'
                : 'Nie udało się wystawić: ' . ($result['error'] ?? 'nieznany błąd');
        }
    } elseif (empty($errors) && $action === 'send_to_ksef' && $invoice['status'] === 'issued') {
        $fkMapping = $pdo->prepare("SELECT fakturownia_id FROM fakturownia_invoices WHERE erp_invoice_sale_id = ? ORDER BY id DESC LIMIT 1");
        $fkMapping->execute([$invoice_id]);
        $fkId = (int)$fkMapping->fetchColumn();
        if ($fkId > 0) {
            $service = new FakturowniaService($pdo);
            $ksefResult = $service->sendToKsef($fkId);
            if ($ksefResult['success']) {
                $mailStatusAfterKsef = null;
                $shouldSendByEmail = !empty($fakturowniaOptions['send_by_email_after_issue']);
                if ($shouldSendByEmail) {
                    $recipientEmail = trim((string)($fakturowniaOptions['buyer_email'] ?? ''));
                    if ($recipientEmail === '') {
                        $recipientEmail = trim((string)($invoice['client_email'] ?? ''));
                    }
                    $mailResult = $service->sendInvoiceByEmail($fkId, $recipientEmail ?: null);
                    $mailStatusAfterKsef = $mailResult['success'] ? 'sent' : 'failed';
                    if ($mailResult['success'] && $recipientEmail && empty($invoice['client_email'])) {
                        $pdo->prepare("UPDATE investors SET email = ? WHERE id = ? AND (email IS NULL OR email = '')")
                            ->execute([$recipientEmail, $invoice['client_id']]);
                    }
                }

                $rp = ['id' => $invoice_id, 'success' => 'ksef_sent'];
                if ($mailStatusAfterKsef) $rp['mail'] = $mailStatusAfterKsef;
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', $rp)); exit;
            }
            $errors[] = 'Błąd wysyłki do KSeF: ' . ($ksefResult['error'] ?? 'nieznany');
        } else {
            $errors[] = 'Nie znaleziono mapowania Fakturowni dla tej faktury.';
        }
    } elseif (empty($errors) && $action === 'delete_fakturownia_before_ksef' && $invoice['status'] === 'issued') {
        $service = new FakturowniaService($pdo);
        $deleteResult = $service->deleteFakturowniaInvoiceBeforeKsef((int)$invoice_id);
        if ($deleteResult['success']) {
            header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'fakturownia_deleted_before_ksef']));
            exit;
        }
        $errors[] = !empty($deleteResult['auth_failure'])
            ? 'Błąd autoryzacji Fakturownia API.'
            : 'Nie udało się usunąć dokumentu z Fakturowni: ' . ($deleteResult['error'] ?? 'nieznany błąd');
    } elseif (empty($errors) && $action === 'mark_paid' && in_array($invoice['status'], ['issued', 'partially_paid'], true)) {
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $pdo->prepare("UPDATE invoices_sale SET status = 'paid', payment_date = ? WHERE id = ?")->execute([$payment_date, $invoice_id]);
        logEvent("Opłacona: {$invoice['invoice_number']}", 'INFO');
        syncStatusToFakturownia($pdo, $invoice_id, 'paid');
        header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'paid'])); exit;
    } elseif (empty($errors) && $action === 'mark_partially_paid' && $invoice['status'] === 'issued') {
        $pdo->prepare("UPDATE invoices_sale SET status = 'partially_paid' WHERE id = ?")->execute([$invoice_id]);
        logEvent("Częściowo opłacona: {$invoice['invoice_number']}", 'INFO');
        syncStatusToFakturownia($pdo, $invoice_id, 'partially_paid');
        header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'partially_paid'])); exit;
    } elseif (empty($errors) && $action === 'change_status') {
        $newStatus = $_POST['new_status'] ?? '';
        $allowedStatuses = ['draft', 'issued', 'paid', 'partially_paid', 'cancelled'];
        if (in_array($newStatus, $allowedStatuses, true)) {
            $upd = ['status' => $newStatus];
            if ($newStatus === 'paid') $upd['payment_date'] = date('Y-m-d');
            $pdo->prepare("UPDATE invoices_sale SET status = ?, payment_date = ? WHERE id = ?")->execute([$newStatus, $upd['payment_date'] ?? $invoice['payment_date'], $invoice_id]);
            logEvent("Zmiana statusu {$invoice['invoice_number']} na {$newStatus}", 'INFO');
            syncStatusToFakturownia($pdo, $invoice_id, $newStatus);
            header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'status_changed'])); exit;
        }
    } elseif (empty($errors) && $action === 'reset_to_issued') {
        $pdo->prepare("DELETE FROM invoice_sale_payments WHERE invoice_id = ?")->execute([$invoice_id]);
        $pdo->prepare("UPDATE invoices_sale SET status = 'issued', payment_date = NULL WHERE id = ?")->execute([$invoice_id]);
        syncStatusToFakturownia($pdo, $invoice_id, 'issued');
        logEvent("Reset FV {$invoice['invoice_number']} do 'wystawiona' — usunięto wszystkie wpłaty", 'INFO');
        header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'reset_to_issued'])); exit;
    } elseif (empty($errors) && $action === 'add_payment') {
        $paymentAmount = round((float)($_POST['payment_amount_net'] ?? 0), 2);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $paymentNotes = trim((string)($_POST['payment_notes'] ?? ''));
        if ($paymentAmount > 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $pdo->prepare(
                "INSERT INTO invoice_sale_payments (invoice_id, amount_net, payment_date, notes, source, created_by) VALUES (?, ?, ?, ?, 'manual', ?)"
            )->execute([$invoice_id, $paymentAmount, $paymentDate, $paymentNotes ?: null, $userId ?: null]);
            $autoStatus = recalcInvoicePaymentStatus($pdo, $invoice_id);
            syncStatusToFakturownia($pdo, $invoice_id, $autoStatus, $paymentAmount);
            logEvent("Wpłata {$paymentAmount} PLN do FV {$invoice['invoice_number']}, status: {$autoStatus}", 'INFO');
            header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'payment_added'])); exit;
        }
    } elseif (empty($errors) && $action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $pdo->prepare("DELETE FROM invoice_sale_payments WHERE id = ? AND invoice_id = ? AND source = 'manual'")->execute([$paymentId, $invoice_id]);
            $autoStatus = recalcInvoicePaymentStatus($pdo, $invoice_id);
            syncStatusToFakturownia($pdo, $invoice_id, $autoStatus);
            logEvent("Usunięto wpłatę ID:{$paymentId} z FV {$invoice['invoice_number']}, status: {$autoStatus}", 'INFO');
            header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'payment_deleted'])); exit;
        }
    } elseif (empty($errors) && $action === 'save_draft' && $invoice['status'] === 'draft') {
        $newItems = json_decode($_POST['invoice_items'] ?? '[]', true);
        if (empty($newItems)) {
            $errors[] = 'Faktura musi zawierać przynajmniej jedną pozycję.';
        } else {
            $archiveCorrectionInvoiceId = 0;
            $archiveStandardInvoiceId = 0;
            $newInvoiceNumber = trim($_POST['invoice_number'] ?? $invoice['invoice_number']);
            $currentInvoiceNumber = trim((string)($invoice['invoice_number'] ?? ''));
            $invoiceOptsBeforeSave = json_decode((string)($invoice['fakturownia_options_json'] ?? '{}'), true) ?: [];
            $isCorrectionBeforeSave = ($invoiceOptsBeforeSave['kind'] ?? '') === 'correction'
                || sprutexInvoiceSaleIsCorrection($invoice)
                || sprutexCorrectionNumberSeq($newInvoiceNumber) > 0;

            if ($isCorrectionBeforeSave && $newInvoiceNumber !== $currentInvoiceNumber && sprutexCorrectionNumberSeq($newInvoiceNumber) > 0) {
                $guard = sprutexFindReusableLocalCorrectionInvoice(
                    $pdo,
                    $newInvoiceNumber,
                    $correctionOfInvoiceId > 0 ? $correctionOfInvoiceId : null
                );
                if (!empty($guard['blocked'])) {
                    $reason = trim((string)($guard['blocking_reason'] ?? ''));
                    $errors[] = $reason !== ''
                        ? "Nie można zapisać numeru {$newInvoiceNumber}. {$reason}"
                        : "Nie można zapisać numeru {$newInvoiceNumber}, bo już istnieje w systemie.";
                } elseif (!empty($guard['reuse_invoice_id']) && (int)$guard['reuse_invoice_id'] !== (int)$invoice_id) {
                    $archiveCorrectionInvoiceId = (int)$guard['reuse_invoice_id'];
                }
            } elseif (!$isCorrectionBeforeSave && $newInvoiceNumber !== $currentInvoiceNumber) {
                $guard = sprutexFindReusableLocalStandardInvoice($pdo, $newInvoiceNumber);
                if (!empty($guard['blocked'])) {
                    $reason = trim((string)($guard['blocking_reason'] ?? ''));
                    $errors[] = $reason !== ''
                        ? "Nie można zapisać numeru {$newInvoiceNumber}. {$reason}"
                        : "Nie można zapisać numeru {$newInvoiceNumber}, bo już istnieje w systemie.";
                } elseif (!empty($guard['reuse_invoice_id']) && (int)$guard['reuse_invoice_id'] !== (int)$invoice_id) {
                    $archiveStandardInvoiceId = (int)$guard['reuse_invoice_id'];
                }
            }

            if (!empty($errors)) {
                // Błędy pokażą się pod nagłówkiem bez naruszania obecnego szkicu.
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($archiveCorrectionInvoiceId > 0) {
                        sprutexArchiveLocalCorrectionInvoiceNumber(
                            $pdo,
                            $archiveCorrectionInvoiceId,
                            'Zwolnienie numeru dla aktualnie edytowanej korekty ID ' . (int)$invoice_id
                        );
                    }
                    if ($archiveStandardInvoiceId > 0) {
                        sprutexArchiveLocalInvoiceNumber(
                            $pdo,
                            $archiveStandardInvoiceId,
                            'Zwolnienie numeru dla aktualnie edytowanej faktury ID ' . (int)$invoice_id
                        );
                    }

                    $updFields = [];
                    $updParams = [];

                    $editableFields = [
                        'invoice_number' => trim($_POST['invoice_number'] ?? $invoice['invoice_number']),
                        'issue_date' => trim($_POST['issue_date'] ?? $invoice['issue_date']),
                        'sale_date' => trim($_POST['sale_date'] ?? $invoice['sale_date']),
                        'due_date' => ($isCorrectionBeforeSave && !empty($correctionPaymentTerms['due_date']))
                            ? $correctionPaymentTerms['due_date']
                            : trim($_POST['due_date'] ?? $invoice['due_date']),
                        'payment_method' => ($isCorrectionBeforeSave && !empty($correctionPaymentTerms['payment_method']))
                            ? $correctionPaymentTerms['payment_method']
                            : trim($_POST['payment_method'] ?? $invoice['payment_method']),
                        'bank_account' => trim($_POST['bank_account'] ?? $invoice['bank_account'] ?? ''),
                        'place_of_issue' => trim($_POST['place_of_issue'] ?? $invoice['place_of_issue'] ?? ''),
                        'currency' => trim($_POST['currency'] ?? $invoice['currency']),
                        'split_payment' => isset($_POST['split_payment']) ? 1 : 0,
                        'notes' => trim($_POST['notes'] ?? $invoice['notes'] ?? ''),
                    ];
                    foreach ($editableFields as $col => $val) {
                        $updFields[] = "`{$col}` = ?";
                        $updParams[] = $val !== '' ? $val : null;
                    }
                    if ($isCorrectionBeforeSave && array_key_exists('payment_days', $correctionPaymentTerms)) {
                        $updFields[] = "`payment_days` = ?";
                        $updParams[] = (int)$correctionPaymentTerms['payment_days'];
                    }

                    $pdo->prepare("DELETE FROM invoice_sale_items WHERE invoice_id = ?")->execute([$invoice_id]);
                    $total_net = 0; $total_vat = 0; $total_gross = 0;
                    $invoiceOptsDraft = json_decode((string)($invoice['fakturownia_options_json'] ?? '{}'), true) ?: [];
                    $isCorr = ($invoiceOptsDraft['kind'] ?? '') === 'correction' || sprutexInvoiceSaleIsCorrection($invoice);
                    if ($isCorr) {
                        $invoiceOptsDraft['correction_reason'] = trim($_POST['correction_reason'] ?? ($invoiceOptsDraft['correction_reason'] ?? '')) ?: null;
                        $invoiceOptsDraft['corrected_content_before'] = trim($_POST['corrected_content_before'] ?? ($invoiceOptsDraft['corrected_content_before'] ?? '')) ?: null;
                        $invoiceOptsDraft['corrected_content_after'] = trim($_POST['corrected_content_after'] ?? ($invoiceOptsDraft['corrected_content_after'] ?? '')) ?: null;
                        $invoiceOptsDraft['kind'] = 'correction';
                        $updFields[] = "fakturownia_options_json = ?";
                        $updParams[] = json_encode($invoiceOptsDraft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $stmtItem = $pdo->prepare("INSERT INTO invoice_sale_items (invoice_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat, amount_gross, fakturownia_item_options_json, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $sortO = 0;
                    foreach ($newItems as $it) {
                        $total_net += (float)$it['amount_net']; $total_vat += (float)$it['amount_vat']; $total_gross += (float)$it['amount_gross'];
                        $itemOpts = ['product_id'=>null,'product_code'=>null,'gtu_code'=>null,'discount_percent'=>0,'discount'=>0];
                        if ($isCorr && !empty($it['correction_before']) && is_array($it['correction_before'])) {
                            $itemOpts['correction_before'] = $it['correction_before'];
                        }
                        $stmtItem->execute([$invoice_id, $it['name'], $it['quantity'], $it['unit'], $it['unit_price'], $it['vat_rate'],
                            $it['amount_net'], $it['amount_vat'], $it['amount_gross'],
                            json_encode($itemOpts, JSON_UNESCAPED_UNICODE), $sortO++]);
                    }

                    $financialEffectKind = $isCorr ? 'correction' : 'invoice';
                    $correctionEffectNet = null;
                    $correctionEffectVat = null;
                    $correctionEffectGross = null;
                    $correctionAttentionRequired = 0;
                    $correctionAttentionNote = null;
                    if ($isCorr) {
                        $correctionEffect = sprutexCalculateInvoiceSaleCorrectionEffect($newItems);
                        $correctionEffectNet = $correctionEffect['net'];
                        $correctionEffectVat = $correctionEffect['vat'];
                        $correctionEffectGross = $correctionEffect['gross'];
                        if (!empty($correctionEffect['has_missing_before'])) {
                            $correctionAttentionRequired = 1;
                            $correctionAttentionNote = mb_substr(
                                'Korekta wymaga sprawdzenia efektu finansowego: ' . implode(' | ', $correctionEffect['warnings']),
                                0,
                                500
                            );
                        }
                    }

                    $updFields[] = "amount_net = ?"; $updParams[] = $total_net;
                    $updFields[] = "amount_vat = ?"; $updParams[] = $total_vat;
                    $updFields[] = "amount_gross = ?"; $updParams[] = $total_gross;
                    $updFields[] = "financial_effect_kind = ?"; $updParams[] = $financialEffectKind;
                    $updFields[] = "correction_of_invoice_id = ?"; $updParams[] = $isCorr && $correctionOfInvoiceId > 0 ? $correctionOfInvoiceId : null;
                    $updFields[] = "correction_effect_net = ?"; $updParams[] = $correctionEffectNet;
                    $updFields[] = "correction_effect_vat = ?"; $updParams[] = $correctionEffectVat;
                    $updFields[] = "correction_effect_gross = ?"; $updParams[] = $correctionEffectGross;
                    $updFields[] = "correction_reason_text = ?"; $updParams[] = $isCorr ? ($invoiceOptsDraft['correction_reason'] ?? null) : null;
                    if ($isCorr) {
                        $updFields[] = "sync_attention_required = ?"; $updParams[] = $correctionAttentionRequired;
                        $updFields[] = "sync_attention_note = ?"; $updParams[] = $correctionAttentionNote;
                    }
                    $updParams[] = $invoice_id;

                    $pdo->prepare("UPDATE invoices_sale SET " . implode(', ', $updFields) . " WHERE id = ?")->execute($updParams);

                    // Aktualizacja danych JST (Podmiot2/Podmiot3) — tylko gdy rekord istnieje
                    if ($jstData) {
                        $jstUpdate = [
                            'jst_buyer_name'       => trim($_POST['jst_buyer_name'] ?? ''),
                            'jst_buyer_nip'        => trim($_POST['jst_buyer_nip'] ?? ''),
                            'jst_buyer_street'     => trim($_POST['jst_buyer_street'] ?? ''),
                            'jst_buyer_post_code'  => trim($_POST['jst_buyer_post_code'] ?? ''),
                            'jst_buyer_city'       => trim($_POST['jst_buyer_city'] ?? ''),
                            'jst_recipient_name'   => trim($_POST['jst_recipient_name'] ?? ''),
                            'jst_recipient_nip'    => trim($_POST['jst_recipient_nip'] ?? ''),
                            'jst_recipient_street' => trim($_POST['jst_recipient_street'] ?? ''),
                            'jst_recipient_post_code' => trim($_POST['jst_recipient_post_code'] ?? ''),
                            'jst_recipient_city'   => trim($_POST['jst_recipient_city'] ?? ''),
                            'jst_recipient_note'   => trim($_POST['jst_recipient_note'] ?? ''),
                        ];
                        $pdo->prepare(
                            "UPDATE invoice_sale_jst_data SET
                                jst_buyer_name = ?, jst_buyer_nip = ?, jst_buyer_street = ?,
                                jst_buyer_post_code = ?, jst_buyer_city = ?,
                                jst_recipient_name = ?, jst_recipient_nip = ?, jst_recipient_street = ?,
                                jst_recipient_post_code = ?, jst_recipient_city = ?, jst_recipient_note = ?
                             WHERE invoice_sale_id = ?"
                        )->execute([
                            $jstUpdate['jst_buyer_name'], $jstUpdate['jst_buyer_nip'], $jstUpdate['jst_buyer_street'],
                            $jstUpdate['jst_buyer_post_code'], $jstUpdate['jst_buyer_city'],
                            $jstUpdate['jst_recipient_name'], $jstUpdate['jst_recipient_nip'], $jstUpdate['jst_recipient_street'],
                            $jstUpdate['jst_recipient_post_code'], $jstUpdate['jst_recipient_city'], $jstUpdate['jst_recipient_note'],
                            $invoice_id,
                        ]);
                    }

                    $pdo->commit();
                    logEvent("Zaktualizowano fakturę roboczą: {$editableFields['invoice_number']}", 'INFO');
                    $afterSaveAction = $_POST['after_save_action'] ?? '';
                    if ($afterSaveAction === 'issue') {
                        header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'auto_issue' => '1', 'confirmed' => '1']));
                        exit;
                    }
                    if ($afterSaveAction === 'issue_jst') {
                        header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'auto_issue_jst' => '1', 'confirmed' => '1']));
                        exit;
                    }
                    header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'saved']));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "Błąd zapisu: " . $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "Błąd zapisu: " . $e->getMessage();
                }
            }
        }
    }
}

// Refresh data
$stmt = $pdo->prepare("SELECT inv.*, i.name as client_name, i.nip as client_nip, i.address as client_address, i.email as client_email FROM invoices_sale inv LEFT JOIN investors i ON inv.client_id = i.id WHERE inv.id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();
if (!empty($correctionPaymentTerms['due_date'])) {
    $invoice['due_date'] = $correctionPaymentTerms['due_date'];
}
if (array_key_exists('payment_days', $correctionPaymentTerms)) {
    $invoice['payment_days'] = (int)$correctionPaymentTerms['payment_days'];
}
if (!empty($correctionPaymentTerms['payment_method'])) {
    $invoice['payment_method'] = $correctionPaymentTerms['payment_method'];
}
$items_stmt = $pdo->prepare("SELECT isi.* FROM invoice_sale_items isi WHERE isi.invoice_id = ? ORDER BY isi.sort_order, isi.id");
$items_stmt->execute([$invoice_id]);
$items = $items_stmt->fetchAll();

$allocStmt = $pdo->prepare("SELECT a.*, p.name AS project_name, pcn.name AS node_name FROM invoice_sale_allocations a JOIN projects p ON p.id = a.project_id LEFT JOIN project_cost_nodes pcn ON pcn.id = a.cost_node_id WHERE a.invoice_id = ? ORDER BY a.id");
$allocStmt->execute([$invoice_id]);
$allocations = $allocStmt->fetchAll();

$ksefData = null;
try {
    $ksefStmt = $pdo->prepare("
        SELECT fakturownia_id, fakturownia_number, gov_id, gov_status, status, synced_at
        FROM fakturownia_invoices
        WHERE erp_invoice_sale_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $ksefStmt->execute([$invoice_id]);
    $ksefData = $ksefStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

$fakturowniaId = $ksefData ? (int)$ksefData['fakturownia_id'] : 0;
$govStatus = $ksefData['gov_status'] ?? null;
$govId = $ksefData['gov_id'] ?? null;
$syncAttentionRequired = !empty($invoice['sync_attention_required']);
$syncAttentionNote = trim((string)($invoice['sync_attention_note'] ?? ''));
$ksefAutoRefreshNotice = null;

if ($fakturowniaId > 0 && (!in_array((string)$govStatus, ['ok', 'demo_ok', 'send_error', 'server_error'], true) || empty($govId))) {
    try {
        $service = new FakturowniaService($pdo);
        $refreshResult = $service->getKsefStatus($fakturowniaId);
        if (!empty($refreshResult['success']) && !empty($refreshResult['data'])) {
            $newGovStatus = $refreshResult['data']['gov_status'] ?? $govStatus;
            $newGovId = $refreshResult['data']['gov_id'] ?? $govId;
            if ((string)$newGovStatus !== (string)$govStatus || (string)$newGovId !== (string)$govId) {
                $ksefAutoRefreshNotice = 'Status KSeF odświeżony z Fakturowni.';
            }

            $ksefStmt = $pdo->prepare("
                SELECT fakturownia_id, fakturownia_number, gov_id, gov_status, status, synced_at
                FROM fakturownia_invoices
                WHERE erp_invoice_sale_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $ksefStmt->execute([$invoice_id]);
            $ksefData = $ksefStmt->fetch(PDO::FETCH_ASSOC) ?: $ksefData;
            $fakturowniaId = $ksefData ? (int)$ksefData['fakturownia_id'] : 0;
            $govStatus = $ksefData['gov_status'] ?? null;
            $govId = $ksefData['gov_id'] ?? null;
        }
    } catch (Throwable $e) {
        if (function_exists('logEvent')) {
            logEvent('KSeF auto refresh failed for fakturownia_id=' . $fakturowniaId . ': ' . $e->getMessage(), 'WARNING');
        }
    }
}

$success = $_GET['success'] ?? '';
$mailStatus = $_GET['mail'] ?? '';
$isDraft = $invoice['status'] === 'draft';
$isIssued = in_array($invoice['status'], ['issued', 'paid', 'partially_paid'], true);
$userName = $_SESSION['worker_name'] ?? ($_SESSION['login'] ?? 'System');
$pmLabels = ['transfer'=>'Przelew','cash'=>'Gotówka','card'=>'Karta','other'=>'Inny'];

$statusLabels = [
    'draft' => ['Robocza', '#fef3c7', '#92400e'],
    'issued' => ['Wystawiona', '#dbeafe', '#1e40af'],
    'paid' => ['Opłacona', '#d1fae5', '#065f46'],
    'partially_paid' => ['Częściowo opłacona', '#fef3c7', '#92400e'],
    'cancelled' => ['Anulowana', '#f3f4f6', '#6b7280'],
    'overdue' => ['Zaległa', '#fee2e2', '#991b1b'],
];

$govStatusLabels = [
    'ok' => ['KSeF: przyjęta', '#d1fae5', '#065f46'],
    'demo_ok' => ['KSeF DEMO: przyjęta', '#dbeafe', '#1e40af'],
    'processing' => ['KSeF: wysyłanie...', '#fef3c7', '#92400e'],
    'demo_processing' => ['KSeF DEMO: wysyłanie...', '#fef3c7', '#92400e'],
    'send_error' => ['KSeF: błąd wysyłki', '#fee2e2', '#991b1b'],
    'server_error' => ['KSeF: błąd serwera', '#fee2e2', '#991b1b'],
    'not_connected' => ['KSeF: nie połączono', '#f3f4f6', '#6b7280'],
    'pending' => ['KSeF: oczekuje', '#fef3c7', '#92400e'],
];
$canSendToKsef = $fakturowniaId > 0
    && (empty($govId) || in_array((string)$govStatus, ['send_error', 'server_error'], true))
    && !in_array((string)$govStatus, ['ok', 'demo_ok', 'processing', 'demo_processing'], true);
$canDeleteFakturowniaBeforeKsef = $fakturowniaId > 0
    && empty($govId)
    && in_array((string)$govStatus, ['', 'pending', 'not_connected'], true);

$curStatus = $statusLabels[$invoice['status']] ?? ['Nieznany', '#f3f4f6', '#6b7280'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($invoice['invoice_number']); ?></title>
    <style>
        :root { --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; --success: #16a34a; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; }
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; }
        .alerts-dropdown { display: none !important; }

        .container { max-width: 1500px; margin: 0 auto; padding: 25px 30px; padding-bottom: 60px; }

        .hero { background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%); color: #fff; border-radius: 14px; padding: 22px 28px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .hero-left { display: flex; align-items: center; gap: 18px; }
        .hero-logo { flex-shrink: 0; }
        .hero-logo img { height: 72px; border-radius: 8px; background: #fff; padding: 6px; }
        .hero-logo-placeholder { width: 72px; height: 72px; border-radius: 8px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 800; color: #93c5fd; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 4px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero h1 { margin: 0 0 4px; font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 13px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .status-badge { display: inline-block; padding: 4px 14px; border-radius: 10px; font-size: 12px; font-weight: 600; }

        .card { background: #fff; border-radius: 12px; margin-bottom: 18px; border: 1px solid var(--border); }
        .card-body { padding: 24px; }
        .section-title { font-size: 16px; font-weight: 700; color: #0f172a; border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 14px; }

        .collapsible-header { padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; user-select: none; transition: background 0.15s; border-radius: 12px 12px 0 0; }
        .collapsible-header:hover { background: #f8fafc; }
        .collapsible-header h3 { font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .collapsible-header .badge-sm { font-size: 10px; padding: 2px 8px; border-radius: 8px; font-weight: 600; }
        .collapse-arrow { font-size: 12px; color: var(--text-muted); transition: transform 0.2s; }
        .collapse-arrow.open { transform: rotate(180deg); }
        .collapsible-body { display: none; border-top: 1px solid var(--border); }
        .collapsible-body.open { display: block; }

        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .info-item label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 3px; font-weight: 600; }
        .info-item .value { font-size: 15px; color: #1a1a1a; font-weight: 500; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        @media (max-width: 1100px) {
            .form-grid { grid-template-columns: 1fr 1fr; }
            .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        }
        @media (max-width: 700px) {
            .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
        }
        .form-group { display: flex; flex-direction: column; min-width: 0; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-group input, .form-group select, .form-group textarea { padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; transition: all 0.15s; width: 100%; background: #fff; font-family: inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .form-group textarea { resize: vertical; min-height: 60px; }

        .checkbox-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
        .checkbox-row input[type="checkbox"] { width: auto; margin: 0; }
        .checkbox-row label { font-size: 13px; font-weight: 500; color: #374151; text-transform: none; letter-spacing: 0; margin: 0; cursor: pointer; }

        .items-table { width: 100%; border-collapse: collapse; }
        .items-table thead th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; border-bottom: 2px solid var(--border); background: #f8fafc; }
        .items-table tbody td { padding: 10px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .items-table input, .items-table select { padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; width: 100%; background: #fff; }
        .items-table input:focus, .items-table select:focus { outline: none; border-color: var(--success); }
        .item-remove { background: none; border: none; color: #dc2626; cursor: pointer; font-size: 16px; padding: 4px 8px; border-radius: 4px; }
        .item-remove:hover { background: #fee2e2; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }

        .totals-grid { display: flex; justify-content: flex-end; margin-top: 16px; }
        .totals-box { min-width: 220px; }
        .total-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
        .total-row.grand { font-size: 18px; font-weight: 800; color: var(--primary-blue); border-top: 2px solid var(--primary-blue); padding-top: 10px; margin-top: 6px; }

        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; }
        .btn-primary { background: linear-gradient(135deg, var(--success), #15803d); color: #fff; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .btn-outline:hover { border-color: #9ca3af; }
        .btn-blue { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
        .btn-blue:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .alert-info { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }

        .status-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-weight: 600; background: #fff; cursor: pointer; }
        .status-select:focus { outline: none; border-color: var(--primary-blue); }

        .dropdown-wrap { position: relative; display: inline-block; }
        .dropdown-toggle { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .dropdown-menu { display: none; position: absolute; top: 100%; left: 0; z-index: 200; min-width: 220px; background: #fff; border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); margin-top: 4px; overflow: hidden; }
        .dropdown-menu.show { display: block; }
        .dropdown-menu a, .dropdown-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 10px 16px; font-size: 13px; font-weight: 500; color: #374151; text-decoration: none; border: none; background: none; cursor: pointer; text-align: left; transition: background 0.1s; }
        .dropdown-menu a:hover, .dropdown-menu button:hover { background: #f0fdf4; color: var(--success); }
        .dropdown-menu .sep { height: 1px; background: var(--border); margin: 2px 0; }
        .dropdown-menu .dd-danger:hover { background: #fef2f2; color: var(--danger); }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
        <!-- HERO z logo wyrównanym z tytułem -->
        <div class="hero">
            <div class="hero-left">
                <div class="hero-logo">
                    <?php if (!empty($company['logo_path'])): ?>
                        <img src="/<?php echo e($company['logo_path']); ?>" alt="Logo">
                    <?php else: ?>
                        <div class="hero-logo-placeholder">S</div>
                    <?php endif; ?>
                </div>
                <div class="hero-info">
                    <div class="hero-breadcrumb">
                        <a href="<?php echo url('dashboard'); ?>">Panel</a> /
                        <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                        <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>">Faktury</a> /
                        <?php echo e($invoice['invoice_number']); ?>
                    </div>
                    <h1>
                        <?php echo e($invoice['invoice_number']); ?>
                        <span class="status-badge" style="background:<?php echo $curStatus[1]; ?>; color:<?php echo $curStatus[2]; ?>;">
                            <?php echo $curStatus[0]; ?>
                        </span>
                        <?php if ($govStatus && isset($govStatusLabels[$govStatus])): ?>
                            <span class="status-badge" style="background:<?php echo $govStatusLabels[$govStatus][1]; ?>; color:<?php echo $govStatusLabels[$govStatus][2]; ?>;"><?php echo $govStatusLabels[$govStatus][0]; ?></span>
                        <?php endif; ?>
                    </h1>
                    <?php if ($govId): ?><p style="font-size:11px; color:#93c5fd;">KSeF: <?php echo e($govId); ?></p><?php endif; ?>
                    <p><?php echo e($invoice['client_name']); ?> &bull; <?php echo formatMoney($invoice['amount_gross']); ?> <?php echo e($invoice['currency']); ?></p>
                </div>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn-hero-secondary">← Lista faktur</a>
                <button type="button" class="btn-hero-secondary" onclick="openPreview()">Podgląd</button>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if ($success === 'ksef_sent'): ?>
            <div class="alert alert-success">Faktura została wysłana do KSeF. Status może się zaktualizować w ciągu kilku sekund.</div>
            <?php if ($mailStatus === 'sent'): ?><div class="alert alert-success">Wysłana e-mailem po wysyłce do KSeF.</div><?php elseif ($mailStatus === 'failed'): ?><div class="alert alert-error">KSeF wysłany, ale wysyłka e-mail nie powiodła się.</div><?php endif; ?>
        <?php elseif ($success === 'created'): ?>
            <div class="alert alert-success">Faktura zapisana jako robocza. Sprawdź dane i kliknij "Podgląd", a gdy wszystko OK — "Wystaw w Fakturowni".</div>
        <?php elseif ($success === 'existing_correction_draft'): ?>
            <div class="alert alert-success">Otworzono istniejącą roboczą korektę. Nie tworzymy kolejnego numeru, dopóki ta korekta nie zostanie wystawiona albo anulowana lokalnie.</div>
        <?php elseif ($success === 'saved'): ?>
            <div class="alert alert-success">Faktura została zaktualizowana.</div>
        <?php elseif ($success === 'fakturownia_ready'): ?>
            <div class="alert alert-success">Fakturownia przyjęła dokument. Otwórz PDF z Fakturowni, sprawdź dane i dopiero wtedy kliknij "Wyślij do KSeF".</div>
        <?php elseif ($success === 'fakturownia_ready_jst'): ?>
            <div class="alert alert-success" style="border-left-color:#7c3aed;background:#faf5ff;color:#4c1d95;">Fakturownia przyjęła dokument JST. Otwórz PDF z Fakturowni, sprawdź Podmiot2/Podmiot3 i dopiero wtedy kliknij "Wyślij do KSeF".</div>
        <?php elseif ($success === 'fakturownia_deleted_before_ksef'): ?>
            <div class="alert alert-success">Dokument usunięty z Fakturowni przed wysyłką do KSeF. Faktura wróciła do edycji jako szkic.</div>
        <?php elseif ($success === 'issued'): ?>
            <div class="alert alert-success">Faktura wystawiona w Fakturowni.</div>
            <?php if ($mailStatus === 'sent'): ?><div class="alert alert-success">Wysłana e-mailem.</div><?php elseif ($mailStatus === 'failed'): ?><div class="alert alert-error">Wystawiona, ale wysyłka e-mail nie powiodła się.</div><?php endif; ?>
        <?php elseif ($success === 'issued_jst'): ?>
            <div class="alert alert-success" style="border-left-color:#7c3aed;background:#faf5ff;color:#4c1d95;">Faktura JST wystawiona w Fakturowni z danymi Nabywcy JST (Podmiot2) i Odbiorcy (Podmiot3) i wysłana do KSeF.</div>
        <?php elseif ($success === 'created_jst'): ?>
            <div class="alert alert-success" style="border-left-color:#7c3aed;background:#faf5ff;color:#4c1d95;">Faktura JST zapisana jako robocza. Sprawdź dane i kliknij "Wystaw w Fakturowni (JST)".</div>
        <?php elseif ($success === 'paid'): ?>
            <div class="alert alert-success">Faktura oznaczona jako opłacona!</div>
        <?php elseif ($success === 'partially_paid'): ?>
            <div class="alert alert-success">Faktura oznaczona jako częściowo opłacona.</div>
        <?php elseif ($success === 'status_changed'): ?>
            <div class="alert alert-success">Status faktury został zmieniony.</div>
        <?php elseif ($success === 'payment_added'): ?>
            <div class="alert alert-success">Wpłata została zapisana. Status zaktualizowany automatycznie.</div>
        <?php elseif ($success === 'payment_deleted'): ?>
            <div class="alert alert-success">Wpłata usunięta. Status zaktualizowany automatycznie.</div>
        <?php endif; ?>
        <?php if ($ksefAutoRefreshNotice): ?>
            <div class="alert alert-success"><?php echo e($ksefAutoRefreshNotice); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błędy:</strong>
                <ul style="margin: 6px 0 0 18px;"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($isDraft): ?>
        <!-- ═══════ TRYB EDYCJI (DRAFT) ═══════ -->
        <form method="POST" id="editForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_draft">
            <input type="hidden" name="after_save_action" id="after_save_action" value="">
            <input type="hidden" name="invoice_items" id="edit_invoice_items" value="">

            <!-- KAFELEK 1: Dane dokumentu (rozwijany) -->
            <div class="card">
                <div class="collapsible-header" onclick="toggleCollapse(this)">
                    <h3>
                        Dane dokumentu
                        <span class="badge-sm" style="background:#e0e7ff;color:#3730a3;">edytowalne</span>
                    </h3>
                    <span class="collapse-arrow open">&#9660;</span>
                </div>
                <div class="collapsible-body open">
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Numer faktury</label>
                                <input type="text" name="invoice_number" value="<?php echo e($invoice['invoice_number']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Klient</label>
                                <input type="text" value="<?php echo e($invoice['client_name']); ?><?php if ($invoice['client_nip']): ?> (NIP: <?php echo e($invoice['client_nip']); ?>)<?php endif; ?>" disabled style="background:#f8fafc;">
                            </div>
                            <div class="form-group">
                                <label>Data wystawienia</label>
                                <input type="date" name="issue_date" value="<?php echo e($invoice['issue_date']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Data sprzedaży</label>
                                <input type="date" name="sale_date" value="<?php echo e($invoice['sale_date']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Termin płatności</label>
                                <input type="date" name="due_date" value="<?php echo e($invoice['due_date']); ?>" required <?php echo $correctionOfInvoiceId > 0 ? 'readonly' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>Sposób płatności</label>
                                <select name="payment_method">
                                    <?php foreach ($pmLabels as $val => $lbl): ?>
                                        <option value="<?php echo $val; ?>" <?php echo $invoice['payment_method'] === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Konto bankowe</label>
                                <input type="text" name="bank_account" value="<?php echo e($invoice['bank_account'] ?? ''); ?>" placeholder="Nr konta">
                            </div>
                            <div class="form-group">
                                <label>Waluta</label>
                                <select name="currency">
                                    <?php foreach (['PLN','EUR','USD','GBP','CHF'] as $c): ?>
                                        <option value="<?php echo $c; ?>" <?php echo $invoice['currency'] === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Miejsce wystawienia</label>
                                <input type="text" name="place_of_issue" value="<?php echo e($invoice['place_of_issue'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <div class="checkbox-row" style="margin-top:22px;">
                                    <input type="checkbox" name="split_payment" id="split_payment" <?php echo !empty($invoice['split_payment']) ? 'checked' : ''; ?>>
                                    <label for="split_payment">Mechanizm podzielonej płatności</label>
                                </div>
                            </div>
                            <?php if (($fakturowniaOptions['kind'] ?? '') === 'correction'): ?>
                                <?php
                                    $correctionReasonForm = trim((string)($fakturowniaOptions['correction_reason'] ?? ''));
                                    $correctedBeforeForm = trim((string)($fakturowniaOptions['corrected_content_before'] ?? ''));
                                    $correctedAfterForm = trim((string)($fakturowniaOptions['corrected_content_after'] ?? ''));
                                ?>
                                <div class="form-group full-width">
                                    <label>Powód korekty</label>
                                    <input type="text" name="correction_reason" value="<?php echo e($correctionReasonForm); ?>" placeholder="np. błędna data, błędna pozycja, zmiana danych">
                                </div>
                                <div class="form-group">
                                    <label>Treść korygowana</label>
                                    <input type="text" name="corrected_content_before" value="<?php echo e($correctedBeforeForm); ?>" placeholder="np. opis błędnej treści na fakturze">
                                </div>
                                <div class="form-group">
                                    <label>Treść prawidłowa</label>
                                    <input type="text" name="corrected_content_after" value="<?php echo e($correctedAfterForm); ?>" placeholder="np. opis prawidłowej treści po korekcie">
                                </div>
                            <?php endif; ?>
                            <div class="form-group full-width">
                                <label>Uwagi</label>
                                <textarea name="notes" rows="3"><?php echo e($invoice['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($jstData): ?>
            <!-- KAFELEK JST: Nabywca (Podmiot2) i Odbiorca (Podmiot3) -->
            <div class="card" style="border-left:4px solid #7c3aed;">
                <div class="collapsible-header" onclick="toggleCollapse(this)">
                    <h3>
                        Dane JST — Nabywca (Podmiot2) i Odbiorca (Podmiot3)
                        <span class="badge-sm" style="background:#ede9fe;color:#7c3aed;">edytowalne</span>
                    </h3>
                    <span class="collapse-arrow open">&#9660;</span>
                </div>
                <div class="collapsible-body open">
                    <div class="card-body">
                        <div style="background:#faf5ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:18px;font-size:13px;color:#4c1d95;">
                            <strong>Nabywca (Podmiot2)</strong> — jednostka samorządu terytorialnego, np. Miasto Poznań.<br>
                            <strong>Odbiorca (Podmiot3)</strong> — jednostka odbierająca fakturę, np. POSiR. Pozostaw puste jeśli brak.
                        </div>

                        <div class="section-title" style="color:#7c3aed;">Nabywca — Podmiot2 (JST)</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nazwa nabywcy JST</label>
                                <input type="text" name="jst_buyer_name" value="<?php echo e($jstData['jst_buyer_name'] ?? ''); ?>" placeholder="np. Miasto Poznań" required>
                            </div>
                            <div class="form-group">
                                <label>NIP nabywcy JST</label>
                                <input type="text" name="jst_buyer_nip" value="<?php echo e($jstData['jst_buyer_nip'] ?? ''); ?>" placeholder="np. 2090001440">
                            </div>
                            <div class="form-group">
                                <label>Ulica i nr nabywcy JST</label>
                                <input type="text" name="jst_buyer_street" value="<?php echo e($jstData['jst_buyer_street'] ?? ''); ?>" placeholder="np. Plac Kolegiacki 17">
                            </div>
                            <div class="form-group">
                                <label>Kod pocztowy nabywcy JST</label>
                                <input type="text" name="jst_buyer_post_code" value="<?php echo e($jstData['jst_buyer_post_code'] ?? ''); ?>" placeholder="np. 61-841">
                            </div>
                            <div class="form-group">
                                <label>Miasto nabywcy JST</label>
                                <input type="text" name="jst_buyer_city" value="<?php echo e($jstData['jst_buyer_city'] ?? ''); ?>" placeholder="np. Poznań">
                            </div>
                        </div>

                        <div class="section-title" style="color:#7c3aed;margin-top:10px;">Odbiorca — Podmiot3 (opcjonalnie)</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nazwa odbiorcy</label>
                                <input type="text" name="jst_recipient_name" value="<?php echo e($jstData['jst_recipient_name'] ?? ''); ?>" placeholder="np. Poznańskie Ośrodki Sportu i Rekreacji">
                            </div>
                            <div class="form-group">
                                <label>NIP odbiorcy</label>
                                <input type="text" name="jst_recipient_nip" value="<?php echo e($jstData['jst_recipient_nip'] ?? ''); ?>" placeholder="np. 7831044564">
                            </div>
                            <div class="form-group">
                                <label>Ulica i nr odbiorcy</label>
                                <input type="text" name="jst_recipient_street" value="<?php echo e($jstData['jst_recipient_street'] ?? ''); ?>" placeholder="np. ul. Jana Spychalskiego 34">
                            </div>
                            <div class="form-group">
                                <label>Kod pocztowy odbiorcy</label>
                                <input type="text" name="jst_recipient_post_code" value="<?php echo e($jstData['jst_recipient_post_code'] ?? ''); ?>" placeholder="np. 61-553">
                            </div>
                            <div class="form-group">
                                <label>Miasto odbiorcy</label>
                                <input type="text" name="jst_recipient_city" value="<?php echo e($jstData['jst_recipient_city'] ?? ''); ?>" placeholder="np. Poznań">
                            </div>
                            <div class="form-group full-width">
                                <label>Notatka / oddział odbiorcy</label>
                                <input type="text" name="jst_recipient_note" value="<?php echo e($jstData['jst_recipient_note'] ?? ''); ?>" placeholder="np. POSiR - Oddział Chwiałka">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- POZYCJE -->
            <div class="card">
                <div class="card-body">
                    <div class="section-title">Pozycje faktury</div>
                    <div style="overflow-x: auto;">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th style="min-width:220px;">Nazwa</th>
                                    <th style="width:70px;" class="text-center">Ilość</th>
                                    <th style="width:70px;" class="text-center">Jedn.</th>
                                    <th style="width:110px;" class="text-right">Cena netto</th>
                                    <th style="width:70px;" class="text-center">VAT</th>
                                    <th style="width:100px;" class="text-right">Netto</th>
                                    <th style="width:90px;" class="text-right">VAT</th>
                                    <th style="width:100px;" class="text-right">Brutto</th>
                                    <th style="width:36px;"></th>
                                </tr>
                            </thead>
                            <tbody id="edit-items-tbody"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-right" style="font-weight:700; font-size:13px; color:#64748b;">RAZEM:</td>
                                    <td class="text-right" style="font-weight:700;" id="edit-total-net">0,00</td>
                                    <td class="text-right" style="font-weight:700;" id="edit-total-vat">0,00</td>
                                    <td class="text-right" style="font-weight:800; color:#1e3a8a;" id="edit-total-gross">0,00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div style="display:flex; gap:10px; margin-top:12px;">
                        <button type="button" onclick="addEditItem()" class="btn btn-sm btn-outline">+ Dodaj pozycję</button>
                    </div>
                </div>
            </div>

            <!-- AKCJE DRAFT -->
            <div class="card">
                <div class="card-body">
                    <div class="alert alert-info" style="margin-bottom:14px;">
                        <strong>Faktura robocza.</strong> Edytuj dane powyżej, kliknij "Podgląd" aby sprawdzić. Gdy OK — utwórz dokument w Fakturowni, sprawdź PDF z Fakturowni i dopiero wyślij do KSeF.
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn btn-primary" onclick="beforeSaveDraft()">Zapisz zmiany</button>
                        <button type="button" class="btn btn-outline" onclick="openPreview()">Podgląd faktury</button>

                        <div class="dropdown-wrap">
                            <button type="button" class="dropdown-toggle btn-blue" onclick="toggleDropdown(this)" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;">
                                Wystaw w Fakturowni &#9660;
                            </button>
                            <div class="dropdown-menu" id="draft-dropdown">
                                <button type="button" onclick="issueInvoice()">Wystaw w Fakturowni</button>
                                <?php if ($jstData): ?>
                                <button type="button" onclick="issueInvoiceJst()" style="color:#7c3aed;font-weight:600;">Wystaw w Fakturowni (JST)</button>
                                <?php endif; ?>
                                <div class="sep"></div>
                                <button type="button" class="dd-danger" onclick="submitDraftDelete()">Usuń fakturę</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <form method="POST" action="<?php echo url('finanse.faktury-sprzedazowe.delete'); ?>" id="draft-delete-form" style="display:none;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice_id; ?>">
            <input type="hidden" name="delete_reason" value="Usunięcie/anulowanie roboczej faktury z edycji">
        </form>

        <?php else: ?>
        <!-- ═══════ TRYB READONLY (ISSUED/PAID) ═══════ -->

        <!-- KAFELEK 1: Dane dokumentu (rozwijany) -->
        <div class="card">
            <div class="collapsible-header" onclick="toggleCollapse(this)">
                <h3>Dane dokumentu</h3>
                <span class="collapse-arrow open">&#9660;</span>
            </div>
            <div class="collapsible-body open">
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Klient</label>
                            <div class="value"><?php echo e($invoice['client_name']); ?></div>
                            <?php if ($invoice['client_nip']): ?><div style="font-size:12px; color:#94a3b8;">NIP: <?php echo e($invoice['client_nip']); ?></div><?php endif; ?>
                        </div>
                        <div class="info-item"><label>Data wystawienia</label><div class="value"><?php echo formatDate($invoice['issue_date']); ?></div></div>
                        <div class="info-item"><label>Data sprzedaży</label><div class="value"><?php echo formatDate($invoice['sale_date']); ?></div></div>
                        <div class="info-item"><label>Termin płatności</label><div class="value"><?php echo formatDate($invoice['due_date']); ?></div></div>
                        <div class="info-item"><label>Płatność</label><div class="value"><?php echo e($pmLabels[$invoice['payment_method']] ?? $invoice['payment_method']); ?></div></div>
                        <div class="info-item"><label>Waluta</label><div class="value"><?php echo e($invoice['currency']); ?></div></div>
                        <?php if (!empty($invoice['bank_account'])): ?>
                        <div class="info-item" style="grid-column:1/-1;"><label>Konto bankowe</label><div class="value"><?php echo e($invoice['bank_account']); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['split_payment'])): ?>
                        <div class="info-item"><label>Podzielona płatność</label><div class="value" style="color:#92400e;">Tak</div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- POZYCJE (readonly) -->
        <div class="card">
            <div class="card-body">
                <div class="section-title">Pozycje faktury</div>
                <?php if (count($items) > 0): ?>
                <table class="items-table">
                    <thead><tr><th>Nazwa</th><th class="text-center">Ilość</th><th class="text-center">Jedn.</th><th class="text-right">Cena netto</th><th class="text-center">VAT</th><th class="text-right">Netto</th><th class="text-right">VAT</th><th class="text-right">Brutto</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?php echo e($item['item_name']); ?></strong></td>
                            <td class="text-center"><?php echo number_format($item['quantity'], 2, ',', ' '); ?></td>
                            <td class="text-center"><?php echo e($item['unit']); ?></td>
                            <td class="text-right"><?php echo formatMoney($item['unit_price_net']); ?></td>
                            <td class="text-center"><?php echo e($item['vat_rate']); ?>%</td>
                            <td class="text-right"><?php echo formatMoney($item['amount_net']); ?></td>
                            <td class="text-right"><?php echo formatMoney($item['amount_vat']); ?></td>
                            <td class="text-right" style="font-weight:600; color:var(--success);"><?php echo formatMoney($item['amount_gross']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <div class="totals-grid">
                    <div class="totals-box">
                        <div class="total-row"><span>Netto:</span><span><?php echo formatMoney($invoice['amount_net']); ?></span></div>
                        <div class="total-row"><span>VAT:</span><span><?php echo formatMoney($invoice['amount_vat']); ?></span></div>
                        <div class="total-row grand"><span>RAZEM:</span><span><?php echo formatMoney($invoice['amount_gross']); ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($invoice['notes']): ?>
        <div class="card">
            <div class="card-body">
                <div class="section-title">Uwagi</div>
                <div style="font-size:14px; color:#475569;"><?php echo nl2br(e($invoice['notes'])); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- KAFELEK: Alokacja do projektów -->
        <div class="card" style="border-left:4px solid #8b5cf6;">
            <div class="collapsible-header" onclick="toggleCollapse(this)">
                <h3>Alokacja do projektów
                    <?php if (count($allocations) > 0): ?>
                        <span class="badge-sm" style="background:#ede9fe;color:#6d28d9;"><?php echo count($allocations); ?> projekt<?php echo count($allocations) == 1 ? '' : (count($allocations) < 5 ? 'y' : 'ów'); ?></span>
                    <?php endif; ?>
                </h3>
                <span class="collapsible-arrow">▾</span>
            </div>
            <div class="collapsible-body">
                <?php if (count($allocations) > 0): ?>
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px;">
                    <thead><tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;">Projekt</th><th style="padding:8px;text-align:right;">Kwota netto</th><th style="padding:8px;text-align:left;">Opis</th></tr></thead>
                    <tbody>
                    <?php $allocSum = 0; foreach ($allocations as $al): $allocSum += (float)$al['amount_net']; ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px;"><?php echo e($al['project_name']); ?><?php if ($al['node_name']): ?> <span style="color:#9ca3af;">/ <?php echo e($al['node_name']); ?></span><?php endif; ?></td>
                            <td style="padding:8px;text-align:right;font-weight:600;"><?php echo formatMoney((float)$al['amount_net']); ?></td>
                            <td style="padding:8px;color:#6b7280;"><?php echo e($al['description'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr style="border-top:2px solid #e2e8f0;">
                        <td style="padding:8px;font-weight:700;">Suma</td>
                        <td style="padding:8px;text-align:right;font-weight:700;"><?php echo formatMoney($allocSum); ?> / <?php echo formatMoney((float)$invoice['amount_net']); ?></td>
                        <td></td>
                    </tr></tfoot>
                </table>
                <?php else: ?>
                <p style="color:#9ca3af;font-size:13px;margin:8px 0;">Brak alokacji — przypisz z listy FV (przycisk "Alokuj do projektu").</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- KAFELEK: Wpłaty -->
        <?php if ($isIssued):
            $paymentsStmt = $pdo->prepare('SELECT * FROM invoice_sale_payments WHERE invoice_id = ? ORDER BY payment_date DESC, id DESC');
            $paymentsStmt->execute([$invoice_id]);
            $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
            $totalPaidNet = 0;
            foreach ($payments as $p) $totalPaidNet += (float)$p['amount_net'];
            $invNetAmt = (float)$invoice['amount_net'];
            $paidPct = $invNetAmt > 0 ? min(100, round(($totalPaidNet / $invNetAmt) * 100)) : 0;
            $remaining = max(0, $invNetAmt - $totalPaidNet);

            $sourceLabels = ['manual' => 'Ręczna', 'fk_sync' => 'Sync FK', 'retention_settled' => 'Retencja'];
        ?>
        <div class="card">
            <div class="collapsible-header" onclick="toggleCollapse(this)">
                <h3>
                    Wpłaty
                    <?php if ($totalPaidNet > 0): ?>
                        <span class="badge-sm" style="background:<?php echo $paidPct >= 100 ? '#d4edda' : '#fef3c7'; ?>;color:<?php echo $paidPct >= 100 ? '#155724' : '#92400e'; ?>;">
                            <?php echo $paidPct; ?>% — <?php echo formatMoney($totalPaidNet); ?> / <?php echo formatMoney($invNetAmt); ?> netto
                        </span>
                    <?php else: ?>
                        <span class="badge-sm" style="background:#e2e8f0;color:#475569;">Brak wpłat</span>
                    <?php endif; ?>
                </h3>
                <span class="collapse-arrow open">&#9660;</span>
            </div>
            <div class="collapsible-body open">
                <div class="card-body">
                    <?php if ($totalPaidNet > 0): ?>
                    <div style="margin-bottom:14px;">
                        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                            <div style="height:100%;width:<?php echo $paidPct; ?>%;background:<?php echo $paidPct >= 100 ? '#10b981' : '#f59e0b'; ?>;border-radius:4px;transition:width 0.3s;"></div>
                        </div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;">
                            Zapłacono: <strong><?php echo formatMoney($totalPaidNet); ?></strong> netto
                            | Pozostało: <strong><?php echo formatMoney($remaining); ?></strong> netto
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($payments) > 0): ?>
                    <table class="items-table" style="margin-bottom:14px;">
                        <thead>
                            <tr><th>Data wpływu</th><th>Kwota netto</th><th>Źródło</th><th>Notatka</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?php echo formatDate($pay['payment_date']); ?></td>
                                <td><strong><?php echo formatMoney($pay['amount_net']); ?></strong></td>
                                <td><span class="badge-sm" style="background:#f1f5f9;color:#475569;"><?php echo $sourceLabels[$pay['source']] ?? $pay['source']; ?></span></td>
                                <td style="font-size:12px;color:#64748b;"><?php echo e($pay['notes'] ?? '—'); ?></td>
                                <td>
                                    <?php if ($pay['source'] === 'manual'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Usunąć tę wpłatę?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$pay['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="color:#ef4444;border-color:#fecaca;background:#fff;">Usuń</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:8px;">Dodaj wpłatę</div>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="add_payment">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                <div>
                                    <label style="font-size:11px;font-weight:600;color:#94a3b8;display:block;margin-bottom:2px;">Kwota netto</label>
                                    <input type="number" name="payment_amount_net" step="0.01" min="0.01"
                                           value="<?php echo number_format($remaining, 2, '.', ''); ?>" required
                                           style="width:100%;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;">
                                </div>
                                <div>
                                    <label style="font-size:11px;font-weight:600;color:#94a3b8;display:block;margin-bottom:2px;">Data wpływu</label>
                                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required
                                           style="width:100%;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;">
                                </div>
                                <div style="grid-column:1/-1;">
                                    <label style="font-size:11px;font-weight:600;color:#94a3b8;display:block;margin-bottom:2px;">Notatka</label>
                                    <input type="text" name="payment_notes" maxlength="500" placeholder="Opcjonalnie"
                                           style="width:100%;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-blue" style="margin-top:8px;">Zapisz wpłatę</button>
                        </form>
                    </div>

                    <?php if ($totalPaidNet > 0): ?>
                    <div style="border-top:1px solid #e2e8f0;margin-top:14px;padding-top:14px;">
                        <form method="POST" onsubmit="return confirm('Usunąć wszystkie ręczne wpłaty i cofnąć status do Wystawiona?\nZmiana trafi też do Fakturowni.');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="reset_to_issued">
                            <button type="submit" class="btn btn-sm" style="color:#ef4444;border-color:#fecaca;background:#fff;">
                                Zeruj — cofnij do wystawionej
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- KAFELEK 2: Płatności i KSeF (rozwijany) -->
        <?php if ($isIssued && $ksefData): ?>
        <div class="card">
            <div class="collapsible-header" onclick="toggleCollapse(this)">
                <h3>
                    Płatności i KSeF
                    <?php if ($govStatus && isset($govStatusLabels[$govStatus])): ?>
                        <span class="badge-sm" style="background:<?php echo $govStatusLabels[$govStatus][1]; ?>; color:<?php echo $govStatusLabels[$govStatus][2]; ?>;"><?php echo $govStatusLabels[$govStatus][0]; ?></span>
                    <?php endif; ?>
                </h3>
                <span class="collapse-arrow open">&#9660;</span>
            </div>
            <div class="collapsible-body open">
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Status synchronizacji</label>
                            <div class="value">
                                <?php if ($syncAttentionRequired): ?>
                                    <span style="display:inline-block; padding:4px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fef3c7; color:#92400e;" title="<?php echo e($syncAttentionNote ?: 'Wymaga ręcznej weryfikacji'); ?>">Wymaga ręcznej weryfikacji</span>
                                <?php elseif (!$ksefData): ?>
                                    <span style="display:inline-block; padding:4px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fee2e2; color:#991b1b;">Brak synchronizacji z Fakturownią</span>
                                <?php elseif ($fakturowniaId > 0 && $govId): ?>
                                    <span style="display:inline-block; padding:4px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#d1fae5; color:#065f46;">Mapowanie po ID lokalnej faktury</span>
                                <?php else: ?>
                                    <span style="display:inline-block; padding:4px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fef3c7; color:#92400e;">Utworzona w Fakturowni, brak ID KSeF</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <label>Status KSeF</label>
                            <div class="value">
                                <?php if ($canSendToKsef && empty($govId)): ?>
                                    <span style="display:inline-block; padding:4px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fef3c7; color:#92400e;">
                                        Nie wysłano do KSeF
                                    </span>
                                <?php elseif ($govStatus && isset($govStatusLabels[$govStatus])): ?>
                                    <span style="display:inline-block; padding:4px 12px; border-radius:6px; font-size:13px; font-weight:600; background:<?php echo $govStatusLabels[$govStatus][1]; ?>; color:<?php echo $govStatusLabels[$govStatus][2]; ?>;">
                                        <?php echo $govStatusLabels[$govStatus][0]; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Brak ID KSeF</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($govId): ?>
                        <div class="info-item">
                            <label>Numer KSeF</label>
                            <div class="value" style="font-family:monospace; font-size:13px;"><?php echo e($govId); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <label>Numer w Fakturowni</label>
                            <div class="value"><?php echo e($ksefData['fakturownia_number'] ?? 'Brak synchronizacji z Fakturownią'); ?></div>
                        </div>
                        <div class="info-item">
                            <label>ID Fakturownia</label>
                            <div class="value"><?php echo $fakturowniaId > 0 ? $fakturowniaId : 'Brak ID Fakturowni'; ?></div>
                        </div>
                    </div>
                    <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                        <?php if ($fakturowniaId > 0): ?>
                            <a href="/api/invoices-sale/download-pdf.php?fakturownia_id=<?php echo $fakturowniaId; ?>" class="btn btn-sm btn-outline" target="_blank">Otwórz PDF z Fakturowni</a>
                            <button type="button" onclick="refreshKsefStatus()" class="btn btn-sm btn-outline" id="ksef-refresh-btn">Odśwież status KSeF</button>
                        <?php endif; ?>
                        <?php if ($canSendToKsef): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="send_to_ksef">
                                <button type="submit" class="btn btn-sm btn-blue" onclick="return confirm('Najpierw sprawdź PDF z Fakturowni. Wysłać teraz fakturę do KSeF?')">Wyślij do KSeF</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canDeleteFakturowniaBeforeKsef): ?>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_fakturownia_before_ksef">
                                <button type="submit" class="btn btn-sm btn-outline" style="color:#991b1b;border-color:#fecaca;" onclick="return confirm('Usunąć dokument z Fakturowni i wrócić do edycji?\\n\\nTej akcji używaj tylko przed wysyłką do KSeF.')">Usuń z Fakturowni i popraw</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- AKCJE -->
        <div class="card">
            <div class="card-body">
                <div class="actions">
                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn btn-secondary">← Lista faktur</a>
                    <button type="button" class="btn btn-outline" onclick="openPreview()">Podgląd faktury</button>

                    <?php if ($fakturowniaId > 0): ?>
                        <a href="/api/invoices-sale/download-pdf.php?fakturownia_id=<?php echo $fakturowniaId; ?>" class="btn btn-outline" target="_blank">Pobierz PDF</a>
                    <?php endif; ?>

                    <div class="dropdown-wrap">
                        <button type="button" class="dropdown-toggle" onclick="toggleDropdown(this)" style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;">
                            Więcej akcji &#9660;
                        </button>
                        <div class="dropdown-menu">
                            <?php
                            $statusOptions = [
                                'issued' => 'Oznacz: Wystawiona',
                                'paid' => 'Oznacz: Opłacona',
                                'partially_paid' => 'Oznacz: Częściowo opłacona',
                                'cancelled' => 'Oznacz: Anulowana',
                            ];
                            foreach ($statusOptions as $sVal => $sLabel):
                                if ($sVal === $invoice['status']) continue;
                            ?>
                            <form method="POST" style="margin:0;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="new_status" value="<?php echo $sVal; ?>">
                                <button type="submit" onclick="return confirm('Zmienić status na: <?php echo $sLabel; ?>?')"><?php echo $sLabel; ?></button>
                            </form>
                            <?php endforeach; ?>

                            <div class="sep"></div>
                            <a href="<?php echo url('finanse.faktury-sprzedazowe.create'); ?>?correction_of=<?php echo $invoice_id; ?>">Wystaw korektę</a>
                            <a href="<?php echo url('finanse.faktury-sprzedazowe.create'); ?>?clone_from=<?php echo $invoice_id; ?>">Wystaw podobną</a>
                            <?php if ($fakturowniaId > 0): ?>
                                <button type="button" onclick="sendInvoiceEmail(<?php echo $fakturowniaId; ?>,'<?php echo e($invoice['invoice_number']); ?>')">Wyślij e-mail do klienta</button>
                            <?php else: ?>
                                <span style="display:block;padding:8px 12px;color:#991b1b;font-size:13px;">Brak synchronizacji z Fakturownią</span>
                            <?php endif; ?>

                            <?php if ($govId): ?>
                            <div class="sep"></div>
                            <a href="/api/invoices-sale/ksef-upo.php?fakturownia_id=<?php echo $fakturowniaId; ?>" target="_blank">KSeF UPO — podgląd</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    const isDraft = <?php echo $isDraft ? 'true' : 'false'; ?>;
    function submitDraftDelete() {
        if (confirm('Oznaczyć roboczą fakturę jako anulowaną/usuniętą lokalnie?')) {
            document.getElementById('draft-delete-form').submit();
        }
    }
    const itemsData = <?php echo json_encode(array_map(function($it) {
        $opts = json_decode((string)($it['fakturownia_item_options_json'] ?? '{}'), true) ?: [];
        $d = ['name' => $it['item_name'], 'quantity' => (float)$it['quantity'], 'unit' => $it['unit'],
            'unit_price' => (float)$it['unit_price_net'], 'vat_rate' => $it['vat_rate']];
        if (!empty($opts['correction_before']) && is_array($opts['correction_before'])) {
            $d['correction_before'] = $opts['correction_before'];
        }
        return $d;
    }, $items), JSON_UNESCAPED_UNICODE); ?>;

    const previewData = <?php echo json_encode([
        'company' => [
            'name' => $company['company_name'] ?? 'BRYGAD ERP',
            'nip' => $company['company_nip'] ?? '',
            'address' => ($company['company_address'] ?? '') . ', ' . ($company['company_post_code'] ?? '') . ' ' . ($company['company_city'] ?? ''),
            'logo_path' => $company['logo_path'] ?? null,
            'bank_name' => $company['default_bank_name'] ?? '',
            'phone' => $company['company_phone'] ?? '',
            'email' => $company['company_email'] ?? '',
            'website' => $company['company_website'] ?? '',
            'bdo' => '',
        ],
        'invoice' => [
            'number' => $invoice['invoice_number'],
            'kind' => $fakturowniaOptions['kind'] ?? 'vat',
            'issue_date' => $invoice['issue_date'],
            'sale_date' => $invoice['sale_date'],
            'due_date' => $invoice['due_date'],
            'payment_method' => $pmLabels[$invoice['payment_method']] ?? $invoice['payment_method'],
            'bank_account' => $invoice['bank_account'] ?? '',
            'currency' => $invoice['currency'],
            'split_payment' => !empty($invoice['split_payment']),
            'notes' => $invoice['notes'] ?? '',
            'place_of_issue' => $invoice['place_of_issue'] ?? '',
            'correction_of_invoice_id' => $fakturowniaOptions['correction_of_invoice_id'] ?? null,
            'correction_of_invoice_number' => $correctionOfInvoiceNumber,
            'correction_reason' => $fakturowniaOptions['correction_reason'] ?? '',
            'corrected_content_before' => $fakturowniaOptions['corrected_content_before'] ?? '',
            'corrected_content_after' => $fakturowniaOptions['corrected_content_after'] ?? '',
            'client_name' => $invoice['client_name'],
            'client_nip' => $invoice['client_nip'] ?? '',
            'client_address' => $invoice['client_address'] ?? '',
            'amount_net' => (float)$invoice['amount_net'],
            'amount_vat' => (float)$invoice['amount_vat'],
            'amount_gross' => (float)$invoice['amount_gross'],
            'status' => $invoice['status'],
            'gov_id' => $govId ?? '',
        ],
        'items' => array_map(function($it) {
            $opts = json_decode((string)($it['fakturownia_item_options_json'] ?? '{}'), true) ?: [];
            $entry = ['name' => $it['item_name'], 'quantity' => (float)$it['quantity'], 'unit' => $it['unit'],
                'price' => (float)$it['unit_price_net'], 'vat_rate' => $it['vat_rate'],
                'net' => (float)$it['amount_net'], 'vat' => (float)$it['amount_vat'], 'gross' => (float)$it['amount_gross']];
            if (!empty($opts['correction_before']) && is_array($opts['correction_before'])) {
                $entry['correction_before'] = $opts['correction_before'];
            }
            return $entry;
        }, $items),
        'issuer_name' => $company['issuer_name'] ?? $userName,
        'jst' => $jstData ? [
            'buyer_name'           => $jstData['jst_buyer_name'] ?? '',
            'buyer_nip'            => $jstData['jst_buyer_nip'] ?? '',
            'buyer_street'         => $jstData['jst_buyer_street'] ?? '',
            'buyer_post_code'      => $jstData['jst_buyer_post_code'] ?? '',
            'buyer_city'           => $jstData['jst_buyer_city'] ?? '',
            'recipient_name'       => $jstData['jst_recipient_name'] ?? '',
            'recipient_nip'        => $jstData['jst_recipient_nip'] ?? '',
            'recipient_street'     => $jstData['jst_recipient_street'] ?? '',
            'recipient_post_code'  => $jstData['jst_recipient_post_code'] ?? '',
            'recipient_city'       => $jstData['jst_recipient_city'] ?? '',
            'recipient_note'       => $jstData['jst_recipient_note'] ?? '',
        ] : null,
    ], JSON_UNESCAPED_UNICODE); ?>;

    // ═══════ COLLAPSIBLE CARDS ═══════
    function toggleCollapse(header) {
        const body = header.nextElementSibling;
        const arrow = header.querySelector('.collapse-arrow');
        body.classList.toggle('open');
        arrow.classList.toggle('open');
    }

    const clientEmail = <?php
        $ce = trim((string)($fakturowniaOptions['buyer_email'] ?? ''));
        if ($ce === '') $ce = trim((string)($invoice['client_email'] ?? ''));
        echo json_encode($ce);
    ?>;

    function sendInvoiceEmail(fakturowniaId, invoiceNumber) {
        let emailTo = clientEmail;
        const inputEmail = prompt('Wysłać fakturę ' + invoiceNumber + ' na adres e-mail:', emailTo || '');
        if (inputEmail === null) return;
        emailTo = inputEmail.trim();
        if (!emailTo) { alert('Podaj adres e-mail odbiorcy.'); return; }

        fetch('/api/invoices-sale/send-email.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'fakturownia_id=' + fakturowniaId + '&email_to=' + encodeURIComponent(emailTo)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) alert(data.message || 'Wysłano!');
            else alert('Błąd: ' + (data.error || 'Nieznany błąd'));
        })
        .catch(() => alert('Błąd połączenia z API'));
    }

    function toggleDropdown(btn) {
        const menu = btn.nextElementSibling;
        document.querySelectorAll('.dropdown-menu.show').forEach(m => { if (m !== menu) m.classList.remove('show'); });
        menu.classList.toggle('show');
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-wrap')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    // ═══════ KWOTA SŁOWNIE (PL) ═══════
    function numberToWordsPL(amount) {
        const jednosci = ['','jeden','dwa','trzy','cztery','pięć','sześć','siedem','osiem','dziewięć'];
        const nastki = ['dziesięć','jedenaście','dwanaście','trzynaście','czternaście','piętnaście','szesnaście','siedemnaście','osiemnaście','dziewiętnaście'];
        const dziesiatki = ['','dziesięć','dwadzieścia','trzydzieści','czterdzieści','pięćdziesiąt','sześćdziesiąt','siedemdziesiąt','osiemdziesiąt','dziewięćdziesiąt'];
        const setki = ['','sto','dwieście','trzysta','czterysta','pięćset','sześćset','siedemset','osiemset','dziewięćset'];

        function groupToWords(n) {
            if (n === 0) return '';
            let result = '';
            const s = Math.floor(n / 100);
            const remainder = n % 100;
            const d = Math.floor(remainder / 10);
            const j = remainder % 10;
            if (s > 0) result += setki[s] + ' ';
            if (remainder >= 10 && remainder <= 19) {
                result += nastki[remainder - 10] + ' ';
            } else {
                if (d > 0) result += dziesiatki[d] + ' ';
                if (j > 0) result += jednosci[j] + ' ';
            }
            return result.trim();
        }

        function pluralForm(n, forms) {
            if (n === 1) return forms[0];
            const lastDigit = n % 10;
            const lastTwo = n % 100;
            if (lastDigit >= 2 && lastDigit <= 4 && (lastTwo < 12 || lastTwo > 14)) return forms[1];
            return forms[2];
        }

        function intToWords(num) {
            if (num === 0) return 'zero';
            const groups = [];
            let temp = num;
            while (temp > 0) { groups.push(temp % 1000); temp = Math.floor(temp / 1000); }
            const thousands = [
                ['', '', ''],
                ['tysiąc', 'tysiące', 'tysięcy'],
                ['milion', 'miliony', 'milionów'],
            ];
            let words = '';
            for (let i = groups.length - 1; i >= 0; i--) {
                if (groups[i] === 0) continue;
                words += groupToWords(groups[i]);
                if (i > 0 && thousands[i]) {
                    words += ' ' + pluralForm(groups[i], thousands[i]);
                }
                words += ' ';
            }
            return words.trim();
        }

        const zlote = Math.floor(Math.abs(amount));
        const grosze = Math.round((Math.abs(amount) - zlote) * 100);

        let result = intToWords(zlote) + ' ' + pluralForm(zlote, ['złoty', 'złote', 'złotych']);
        if (grosze > 0) {
            result += ' ' + intToWords(grosze) + ' ' + pluralForm(grosze, ['grosz', 'grosze', 'groszy']);
        } else {
            result += ' zero groszy';
        }
        return result;
    }

    // ═══════ PODGLĄD FAKTUROWNIA-STYLE ═══════
    function openPreview() {
        const d = isDraft ? getPreviewDataFromForm() : previewData;
        const kindLabels = {vat:'Faktura VAT', proforma:'Proforma', advance:'Faktura zaliczkowa', final:'Faktura końcowa', correction:'Faktura korygująca', bill:'Rachunek', receipt:'Paragon', vat_mp:'VAT MP', vat_margin:'VAT marża'};
        const kindLabel = kindLabels[d.invoice.kind] || 'Faktura VAT';
        const isCorrection = d.invoice.kind === 'correction';
        const fmtDate = (s) => { if (!s) return '-'; const p = s.split('-'); return p.length===3 ? p[2]+'.'+p[1]+'.'+p[0] : s; };
        const fmtN = (v) => parseFloat(v||0).toFixed(2).replace('.',',');

        const logoHtml = d.company.logo_path
            ? '<img src="/' + d.company.logo_path + '" style="max-height:60px;">'
            : '<div style="font-size:20px;font-weight:800;color:#1e3a8a;">' + d.company.name + '</div>';

        const commonCss = '*{margin:0;padding:0;box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2937;padding:0;background:#f5f7fa;}'
            + 'table{width:100%;border-collapse:collapse;}'
            + '.toolbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 30px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,0.06);}'
            + '.toolbar-title{font-weight:700;font-size:15px;color:#1e3a8a;margin-right:auto;}'
            + '.btn{padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;}'
            + '.btn-green{background:#16a34a;color:#fff;}.btn-green:hover{background:#15803d;}'
            + '.btn-blue{background:#2563eb;color:#fff;}.btn-blue:hover{background:#1d4ed8;}'
            + '.btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}.btn-outline:hover{background:#f9fafb;}'
            + '.btn-gray{background:#f3f4f6;color:#374151;}.btn-gray:hover{background:#e5e7eb;}'
            + '.invoice{max-width:820px;margin:30px auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.08);padding:40px;border:1px solid #e5e7eb;}'
            + '@media print{.toolbar{display:none!important;}.invoice{box-shadow:none;border:none;margin:0;padding:20px;border-radius:0;}.payment-footer{break-inside:avoid;page-break-inside:avoid;}}';

        const w = window.open('', '_blank', 'width=860,height=1100');
        let html = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>' + kindLabel + ' ' + d.invoice.number + '</title>'
            + '<style>' + commonCss + '</style></head><body>';

        if (isDraft) {
            const issueBtn = d.jst
                ? '<button class="btn" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;" onclick="saveAndIssueJst()">Wystaw w Fakturowni (JST)</button>'
                : '<button class="btn btn-blue" onclick="saveAndIssue()">Wystaw w Fakturowni</button>';
            html += '<div class="toolbar"><span class="toolbar-title">Podgląd: ' + kindLabel + ' ' + d.invoice.number + (d.jst ? ' — JST' : '') + '</span>'
                + '<button class="btn btn-green" onclick="saveAsDraft()">Zapisz w roboczych</button>'
                + issueBtn
                + '<button class="btn btn-outline" onclick="window.print()">Drukuj / PDF</button>'
                + '<button class="btn btn-gray" onclick="window.close()">Zamknij</button></div>';
        } else {
            html += '<div class="toolbar"><span class="toolbar-title">' + kindLabel + ' ' + d.invoice.number + '</span>'
                + '<button class="btn btn-outline" onclick="window.print()">Drukuj / PDF</button>'
                + '<button class="btn btn-gray" onclick="window.close()">Zamknij</button></div>';
        }

        html += '<div class="invoice">';

        // Header z logo i tytułem
        html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:14px;border-bottom:3px solid #1e3a8a;">'
            + '<div>' + logoHtml + '</div>'
            + '<div style="text-align:right;"><div style="font-size:22px;font-weight:800;color:#1e3a8a;">' + kindLabel + '</div>'
            + '<div style="font-size:14px;color:#64748b;margin-top:2px;">' + d.invoice.number + '</div>';
        if (d.invoice.status === 'draft') {
            html += '<div style="margin-top:6px;display:inline-block;background:#fef3c7;color:#92400e;font-size:11px;padding:3px 12px;border-radius:4px;font-weight:600;">ROBOCZA</div>';
        }
        html += '</div></div>';

        // Baner "Korekta do faktury nr X"
        if (isCorrection && d.invoice.correction_of_invoice_number) {
            html += '<div style="margin-bottom:16px;padding:10px 16px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;font-size:13px;color:#92400e;">'
                + '<strong>Korekta do faktury:</strong> ' + d.invoice.correction_of_invoice_number
                + '</div>';
        }

        // Baner JST — wyraźne oznaczenie że to faktura dla JST (KSeF Podmiot2/Podmiot3)
        if (d.jst) {
            html += '<div style="margin-bottom:16px;padding:10px 16px;background:#ede9fe;border-left:4px solid #7c3aed;border-radius:4px;font-size:13px;color:#4c1d95;">'
                + '<strong>Faktura JST (KSeF)</strong> — nabywca to Podmiot2 (JST), odbiorca to Podmiot3. '
                + 'Dane poniżej zostaną wysłane do Fakturowni i KSeF zamiast danych zwykłego klienta.'
                + '</div>';
        }

        // Miejsce wystawienia
        if (d.invoice.place_of_issue) {
            html += '<div style="font-size:12px;color:#94a3b8;margin-bottom:16px;">Miejsce wystawienia: ' + d.invoice.place_of_issue + '</div>';
        }

        // Sprzedawca / Nabywca
        if (d.jst) {
            // ── JST: Podmiot2 (nabywca JST) + Podmiot3 (odbiorca) ──
            const jst = d.jst;

            // Wiersz: Sprzedawca | Podmiot2 (nabywca JST)
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:16px;">'
                + '<div>'
                + '<div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#94a3b8;margin-bottom:6px;">Sprzedawca</div>'
                + '<div style="font-weight:700;font-size:15px;">' + d.company.name + '</div>'
                + '<div style="font-size:13px;color:#64748b;line-height:1.7;">'
                + (d.company.nip ? 'NIP: ' + d.company.nip + '<br>' : '')
                + d.company.address
                + (d.company.email ? '<br>' + d.company.email : '')
                + (d.company.phone ? '<br>Tel: ' + d.company.phone : '')
                + '</div></div>'
                + '<div style="border:2px solid #7c3aed;border-radius:8px;padding:12px;background:#faf5ff;">'
                + '<div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#7c3aed;margin-bottom:6px;">Nabywca — Podmiot2 (JST)</div>'
                + '<div style="font-weight:700;font-size:15px;">' + (jst.buyer_name || '—') + '</div>'
                + '<div style="font-size:13px;color:#64748b;line-height:1.7;">'
                + (jst.buyer_nip ? 'NIP: ' + jst.buyer_nip + '<br>' : '')
                + (jst.buyer_street ? jst.buyer_street + '<br>' : '')
                + (jst.buyer_post_code || jst.buyer_city ? (jst.buyer_post_code + ' ' + jst.buyer_city) : '')
                + '</div></div></div>';

            // Podmiot3 (odbiorca) — jeśli podany
            if (jst.recipient_name) {
                html += '<div style="margin-bottom:16px;border:1px solid #ddd6fe;border-radius:8px;padding:12px;background:#f5f3ff;">'
                    + '<div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#7c3aed;margin-bottom:6px;">Odbiorca — Podmiot3</div>'
                    + '<div style="font-weight:700;font-size:15px;">' + jst.recipient_name + '</div>'
                    + '<div style="font-size:13px;color:#64748b;line-height:1.7;">'
                    + (jst.recipient_nip ? 'NIP: ' + jst.recipient_nip + '<br>' : '')
                    + (jst.recipient_street ? jst.recipient_street + '<br>' : '')
                    + (jst.recipient_post_code || jst.recipient_city ? (jst.recipient_post_code + ' ' + jst.recipient_city) : '')
                    + (jst.recipient_note ? '<br><em>' + jst.recipient_note + '</em>' : '')
                    + '</div></div>';
            }

        } else {
            // ── Zwykła faktura: standardowy nabywca ──
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px;">'
                + '<div><div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#94a3b8;margin-bottom:6px;">Sprzedawca</div>'
                + '<div style="font-weight:700;font-size:15px;">' + d.company.name + '</div>'
                + '<div style="font-size:13px;color:#64748b;line-height:1.7;">'
                + (d.company.nip ? 'NIP: ' + d.company.nip + '<br>' : '')
                + d.company.address
                + (d.company.email ? '<br>' + d.company.email : '')
                + (d.company.website ? '<br>' + d.company.website : '')
                + (d.company.phone ? '<br>Tel: ' + d.company.phone : '')
                + '</div></div>'
                + '<div><div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#94a3b8;margin-bottom:6px;">Nabywca</div>'
                + '<div style="font-weight:700;font-size:15px;">' + d.invoice.client_name + '</div>'
                + '<div style="font-size:13px;color:#64748b;line-height:1.7;">'
                + (d.invoice.client_nip ? 'NIP: ' + d.invoice.client_nip + '<br>' : '')
                + (d.invoice.client_address || '')
                + '</div></div></div>';
        }

        // Daty
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;padding:14px;background:#f8fafc;border-radius:8px;font-size:13px;">'
            + '<div><span style="color:#94a3b8;">Data wystawienia:</span> <strong>' + fmtDate(d.invoice.issue_date) + '</strong></div>'
            + '<div><span style="color:#94a3b8;">Data sprzedaży:</span> <strong>' + fmtDate(d.invoice.sale_date) + '</strong></div>'
            + '<div><span style="color:#94a3b8;">Termin płatności:</span> <strong>' + fmtDate(d.invoice.due_date) + '</strong></div>'
            + '<div><span style="color:#94a3b8;">Sposób płatności:</span> <strong>' + d.invoice.payment_method + '</strong></div>'
            + '</div>';

        // ─── TABELA POZYCJI ───
        let positionsHtml = '';

        if (isCorrection) {
            // Powód korekty
            if (d.invoice.correction_reason) {
                positionsHtml += '<div style="margin-bottom:12px;padding:10px 14px;background:#fef3c7;border-radius:6px;font-size:13px;color:#92400e;">'
                    + '<strong>Powód korekty:</strong> ' + d.invoice.correction_reason + '</div>';
            }
            if (d.invoice.corrected_content_before || d.invoice.corrected_content_after) {
                positionsHtml += '<div style="margin-bottom:12px;padding:10px 14px;background:#eff6ff;border-radius:6px;font-size:13px;color:#1e40af;">'
                    + (d.invoice.corrected_content_before ? '<strong>Treść korygowana:</strong> ' + d.invoice.corrected_content_before + '<br>' : '')
                    + (d.invoice.corrected_content_after ? '<strong>Treść prawidłowa:</strong> ' + d.invoice.corrected_content_after : '')
                    + '</div>';
            }

            const thBase = 'padding:7px 10px;font-size:11px;font-weight:700;text-transform:uppercase;';
            const thL = thBase + 'text-align:left;';
            const thR = thBase + 'text-align:right;';
            const thC = thBase + 'text-align:center;';
            const thBefore = thR + 'background:#fef9e7;color:#92400e;border-bottom:2px solid #f59e0b;';
            const thAfter  = thR + 'background:#f0fdf4;color:#065f46;border-bottom:2px solid #10b981;';
            const thDiff   = thR + 'background:#eff6ff;color:#1e40af;border-bottom:2px solid #3b82f6;';
            const thGrp = 'padding:6px 10px;font-size:10px;font-weight:700;text-transform:uppercase;text-align:center;letter-spacing:0.5px;border-bottom:2px solid;';

            let corrRows = '';
            let tGrossBefore = 0, tGrossAfter = 0;

            d.items.forEach(it => {
                const cb = it.correction_before || {};
                const bQty   = parseFloat(cb.quantity)        || parseFloat(it.quantity) || 0;
                const bPrice = parseFloat(cb.unit_price_net)   || parseFloat(it.price)   || 0;
                const bGross = parseFloat(cb.amount_gross)     || 0;
                const aGross = parseFloat(it.gross)            || 0;
                const diff   = aGross - bGross;
                tGrossBefore += bGross;
                tGrossAfter  += aGross;
                const vatLabel = it.vat_rate === 'zw' ? 'zw' : it.vat_rate + '%';
                const diffColor = diff < 0 ? '#dc2626' : (diff > 0 ? '#16a34a' : '#64748b');

                corrRows += '<tr>'
                    + '<td style="padding:8px 10px;font-size:13px;font-weight:600;">' + it.name + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:center;">' + it.unit + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:center;">' + vatLabel + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#fef9e7;color:#92400e;">' + bQty + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#fef9e7;color:#92400e;">' + fmtN(bPrice) + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#fef9e7;color:#92400e;font-weight:700;">' + fmtN(bGross) + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#f0fdf4;color:#065f46;">' + it.quantity + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#f0fdf4;color:#065f46;">' + fmtN(it.price) + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#f0fdf4;color:#065f46;font-weight:700;">' + fmtN(aGross) + '</td>'
                    + '<td style="padding:8px 10px;font-size:13px;text-align:right;background:#eff6ff;color:' + diffColor + ';font-weight:700;">' + (diff >= 0 ? '+' : '') + fmtN(diff) + '</td>'
                    + '</tr>';
            });

            const tGrossDiff = tGrossAfter - tGrossBefore;
            const diffColor = tGrossDiff < 0 ? '#dc2626' : (tGrossDiff > 0 ? '#16a34a' : '#64748b');

            positionsHtml += '<table style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
                + '<thead>'
                + '<tr>'
                + '<th style="' + thL + 'background:#f8fafc;color:#374151;border-color:#e5e7eb;" rowspan="2">Nazwa</th>'
                + '<th style="' + thC + 'background:#f8fafc;color:#374151;border-color:#e5e7eb;" rowspan="2">Jedn.</th>'
                + '<th style="' + thC + 'background:#f8fafc;color:#374151;border-color:#e5e7eb;" rowspan="2">VAT</th>'
                + '<th colspan="3" style="' + thGrp + 'background:#fef3c7;color:#92400e;border-color:#f59e0b;">Przed korektą</th>'
                + '<th colspan="3" style="' + thGrp + 'background:#dcfce7;color:#065f46;border-color:#10b981;">Po korekcie</th>'
                + '<th style="' + thGrp + 'background:#dbeafe;color:#1e40af;border-color:#3b82f6;" rowspan="2">Korekta<br>(brutto)</th>'
                + '</tr>'
                + '<tr>'
                + '<th style="' + thBefore + '">Ilość</th><th style="' + thBefore + '">Cena netto</th><th style="' + thBefore + '">Brutto</th>'
                + '<th style="' + thAfter  + '">Ilość</th><th style="' + thAfter  + '">Cena netto</th><th style="' + thAfter  + '">Brutto</th>'
                + '</tr>'
                + '</thead>'
                + '<tbody>' + corrRows + '</tbody>'
                + '<tfoot><tr>'
                + '<td colspan="3" style="padding:10px;text-align:right;font-weight:700;font-size:13px;color:#64748b;">RAZEM:</td>'
                + '<td colspan="2" style="background:#fef9e7;"></td>'
                + '<td style="padding:10px;text-align:right;background:#fef9e7;color:#92400e;font-weight:800;">' + fmtN(tGrossBefore) + '</td>'
                + '<td colspan="2" style="background:#f0fdf4;"></td>'
                + '<td style="padding:10px;text-align:right;background:#f0fdf4;color:#065f46;font-weight:800;">' + fmtN(tGrossAfter) + '</td>'
                + '<td style="padding:10px;text-align:right;background:#eff6ff;color:' + diffColor + ';font-weight:800;">' + (tGrossDiff >= 0 ? '+' : '') + fmtN(tGrossDiff) + '</td>'
                + '</tr></tfoot>'
                + '</table>';

            // Ramka z wartością korekty
            positionsHtml += '<div style="border:2px solid #1e3a8a;border-radius:8px;padding:16px;margin-bottom:20px;">'
                + '<div style="display:flex;justify-content:space-between;font-size:18px;font-weight:800;color:#1e3a8a;">'
                + '<span>Wartość korekty (brutto):</span>'
                + '<span style="color:' + diffColor + ';">' + (tGrossDiff >= 0 ? '+' : '') + fmtN(tGrossDiff) + ' ' + d.invoice.currency + '</span>'
                + '</div>'
                + '</div>';

        } else {
            // ─── ZWYKŁA FAKTURA ───
            let itemsHtml = '';
            let vatSummary = {};
            d.items.forEach((it, i) => {
                const vrKey = it.vat_rate === 'zw' ? 'zw' : it.vat_rate;
                if (!vatSummary[vrKey]) vatSummary[vrKey] = {net:0, vat:0, gross:0};
                vatSummary[vrKey].net   += parseFloat(it.net)   || 0;
                vatSummary[vrKey].vat   += parseFloat(it.vat)   || 0;
                vatSummary[vrKey].gross += parseFloat(it.gross) || 0;
                itemsHtml += '<tr>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:center;font-size:13px;">' + (i+1) + '</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;">' + it.name + '</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;font-size:13px;">' + it.quantity + ' (' + it.unit + ')</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;font-size:13px;">' + parseFloat(it.price||0).toFixed(2) + '</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;font-size:13px;">' + parseFloat(it.net||0).toFixed(2) + '</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:center;font-size:13px;">' + (it.vat_rate==='zw'?'zw':it.vat_rate+'%') + '</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;font-size:13px;">' + parseFloat(it.vat||0).toFixed(2) + '</td>'
                    + '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;font-size:13px;font-weight:600;">' + parseFloat(it.gross||0).toFixed(2) + '</td>'
                    + '</tr>';
            });

            let vatSumHtml = '';
            for (const [rate, vals] of Object.entries(vatSummary)) {
                vatSumHtml += '<tr style="font-size:13px;">'
                    + '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;text-align:right;">W tym</td>'
                    + '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;text-align:right;">' + vals.net.toFixed(2) + '</td>'
                    + '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;text-align:center;">' + (rate==='zw'?'zw':rate+'%') + '</td>'
                    + '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;text-align:right;">' + vals.vat.toFixed(2) + '</td>'
                    + '<td style="padding:4px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;">' + vals.gross.toFixed(2) + '</td>'
                    + '</tr>';
            }
            const amtNet   = parseFloat(d.invoice.amount_net)   || 0;
            const amtVat   = parseFloat(d.invoice.amount_vat)   || 0;
            const amtGross = parseFloat(d.invoice.amount_gross) || 0;
            vatSumHtml += '<tr style="font-size:14px;font-weight:700;">'
                + '<td style="padding:6px 10px;text-align:right;">Razem</td>'
                + '<td style="padding:6px 10px;text-align:right;">' + amtNet.toFixed(2) + '</td>'
                + '<td style="padding:6px 10px;text-align:center;"></td>'
                + '<td style="padding:6px 10px;text-align:right;">' + amtVat.toFixed(2) + '</td>'
                + '<td style="padding:6px 10px;text-align:right;">' + amtGross.toFixed(2) + '</td>'
                + '</tr>';

            positionsHtml += '<table style="margin-bottom:16px;"><thead><tr style="background:#1e3a8a;color:#fff;">'
                + '<th style="text-align:center;">#</th><th>Nazwa towaru / usługi</th><th style="text-align:right;">Ilość (j.m.)</th>'
                + '<th style="text-align:right;">Cena netto</th><th style="text-align:right;">Wartość netto</th>'
                + '<th style="text-align:center;">VAT %</th><th style="text-align:right;">Wartość VAT</th><th style="text-align:right;">Wartość brutto</th>'
                + '</tr></thead><tbody>' + itemsHtml + '</tbody></table>';

            positionsHtml += '<table style="width:auto;margin-left:auto;margin-bottom:20px;"><tbody>' + vatSumHtml + '</tbody></table>';

            const slownie = numberToWordsPL(amtGross);
            positionsHtml += '<div style="border:2px solid #1e3a8a;border-radius:8px;padding:16px;margin-bottom:20px;">'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;"><span>Wartość netto:</span><span>' + amtNet.toFixed(2) + ' ' + d.invoice.currency + '</span></div>'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;"><span>Wartość VAT:</span><span>' + amtVat.toFixed(2) + ' ' + d.invoice.currency + '</span></div>'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;"><span>Wartość brutto:</span><span>' + amtGross.toFixed(2) + ' ' + d.invoice.currency + '</span></div>'
                + '<div style="display:flex;justify-content:space-between;font-size:20px;font-weight:800;color:#1e3a8a;border-top:2px solid #1e3a8a;padding-top:10px;margin-top:6px;"><span>Do zapłaty:</span><span>' + amtGross.toFixed(2) + ' ' + d.invoice.currency + '</span></div>'
                + '<div style="font-size:12px;color:#64748b;margin-top:4px;">Słownie: ' + slownie + '</div>'
                + '</div>';
        }

        html += positionsHtml;

        // Payment footer
        html += '<div class="payment-footer">';
        if (!isCorrection) {
            html += '<div style="font-size:13px;margin-bottom:16px;line-height:1.8;">'
                + '<strong>Termin płatności:</strong> ' + fmtDate(d.invoice.due_date) + '<br>'
                + '<strong>Płatność:</strong> ' + d.invoice.payment_method;
            if (d.invoice.bank_account) {
                html += '<br><strong>Rachunki bankowe:</strong><br>';
                if (d.company.bank_name) html += d.company.bank_name + '<br>';
                html += '<span style="font-size:14px;letter-spacing:0.5px;">' + d.invoice.bank_account + '</span>';
            }
            html += '</div>';

            if (d.invoice.split_payment) {
                html += '<div style="background:#fef3c7;color:#92400e;padding:10px 16px;border-radius:6px;font-size:13px;font-weight:600;margin-bottom:14px;">Mechanizm podzielonej płatności</div>';
            }
        }

        if (d.invoice.notes) {
            html += '<div style="margin-bottom:14px;padding:12px;background:#f8fafc;border-radius:6px;font-size:13px;color:#64748b;"><strong>Uwagi:</strong><br>' + d.invoice.notes.replace(/\n/g,'<br>') + '</div>';
        }

        html += '<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;">'
            + '<div style="font-size:12px;color:#94a3b8;">Imię i nazwisko wystawcy<br><strong style="color:#1f2937;font-size:14px;">' + (d.issuer_name || '') + '</strong></div>';
        if (d.invoice.gov_id) {
            html += '<div style="text-align:right;font-size:12px;color:#94a3b8;">Numer KSeF<br><strong style="color:#1f2937;font-size:12px;">' + d.invoice.gov_id + '</strong></div>';
        }
        html += '</div>';

        html += '</div>'; // end .payment-footer
        html += '</div>'; // end .invoice

        if (isDraft) {
            html += '<scr' + 'ipt>'
                + 'function saveAsDraft(){if(window.opener&&!window.opener.closed){document.querySelector(".toolbar-title").textContent="Zapisuję...";window.opener.document.getElementById("editForm").requestSubmit();window.close();}else{alert("Wróć do formularza.");}}'
                + 'function saveAndIssue(){if(!confirm("Utworzyć dokument w Fakturowni? Po tym kroku sprawdź PDF z Fakturowni i dopiero wyślij do KSeF."))return;if(window.opener&&!window.opener.closed){var f=window.opener.document.getElementById("editForm");var a=f.querySelector("[name=action]");var next=window.opener.document.getElementById("after_save_action");a.value="save_draft";if(next)next.value="issue";f.requestSubmit();window.close();}else{alert("Wróć do formularza.");}}'
                + 'function saveAndIssueJst(){if(!confirm("Utworzyć dokument JST w Fakturowni?\\n\\nPo tym kroku sprawdź PDF z Fakturowni i dopiero wyślij do KSeF."))return;if(window.opener&&!window.opener.closed){var f=window.opener.document.getElementById("editForm");var a=f.querySelector("[name=action]");var next=window.opener.document.getElementById("after_save_action");a.value="save_draft";if(next)next.value="issue_jst";f.requestSubmit();window.close();}else{alert("Wróć do formularza.");}}'
                + '</scr' + 'ipt>';
        }

        html += '</body></html>';

        w.document.write(html);
        w.document.close();
    }

    function getPreviewDataFromForm() {
        const form = document.getElementById('editForm');
        if (!form) return previewData;

        beforeSaveDraft();
        const items = JSON.parse(document.getElementById('edit_invoice_items').value || '[]');
        let tNet = 0, tVat = 0, tGross = 0;
        const mappedItems = items.map(it => {
            tNet += it.amount_net; tVat += it.amount_vat; tGross += it.amount_gross;
            const entry = {name: it.name, quantity: it.quantity, unit: it.unit, price: it.unit_price,
                vat_rate: it.vat_rate, net: it.amount_net, vat: it.amount_vat, gross: it.amount_gross};
            if (it.correction_before) entry.correction_before = it.correction_before;
            return entry;
        });

        // Pobierz aktualne dane JST z formularza (jeśli pola istnieją)
        let jstFromForm = null;
        const jstBuyerName = form.querySelector('[name="jst_buyer_name"]');
        if (jstBuyerName) {
            jstFromForm = {
                buyer_name:           (form.querySelector('[name="jst_buyer_name"]')?.value || '').trim(),
                buyer_nip:            (form.querySelector('[name="jst_buyer_nip"]')?.value || '').trim(),
                buyer_street:         (form.querySelector('[name="jst_buyer_street"]')?.value || '').trim(),
                buyer_post_code:      (form.querySelector('[name="jst_buyer_post_code"]')?.value || '').trim(),
                buyer_city:           (form.querySelector('[name="jst_buyer_city"]')?.value || '').trim(),
                recipient_name:       (form.querySelector('[name="jst_recipient_name"]')?.value || '').trim(),
                recipient_nip:        (form.querySelector('[name="jst_recipient_nip"]')?.value || '').trim(),
                recipient_street:     (form.querySelector('[name="jst_recipient_street"]')?.value || '').trim(),
                recipient_post_code:  (form.querySelector('[name="jst_recipient_post_code"]')?.value || '').trim(),
                recipient_city:       (form.querySelector('[name="jst_recipient_city"]')?.value || '').trim(),
                recipient_note:       (form.querySelector('[name="jst_recipient_note"]')?.value || '').trim(),
            };
        }

        return {
            company: previewData.company,
            invoice: {
                number: form.invoice_number.value,
                kind: previewData.invoice.kind,
                issue_date: form.issue_date.value,
                sale_date: form.sale_date.value,
                due_date: form.due_date.value,
                payment_method: form.payment_method.options[form.payment_method.selectedIndex].text,
                bank_account: form.bank_account.value,
                currency: form.currency.value,
                split_payment: form.split_payment.checked,
                notes: form.notes.value,
                place_of_issue: form.place_of_issue.value,
                correction_of_invoice_id: previewData.invoice.correction_of_invoice_id,
                correction_of_invoice_number: previewData.invoice.correction_of_invoice_number,
                correction_reason: form.correction_reason ? form.correction_reason.value : previewData.invoice.correction_reason,
                corrected_content_before: form.corrected_content_before ? form.corrected_content_before.value : previewData.invoice.corrected_content_before,
                corrected_content_after: form.corrected_content_after ? form.corrected_content_after.value : previewData.invoice.corrected_content_after,
                client_name: previewData.invoice.client_name,
                client_nip: previewData.invoice.client_nip,
                client_address: previewData.invoice.client_address,
                amount_net: tNet,
                amount_vat: tVat,
                amount_gross: tGross,
                status: 'draft',
                gov_id: '',
            },
            items: mappedItems,
            issuer_name: previewData.issuer_name,
            jst: jstFromForm !== null ? jstFromForm : (previewData.jst || null),
        };
    }

    // ═══════ ISSUE FROM EDIT ═══════
    function issueInvoice() {
        if (!confirm('Utworzyć dokument w Fakturowni? Po tym kroku sprawdź PDF z Fakturowni i dopiero wyślij do KSeF.')) return;
        beforeSaveDraft();
        const form = document.getElementById('editForm');
        form.querySelector('[name=action]').value = 'save_draft';
        const afterSave = document.getElementById('after_save_action');
        if (afterSave) afterSave.value = 'issue';
        form.requestSubmit();
    }

    function issueInvoiceJst() {
        if (!confirm('Utworzyć dokument JST w Fakturowni?\n\nPo tym kroku sprawdź PDF z Fakturowni i dopiero wyślij do KSeF.')) return;
        beforeSaveDraft();
        const form = document.getElementById('editForm');
        form.querySelector('[name=action]').value = 'save_draft';
        const afterSave = document.getElementById('after_save_action');
        if (afterSave) afterSave.value = 'issue_jst';
        form.requestSubmit();
    }

    // ═══════ EDIT ITEMS (draft) ═══════
    let editCounter = 0;
    function addEditItem(data = {}) {
        editCounter++;
        const tbody = document.getElementById('edit-items-tbody');
        if (!tbody) return;
        const row = document.createElement('tr');
        const d = {
            name: data.name || '', quantity: data.quantity || 1, unit: data.unit || 'szt',
            unit_price: data.unit_price || 0, vat_rate: (data.vat_rate || '23').toString(),
            correction_before: data.correction_before || null,
        };

        const unitOpts = ['szt','usł','godz','m2','mb','kg','kpl'].map(u => '<option value="' + u + '" ' + (d.unit===u?'selected':'') + '>' + (u==='m2'?'m²':u) + '</option>').join('');
        const vatOpts = [['23','23%'],['8','8%'],['5','5%'],['0','0%'],['zw','zw']].map(([v,l]) => '<option value="' + v + '" ' + (d.vat_rate===v?'selected':'') + '>' + l + '</option>').join('');
        const cbJson = d.correction_before ? JSON.stringify(d.correction_before).replace(/"/g,'&quot;') : '';

        row.innerHTML = '<td><input type="text" class="item-name" value="' + d.name.replace(/"/g,'&quot;') + '" placeholder="Nazwa">'
            + '<input type="hidden" class="item-correction-before" value="' + cbJson + '"></td>'
            + '<td><input type="number" class="item-quantity" value="' + d.quantity + '" step="0.01" min="0" style="text-align:center;"></td>'
            + '<td><select class="item-unit">' + unitOpts + '</select></td>'
            + '<td><input type="number" class="item-price" value="' + d.unit_price + '" step="0.01" min="0" style="text-align:right;"></td>'
            + '<td><select class="item-vat">' + vatOpts + '</select></td>'
            + '<td class="text-right item-net-display" style="font-weight:600;">0,00</td>'
            + '<td class="text-right item-vat-display">0,00</td>'
            + '<td class="text-right item-gross-display" style="font-weight:600;">0,00</td>'
            + '<td><button type="button" class="item-remove" onclick="this.closest(\'tr\').remove(); updateEditTotals();">&times;</button></td>';
        tbody.appendChild(row);
        row.querySelectorAll('.item-quantity, .item-price, .item-vat').forEach(el => el.addEventListener('input', () => calcEditRow(row)));
        calcEditRow(row);
    }

    function calcEditRow(row) {
        const qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const vr = row.querySelector('.item-vat').value;
        const net = qty * price;
        const vat = vr === 'zw' ? 0 : net * (parseFloat(vr)/100);
        row.querySelector('.item-net-display').textContent = net.toFixed(2).replace('.',',');
        row.querySelector('.item-vat-display').textContent = vat.toFixed(2).replace('.',',');
        row.querySelector('.item-gross-display').textContent = (net+vat).toFixed(2).replace('.',',');
        updateEditTotals();
    }

    function updateEditTotals() {
        let tN=0, tV=0, tG=0;
        document.querySelectorAll('#edit-items-tbody tr').forEach(r => {
            tN += parseFloat(r.querySelector('.item-net-display').textContent.replace(',','.')) || 0;
            tV += parseFloat(r.querySelector('.item-vat-display').textContent.replace(',','.')) || 0;
            tG += parseFloat(r.querySelector('.item-gross-display').textContent.replace(',','.')) || 0;
        });
        const en = document.getElementById('edit-total-net');
        const ev = document.getElementById('edit-total-vat');
        const eg = document.getElementById('edit-total-gross');
        if (en) en.textContent = tN.toFixed(2).replace('.',',');
        if (ev) ev.textContent = tV.toFixed(2).replace('.',',');
        if (eg) eg.textContent = tG.toFixed(2).replace('.',',');
    }

    function beforeSaveDraft() {
        const rows = document.querySelectorAll('#edit-items-tbody tr');
        const items = [];
        rows.forEach(r => {
            const name = r.querySelector('.item-name').value.trim();
            if (!name) return;
            const cbEl = r.querySelector('.item-correction-before');
            let correctionBefore = null;
            try { if (cbEl && cbEl.value) correctionBefore = JSON.parse(cbEl.value); } catch(e) {}
            const item = { name, product_id: null, product_code: '',
                quantity: parseFloat(r.querySelector('.item-quantity').value) || 0,
                unit: r.querySelector('.item-unit').value,
                unit_price: parseFloat(r.querySelector('.item-price').value) || 0,
                vat_rate: r.querySelector('.item-vat').value,
                gtu_code: '', discount_percent: 0, discount: 0,
                amount_net: parseFloat(r.querySelector('.item-net-display').textContent.replace(',','.')) || 0,
                amount_vat: parseFloat(r.querySelector('.item-vat-display').textContent.replace(',','.')) || 0,
                amount_gross: parseFloat(r.querySelector('.item-gross-display').textContent.replace(',','.')) || 0,
            };
            if (correctionBefore) item.correction_before = correctionBefore;
            items.push(item);
        });
        document.getElementById('edit_invoice_items').value = JSON.stringify(items);
    }

    if (isDraft) {
        itemsData.forEach(it => addEditItem(it));
        if (itemsData.length === 0) addEditItem();
        document.getElementById('editForm').addEventListener('submit', function() { beforeSaveDraft(); });
    }

    <?php if ($autoIssue && $isDraft && count($items) > 0): ?>
    if (<?php echo $autoIssueConfirmed ? 'true' : "confirm('Faktura zapisana. Utworzyć teraz dokument w Fakturowni?')"; ?>) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="issue">';
        document.body.appendChild(form);
        form.submit();
    }
    <?php endif; ?>

    <?php if ($autoIssueJst && $isDraft && count($items) > 0 && $jstData): ?>
    if (<?php echo $autoIssueConfirmed ? 'true' : "confirm('Faktura JST zapisana. Utworzyć teraz dokument w Fakturowni z danymi JST?')"; ?>) {
        const formJst = document.createElement('form');
        formJst.method = 'POST';
        formJst.innerHTML = '<?php echo csrfField(); ?><input type="hidden" name="action" value="issue_jst">';
        document.body.appendChild(formJst);
        formJst.submit();
    }
    <?php endif; ?>

    function refreshKsefStatus() {
        const fkId = <?php echo $fakturowniaId ?: 0; ?>;
        if (!fkId) return;
        const btn = document.getElementById('ksef-refresh-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Sprawdzam...'; }
        fetch('/api/invoices-sale/ksef-status.php?fakturownia_id=' + fkId)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data) {
                    const d = data.data;
                    let msg = 'Status: ' + (d.gov_status || 'brak');
                    if (d.gov_id) msg += '\nNumer KSeF: ' + d.gov_id;
                    if (d.gov_error_messages && d.gov_error_messages.length > 0) msg += '\nBłędy: ' + d.gov_error_messages.join(', ');
                    alert(msg);
                    location.reload();
                } else {
                    alert('Błąd: ' + (data.error || 'nieznany'));
                }
            })
            .catch(() => alert('Błąd połączenia'))
            .finally(() => { if (btn) { btn.disabled = false; btn.textContent = 'Odśwież status KSeF'; } });
    }
    </script>
</body>
</html>
