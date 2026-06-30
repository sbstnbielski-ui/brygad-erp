<?php
/**
 * BRYGAD ERP - Katalog Towarów i Usług
 */
require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$search = trim($_GET['search'] ?? '');
$showInactive = ($_GET['inactive'] ?? '') === '1';

$where = $showInactive ? '1=1' : 'is_active = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (name LIKE ? OR code LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$stmt = $pdo->prepare("SELECT * FROM erp_products WHERE {$where} ORDER BY name ASC");
$stmt->execute($params);
$products = $stmt->fetchAll();

$countAll = (int)$pdo->query("SELECT COUNT(*) FROM erp_products WHERE is_active = 1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Katalog Towarów i Usług</title>
    <style>
        :root { --primary-blue: #1e3a8a; --bg-body: #f5f7fa; --border: #e5e7eb; --text-main: #1f2937; --text-muted: #6b7280; --success: #16a34a; --danger: #ef4444; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 25px; }

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
        .btn-hero { background: #fff; color: #1e3a8a; border: none; font-weight: 700; padding: 9px 18px; border-radius: 8px; text-decoration: none; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
        .toolbar input[type="text"] { padding: 9px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; min-width: 260px; }
        .toolbar input:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .toolbar-count { font-size: 13px; color: var(--text-muted); margin-left: auto; }

        .card { background: #fff; border-radius: 12px; border: 1px solid var(--border); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead th { padding: 10px 14px; text-align: left; font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--border); background: #f8fafc; }
        tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }

        .btn-sm { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid; transition: all 0.15s; background: #fff; }
        .btn-edit { color: #2563eb; border-color: #93c5fd; }
        .btn-edit:hover { background: #eff6ff; }
        .btn-del { color: #dc2626; border-color: #fca5a5; }
        .btn-del:hover { background: #fef2f2; }
        .btn-save { color: #fff; background: var(--success); border-color: var(--success); }
        .btn-save:hover { background: #15803d; }
        .btn-cancel { color: #374151; border-color: #d1d5db; }

        .no-data { padding: 40px; text-align: center; color: var(--text-muted); }
        .actions { display: flex; gap: 5px; }

        #add-form { background: #f0fdf4; border-bottom: 2px solid #bbf7d0; }
        #add-form td { padding: 8px 10px; }
        #add-form input, #add-form select { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; width: 100%; }
        #add-form input:focus, #add-form select:focus { outline: none; border-color: var(--success); }

        .edit-row input, .edit-row select { padding: 5px 8px; border: 1px solid #93c5fd; border-radius: 6px; font-size: 13px; width: 100%; background: #eff6ff; }
        .edit-row input:focus, .edit-row select:focus { outline: none; border-color: #2563eb; }

        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #22c55e; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
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
                    Towary i usługi
                </div>
                <h1>Katalog towarów i usług</h1>
                <p>Zarządzaj cenami i pozycjami na fakturach sprzedażowych</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn-hero-secondary">← Faktury</a>
            </div>
        </div>

        <div id="alert-box"></div>

        <div class="toolbar">
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Szukaj po nazwie lub kodzie...">
                <?php if ($showInactive): ?>
                    <input type="hidden" name="inactive" value="1">
                <?php endif; ?>
                <button type="submit" class="btn-sm btn-edit">Szukaj</button>
                <?php if ($search): ?>
                    <a href="<?php echo url('finanse.towary'); ?><?php echo $showInactive ? '?inactive=1' : ''; ?>" class="btn-sm btn-cancel">Wyczyść</a>
                <?php endif; ?>
            </form>
            <label style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                <input type="checkbox" id="toggle-inactive" <?php echo $showInactive ? 'checked' : ''; ?>> Pokaż nieaktywne
            </label>
            <span class="toolbar-count"><?php echo $countAll; ?> aktywnych pozycji</span>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="width:100px;">Kod</th>
                        <th>Nazwa towaru / usługi</th>
                        <th style="width:70px;" class="text-center">Jedn.</th>
                        <th style="width:70px;" class="text-center">VAT</th>
                        <th style="width:120px;" class="text-right">Cena netto</th>
                        <th style="width:80px;" class="text-center">Status</th>
                        <th style="width:140px;">Akcje</th>
                    </tr>
                </thead>
                <tbody id="products-tbody">
                    <tr id="add-form">
                        <td class="text-center" style="color:var(--success);font-weight:700;">+</td>
                        <td><input type="text" id="new-code" placeholder="Kod"></td>
                        <td><input type="text" id="new-name" placeholder="Nazwa towaru lub usługi *"></td>
                        <td>
                            <select id="new-unit">
                                <option value="szt">szt</option>
                                <option value="usł" selected>usł</option>
                                <option value="godz">godz</option>
                                <option value="m2">m²</option>
                                <option value="mb">mb</option>
                                <option value="kg">kg</option>
                                <option value="kpl">kpl</option>
                            </select>
                        </td>
                        <td>
                            <select id="new-vat">
                                <option value="23" selected>23%</option>
                                <option value="8">8%</option>
                                <option value="5">5%</option>
                                <option value="0">0%</option>
                                <option value="zw">zw</option>
                            </select>
                        </td>
                        <td><input type="number" id="new-price" step="0.01" min="0" placeholder="0.00" style="text-align:right;"></td>
                        <td></td>
                        <td><button class="btn-sm btn-save" onclick="addProduct()">Dodaj</button></td>
                    </tr>
                    <?php if (count($products) === 0): ?>
                    <tr id="no-data-row"><td colspan="8" class="no-data">Katalog jest pusty. Dodaj pierwszy towar lub usługę powyżej.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($products as $i => $p): ?>
                    <tr data-id="<?php echo $p['id']; ?>" id="row-<?php echo $p['id']; ?>">
                        <td class="text-center" style="color:var(--text-muted);"><?php echo $i + 1; ?></td>
                        <td style="font-family:monospace;font-size:12px;color:var(--text-muted);"><?php echo e($p['code'] ?? ''); ?></td>
                        <td style="font-weight:600;"><?php echo e($p['name']); ?></td>
                        <td class="text-center"><?php echo e($p['unit']); ?></td>
                        <td class="text-center"><?php echo $p['vat_rate'] === 'zw' ? 'zw' : e($p['vat_rate']) . '%'; ?></td>
                        <td class="text-right" style="font-weight:600;"><?php echo number_format((float)$p['price_net'], 2, ',', ' '); ?> zł</td>
                        <td class="text-center">
                            <span class="badge <?php echo $p['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                <?php echo $p['is_active'] ? 'Aktywny' : 'Ukryty'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <button class="btn-sm btn-edit" onclick="editRow(<?php echo $p['id']; ?>)">Edytuj</button>
                                <?php if ($p['is_active']): ?>
                                <button class="btn-sm btn-del" onclick="deleteProduct(<?php echo $p['id']; ?>, '<?php echo e(addslashes($p['name'])); ?>')">Ukryj</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.getElementById('toggle-inactive').addEventListener('change', function() {
        const url = new URL(window.location);
        if (this.checked) url.searchParams.set('inactive', '1');
        else url.searchParams.delete('inactive');
        window.location = url;
    });

    function showAlert(msg, type) {
        const box = document.getElementById('alert-box');
        box.innerHTML = '<div class="alert alert-' + type + '">' + msg + '</div>';
        setTimeout(() => box.innerHTML = '', 4000);
    }

    function addProduct() {
        const name = document.getElementById('new-name').value.trim();
        if (!name) { document.getElementById('new-name').focus(); return; }

        const body = new URLSearchParams({
            name,
            code: document.getElementById('new-code').value.trim(),
            unit: document.getElementById('new-unit').value,
            vat_rate: document.getElementById('new-vat').value,
            price_net: document.getElementById('new-price').value || '0',
        });

        fetch('/api/products/save.php', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                if (d.mode === 'exists') showAlert('Towar/usługa "' + name + '" już istnieje w katalogu.', 'error');
                else { showAlert('Dodano: ' + name, 'success'); location.reload(); }
            } else {
                showAlert(d.error || 'Błąd', 'error');
            }
        }).catch(() => showAlert('Błąd połączenia', 'error'));
    }

    document.getElementById('new-name').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addProduct(); } });

    function editRow(id) {
        const row = document.getElementById('row-' + id);
        const cells = row.querySelectorAll('td');
        const code = cells[1].textContent.trim();
        const name = cells[2].textContent.trim();
        const unit = cells[3].textContent.trim();
        const vatText = cells[4].textContent.trim().replace('%', '');
        const priceText = cells[5].textContent.trim().replace(/[^\d,.-]/g, '').replace(',', '.').replace(/\s/g, '');

        const unitOpts = ['szt','usł','godz','m2','mb','kg','kpl'].map(u =>
            '<option value="' + u + '"' + (unit === u || (u === 'm2' && unit === 'm²') ? ' selected' : '') + '>' + (u === 'm2' ? 'm²' : u) + '</option>').join('');
        const vatOpts = [['23','23%'],['8','8%'],['5','5%'],['0','0%'],['zw','zw']].map(([v,l]) =>
            '<option value="' + v + '"' + (vatText === v || vatText === l ? ' selected' : '') + '>' + l + '</option>').join('');

        row.classList.add('edit-row');
        cells[1].innerHTML = '<input type="text" value="' + code + '" class="e-code">';
        cells[2].innerHTML = '<input type="text" value="' + name + '" class="e-name">';
        cells[3].innerHTML = '<select class="e-unit">' + unitOpts + '</select>';
        cells[4].innerHTML = '<select class="e-vat">' + vatOpts + '</select>';
        cells[5].innerHTML = '<input type="number" step="0.01" min="0" value="' + priceText + '" class="e-price" style="text-align:right;">';
        cells[7].innerHTML = '<div class="actions"><button class="btn-sm btn-save" onclick="saveRow(' + id + ')">Zapisz</button><button class="btn-sm btn-cancel" onclick="location.reload()">Anuluj</button></div>';
    }

    function saveRow(id) {
        const row = document.getElementById('row-' + id);
        const name = row.querySelector('.e-name').value.trim();
        if (!name) { row.querySelector('.e-name').focus(); return; }

        const body = new URLSearchParams({
            id,
            name,
            code: row.querySelector('.e-code').value.trim(),
            unit: row.querySelector('.e-unit').value,
            vat_rate: row.querySelector('.e-vat').value,
            price_net: row.querySelector('.e-price').value || '0',
        });

        fetch('/api/products/save.php', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.success) { showAlert('Zapisano zmiany', 'success'); location.reload(); }
            else showAlert(d.error || 'Błąd', 'error');
        }).catch(() => showAlert('Błąd połączenia', 'error'));
    }

    function deleteProduct(id, name) {
        if (!confirm('Ukryć "' + name + '" z katalogu? (nie usunie z wystawionych faktur)')) return;
        fetch('/api/products/delete.php', { method: 'POST', body: new URLSearchParams({ id }), })
        .then(r => r.json())
        .then(d => {
            if (d.success) { showAlert('Ukryto: ' + name, 'success'); document.getElementById('row-' + id).remove(); }
            else showAlert(d.error || 'Błąd', 'error');
        }).catch(() => showAlert('Błąd połączenia', 'error'));
    }
    </script>
</body>
</html>
