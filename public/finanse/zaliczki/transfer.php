<?php
/**
 * BRYGAD ERP - Transfer środków między portfelami pracowników (A -> B)
 *
 * Mechanizm:
 * - źródło: dodatni wpis CASH_RETURN na wybranej zaliczce firmowej pracownika A
 * - cel: nowa zaliczka firmowa dla pracownika B (ADVANCE)
 *
 * Dzięki temu nie ruszamy logiki rozliczeń i stanów otwartych zaliczek.
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors = [];
$isAdminUser = isAdmin();
$currentWorkerId = (int)($_SESSION['worker_id'] ?? 0);

if (!$isAdminUser && $currentWorkerId <= 0) {
    die('Brak przypisanego konta pracownika.');
}

$filterFromWorkerId = (int)($_GET['from_worker_id'] ?? ($_POST['from_worker_id'] ?? 0));
$preselectedAdvanceId = (int)($_GET['source_advance_id'] ?? ($_POST['source_advance_id'] ?? 0));
if ($filterFromWorkerId <= 0 && $preselectedAdvanceId > 0) {
    $filterFromWorkerId = walletResolveCompanyAdvanceWorkerId($pdo, $preselectedAdvanceId);
}
if (!$isAdminUser) {
    $filterFromWorkerId = $currentWorkerId;
}

// Dla admina docelowym formularzem jest create.php (jeden formularz 4 operacji).
if ($isAdminUser && !isset($_GET['legacy'])) {
    $redirectParams = [
        'operation' => 'TRANSFER_COMPANY_TO_COMPANY',
    ];
    if ($filterFromWorkerId > 0) {
        $redirectParams['from_worker_id'] = $filterFromWorkerId;
    }

    header('Location: ' . url('finanse.zaliczki.create', $redirectParams));
    exit;
}

try {
    if ($isAdminUser) {
        $stmt = $pdo->query("
            SELECT id, first_name, last_name
            FROM workers
            WHERE is_active = 1
            ORDER BY last_name, first_name
        ");
        $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Dla pracownika pokazujemy innych aktywnych pracowników jako cele transferu
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name
            FROM workers
            WHERE is_active = 1
              AND id <> ?
            ORDER BY last_name, first_name
        ");
        $stmt->execute([$currentWorkerId]);
        $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Transfer wallet - workers list error: " . $e->getMessage());
    $workers = [];
    $errors[] = 'Nie udało się pobrać listy pracowników.';
}

try {
    $workerFilter = $filterFromWorkerId > 0 ? [$filterFromWorkerId] : [];
    $sourceWorkers = walletGetCompanySourceWorkers($pdo, $workerFilter);
} catch (PDOException $e) {
    error_log("Transfer wallet - source workers error: " . $e->getMessage());
    $sourceWorkers = [];
    $errors[] = 'Nie udało się pobrać portfeli źródłowych.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromWorkerId = (int)($_POST['from_worker_id'] ?? 0);
    $toWorkerId = (int)($_POST['to_worker_id'] ?? 0);
    $amount = (float)str_replace(',', '.', str_replace(' ', '', trim($_POST['amount'] ?? '0')));
    $transferDate = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $note = trim($_POST['note'] ?? '');

    if ($fromWorkerId <= 0) {
        $errors[] = 'Wybierz pracownika źródłowego.';
    }
    if ($toWorkerId <= 0) {
        $errors[] = 'Wybierz pracownika docelowego.';
    }
    if ($amount <= 0) {
        $errors[] = 'Kwota transferu musi być większa od 0.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
        $errors[] = 'Nieprawidłowa data transferu.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if (!$isAdminUser && $fromWorkerId !== $currentWorkerId) {
                throw new Exception('Możesz transferować środki tylko ze swojego portfela.');
            }
            if ($fromWorkerId === $toWorkerId) {
                throw new Exception('Pracownik źródłowy i docelowy muszą być różni.');
            }

            $stmt = $pdo->prepare("
                SELECT id, first_name, last_name, is_active
                FROM workers
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$toWorkerId]);
            $targetWorker = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetWorker) {
                throw new Exception('Nie znaleziono pracownika docelowego.');
            }
            if ((int)$targetWorker['is_active'] !== 1) {
                throw new Exception('Pracownik docelowy jest nieaktywny.');
            }

            $createdBy = $_SESSION['user_id'] ?? null;
            walletCreateTransfer(
                $pdo,
                $fromWorkerId,
                $toWorkerId,
                $amount,
                $transferDate,
                $note,
                $createdBy,
                'COMPANY'
            );

            $pdo->commit();

            $redirectUrl = $isAdminUser
                ? url('finanse.zaliczki', ['success' => 'transfer_created'])
                : (url('hr.workers.my-advances') . '?success=transfer_created#wallet-overview');
            header("Location: " . $redirectUrl);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Transfer wallet save error: " . $e->getMessage());
            $errors[] = 'Błąd zapisu transferu: ' . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$cancelUrl = $isAdminUser ? url('finanse.zaliczki') : url('hr.workers.my-advances') . '#wallet-overview';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Transfer środków A→B</title>
    <style>

        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px;
        }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }

        /* Override header */
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        .alert ul {
            margin: 8px 0 0 18px;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 24px;
        }
        .hint {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 18px;
            line-height: 1.5;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .group {
            margin-bottom: 16px;
        }
        .label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .required {
            color: #dc2626;
        }
        .input, .select, .textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #fff;
        }
        .input:focus, .select:focus, .textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .textarea {
            min-height: 90px;
            resize: vertical;
        }
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .btn {
            display: inline-block;
            padding: 10px 14px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            color: #fff;
        }
        .btn-primary:hover {
            filter: brightness(1.05);
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .help {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        @media (max-width: 800px) {
            .container { padding: 18px; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
                <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.zaliczki'); ?>">Zaliczki</a> /
                    Transfer Portfela
                </div>
                <h1>Transfer Portfela</h1>
                <p>Przekaż środki między portfelami pracowników.</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Popraw formularz:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if ($isAdminUser): ?>
                <div class="toolbar">
                    <form method="GET" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <label for="from_worker_id_filter" class="label" style="margin:0;">Filtruj portfele źródłowe po pracowniku:</label>
                        <select id="from_worker_id_filter" name="from_worker_id" class="select" style="min-width:260px;" onchange="this.form.submit()">
                            <option value="0">Wszyscy pracownicy</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo (int)$worker['id']; ?>" <?php echo $filterFromWorkerId === (int)$worker['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-secondary">Filtruj</button></noscript>
                        <?php if ($filterFromWorkerId > 0): ?>
                            <a href="<?php echo url('finanse.zaliczki.transfer'); ?>" class="btn btn-secondary">Wyczyść</a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>

            <p class="hint">
                Operacja zapisze dwa ruchy: rozchód na portfelu źródłowym i zasilenie portfela pracownika docelowego.
                Transfer rozliczy się automatycznie z najstarszych otwartych pozycji portfela źródłowego.
            </p>

            <form method="POST">
                <div class="grid">
                    <div class="group">
                        <label class="label" for="from_worker_id">Pracownik źródłowy (A) <span class="required">*</span></label>
                        <?php if ($isAdminUser): ?>
                            <select class="select" id="from_worker_id" name="from_worker_id" required>
                                <option value="">Wybierz portfel źródłowy</option>
                                <?php foreach ($sourceWorkers as $sourceWorker): ?>
                                    <option value="<?php echo (int)$sourceWorker['worker_id']; ?>" <?php echo $filterFromWorkerId === (int)$sourceWorker['worker_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($sourceWorker['worker_name']); ?> | saldo: <?php echo formatMoney((float)$sourceWorker['wallet_balance']); ?> | otwarte pozycje: <?php echo (int)$sourceWorker['open_count']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" name="from_worker_id" value="<?php echo (int)$filterFromWorkerId; ?>">
                            <div class="input" style="display:flex; align-items:center; background:#f8fafc;">
                                Moje saldo portfela
                            </div>
                        <?php endif; ?>
                        <div class="help">
                            System sam rozpisze kwotę FIFO po ukrytych pozycjach portfela pracownika źródłowego.
                        </div>
                    </div>

                    <div class="group">
                        <label class="label" for="to_worker_id">Pracownik docelowy (B) <span class="required">*</span></label>
                        <select class="select" id="to_worker_id" name="to_worker_id" required>
                            <option value="">Wybierz pracownika docelowego</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo (int)$worker['id']; ?>" <?php echo ((int)($_POST['to_worker_id'] ?? 0) === (int)$worker['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="group">
                        <label class="label" for="amount">Kwota transferu (PLN) <span class="required">*</span></label>
                        <input class="input" id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?php echo e($_POST['amount'] ?? ''); ?>">
                    </div>

                    <div class="group">
                        <label class="label" for="transfer_date">Data transferu <span class="required">*</span></label>
                        <input class="input" id="transfer_date" name="transfer_date" type="date" required value="<?php echo e($_POST['transfer_date'] ?? date('Y-m-d')); ?>">
                    </div>
                </div>

                <div class="group">
                    <label class="label" for="note">Opis (opcjonalnie)</label>
                    <textarea class="textarea" id="note" name="note" placeholder="Np. zakup materiałów na pilną realizację"><?php echo e($_POST['note'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Zapisz transfer</button>
                    <a href="<?php echo e($cancelUrl); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
