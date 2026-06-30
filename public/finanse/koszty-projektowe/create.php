<?php
/**
 * BRYGAD ERP - Koszt projektowy bez pracownika
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__) . '/_project-select.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;
$returnUrl = url('finanse.wydatki');

$projects = [];
try {
    $projects = spxFetchSelectableProjects($pdo);
} catch (Throwable $e) {}

$selectedProjectId = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
$costNodes = [];
if ($selectedProjectId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, parent_id, name
            FROM project_cost_nodes
            WHERE project_id = ? AND is_active = 1
            ORDER BY parent_id IS NULL DESC, sort_order, name
        ");
        $stmt->execute([$selectedProjectId]);
        $costNodes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token sesji.';
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $costNodeId = !empty($_POST['cost_node_id']) ? (int)$_POST['cost_node_id'] : null;
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $issueDate = trim((string)($_POST['issue_date'] ?? date('Y-m-d')));
    $paymentSource = trim((string)($_POST['payment_source'] ?? 'gotowka'));
    $docNumber = trim((string)($_POST['doc_number'] ?? ''));
    $category = trim((string)($_POST['category'] ?? 'project_cash_expense'));
    $subcategory = trim((string)($_POST['subcategory'] ?? ''));

    if ($projectId <= 0) $errors[] = 'Wybierz projekt.';
    if ($amount <= 0) $errors[] = 'Kwota musi być większa od 0.';
    if ($title === '') $errors[] = 'Podaj tytuł kosztu.';
    if ($issueDate === '') $errors[] = 'Data jest wymagana.';

    if ($costNodeId && $projectId > 0) {
        $check = $pdo->prepare("SELECT id FROM project_cost_nodes WHERE id = ? AND project_id = ? AND is_active = 1 LIMIT 1");
        $check->execute([$costNodeId, $projectId]);
        if (!$check->fetchColumn()) {
            $errors[] = 'Wybrany etap nie należy do projektu.';
        }
    }

    $filePath = null;
    if (empty($errors) && !empty($_FILES['attachment']['name'])) {
        $file = $_FILES['attachment'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        if (!in_array((string)$file['type'], $allowedTypes, true)) {
            $errors[] = 'Załącznik musi być w formacie JPG, PNG lub PDF.';
        } elseif ((int)$file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Załącznik jest za duży (max 5MB).';
        } else {
            $basePath = defined('RECEIPTS_PATH') ? RECEIPTS_PATH : dirname(__DIR__, 2) . '/uploads/receipts';
            if (!is_dir($basePath)) {
                @mkdir($basePath, 0755, true);
            }
            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $fileName = 'project_cost_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = rtrim($basePath, '/') . '/' . $fileName;
            if (move_uploaded_file($file['tmp_name'], $target)) {
                $filePath = 'uploads/receipts/' . $fileName;
            } else {
                $errors[] = 'Nie udało się zapisać załącznika.';
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO finance_items
                    (item_type, category, subcategory, project_id, etap_id, company_name, title, description, doc_number,
                     issue_date, payment_date, amount_net, amount_gross, currency, file_path, status, created_at, created_by)
                VALUES
                    ('RECEIPT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PLN', ?, 'approved', NOW(), ?)
            ");
            $stmt->execute([
                mb_substr($category ?: 'project_cash_expense', 0, 50),
                $subcategory !== '' ? mb_substr($subcategory, 0, 120) : null,
                $projectId,
                $costNodeId ?: null,
                mb_substr($paymentSource !== '' ? $paymentSource : 'gotowka', 0, 255),
                mb_substr($title, 0, 255),
                $description !== '' ? $description : null,
                $docNumber !== '' ? mb_substr($docNumber, 0, 100) : null,
                $issueDate,
                $issueDate,
                $amount,
                $amount,
                $filePath,
                $_SESSION['user_id'] ?? null,
            ]);
            $itemId = (int)$pdo->lastInsertId();
            logEvent("Dodano koszt projektowy bez pracownika finance_items #{$itemId}, projekt #{$projectId}, kwota {$amount} PLN", 'INFO');
            header('Location: ' . url('projekty.view', ['id' => $projectId, 'success' => 'project_cost_added']));
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Błąd zapisu kosztu projektowego.';
            logEvent('Błąd zapisu kosztu projektowego: ' . $e->getMessage(), 'ERROR');
        }
    }
}

$userName = $_SESSION['worker_name'] ?? ($_SESSION['login'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj koszt projektowy</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f5f7fa; color:#1f2937; margin:0; }
        .container { max-width: 960px; margin:0 auto; padding:28px; }
        .hero { background:linear-gradient(135deg,#1e3a8a,#0f172a); color:#fff; border-radius:12px; padding:22px; margin-bottom:22px; display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; }
        .hero h1 { margin:4px 0; font-size:26px; }
        .hero a { color:#dbeafe; text-decoration:none; font-size:13px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:28px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:18px; }
        label { display:block; font-weight:700; margin-bottom:7px; }
        input, select, textarea { width:100%; padding:11px 13px; border:1px solid #d1d5db; border-radius:6px; font:inherit; box-sizing:border-box; }
        textarea { min-height:110px; resize:vertical; }
        .group { margin-bottom:18px; }
        .help { color:#6b7280; font-size:12px; margin-top:5px; }
        .alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        .alert-error { background:#fee2e2; color:#991b1b; }
        .actions { display:flex; gap:10px; margin-top:24px; flex-wrap:wrap; }
        .btn { padding:11px 18px; border-radius:6px; border:0; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; }
        .btn-primary { background:#1e3a8a; color:white; }
        .btn-secondary { background:#e5e7eb; color:#374151; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
<div class="container">
    <div class="hero">
        <div>
            <a href="<?php echo url('finanse.wydatki'); ?>">Finanse / Wydatki</a>
            <h1>Dodaj koszt projektowy</h1>
            <p style="margin:0;color:#cbd5e1;">Koszt firmowy bez pracownika i bez obciążania portfela.</p>
        </div>
        <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary" style="height:max-content;">Wróć</a>
    </div>

    <div class="card">
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <strong>Błąd:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <div class="grid">
                <div class="group">
                    <label>Projekt *</label>
                    <select name="project_id" id="project_select" required onchange="if (this.value) window.location.href='<?php echo url('finanse.koszty-projektowe.create'); ?>?project_id=' + encodeURIComponent(this.value);">
                        <?php echo spxRenderProjectOptions($projects, $selectedProjectId, 'Wybierz projekt'); ?>
                    </select>
                    <div class="help">Zmiana projektu odświeża listę etapów.</div>
                </div>
                <div class="group">
                    <label>Etap projektu</label>
                    <select name="cost_node_id" <?php echo $selectedProjectId > 0 ? '' : 'disabled'; ?>>
                        <option value="">Koszt ogólny projektu</option>
                        <?php foreach ($costNodes as $node): ?>
                            <option value="<?php echo (int)$node['id']; ?>" <?php echo (string)($_POST['cost_node_id'] ?? '') === (string)$node['id'] ? 'selected' : ''; ?>>
                                <?php echo e(($node['parent_id'] ? '- ' : '') . $node['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid">
                <div class="group">
                    <label>Kwota brutto PLN *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required value="<?php echo e($_POST['amount'] ?? ''); ?>">
                </div>
                <div class="group">
                    <label>Data *</label>
                    <input type="date" name="issue_date" required value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>">
                </div>
            </div>

            <div class="group">
                <label>Tytuł kosztu *</label>
                <input type="text" name="title" maxlength="255" required value="<?php echo e($_POST['title'] ?? ''); ?>" placeholder="np. drobny zakup gotówkowy do projektu">
            </div>
            <div class="group">
                <label>Opis</label>
                <textarea name="description" placeholder="Szczegóły kosztu"><?php echo e($_POST['description'] ?? ''); ?></textarea>
            </div>

            <div class="grid">
                <div class="group">
                    <label>Źródło płatności</label>
                    <input type="text" name="payment_source" maxlength="255" value="<?php echo e($_POST['payment_source'] ?? 'gotowka / wlasciciel'); ?>">
                </div>
                <div class="group">
                    <label>Numer dokumentu</label>
                    <input type="text" name="doc_number" maxlength="100" value="<?php echo e($_POST['doc_number'] ?? ''); ?>">
                </div>
            </div>

            <div class="grid">
                <div class="group">
                    <label>Kategoria</label>
                    <input type="text" name="category" maxlength="50" value="<?php echo e($_POST['category'] ?? 'project_cash_expense'); ?>">
                </div>
                <div class="group">
                    <label>Podkategoria</label>
                    <input type="text" name="subcategory" maxlength="120" value="<?php echo e($_POST['subcategory'] ?? ''); ?>">
                </div>
            </div>

            <div class="group">
                <label>Załącznik</label>
                <input type="file" name="attachment" accept="image/jpeg,image/jpg,image/png,application/pdf">
                <div class="help">Opcjonalnie: JPG, PNG, PDF, max 5MB.</div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Zapisz koszt projektowy</button>
                <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
