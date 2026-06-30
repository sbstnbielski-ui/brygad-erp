<?php
/**
 * BRYGAD ERP - Dodawanie Dokumentu Kosztowego
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token sesji (CSRF). Odśwież stronę i spróbuj ponownie.';
    }

    $vendor_id = trim($_POST['vendor_id'] ?? '');
    $source_name = trim($_POST['source_name'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $issue_date = trim($_POST['issue_date'] ?? '');
    $due_date = trim($_POST['due_date'] ?? '');
    $sale_date = trim($_POST['sale_date'] ?? '');
    $currency = trim($_POST['currency'] ?? 'PLN');
    $description = trim($_POST['description'] ?? '');
    
    // Walidacja pozycji faktury
    $document_items = [];
    if (!empty($_POST['document_items'])) {
        $document_items = json_decode($_POST['document_items'], true);
        if (empty($document_items)) {
            $errors[] = "Faktura musi zawierać przynajmniej jedną pozycję";
        }
    } else {
        $errors[] = "Faktura musi zawierać przynajmniej jedną pozycję";
    }
    
    // Walidacja: albo kontrahent albo źródło musi być podane
    if (empty($vendor_id) && empty($source_name)) {
        $errors[] = "Musisz wybrać kontrahenta lub podać źródło zakupu";
    }
    if (empty($number)) {
        $errors[] = "Numer dokumentu jest wymagany";
    }
    if (empty($issue_date)) {
        $errors[] = "Data wystawienia jest wymagana";
    }
    
    // Sprawdź unikalność numeru (tylko jeśli vendor_id jest podany)
    if (empty($errors) && !empty($vendor_id)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE vendor_id = ? AND number = ?");
        $stmt->execute([$vendor_id, $number]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Dokument z tym numerem już istnieje dla tego kontrahenta";
        }
    }
    
    // Obsługa uploadu pliku
    $file_path = null;
    if (!empty($_FILES['file']['name'])) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Dozwolone są tylko pliki PDF, JPG i PNG";
        } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            $errors[] = "Plik nie może być większy niż 10MB";
        } else {
            $upload_dir = dirname(__DIR__) . '/uploads/documents';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = 'doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
            $destination = $upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
                $file_path = 'uploads/documents/' . $file_name;
            } else {
                $errors[] = "Błąd podczas przesyłania pliku";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Oblicz sumy z pozycji
            $total_net = 0;
            $total_vat = 0;
            $total_gross = 0;
            
            foreach ($document_items as $item) {
                $total_net += $item['amount_net'];
                $total_vat += $item['amount_vat'];
                $total_gross += $item['amount_gross'];
            }
            
            // Utwórz dokument
            $stmt = $pdo->prepare("
                INSERT INTO documents (
                    type, status, vendor_id, source_name, created_by, number, 
                    issue_date, due_date, sale_date, currency,
                    amount_net, amount_vat, amount_gross, 
                    file_path, description
                ) VALUES (
                    'invoice_cost', 'draft', ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?
                )
            ");
            
            $stmt->execute([
                $vendor_id ?: null,
                $source_name ?: null,
                $_SESSION['user_id'],
                $number,
                $issue_date,
                $due_date ?: null,
                $sale_date ?: null,
                $currency,
                $total_net,
                $total_vat,
                $total_gross,
                $file_path,
                $description ?: null
            ]);
            
            $document_id = $pdo->lastInsertId();
            
            // Zapisz pozycje dokumentu
            $stmt_item = $pdo->prepare("
                INSERT INTO document_items 
                (document_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat, amount_gross, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $sort_order = 0;
            foreach ($document_items as $item) {
                $stmt_item->execute([
                    $document_id,
                    $item['name'],
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price'],
                    $item['vat_rate'],
                    $item['amount_net'],
                    $item['amount_vat'],
                    $item['amount_gross'],
                    $sort_order++
                ]);
            }
            
            $pdo->commit();
            
            logEvent("Utworzono dokument kosztowy: {$number} (ID: {$document_id}, Pozycji: " . count($document_items) . ")", 'INFO');
            
            header("Location: " . url('finanse.fakturownia-cost-inbox', [
                'source' => 'documents',
                'success' => 'document_created',
                'search' => $number,
            ]));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logEvent("Błąd tworzenia dokumentu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia dokumentu";
        }
    }
}

// Pobierz listę kontrahentów
$vendors = $pdo->query("
    SELECT id, name, nip 
    FROM investors 
    WHERE is_active = 1 
    ORDER BY name ASC
")->fetchAll();

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj Dokument Kosztowy</title>
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #16a34a;
            --danger: #dc2626;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 40px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 25px;
        }
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.1);
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2);
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .card {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            border: 1px solid #eef2f7;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }
        .alert ul { margin: 10px 0 0 20px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-main);
        }
        .form-group .required { color: var(--danger); }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            background: #fff;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--success);
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }
        .form-group .help-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: #fff;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
        }
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        .btn-secondary:hover { background: #e5e7eb; }
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
            font-size: 13px;
        }
        .info-box {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #1e3a8a;
        }
        .btn-ocr {
            background: linear-gradient(135deg, #64748b 0%, #334155 100%);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-ocr:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(51, 65, 85, 0.35);
        }
        .btn-ocr:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .ocr-status-loading {
            color: #475569;
            font-weight: 500;
        }
        .ocr-status-success {
            color: #10b981;
            font-weight: 500;
        }
        .ocr-status-error {
            color: #ef4444;
            font-weight: 500;
        }
        .ocr-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (max-width: 960px) {
            .form-row { grid-template-columns: 1fr; }
            .container { padding: 16px; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['source' => 'documents']); ?>">Centrum Faktur Kosztowych</a> /
                    Dodaj Dokument
                </div>
                <h1>Dodaj Dokument Kosztowy</h1>
                <p>Wersja robocza z pozycjami i OCR AI</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['source' => 'documents']); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <div class="card">
            <div class="info-box">
                Dokument zostanie utworzony w statusie "Wersja robocza". Przed zatwierdzeniem należy dodać alokacje kosztów do projektów.
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Błąd!</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label>Kontrahent (opcjonalnie)</label>
                    <select name="vendor_id">
                        <option value="">-- Brak (wpisz źródło poniżej) --</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?php echo $vendor['id']; ?>" 
                                    <?php echo ($_POST['vendor_id'] ?? '') == $vendor['id'] ? 'selected' : ''; ?>>
                                <?php echo e($vendor['name']); ?>
                                <?php if ($vendor['nip']): ?>
                                    (NIP: <?php echo e($vendor['nip']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help-text">
                        Wybierz kontrahenta z listy LUB wpisz źródło zakupu poniżej
                        <span style="margin-left: 15px;">
                            <a href="<?php echo url('investors.create'); ?>" target="_blank"
                               style="color: #1e3a8a; font-weight: 600;">+ Dodaj nowego kontrahenta</a>
                        </span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Źródło zakupu (jeśli brak kontrahenta)</label>
                    <input type="text" name="source_name" value="<?php echo e($_POST['source_name'] ?? ''); ?>" 
                           placeholder="np. Solidtech - jednorazowo, Castorama - paragon">
                    <div class="help-text">Pole wymagane jeśli nie wybrano kontrahenta. Przykład: "Castorama Kraków - paragon"</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Numer dokumentu <span class="required">*</span></label>
                        <input type="text" name="number" value="<?php echo e($_POST['number'] ?? ''); ?>" 
                               placeholder="np. FV/2026/01/001" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Waluta <span class="required">*</span></label>
                        <select name="currency" required>
                            <option value="PLN" <?php echo ($_POST['currency'] ?? 'PLN') === 'PLN' ? 'selected' : ''; ?>>PLN</option>
                            <option value="EUR" <?php echo ($_POST['currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                            <option value="USD" <?php echo ($_POST['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Data wystawienia <span class="required">*</span></label>
                        <input type="date" name="issue_date" value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Termin płatności</label>
                        <input type="date" name="due_date" value="<?php echo e($_POST['due_date'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Data sprzedaży</label>
                    <input type="date" name="sale_date" value="<?php echo e($_POST['sale_date'] ?? ''); ?>">
                </div>
                
                <!-- Pozycje faktury -->
                <div style="margin-top: 30px; margin-bottom: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 18px; color: #1a1a1a;">Pozycje faktury</h3>
                    
                    <div style="overflow-x: auto;">
                        <table id="items-table" style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
                            <thead>
                                <tr style="background: #f3f4f6; border-bottom: 2px solid #d1d5db;">
                                    <th style="padding: 10px; text-align: left; font-size: 13px;">Nazwa</th>
                                    <th style="padding: 10px; text-align: center; width: 80px; font-size: 13px;">Ilość</th>
                                    <th style="padding: 10px; text-align: center; width: 80px; font-size: 13px;">Jedn.</th>
                                    <th style="padding: 10px; text-align: right; width: 100px; font-size: 13px;">Cena netto</th>
                                    <th style="padding: 10px; text-align: center; width: 70px; font-size: 13px;">VAT %</th>
                                    <th style="padding: 10px; text-align: right; width: 100px; font-size: 13px;">Netto</th>
                                    <th style="padding: 10px; text-align: right; width: 100px; font-size: 13px;">VAT</th>
                                    <th style="padding: 10px; text-align: right; width: 100px; font-size: 13px;">Brutto</th>
                                    <th style="padding: 10px; text-align: center; width: 50px; font-size: 13px;"></th>
                                </tr>
                            </thead>
                            <tbody id="items-tbody">
                                <!-- Wiersze dodawane przez JavaScript -->
                            </tbody>
                            <tfoot>
                                <tr style="background: #f9fafb; border-top: 2px solid #d1d5db; font-weight: 600;">
                                    <td colspan="5" style="padding: 12px; text-align: right; font-size: 14px;">RAZEM:</td>
                                    <td style="padding: 12px; text-align: right; font-size: 14px;" id="total-net">0.00</td>
                                    <td style="padding: 12px; text-align: right; font-size: 14px;" id="total-vat">0.00</td>
                                    <td style="padding: 12px; text-align: right; font-size: 14px;" id="total-gross">0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <button type="button" onclick="addDocumentItem()" class="btn btn-secondary" style="margin-bottom: 20px;">
                        + Dodaj pozycję
                    </button>
                </div>
                
                <div class="form-group">
                    <label>Plik dokumentu</label>
                    <input type="file" name="file" id="file-input" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="help-text">Dozwolone formaty: PDF, JPG, PNG (max 10MB)</div>
                    
                    <!-- OCR Button -->
                    <div id="ocr-container" style="margin-top: 12px; display: none;">
                        <button type="button" id="ocr-scan-btn" class="btn-ocr">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;">
                                <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/>
                                <path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Skanuj fakturę AI (Gemini)
                        </button>
                        <div id="ocr-status" style="margin-top: 8px; font-size: 13px;"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Opis / Notatki</label>
                    <textarea name="description" rows="3" placeholder="Dodatkowe informacje..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Utwórz Dokument</button>
                    <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['source' => 'documents']); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
    // ===== POZYCJE FAKTURY =====
    let itemCounter = 0;
    
    function addDocumentItem(data = {}) {
        itemCounter++;
        const tbody = document.getElementById('items-tbody');
        const row = document.createElement('tr');
        row.setAttribute('data-item-id', itemCounter);
        row.style.borderBottom = '1px solid #e5e7eb';
        
        // Normalizuj dane (OCR może zwracać różne nazwy pól)
        const itemData = {
            name: data.name || data.item_name || '',
            quantity: data.quantity || 1,
            unit: data.unit || 'szt',
            unit_price: data.unit_price || data.price || 0,
            vat_rate: data.vat_rate || data.vat || '23'
        };
        
        row.innerHTML = `
            <td style="padding: 10px;">
                <input type="text" class="item-name" value="${itemData.name}" 
                       placeholder="Nazwa usługi/towaru" 
                       style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
            </td>
            <td style="padding: 10px;">
                <input type="number" class="item-quantity" value="${itemData.quantity}" 
                       step="0.01" min="0"
                       style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: center;">
            </td>
            <td style="padding: 10px;">
                <select class="item-unit" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    <option value="szt" ${itemData.unit === 'szt' ? 'selected' : ''}>szt</option>
                    <option value="usł" ${itemData.unit === 'usł' ? 'selected' : ''}>usł</option>
                    <option value="godz" ${itemData.unit === 'godz' ? 'selected' : ''}>godz</option>
                    <option value="m2" ${itemData.unit === 'm2' ? 'selected' : ''}>m²</option>
                    <option value="mb" ${itemData.unit === 'mb' ? 'selected' : ''}>mb</option>
                    <option value="kg" ${itemData.unit === 'kg' ? 'selected' : ''}>kg</option>
                    <option value="kpl" ${itemData.unit === 'kpl' ? 'selected' : ''}>kpl</option>
                </select>
            </td>
            <td style="padding: 10px;">
                <input type="number" class="item-price" value="${itemData.unit_price}" 
                       step="0.01" min="0"
                       style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: right;">
            </td>
            <td style="padding: 10px;">
                <select class="item-vat" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: center;">
                    <option value="23" ${itemData.vat_rate === '23' || !itemData.vat_rate ? 'selected' : ''}>23%</option>
                    <option value="8" ${itemData.vat_rate === '8' ? 'selected' : ''}>8%</option>
                    <option value="5" ${itemData.vat_rate === '5' ? 'selected' : ''}>5%</option>
                    <option value="0" ${itemData.vat_rate === '0' ? 'selected' : ''}>0%</option>
                    <option value="zw" ${itemData.vat_rate === 'zw' ? 'selected' : ''}>zw</option>
                </select>
            </td>
            <td style="padding: 10px; text-align: right; font-size: 13px;" class="item-net-display">0.00</td>
            <td style="padding: 10px; text-align: right; font-size: 13px;" class="item-vat-display">0.00</td>
            <td style="padding: 10px; text-align: right; font-size: 13px;" class="item-gross-display">0.00</td>
            <td style="padding: 10px; text-align: center;">
                <button type="button" onclick="removeDocumentItem(this)" 
                        style="background: #ef4444; color: white; border: none; border-radius: 4px; padding: 6px 10px; cursor: pointer; font-size: 12px;">✕</button>
            </td>
        `;
        
        tbody.appendChild(row);
        
        // Event listeners dla auto-obliczania
        const inputs = row.querySelectorAll('.item-quantity, .item-price, .item-vat');
        inputs.forEach(input => {
            input.addEventListener('input', () => calculateItemRow(row));
        });
        
        calculateItemRow(row);
    }
    
    function removeDocumentItem(button) {
        button.closest('tr').remove();
        updateTotals();
    }
    
    function calculateItemRow(row) {
        const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.item-price').value) || 0;
        const vatRate = row.querySelector('.item-vat').value;
        
        const amountNet = quantity * unitPrice;
        let amountVat = 0;
        
        if (vatRate !== 'zw') {
            const vatPercent = parseFloat(vatRate) / 100;
            amountVat = amountNet * vatPercent;
        }
        
        const amountGross = amountNet + amountVat;
        
        row.querySelector('.item-net-display').textContent = amountNet.toFixed(2);
        row.querySelector('.item-vat-display').textContent = amountVat.toFixed(2);
        row.querySelector('.item-gross-display').textContent = amountGross.toFixed(2);
        
        updateTotals();
    }
    
    function updateTotals() {
        let totalNet = 0;
        let totalVat = 0;
        let totalGross = 0;
        
        document.querySelectorAll('#items-tbody tr').forEach(row => {
            totalNet += parseFloat(row.querySelector('.item-net-display').textContent) || 0;
            totalVat += parseFloat(row.querySelector('.item-vat-display').textContent) || 0;
            totalGross += parseFloat(row.querySelector('.item-gross-display').textContent) || 0;
        });
        
        document.getElementById('total-net').textContent = totalNet.toFixed(2);
        document.getElementById('total-vat').textContent = totalVat.toFixed(2);
        document.getElementById('total-gross').textContent = totalGross.toFixed(2);
    }
    
    // Dodaj pierwszą pozycję domyślnie
    addDocumentItem();
    
    // ===== SUBMIT FORMULARZA - ZBIERANIE POZYCJI =====
    document.querySelector('form').addEventListener('submit', function(e) {
        const rows = document.querySelectorAll('#items-tbody tr');
        
        if (rows.length === 0) {
            e.preventDefault();
            alert('Dodaj przynajmniej jedną pozycję na fakturze!');
            return false;
        }
        
        // Zbierz pozycje jako JSON
        const items = [];
        rows.forEach(row => {
            const name = row.querySelector('.item-name').value.trim();
            if (!name) return; // Pomiń puste wiersze
            
            items.push({
                name: name,
                quantity: parseFloat(row.querySelector('.item-quantity').value) || 0,
                unit: row.querySelector('.item-unit').value,
                unit_price: parseFloat(row.querySelector('.item-price').value) || 0,
                vat_rate: row.querySelector('.item-vat').value,
                amount_net: parseFloat(row.querySelector('.item-net-display').textContent) || 0,
                amount_vat: parseFloat(row.querySelector('.item-vat-display').textContent) || 0,
                amount_gross: parseFloat(row.querySelector('.item-gross-display').textContent) || 0
            });
        });
        
        if (items.length === 0) {
            e.preventDefault();
            alert('Wypełnij przynajmniej jedną pozycję na fakturze!');
            return false;
        }
        
        // Dodaj jako hidden input
        const itemsInput = document.createElement('input');
        itemsInput.type = 'hidden';
        itemsInput.name = 'document_items';
        itemsInput.value = JSON.stringify(items);
        this.appendChild(itemsInput);
    });
    
    // ===== OCR FUNCTIONALITY =====
    (function() {
        const fileInput = document.getElementById('file-input');
        const ocrContainer = document.getElementById('ocr-container');
        const ocrBtn = document.getElementById('ocr-scan-btn');
        const ocrStatus = document.getElementById('ocr-status');
        
        // Pokaż przycisk OCR gdy plik zostanie wybrany
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                ocrContainer.style.display = 'block';
                ocrStatus.innerHTML = '';
            } else {
                ocrContainer.style.display = 'none';
            }
        });
        
        // Obsługa kliknięcia w przycisk OCR
        ocrBtn.addEventListener('click', async function() {
            const file = fileInput.files[0];
            if (!file) {
                alert('Najpierw wybierz plik do zeskanowania');
                return;
            }
            
            // Sprawdź typ pliku
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                alert('Obsługiwane formaty: PDF, JPG, PNG');
                return;
            }
            
            // Disable button
            ocrBtn.disabled = true;
            ocrBtn.innerHTML = '<span class="ocr-spinner"></span> Skanowanie AI...';
            ocrStatus.innerHTML = '<span class="ocr-status-loading">Gemini analizuje dokument... To może potrwać 5-10 sekund.</span>';
            
            try {
                // Przygotuj FormData
                const formData = new FormData();
                formData.append('file', file);
                
                // Wywołaj API
                const response = await fetch('/api/ocr/gemini-process.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.data) {
                    // Wypełnij pola formularza
                    fillFormFromOCR(result.data);
                    
                    // Status jest już ustawiony w fillFormFromOCR() z informacją o pozycjach
                } else {
                    throw new Error(result.error || 'Nieznany błąd');
                }
                
            } catch (error) {
                console.error('OCR Error:', error);
                ocrStatus.innerHTML = '<span class="ocr-status-error">✗ Błąd: ' + error.message + '</span>';
            } finally {
                // Enable button
                ocrBtn.disabled = false;
                ocrBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;"><path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/><path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/></svg> Skanuj ponownie';
            }
        });
        
        // Funkcja wypełniająca formularz danymi z OCR
        function fillFormFromOCR(data) {
            // Numer faktury
            if (data.number) {
                document.querySelector('input[name="number"]').value = data.number;
            }
            
            // Daty
            if (data.issue_date) {
                document.querySelector('input[name="issue_date"]').value = data.issue_date;
            }
            if (data.due_date) {
                document.querySelector('input[name="due_date"]').value = data.due_date;
            }
            if (data.sale_date) {
                document.querySelector('input[name="sale_date"]').value = data.sale_date;
            }
            
            // Kontrahent (jeśli nie wybrany z listy)
            if (data.vendor_name && !document.querySelector('select[name="vendor_id"]').value) {
                document.querySelector('input[name="source_name"]').value = data.vendor_name;
                if (data.vendor_nip) {
                    document.querySelector('input[name="source_name"]').value += ' (NIP: ' + data.vendor_nip + ')';
                }
            }
            
            // Wypełnij pozycje faktury (jeśli OCR je wykrył)
            if (data.items && Array.isArray(data.items) && data.items.length > 0) {
                // Wyczyść obecne pozycje
                document.getElementById('items-tbody').innerHTML = '';
                
                // Dodaj pozycje z OCR
                data.items.forEach(item => {
                    addDocumentItem({
                        name: item.name || item.description || '',
                        quantity: item.quantity || 1,
                        unit: item.unit || 'szt',
                        unit_price: item.unit_price || item.price || 0,
                        vat_rate: item.vat_rate || item.vat || '23'
                    });
                });
                
                // Zaktualizuj status z liczbą pozycji
                const confidence = Math.round(data.confidence * 100);
                ocrStatus.innerHTML = '<span class="ocr-status-success">✓ Faktura zeskanowana! Wykryto ' + data.items.length + ' pozycji.</span> <span style="color: #666;">(Pewność: ' + confidence + '%)</span>';
            } else {
                // Jeśli nie wykryto pozycji, zachowaj domyślną pozycję
                if (document.querySelectorAll('#items-tbody tr').length === 0) {
                    addDocumentItem();
                }
            }
            
            // Animacja (highlight wypełnionych pól)
            const filledInputs = document.querySelectorAll('input[name="number"], input[name="issue_date"]');
            filledInputs.forEach(input => {
                if (input.value) {
                    input.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        input.style.backgroundColor = '';
                    }, 2000);
                }
            });
        }
    })();
    </script>
</body>
</html>
