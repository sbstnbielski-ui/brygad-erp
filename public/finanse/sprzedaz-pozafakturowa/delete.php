<?php
/**
 * BRYGAD ERP - Usunięcie wpisu pozafakturowego (PZF)
 *
 * Guardrail: NIE dotyka invoices_sale, Fakturowni, KSeF ani project_revenues.
 * Alokacje usuwane kaskadowo przez FK ON DELETE CASCADE.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$id  = (int)($_GET['id'] ?? 0);

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

if ($id <= 0) {
    header("Location: " . pzfCenterUrl($returnSource, ['error' => 'pzf_not_found']));
    exit;
}

$stmt = $pdo->prepare("SELECT id, entry_number FROM sales_noninvoice_entries WHERE id = ?");
$stmt->execute([$id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    header("Location: " . pzfCenterUrl($returnSource, ['error' => 'pzf_not_found']));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Nieprawidłowy token CSRF.';
    } else {
        try {
            $del = $pdo->prepare("DELETE FROM sales_noninvoice_entries WHERE id = ?");
            $del->execute([$id]);
            logEvent("Usunięto wpis PZF: {$entry['entry_number']}, ID: {$id}", 'INFO');
            header("Location: " . pzfCenterUrl($returnSource, ['deleted_pzf' => 1]));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd usuwania PZF ID {$id}: " . $e->getMessage(), 'ERROR');
            $error = 'Błąd podczas usuwania wpisu.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Usuń wpis PZF</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .dialog { background: #fff; border-radius: 14px; padding: 32px; max-width: 460px; width: 100%; box-shadow: 0 8px 40px rgba(0,0,0,0.12); border: 1px solid #e5e7eb; }
        .dialog-icon { width: 52px; height: 52px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 24px; }
        .dialog h2 { text-align: center; font-size: 20px; font-weight: 700; margin-bottom: 8px; }
        .dialog p { text-align: center; color: #6b7280; font-size: 14px; margin-bottom: 20px; }
        .entry-box { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; text-align: center; }
        .entry-box strong { font-size: 16px; font-weight: 700; color: #1e3a8a; }
        .entry-box small { display: block; color: #6b7280; font-size: 12px; margin-top: 4px; }
        .btn-row { display: flex; gap: 10px; justify-content: center; }
        .btn { padding: 10px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; }
        .btn-cancel { background: #f3f4f6; color: #374151; }
        .btn-cancel:hover { background: #e5e7eb; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 10px 14px; border-radius: 8px; text-align: center; font-size: 13px; margin-bottom: 16px; border-left: 4px solid #dc2626; }
    </style>
</head>
<body>
    <div class="dialog">
        <div class="dialog-icon">⚠️</div>
        <h2>Usunąć wpis?</h2>
        <p>Ta operacja jest <strong>nieodwracalna</strong>. Wpis i wszystkie jego alokacje do projektów zostaną usunięte.</p>

        <?php if ($error): ?>
            <div class="alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="entry-box">
            <strong><?php echo e($entry['entry_number']); ?></strong>
            <small>Wpis pozafakturowy (PZF)</small>
        </div>

        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <input type="hidden" name="return_source" value="<?php echo e($returnSource); ?>">
            <div class="btn-row">
                <a href="<?php echo url('finanse.sprzedaz-pozafakturowa.edit', ['id' => $id, 'source' => $returnSource]); ?>" class="btn btn-cancel">← Anuluj</a>
                <button type="submit" class="btn btn-danger">Usuń wpis</button>
            </div>
        </form>
    </div>
</body>
</html>
