<?php
/**
 * BRYGAD ERP - Edycja wpisu pozafakturowego (PZF)
 *
 * Guardrail: NIE korzysta z invoices_sale, Fakturowni, KSeF ani project_revenues.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo    = getDbConnection();
$errors = [];

function resolvePzfReturnSource(string $default = 'noninvoice'): string
{
    $source = (string)($_POST['return_source'] ?? $_GET['source'] ?? $default);
    return in_array($source, ['invoice', 'noninvoice', 'all'], true) ? $source : $default;
}

function pzfCenterUrl(string $source, array $extra = []): string
{
    return url('finanse.faktury-sprzedazowe', array_merge(['source' => $source], $extra));
}

$returnSource = resolvePzfReturnSource();
$centerUrl = pzfCenterUrl($returnSource);

$id = (int)($_GET['id'] ?? 0);
$centerAllocUrl = pzfCenterUrl($returnSource === 'invoice' ? 'noninvoice' : $returnSource, ['open_pzf_alloc' => $id]);
if ($id <= 0) {
    header("Location: " . pzfCenterUrl($returnSource, ['error' => 'pzf_not_found']));
    exit;
}

// Pobierz wpis
$stmt = $pdo->prepare("
    SELECT e.*,
           i.name AS investor_client_name,
           COALESCE(i.name, e.counterparty_name_manual) AS client_name,
           i.nip AS client_nip
    FROM sales_noninvoice_entries e
    LEFT JOIN investors i ON i.id = e.client_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entry) {
    header("Location: " . pzfCenterUrl($returnSource, ['error' => 'pzf_not_found']));
    exit;
}

// Pobierz alokacje
$allocStmt = $pdo->prepare("
    SELECT a.*, p.name AS project_name,
           pcn.name AS cost_node_name
    FROM sales_noninvoice_allocations a
    JOIN projects p ON p.id = a.project_id
    LEFT JOIN project_cost_nodes pcn ON pcn.id = a.cost_node_id
    WHERE a.entry_id = ?
    ORDER BY a.created_at ASC
");
$allocStmt->execute([$id]);
$allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

$total_allocated = array_sum(array_column($allocations, 'amount_net'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token sesji (CSRF). Odśwież stronę.';
    }

    $client_id      = (int)($_POST['client_id'] ?? 0);
    $counterparty_name_manual = trim((string)($_POST['counterparty_name_manual'] ?? ''));
    $title          = trim($_POST['title'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');
    $issue_date     = trim($_POST['issue_date'] ?? '');
    $due_date       = trim($_POST['due_date'] ?? '') ?: null;
    $currency       = trim($_POST['currency'] ?? 'PLN');
    $amount_net     = (float)str_replace(',', '.', $_POST['amount_net'] ?? '0');
    $payment_status = in_array($_POST['payment_status'] ?? '', ['unpaid', 'paid'], true)
                      ? $_POST['payment_status']
                      : 'unpaid';
    $payment_date   = trim($_POST['payment_date'] ?? '') ?: null;

    if ($client_id > 0) {
        $counterparty_name_manual = '';
    } else {
        $counterparty_name_manual = mb_substr($counterparty_name_manual, 0, 255);
    }

    if ($client_id <= 0 && $counterparty_name_manual === '') {
        $errors[] = 'Wybierz Kontrahenta z listy albo wpisz Kontrahenta ręcznie.';
    }
    if (empty($title))      $errors[] = 'Tytuł jest wymagany.';
    if (empty($issue_date)) $errors[] = 'Data wystawienia jest wymagana.';
    if ($amount_net <= 0)   $errors[] = 'Kwota musi być większa od 0.';
    if (!in_array($currency, ['PLN','EUR','USD','GBP'], true)) $currency = 'PLN';

    if ($payment_status === 'paid' && empty($payment_date)) {
        $payment_date = date('Y-m-d');
    }
    if ($payment_status === 'unpaid') {
        $payment_date = null;
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE sales_noninvoice_entries
                SET client_id      = ?,
                    counterparty_name_manual = ?,
                    title          = ?,
                    notes          = ?,
                    issue_date     = ?,
                    due_date       = ?,
                    payment_date   = ?,
                    currency       = ?,
                    amount_net     = ?,
                    amount_vat     = 0.00,
                    amount_gross   = ?,
                    payment_status = ?,
                    updated_at     = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $client_id > 0 ? $client_id : null,
                $counterparty_name_manual !== '' ? $counterparty_name_manual : null,
                $title, $notes ?: null,
                $issue_date, $due_date, $payment_date,
                $currency, $amount_net, $amount_net,
                $payment_status, $id
            ]);
            logEvent("Zaktualizowano wpis PZF ID: {$id}", 'INFO');
            header("Location: " . url('finanse.sprzedaz-pozafakturowa.edit', [
                'id' => $id,
                'source' => $returnSource,
                'success' => 'updated',
            ]));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd edycji PZF ID {$id}: " . $e->getMessage(), 'ERROR');
            $errors[] = 'Błąd podczas aktualizacji wpisu.';
        }
    }
}

$clients = $pdo->query("SELECT id, name, nip FROM investors WHERE is_active = 1 ORDER BY name ASC")
               ->fetchAll(PDO::FETCH_ASSOC);

$success = $_GET['success'] ?? null;
$formClientId = (string)($_POST['client_id'] ?? ($entry['client_id'] ?? ''));
$formCounterpartyManual = trim((string)($_POST['counterparty_name_manual'] ?? ($entry['counterparty_name_manual'] ?? '')));
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj wpis PZF</title>
    <style>
        :root { --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; --success: #16a34a; --success-dark: #15803d; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; }
        .container { max-width: 960px; margin: 0 auto; padding: 25px 30px 60px; }
        .hero { background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%); color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .hero h1 { margin: 0 0 4px; font-size: 22px; font-weight: 700; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-sub { margin: 0; color: #cbd5e1; font-size: 13px; }
        .badge-pzf { background: rgba(255,255,255,0.15); color: #e0f2fe; border: 1px solid rgba(255,255,255,0.25); font-size: 11px; padding: 3px 10px; border-radius: 10px; font-weight: 700; letter-spacing: 0.5px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .btn-hero-danger { background: rgba(239,68,68,0.2); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        .btn-hero-danger:hover { background: rgba(239,68,68,0.35); color: #fff; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 18px; border: 1px solid var(--border); }
        .section-title { font-size: 16px; font-weight: 700; color: #0f172a; border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        @media (max-width: 700px) { .form-grid, .form-grid-3 { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; min-width: 0; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .required { color: #dc2626; }
        .form-group input, .form-group select, .form-group textarea { padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; width: 100%; background: #fff; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .help-text { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
        .client-search-wrap { position: relative; }
        .client-search-input { width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        .client-search-input:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .client-dropdown { position: absolute; top: 100%; left: 0; right: 0; z-index: 100; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; margin-top: 2px; max-height: 240px; overflow-y: auto; display: none; box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .client-dropdown.show { display: block; }
        .client-option { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; }
        .client-option:hover { background: #f0fdf4; }
        .client-option-name { font-weight: 600; font-size: 14px; }
        .client-option-nip { font-size: 12px; color: var(--text-muted); }
        .client-selected { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; }
        .client-selected-clear { background: none; border: none; color: #dc2626; cursor: pointer; font-size: 18px; margin-left: auto; padding: 0 4px; }
        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; }
        .btn-primary { background: linear-gradient(135deg, var(--success), var(--success-dark)); color: #fff; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .btn-danger-outline { background: #fff; color: #dc2626; border: 1px solid #fca5a5; }
        .btn-danger-outline:hover { background: #fee2e2; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid var(--border); flex-wrap: wrap; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #16a34a; }
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-unpaid { background: #fef9c3; color: #854d0e; }
        .alloc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .alloc-table th { padding: 8px 12px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; border-bottom: 2px solid var(--border); background: #f8fafc; }
        .alloc-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
        .alloc-summary { display: flex; gap: 20px; padding: 12px 16px; background: #f8fafc; border-radius: 8px; font-size: 14px; margin-top: 10px; flex-wrap: wrap; }
        .alloc-summary-item { display: flex; flex-direction: column; }
        .alloc-summary-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
        .alloc-summary-value { font-size: 16px; font-weight: 700; color: #0f172a; }
        #payment-date-group { display: none; }
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
                    <a href="<?php echo $centerUrl; ?>">Centrum sprzedaży</a> /
                    <?php echo e($entry['entry_number']); ?>
                </div>
                <h1><?php echo e($entry['entry_number']); ?> <span class="badge-pzf">PZF</span></h1>
                <p class="hero-sub"><?php echo e($entry['client_name'] ?: '-'); ?> · <?php echo e(number_format($entry['amount_net'], 2, ',', ' ')); ?> <?php echo e($entry['currency']); ?></p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.sprzedaz-pozafakturowa.delete', ['id' => $id, 'source' => $returnSource]); ?>"
                   class="btn-hero-danger"
                   onclick="return confirm('Na pewno usunąć wpis <?php echo e(addslashes($entry['entry_number'])); ?>? Operacja jest nieodwracalna.')">
                    Usuń
                </a>
                <a href="<?php echo $centerUrl; ?>" class="btn-hero-secondary">← Centrum sprzedaży</a>
            </div>
        </div>

        <?php if ($success === 'created'): ?>
            <div class="alert alert-success">Wpis pozafakturowy został utworzony.</div>
        <?php elseif ($success === 'updated'): ?>
            <div class="alert alert-success">Zmiany zostały zapisane.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błędy:</strong>
                <ul style="margin: 6px 0 0 18px;">
                    <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="pzfEditForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="return_source" value="<?php echo e($returnSource); ?>">

            <!-- KONTRAHENT -->
            <div class="card">
                <div class="section-title">Kontrahent</div>
                <input type="hidden" name="client_id" id="client_id" value="<?php echo e($formClientId); ?>">
                <div id="client-selected" class="client-selected" style="display:none;">
                    <div>
                        <div class="client-selected-name" id="client-selected-name"></div>
                        <div style="font-size:12px;color:var(--text-muted);" id="client-selected-nip"></div>
                    </div>
                    <button type="button" class="client-selected-clear" onclick="clearClient()">&times;</button>
                </div>
                <div id="client-search-wrapper" class="client-search-wrap">
                    <input type="text" class="client-search-input" id="client-search" placeholder="Szukaj kontrahenta..." autocomplete="off">
                    <div class="client-dropdown" id="client-dropdown"></div>
                </div>
                <div class="form-group" style="margin-top:12px;">
                    <label>Kontrahent (ręcznie)</label>
                    <input type="text"
                           name="counterparty_name_manual"
                           id="counterparty_name_manual"
                           value="<?php echo e($formCounterpartyManual); ?>"
                           placeholder="Wpisz nazwę, jeśli kontrahenta nie ma na liście"
                           maxlength="255">
                    <div class="help-text">Wymagane jest jedno z pól: wybór z listy albo nazwa ręczna.</div>
                </div>
            </div>

            <!-- DANE WPISU -->
            <div class="card">
                <div class="section-title">Dane wpisu</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Numer wpisu</label>
                        <input type="text" value="<?php echo e($entry['entry_number']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Status płatności <span class="required">*</span></label>
                        <select name="payment_status" id="payment_status" onchange="togglePaymentDate()">
                            <option value="unpaid" <?php echo $entry['payment_status'] === 'unpaid' ? 'selected' : ''; ?>>Nieopłacona</option>
                            <option value="paid"   <?php echo $entry['payment_status'] === 'paid' ? 'selected' : ''; ?>>Opłacona</option>
                        </select>
                    </div>
                </div>
                <div class="form-group full-width" style="margin-bottom:14px;">
                    <label>Tytuł <span class="required">*</span></label>
                    <input type="text" name="title" value="<?php echo e($entry['title']); ?>" required>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Data wystawienia <span class="required">*</span></label>
                        <input type="date" name="issue_date" value="<?php echo e($entry['issue_date']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Termin płatności</label>
                        <input type="date" name="due_date" value="<?php echo e($entry['due_date'] ?? ''); ?>">
                    </div>
                    <div id="payment-date-group" class="form-group">
                        <label>Data zapłaty</label>
                        <input type="date" name="payment_date" id="payment_date" value="<?php echo e($entry['payment_date'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Kwota netto <span class="required">*</span></label>
                        <input type="number" name="amount_net" value="<?php echo e($entry['amount_net']); ?>" step="0.01" min="0.01" required>
                        <div class="help-text">Kwota brutto = kwota netto (VAT = 0)</div>
                    </div>
                    <div class="form-group">
                        <label>Waluta</label>
                        <select name="currency">
                            <?php foreach (['PLN','EUR','USD','GBP'] as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo $entry['currency'] === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group full-width">
                    <label>Notatka / opis</label>
                    <textarea name="notes"><?php echo e($entry['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <!-- ALOKACJE -->
            <div class="card" id="allocations">
                <div class="section-title">
                    Alokacje do projektów
                    <a href="<?php echo $centerAllocUrl; ?>"
                       class="btn btn-sm btn-secondary">Zarządzaj alokacjami</a>
                </div>
                <?php if (empty($allocations)): ?>
                    <p style="color:var(--text-muted);font-size:14px;">Brak alokacji. Możesz przypisać ten wpis do projektów.</p>
                <?php else: ?>
                    <table class="alloc-table">
                        <thead>
                            <tr>
                                <th>Projekt</th>
                                <th>Węzeł kosztowy</th>
                                <th style="text-align:right;">Kwota netto</th>
                                <th>Opis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocations as $alloc): ?>
                            <tr>
                                <td><?php echo e($alloc['project_name']); ?></td>
                                <td><?php echo e($alloc['cost_node_name'] ?? '—'); ?></td>
                                <td style="text-align:right;"><?php echo e(number_format($alloc['amount_net'], 2, ',', ' ')); ?></td>
                                <td style="color:var(--text-muted);"><?php echo e($alloc['description'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div class="alloc-summary">
                    <div class="alloc-summary-item">
                        <span class="alloc-summary-label">Kwota wpisu</span>
                        <span class="alloc-summary-value"><?php echo e(number_format($entry['amount_net'], 2, ',', ' ')); ?> <?php echo e($entry['currency']); ?></span>
                    </div>
                    <div class="alloc-summary-item">
                        <span class="alloc-summary-label">Zaalokowano</span>
                        <span class="alloc-summary-value" style="color:<?php echo $total_allocated > $entry['amount_net'] ? '#dc2626' : '#166534'; ?>">
                            <?php echo e(number_format($total_allocated, 2, ',', ' ')); ?> <?php echo e($entry['currency']); ?>
                        </span>
                    </div>
                    <div class="alloc-summary-item">
                        <span class="alloc-summary-label">Pozostało</span>
                        <span class="alloc-summary-value"><?php echo e(number_format(max(0, $entry['amount_net'] - $total_allocated), 2, ',', ' ')); ?> <?php echo e($entry['currency']); ?></span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="form-actions">
                    <a href="<?php echo $centerUrl; ?>" class="btn btn-secondary">← Centrum sprzedaży</a>
                    <a href="<?php echo url('finanse.sprzedaz-pozafakturowa.delete', ['id' => $id, 'source' => $returnSource]); ?>"
                       class="btn btn-danger-outline btn-sm"
                       onclick="return confirm('Na pewno usunąć ten wpis?')">Usuń</a>
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                </div>
            </div>
        </form>
    </div>

    <script>
    const allClients = <?php echo json_encode(array_map(fn($c) => [
        'id' => $c['id'], 'name' => $c['name'], 'nip' => $c['nip'] ?? ''
    ], $clients), JSON_UNESCAPED_UNICODE); ?>;

    const clientSearch        = document.getElementById('client-search');
    const clientDropdown      = document.getElementById('client-dropdown');
    const clientIdInput       = document.getElementById('client_id');
    const clientSelectedDiv   = document.getElementById('client-selected');
    const clientSearchWrapper = document.getElementById('client-search-wrapper');
    const manualCounterpartyInput = document.getElementById('counterparty_name_manual');

    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function renderClientDropdown(filter) {
        const q = (filter || '').toLowerCase();
        const filtered = allClients.filter(c => c.name.toLowerCase().includes(q) || (c.nip && c.nip.includes(q))).slice(0, 20);
        clientDropdown.innerHTML = filtered.length
            ? filtered.map(c => `<div class="client-option" data-id="${c.id}" data-name="${escH(c.name)}" data-nip="${escH(c.nip)}"><div class="client-option-name">${escH(c.name)}</div>${c.nip ? '<div class="client-option-nip">NIP: ' + escH(c.nip) + '</div>' : ''}</div>`).join('')
            : '<div style="padding:12px;color:#94a3b8;text-align:center;font-size:13px;">Brak wyników</div>';
        clientDropdown.classList.add('show');
    }
    if (clientSearch) {
        clientSearch.addEventListener('input', () => renderClientDropdown(clientSearch.value));
        clientSearch.addEventListener('focus', () => renderClientDropdown(clientSearch.value));
    }
    document.addEventListener('click', e => { if (clientDropdown && !e.target.closest('.client-search-wrap')) clientDropdown.classList.remove('show'); });
    if (clientDropdown) {
        clientDropdown.addEventListener('click', e => {
            const opt = e.target.closest('.client-option');
            if (opt) selectClient(opt.dataset.id, opt.dataset.name, opt.dataset.nip);
        });
    }
    function selectClient(id, name, nip) {
        clientIdInput.value = id;
        document.getElementById('client-selected-name').textContent = name;
        document.getElementById('client-selected-nip').textContent = nip ? 'NIP: ' + nip : '';
        clientSelectedDiv.style.display = 'flex';
        clientSearchWrapper.style.display = 'none';
        if (clientDropdown) clientDropdown.classList.remove('show');
        if (manualCounterpartyInput) {
            manualCounterpartyInput.value = '';
        }
    }
    function clearClient() {
        clientIdInput.value = '';
        clientSelectedDiv.style.display = 'none';
        clientSearchWrapper.style.display = 'block';
        if (clientSearch) { clientSearch.value = ''; clientSearch.focus(); }
    }

    (function initClientSelection() {
        const selectedId = parseInt(clientIdInput.value || '0', 10);
        if (selectedId > 0) {
            const c = allClients.find(x => parseInt(x.id, 10) === selectedId);
            if (c) {
                selectClient(c.id, c.name, c.nip || '');
            }
        }
    })();

    function togglePaymentDate() {
        const statusSel = document.getElementById('payment_status');
        const group = document.getElementById('payment-date-group');
        if (statusSel && group) {
            group.style.display = statusSel.value === 'paid' ? 'flex' : 'none';
        }
    }
    togglePaymentDate();
    </script>
</body>
</html>
