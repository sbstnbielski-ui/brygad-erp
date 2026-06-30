<?php
/**
 * KSeF UPO — pobierz/wyświetl dane UPO z Fakturowni
 * GET ?fakturownia_id=123
 *
 * Zwraca HTML ze statusem KSeF, numerem, linkami do weryfikacji i UPO.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaService.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$fakturowniaId = (int)($_GET['fakturownia_id'] ?? 0);
if ($fakturowniaId <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><body><h2>Brak parametru fakturownia_id</h2></body></html>';
    exit;
}

$service = new FakturowniaService($pdo);
$result = $service->getKsefStatus($fakturowniaId);

$data = $result['data'] ?? [];
$govStatus = $data['gov_status'] ?? 'brak';
$govId = $data['gov_id'] ?? '';
$govSendDate = $data['gov_send_date'] ?? '';
$govVerificationLink = $data['gov_verification_link'] ?? '';
$govLink = $data['gov_link'] ?? '';
$govErrors = $data['gov_error_messages'] ?? [];

$statusLabels = [
    'ok' => ['Wysłana do KSeF', '#d1fae5', '#065f46'],
    'demo_ok' => ['Wysłana do KSeF (DEMO)', '#dbeafe', '#1e40af'],
    'processing' => ['Wysyłanie...', '#fef3c7', '#92400e'],
    'demo_processing' => ['Wysyłanie (DEMO)...', '#fef3c7', '#92400e'],
    'send_error' => ['Błąd wysyłki', '#fee2e2', '#991b1b'],
    'server_error' => ['Błąd serwera KSeF', '#fee2e2', '#991b1b'],
];
$statusInfo = $statusLabels[$govStatus] ?? [$govStatus, '#f3f4f6', '#6b7280'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KSeF UPO — Faktura #<?php echo $fakturowniaId; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #1f2937; padding: 40px; }
        .card { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 32px; border: 1px solid #e5e7eb; }
        h1 { font-size: 20px; font-weight: 700; color: #1e3a8a; margin-bottom: 20px; }
        .status-badge { display: inline-block; padding: 6px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .row:last-child { border-bottom: none; }
        .row-label { color: #6b7280; font-weight: 500; }
        .row-value { font-weight: 600; color: #1f2937; text-align: right; max-width: 60%; word-break: break-all; }
        .link { color: #2563eb; text-decoration: none; font-weight: 600; }
        .link:hover { text-decoration: underline; }
        .actions { margin-top: 20px; display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; transition: all 0.2s; }
        .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .btn-outline:hover { background: #f9fafb; }
        .btn-blue { background: #2563eb; color: #fff; }
        .btn-blue:hover { background: #1d4ed8; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-top: 16px; font-size: 13px; color: #991b1b; }
    </style>
</head>
<body>
    <div class="card">
        <h1>KSeF UPO — Urzędowe Poświadczenie Odbioru</h1>

        <span class="status-badge" style="background:<?php echo $statusInfo[1]; ?>;color:<?php echo $statusInfo[2]; ?>;">
            <?php echo $statusInfo[0]; ?>
        </span>

        <div class="row">
            <span class="row-label">ID Fakturownia</span>
            <span class="row-value"><?php echo $fakturowniaId; ?></span>
        </div>

        <?php if ($govId): ?>
        <div class="row">
            <span class="row-label">Numer KSeF</span>
            <span class="row-value"><?php echo e($govId); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($govSendDate): ?>
        <div class="row">
            <span class="row-label">Data wysłania</span>
            <span class="row-value"><?php echo e($govSendDate); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($govVerificationLink): ?>
        <div class="row">
            <span class="row-label">Link weryfikacyjny</span>
            <span class="row-value"><a href="<?php echo e($govVerificationLink); ?>" target="_blank" class="link">Weryfikuj na KSeF</a></span>
        </div>
        <?php endif; ?>

        <?php if ($govLink): ?>
        <div class="row">
            <span class="row-label">Link UPO</span>
            <span class="row-value"><a href="<?php echo e($govLink); ?>" target="_blank" class="link">Pobierz UPO</a></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($govErrors) && is_array($govErrors)): ?>
        <div class="errors">
            <strong>Błędy KSeF:</strong>
            <ul style="margin: 6px 0 0 18px;">
                <?php foreach ($govErrors as $err): ?>
                    <li><?php echo e(is_string($err) ? $err : json_encode($err)); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="actions">
            <?php if ($govVerificationLink): ?>
                <a href="<?php echo e($govVerificationLink); ?>" target="_blank" class="btn btn-blue">Weryfikuj w KSeF</a>
            <?php endif; ?>
            <a href="/api/invoices-sale/download-pdf.php?fakturownia_id=<?php echo $fakturowniaId; ?>" target="_blank" class="btn btn-outline">Pobierz PDF faktury</a>
            <button onclick="window.close()" class="btn btn-outline">Zamknij</button>
        </div>
    </div>
</body>
</html>
