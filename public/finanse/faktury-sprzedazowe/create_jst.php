<?php
/**
 * BRYGAD ERP - Wystaw Fakturę dla JST (KSeF Podmiot2/Podmiot3)
 *
 * Faktura zapisuje się w invoices_sale identycznie jak każda inna.
 * Dodatkowe dane JST (nabywca + odbiorca) zapisywane w invoice_sale_jst_data.
 * Wystawienie do Fakturowni używa FakturowniaService::createJstInvoiceFromSalesInvoice().
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

    // Pola podstawowe — identyczne jak create.php
    $client_id        = trim($_POST['client_id'] ?? '');
    $invoice_number   = trim($_POST['invoice_number'] ?? '');
    $issue_date       = trim($_POST['issue_date'] ?? '');
    $sale_date        = trim($_POST['sale_date'] ?? '');
    $due_date         = trim($_POST['due_date'] ?? '');
    $payment_days     = (int)($_POST['payment_days'] ?? ($company['default_payment_days'] ?? 14));
    $payment_method   = trim($_POST['payment_method'] ?? ($company['default_payment_method'] ?? 'transfer'));
    $bank_account     = trim($_POST['bank_account'] ?? '');
    $place_of_issue   = trim($_POST['place_of_issue'] ?? ($company['default_place_of_issue'] ?? ''));
    $currency         = trim($_POST['currency'] ?? ($company['default_currency'] ?? 'PLN'));
    $split_payment    = isset($_POST['split_payment']) ? 1 : 0;
    $notes            = trim($_POST['notes'] ?? '');
    $submit_action    = $_POST['submit_action'] ?? ($_POST['submit_action_btn'] ?? 'draft');

    $fkKind              = strtolower(trim($_POST['fakturownia_kind'] ?? 'vat'));
    $fkDepartmentId      = (int)($_POST['fakturownia_department_id'] ?? ($company['fakturownia_department_id'] ?? 0));
    $fkLang              = strtolower(trim($_POST['fakturownia_lang'] ?? ($company['fakturownia_lang'] ?? 'pl')));
    $fkBuyerEmail        = trim($_POST['fakturownia_buyer_email'] ?? '');
    $fkCategoryId        = (int)($_POST['fakturownia_category_id'] ?? 0);
    $fkOid               = trim($_POST['fakturownia_oid'] ?? '');
    $fkDescription       = trim($_POST['fakturownia_description'] ?? '');
    $fkDescriptionFooter = trim($_POST['fakturownia_description_footer'] ?? ($company['default_description_footer'] ?? ''));
    $fkPaymentType       = strtolower(trim($_POST['fakturownia_payment_type'] ?? ''));
    $fkDiscountKind      = trim($_POST['fakturownia_discount_kind'] ?? 'none');

    // Dane JST
    $jstBuyerName      = trim($_POST['jst_buyer_name'] ?? '');
    $jstBuyerNip       = preg_replace('/[^0-9]/', '', $_POST['jst_buyer_nip'] ?? '');
    $jstBuyerStreet    = trim($_POST['jst_buyer_street'] ?? '');
    $jstBuyerPostCode  = trim($_POST['jst_buyer_post_code'] ?? '');
    $jstBuyerCity      = trim($_POST['jst_buyer_city'] ?? '');

    $jstRecipientName      = trim($_POST['jst_recipient_name'] ?? '');
    $jstRecipientNip       = preg_replace('/[^0-9]/', '', $_POST['jst_recipient_nip'] ?? '');
    $jstRecipientStreet    = trim($_POST['jst_recipient_street'] ?? '');
    $jstRecipientPostCode  = trim($_POST['jst_recipient_post_code'] ?? '');
    $jstRecipientCity      = trim($_POST['jst_recipient_city'] ?? '');
    $jstRecipientNote      = trim($_POST['jst_recipient_note'] ?? '');

    // Walidacja
    if (empty($client_id))     $errors[] = "Klient (nabywca podstawowy) jest wymagany";
    if (empty($invoice_number)) $errors[] = "Numer faktury jest wymagany";
    if (empty($issue_date))    $errors[] = "Data wystawienia jest wymagana";
    if (empty($sale_date))     $errors[] = "Data sprzedaży jest wymagana";
    if (empty($due_date))      $errors[] = "Termin płatności jest wymagany";
    if (empty($jstBuyerName))  $errors[] = "Nazwa nabywcy JST (Podmiot2) jest wymagana";
    if (empty($jstBuyerNip))   $errors[] = "NIP nabywcy JST jest wymagany";

    $allowedKinds = ['vat', 'proforma', 'bill', 'receipt', 'advance', 'final', 'correction', 'vat_mp', 'vat_margin'];
    if (!in_array($fkKind, $allowedKinds, true)) $fkKind = 'vat';
    $allowedLangs = ['pl', 'en', 'de', 'fr', 'it', 'es', 'cs', 'sk'];
    if (!in_array($fkLang, $allowedLangs, true)) $fkLang = 'pl';
    $allowedDiscountKinds = ['none', 'percent_unit', 'amount'];
    if (!in_array($fkDiscountKind, $allowedDiscountKinds, true)) $fkDiscountKind = 'none';

    if ($fkBuyerEmail !== '' && !filter_var($fkBuyerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Nieprawidłowy e-mail nabywcy";
    }

    $fakturowniaOptions = [
        'kind'                 => $fkKind,
        'department_id'        => $fkDepartmentId > 0 ? $fkDepartmentId : null,
        'lang'                 => $fkLang,
        'buyer_email'          => $fkBuyerEmail !== '' ? $fkBuyerEmail : null,
        'category_id'          => $fkCategoryId > 0 ? $fkCategoryId : null,
        'oid'                  => $fkOid !== '' ? $fkOid : null,
        'description'          => $fkDescription !== '' ? $fkDescription : null,
        'description_footer'   => $fkDescriptionFooter !== '' ? $fkDescriptionFooter : null,
        'payment_type_override'=> $fkPaymentType !== '' ? $fkPaymentType : null,
        'discount_kind'        => $fkDiscountKind,
        'jst'                  => true,
    ];

    $sellerData = [
        'company_name'   => $company['company_name'] ?? '',
        'company_nip'    => $company['company_nip'] ?? '',
        'company_address'=> $company['company_address'] ?? '',
        'company_city'   => $company['company_city'] ?? '',
        'company_post_code' => $company['company_post_code'] ?? '',
        'bank_account'   => $bank_account,
        'bank_name'      => $company['default_bank_name'] ?? '',
        'logo_path'      => $company['logo_path'] ?? null,
    ];
    $archiveStandardInvoiceId = 0;

    if (empty($errors)) {
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
                    'Zwolnienie numeru dla nowej faktury JST'
                );
            }

            $total_net = 0; $total_vat = 0; $total_gross = 0;
            foreach ($invoice_items as $item) {
                $total_net   += $item['amount_net'];
                $total_vat   += $item['amount_vat'];
                $total_gross += $item['amount_gross'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO invoices_sale
                (invoice_number, client_id, issue_date, sale_date, due_date, payment_days, payment_method,
                 bank_account, place_of_issue, currency, split_payment, amount_net, amount_vat, amount_gross,
                 status, notes, fakturownia_options_json, seller_data_json, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $invoice_number, $client_id, $issue_date, $sale_date, $due_date, $payment_days,
                $payment_method, $bank_account ?: null, $place_of_issue, $currency, $split_payment,
                $total_net, $total_vat, $total_gross, $notes,
                json_encode($fakturowniaOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($sellerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $_SESSION['user_id'],
            ]);
            $invoice_id = $pdo->lastInsertId();

            // Pozycje
            $stmt_item = $pdo->prepare("
                INSERT INTO invoice_sale_items
                (invoice_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat,
                 amount_gross, fakturownia_item_options_json, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $sort_order = 0;
            foreach ($invoice_items as $item) {
                $itemOptions = [
                    'product_id'   => isset($item['product_id']) && (int)$item['product_id'] > 0 ? (int)$item['product_id'] : null,
                    'product_code' => trim((string)($item['product_code'] ?? '')) ?: null,
                    'gtu_code'     => null,
                ];
                $stmt_item->execute([
                    $invoice_id, $item['name'], $item['quantity'], $item['unit'], $item['unit_price'],
                    $item['vat_rate'], $item['amount_net'], $item['amount_vat'], $item['amount_gross'],
                    json_encode($itemOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sort_order++,
                ]);
            }

            // Dane JST
            $jstStmt = $pdo->prepare("
                INSERT INTO invoice_sale_jst_data
                (invoice_sale_id, jst_buyer_name, jst_buyer_nip, jst_buyer_street, jst_buyer_post_code,
                 jst_buyer_city, jst_recipient_name, jst_recipient_nip, jst_recipient_street,
                 jst_recipient_post_code, jst_recipient_city, jst_recipient_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $jstStmt->execute([
                $invoice_id,
                $jstBuyerName, $jstBuyerNip, $jstBuyerStreet, $jstBuyerPostCode, $jstBuyerCity,
                $jstRecipientName, $jstRecipientNip, $jstRecipientStreet, $jstRecipientPostCode,
                $jstRecipientCity, $jstRecipientNote,
            ]);

            $pdo->commit();
            logEvent("Utworzono fakturę JST: {$invoice_number}, ID: {$invoice_id}", 'INFO');

            if ($submit_action === 'issue') {
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'auto_issue_jst' => '1']));
            } else {
                header("Location: " . url('finanse.faktury-sprzedazowe.edit', ['id' => $invoice_id, 'success' => 'created_jst']));
            }
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logEvent("Błąd tworzenia faktury JST: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia faktury";
        }
    }
}

$clients_stmt = $pdo->query("SELECT id, name, nip, address, email, phone FROM investors WHERE is_active = 1 ORDER BY name ASC");
$clients = $clients_stmt->fetchAll();

// Sugerowany numer — identyczna logika jak create.php
$year = date('Y'); $month = date('m');
$fkPattern = "%/{$month}/{$year}";
$maxLocalSeq = sprutexMaxLocalStandardInvoiceSeq($pdo, $month, $year);
$maxFkSeq = sprutexMaxFakturowniaStandardInvoiceSeq($pdo, $month, $year);
$nextSeq = max($maxLocalSeq, $maxFkSeq) + 1;
$suggested_number = "{$nextSeq}/{$month}/{$year}";

$defaultBankAccount  = $company['default_bank_account'] ?? '';
$defaultPlaceOfIssue = $company['default_place_of_issue'] ?? '';
$defaultPaymentDays  = (int)($company['default_payment_days'] ?? 14);
$defaultPaymentMethod= $company['default_payment_method'] ?? 'transfer';
$defaultCurrency     = $company['default_currency'] ?? 'PLN';
$defaultDescriptionFooter = $company['default_description_footer'] ?? '';
$defaultDepartmentId = $company['fakturownia_department_id'] ?? '';
$defaultLang         = $company['fakturownia_lang'] ?? 'pl';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Faktura JST</title>
    <style>
        :root {
            --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb;
            --text-main: #1f2937; --text-muted: #6b7280;
            --success: #16a34a; --success-dark: #15803d; --danger: #ef4444;
            --jst-color: #7c3aed;
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
            background: linear-gradient(135deg, #5b21b6 0%, #1e1b4b 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 24px; font-weight: 700; }
        .hero-breadcrumb { font-size: 12px; color: #c4b5fd; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #ddd6fe; text-decoration: none; }
        .hero p { margin: 0; color: #c4b5fd; font-size: 13px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 18px; border: 1px solid var(--border); }
        .card-jst { border-color: #ddd6fe; background: #faf5ff; }
        .section-title { font-size: 16px; font-weight: 700; color: #0f172a; border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .section-title-jst { color: var(--jst-color); border-bottom-color: #ddd6fe; }
        .jst-badge { background: #ede9fe; color: #5b21b6; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 700; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        @media (max-width: 1100px) { .form-grid { grid-template-columns: 1fr 1fr; } .form-grid-3 { grid-template-columns: 1fr 1fr 1fr; } }
        @media (max-width: 700px) { .form-grid, .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; } }
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
        .btn-jst { background: linear-gradient(135deg, #7c3aed, #5b21b6); color: #fff; }
        .btn-jst:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124,58,237,0.3); }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .btn-outline:hover { border-color: #9ca3af; background: #f9fafb; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }

        .form-actions { display: flex; gap: 10px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid var(--border); flex-wrap: wrap; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .alert-info { background: #ede9fe; color: #4c1d95; border-left: 4px solid #7c3aed; }
        .alert-tip { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }

        .checkbox-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
        .checkbox-row input[type="checkbox"] { width: auto; margin: 0; }
        .checkbox-row label { font-size: 13px; font-weight: 500; color: #374151; text-transform: none; letter-spacing: 0; margin: 0; cursor: pointer; }

        .jst-divider { border: none; border-top: 1px solid #ddd6fe; margin: 16px 0; }
        .jst-sublabel { font-size: 11px; font-weight: 700; color: #5b21b6; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; padding: 4px 8px; background: #ede9fe; border-radius: 6px; display: inline-block; }
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
                    Faktura dla JST
                </div>
                <h1>Wystaw fakturę dla JST</h1>
                <p>Faktura z Nabywcą JST (Podmiot2) i Odbiorcą (Podmiot3) — KSeF</p>
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

        <div class="alert alert-info">
            <strong>Faktura dla JST (KSeF):</strong> Nabywca (Podmiot2) to jednostka samorządu terytorialnego (np. Miasto). Odbiorca (Podmiot3) to jednostka organizacyjna JST (np. ośrodek sportu). Faktura zapisuje się normalnie na liście faktur sprzedażowych.
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="invoiceForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="submit_action" id="submit_action" value="draft">

            <!-- NABYWCA (z systemu — Podmiot1 wystawcy) -->
            <div class="card">
                <div class="section-title">Nabywca do systemu (klient ERP)</div>
                <div class="help-text" style="margin-bottom: 10px; color: #6b7280;">Wybierz klienta z bazy ERP — powiązanie dla rozliczeń. Dane JST (Podmiot2) uzupełnisz poniżej.</div>
                <input type="hidden" name="client_id" id="client_id" value="<?php echo e($_POST['client_id'] ?? ''); ?>">
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
                    <a href="<?php echo url('investors.create'); ?>?return_to=<?php echo urlencode('/finanse/faktury-sprzedazowe/create_jst.php'); ?>" style="font-size: 12px; color: #2563eb;">+ Dodaj nowego klienta</a>
                </div>
            </div>

            <!-- DANE JST -->
            <div class="card card-jst">
                <div class="section-title section-title-jst">
                    Dane JST (KSeF)
                    <span class="jst-badge">KSeF</span>
                </div>

                <div class="alert alert-tip" style="margin-bottom: 16px;">
                    Pola poniżej trafią do faktury w Fakturowni jako Podmiot2 (nabywca JST) i Podmiot3 (odbiorca). Uzupełnij zgodnie z wymaganiami kontrahenta.
                </div>

                <!-- Podmiot2 — Nabywca JST -->
                <div class="jst-sublabel">Nabywca — Podmiot2 (np. Miasto Poznań)</div>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Nazwa nabywcy JST <span class="required">*</span></label>
                        <input type="text" name="jst_buyer_name" value="<?php echo e($_POST['jst_buyer_name'] ?? ''); ?>" placeholder="np. Miasto Poznań" required>
                        <div class="help-text">Pełna nazwa jednostki samorządu terytorialnego</div>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>NIP nabywcy JST <span class="required">*</span></label>
                        <input type="text" name="jst_buyer_nip" value="<?php echo e($_POST['jst_buyer_nip'] ?? ''); ?>" placeholder="np. 2090001440" maxlength="15" required>
                    </div>
                    <div class="form-group">
                        <label>Ulica</label>
                        <input type="text" name="jst_buyer_street" value="<?php echo e($_POST['jst_buyer_street'] ?? ''); ?>" placeholder="np. Plac Kolegiacki 17">
                    </div>
                    <div class="form-group">
                        <label>Kod pocztowy</label>
                        <input type="text" name="jst_buyer_post_code" value="<?php echo e($_POST['jst_buyer_post_code'] ?? ''); ?>" placeholder="np. 61-841" maxlength="10">
                    </div>
                    <div class="form-group">
                        <label>Miasto</label>
                        <input type="text" name="jst_buyer_city" value="<?php echo e($_POST['jst_buyer_city'] ?? ''); ?>" placeholder="np. Poznań">
                    </div>
                </div>

                <hr class="jst-divider">

                <!-- Podmiot3 — Odbiorca -->
                <div class="jst-sublabel">Odbiorca — Podmiot3 (opcjonalnie, np. POSiR)</div>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Nazwa odbiorcy</label>
                        <input type="text" name="jst_recipient_name" value="<?php echo e($_POST['jst_recipient_name'] ?? ''); ?>" placeholder="np. Poznańskie Ośrodki Sportu i Rekreacji">
                        <div class="help-text">Jednostka organizacyjna JST (Podmiot3 w KSeF). Zostaw puste jeśli nie dotyczy.</div>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>NIP odbiorcy</label>
                        <input type="text" name="jst_recipient_nip" value="<?php echo e($_POST['jst_recipient_nip'] ?? ''); ?>" placeholder="np. 7831044564" maxlength="15">
                    </div>
                    <div class="form-group">
                        <label>Ulica odbiorcy</label>
                        <input type="text" name="jst_recipient_street" value="<?php echo e($_POST['jst_recipient_street'] ?? ''); ?>" placeholder="np. ul. Jana Spychalskiego 34">
                    </div>
                    <div class="form-group">
                        <label>Kod pocztowy odbiorcy</label>
                        <input type="text" name="jst_recipient_post_code" value="<?php echo e($_POST['jst_recipient_post_code'] ?? ''); ?>" placeholder="np. 61-553" maxlength="10">
                    </div>
                    <div class="form-group">
                        <label>Miasto odbiorcy</label>
                        <input type="text" name="jst_recipient_city" value="<?php echo e($_POST['jst_recipient_city'] ?? ''); ?>" placeholder="np. Poznań">
                    </div>
                </div>
                <div class="form-group">
                    <label>Dodatkowy opis odbiorcy</label>
                    <input type="text" name="jst_recipient_note" value="<?php echo e($_POST['jst_recipient_note'] ?? ''); ?>" placeholder="np. POSiR - Oddział Chwiałka." maxlength="500">
                    <div class="help-text">Pojawi się w polu "nota" Podmiotu3 na fakturze KSeF</div>
                </div>
            </div>

            <!-- DANE DOKUMENTU -->
            <div class="card">
                <div class="section-title">Dane dokumentu</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Numer faktury <span class="required">*</span></label>
                        <input type="text" name="invoice_number" id="invoice_number" value="<?php echo e($_POST['invoice_number'] ?? $suggested_number); ?>" required>
                        <div class="help-text">Numer uwzględnia faktury z Fakturowni.</div>
                    </div>
                    <div class="form-group">
                        <label>Rodzaj dokumentu</label>
                        <?php $kindSelected = $_POST['fakturownia_kind'] ?? 'vat'; ?>
                        <select name="fakturownia_kind" id="fakturownia_kind">
                            <option value="vat" <?php echo $kindSelected === 'vat' ? 'selected' : ''; ?>>Faktura VAT</option>
                            <option value="proforma" <?php echo $kindSelected === 'proforma' ? 'selected' : ''; ?>>Proforma</option>
                            <option value="advance" <?php echo $kindSelected === 'advance' ? 'selected' : ''; ?>>Zaliczkowa</option>
                            <option value="final" <?php echo $kindSelected === 'final' ? 'selected' : ''; ?>>Końcowa</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Data wystawienia <span class="required">*</span></label>
                        <input type="date" name="issue_date" id="issue_date" value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Data sprzedaży <span class="required">*</span></label>
                        <input type="date" name="sale_date" id="sale_date" value="<?php echo e($_POST['sale_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Termin płatności <span class="required">*</span></label>
                        <input type="date" name="due_date" id="due_date" value="<?php echo e($_POST['due_date'] ?? date('Y-m-d', strtotime('+' . $defaultPaymentDays . ' days'))); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Termin (dni)</label>
                        <input type="number" name="payment_days" id="payment_days" value="<?php echo e($_POST['payment_days'] ?? $defaultPaymentDays); ?>" min="0" max="365">
                    </div>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Sposób płatności</label>
                        <?php $pmSel = $_POST['payment_method'] ?? $defaultPaymentMethod; ?>
                        <select name="payment_method" id="payment_method">
                            <option value="transfer" <?php echo $pmSel === 'transfer' ? 'selected' : ''; ?>>Przelew</option>
                            <option value="cash" <?php echo $pmSel === 'cash' ? 'selected' : ''; ?>>Gotówka</option>
                            <option value="card" <?php echo $pmSel === 'card' ? 'selected' : ''; ?>>Karta</option>
                            <option value="other" <?php echo $pmSel === 'other' ? 'selected' : ''; ?>>Inny</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Konto bankowe</label>
                        <input type="text" name="bank_account" id="bank_account" value="<?php echo e($_POST['bank_account'] ?? $defaultBankAccount); ?>" placeholder="XX XXXX XXXX XXXX XXXX XXXX XXXX">
                    </div>
                    <div class="form-group">
                        <label>Waluta</label>
                        <?php $curSel = $_POST['currency'] ?? $defaultCurrency; ?>
                        <select name="currency" id="currency">
                            <option value="PLN" <?php echo $curSel === 'PLN' ? 'selected' : ''; ?>>PLN</option>
                            <option value="EUR" <?php echo $curSel === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                            <option value="USD" <?php echo $curSel === 'USD' ? 'selected' : ''; ?>>USD</option>
                            <option value="GBP" <?php echo $curSel === 'GBP' ? 'selected' : ''; ?>>GBP</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Miejsce wystawienia</label>
                        <input type="text" name="place_of_issue" id="place_of_issue" value="<?php echo e($_POST['place_of_issue'] ?? $defaultPlaceOfIssue); ?>">
                    </div>
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="split_payment" id="split_payment" value="1" <?php echo !empty($_POST['split_payment']) ? 'checked' : ''; ?>>
                    <label for="split_payment">Mechanizm podzielonej płatności</label>
                </div>
            </div>

            <!-- POZYCJE -->
            <div class="card">
                <div class="section-title">Pozycje faktury <span style="background:#e0e7ff;color:#3730a3;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;" id="items-count-badge">0</span></div>
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
                <button type="button" onclick="addInvoiceItem()" class="btn btn-sm btn-outline" style="margin-top: 10px;">+ Dodaj pozycję</button>
            </div>

            <!-- UWAGI -->
            <div class="card">
                <div class="section-title">Uwagi i załącznik</div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Notatki / uwagi na fakturze</label>
                    <textarea name="notes" id="notes"><?php echo e($_POST['notes'] ?? ($company['default_notes'] ?? '')); ?></textarea>
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
                            <label>Typ płatności override</label>
                            <?php $ptSel = $_POST['fakturownia_payment_type'] ?? ''; ?>
                            <select name="fakturownia_payment_type">
                                <option value="" <?php echo $ptSel === '' ? 'selected' : ''; ?>>Z pola podstawowego</option>
                                <option value="transfer" <?php echo $ptSel === 'transfer' ? 'selected' : ''; ?>>transfer</option>
                                <option value="cash" <?php echo $ptSel === 'cash' ? 'selected' : ''; ?>>cash</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Opis na fakturze</label>
                        <textarea name="fakturownia_description"><?php echo e($_POST['fakturownia_description'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label>Stopka opisu</label>
                        <textarea name="fakturownia_description_footer"><?php echo e($_POST['fakturownia_description_footer'] ?? $defaultDescriptionFooter); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- AKCJE -->
            <div class="card">
                <div class="alert alert-info" style="margin-bottom: 14px;">
                    <strong>Flow:</strong> "Zapisz roboczą" → sprawdź w podglądzie → utwórz w Fakturowni → sprawdź PDF z Fakturowni → dopiero wyślij do KSeF.
                </div>
                <div class="form-actions">
                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn btn-secondary">Anuluj</a>
                    <button type="submit" name="submit_action_btn" value="issue" onclick="document.getElementById('submit_action').value='issue'" class="btn btn-jst" style="font-size:15px; padding:12px 28px;">Zapisz i wystaw w Fakturowni</button>
                    <button type="submit" name="submit_action_btn" value="draft" class="btn btn-outline btn-sm">Zapisz jako roboczy</button>
                </div>
            </div>
        </form>

        <input type="hidden" id="invoice_items_json" name="">
    </div>

    <script>
    // ═══════ KLIENT ═══════
    const allClients = <?php echo json_encode(array_map(function($c) {
        return ['id' => $c['id'], 'name' => $c['name'], 'nip' => $c['nip'] ?? '', 'email' => $c['email'] ?? ''];
    }, $clients), JSON_UNESCAPED_UNICODE); ?>;

    const clientSearch   = document.getElementById('client-search');
    const clientDropdown = document.getElementById('client-dropdown');
    const clientIdInput  = document.getElementById('client_id');
    const clientSelectedDiv = document.getElementById('client-selected');
    const clientSearchWrapper = document.getElementById('client-search-wrapper');

    function renderClientDropdown(filter) {
        const q = (filter || '').toLowerCase();
        const filtered = allClients.filter(c => c.name.toLowerCase().includes(q) || (c.nip && c.nip.includes(q))).slice(0, 20);
        clientDropdown.innerHTML = filtered.length === 0
            ? '<div style="padding:12px;color:#94a3b8;font-size:13px;text-align:center;">Brak wyników</div>'
            : filtered.map(c => `<div class="client-option" data-id="${c.id}" data-name="${escH(c.name)}" data-nip="${escH(c.nip)}" data-email="${escH(c.email)}">
                <div class="client-option-name">${escH(c.name)}</div>
                ${c.nip ? '<div class="client-option-nip">NIP: ' + escH(c.nip) + '</div>' : ''}
              </div>`).join('');
        clientDropdown.classList.add('show');
    }
    function escH(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    clientSearch.addEventListener('input', () => renderClientDropdown(clientSearch.value));
    clientSearch.addEventListener('focus', () => renderClientDropdown(clientSearch.value));
    document.addEventListener('click', e => { if (!e.target.closest('.client-search-wrap')) clientDropdown.classList.remove('show'); });
    clientDropdown.addEventListener('click', e => {
        const opt = e.target.closest('.client-option');
        if (opt) selectClient(opt.dataset.id, opt.dataset.name, opt.dataset.nip, opt.dataset.email);
    });
    function selectClient(id, name, nip, email) {
        clientIdInput.value = id;
        document.getElementById('client-selected-name').textContent = name;
        document.getElementById('client-selected-nip').textContent = nip ? 'NIP: ' + nip : '';
        clientSelectedDiv.style.display = 'flex';
        clientSearchWrapper.style.display = 'none';
        clientDropdown.classList.remove('show');
        const emailIn = document.getElementById('fakturownia_buyer_email');
        if (email && emailIn && !emailIn.value) emailIn.value = email;
    }
    function clearClient() {
        clientIdInput.value = '';
        clientSelectedDiv.style.display = 'none';
        clientSearchWrapper.style.display = 'block';
        clientSearch.value = ''; clientSearch.focus();
    }
    <?php if (!empty($_POST['client_id'])): ?>
    (function() {
        const autoId = '<?php echo (int)$_POST['client_id']; ?>';
        const found = allClients.find(c => c.id == autoId);
        if (found) selectClient(found.id, found.name, found.nip, found.email);
    })();
    <?php endif; ?>

    // ═══════ POZYCJE ═══════
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
        const vatSel  = row.querySelector('.item-vat');
        const pidIn   = row.querySelector('.item-product-id');
        if (parseFloat(priceIn.value) <= 0 && parseFloat(p.price_net||0) > 0) priceIn.value = parseFloat(p.price_net).toFixed(2);
        if (p.unit) unitSel.value = p.unit;
        if (p.vat_rate) { const v = p.vat_rate.toString().toLowerCase(); if (['zw','0','5','8','23'].includes(v)) vatSel.value = v; }
        if (p.fakturownia_product_id > 0) pidIn.value = p.fakturownia_product_id;
        calcRow(row);
    }

    function addInvoiceItem(prefill) {
        itemCounter++;
        const idx = itemCounter;
        const tbody = document.getElementById('items-tbody');
        const tr = document.createElement('tr');
        tr.dataset.idx = idx;
        const pf = prefill || {};
        tr.innerHTML = `
            <td><input type="text" class="item-name" value="${escH(pf.name||'')}" placeholder="Nazwa usługi/towaru"></td>
            <td><input type="number" class="item-qty" value="${pf.quantity||1}" min="0.001" step="0.001" style="text-align:right;"></td>
            <td><select class="item-unit">
                <option value="szt" ${(pf.unit||'szt')==='szt'?'selected':''}>szt</option>
                <option value="usł" ${(pf.unit||'')==='usł'?'selected':''}>usł</option>
                <option value="godz" ${(pf.unit||'')==='godz'?'selected':''}>godz</option>
                <option value="m" ${(pf.unit||'')==='m'?'selected':''}>m</option>
                <option value="m2" ${(pf.unit||'')==='m2'?'selected':''}>m2</option>
                <option value="m3" ${(pf.unit||'')==='m3'?'selected':''}>m3</option>
                <option value="kg" ${(pf.unit||'')==='kg'?'selected':''}>kg</option>
                <option value="t" ${(pf.unit||'')==='t'?'selected':''}>t</option>
                <option value="km" ${(pf.unit||'')==='km'?'selected':''}>km</option>
                <option value="kpl" ${(pf.unit||'')==='kpl'?'selected':''}>kpl</option>
                <option value="l" ${(pf.unit||'')==='l'?'selected':''}>l</option>
            </select></td>
            <td><input type="number" class="item-price" value="${pf.unit_price||''}" min="0" step="0.01" placeholder="0.00" style="text-align:right;"></td>
            <td><select class="item-vat">
                <option value="23" ${(pf.vat_rate||'23')==='23'?'selected':''}>23%</option>
                <option value="8"  ${(pf.vat_rate||'')==='8'?'selected':''}>8%</option>
                <option value="5"  ${(pf.vat_rate||'')==='5'?'selected':''}>5%</option>
                <option value="0"  ${(pf.vat_rate||'')==='0'?'selected':''}>0%</option>
                <option value="zw" ${(pf.vat_rate||'')==='zw'?'selected':''}>zw</option>
            </select></td>
            <td class="text-right item-net">0,00</td>
            <td class="text-right item-vat-amt">0,00</td>
            <td class="text-right item-gross">0,00</td>
            <td><button type="button" class="item-remove" onclick="removeItem(this)" title="Usuń">&times;</button></td>
            <input type="hidden" class="item-product-id" value="${pf.product_id||''}">
        `;
        tbody.appendChild(tr);

        const nameIn = tr.querySelector('.item-name');
        nameIn.addEventListener('blur', () => applyProductDefaults(tr, nameIn.value));

        tr.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('input', () => calcRow(tr));
            el.addEventListener('change', () => calcRow(tr));
        });

        if (pf.name) calcRow(tr);
        updateItemsBadge();
        serializeItems();
        return tr;
    }

    function calcRow(tr) {
        const qty   = parseFloat(tr.querySelector('.item-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.item-price').value) || 0;
        const vatStr = tr.querySelector('.item-vat').value;
        const vatRate = vatStr === 'zw' ? 0 : (parseFloat(vatStr) || 0);

        const net   = qty * price;
        const vatAmt = net * vatRate / 100;
        const gross  = net + vatAmt;

        tr.querySelector('.item-net').textContent     = fmt(net);
        tr.querySelector('.item-vat-amt').textContent = fmt(vatAmt);
        tr.querySelector('.item-gross').textContent   = fmt(gross);
        updateTotals();
        serializeItems();
    }

    function removeItem(btn) {
        btn.closest('tr').remove();
        updateTotals();
        updateItemsBadge();
        serializeItems();
    }

    function updateTotals() {
        let net = 0, vat = 0, gross = 0;
        document.querySelectorAll('#items-tbody tr').forEach(tr => {
            net   += parseFmt(tr.querySelector('.item-net').textContent);
            vat   += parseFmt(tr.querySelector('.item-vat-amt').textContent);
            gross += parseFmt(tr.querySelector('.item-gross').textContent);
        });
        document.getElementById('total-net').textContent   = fmt(net);
        document.getElementById('total-vat').textContent   = fmt(vat);
        document.getElementById('total-gross').textContent = fmt(gross);
    }

    function updateItemsBadge() {
        document.getElementById('items-count-badge').textContent = document.querySelectorAll('#items-tbody tr').length;
    }

    function serializeItems() {
        const rows = [];
        document.querySelectorAll('#items-tbody tr').forEach(tr => {
            const qty   = parseFloat(tr.querySelector('.item-qty').value) || 0;
            const price = parseFloat(tr.querySelector('.item-price').value) || 0;
            const vatStr = tr.querySelector('.item-vat').value;
            const vatRate = vatStr === 'zw' ? 0 : (parseFloat(vatStr) || 0);
            const net   = qty * price;
            const vatAmt = net * vatRate / 100;
            const gross  = net + vatAmt;
            rows.push({
                name: tr.querySelector('.item-name').value,
                quantity: qty,
                unit: tr.querySelector('.item-unit').value,
                unit_price: price,
                vat_rate: vatStr,
                amount_net: Math.round(net * 100) / 100,
                amount_vat: Math.round(vatAmt * 100) / 100,
                amount_gross: Math.round(gross * 100) / 100,
                product_id: tr.querySelector('.item-product-id').value || null,
            });
        });
        document.querySelector('input[name="invoice_items"]') && (document.querySelector('input[name="invoice_items"]').value = JSON.stringify(rows));
    }

    function fmt(n) { return n.toLocaleString('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    function parseFmt(s) { return parseFloat((s||'0').replace(/\s/g,'').replace(',','.')) || 0; }

    // ═══════ ACCORDION ═══════
    function toggleAccordion(header) {
        const arrow = header.querySelector('.accordion-arrow');
        const body  = header.nextElementSibling;
        const isOpen = body.classList.contains('open');
        body.classList.toggle('open', !isOpen);
        arrow.classList.toggle('open', !isOpen);
    }

    // ═══════ FORMULARZ ═══════
    document.getElementById('invoiceForm').addEventListener('submit', function(e) {
        serializeItems();
        const rows = document.querySelectorAll('#items-tbody tr');
        if (rows.length === 0) { e.preventDefault(); alert('Dodaj przynajmniej jedną pozycję.'); return; }
        const clientId = document.getElementById('client_id').value;
        if (!clientId) { e.preventDefault(); alert('Wybierz klienta z listy.'); return; }
    });

    // Dynamiczne pole invoice_items
    (function() {
        const form = document.getElementById('invoiceForm');
        let inp = form.querySelector('input[name="invoice_items"]');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'invoice_items';
            form.appendChild(inp);
        }
    })();

    // Dodaj pierwszą pozycję domyślnie
    addInvoiceItem();
    </script>
</body>
</html>
