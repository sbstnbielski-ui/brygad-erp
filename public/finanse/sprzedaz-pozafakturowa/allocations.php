<?php
/**
 * BRYGAD ERP - Alokacje wpisu pozafakturowego (PZF)
 *
 * Guardrail:
 * - UI działa jako osobny ekran modułu finanse
 * - zapis/odczyt idzie wyłącznie przez sales_noninvoice_allocations
 * - API JSON pozostaje w /api/sales-noninvoice/assign-project.php
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo     = getDbConnection();
$entryId = (int)($_GET['entry_id'] ?? 0);

function resolvePzfReturnSource(string $default = 'noninvoice'): string
{
    $source = (string)($_GET['source'] ?? $default);
    return in_array($source, ['invoice', 'noninvoice', 'all'], true) ? $source : $default;
}

function pzfCenterUrl(string $source, array $extra = []): string
{
    return url('finanse.faktury-sprzedazowe', array_merge(['source' => $source], $extra));
}

$returnSource = resolvePzfReturnSource();
$centerUrl = pzfCenterUrl($returnSource);
$editUrl = $entryId > 0
    ? url('finanse.sprzedaz-pozafakturowa.edit', ['id' => $entryId, 'source' => $returnSource])
    : $centerUrl;

if ($entryId <= 0) {
    header('Location: ' . pzfCenterUrl($returnSource, ['error' => 'pzf_not_found']));
    exit;
}

$stmt = $pdo->prepare("
    SELECT e.*, COALESCE(i.name, e.counterparty_name_manual) AS client_name
    FROM sales_noninvoice_entries e
    LEFT JOIN investors i ON i.id = e.client_id
    WHERE e.id = ?
");
$stmt->execute([$entryId]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    header('Location: ' . pzfCenterUrl($returnSource, ['error' => 'pzf_not_found']));
    exit;
}

$projects = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active','in_progress') ORDER BY name ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Alokacje PZF <?php echo e($entry['entry_number']); ?></title>
    <style>
        :root { --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; --success: #10b981; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; }
        .container { max-width: 980px; margin: 0 auto; padding: 25px 30px 60px; }
        .hero { background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%); color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .hero h1 { margin: 0 0 4px; font-size: 20px; font-weight: 700; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-sub { margin: 0; color: #cbd5e1; font-size: 13px; }
        .badge-pzf { background: rgba(255,255,255,0.15); color: #e0f2fe; border: 1px solid rgba(255,255,255,0.25); font-size: 11px; padding: 3px 10px; border-radius: 10px; font-weight: 700; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .card { background: #fff; border-radius: 14px; padding: 28px 32px; border: 1px solid var(--border); box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08); }
        .alloc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
        .alloc-header-main { min-width: 0; }
        .alloc-eyebrow { font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600; }
        .alloc-title-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 2px; }
        .alloc-title { font-size: 18px; font-weight: 700; color: #111827; }
        .alloc-title-badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; background: #ede9fe; color: #6d28d9; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .alloc-client { margin-top: 4px; color: #6b7280; font-size: 13px; }
        .alloc-amount-box { text-align: right; min-width: 140px; }
        .alloc-amount-label { font-size: 11px; color: #6b7280; text-transform: uppercase; font-weight: 600; }
        .alloc-amount-value { font-size: 18px; font-weight: 700; color: #2563eb; }

        .alloc-progress { margin-bottom: 14px; }
        .alloc-progress-track { height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
        .alloc-progress-fill { height: 100%; width: 0%; background: var(--success); border-radius: 999px; transition: width .25s ease; }
        .alloc-progress-meta { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 6px; flex-wrap: wrap; }
        .alloc-progress-label { font-size: 11px; color: #6b7280; }
        .alloc-progress-count { font-size: 11px; color: #6b7280; font-weight: 600; }

        .alloc-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 13px; }
        .alloc-table thead tr { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .alloc-table th { padding: 6px 8px; text-align: left; font-weight: 600; color: #374151; }
        .alloc-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .alloc-table td.amount { text-align: right; font-weight: 600; }
        .alloc-table td.note { color: #9ca3af; font-size: 12px; }
        .alloc-table td.empty { text-align: center; color: #9ca3af; padding: 12px; }
        .alloc-node-name { color: #9ca3af; }
        .alloc-remove { color: #dc2626; background: none; border: none; cursor: pointer; font-size: 13px; padding: 0; }

        .alloc-form-box { background: #f8fafc; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
        .alloc-form-title { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; margin-bottom: 8px; }
        .alloc-form-grid { display: grid; grid-template-columns: 1fr 120px; gap: 8px; margin-bottom: 8px; }
        .alloc-form-grid select, .alloc-form-grid input,
        .alloc-form-node select, .alloc-form-desc input {
            width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; background: #fff;
        }
        .alloc-form-grid input { text-align: right; }
        .alloc-form-grid select:focus, .alloc-form-grid input:focus,
        .alloc-form-node select:focus, .alloc-form-desc input:focus {
            outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .alloc-form-node { display: none; margin-bottom: 8px; }
        .alloc-form-desc { margin-bottom: 8px; }
        .alloc-form-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-primary { flex: 1; padding: 8px; border: none; border-radius: 8px; background: #2563eb; color: #fff; cursor: pointer; font-weight: 600; font-size: 13px; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; cursor: pointer; font-size: 12px; color: #374151; }
        .btn-secondary:hover { background: #f8fafc; }
        .btn-danger-outline { padding: 8px 14px; border: 1px solid #fecaca; border-radius: 8px; background: #fff; cursor: pointer; font-size: 12px; color: #dc2626; }
        .btn-danger-outline:hover { background: #fff5f5; }

        .alloc-footer { display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .alloc-error { display: none; background: #fee2e2; border-left: 4px solid #dc2626; color: #991b1b; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 8px; }
        .alloc-back { padding: 8px 18px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; cursor: pointer; font-size: 13px; color: #374151; text-decoration: none; }
        .alloc-back:hover { background: #f8fafc; }

        @media (max-width: 720px) {
            .container { padding: 20px 16px 48px; }
            .card { padding: 20px; }
            .alloc-header { flex-direction: column; align-items: stretch; }
            .alloc-amount-box { text-align: left; }
            .alloc-form-grid { grid-template-columns: 1fr; }
            .alloc-progress-meta, .alloc-footer { flex-direction: column; align-items: stretch; }
            .alloc-back, .btn-danger-outline { text-align: center; justify-content: center; }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo $centerUrl; ?>">Centrum sprzedaży</a> /
                    <a href="<?php echo $editUrl; ?>"><?php echo e($entry['entry_number']); ?></a> /
                    Alokacje
                </div>
                <h1>Alokacje <span class="badge-pzf">PZF</span></h1>
                <p class="hero-sub"><?php echo e($entry['entry_number']); ?> · <?php echo e($entry['client_name'] ?: '-'); ?></p>
            </div>
            <div>
                <a href="<?php echo $editUrl; ?>" class="btn-hero-secondary">← Powrót do wpisu</a>
            </div>
        </div>

        <div class="card">
            <div class="alloc-header">
                <div class="alloc-header-main">
                    <div class="alloc-eyebrow">Alokacja do projektów</div>
                    <div class="alloc-title-row">
                        <div class="alloc-title"><?php echo e($entry['entry_number']); ?></div>
                        <div class="alloc-title-badge" id="alloc-count-badge">0 alokacji</div>
                    </div>
                    <div class="alloc-client"><?php echo e($entry['client_name'] ?: '-'); ?></div>
                </div>
                <div class="alloc-amount-box">
                    <div class="alloc-amount-label">Kwota netto wpisu</div>
                    <div class="alloc-amount-value" id="alloc-entry-net"><?php echo number_format((float)$entry['amount_net'], 2, ',', ' '); ?> <?php echo e($entry['currency']); ?></div>
                </div>
            </div>

            <div class="alloc-progress">
                <div class="alloc-progress-track">
                    <div id="alloc-bar-fill" class="alloc-progress-fill"></div>
                </div>
                <div class="alloc-progress-meta">
                    <div id="alloc-sum-label" class="alloc-progress-label">0.00 / 0.00 netto (0%)</div>
                    <div id="alloc-count-label" class="alloc-progress-count">0 alokacji</div>
                </div>
            </div>

            <table class="alloc-table">
                <thead>
                    <tr>
                        <th>Projekt</th>
                        <th style="text-align:right;">Kwota netto</th>
                        <th>Opis</th>
                        <th style="width:32px;"></th>
                    </tr>
                </thead>
                <tbody id="alloc-tbody">
                    <tr><td colspan="4" class="empty">Ładuję…</td></tr>
                </tbody>
            </table>

            <div class="alloc-form-box">
                <div class="alloc-form-title">Dodaj alokację</div>
                <div id="addError" class="alloc-error"></div>
                <div class="alloc-form-grid">
                    <select id="alloc-add-project" onchange="onAllocProjectChange()">
                        <option value="">— Wybierz projekt —</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo (int)$project['id']; ?>"><?php echo e($project['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input id="alloc-add-amount" type="number" step="0.01" min="0.01" placeholder="0.00">
                </div>
                <div id="alloc-node-wrap" class="alloc-form-node">
                    <select id="alloc-add-node">
                        <option value="">— Bez etapu —</option>
                    </select>
                </div>
                <div class="alloc-form-desc">
                    <input id="alloc-add-desc" type="text" placeholder="Opis alokacji (opcjonalnie)">
                </div>
                <div class="alloc-form-actions">
                    <button type="button" class="btn-primary" onclick="addAllocation()">+ Dodaj</button>
                    <button type="button" class="btn-secondary" onclick="allocSetFull()" title="Cała kwota na wybrany projekt">Cała kwota →</button>
                </div>
            </div>

            <div class="alloc-footer">
                <button type="button" class="btn-danger-outline" onclick="allocUnassignAll()">Odepnij wszystko</button>
                <a href="<?php echo $editUrl; ?>" class="alloc-back">Zamknij</a>
            </div>
        </div>
    </div>

    <script>
    var ENTRY_ID = <?php echo $entryId; ?>;
    var ENTRY_NET = 0;
    var ENTRY_CURRENCY = <?php echo json_encode((string)$entry['currency'], JSON_UNESCAPED_UNICODE); ?>;
    var API = '/api/sales-noninvoice/assign-project.php';

    function fmt(v) {
        var n = parseFloat(v) || 0;
        return n.toFixed(2);
    }

    function fmtMoney(v) {
        return fmt(v).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function escH(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function allocWord(count) {
        if (count === 1) return 'alokacja';
        var mod10 = count % 10;
        var mod100 = count % 100;
        if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) return 'alokacje';
        return 'alokacji';
    }

    function updateCountLabels(count) {
        var label = count + ' ' + allocWord(count);
        document.getElementById('alloc-count-label').textContent = label;
        document.getElementById('alloc-count-badge').textContent = label;
    }

    function resetForm() {
        document.getElementById('alloc-add-project').value = '';
        document.getElementById('alloc-add-amount').value = '';
        document.getElementById('alloc-add-desc').value = '';
        document.getElementById('alloc-add-node').innerHTML = '<option value="">— Bez etapu —</option>';
        document.getElementById('alloc-node-wrap').style.display = 'none';
    }

    function showError(msg) {
        var el = document.getElementById('addError');
        el.textContent = msg;
        el.style.display = 'block';
    }

    function hideError() {
        document.getElementById('addError').style.display = 'none';
    }

    function loadEntry() {
        fetch(API + '?action=entry&entry_id=' + ENTRY_ID)
            .then(function(r) { return r.json(); })
            .then(function(entry) {
                ENTRY_NET = parseFloat(entry.amount_net) || 0;
                ENTRY_CURRENCY = entry.currency || ENTRY_CURRENCY;
                document.getElementById('alloc-entry-net').textContent = fmtMoney(ENTRY_NET) + ' ' + ENTRY_CURRENCY;
                loadAllocations();
            });
    }

    function loadAllocations() {
        fetch(API + '?action=allocations&entry_id=' + ENTRY_ID)
            .then(function(r) { return r.json(); })
            .then(function(rows) {
                var tbody = document.getElementById('alloc-tbody');
                var sum = 0;
                updateCountLabels(rows.length);
                if (!rows.length) {
                    tbody.innerHTML = '<tr><td colspan="4" class="empty">Brak alokacji</td></tr>';
                } else {
                    var html = '';
                    rows.forEach(function(a) {
                        sum += parseFloat(a.amount_net) || 0;
                        html += '<tr>'
                            + '<td>' + escH(a.project_name) + (a.node_name ? ' <span class="alloc-node-name">/ ' + escH(a.node_name) + '</span>' : '') + '</td>'
                            + '<td class="amount">' + fmtMoney(a.amount_net) + '</td>'
                            + '<td class="note">' + escH(a.description || '') + '</td>'
                            + '<td style="text-align:center;"><button type="button" class="alloc-remove" onclick="deleteAllocation(' + a.id + ')" title="Usuń">✕</button></td>'
                            + '</tr>';
                    });
                    tbody.innerHTML = html;
                }

                var rawPct = ENTRY_NET > 0 ? (sum / ENTRY_NET * 100) : 0;
                var barPct = Math.max(0, Math.min(100, rawPct));
                var remain = ENTRY_NET - sum;
                var fill = document.getElementById('alloc-bar-fill');
                fill.style.width = barPct + '%';
                fill.style.background = rawPct > 100.005 ? '#dc2626' : '#10b981';
                document.getElementById('alloc-sum-label').textContent = fmt(sum) + ' / ' + fmt(ENTRY_NET) + ' netto (' + rawPct.toFixed(0) + '%)';
                document.getElementById('alloc-add-amount').placeholder = remain > 0 ? fmt(remain) : '0.00';
            });
    }

    function onAllocProjectChange() {
        var pid = document.getElementById('alloc-add-project').value;
        var wrap = document.getElementById('alloc-node-wrap');
        var sel = document.getElementById('alloc-add-node');
        if (!pid) {
            wrap.style.display = 'none';
            sel.innerHTML = '<option value="">— Bez etapu —</option>';
            return;
        }
        wrap.style.display = 'block';
        sel.innerHTML = '<option value="">Ładuję…</option>';
        fetch(API + '?action=nodes&project_id=' + encodeURIComponent(pid))
            .then(function(r) { return r.json(); })
            .then(function(nodes) {
                var html = '<option value="">— Bez etapu —</option>';
                nodes.filter(function(n) { return !n.parent_id; }).forEach(function(root) {
                    html += '<option value="' + root.id + '">' + escH(root.name) + '</option>';
                    nodes.filter(function(n) { return String(n.parent_id) === String(root.id); }).forEach(function(child) {
                        html += '<option value="' + child.id + '">&nbsp;&nbsp;↳ ' + escH(child.name) + '</option>';
                    });
                });
                sel.innerHTML = html;
            });
    }

    function addAllocation() {
        hideError();
        var pid = document.getElementById('alloc-add-project').value;
        var amt = document.getElementById('alloc-add-amount').value || document.getElementById('alloc-add-amount').placeholder;
        var nid = document.getElementById('alloc-add-node').value;
        var desc = document.getElementById('alloc-add-desc').value.trim();
        if (!pid) { showError('Wybierz projekt.'); return; }
        if (!amt || parseFloat(amt) <= 0) { showError('Podaj kwotę większą od 0.'); return; }

        var body = new URLSearchParams();
        body.set('action', 'add');
        body.set('entry_id', ENTRY_ID);
        body.set('project_id', pid);
        body.set('amount_net', amt);
        if (nid) body.set('cost_node_id', nid);
        if (desc) body.set('description', desc);

        fetch(API, { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    resetForm();
                    loadAllocations();
                } else {
                    showError(d.error || 'Błąd zapisu.');
                }
            });
    }

    function deleteAllocation(id) {
        if (!confirm('Usunąć alokację?')) return;
        var body = new URLSearchParams();
        body.set('action', 'delete');
        body.set('allocation_id', id);
        fetch(API, { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function() { loadAllocations(); });
    }

    function allocSetFull() {
        hideError();
        var pid = document.getElementById('alloc-add-project').value;
        if (!pid) { showError('Wybierz najpierw projekt.'); return; }
        var nid = document.getElementById('alloc-add-node').value || '';
        if (!confirm('Przypisać całą kwotę wpisu do tego projektu? Istniejące alokacje zostaną usunięte.')) return;

        var body = new URLSearchParams();
        body.set('action', 'set');
        body.set('entry_id', ENTRY_ID);
        body.set('project_id', pid);
        if (nid) body.set('cost_node_id', nid);

        fetch(API, { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    resetForm();
                    loadAllocations();
                } else {
                    showError(d.error || 'Błąd zapisu.');
                }
            });
    }

    function allocUnassignAll() {
        hideError();
        if (!confirm('Odpiąć wpis od wszystkich projektów?')) return;
        var body = new URLSearchParams();
        body.set('action', 'set');
        body.set('entry_id', ENTRY_ID);
        body.set('project_id', '0');
        fetch(API, { method: 'POST', body: body })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    resetForm();
                    loadAllocations();
                } else {
                    showError(d.error || 'Błąd zapisu.');
                }
            });
    }

    resetForm();
    loadEntry();
    </script>
</body>
</html>
