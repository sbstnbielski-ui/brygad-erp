<?php
/**
 * BRYGAD ERP - Nowy wpis pozafakturowy (PZF)
 *
 * Guardrail: NIE korzysta z invoices_sale, Fakturowni, KSeF ani project_revenues.
 * Brak draftu. Zapis bezpośrednio do sales_noninvoice_entries.
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

// ── Helper numeracji PZF/NNN/RRRR ──────────────────────────────────────────
function generatePzfNumber(PDO $pdo): string
{
    $year  = date('Y');
    $month = date('m');
    $like  = "PZF/%/{$month}/{$year}";
    $stmt  = $pdo->prepare(
        "SELECT entry_number FROM sales_noninvoice_entries
         WHERE entry_number LIKE ?
           AND entry_number REGEXP '^PZF/[0-9]+/[0-9]{2}/[0-9]{4}$'"
    );
    $stmt->execute([$like]);
    $max = 0;
    while ($row = $stmt->fetchColumn()) {
        $parts = explode('/', $row); // PZF / NNN / MM / YYYY
        $max   = max($max, (int)($parts[1] ?? 0));
    }
    $seq = str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    return "PZF/{$seq}/{$month}/{$year}";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token sesji (CSRF). Odśwież stronę i spróbuj ponownie.';
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
    $payment_date   = ($payment_status === 'paid' && !empty($_POST['payment_date']))
                      ? trim($_POST['payment_date'])
                      : null;

    // Walidacja
    if ($client_id > 0) {
        $counterparty_name_manual = '';
    } else {
        $counterparty_name_manual = mb_substr($counterparty_name_manual, 0, 255);
    }

    if ($client_id <= 0 && $counterparty_name_manual === '') {
        $errors[] = 'Wybierz Kontrahenta z listy albo wpisz Kontrahenta ręcznie.';
    }
    if (empty($title))         $errors[] = 'Tytuł jest wymagany.';
    if (empty($issue_date))    $errors[] = 'Data wystawienia jest wymagana.';
    if ($amount_net <= 0)      $errors[] = 'Kwota musi być większa od 0.';
    if (!in_array($currency, ['PLN','EUR','USD','GBP'], true)) $currency = 'PLN';

    // Jeśli status = paid i brak payment_date → ustaw dziś
    if ($payment_status === 'paid' && empty($payment_date)) {
        $payment_date = date('Y-m-d');
    }

    if (empty($errors)) {
        try {
            $entry_id = null;
            $entry_number = null;
            $amount_vat   = 0.00;
            $amount_gross = $amount_net;
            $maxAttempts = 3;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $pdo->beginTransaction();

                    $entry_number = generatePzfNumber($pdo);
                    $stmt = $pdo->prepare("
                        INSERT INTO sales_noninvoice_entries
                            (entry_number, client_id, counterparty_name_manual, title, notes, issue_date, due_date, payment_date,
                             currency, amount_net, amount_vat, amount_gross, payment_status, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $entry_number, $client_id > 0 ? $client_id : null, $counterparty_name_manual !== '' ? $counterparty_name_manual : null, $title, $notes ?: null,
                        $issue_date, $due_date, $payment_date,
                        $currency, $amount_net, $amount_vat, $amount_gross,
                        $payment_status, $_SESSION['user_id']
                    ]);
                    $entry_id = $pdo->lastInsertId();
                    $pdo->commit();
                    break;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $isDuplicateEntryNumber = $e->getCode() === '23000'
                        && str_contains(mb_strtolower($e->getMessage()), 'entry_number');
                    if ($isDuplicateEntryNumber && $attempt < $maxAttempts) {
                        continue;
                    }
                    throw $e;
                }
            }

            logEvent("Utworzono wpis PZF: {$entry_number}, ID: {$entry_id}", 'INFO');
            header("Location: " . url('finanse.sprzedaz-pozafakturowa.edit', [
                'id' => $entry_id,
                'source' => $returnSource,
                'success' => 'created',
            ]));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logEvent("Błąd tworzenia PZF: " . $e->getMessage(), 'ERROR');
            $errors[] = 'Błąd podczas zapisu wpisu.';
        }
    }
}

// Lista aktywnych kontrahentów
$clients = $pdo->query("SELECT id, name, nip FROM investors WHERE is_active = 1 ORDER BY name ASC")
               ->fetchAll(PDO::FETCH_ASSOC);

$suggested_number = generatePzfNumber($pdo);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Nowy wpis pozafakturowy</title>
    <style>
        :root {
            --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb;
            --text-main: #1f2937; --text-muted: #6b7280;
            --success: #16a34a; --success-dark: #15803d; --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; }
        .container { max-width: 960px; margin: 0 auto; padding: 25px 30px 60px; }
        .hero { background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%); color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .hero h1 { margin: 0 0 4px; font-size: 24px; font-weight: 700; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-sub { margin: 0; color: #cbd5e1; font-size: 13px; }
        .hero-actions { display: flex; gap: 8px; }
        .badge-pzf { background: rgba(255,255,255,0.15); color: #e0f2fe; border: 1px solid rgba(255,255,255,0.25); font-size: 11px; padding: 3px 10px; border-radius: 10px; font-weight: 700; letter-spacing: 0.5px; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 18px; border: 1px solid var(--border); }
        .section-title { font-size: 16px; font-weight: 700; color: #0f172a; border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 14px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        @media (max-width: 700px) { .form-grid, .form-grid-3 { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; min-width: 0; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .required { color: #dc2626; }
        .form-group input, .form-group select, .form-group textarea { padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; transition: all 0.15s; width: 100%; background: #fff; }
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
        .client-selected-name { font-weight: 600; font-size: 14px; }
        .client-selected-nip { font-size: 12px; color: var(--text-muted); }
        .client-selected-clear { background: none; border: none; color: #dc2626; cursor: pointer; font-size: 18px; margin-left: auto; padding: 0 4px; }
        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; }
        .btn-primary { background: linear-gradient(135deg, var(--success), var(--success-dark)); color: #fff; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid var(--border); flex-wrap: wrap; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        #payment-date-row { display: none; }
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
                    Nowy wpis pozafakturowy
                </div>
                <h1>Nowy wpis pozafakturowy <span class="badge-pzf">PZF</span></h1>
                <p class="hero-sub">Przychód bez faktury — tylko lokalny w ERP, nie trafia do Fakturowni / KSeF</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo $centerUrl; ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błędy:</strong>
                <ul style="margin: 6px 0 0 18px;">
                    <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="pzfForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="return_source" value="<?php echo e($returnSource); ?>">

            <!-- KONTRAHENT -->
            <div class="card">
                <div class="section-title">Kontrahent</div>
                <input type="hidden" name="client_id" id="client_id" value="<?php echo e($_POST['client_id'] ?? ''); ?>">
                <div id="client-selected" class="client-selected" style="display:none;">
                    <div>
                        <div class="client-selected-name" id="client-selected-name"></div>
                        <div class="client-selected-nip" id="client-selected-nip"></div>
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
                           value="<?php echo e($_POST['counterparty_name_manual'] ?? ''); ?>"
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
                        <input type="text" name="entry_number_preview" value="<?php echo e($suggested_number); ?>" disabled>
                        <div class="help-text">Numer generowany automatycznie po zapisie.</div>
                    </div>
                    <div class="form-group">
                        <label>Status płatności <span class="required">*</span></label>
                        <select name="payment_status" id="payment_status" onchange="togglePaymentDate()">
                            <option value="unpaid" <?php echo ($_POST['payment_status'] ?? 'unpaid') === 'unpaid' ? 'selected' : ''; ?>>Nieopłacona</option>
                            <option value="paid"   <?php echo ($_POST['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Opłacona</option>
                        </select>
                    </div>
                </div>
                <div class="form-group full-width" style="margin-bottom:14px;">
                    <label>Tytuł <span class="required">*</span></label>
                    <input type="text" name="title" value="<?php echo e($_POST['title'] ?? ''); ?>" required>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Data wystawienia <span class="required">*</span></label>
                        <input type="date" name="issue_date" value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Termin płatności</label>
                        <input type="date" name="due_date" value="<?php echo e($_POST['due_date'] ?? ''); ?>">
                    </div>
                    <div id="payment-date-row" class="form-group">
                        <label>Data zapłaty</label>
                        <input type="date" name="payment_date" id="payment_date" value="<?php echo e($_POST['payment_date'] ?? ''); ?>">
                        <div class="help-text">Zostaw puste — zostanie ustawiona na dziś.</div>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Kwota netto <span class="required">*</span></label>
                        <input type="number" name="amount_net" value="<?php echo e($_POST['amount_net'] ?? ''); ?>" step="0.01" min="0.01" required>
                        <div class="help-text">Kwota brutto = kwota netto (VAT = 0)</div>
                    </div>
                    <div class="form-group">
                        <label>Waluta</label>
                        <select name="currency">
                            <?php foreach (['PLN','EUR','USD','GBP'] as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo ($_POST['currency'] ?? 'PLN') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group full-width">
                    <label>Notatka / opis</label>
                    <textarea name="notes"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="card">
                <div class="form-actions">
                    <a href="<?php echo $centerUrl; ?>" class="btn btn-secondary">Anuluj</a>
                    <button type="submit" class="btn btn-primary">Zapisz wpis PZF</button>
                </div>
            </div>
        </form>
    </div>

    <script>
    const allClients = <?php echo json_encode(array_map(fn($c) => [
        'id' => $c['id'], 'name' => $c['name'], 'nip' => $c['nip'] ?? ''
    ], $clients), JSON_UNESCAPED_UNICODE); ?>;

    const clientSearch   = document.getElementById('client-search');
    const clientDropdown = document.getElementById('client-dropdown');
    const clientIdInput  = document.getElementById('client_id');
    const clientSelectedDiv = document.getElementById('client-selected');
    const clientSearchWrapper = document.getElementById('client-search-wrapper');
    const manualCounterpartyInput = document.getElementById('counterparty_name_manual');

    function renderClientDropdown(filter) {
        const q = (filter || '').toLowerCase();
        const filtered = allClients.filter(c => c.name.toLowerCase().includes(q) || (c.nip && c.nip.includes(q))).slice(0, 20);
        clientDropdown.innerHTML = filtered.length
            ? filtered.map(c => `<div class="client-option" data-id="${c.id}" data-name="${escH(c.name)}" data-nip="${escH(c.nip)}"><div class="client-option-name">${escH(c.name)}</div>${c.nip ? '<div class="client-option-nip">NIP: ' + escH(c.nip) + '</div>' : ''}</div>`).join('')
            : '<div style="padding:12px;color:#94a3b8;font-size:13px;text-align:center;">Brak wyników</div>';
        clientDropdown.classList.add('show');
    }
    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    clientSearch.addEventListener('input', () => renderClientDropdown(clientSearch.value));
    clientSearch.addEventListener('focus', () => renderClientDropdown(clientSearch.value));
    document.addEventListener('click', e => { if (!e.target.closest('.client-search-wrap')) clientDropdown.classList.remove('show'); });
    clientDropdown.addEventListener('click', e => {
        const opt = e.target.closest('.client-option');
        if (opt) selectClient(opt.dataset.id, opt.dataset.name, opt.dataset.nip);
    });
    function selectClient(id, name, nip) {
        clientIdInput.value = id;
        document.getElementById('client-selected-name').textContent = name;
        document.getElementById('client-selected-nip').textContent = nip ? 'NIP: ' + nip : '';
        clientSelectedDiv.style.display = 'flex';
        clientSearchWrapper.style.display = 'none';
        clientDropdown.classList.remove('show');
        if (manualCounterpartyInput) {
            manualCounterpartyInput.value = '';
        }
    }
    function clearClient() {
        clientIdInput.value = '';
        clientSelectedDiv.style.display = 'none';
        clientSearchWrapper.style.display = 'block';
        clientSearch.value = '';
        clientSearch.focus();
    }
    <?php if (!empty($_POST['client_id'])): ?>
    (function() {
        const c = allClients.find(x => x.id == <?php echo (int)$_POST['client_id']; ?>);
        if (c) selectClient(c.id, c.name, c.nip);
    })();
    <?php endif; ?>

    function togglePaymentDate() {
        const row = document.getElementById('payment-date-row');
        row.style.display = document.getElementById('payment_status').value === 'paid' ? 'flex' : 'none';
    }
    togglePaymentDate();
    </script>
</body>
</html>
