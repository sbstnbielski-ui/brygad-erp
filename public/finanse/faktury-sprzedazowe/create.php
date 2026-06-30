<?php
/**
 * BRYGAD ERP - Wystaw Fakturę Sprzedażową (v3)
 *
 * - auto-wypełnianie danych sprzedawcy z company_settings
 * - autocomplete klienta
 * - podgląd faktury na klik (nowa karta)
 * - workflow: Zapisz roboczą / Wystaw do Fakturowni
 * - podzielona płatność
 * - opcje zaawansowane Fakturowni w accordion
 * - numeracja skorelowana z Fakturowni
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/includes/sales_invoice_correction_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

$companyStmt = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1");
$company = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token sesji (CSRF). Odśwież stronę i spróbuj ponownie.';
    }

    $client_id = trim($_POST['client_id'] ?? '');
    $invoice_number = trim($_POST['invoice_number'] ?? '');
    $issue_date = trim($_POST['issue_date'] ?? '');
    $sale_date = trim($_POST['sale_date'] ?? '');
    $due_date = trim($_POST['due_date'] ?? '');
    $payment_days = (int)($_POST['payment_days'] ?? ($company['default_payment_days'] ?? 14));
    $payment_method = trim($_POST['payment_method'] ?? ($company['default_payment_method'] ?? 'transfer'));
    $bank_account = trim($_POST['bank_account'] ?? '');
    $place_of_issue = trim($_POST['place_of_issue'] ?? ($company['default_place_of_issue'] ?? 'Czerwonak'));
    $currency = trim($_POST['currency'] ?? ($company['default_currency'] ?? 'PLN'));
    $split_payment = isset($_POST['split_payment']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    $submit_action = $_POST['submit_action'] ?? ($_POST['submit_action_btn'] ?? 'draft');

    $fkKind = strtolower(trim($_POST['fakturownia_kind'] ?? 'vat'));
    $fkDepartmentId = (int)($_POST['fakturownia_department_id'] ?? ($company['fakturownia_department_id'] ?? 0));
    $fkLang = strtolower(trim($_POST['fakturownia_lang'] ?? ($company['fakturownia_lang'] ?? 'pl')));
    $fkBuyerEmail = trim($_POST['fakturownia_buyer_email'] ?? '');
    $fkCategoryId = (int)($_POST['fakturownia_category_id'] ?? 0);
    $fkOid = trim($_POST['fakturownia_oid'] ?? '');
    $fkDescription = trim($_POST['fakturownia_description'] ?? '');
    $fkDescriptionFooter = trim($_POST['fakturownia_description_footer'] ?? ($company['default_description_footer'] ?? ''));
    $fkDescriptionLong = trim($_POST['fakturownia_description_long'] ?? '');
    $fkExchangeCurrency = strtoupper(trim($_POST['fakturownia_exchange_currency'] ?? ''));
    $fkExchangeKind = trim($_POST['fakturownia_exchange_kind'] ?? 'nbp');
    $fkExchangeRate = (float)($_POST['fakturownia_exchange_rate'] ?? 0);
    $fkPaymentType = strtolower(trim($_POST['fakturownia_payment_type'] ?? ''));
    $fkDiscountKind = trim($_POST['fakturownia_discount_kind'] ?? 'none');
    $fkSendByEmailAfterIssue = isset($_POST['fakturownia_send_by_email_after_issue']) ? 1 : 0;

    if (empty($client_id)) $errors[] = "Klient jest wymagany";
    if (empty($invoice_number)) $errors[] = "Numer faktury jest wymagany";
    if (empty($issue_date)) $errors[] = "Data wystawienia jest wymagana";
    if (empty($sale_date)) $errors[] = "Data sprzedaży jest wymagana";
    if (empty($due_date)) $errors[] = "Termin płatności jest wymagany";

    $allowedKinds = ['vat', 'proforma', 'bill', 'receipt', 'advance', 'final', 'correction', 'vat_mp', 'vat_margin'];
    if (!in_array($fkKind, $allowedKinds, true)) $fkKind = 'vat';
    $allowedLangs = ['pl', 'en', 'de', 'fr', 'it', 'es', 'cs', 'sk'];
    if (!in_array($fkLang, $allowedLangs, true)) $fkLang = 'pl';
    $allowedDiscountKinds = ['none', 'percent_unit', 'amount'];
    if (!in_array($fkDiscountKind, $allowedDiscountKinds, true)) $fkDiscountKind = 'none';

    if ($fkBuyerEmail !== '' && !filter_var($fkBuyerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Nieprawidłowy e-mail nabywcy (Fakturownia)";
    }

    $fkCorrectionReason = trim($_POST['correction_reason'] ?? '');
    $fkCorrectionOfInvoiceId = (int)($_POST['correction_of_invoice_id'] ?? 0);
    $fkCorrectedContentBefore = trim($_POST['corrected_content_before'] ?? '');
    $fkCorrectedContentAfter = trim($_POST['corrected_content_after'] ?? '');

    if ($fkKind === 'correction' && $fkCorrectionOfInvoiceId > 0) {
        $correctionPaymentTerms = sprutexCorrectionPaymentTerms($pdo, $fkCorrectionOfInvoiceId);
        if (!empty($correctionPaymentTerms['due_date'])) {
            $due_date = $correctionPaymentTerms['due_date'];
        }
        if (array_key_exists('payment_days', $correctionPaymentTerms)) {
            $payment_days = (int)$correctionPaymentTerms['payment_days'];
        }
        if (!empty($correctionPaymentTerms['payment_method'])) {
            $payment_method = $correctionPaymentTerms['payment_method'];
        }
    }

    $fakturowniaOptions = [
        'kind' => $fkKind,
        'department_id' => $fkDepartmentId > 0 ? $fkDepartmentId : null,
        'lang' => $fkLang,
        'buyer_email' => $fkBuyerEmail !== '' ? $fkBuyerEmail : null,
        'category_id' => $fkCategoryId > 0 ? $fkCategoryId : null,
        'oid' => $fkOid !== '' ? $fkOid : null,
        'description' => $fkDescription !== '' ? $fkDescription : null,
        'description_footer' => $fkDescriptionFooter !== '' ? $fkDescriptionFooter : null,
        'description_long' => $fkDescriptionLong !== '' ? $fkDescriptionLong : null,
        'exchange_currency' => $fkExchangeCurrency !== '' ? $fkExchangeCurrency : null,
        'exchange_kind' => $fkExchangeKind !== '' ? $fkExchangeKind : 'nbp',
        'exchange_rate' => $fkExchangeRate > 0 ? $fkExchangeRate : null,
        'payment_type_override' => $fkPaymentType !== '' ? $fkPaymentType : null,
        'discount_kind' => $fkDiscountKind,
        'send_by_email_after_issue' => $fkSendByEmailAfterIssue,
        'correction_of_invoice_id' => $fkCorrectionOfInvoiceId > 0 ? $fkCorrectionOfInvoiceId : null,
        'correction_reason' => $fkCorrectionReason !== '' ? $fkCorrectionReason : null,
        'corrected_content_before' => $fkCorrectedContentBefore !== '' ? $fkCorrectedContentBefore : null,
        'corrected_content_after' => $fkCorrectedContentAfter !== '' ? $fkCorrectedContentAfter : null,
    ];

    $sellerData = [
        'company_name' => $company['company_name'] ?? '',
        'company_nip' => $company['company_nip'] ?? '',
        'company_address' => $company['company_address'] ?? '',
        'company_city' => $company['company_city'] ?? '',
        'company_post_code' => $company['company_post_code'] ?? '',
        'bank_account' => $bank_account,
        'bank_name' => $company['default_bank_name'] ?? '',
        'logo_path' => $company['logo_path'] ?? null,
    ];
    $reuseDraftInvoiceId = 0;
    $archiveStandardInvoiceId = 0;

    if (empty($errors)) {
        $isCorrectionNumber = sprutexCorrectionNumberSeq($invoice_number) > 0;
        if ($fkKind === 'correction' || $isCorrectionNumber) {
            $correctionGuard = sprutexFindReusableLocalCorrectionInvoice(
                $pdo,
                $invoice_number,
                $fkCorrectionOfInvoiceId > 0 ? $fkCorrectionOfInvoiceId : null
            );

            if (!empty($correctionGuard['blocked'])) {
                $reason = trim((string)($correctionGuard['blocking_reason'] ?? ''));
                $errors[] = $reason !== ''
                    ? "Faktura z tym numerem już istnieje w systemie. {$reason}"
                    : "Faktura z tym numerem już istnieje w systemie";
            } elseif (!empty($correctionGuard['reuse_invoice_id'])) {
                $reuseDraftInvoiceId = (int)$correctionGuard['reuse_invoice_id'];
            }
        } else {
            $standardGuard = sprutexFindReusableLocalStandardInvoice($pdo, $invoice_number);
            if (!empty($standardGuard['blocked'])) {
                $reason = trim((string)($standardGuard['blocking_reason'] ?? ''));
                $errors[] = $reason !== ''
                    ? "Faktura z tym numerem już istnieje w systemie. {$reason}"
                    : "Faktura z tym numerem już istnieje w systemie";
            } elseif (!empty($standardGuard['reuse_invoice_id'])) {
                $archiveStandardInvoiceId = (int)$standardGuard['reuse_invoice_id'];
            }
        }
    }

    $file_path = null;
    if (!empty($_FILES['file']['name'])) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Dozwolone są tylko pliki PDF, JPG i PNG";
        } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            $errors[] = "Plik nie może być większy niż 10MB";
        } else {
            $upload_dir = dirname(dirname(__DIR__)) . '/uploads/invoices-sale';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_name = 'inv_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
            $destination = $upload_dir . '/' . $file_name;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
                $file_path = 'uploads/invoices-sale/' . $file_name;
            } else {
                $errors[] = "Błąd podczas przesyłania pliku";
            }
        }
    }

    $invoice_items = [];
    if (!empty($_POST['invoice_items'])) {
        $invoice_items = json_decode($_POST['invoice_items'], true);
        if (empty($invoice_items)) $errors[] = "Faktura musi zawierać przynajmniej jedną pozycję";
    } else {
        $errors[] = "Faktura musi zawierać przynajmniej jedną pozycję";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            if ($archiveStandardInvoiceId > 0) {
                sprutexArchiveLocalInvoiceNumber(
                    $pdo,
                    $archiveStandardInvoiceId,
                    'Zwolnienie numeru dla nowej faktury sprzedażowej'
                );
            }

            $total_net = 0; $total_vat = 0; $total_gross = 0;
            foreach ($invoice_items as $item) {
                $total_net += $item['amount_net'];
                $total_vat += $item['amount_vat'];
                $total_gross += $item['amount_gross'];
            }
            $financialEffectKind = $fkKind === 'correction' ? 'correction' : 'invoice';
            $correctionEffectNet = null;
            $correctionEffectVat = null;
            $correctionEffectGross = null;
            $correctionAttentionRequired = 0;
            $correctionAttentionNote = null;
            if ($financialEffectKind === 'correction') {
                $correctionEffect = sprutexCalculateInvoiceSaleCorrectionEffect($invoice_items);
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

            if ($reuseDraftInvoiceId > 0) {
                $updateSql = "
                    UPDATE invoices_sale
                    SET invoice_number = ?,
                        client_id = ?,
                        issue_date = ?,
                        sale_date = ?,
                        due_date = ?,
                        payment_days = ?,
                        payment_method = ?,
                        bank_account = ?,
                        place_of_issue = ?,
                        currency = ?,
                        split_payment = ?,
                        amount_net = ?,
                        amount_vat = ?,
                        amount_gross = ?,
                        status = 'draft',
                        financial_effect_kind = ?,
                        correction_of_invoice_id = ?,
                        correction_effect_net = ?,
                        correction_effect_vat = ?,
                        correction_effect_gross = ?,
                        correction_reason_text = ?,
                        deleted_at = NULL,
                        deleted_by = NULL,
                        delete_reason = NULL,
                        sync_attention_required = ?,
                        sync_attention_note = ?,
                        notes = ?,
                        fakturownia_options_json = ?,
                        seller_data_json = ?
                ";
                $updateParams = [
                    $invoice_number, $client_id, $issue_date, $sale_date, $due_date, $payment_days,
                    $payment_method, $bank_account ?: null, $place_of_issue, $currency, $split_payment,
                    $total_net, $total_vat, $total_gross,
                    $financialEffectKind,
                    $fkCorrectionOfInvoiceId > 0 ? $fkCorrectionOfInvoiceId : null,
                    $correctionEffectNet,
                    $correctionEffectVat,
                    $correctionEffectGross,
                    $fkCorrectionReason !== '' ? $fkCorrectionReason : null,
                    $correctionAttentionRequired,
                    $correctionAttentionNote,
                    $notes,
                    json_encode($fakturowniaOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($sellerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                if ($file_path !== null) {
                    $updateSql .= ", file_path = ?";
                    $updateParams[] = $file_path;
                }
                $updateSql .= " WHERE id = ?";
                $updateParams[] = $reuseDraftInvoiceId;
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute($updateParams);
                $invoice_id = $reuseDraftInvoiceId;
                $pdo->prepare("DELETE FROM invoice_sale_items WHERE invoice_id = ?")->execute([$invoice_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO invoices_sale
                    (invoice_number, client_id, issue_date, sale_date, due_date, payment_days, payment_method, bank_account, place_of_issue, currency, split_payment, amount_net, amount_vat, amount_gross, status, financial_effect_kind, correction_of_invoice_id, correction_effect_net, correction_effect_vat, correction_effect_gross, correction_reason_text, sync_attention_required, sync_attention_note, file_path, notes, fakturownia_options_json, seller_data_json, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $invoice_number, $client_id, $issue_date, $sale_date, $due_date, $payment_days,
                    $payment_method, $bank_account ?: null, $place_of_issue, $currency, $split_payment,
                    $total_net, $total_vat, $total_gross,
                    $financialEffectKind,
                    $fkCorrectionOfInvoiceId > 0 ? $fkCorrectionOfInvoiceId : null,
                    $correctionEffectNet,
                    $correctionEffectVat,
                    $correctionEffectGross,
                    $fkCorrectionReason !== '' ? $fkCorrectionReason : null,
                    $correctionAttentionRequired,
                    $correctionAttentionNote,
                    $file_path, $notes,
                    json_encode($fakturowniaOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($sellerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $_SESSION['user_id']
                ]);
                $invoice_id = (int)$pdo->lastInsertId();
            }

            $stmt_item = $pdo->prepare("
                INSERT INTO invoice_sale_items
                (invoice_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat, amount_gross, fakturownia_item_options_json, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $sort_order = 0;
            foreach ($invoice_items as $item) {
                $itemOptions = [
                    'product_id' => isset($item['product_id']) && (int)$item['product_id'] > 0 ? (int)$item['product_id'] : null,
                    'product_code' => trim((string)($item['product_code'] ?? '')) ?: null,
                    'gtu_code' => null,
                    'discount_percent' => 0,
                    'discount' => 0,
                ];
                // Dla korekty — zapisujemy oryginalne wartości "przed" korektą
                if ($fkKind === 'correction' && !empty($item['correction_before'])) {
                    $itemOptions['correction_before'] = $item['correction_before'];
                }
                $stmt_item->execute([
                    $invoice_id, $item['name'], $item['quantity'], $item['unit'], $item['unit_price'],
                    $item['vat_rate'], $item['amount_net'], $item['amount_vat'], $item['amount_gross'],
                    json_encode($itemOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sort_order++
                ]);
            }
            $pdo->commit();
            logEvent(($reuseDraftInvoiceId > 0 ? "Zaktualizowano roboczą fakturę sprzedażową" : "Utworzono fakturę sprzedażową") . ": {$invoice_number}, ID: {$invoice_id}", 'INFO');

            if ($submit_action === 'issue') {
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'auto_issue' => '1', 'confirmed' => '1']));
            } else {
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'created']));
            }
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logEvent("Błąd tworzenia faktury sprzedażowej: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia faktury";
        }
    }
}

$clients_stmt = $pdo->query("SELECT id, name, nip, address, email, phone FROM investors WHERE is_active = 1 ORDER BY name ASC");
$clients = $clients_stmt->fetchAll();

// Numer faktury — format Fakturowni: N/MM/YYYY (np. 3/03/2026)
$year = date('Y');
$month = date('m');
$fkPattern = "%/{$month}/{$year}";

// Najwyższy numer w naszej bazie i Fakturowni.
// Lokalne szkice/anulowane bez Fakturowni/KSeF nie rezerwują numeru.
$maxLocalSeq = sprutexMaxLocalStandardInvoiceSeq($pdo, $month, $year);
$maxFkSeq = sprutexMaxFakturowniaStandardInvoiceSeq($pdo, $month, $year);

// Fallback: sprawdź też stary format FS/YYYY/MM/NNN
$maxOldSeq = 0;
try {
    $oldStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices_sale WHERE invoice_number LIKE ?");
    $oldStmt->execute(["FS/{$year}/{$month}/%"]);
    $maxOldSeq = (int)$oldStmt->fetchColumn();
} catch (Throwable $e) {}

$nextSeq = max($maxLocalSeq, $maxFkSeq, $maxOldSeq) + 1;
$suggested_number = "{$nextSeq}/{$month}/{$year}";

// Numeracja korekt — liczymy tylko numery faktycznie wystawione, nie robocze próby po błędzie API.
$corrMaxLocal = 0;
try {
    $corrStmt = $pdo->query("
        SELECT invoice_number
        FROM invoices_sale
        WHERE invoice_number REGEXP '^K[[:space:]]*0*[0-9]+$'
          AND status IN ('issued', 'paid', 'partially_paid')
          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
    ");
    while ($corrNumber = $corrStmt->fetchColumn()) {
        $corrMaxLocal = max($corrMaxLocal, sprutexCorrectionNumberSeq($corrNumber));
    }
} catch (Throwable $e) {
    try {
        $corrStmt = $pdo->query("
            SELECT invoice_number
            FROM invoices_sale
            WHERE invoice_number REGEXP '^K[[:space:]]*0*[0-9]+$'
              AND status IN ('issued', 'paid', 'partially_paid')
        ");
        while ($corrNumber = $corrStmt->fetchColumn()) {
            $corrMaxLocal = max($corrMaxLocal, sprutexCorrectionNumberSeq($corrNumber));
        }
    } catch (Throwable $ignored) {}
}
$corrMaxFk = 0;
try {
    $corrFkStmt = $pdo->query("
        SELECT fakturownia_number
        FROM fakturownia_invoices
        WHERE fakturownia_number REGEXP '^K[[:space:]]*0*[0-9]+$'
          AND fakturownia_id IS NOT NULL
          AND status IN ('sent', 'paid')
    ");
    while ($corrNumber = $corrFkStmt->fetchColumn()) {
        $corrMaxFk = max($corrMaxFk, sprutexCorrectionNumberSeq($corrNumber));
    }
} catch (Throwable $e) {}
$corrNextSeq = max($corrMaxLocal, $corrMaxFk) + 1;
$suggested_correction_number = "K" . $corrNextSeq;

// Prefill from correction_of or clone_from
$prefill = null;
$prefillItems = [];
$prefillMode = null;
$correctionOfId = (int)($_GET['correction_of'] ?? 0);
$cloneFromId = (int)($_GET['clone_from'] ?? 0);
$sourceId = $correctionOfId ?: $cloneFromId;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $correctionOfId > 0) {
    $existingDraftCorrectionId = sprutexFindActiveDraftCorrectionForSource($pdo, $correctionOfId);
    if ($existingDraftCorrectionId > 0) {
        header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $existingDraftCorrectionId, 'success' => 'existing_correction_draft']));
        exit;
    }
}
if ($sourceId > 0) {
    $prefillMode = $correctionOfId ? 'correction' : 'clone';
    $srcStmt = $pdo->prepare("SELECT inv.*, i.name AS client_name, i.nip AS client_nip, i.id AS client_id FROM invoices_sale inv LEFT JOIN investors i ON inv.client_id = i.id WHERE inv.id = ?");
    $srcStmt->execute([$sourceId]);
    $prefill = $srcStmt->fetch();
    if ($prefill) {
        $itmStmt = $pdo->prepare("SELECT * FROM invoice_sale_items WHERE invoice_id = ? ORDER BY sort_order, id");
        $itmStmt->execute([$sourceId]);
        $prefillItems = $itmStmt->fetchAll();
    }
    if ($prefillMode === 'correction' && $prefill) {
        $suggested_number = $suggested_correction_number;
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

$defaultBankAccount = $company['default_bank_account'] ?? '';
$defaultPlaceOfIssue = $company['default_place_of_issue'] ?? 'Czerwonak';
$defaultPaymentDays = (int)($company['default_payment_days'] ?? 14);
$defaultPaymentMethod = $company['default_payment_method'] ?? 'transfer';
$defaultCurrency = $company['default_currency'] ?? 'PLN';
$defaultDescriptionFooter = $company['default_description_footer'] ?? '';
$defaultDepartmentId = $company['fakturownia_department_id'] ?? '';
$defaultLang = $company['fakturownia_lang'] ?? 'pl';
$prefillCorrectionPaymentTerms = ($prefillMode === 'correction' && $correctionOfId > 0)
    ? sprutexCorrectionPaymentTerms($pdo, $correctionOfId)
    : [];

$formIssueDate = $_POST['issue_date'] ?? date('Y-m-d');
$formSaleDate = $_POST['sale_date'] ?? (
    $prefillMode === 'correction' && $prefill && !empty($prefill['sale_date'])
        ? $prefill['sale_date']
        : date('Y-m-d')
);
$formPaymentDays = ($prefillMode === 'correction' && array_key_exists('payment_days', $prefillCorrectionPaymentTerms))
    ? (int)$prefillCorrectionPaymentTerms['payment_days']
    : ($_POST['payment_days'] ?? (
        $prefillMode === 'correction' && $prefill && isset($prefill['payment_days'])
            ? (int)$prefill['payment_days']
            : $defaultPaymentDays
    ));
$formDueDate = ($prefillMode === 'correction' && !empty($prefillCorrectionPaymentTerms['due_date']))
    ? $prefillCorrectionPaymentTerms['due_date']
    : ($_POST['due_date'] ?? (
        $prefillMode === 'correction' && $prefill && !empty($prefill['due_date'])
            ? $prefill['due_date']
            : date('Y-m-d', strtotime('+' . $defaultPaymentDays . ' days'))
    ));
$formPaymentMethod = ($prefillMode === 'correction' && !empty($prefillCorrectionPaymentTerms['payment_method']))
    ? $prefillCorrectionPaymentTerms['payment_method']
    : ($_POST['payment_method'] ?? (
        $prefillMode === 'correction' && $prefill && !empty($prefill['payment_method'])
            ? $prefill['payment_method']
            : $defaultPaymentMethod
    ));
$formBankAccount = $_POST['bank_account'] ?? (
    $defaultBankAccount !== ''
        ? $defaultBankAccount
        : ($prefillMode === 'correction' && $prefill ? ($prefill['bank_account'] ?? '') : '')
);
$formCurrency = $_POST['currency'] ?? (
    $prefillMode === 'correction' && $prefill && !empty($prefill['currency'])
        ? $prefill['currency']
        : $defaultCurrency
);
$formPlaceOfIssue = $_POST['place_of_issue'] ?? (
    $prefillMode === 'correction' && $prefill && !empty($prefill['place_of_issue'])
        ? $prefill['place_of_issue']
        : $defaultPlaceOfIssue
);
$formSplitPaymentChecked = isset($_POST['split_payment'])
    || ($prefillMode === 'correction' && $prefill && !empty($prefill['split_payment']));
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Wystaw Fakturę Sprzedażową</title>
    <style>
        :root {
            --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb;
            --text-main: #1f2937; --text-muted: #6b7280;
            --success: #16a34a; --success-dark: #15803d; --danger: #ef4444;
        }
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

        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 24px; font-weight: 700; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 13px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 18px; border: 1px solid var(--border); }
        .section-title { font-size: 16px; font-weight: 700; color: #0f172a; border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .section-title .badge-count { background: #e0e7ff; color: #3730a3; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        @media (max-width: 1100px) {
            .form-grid, .form-grid-2 { grid-template-columns: 1fr 1fr; }
            .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        }
        @media (max-width: 700px) {
            .form-grid, .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
        }
        .form-group { display: flex; flex-direction: column; min-width: 0; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .required { color: #dc2626; }
        .form-group input, .form-group select, .form-group textarea { padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; transition: all 0.15s; width: 100%; background: #fff; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .form-group textarea { resize: vertical; min-height: 60px; }
        .help-text { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

        .client-search-wrap { position: relative; }
        .client-search-input { width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .client-search-input:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .client-dropdown { position: absolute; top: 100%; left: 0; right: 0; z-index: 100; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; margin-top: 2px; max-height: 260px; overflow-y: auto; display: none; box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .client-dropdown.show { display: block; }
        .client-option { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background 0.1s; }
        .client-option:hover { background: #f0fdf4; }
        .client-option-name { font-weight: 600; font-size: 14px; }
        .client-option-nip { font-size: 12px; color: var(--text-muted); }
        .client-selected { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; }
        .client-selected-name { font-weight: 600; font-size: 14px; }
        .client-selected-nip { font-size: 12px; color: var(--text-muted); }
        .client-selected-clear { background: none; border: none; color: #dc2626; cursor: pointer; font-size: 18px; margin-left: auto; padding: 0 4px; }

        .accordion-header { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 0; font-size: 14px; font-weight: 600; color: #475569; user-select: none; }
        .accordion-header:hover { color: #1e3a8a; }
        .accordion-arrow { transition: transform 0.2s; font-size: 12px; }
        .accordion-arrow.open { transform: rotate(90deg); }
        .accordion-body { display: none; padding-top: 10px; }
        .accordion-body.open { display: block; }

        .items-table { width: 100%; border-collapse: collapse; }
        .items-table thead th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; border-bottom: 2px solid var(--border); background: #f8fafc; }
        .items-table tbody td { padding: 8px 6px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .items-table tfoot td { padding: 10px; border-top: 2px solid var(--border); font-weight: 700; font-size: 14px; }
        .items-table input, .items-table select { padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; width: 100%; background: #fff; }
        .items-table input:focus, .items-table select:focus { outline: none; border-color: var(--success); }
        .item-remove { background: none; border: none; color: #dc2626; cursor: pointer; font-size: 16px; padding: 4px 8px; border-radius: 4px; }
        .item-remove:hover { background: #fee2e2; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }

        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; }
        .btn-primary { background: linear-gradient(135deg, var(--success), var(--success-dark)); color: #fff; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .btn-outline:hover { border-color: #9ca3af; background: #f9fafb; }
        .btn-blue { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
        .btn-blue:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .form-actions { display: flex; gap: 10px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid var(--border); flex-wrap: wrap; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .alert-info { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }

        .checkbox-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
        .checkbox-row input[type="checkbox"] { width: auto; margin: 0; }
        .checkbox-row label { font-size: 13px; font-weight: 500; color: #374151; text-transform: none; letter-spacing: 0; margin: 0; cursor: pointer; }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>">Faktury</a> /
                    Nowa faktura
                </div>
                <h1><?php echo $prefillMode === 'correction' ? 'Wystaw korektę' : 'Wystaw fakturę sprzedażową'; ?></h1>
                <p>Dane sprzedawcy z <a href="<?php echo url('finanse.company-settings'); ?>" style="color:#93c5fd; text-decoration:underline;">ustawień firmy</a></p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błędy:</strong>
                <ul style="margin: 6px 0 0 18px;">
                    <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" id="invoiceForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="submit_action" id="submit_action" value="draft">

            <!-- KLIENT -->
            <div class="card">
                <div class="section-title">Nabywca (klient)</div>
                <input type="hidden" name="client_id" id="client_id" value="<?php echo e($_POST['client_id'] ?? ($prefill['client_id'] ?? '')); ?>">
                <?php if ($correctionOfId > 0 && $prefill): ?>
                    <input type="hidden" name="correction_of_invoice_id" value="<?php echo $correctionOfId; ?>">
                    <div class="help-text" style="margin-bottom:8px;color:#2563eb;font-weight:600;">Korekta do faktury: <?php echo e($prefill['invoice_number']); ?></div>
                <?php endif; ?>
                <div id="client-selected" class="client-selected" style="display:none;">
                    <div>
                        <div class="client-selected-name" id="client-selected-name"></div>
                        <div class="client-selected-nip" id="client-selected-nip"></div>
                    </div>
                    <button type="button" class="client-selected-clear" onclick="clearClient()" title="Zmień klienta">&times;</button>
                </div>
                <div id="client-search-wrapper" class="client-search-wrap">
                    <input type="text" class="client-search-input" id="client-search" placeholder="Szukaj klienta po nazwie lub NIP..." autocomplete="off">
                    <div class="client-dropdown" id="client-dropdown"></div>
                </div>
                <div style="margin-top: 8px;">
                    <a href="<?php echo url('investors.create'); ?>?return_to=<?php echo urlencode('/finanse/faktury-sprzedazowe/create.php'); ?>" style="font-size: 12px; color: #2563eb;">+ Dodaj nowego klienta</a>
                </div>
            </div>

            <!-- DANE DOKUMENTU -->
            <div class="card">
                <div class="section-title">Dane dokumentu</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Numer faktury <span class="required">*</span></label>
                        <input type="text" name="invoice_number" id="invoice_number" value="<?php echo e($_POST['invoice_number'] ?? $suggested_number); ?>" required>
                        <div class="help-text">Numer uwzględnia faktury z Fakturowni. Numer nadany przez Fakturownię może się różnić.</div>
                    </div>
                    <div class="form-group">
                        <label>Rodzaj dokumentu</label>
                        <?php
                        $kindSelected = $_POST['fakturownia_kind'] ?? ($prefillMode === 'correction' ? 'correction' : 'vat');
                        if ($prefill && $prefillMode === 'clone') {
                            $srcOpts = json_decode($prefill['fakturownia_options_json'] ?? '{}', true);
                            $kindSelected = $_POST['fakturownia_kind'] ?? ($srcOpts['kind'] ?? 'vat');
                        }
                        ?>
                        <select name="fakturownia_kind" id="fakturownia_kind">
                            <option value="vat" <?php echo $kindSelected === 'vat' ? 'selected' : ''; ?>>Faktura VAT</option>
                            <option value="proforma" <?php echo $kindSelected === 'proforma' ? 'selected' : ''; ?>>Proforma</option>
                            <option value="advance" <?php echo $kindSelected === 'advance' ? 'selected' : ''; ?>>Zaliczkowa</option>
                            <option value="final" <?php echo $kindSelected === 'final' ? 'selected' : ''; ?>>Końcowa</option>
                            <option value="correction" <?php echo $kindSelected === 'correction' ? 'selected' : ''; ?>>Korekta</option>
                            <option value="bill" <?php echo $kindSelected === 'bill' ? 'selected' : ''; ?>>Rachunek</option>
                            <option value="receipt" <?php echo $kindSelected === 'receipt' ? 'selected' : ''; ?>>Paragon</option>
                            <option value="vat_mp" <?php echo $kindSelected === 'vat_mp' ? 'selected' : ''; ?>>VAT MP</option>
                            <option value="vat_margin" <?php echo $kindSelected === 'vat_margin' ? 'selected' : ''; ?>>VAT marża</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Data wystawienia <span class="required">*</span></label>
                        <input type="date" name="issue_date" id="issue_date" value="<?php echo e($formIssueDate); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Data sprzedaży <span class="required">*</span></label>
                        <input type="date" name="sale_date" id="sale_date" value="<?php echo e($formSaleDate); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Termin płatności <span class="required">*</span></label>
                        <input type="date" name="due_date" id="due_date" value="<?php echo e($formDueDate); ?>" required <?php echo $prefillMode === 'correction' ? 'readonly' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Termin (dni)</label>
                        <input type="number" name="payment_days" id="payment_days" value="<?php echo e($formPaymentDays); ?>" min="0" max="365" <?php echo $prefillMode === 'correction' ? 'readonly' : ''; ?>>
                    </div>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Sposób płatności</label>
                        <?php $pmSel = $formPaymentMethod; ?>
                        <select name="payment_method" id="payment_method">
                            <option value="transfer" <?php echo $pmSel === 'transfer' ? 'selected' : ''; ?>>Przelew</option>
                            <option value="cash" <?php echo $pmSel === 'cash' ? 'selected' : ''; ?>>Gotówka</option>
                            <option value="card" <?php echo $pmSel === 'card' ? 'selected' : ''; ?>>Karta</option>
                            <option value="other" <?php echo $pmSel === 'other' ? 'selected' : ''; ?>>Inny</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Konto bankowe</label>
                        <input type="text" name="bank_account" id="bank_account" value="<?php echo e($formBankAccount); ?>" placeholder="XX XXXX XXXX XXXX XXXX XXXX XXXX">
                    </div>
                    <div class="form-group">
                        <label>Waluta</label>
                        <?php $curSel = $formCurrency; ?>
                        <select name="currency" id="currency">
                            <option value="PLN" <?php echo $curSel === 'PLN' ? 'selected' : ''; ?>>PLN</option>
                            <option value="EUR" <?php echo $curSel === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                            <option value="USD" <?php echo $curSel === 'USD' ? 'selected' : ''; ?>>USD</option>
                            <option value="GBP" <?php echo $curSel === 'GBP' ? 'selected' : ''; ?>>GBP</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Miejsce wystawienia</label>
                        <input type="text" name="place_of_issue" id="place_of_issue" value="<?php echo e($formPlaceOfIssue); ?>">
                    </div>
                </div>
                <div class="checkbox-row" style="margin-bottom: 4px;">
                    <input type="checkbox" name="split_payment" id="split_payment" value="1" <?php echo $formSplitPaymentChecked ? 'checked' : ''; ?>>
                    <label for="split_payment">Mechanizm podzielonej płatności</label>
                </div>
                <?php if ($prefillMode === 'correction'): ?>
                <div class="form-group" style="margin-top: 8px;" id="correction-reason-wrap">
                    <label>Powód korekty</label>
                    <input type="text" name="correction_reason" id="correction_reason" value="<?php echo e($_POST['correction_reason'] ?? ''); ?>" placeholder="np. Błędna ilość, błędna cena netto...">
                    <div class="help-text">Wymagane przez Fakturownię dla faktur korygujących.</div>
                </div>
                <div class="form-grid-2" style="margin-top: 8px;">
                    <div class="form-group">
                        <label>Treść korygowana</label>
                        <input type="text" name="corrected_content_before" id="corrected_content_before" value="<?php echo e($_POST['corrected_content_before'] ?? ''); ?>" placeholder="np. opis błędnej treści na fakturze">
                    </div>
                    <div class="form-group">
                        <label>Treść prawidłowa</label>
                        <input type="text" name="corrected_content_after" id="corrected_content_after" value="<?php echo e($_POST['corrected_content_after'] ?? ''); ?>" placeholder="np. opis prawidłowej treści po korekcie">
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- POZYCJE -->
            <div class="card">
                <div class="section-title">Pozycje faktury <span class="badge-count" id="items-count-badge">0</span></div>
                <?php if ($prefillMode === 'correction'): ?>
                <div style="overflow-x: auto;">
                    <table class="items-table" id="items-table" style="min-width:900px;">
                        <thead>
                            <tr>
                                <th rowspan="2" style="min-width:180px; vertical-align:middle;">Nazwa</th>
                                <th rowspan="2" style="width:60px; text-align:center; vertical-align:middle;">Jedn.</th>
                                <th rowspan="2" style="width:60px; text-align:center; vertical-align:middle;">VAT</th>
                                <th colspan="3" style="text-align:center; background:#fef3c7; color:#92400e; border-bottom:1px solid #f59e0b;">Przed korektą</th>
                                <th colspan="3" style="text-align:center; background:#d1fae5; color:#065f46; border-bottom:1px solid #10b981;">Po korekcie</th>
                                <th rowspan="2" style="width:28px; vertical-align:middle;"></th>
                            </tr>
                            <tr>
                                <th style="width:70px; text-align:right; background:#fef9e7; color:#92400e;">Ilość</th>
                                <th style="width:100px; text-align:right; background:#fef9e7; color:#92400e;">Cena netto</th>
                                <th style="width:100px; text-align:right; background:#fef9e7; color:#92400e;">Brutto</th>
                                <th style="width:70px; text-align:right; background:#f0fdf4; color:#065f46;">Ilość</th>
                                <th style="width:100px; text-align:right; background:#f0fdf4; color:#065f46;">Cena netto</th>
                                <th style="width:100px; text-align:right; background:#f0fdf4; color:#065f46;">Brutto</th>
                            </tr>
                        </thead>
                        <tbody id="items-tbody"></tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7" class="text-right" style="font-size:13px; color:#64748b;">RAZEM po korekcie (brutto):</td>
                                <td class="text-right" id="total-gross" style="color:#065f46; font-weight:700;">0,00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="items-table" id="items-table">
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
                        <tbody id="items-tbody"></tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="text-right" style="font-size:13px; color:#64748b;">RAZEM:</td>
                                <td class="text-right" id="total-net">0,00</td>
                                <td class="text-right" id="total-vat">0,00</td>
                                <td class="text-right" id="total-gross" style="color:#1e3a8a;">0,00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
                <button type="button" onclick="addInvoiceItem()" class="btn btn-sm btn-outline" style="margin-top: 10px;">+ Dodaj pozycję</button>
            </div>

            <!-- UWAGI -->
            <div class="card">
                <div class="section-title">Uwagi i załącznik</div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Notatki / uwagi na fakturze</label>
                    <textarea name="notes" id="notes"><?php echo e($_POST['notes'] ?? ($company['default_notes'] ?? '')); ?></textarea>
                </div>
                <div class="form-group">
                    <label>Załącznik (PDF/JPG/PNG)</label>
                    <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="help-text">Maks. 10MB</div>
                </div>
            </div>

            <!-- OPCJE ZAAWANSOWANE -->
            <div class="card">
                <div class="accordion-header" onclick="toggleAccordion(this)">
                    <span class="accordion-arrow">▸</span>
                    Opcje zaawansowane Fakturowni
                </div>
                <div class="accordion-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Dział sprzedawcy</label>
                            <input type="number" name="fakturownia_department_id" value="<?php echo e($_POST['fakturownia_department_id'] ?? $defaultDepartmentId); ?>" min="1" placeholder="zostaw puste = domyślny">
                            <div class="help-text">Tylko jeśli masz kilka firm/oddziałów w Fakturowni.</div>
                        </div>
                        <div class="form-group">
                            <label>Język dokumentu</label>
                            <?php $langSel = $_POST['fakturownia_lang'] ?? $defaultLang; ?>
                            <select name="fakturownia_lang">
                                <option value="pl" <?php echo $langSel === 'pl' ? 'selected' : ''; ?>>Polski</option>
                                <option value="en" <?php echo $langSel === 'en' ? 'selected' : ''; ?>>English</option>
                                <option value="de" <?php echo $langSel === 'de' ? 'selected' : ''; ?>>Deutsch</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>E-mail nabywcy</label>
                            <input type="email" name="fakturownia_buyer_email" id="fakturownia_buyer_email" value="<?php echo e($_POST['fakturownia_buyer_email'] ?? ''); ?>" placeholder="klient@firma.pl">
                        </div>
                        <div class="form-group">
                            <label>OID / nr zamówienia</label>
                            <input type="text" name="fakturownia_oid" value="<?php echo e($_POST['fakturownia_oid'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Kategoria (ID)</label>
                            <input type="number" name="fakturownia_category_id" value="<?php echo e($_POST['fakturownia_category_id'] ?? ''); ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label>Rabat</label>
                            <?php $dkSel = $_POST['fakturownia_discount_kind'] ?? 'none'; ?>
                            <select name="fakturownia_discount_kind">
                                <option value="none" <?php echo $dkSel === 'none' ? 'selected' : ''; ?>>Brak</option>
                                <option value="percent_unit" <?php echo $dkSel === 'percent_unit' ? 'selected' : ''; ?>>Procent</option>
                                <option value="amount" <?php echo $dkSel === 'amount' ? 'selected' : ''; ?>>Kwotowo</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Typ płatności override</label>
                            <?php $ptSel = $_POST['fakturownia_payment_type'] ?? ''; ?>
                            <select name="fakturownia_payment_type">
                                <option value="" <?php echo $ptSel === '' ? 'selected' : ''; ?>>Z pola podstawowego</option>
                                <option value="transfer" <?php echo $ptSel === 'transfer' ? 'selected' : ''; ?>>transfer</option>
                                <option value="cash" <?php echo $ptSel === 'cash' ? 'selected' : ''; ?>>cash</option>
                                <option value="card" <?php echo $ptSel === 'card' ? 'selected' : ''; ?>>card</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Waluta kursowa</label>
                            <input type="text" name="fakturownia_exchange_currency" value="<?php echo e($_POST['fakturownia_exchange_currency'] ?? ''); ?>" maxlength="3" placeholder="EUR">
                        </div>
                        <div class="form-group">
                            <label>Rodzaj kursu</label>
                            <?php $ekSel = $_POST['fakturownia_exchange_kind'] ?? 'nbp'; ?>
                            <select name="fakturownia_exchange_kind">
                                <option value="nbp" <?php echo $ekSel === 'nbp' ? 'selected' : ''; ?>>NBP</option>
                                <option value="ecb" <?php echo $ekSel === 'ecb' ? 'selected' : ''; ?>>ECB</option>
                                <option value="fixed" <?php echo $ekSel === 'fixed' ? 'selected' : ''; ?>>Stały</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Kurs wymiany</label>
                            <input type="number" step="0.000001" min="0" name="fakturownia_exchange_rate" value="<?php echo e($_POST['fakturownia_exchange_rate'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="checkbox-row" style="margin-bottom: 10px;">
                        <input type="checkbox" name="fakturownia_send_by_email_after_issue" id="fk_send_email" value="1" <?php echo !empty($_POST['fakturownia_send_by_email_after_issue']) ? 'checked' : ''; ?>>
                        <label for="fk_send_email">Wyślij e-mailem po wystawieniu</label>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Opis na fakturze</label>
                        <textarea name="fakturownia_description"><?php echo e($_POST['fakturownia_description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Stopka opisu</label>
                        <textarea name="fakturownia_description_footer"><?php echo e($_POST['fakturownia_description_footer'] ?? $defaultDescriptionFooter); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Opis rozszerzony</label>
                        <textarea name="fakturownia_description_long"><?php echo e($_POST['fakturownia_description_long'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- AKCJE -->
            <div class="card">
                <div class="alert alert-info" style="margin-bottom: 14px;">
                            <strong>Zalecany flow:</strong> Kliknij "Podgląd" → sprawdź fakturę → utwórz w Fakturowni → sprawdź PDF z Fakturowni → dopiero wyślij do KSeF.
                </div>
                <div class="form-actions">
                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn btn-secondary">Anuluj</a>
                    <button type="button" onclick="openInvoicePreview()" class="btn btn-primary" style="font-size:15px; padding:12px 28px;">Podgląd faktury</button>
                    <button type="submit" name="submit_action_btn" value="draft" class="btn btn-outline btn-sm">Zapisz w roboczych (bez podglądu)</button>
                </div>
            </div>
        </form>
    </div>

    <script>
    // ═══════ KLIENT ═══════
    const allClients = <?php echo json_encode(array_map(function($c) {
        return ['id' => $c['id'], 'name' => $c['name'], 'nip' => $c['nip'] ?? '', 'address' => $c['address'] ?? '', 'email' => $c['email'] ?? ''];
    }, $clients), JSON_UNESCAPED_UNICODE); ?>;

    const clientSearch = document.getElementById('client-search');
    const clientDropdown = document.getElementById('client-dropdown');
    const clientIdInput = document.getElementById('client_id');
    const clientSelectedDiv = document.getElementById('client-selected');
    const clientSearchWrapper = document.getElementById('client-search-wrapper');
    let selectedClient = null;

    function renderClientDropdown(filter) {
        const q = (filter || '').toLowerCase();
        const filtered = allClients.filter(c => c.name.toLowerCase().includes(q) || (c.nip && c.nip.includes(q))).slice(0, 20);
        if (filtered.length === 0) {
            clientDropdown.innerHTML = '<div style="padding:12px; color:#94a3b8; font-size:13px; text-align:center;">Brak wyników</div>';
        } else {
            clientDropdown.innerHTML = filtered.map(c => `
                <div class="client-option" data-id="${c.id}" data-name="${c.name}" data-nip="${c.nip}" data-email="${c.email}">
                    <div class="client-option-name">${c.name}</div>
                    ${c.nip ? '<div class="client-option-nip">NIP: ' + c.nip + '</div>' : ''}
                </div>`).join('');
        }
        clientDropdown.classList.add('show');
    }
    clientSearch.addEventListener('input', () => renderClientDropdown(clientSearch.value));
    clientSearch.addEventListener('focus', () => renderClientDropdown(clientSearch.value));
    document.addEventListener('click', (e) => { if (!e.target.closest('.client-search-wrap')) clientDropdown.classList.remove('show'); });
    clientDropdown.addEventListener('click', (e) => {
        const opt = e.target.closest('.client-option');
        if (opt) selectClient(opt.dataset.id, opt.dataset.name, opt.dataset.nip, opt.dataset.email);
    });

    function selectClient(id, name, nip, email) {
        selectedClient = {id, name, nip, email};
        clientIdInput.value = id;
        document.getElementById('client-selected-name').textContent = name;
        document.getElementById('client-selected-nip').textContent = nip ? 'NIP: ' + nip : '';
        clientSelectedDiv.style.display = 'flex';
        clientSearchWrapper.style.display = 'none';
        clientDropdown.classList.remove('show');
        if (email && !document.getElementById('fakturownia_buyer_email').value) {
            document.getElementById('fakturownia_buyer_email').value = email;
        }
    }
    function clearClient() {
        selectedClient = null; clientIdInput.value = '';
        clientSelectedDiv.style.display = 'none'; clientSearchWrapper.style.display = 'block';
        clientSearch.value = ''; clientSearch.focus();
    }
    <?php
    $autoClientId = !empty($_POST['client_id']) ? $_POST['client_id'] : ($prefill['client_id'] ?? '');
    if ($autoClientId):
        $selClient = null;
        foreach ($clients as $c) { if ($c['id'] == $autoClientId) { $selClient = $c; break; } }
        if ($selClient): ?>
    selectClient('<?php echo $selClient['id']; ?>', '<?php echo e($selClient['name']); ?>', '<?php echo e($selClient['nip']); ?>', '<?php echo e($selClient['email'] ?? ''); ?>');
    <?php endif; endif; ?>

    // ═══════ POZYCJE ═══════
    const isCorrectionMode = <?php echo $prefillMode === 'correction' ? 'true' : 'false'; ?>;
    let itemCounter = 0;
    let erpProducts = [];
    let erpProductsByName = {};

    function normalizeKey(v) { return (v||'').toString().trim().toLowerCase(); }

    function loadErpProducts() {
        fetch('/api/products/list.php?limit=500')
            .then(r => r.json())
            .then(d => {
                if (d.success && d.items) {
                    erpProducts = d.items;
                    erpProductsByName = {};
                    d.items.forEach(p => {
                        erpProductsByName[normalizeKey(p.name)] = p;
                        if (p.code) erpProductsByName[normalizeKey(p.code)] = p;
                    });
                }
            }).catch(() => {});
    }
    loadErpProducts();

    function applyProductDefaults(row, name) {
        const p = erpProductsByName[normalizeKey(name)];
        if (!p) return;
        const priceIn = row.querySelector('.item-price');
        const unitSel = row.querySelector('.item-unit');
        const vatSel = row.querySelector('.item-vat');
        const pidIn = row.querySelector('.item-product-id');
        const pcodeIn = row.querySelector('.item-product-code');
        if (parseFloat(priceIn.value) <= 0 && parseFloat(p.price_net||0) > 0) priceIn.value = parseFloat(p.price_net).toFixed(2);
        if (p.unit) unitSel.value = p.unit;
        if (p.vat_rate) { const v = p.vat_rate.toString().toLowerCase(); if (['zw','0','5','8','23'].includes(v)) vatSel.value = v; }
        if (p.fakturownia_product_id > 0) { pidIn.value = p.fakturownia_product_id; }
        if (p.code) pcodeIn.value = p.code;
        calcRow(row);
    }

    function setupNameAutocomplete(nameIn, row) {
        let dropdown = null;
        function showDropdown(items) {
            hideDropdown();
            if (items.length === 0) return;
            dropdown = document.createElement('div');
            dropdown.className = 'product-dropdown';
            dropdown.style.cssText = 'position:absolute;top:100%;left:0;right:0;z-index:200;background:#fff;border:1px solid #d1d5db;border-radius:8px;margin-top:2px;max-height:220px;overflow-y:auto;box-shadow:0 8px 25px rgba(0,0,0,0.12);';
            items.forEach(p => {
                const opt = document.createElement('div');
                opt.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:13px;';
                opt.innerHTML = '<strong>' + escH(p.name) + '</strong>' +
                    (p.code ? ' <span style="color:#6b7280;font-size:11px;">[' + escH(p.code) + ']</span>' : '') +
                    ' <span style="color:#16a34a;font-size:12px;">' + parseFloat(p.price_net||0).toFixed(2) + ' zł</span>';
                opt.addEventListener('mousedown', e => { e.preventDefault(); nameIn.value = p.name; hideDropdown(); applyProductDefaults(row, p.name); });
                opt.addEventListener('mouseover', () => opt.style.background = '#f0fdf4');
                opt.addEventListener('mouseout', () => opt.style.background = '#fff');
                dropdown.appendChild(opt);
            });
            nameIn.parentElement.style.position = 'relative';
            nameIn.parentElement.appendChild(dropdown);
        }
        function hideDropdown() { if (dropdown) { dropdown.remove(); dropdown = null; } }

        nameIn.addEventListener('input', () => {
            const q = normalizeKey(nameIn.value);
            if (q.length < 1) { hideDropdown(); return; }
            const filtered = erpProducts.filter(p =>
                normalizeKey(p.name).includes(q) || (p.code && normalizeKey(p.code).includes(q))
            ).slice(0, 15);
            showDropdown(filtered);
        });
        nameIn.addEventListener('blur', () => { setTimeout(hideDropdown, 150); applyProductDefaults(row, nameIn.value); });
        nameIn.addEventListener('keydown', e => { if (e.key === 'Escape') hideDropdown(); });
    }

    function saveToProductCatalog(row) {
        const name = row.querySelector('.item-name').value.trim();
        if (!name) return;
        if (erpProductsByName[normalizeKey(name)]) { alert('Ten towar/usługa jest już w katalogu.'); return; }
        const unit = row.querySelector('.item-unit').value;
        const vatRate = row.querySelector('.item-vat').value;
        const priceNet = row.querySelector('.item-price').value || '0';
        const body = new URLSearchParams({ name, unit, vat_rate: vatRate, price_net: priceNet });
        fetch('/api/products/save.php', { method: 'POST', body })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.mode !== 'exists') {
                    loadErpProducts();
                    const btn = row.querySelector('.btn-save-catalog');
                    if (btn) { btn.textContent = 'Zapisano!'; btn.disabled = true; btn.style.color = '#16a34a'; }
                } else if (d.mode === 'exists') {
                    alert('Już istnieje w katalogu.');
                }
            }).catch(() => {});
    }

    function addInvoiceItem(data = {}) {
        itemCounter++;
        const tbody = document.getElementById('items-tbody');
        const row = document.createElement('tr');
        const d = {
            name: data.name || data.item_name || '',
            product_id: data.product_id || '',
            product_code: data.product_code || '',
            quantity: parseFloat(data.quantity) || 1,
            unit: data.unit || 'szt',
            unit_price: parseFloat(data.unit_price || data.price) || 0,
            vat_rate: (data.vat_rate || data.vat || '23').toString(),
            correction_before: data.correction_before || null,
        };

        const unitOpts = ['szt','usł','godz','m2','mb','kg','kpl'].map(u =>
            `<option value="${u}" ${d.unit===u?'selected':''}>${u==='m2'?'m²':u}</option>`).join('');
        const vatOpts = [['23','23%'],['8','8%'],['5','5%'],['0','0%'],['zw','zw']].map(([v,l]) =>
            `<option value="${v}" ${d.vat_rate===v?'selected':''}>${l}</option>`).join('');
        const cbJson = d.correction_before ? JSON.stringify(d.correction_before).replace(/"/g,'&quot;') : '';

        if (isCorrectionMode) {
            // Wiersz korekty: Przed (read-only) | Po (edytowalne)
            const cb = d.correction_before || {};
            const bQty   = parseFloat(cb.quantity)      || d.quantity;
            const bPrice = parseFloat(cb.unit_price_net) || d.unit_price;
            const bVat   = cb.vat_rate                   || d.vat_rate;
            const bGross = parseFloat(cb.amount_gross)   || 0;
            const bGrossDisp = bGross ? bGross.toFixed(2).replace('.',',') : calcGross(bQty, bPrice, bVat).toFixed(2).replace('.',',');

            row.innerHTML = `
                <td style="background:#fafafa;">
                    <strong style="font-size:13px;">${escH(d.name)}</strong>
                    <input type="hidden" class="item-product-id" value="${escH(String(d.product_id))}">
                    <input type="hidden" class="item-product-code" value="${escH(String(d.product_code))}">
                    <input type="hidden" class="item-correction-before" value="${cbJson}">
                </td>
                <td style="text-align:center; background:#fafafa;">
                    <select class="item-unit">${unitOpts}</select>
                </td>
                <td style="text-align:center; background:#fafafa;">
                    <select class="item-vat">${vatOpts}</select>
                </td>
                <td style="text-align:right; background:#fef9e7; color:#92400e; font-size:13px;" class="before-qty-display">${bQty}</td>
                <td style="text-align:right; background:#fef9e7; color:#92400e; font-size:13px;" class="before-price-display">${bPrice.toFixed(2).replace('.',',')}</td>
                <td style="text-align:right; background:#fef9e7; color:#92400e; font-size:13px; font-weight:600;" class="before-gross-display">${bGrossDisp}</td>
                <td style="background:#f0fdf4;"><input type="number" class="item-quantity" value="${d.quantity}" step="0.01" style="text-align:center; width:70px; border:1px solid #a7f3d0; border-radius:4px; padding:4px;"></td>
                <td style="background:#f0fdf4;"><input type="number" class="item-price" value="${d.unit_price.toFixed(2)}" step="0.01" min="0" style="text-align:right; width:100px; border:1px solid #a7f3d0; border-radius:4px; padding:4px;"></td>
                <td style="text-align:right; background:#f0fdf4; color:#065f46; font-size:13px; font-weight:600;" class="item-gross-display">0,00</td>
                <td><button type="button" class="item-remove" onclick="removeItem(this)" style="color:#dc2626; background:none; border:none; cursor:pointer; font-size:16px;">&times;</button></td>
            `;
            tbody.appendChild(row);
            row.querySelectorAll('.item-quantity, .item-price, .item-vat').forEach(el => el.addEventListener('input', () => calcCorrRow(row)));
            calcCorrRow(row);
        } else {
            row.innerHTML = `
                <td><input type="text" class="item-name" value="${escH(d.name)}" placeholder="Nazwa usługi/towaru" autocomplete="off">
                    <input type="hidden" class="item-product-id" value="${escH(String(d.product_id))}">
                    <input type="hidden" class="item-product-code" value="${escH(String(d.product_code))}">
                    <input type="hidden" class="item-correction-before" value="">
                    <button type="button" class="btn-save-catalog" onclick="saveToProductCatalog(this.closest('tr'))" title="Zapisz do katalogu towarów" style="background:none;border:none;cursor:pointer;font-size:11px;color:#6b7280;margin-top:2px;display:block;">+ katalog</button></td>
                <td><input type="number" class="item-quantity" value="${d.quantity}" step="0.01" min="0" style="text-align:center;"></td>
                <td><select class="item-unit">${unitOpts}</select></td>
                <td><input type="number" class="item-price" value="${d.unit_price}" step="0.01" min="0" style="text-align:right;"></td>
                <td><select class="item-vat">${vatOpts}</select></td>
                <td class="text-right item-net-display" style="font-weight:600;">0,00</td>
                <td class="text-right item-vat-display">0,00</td>
                <td class="text-right item-gross-display" style="font-weight:600;">0,00</td>
                <td><button type="button" class="item-remove" onclick="removeItem(this)">&times;</button></td>
            `;
            tbody.appendChild(row);
            row.querySelectorAll('.item-quantity, .item-price, .item-vat').forEach(el => el.addEventListener('input', () => calcRow(row)));
            const nameIn = row.querySelector('.item-name');
            setupNameAutocomplete(nameIn, row);
            calcRow(row);
            if (d.name) applyProductDefaults(row, d.name);
        }
        updateItemCount();
    }

    function escH(s) {
        return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function calcGross(qty, price, vatRate) {
        const mult = vatRate === 'zw' ? 1 : (1 + parseFloat(vatRate) / 100);
        return qty * price * mult;
    }

    function calcCorrRow(row) {
        const qty   = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const vr    = row.querySelector('.item-vat').value;
        const gross = calcGross(qty, price, vr);
        row.querySelector('.item-gross-display').textContent = gross.toFixed(2).replace('.',',');
        updateCorrTotals();
    }

    function updateCorrTotals() {
        let tG = 0;
        document.querySelectorAll('#items-tbody tr').forEach(r => {
            const d = r.querySelector('.item-gross-display');
            if (d) tG += parseFloat(d.textContent.replace(',','.')) || 0;
        });
        const el = document.getElementById('total-gross');
        if (el) el.textContent = tG.toFixed(2).replace('.',',');
    }

    function removeItem(btn) {
        btn.closest('tr').remove();
        if (isCorrectionMode) updateCorrTotals(); else updateTotals();
        updateItemCount();
    }

    function calcRow(row) {
        const qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        const vatRate = row.querySelector('.item-vat').value;
        const net = qty * price;
        const vat = vatRate === 'zw' ? 0 : net * (parseFloat(vatRate) / 100);
        row.querySelector('.item-net-display').textContent = net.toFixed(2).replace('.',',');
        row.querySelector('.item-vat-display').textContent = vat.toFixed(2).replace('.',',');
        row.querySelector('.item-gross-display').textContent = (net+vat).toFixed(2).replace('.',',');
        updateTotals();
    }

    function updateTotals() {
        let tN=0, tV=0, tG=0;
        document.querySelectorAll('#items-tbody tr').forEach(r => {
            tN += parseFloat(r.querySelector('.item-net-display') ? r.querySelector('.item-net-display').textContent.replace(',','.') : 0) || 0;
            tV += parseFloat(r.querySelector('.item-vat-display') ? r.querySelector('.item-vat-display').textContent.replace(',','.') : 0) || 0;
            tG += parseFloat(r.querySelector('.item-gross-display') ? r.querySelector('.item-gross-display').textContent.replace(',','.') : 0) || 0;
        });
        const tn = document.getElementById('total-net');
        const tv = document.getElementById('total-vat');
        const tg = document.getElementById('total-gross');
        if (tn) tn.textContent = tN.toFixed(2).replace('.',',');
        if (tv) tv.textContent = tV.toFixed(2).replace('.',',');
        if (tg) tg.textContent = tG.toFixed(2).replace('.',',');
    }

    function updateItemCount() {
        document.getElementById('items-count-badge').textContent = document.querySelectorAll('#items-tbody tr').length;
    }

    <?php
    $prefillItemsJs = [];
    foreach ($prefillItems as $pi) {
        $itemName = $pi['item_name'] ?? $pi['name'] ?? '';
        $itemOpts = json_decode((string)($pi['fakturownia_item_options_json'] ?? '{}'), true) ?: [];
        $entry = [
            'name'         => $itemName,
            'quantity'     => (string)(float)$pi['quantity'],
            'unit'         => $pi['unit'] ?? 'szt.',
            'price'        => (string)(float)$pi['unit_price_net'],
            'vat'          => $pi['vat_rate'] ?? '23',
            'product_id'   => $itemOpts['product_id'] ?? '',
            'product_code' => $itemOpts['product_code'] ?? '',
        ];
        if ($prefillMode === 'correction') {
            $entry['correction_before'] = [
                'name'          => $itemName,
                'quantity'      => (string)(float)$pi['quantity'],
                'unit_price_net'=> (string)(float)$pi['unit_price_net'],
                'vat_rate'      => $pi['vat_rate'] ?? '23',
                'amount_net'    => (string)(float)$pi['amount_net'],
                'amount_gross'  => (string)(float)$pi['amount_gross'],
            ];
        }
        $prefillItemsJs[] = $entry;
    }
    ?>
    <?php if (!empty($prefillItemsJs)): ?>
    <?php foreach ($prefillItemsJs as $jsItem): ?>
    addInvoiceItem(<?php echo json_encode($jsItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
    <?php endforeach; ?>
    <?php else: ?>
    addInvoiceItem();
    <?php endif; ?>

    // ═══════ AUTO-DATA ═══════
    const issueDateIn = document.getElementById('issue_date');
    const paymentDaysIn = document.getElementById('payment_days');
    const dueDateIn = document.getElementById('due_date');
    function updateDueDate() {
        if (isCorrectionMode) return;
        const d = new Date(issueDateIn.value);
        const days = parseInt(paymentDaysIn.value) || 0;
        if (issueDateIn.value && !isNaN(days)) { d.setDate(d.getDate() + days); dueDateIn.value = d.toISOString().split('T')[0]; }
    }
    issueDateIn.addEventListener('change', updateDueDate);
    paymentDaysIn.addEventListener('input', updateDueDate);

    // ═══════ ACCORDION ═══════
    function toggleAccordion(header) {
        const body = header.nextElementSibling;
        const arrow = header.querySelector('.accordion-arrow');
        body.classList.toggle('open');
        arrow.classList.toggle('open');
    }

    // ═══════ ZBIERZ POZYCJE ═══════
    function collectItems() {
        const rows = document.querySelectorAll('#items-tbody tr');
        const items = [];
        rows.forEach(row => {
            const cbRaw = row.querySelector('.item-correction-before') ? row.querySelector('.item-correction-before').value : '';
            let correctionBefore = null;
            try { if (cbRaw) correctionBefore = JSON.parse(cbRaw); } catch(e) {}

            if (isCorrectionMode) {
                // W trybie korekty nazwa jest w <strong>, nie w inpucie
                const nameEl = row.querySelector('td strong');
                const name = nameEl ? nameEl.textContent.trim() : '';
                if (!name) return;
                const qty      = parseFloat(row.querySelector('.item-quantity').value) || 0;
                const price    = parseFloat(row.querySelector('.item-price').value) || 0;
                const vatRate  = row.querySelector('.item-vat').value;
                const gross    = parseFloat(row.querySelector('.item-gross-display').textContent.replace(',','.')) || 0;
                const vatMult  = vatRate === 'zw' ? 0 : parseFloat(vatRate) / 100;
                const net      = price * qty;
                const vat      = net * vatMult;
                const item = {
                    name,
                    product_id: parseInt((row.querySelector('.item-product-id') || {}).value || '0', 10) || null,
                    product_code: (row.querySelector('.item-product-code') || {}).value || '',
                    quantity: qty,
                    unit: row.querySelector('.item-unit').value,
                    unit_price: price,
                    vat_rate: vatRate,
                    gtu_code: '', discount_percent: 0, discount: 0,
                    amount_net: Math.round(net * 100) / 100,
                    amount_vat: Math.round(vat * 100) / 100,
                    amount_gross: gross,
                };
                if (correctionBefore) item.correction_before = correctionBefore;
                items.push(item);
            } else {
                const nameEl = row.querySelector('.item-name');
                const name = nameEl ? nameEl.value.trim() : '';
                if (!name) return;
                const item = {
                    name,
                    product_id: parseInt(row.querySelector('.item-product-id').value, 10) || null,
                    product_code: row.querySelector('.item-product-code').value || '',
                    quantity: parseFloat(row.querySelector('.item-quantity').value) || 0,
                    unit: row.querySelector('.item-unit').value,
                    unit_price: parseFloat(row.querySelector('.item-price').value) || 0,
                    vat_rate: row.querySelector('.item-vat').value,
                    gtu_code: '', discount_percent: 0, discount: 0,
                    amount_net:   parseFloat(row.querySelector('.item-net-display').textContent.replace(',','.')) || 0,
                    amount_vat:   parseFloat(row.querySelector('.item-vat-display').textContent.replace(',','.')) || 0,
                    amount_gross: parseFloat(row.querySelector('.item-gross-display').textContent.replace(',','.')) || 0,
                };
                if (correctionBefore) item.correction_before = correctionBefore;
                items.push(item);
            }
        });
        return items;
    }

    // ═══════ SUBMIT (formularz) ═══════
    document.getElementById('invoiceForm').addEventListener('submit', function(e) {
        const items = collectItems();
        if (items.length === 0) { e.preventDefault(); alert('Dodaj przynajmniej jedną pozycję!'); return false; }
        let existing = this.querySelector('input[name="invoice_items"]');
        if (!existing) { existing = document.createElement('input'); existing.type = 'hidden'; existing.name = 'invoice_items'; this.appendChild(existing); }
        existing.value = JSON.stringify(items);
        const submitAction = document.getElementById('submit_action');
        if (!submitAction.value) submitAction.value = 'draft';
    });

    // ═══════ PODGLĄD W NOWEJ KARCIE ═══════
    const companyData = <?php echo json_encode([
        'name' => $company['company_name'] ?? 'SPRUTEX Sp. z o.o.',
        'nip' => $company['company_nip'] ?? '',
        'address' => ($company['company_address'] ?? '') . ', ' . ($company['company_post_code'] ?? '') . ' ' . ($company['company_city'] ?? ''),
        'bank_account' => $company['default_bank_account'] ?? '',
        'bank_name' => $company['default_bank_name'] ?? '',
        'logo_path' => $company['logo_path'] ?? null,
        'phone' => $company['company_phone'] ?? '',
        'email' => $company['company_email'] ?? '',
        'website' => $company['company_website'] ?? '',
        'issuer_name' => $company['issuer_name'] ?? ($userName ?? ''),
    ], JSON_UNESCAPED_UNICODE); ?>;

    function numberToWordsPL(amount) {
        const jednosci = ['','jeden','dwa','trzy','cztery','pięć','sześć','siedem','osiem','dziewięć'];
        const nastki = ['dziesięć','jedenaście','dwanaście','trzynaście','czternaście','piętnaście','szesnaście','siedemnaście','osiemnaście','dziewiętnaście'];
        const dziesiatki = ['','dziesięć','dwadzieścia','trzydzieści','czterdzieści','pięćdziesiąt','sześćdziesiąt','siedemdziesiąt','osiemdziesiąt','dziewięćdziesiąt'];
        const setki = ['','sto','dwieście','trzysta','czterysta','pięćset','sześćset','siedemset','osiemset','dziewięćset'];
        function groupToWords(n) {
            if (n === 0) return '';
            let result = '';
            const s = Math.floor(n / 100), remainder = n % 100, d = Math.floor(remainder / 10), j = remainder % 10;
            if (s > 0) result += setki[s] + ' ';
            if (remainder >= 10 && remainder <= 19) { result += nastki[remainder - 10] + ' '; }
            else { if (d > 0) result += dziesiatki[d] + ' '; if (j > 0) result += jednosci[j] + ' '; }
            return result.trim();
        }
        function pluralForm(n, forms) {
            if (n === 1) return forms[0];
            const ld = n % 10, lt = n % 100;
            if (ld >= 2 && ld <= 4 && (lt < 12 || lt > 14)) return forms[1];
            return forms[2];
        }
        function intToWords(num) {
            if (num === 0) return 'zero';
            const groups = []; let temp = num;
            while (temp > 0) { groups.push(temp % 1000); temp = Math.floor(temp / 1000); }
            const thousands = [['','',''],['tysiąc','tysiące','tysięcy'],['milion','miliony','milionów']];
            let words = '';
            for (let i = groups.length - 1; i >= 0; i--) {
                if (groups[i] === 0) continue;
                words += groupToWords(groups[i]);
                if (i > 0 && thousands[i]) words += ' ' + pluralForm(groups[i], thousands[i]);
                words += ' ';
            }
            return words.trim();
        }
        const zlote = Math.floor(Math.abs(amount)), grosze = Math.round((Math.abs(amount) - zlote) * 100);
        let result = intToWords(zlote) + ' ' + pluralForm(zlote, ['złoty', 'złote', 'złotych']);
        if (grosze > 0) result += ' ' + intToWords(grosze) + ' ' + pluralForm(grosze, ['grosz', 'grosze', 'groszy']);
        else result += ' zero groszy';
        return result;
    }

    function openInvoicePreview() {
        const items = collectItems();
        if (items.length === 0) { alert('Dodaj przynajmniej jedną pozycję!'); return; }
        if (!document.getElementById('client_id').value) { alert('Wybierz klienta!'); return; }

        const invNum    = document.getElementById('invoice_number').value || '-';
        const kind      = document.getElementById('fakturownia_kind').value;
        const issueDate = document.getElementById('issue_date').value;
        const saleDate  = document.getElementById('sale_date').value;
        const dueDate   = document.getElementById('due_date').value;
        const payMethod = document.getElementById('payment_method');
        const payLabel  = payMethod.options[payMethod.selectedIndex].text;
        const bankAcc   = document.getElementById('bank_account').value;
        const currency  = document.getElementById('currency').value;
        const splitPay  = document.getElementById('split_payment').checked;
        const notes     = document.getElementById('notes').value;
        const buyerName = selectedClient ? selectedClient.name : '-';
        const buyerNip  = selectedClient ? selectedClient.nip : '';
        const corrReason = document.getElementById('correction_reason') ? document.getElementById('correction_reason').value : '';

        const kindLabels = {vat:'Faktura VAT', proforma:'Proforma', advance:'Faktura zaliczkowa', final:'Faktura końcowa', correction:'Faktura korygująca', bill:'Rachunek', receipt:'Paragon', vat_mp:'VAT MP', vat_margin:'VAT marża'};
        const kindLabel = kindLabels[kind] || 'Faktura VAT';
        const fmtDate = (s) => { if (!s) return '-'; const p = s.split('-'); return p.length===3?p[2]+'.'+p[1]+'.'+p[0]:s; };
        const fmtN = (v) => parseFloat(v||0).toFixed(2).replace('.',',');

        function buildPreviewFallbackForm(itemsSnapshot) {
            const fd = new FormData(document.getElementById('invoiceForm'));
            fd.delete('submit_action');
            fd.delete('submit_action_btn');
            fd.delete('invoice_items');
            fd.append('invoice_items', JSON.stringify(itemsSnapshot));

            let html = '<form id="preview-fallback-form" method="POST" action="' + escH(window.location.href) + '" style="display:none;">'
                + '<input type="hidden" name="submit_action" id="preview-submit-action" value="draft">';
            fd.forEach((value, name) => {
                if (typeof File !== 'undefined' && value instanceof File) return;
                html += '<input type="hidden" name="' + escH(name) + '" value="' + escH(String(value)) + '">';
            });
            html += '</form>';
            return html;
        }

        const fallbackFormHtml = buildPreviewFallbackForm(items);

        const logoHtml = companyData.logo_path
            ? '<img src="/' + companyData.logo_path + '" style="max-height:60px;">'
            : '<div style="font-size:20px;font-weight:800;color:#1e3a8a;">' + companyData.name + '</div>';

        const previewScript = '<scr'+'ipt>'
            + 'function submitFromOpener(action){try{if(!window.opener||window.opener.closed)return false;var f=window.opener.document.getElementById("invoiceForm");var a=window.opener.document.getElementById("submit_action");if(!f||!a)return false;a.value=action;f.requestSubmit();window.close();return true;}catch(e){return false;}}'
            + 'function submitFallback(action){var f=document.getElementById("preview-fallback-form");var a=document.getElementById("preview-submit-action");if(!f||!a){alert("Nie udało się przekazać danych formularza. Wróć do formularza i kliknij ponownie.");return;}a.value=action;f.submit();}'
            + 'function saveAsDraft(){if(!submitFromOpener("draft"))submitFallback("draft");}'
            + 'function saveAndIssue(){if(!confirm("Utworzyć dokument w Fakturowni? Po tym kroku sprawdź PDF z Fakturowni i dopiero wyślij do KSeF."))return;if(!submitFromOpener("issue"))submitFallback("issue");}'
            + '</scr'+'ipt>';

        const notesHtml = notes ? '<div style="margin-bottom:14px;padding:12px;background:#f8fafc;border-radius:6px;font-size:13px;color:#64748b;"><strong>Uwagi:</strong><br>' + notes.replace(/\n/g,'<br>') + '</div>' : '';

        const commonCss = '*{margin:0;padding:0;box-sizing:border-box;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2937;background:#f5f7fa;}'
            + '.toolbar{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 30px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.06);}'
            + '.toolbar-title{font-weight:700;font-size:15px;color:#1e3a8a;margin-right:auto;}'
            + '.btn{padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}'
            + '.btn-green{background:#16a34a;color:#fff;}.btn-green:hover{background:#15803d;}'
            + '.btn-blue{background:#2563eb;color:#fff;}.btn-blue:hover{background:#1d4ed8;}'
            + '.btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}.btn-outline:hover{background:#f9fafb;}'
            + '.btn-gray{background:#f3f4f6;color:#374151;}.btn-gray:hover{background:#e5e7eb;}'
            + '.invoice{max-width:820px;margin:30px auto;background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:40px;border:1px solid #e5e7eb;}'
            + 'table{width:100%;border-collapse:collapse;}th{padding:7px 10px;font-size:11px;text-transform:uppercase;font-weight:700;}'
            + 'td{padding:7px 10px;font-size:13px;border-bottom:1px solid #f1f5f9;}'
            + '@media print{.toolbar{display:none!important;}.invoice{box-shadow:none;border:none;margin:0;padding:20px;border-radius:0;}}';

        const headerHtml = '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:14px;border-bottom:3px solid #1e3a8a;">'
            + '<div>' + logoHtml + '</div>'
            + '<div style="text-align:right;">'
            + '<div style="font-size:22px;font-weight:800;color:#1e3a8a;">' + kindLabel + '</div>'
            + '<div style="font-size:14px;color:#64748b;margin-top:2px;">' + invNum + '</div>'
            + '<div style="margin-top:6px;display:inline-block;background:#fef3c7;color:#92400e;font-size:11px;padding:3px 12px;border-radius:4px;font-weight:600;">PODGLĄD — niezapisana</div>'
            + '</div></div>';

        const partiesHtml = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px;">'
            + '<div><div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#94a3b8;margin-bottom:6px;">Sprzedawca</div>'
            + '<div style="font-weight:700;font-size:15px;">' + companyData.name + '</div>'
            + '<div style="font-size:13px;color:#64748b;line-height:1.7;">' + (companyData.nip?'NIP: '+companyData.nip+'<br>':'') + companyData.address + (companyData.email?'<br>'+companyData.email:'') + (companyData.phone?'<br>Tel: '+companyData.phone:'') + '</div></div>'
            + '<div><div style="font-size:10px;text-transform:uppercase;font-weight:700;color:#94a3b8;margin-bottom:6px;">Nabywca</div>'
            + '<div style="font-weight:700;font-size:15px;">' + buyerName + '</div>'
            + '<div style="font-size:13px;color:#64748b;">' + (buyerNip?'NIP: '+buyerNip:'') + '</div></div></div>';

        const datesHtml = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:20px;padding:14px;background:#f8fafc;border-radius:8px;font-size:13px;">'
            + '<div><span style="color:#94a3b8;">Data wystawienia:</span> <strong>' + fmtDate(issueDate) + '</strong></div>'
            + '<div><span style="color:#94a3b8;">Data sprzedaży:</span> <strong>' + fmtDate(saleDate) + '</strong></div>'
            + '<div><span style="color:#94a3b8;">Termin płatności:</span> <strong>' + fmtDate(dueDate) + '</strong></div>'
            + '<div><span style="color:#94a3b8;">Sposób płatności:</span> <strong>' + payLabel + '</strong></div>'
            + '</div>';

        const payFooter = '<div class="payment-footer" style="margin-top:20px;">'
            + '<div style="font-size:13px;margin-bottom:16px;line-height:1.8;"><strong>Termin płatności:</strong> ' + fmtDate(dueDate) + '<br><strong>Płatność:</strong> ' + payLabel
            + (bankAcc?'<br><strong>Rachunki bankowe:</strong><br>'+(companyData.bank_name?companyData.bank_name+'<br>':'')+'<span style="font-size:14px;letter-spacing:0.5px;">'+bankAcc+'</span>':'')
            + '</div>'
            + (splitPay?'<div style="background:#fef3c7;color:#92400e;padding:10px 16px;border-radius:6px;font-size:13px;font-weight:600;margin-bottom:14px;">Mechanizm podzielonej płatności</div>':'')
            + notesHtml
            + '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:12px;color:#94a3b8;">Imię i nazwisko wystawcy<br><strong style="color:#1f2937;font-size:14px;">' + (companyData.issuer_name||'') + '</strong></div>'
            + '</div>';

        let positionsHtml = '';

        if (kind === 'correction') {
            // ---- TABELA KOREKTY: Przed / Po / Korekta ----
            const corrReasonHtml = corrReason
                ? '<div style="margin-bottom:14px;padding:10px 14px;background:#fef3c7;border-radius:6px;font-size:13px;color:#92400e;"><strong>Powód korekty:</strong> ' + corrReason + '</div>'
                : '';

            const thStyle   = 'padding:8px 10px;font-size:11px;font-weight:700;text-transform:uppercase;text-align:right;border-bottom:2px solid;';
            const thStyleL  = 'padding:8px 10px;font-size:11px;font-weight:700;text-transform:uppercase;text-align:left;border-bottom:2px solid;';
            const thBefore  = thStyle + 'background:#fef9e7;color:#92400e;border-color:#f59e0b;';
            const thAfter   = thStyle + 'background:#f0fdf4;color:#065f46;border-color:#10b981;';
            const thDiff    = thStyle + 'background:#eff6ff;color:#1e40af;border-color:#3b82f6;';
            const thGroup   = 'padding:6px 10px;font-size:10px;font-weight:700;text-transform:uppercase;text-align:center;letter-spacing:0.5px;';

            let corrItemsHtml = '';
            let tGrossBefore = 0, tGrossAfter = 0, tGrossDiff = 0;

            items.forEach((it) => {
                const cb = it.correction_before || {};
                const bQty   = parseFloat(cb.quantity)       || parseFloat(it.quantity);
                const bPrice = parseFloat(cb.unit_price_net)  || parseFloat(it.unit_price);
                const bVat   = cb.vat_rate                    || it.vat_rate;
                const bGross = parseFloat(cb.amount_gross)    || 0;
                const aGross = parseFloat(it.amount_gross)    || calcGross(it.quantity, it.unit_price, it.vat_rate);
                const diff   = aGross - bGross;
                tGrossBefore += bGross; tGrossAfter += aGross; tGrossDiff += diff;
                const vatLabel = (v) => v === 'zw' ? 'zw' : v + '%';
                const diffStyle = diff < 0 ? 'color:#dc2626;font-weight:700;' : (diff > 0 ? 'color:#16a34a;font-weight:700;' : 'color:#64748b;');

                corrItemsHtml += '<tr>'
                    + '<td style="padding:8px 10px;font-weight:600;vertical-align:middle;" rowspan="1">' + it.name + '</td>'
                    + '<td style="padding:8px 10px;text-align:center;vertical-align:middle;">' + it.unit + '</td>'
                    + '<td style="padding:8px 10px;text-align:center;vertical-align:middle;">' + vatLabel(it.vat_rate) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#fef9e7;color:#92400e;">' + bQty + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#fef9e7;color:#92400e;">' + fmtN(bPrice) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#fef9e7;color:#92400e;font-weight:700;">' + fmtN(bGross) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#f0fdf4;color:#065f46;">' + it.quantity + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#f0fdf4;color:#065f46;">' + fmtN(it.unit_price) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#f0fdf4;color:#065f46;font-weight:700;">' + fmtN(aGross) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;background:#eff6ff;' + diffStyle + '">' + (diff >= 0 ? '+' : '') + fmtN(diff) + '</td>'
                    + '</tr>';
            });

            positionsHtml = corrReasonHtml
                + '<table style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
                + '<thead>'
                + '<tr>'
                + '<th style="' + thStyleL + 'background:#f8fafc;color:#374151;border-color:#e5e7eb;" rowspan="2">Nazwa</th>'
                + '<th style="' + thStyleL.replace('right','center') + 'background:#f8fafc;color:#374151;border-color:#e5e7eb;" rowspan="2">Jedn.</th>'
                + '<th style="' + thStyleL.replace('right','center') + 'background:#f8fafc;color:#374151;border-color:#e5e7eb;" rowspan="2">VAT</th>'
                + '<th colspan="3" style="' + thGroup + 'background:#fef3c7;color:#92400e;border-bottom:2px solid #f59e0b;">Przed korektą</th>'
                + '<th colspan="3" style="' + thGroup + 'background:#dcfce7;color:#065f46;border-bottom:2px solid #10b981;">Po korekcie</th>'
                + '<th style="' + thGroup + 'background:#dbeafe;color:#1e40af;border-bottom:2px solid #3b82f6;" rowspan="2">Korekta<br>(brutto)</th>'
                + '</tr>'
                + '<tr>'
                + '<th style="' + thBefore + '">Ilość</th><th style="' + thBefore + '">Cena netto</th><th style="' + thBefore + '">Brutto</th>'
                + '<th style="' + thAfter + '">Ilość</th><th style="' + thAfter + '">Cena netto</th><th style="' + thAfter + '">Brutto</th>'
                + '</tr>'
                + '</thead>'
                + '<tbody>' + corrItemsHtml + '</tbody>'
                + '<tfoot><tr>'
                + '<td colspan="3" style="padding:10px;text-align:right;font-weight:700;font-size:13px;color:#64748b;">RAZEM:</td>'
                + '<td colspan="2" style="background:#fef9e7;"></td>'
                + '<td style="padding:10px;text-align:right;background:#fef9e7;color:#92400e;font-weight:800;">' + fmtN(tGrossBefore) + '</td>'
                + '<td colspan="2" style="background:#f0fdf4;"></td>'
                + '<td style="padding:10px;text-align:right;background:#f0fdf4;color:#065f46;font-weight:800;">' + fmtN(tGrossAfter) + '</td>'
                + '<td style="padding:10px;text-align:right;background:#eff6ff;color:#1e40af;font-weight:800;">' + (tGrossDiff>=0?'+':'') + fmtN(tGrossDiff) + '</td>'
                + '</tr></tfoot>'
                + '</table>'
                + '<div style="border:2px solid #1e3a8a;border-radius:8px;padding:16px;margin-bottom:20px;">'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;"><span>Wartość korekty (brutto):</span><span style="font-weight:700;color:' + (tGrossDiff<0?'#dc2626':'#16a34a') + ';">' + (tGrossDiff>=0?'+':'') + fmtN(tGrossDiff) + ' ' + currency + '</span></div>'
                + '</div>';

        } else {
            // ---- TABELA ZWYKŁEJ FAKTURY ----
            let tNet=0, tVat=0, tGross=0;
            let itemsHtml = '';
            items.forEach((it, i) => {
                tNet += it.amount_net; tVat += it.amount_vat; tGross += it.amount_gross;
                itemsHtml += '<tr>'
                    + '<td style="text-align:center;">' + (i+1) + '</td>'
                    + '<td>' + it.name + '</td>'
                    + '<td style="text-align:right;">' + it.quantity + '</td>'
                    + '<td>' + it.unit + '</td>'
                    + '<td style="text-align:right;">' + fmtN(it.unit_price) + '</td>'
                    + '<td style="text-align:center;">' + (it.vat_rate==='zw'?'zw':it.vat_rate+'%') + '</td>'
                    + '<td style="text-align:right;">' + fmtN(it.amount_net) + '</td>'
                    + '<td style="text-align:right;font-weight:600;">' + fmtN(it.amount_gross) + '</td>'
                    + '</tr>';
            });
            positionsHtml = '<table style="margin-bottom:16px;">'
                + '<thead><tr style="background:#1e3a8a;color:#fff;">'
                + '<th style="text-align:center;width:36px;">#</th><th>Nazwa towaru / usługi</th>'
                + '<th style="text-align:right;">Ilość</th><th>Jedn.</th>'
                + '<th style="text-align:right;">Cena netto</th><th style="text-align:center;">VAT</th>'
                + '<th style="text-align:right;">Wartość netto</th><th style="text-align:right;">Wartość brutto</th>'
                + '</tr></thead>'
                + '<tbody>' + itemsHtml + '</tbody></table>'
                + '<div style="border:2px solid #1e3a8a;border-radius:8px;padding:16px;margin-bottom:20px;">'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;"><span>Wartość netto:</span><span>' + fmtN(tNet) + ' ' + currency + '</span></div>'
                + '<div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px;"><span>Wartość VAT:</span><span>' + fmtN(tVat) + ' ' + currency + '</span></div>'
                + '<div style="display:flex;justify-content:space-between;font-size:20px;font-weight:800;color:#1e3a8a;border-top:2px solid #1e3a8a;padding-top:10px;margin-top:6px;"><span>Do zapłaty:</span><span>' + fmtN(tGross) + ' ' + currency + '</span></div>'
                + '<div style="font-size:12px;color:#64748b;margin-top:4px;">Słownie: ' + numberToWordsPL(tGross) + '</div>'
                + '</div>';
        }

        const w = window.open('', '_blank', 'width=860,height=1100');
        const html = '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>Podgląd: ' + kindLabel + ' ' + invNum + '</title>'
            + '<style>' + commonCss + '</style></head><body>'
            + '<div class="toolbar"><span class="toolbar-title">Podgląd: ' + kindLabel + ' ' + invNum + '</span>'
            + '<button class="btn btn-green" onclick="saveAsDraft()">Zapisz w roboczych</button>'
            + '<button class="btn btn-blue" onclick="saveAndIssue()">Wystaw w Fakturowni</button>'
            + '<button class="btn btn-outline" onclick="window.print()">Drukuj / PDF</button>'
            + '<button class="btn btn-gray" onclick="window.close()">Zamknij</button></div>'
            + fallbackFormHtml
            + '<div class="invoice">'
            + headerHtml + partiesHtml + datesHtml
            + positionsHtml
            + payFooter
            + '</div>' + previewScript + '</body></html>';

        w.document.write(html);
        w.document.close();
    }

    // ═══════ SUBMIT (formularz natywny) ═══════
    document.getElementById('invoiceForm').addEventListener('submit', function(e) {
        const items = collectItems();
        if (items.length === 0) { e.preventDefault(); alert('Dodaj przynajmniej jedną pozycję!'); return false; }
        let existing = this.querySelector('input[name="invoice_items"]');
        if (!existing) { existing = document.createElement('input'); existing.type = 'hidden'; existing.name = 'invoice_items'; this.appendChild(existing); }
        existing.value = JSON.stringify(items);
    });
    </script>
</body>
</html>
