<?php
/**
 * BRYGAD ERP - Moje Zaliczki (widok pracownika)
 * 
 * Pracownik może:
 * - Przeglądać swoje zaliczki
 * - Dodawać pliki/paragony do zaliczek
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$workerId = $_SESSION['worker_id'] ?? null;

if (!$workerId) {
    die("Brak przypisanego ID pracownika.");
}

$errors = [];
$success = false;

// Obsługa uploadu pliku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $advanceId = (int)($_POST['advance_id'] ?? 0);
    
    if ($advanceId <= 0) {
        $errors[] = 'Nieprawidłowy ID zaliczki.';
    }
    
    // Sprawdź czy zaliczka należy do tego pracownika
    try {
        $stmt = $pdo->prepare("SELECT id FROM worker_advances WHERE id = ? AND worker_id = ?");
        $stmt->execute([$advanceId, $workerId]);
        if (!$stmt->fetch()) {
            $errors[] = 'Brak dostępu do tej zaliczki.';
        }
    } catch (PDOException $e) {
        $errors[] = 'Błąd weryfikacji zaliczki.';
    }
    
    if (empty($errors)) {
        $file = $_FILES['file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $uploadDir = ROOT_PATH . '/public/uploads/zaliczki/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = uniqid() . '_' . basename($file['name']);
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO worker_advance_files 
                        (advance_id, file_path, original_name, mime_type, file_size, uploaded_by_user_id, uploaded_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $advanceId,
                        '/uploads/zaliczki/' . $fileName,
                        $file['name'],
                        $file['type'],
                        $file['size'],
                        $_SESSION['user_id'] ?? null
                    ]);
                    
                    $success = true;
                } catch (PDOException $e) {
                    error_log("Error saving file: " . $e->getMessage());
                    $errors[] = 'Błąd podczas zapisywania informacji o pliku.';
                }
            } else {
                $errors[] = 'Błąd podczas przesyłania pliku.';
            }
        } else {
            $errors[] = 'Błąd podczas przesyłania pliku: ' . $file['error'];
        }
    }
}

// Pobierz zaliczki pracownika
try {
    $stmt = $pdo->prepare("
        SELECT 
            vad.*,
            (SELECT COUNT(*) FROM worker_advance_files WHERE advance_id = vad.advance_id) as files_count
        FROM v_worker_advances_details vad
        WHERE vad.worker_id = ?
        ORDER BY vad.issue_date DESC, vad.advance_id DESC
    ");
    $stmt->execute([$workerId]);
    $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching worker advances: " . $e->getMessage());
    die("Błąd pobierania danych.");
}

$walletSummary = [
    'available' => 0.0,
    'open_count' => 0,
];
$walletLastMovement = null;
$walletWorkerNames = [];

foreach ($advances as $adv) {
    if (($adv['type'] ?? '') === 'COMPANY' && ($adv['status'] ?? '') === 'open') {
        $walletSummary['available'] += (float)($adv['amount_remaining'] ?? 0);
        $walletSummary['open_count']++;
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT
            wl.entry_type,
            wl.amount,
            wl.entry_date,
            wl.description,
            wl.advance_id
        FROM worker_ledger wl
        INNER JOIN worker_advances wa ON wa.id = wl.advance_id
        WHERE wl.worker_id = ?
          AND wa.type = 'COMPANY'
        ORDER BY wl.entry_date DESC, wl.id DESC
        LIMIT 1
    ");
    $stmt->execute([$workerId]);
    $walletLastMovement = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log("Error fetching wallet movement: " . $e->getMessage());
}

$walletNameIds = walletExtractWorkerIdsFromDescriptions(array_map(
    static fn(array $advance): string => (string)($advance['description'] ?? ''),
    $advances
));
if (!empty($walletLastMovement['description'])) {
    $walletNameIds = array_values(array_unique(array_merge(
        $walletNameIds,
        walletExtractWorkerIdsFromDescriptions([(string)$walletLastMovement['description']])
    )));
}
try {
    $walletWorkerNames = walletGetWorkerDisplayNames($pdo, $walletNameIds);
} catch (PDOException $e) {
    $walletWorkerNames = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Moje zaliczki</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
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
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }
        .page-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;
        }
        .page-header h1 { font-size: 26px; font-weight: 700; color: #fff; margin-bottom: 4px; letter-spacing: -0.3px; }
        .page-header p { color: #cbd5e1; font-size: 14px; margin-top: 2px; }
        .page-header .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .page-header .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        
        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        
        /* Advances list */
        .advances-list {
            display: grid;
            gap: 20px;
        }
        
        .advance-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: box-shadow 0.2s;
        }
        .advance-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .advance-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }
        .advance-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .advance-id {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge.type-company {
            background: #dbeafe;
            color: #1e40af;
        }
        .badge.type-private {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-open {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-closed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .advance-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 600;
        }
        .info-value.large {
            font-size: 20px;
            color: #667eea;
        }
        .info-value.remaining {
            color: #dc2626;
        }
        
        .advance-description {
            padding: 12px 16px;
            background: #f9fafb;
            border-radius: 8px;
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 15px;
        }
        
        .advance-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding-top: 15px;
            border-top: 1px solid #f3f4f6;
        }
        
        .upload-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: -9999px;
        }
        .file-input-label {
            padding: 8px 16px;
            background: #e5e7eb;
            color: #374151;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-input-label:hover {
            background: #d1d5db;
        }
        .btn-upload {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-upload:hover {
            background: #5568d3;
        }
        .files-count {
            font-size: 12px;
            color: #6b7280;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }
        .no-data h3 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        .wallet-overview {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .wallet-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .wallet-header h2 {
            font-size: 20px;
            color: #1f2937;
            margin: 0;
        }
        .wallet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .wallet-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 14px;
            background: #fcfdff;
        }
        .wallet-card .label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 6px;
        }
        .wallet-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #1e40af;
        }
        .wallet-card .sub {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        .wallet-cta {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #667eea 0%, #4f46e5 100%);
            text-decoration: none;
        }
        .wallet-cta:hover {
            filter: brightness(1.05);
        }
        .wallet-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .wallet-cta-secondary {
            background: #1f2937;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .advance-header {
                flex-direction: column;
                gap: 10px;
            }
            .advance-info {
                grid-template-columns: 1fr;
            }
            .advance-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .upload-form {
                flex-direction: column;
            }
            .wallet-header {
                align-items: stretch;
            }
            .wallet-actions a {
                text-align: center;
            }
            .wallet-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel główny</a> › Moje zaliczki
                </div>
                <h1>Moje zaliczki</h1>
                <p>Przeglądaj swoje zaliczki i dodawaj dokumenty/paragony</p>
            </div>
        </div>
        
        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Wystąpiły błędy:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Success -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                Plik został dodany pomyślnie.
            </div>
        <?php endif; ?>

        <?php
            $walletLastDelta = null;
            $walletLastLabel = null;
            if ($walletLastMovement) {
                $walletLastDelta = -1 * (float)$walletLastMovement['amount'];
                $walletLastLabel = walletResolveEntryLabel($walletLastMovement);
            }
        ?>
        <section class="wallet-overview" id="wallet-overview">
            <div class="wallet-header">
                <h2>Mój portfel firmowy</h2>
                <div class="wallet-actions">
                    <a href="<?php echo url('finanse.zaliczki.transfer', ['from_worker_id' => (int)$workerId]); ?>" class="wallet-cta wallet-cta-secondary">Przekaż środki</a>
                    <a href="#company-advances-section" class="wallet-cta">Dodaj paragon / plik</a>
                </div>
            </div>
            <div class="wallet-grid">
                <div class="wallet-card">
                    <div class="label">Dostępne środki</div>
                    <div class="value"><?php echo formatMoney($walletSummary['available']); ?></div>
                    <div class="sub">Pozostało do wydania</div>
                </div>
                <div class="wallet-card">
                    <div class="label">Otwarte zaliczki firmowe</div>
                    <div class="value"><?php echo (int)$walletSummary['open_count']; ?></div>
                    <div class="sub">Aktywne pozycje portfela</div>
                </div>
                <div class="wallet-card">
                    <div class="label">Ostatni ruch</div>
                    <?php if ($walletLastMovement): ?>
                        <div class="value" style="color: <?php echo $walletLastDelta >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                            <?php echo ($walletLastDelta >= 0 ? '+' : '') . formatMoney($walletLastDelta); ?>
                        </div>
                        <div class="sub"><?php echo formatDate($walletLastMovement['entry_date']); ?> • <?php echo e($walletLastLabel); ?></div>
                    <?php else: ?>
                        <div class="value">-</div>
                        <div class="sub">Brak ruchów portfela</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        
        <!-- Advances List -->
        <?php if (empty($advances)): ?>
            <div class="no-data">
                <h3>Brak zaliczek</h3>
                <p>Nie masz jeszcze żadnych zaliczek w systemie.</p>
            </div>
        <?php else: ?>
            <div class="advances-list" id="company-advances-section">
                <?php foreach ($advances as $adv): ?>
                    <div class="advance-card">
                        <div class="advance-header">
                            <div class="advance-title">
                                <span class="advance-id">#<?php echo $adv['advance_id']; ?></span>
                                <span class="badge type-<?php echo strtolower($adv['type']); ?>">
                                    <?php echo $adv['type'] === 'COMPANY' ? 'Firmowa' : 'Prywatna'; ?>
                                </span>
                                <span class="badge status-<?php echo $adv['status']; ?>">
                                    <?php echo $adv['status'] === 'open' ? 'Otwarta' : 'Zamknięta'; ?>
                                </span>
                            </div>
                            <div style="text-align: right; font-size: 12px; color: #6b7280;">
                                <?php echo formatDate($adv['issue_date']); ?>
                            </div>
                        </div>
                        
                        <?php if ($adv['description']): ?>
                            <div class="advance-description">
                                <?php echo nl2br(e(walletHumanizeDescription((string)$adv['description'], $walletWorkerNames))); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="advance-info">
                            <div class="info-item">
                                <div class="info-label">Kwota wydana</div>
                                <div class="info-value large"><?php echo formatMoney($adv['amount']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Rozliczono</div>
                                <div class="info-value"><?php echo formatMoney($adv['amount_settled']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Pozostało</div>
                                <div class="info-value <?php echo $adv['amount_remaining'] > 0 ? 'remaining' : ''; ?>">
                                    <?php echo formatMoney($adv['amount_remaining']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="advance-actions">
                            <div class="files-count">
                                📎 Plików: <?php echo $adv['files_count']; ?>
                            </div>
                            
                            <?php if ($adv['status'] === 'open' && $adv['amount_remaining'] > 0): ?>
                                <form method="POST" enctype="multipart/form-data" class="upload-form">
                                    <input type="hidden" name="advance_id" value="<?php echo $adv['advance_id']; ?>">
                                    
                                    <div class="file-input-wrapper">
                                        <input type="file" name="file" id="file-<?php echo $adv['advance_id']; ?>" required onchange="this.form.querySelector('.file-input-label').textContent = this.files[0] ? this.files[0].name : 'Wybierz plik';">
                                        <label for="file-<?php echo $adv['advance_id']; ?>" class="file-input-label">Wybierz plik</label>
                                    </div>
                                    
                                    <button type="submit" class="btn-upload">Dodaj plik</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size: 12px; color: #10b981; font-weight: 600;">✓ Rozliczona</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
