<?php
/**
 * BRYGAD ERP - Ustawienia integracji Fakturownia (konsola techniczna)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Ustawienia Fakturownia</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
            margin: 0;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px;
        }
        .breadcrumb {
            margin-bottom: 18px;
            color: #6b7280;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #2563eb;
            text-decoration: none;
        }
        .page-header {
            margin-bottom: 22px;
        }
        .page-header h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }
        .page-header p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 14px;
        }
        .card-link {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            display: block;
        }
        .card-link:hover {
            border-color: #2563eb;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.1);
            transform: translateY(-1px);
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .card-desc {
            font-size: 13px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
            <a href="<?php echo url('finanse'); ?>">Finanse</a> /
            Ustawienia integracji
        </div>

        <div class="page-header">
            <h1>Konsola integracji Fakturownia</h1>
            <p>Widok techniczny: diagnostyka API, rekonsyliacja i operacje serwisowe.</p>
        </div>

        <div class="grid">
            <a href="<?php echo url('finanse.company-settings'); ?>" class="card-link" style="border-color: #16a34a;">
                <div class="card-title">Dane firmy (sprzedawca)</div>
                <div class="card-desc">Logo, NIP, konto bankowe, domyślne ustawienia faktur sprzedażowych.</div>
            </a>

            <a href="<?php echo url('finanse.fakturownia-logs'); ?>" class="card-link">
                <div class="card-title">Logi API Fakturowni</div>
                <div class="card-desc">Diagnostyka 2xx/4xx/5xx, retry oraz błędy integracji.</div>
            </a>

            <a href="<?php echo url('finanse.fakturownia-reconciliation'); ?>" class="card-link">
                <div class="card-title">Rekonsyliacja Fakturowni</div>
                <div class="card-desc">Spójność mapowań, kolejki KSeF, alokacje i archiwum.</div>
            </a>

            <a href="<?php echo url('finanse.fakturownia-cost-inbox'); ?>" class="card-link">
                <div class="card-title">Inbox kosztów (Fakturownia/KSeF)</div>
                <div class="card-desc">Operacyjna praca na fakturach kosztowych i workflow.</div>
            </a>

            <a href="<?php echo url('finanse.fakturownia-archive'); ?>" class="card-link">
                <div class="card-title">Archiwum Fakturowni (PDF)</div>
                <div class="card-desc">Podgląd i pobieranie dokumentów PDF z archiwum ERP.</div>
            </a>

            <div class="card-link" style="cursor:default;">
                <div class="card-title">Synchronizuj faktury sprzedażowe</div>
                <div class="card-desc" style="margin-bottom:10px;">Pobierz brakujące faktury wystawione bezpośrednio w Fakturowni do ERP.</div>
                <button type="button" onclick="runSalesBackfill(this)" style="padding:8px 16px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer; font-size:13px;">Synchronizuj teraz</button>
                <div id="sync-result" style="margin-top:8px; font-size:12px;"></div>
            </div>
        </div>

        <script>
        function runSalesBackfill(btn) {
            btn.disabled = true;
            btn.textContent = 'Synchronizuję...';
            const result = document.getElementById('sync-result');
            result.textContent = '';
            const fd = new FormData();
            fd.append('period', 'this_year');
            fetch('/api/invoices-sale/sync-from-fakturownia.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        result.innerHTML = '<span style="color:#16a34a;">' + data.message + '</span>';
                    } else {
                        result.innerHTML = '<span style="color:#dc2626;">' + (data.error || 'Nieznany błąd') + '</span>';
                    }
                })
                .catch(() => { result.innerHTML = '<span style="color:#dc2626;">Błąd połączenia</span>'; })
                .finally(() => { btn.disabled = false; btn.textContent = 'Synchronizuj teraz'; });
        }
        </script>
    </div>
</body>
</html>
