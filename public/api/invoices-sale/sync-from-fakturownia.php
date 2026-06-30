<?php
/**
 * BRYGAD ERP - API: Uruchom backfill faktur sprzedażowych z Fakturowni
 *
 * Pobiera ostatnie faktury z Fakturowni i importuje brakujące do ERP.
 * Wywoływane ajaxem z UI. Zwraca JSON ze statusem i podsumowaniem.
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

$period = $_POST['period'] ?? 'last_30_days';
$allowedPeriods = ['last_30_days', 'this_month', 'last_month', 'this_year', 'all'];
if (!in_array($period, $allowedPeriods, true)) {
    $period = 'last_30_days';
}

$perPage = 100;

try {
    $client = new FakturowniaClient($pdo);
    $systemUserId = findSystemUserIdApi($pdo);

    $page = 1;
    $imported = 0;
    $matched = 0;
    $statusUpdated = 0;
    $skippedNoClient = 0;
    $errors = 0;

    do {
        $endpoint = '/invoices.json?income=yes&period=' . urlencode($period)
            . '&page=' . $page . '&per_page=' . $perPage;

        $response = $client->get($endpoint);
        $rows = $response['data'] ?? [];

        if (!is_array($rows) || count($rows) === 0) break;

        foreach ($rows as $rawRow) {
            try {
                $invoice = is_array($rawRow) && isset($rawRow['invoice']) ? $rawRow['invoice'] : $rawRow;
                $fakturowniaId = (int)($invoice['id'] ?? 0);
                if ($fakturowniaId <= 0) continue;

                $invoiceNumber = trim((string)($invoice['number'] ?? ''));
                $issueDate = normalizeDateApi($invoice['issue_date'] ?? null);
                $clientId = findInvestorIdApi($pdo, $invoice);

                $existingId = findLocalIdByFkId($pdo, $fakturowniaId);

                if ($existingId) {
                    $matched++;
                    $statusUpdated += updatePaymentStatusApi($pdo, $existingId, $invoice);
                    upsertMappingApi($pdo, $invoice, $existingId);
                } elseif ($clientId > 0) {
                    if (empty($invoice['positions'])) {
                        $detail = $client->get('/invoices/' . $fakturowniaId . '.json');
                        $invoice = is_array($detail['data'] ?? null) && isset($detail['data']['invoice']) ? $detail['data']['invoice'] : ($detail['data'] ?? []);
                    }
                    $existingId = insertInvoiceApi($pdo, $invoice, $clientId, $systemUserId);
                    insertItemsApi($pdo, $existingId, $invoice['positions'] ?? []);
                    $imported++;
                    upsertMappingApi($pdo, $invoice, $existingId);
                } else {
                    $skippedNoClient++;
                }
            } catch (Throwable $e) {
                $errors++;
            }
        }

        $page++;
    } while (count($rows) === $perPage);

    $parts = [];
    if ($imported > 0) $parts[] = "Zaimportowano {$imported} nowych";
    if ($statusUpdated > 0) $parts[] = "zaktualizowano status {$statusUpdated} faktur";
    if ($matched > 0 && $statusUpdated === 0) $parts[] = "{$matched} bez zmian";
    if ($skippedNoClient > 0) $parts[] = "{$skippedNoClient} pominięto (brak klienta)";
    if ($errors > 0) $parts[] = "{$errors} błędów";
    $msg = count($parts) > 0 ? implode(', ', $parts) . '.' : 'Brak faktur do synchronizacji.';

    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'matched' => $matched,
        'status_updated' => $statusUpdated,
        'skipped_no_client' => $skippedNoClient,
        'errors' => $errors,
        'message' => $msg
    ]);

} catch (FakturowniaAuthException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Błąd autoryzacji Fakturownia API']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd synchronizacji: ' . $e->getMessage()]);
}


function findSystemUserIdApi(PDO $pdo): int {
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : 1;
}

function findInvestorIdApi(PDO $pdo, array $inv): int {
    $nip = preg_replace('/[^0-9]/', '', (string)($inv['buyer_tax_no'] ?? ''));
    $name = trim((string)($inv['buyer_name'] ?? ''));
    if ($nip !== '') {
        $stmt = $pdo->prepare('SELECT id FROM investors WHERE REPLACE(REPLACE(nip,"-","")," ","") = ? LIMIT 1');
        $stmt->execute([$nip]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) return $id;
    }
    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM investors WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) return $id;
    }
    return 0;
}

function normalizeDateApi($v): ?string {
    $s = trim((string)$v);
    if ($s === '') return null;
    $ts = strtotime($s);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function normalizeMoneyApi($v): float { return round((float)($v ?? 0), 2); }

function findLocalIdByFkId(PDO $pdo, int $fkId): ?int {
    $stmt = $pdo->prepare('SELECT erp_invoice_sale_id FROM fakturownia_invoices WHERE fakturownia_id = ? AND erp_invoice_sale_id IS NOT NULL LIMIT 1');
    $stmt->execute([$fkId]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;

    $stmt2 = $pdo->prepare('SELECT id FROM invoices_sale WHERE notes LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt2->execute(['%(ID ' . $fkId . ')%']);
    $id2 = (int)$stmt2->fetchColumn();
    return $id2 > 0 ? $id2 : null;
}

function mapStatusApi(array $inv): string {
    $st = strtolower(trim((string)($inv['status'] ?? $inv['state'] ?? 'issued')));

    $paidAmount = (float)str_replace(',', '.', (string)($inv['paid'] ?? '0'));

    if (in_array($st, ['paid', 'zaplacona'], true)) return 'paid';
    if (in_array($st, ['partial'], true) || ($paidAmount > 0 && !in_array($st, ['paid', 'zaplacona'], true))) return 'partially_paid';
    if (in_array($st, ['draft', 'robocza'], true)) return 'draft';
    return 'issued';
}

function mapPaymentApi(array $inv): string {
    $t = strtolower(trim((string)($inv['payment_type'] ?? 'transfer')));
    return in_array($t, ['transfer','cash','card'], true) ? $t : 'other';
}

function updatePaymentStatusApi(PDO $pdo, int $id, array $inv): int {
    $mapped = mapStatusApi($inv);
    $changed = 0;
    if ($mapped === 'paid') {
        $paid = normalizeDateApi($inv['paid_date'] ?? null) ?: date('Y-m-d');
        $stmt = $pdo->prepare("UPDATE invoices_sale SET status='paid', payment_date=? WHERE id=? AND status<>'paid'");
        $stmt->execute([$paid, $id]);
        $changed = $stmt->rowCount();
        ensureFkSyncPayment($pdo, $id, $paid);
    } elseif ($mapped === 'partially_paid') {
        $stmt = $pdo->prepare("UPDATE invoices_sale SET status='partially_paid' WHERE id=? AND status NOT IN ('paid','partially_paid')");
        $stmt->execute([$id]);
        $changed = $stmt->rowCount();
    } elseif ($mapped === 'issued') {
        $stmt = $pdo->prepare("UPDATE invoices_sale SET status='issued' WHERE id=? AND status NOT IN ('issued','draft')");
        $stmt->execute([$id]);
        $changed = $stmt->rowCount();
    } elseif ($mapped === 'draft') {
        $stmt = $pdo->prepare("UPDATE invoices_sale SET status='draft' WHERE id=? AND status<>'draft'");
        $stmt->execute([$id]);
        $changed = $stmt->rowCount();
    }
    return $changed;
}

function ensureFkSyncPayment(PDO $pdo, int $invoiceId, string $paymentDate): void {
    $existing = $pdo->prepare('SELECT COUNT(*) FROM invoice_sale_payments WHERE invoice_id = ?');
    $existing->execute([$invoiceId]);
    if ((int)$existing->fetchColumn() > 0) return;

    $invStmt = $pdo->prepare('SELECT amount_net FROM invoices_sale WHERE id = ?');
    $invStmt->execute([$invoiceId]);
    $amount = (float)$invStmt->fetchColumn();
    if ($amount <= 0) return;

    $pdo->prepare(
        "INSERT INTO invoice_sale_payments (invoice_id, amount_net, payment_date, source, created_at) VALUES (?, ?, ?, 'fk_sync', NOW())"
    )->execute([$invoiceId, $amount, $paymentDate]);
}

function insertInvoiceApi(PDO $pdo, array $inv, int $clientId, int $createdBy): int {
    $issueDate = normalizeDateApi($inv['issue_date'] ?? null) ?: date('Y-m-d');
    $saleDate = normalizeDateApi($inv['sell_date'] ?? null) ?: $issueDate;
    $dueDate = normalizeDateApi($inv['payment_to'] ?? null) ?: $issueDate;
    $payDays = (int)($inv['payment_to_kind'] ?? 14);
    if ($payDays <= 0) $payDays = max(0, (int)((strtotime($dueDate) - strtotime($issueDate)) / 86400));

    $net = normalizeMoneyApi($inv['price_net'] ?? 0);
    $vat = normalizeMoneyApi($inv['price_tax'] ?? 0);
    $gross = normalizeMoneyApi($inv['price_gross'] ?? $inv['total'] ?? 0);

    // Zapisz kind i from_invoice_id w fakturownia_options_json (potrzebne dla korekt)
    $kind = strtolower(trim((string)($inv['kind'] ?? 'vat')));
    $fkOptions = ['kind' => $kind !== '' ? $kind : 'vat'];
    $fromInvoiceId = (int)($inv['from_invoice_id'] ?? $inv['invoice_id'] ?? 0);
    if ($fromInvoiceId > 0) {
        $fkOptions['from_invoice_id'] = $fromInvoiceId;
    }
    $fkOptionsJson = json_encode($fkOptions, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("INSERT INTO invoices_sale (invoice_number, client_id, issue_date, sale_date, due_date, payment_days, payment_method, place_of_issue, currency, amount_net, amount_vat, amount_gross, status, notes, fakturownia_options_json, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $stmt->execute([
        trim((string)($inv['number'] ?? '')), $clientId, $issueDate, $saleDate, $dueDate, $payDays,
        mapPaymentApi($inv), 'Import', strtoupper(trim((string)($inv['currency'] ?? 'PLN'))) ?: 'PLN',
        $net, $vat, $gross, mapStatusApi($inv),
        'Import z Fakturowni (ID ' . (int)($inv['id'] ?? 0) . ')',
        $fkOptionsJson, $createdBy
    ]);
    $id = (int)$pdo->lastInsertId();
    if (mapStatusApi($inv) === 'paid') {
        $paid = normalizeDateApi($inv['paid_date'] ?? null) ?: date('Y-m-d');
        $pdo->prepare('UPDATE invoices_sale SET payment_date=? WHERE id=?')->execute([$paid, $id]);
    }
    return $id;
}

function insertItemsApi(PDO $pdo, int $invoiceId, array $positions): void {
    if (empty($positions)) $positions = [['name' => 'Pozycja importowana', 'quantity' => 1, 'tax' => 23, 'price_net' => 0]];
    $stmt = $pdo->prepare("INSERT INTO invoice_sale_items (invoice_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat, amount_gross, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $sort = 0;
    foreach ($positions as $pos) {
        $it = isset($pos['position']) ? $pos['position'] : $pos;
        $qty = max(1.0, (float)($it['quantity'] ?? 1));
        $unit = trim((string)($it['unit'] ?? 'szt')) ?: 'szt';
        $vr = strtolower(trim((string)($it['tax'] ?? $it['vat'] ?? '23')));
        if (!in_array($vr, ['zw','np','0','5','8','23'], true)) $vr = '23';
        $upn = normalizeMoneyApi($it['price_net'] ?? $it['unit_price_net'] ?? 0);
        $n = normalizeMoneyApi($it['total_price_net'] ?? null); if ($n <= 0) $n = round($qty * $upn, 2);
        $v = normalizeMoneyApi($it['total_price_tax'] ?? $it['price_tax'] ?? null); if ($v <= 0) $v = round($n * (is_numeric($vr) ? (float)$vr / 100 : 0), 2);
        $g = normalizeMoneyApi($it['total_price_gross'] ?? $it['price_gross'] ?? null); if ($g <= 0) $g = round($n + $v, 2);
        $stmt->execute([$invoiceId, trim((string)($it['name'] ?? 'Pozycja')), $qty, $unit, $upn, $vr, $n, $v, $g, $sort++]);
    }
}

function upsertMappingApi(PDO $pdo, array $inv, int $localId): void {
    $fkId = (int)($inv['id'] ?? 0);
    if ($fkId <= 0) return;
    $fkNum = (string)($inv['number'] ?? '');
    $govId = (string)($inv['gov_id'] ?? '');
    $mappedSt = mapStatusApi($inv);
    // fakturownia_invoices.status ENUM: draft|sent|paid (FK bridge — "sent" = wystawiona/częściowo)
    // invoices_sale.status: draft|issued|paid|partially_paid|cancelled (ERP)
    $st = $mappedSt === 'paid' ? 'paid' : ($mappedSt === 'issued' ? 'sent' : ($mappedSt === 'partially_paid' ? 'sent' : 'draft'));
    $govSt = strtolower(trim((string)($inv['gov_status'] ?? 'pending')));
    if (in_array($govSt, ['ok','accepted'], true)) $govSt = 'ok';
    elseif (in_array($govSt, ['error','rejected'], true)) $govSt = 'error';
    else $govSt = 'pending';

    $ex = $pdo->prepare('SELECT id FROM fakturownia_invoices WHERE fakturownia_id = ? LIMIT 1');
    $ex->execute([$fkId]);
    $exId = (int)$ex->fetchColumn();

    if ($exId > 0) {
        $pdo->prepare("UPDATE fakturownia_invoices SET fakturownia_number=?, gov_id=?, gov_status=?, status=?, erp_invoice_sale_id=?, synced_at=NOW() WHERE id=?")
            ->execute([$fkNum, $govId, $govSt, $st, $localId > 0 ? $localId : null, $exId]);
    } else {
        $pdo->prepare("INSERT INTO fakturownia_invoices (fakturownia_id, fakturownia_number, gov_id, gov_status, status, erp_invoice_sale_id, request_hash, created_at, synced_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
            ->execute([$fkId, $fkNum, $govId, $govSt, $st, $localId > 0 ? $localId : null, md5('api_sync_' . $fkId)]);
    }
}
