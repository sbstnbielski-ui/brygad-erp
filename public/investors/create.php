<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Kontrahenta/Inwestora
 * Obsługa B2B (firma) i B2C (osoba prywatna)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$return_to = $_GET['return_to'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? 'business';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $nip = trim($_POST['nip'] ?? '');
    $regon = trim($_POST['regon'] ?? '');
    $krs = trim($_POST['krs'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account = trim($_POST['bank_account'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Walidacja w zależności od typu
    if ($type === 'private') {
        // Osoba prywatna - wymagane imię i nazwisko
        if (empty($first_name) || empty($last_name)) {
            $errors[] = "Imię i nazwisko są wymagane dla osoby prywatnej";
        }
        // Dla B2C: name = "Imię Nazwisko"
        $name = trim($first_name . ' ' . $last_name);
    } else {
        // Firma - wymagana tylko nazwa (minimum dla CRM)
        if (empty($name)) {
            $errors[] = "Nazwa firmy jest wymagana";
        }
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Nieprawidłowy adres email";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO investors (type, first_name, last_name, name, nip, regon, krs, address, email, phone, website, contact_person, bank_name, bank_account, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $type,
                $type === 'private' ? $first_name : null,
                $type === 'private' ? $last_name : null,
                $name,
                $nip ?: null,
                $regon ?: null,
                $krs ?: null,
                $address ?: null,
                $email ?: null,
                $phone ?: null,
                $website ?: null,
                $contact_person ?: null,
                $bank_name ?: null,
                $bank_account ?: null,
                $notes ?: null,
                $is_active
            ]);
            
            $investor_id = $pdo->lastInsertId();
            $typeLabel = $type === 'private' ? 'B2C' : 'B2B';
            logEvent("Utworzono kontrahenta {$typeLabel}: {$name} (ID: {$investor_id})", 'INFO');
            
            // Check if this is an AJAX request (quick add from project form)
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'investor_id' => $investor_id,
                    'investor_name' => $name
                ]);
                exit;
            }
            
            // Check if return_to is set (coming from full form link)
            if (!empty($_POST['return_to'])) {
                $returnUrl = $_POST['return_to'];
                
                // Dodaj parametr investor_id do URL
                $separator = strpos($returnUrl, '?') !== false ? '&' : '?';
                $finalUrl = $returnUrl . $separator . 'investor_id=' . $investor_id;
                
                // Jeśli URL jest relative (nie zaczyna się od http), to OK - PHP Location obsługuje relative paths
                header("Location: " . $finalUrl);
                exit;
            }
            
            header("Location: " . url('investors', ['success' => 'created']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd tworzenia kontrahenta: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia kontrahenta";
            
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'errors' => $errors
                ]);
                exit;
            }
        }
    } elseif (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'errors' => $errors
        ]);
        exit;
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
$currentType = $_POST['type'] ?? 'business';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj Kontrahenta</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 25px;
        }
        .hero {
            background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%);
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
        .hero h1 {
            margin: 0 0 4px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.4px;
        }
        .hero-breadcrumb {
            font-size: 12px;
            color: #bfdbfe;
            margin-bottom: 6px;
        }
        .hero-breadcrumb a {
            color: #dbeafe;
            text-decoration: none;
        }
        .hero-breadcrumb a:hover {
            text-decoration: underline;
        }
        .hero p {
            margin: 0;
            color: #cbd5e1;
            font-size: 14px;
        }
        .hero-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            align-self: center;
        }
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
        .btn-hero-secondary:hover {
            background: rgba(255,255,255,0.18);
            color: #fff;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 40px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        
        /* Type Switcher - duże karty */
        .type-switcher {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 30px;
        }
        .type-option {
            padding: 20px;
            border: 3px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            background: white;
        }
        .type-option:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .type-option.active {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
        }
        .type-option input {
            display: none;
        }
        .type-option-icon {
            width: 40px;
            height: 40px;
            margin: 0 auto 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 700;
        }
        .type-option-label {
            font-weight: 700;
            font-size: 16px;
            color: #333;
        }
        .type-option-desc {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group .required {
            color: #dc3545;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        .btn {
            padding: 12px 32px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        
        /* Hide/show fields based on type */
        .business-fields { display: block; }
        .private-fields { display: none; }
        
        body.type-private .business-fields { display: none; }
        body.type-private .private-fields { display: block; }
        
        /* Sekcje */
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #667eea;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 30px 0 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        /* GUS Fetch Button */
        .btn-fetch-gus {
            padding: 12px 20px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .btn-fetch-gus:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }
        .btn-fetch-gus:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .btn-fetch-gus-icon {
            font-size: 16px;
        }
        .btn-fetch-gus.loading .btn-fetch-gus-icon {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* GUS notification */
        .gus-notification {
            margin-top: 15px;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
            animation: slideDown 0.3s ease-out;
        }
        .gus-notification.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .gus-notification.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .gus-notification.loading {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body class="type-<?php echo e($currentType); ?>">
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('investors'); ?>">Baza Kontrahentów</a> /
                    Dodaj kontrahenta
                </div>
                <h1>Dodaj Kontrahenta / Inwestora</h1>
                <p>Nowa karta klienta B2B lub B2C</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('investors'); ?>" class="btn-hero-secondary">← Wróć do listy</a>
            </div>
        </div>
        
        <div class="card">
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
            
            <form method="POST" action="" id="investorForm">
                <?php if ($return_to): ?>
                    <input type="hidden" name="return_to" value="<?php echo e($return_to); ?>">
                <?php endif; ?>
                
                <!-- Type Switcher -->
                <div class="type-switcher">
                    <label class="type-option <?php echo $currentType === 'business' ? 'active' : ''; ?>">
                        <input type="radio" name="type" value="business" <?php echo $currentType === 'business' ? 'checked' : ''; ?>>
                        <div class="type-option-icon">B2B</div>
                        <div class="type-option-label">Firma (B2B)</div>
                        <div class="type-option-desc">Przedsiębiorstwo, spółka, działalność</div>
                    </label>
                    <label class="type-option <?php echo $currentType === 'private' ? 'active' : ''; ?>">
                        <input type="radio" name="type" value="private" <?php echo $currentType === 'private' ? 'checked' : ''; ?>>
                        <div class="type-option-icon">B2C</div>
                        <div class="type-option-label">Osoba prywatna (B2C)</div>
                        <div class="type-option-desc">Klient indywidualny bez firmy</div>
                    </label>
                </div>
                
                <!-- Pola dla firmy (B2B) -->
                <div class="business-fields">
                    <div class="form-group">
                        <label>Nazwa firmy <span class="required">*</span></label>
                        <input type="text" name="name" id="company_name" value="<?php echo e($_POST['name'] ?? ''); ?>" 
                               placeholder="np. Budimex S.A.">
                        <div class="help-text">Pełna nazwa firmy lub działalności</div>
                    </div>
                    
                    <div class="section-title">Dane rejestrowe (opcjonalne)</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>NIP</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" name="nip" id="nip" value="<?php echo e($_POST['nip'] ?? ''); ?>" 
                                       placeholder="1234567890" maxlength="10" style="flex: 1;">
                                <button type="button" class="btn-fetch-gus" onclick="fetchFromGus()" title="Pobierz dane z GUS">
                                    <span class="btn-fetch-gus-icon">🔍</span>
                                    <span class="btn-fetch-gus-text">Pobierz z GUS</span>
                                </button>
                            </div>
                            <div class="help-text">Wpisz NIP i kliknij "Pobierz z GUS", aby automatycznie wypełnić dane firmy</div>
                        </div>
                        
                        <div class="form-group">
                            <label>REGON</label>
                            <input type="text" name="regon" id="regon" value="<?php echo e($_POST['regon'] ?? ''); ?>" 
                                   placeholder="123456789" maxlength="14">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>KRS / EDG</label>
                        <input type="text" name="krs" id="krs" value="<?php echo e($_POST['krs'] ?? ''); ?>" 
                               placeholder="0000123456">
                        <div class="help-text">Numer w Krajowym Rejestrze Sądowym lub ewidencji działalności gospodarczej</div>
                    </div>
                    
                    <div class="section-title">Dane kontaktowe</div>
                    <div class="form-group">
                        <label>Osoba do kontaktu</label>
                        <input type="text" name="contact_person" value="<?php echo e($_POST['contact_person'] ?? ''); ?>" 
                               placeholder="Jan Kowalski">
                        <div class="help-text">Imię i nazwisko osoby odpowiedzialnej za kontakt</div>
                    </div>
                </div>
                
                <!-- Pola dla osoby prywatnej (B2C) -->
                <div class="private-fields">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Imię <span class="required">*</span></label>
                            <input type="text" name="first_name" value="<?php echo e($_POST['first_name'] ?? ''); ?>" 
                                   placeholder="Jan">
                        </div>
                        
                        <div class="form-group">
                            <label>Nazwisko <span class="required">*</span></label>
                            <input type="text" name="last_name" value="<?php echo e($_POST['last_name'] ?? ''); ?>" 
                                   placeholder="Kowalski">
                        </div>
                    </div>
                </div>
                
                <!-- Wspólne pola -->
                <div class="section-title">Kontakt</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Telefon</label>
                        <input type="text" name="phone" id="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>" 
                               placeholder="123456789">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="email" value="<?php echo e($_POST['email'] ?? ''); ?>" 
                               placeholder="kontakt@firma.pl">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Strona WWW</label>
                        <input type="text" name="website" id="website" value="<?php echo e($_POST['website'] ?? ''); ?>" 
                               placeholder="www.firma.pl">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Adres</label>
                    <input type="text" name="address" id="address" value="<?php echo e($_POST['address'] ?? ''); ?>" 
                           placeholder="ul. Główna 1, 00-001 Warszawa">
                    <div class="help-text">Ulica, kod pocztowy, miasto</div>
                </div>
                
                <!-- Dane bankowe (tylko B2B) -->
                <div class="business-fields">
                    <div class="section-title">Dane bankowe (opcjonalne)</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nazwa banku</label>
                            <input type="text" name="bank_name" value="<?php echo e($_POST['bank_name'] ?? ''); ?>" 
                                   placeholder="np. PKO BP">
                        </div>
                        
                        <div class="form-group">
                            <label>Numer konta</label>
                            <input type="text" name="bank_account" value="<?php echo e($_POST['bank_account'] ?? ''); ?>" 
                                   placeholder="12 1234 5678 9012 3456 7890 1234">
                        </div>
                    </div>
                </div>
                
                <div class="section-title">Dodatkowe</div>
                <div class="form-group">
                    <label>Notatki</label>
                    <textarea name="notes" rows="3" placeholder="Dodatkowe informacje..."><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="is_active" value="1" 
                               <?php echo (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : ''; ?>>
                        <label for="is_active" style="margin: 0;">Aktywny</label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Utwórz Kontrahenta</button>
                    <a href="<?php echo url('investors'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        // Type switcher - dynamicznie pokazuj/ukrywaj pola
        document.querySelectorAll('.type-option input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                // Update active class
                document.querySelectorAll('.type-option').forEach(opt => opt.classList.remove('active'));
                this.closest('.type-option').classList.add('active');
                
                // Update body class for CSS show/hide
                document.body.className = 'type-' + this.value;
                
                // Focus appropriate first field
                if (this.value === 'business') {
                    document.querySelector('input[name="name"]').focus();
                } else {
                    document.querySelector('input[name="first_name"]').focus();
                }
            });
        });
        
        // Form validation - only validate visible required fields
        document.getElementById('investorForm').addEventListener('submit', function(e) {
            const type = document.querySelector('input[name="type"]:checked').value;
            
            if (type === 'business') {
                const name = document.querySelector('input[name="name"]').value.trim();
                if (!name) {
                    e.preventDefault();
                    alert('Nazwa firmy jest wymagana');
                    document.querySelector('input[name="name"]').focus();
                    return false;
                }
            } else {
                const firstName = document.querySelector('input[name="first_name"]').value.trim();
                const lastName = document.querySelector('input[name="last_name"]').value.trim();
                if (!firstName || !lastName) {
                    e.preventDefault();
                    alert('Imię i nazwisko są wymagane dla osoby prywatnej');
                    document.querySelector('input[name="first_name"]').focus();
                    return false;
                }
            }
        });
        
        // ============================================
        // GUS BIR API Integration
        // ============================================
        
        let gusNotificationElement = null;
        
        function showGusNotification(message, type = 'info') {
            // Remove existing notification
            if (gusNotificationElement) {
                gusNotificationElement.remove();
            }
            
            // Create new notification
            gusNotificationElement = document.createElement('div');
            gusNotificationElement.className = 'gus-notification ' + type;
            gusNotificationElement.textContent = message;
            
            // Insert after NIP field group
            const nipFieldGroup = document.querySelector('#nip').closest('.form-group');
            nipFieldGroup.appendChild(gusNotificationElement);
            
            // Auto-remove success/error messages after 5 seconds
            if (type === 'success' || type === 'error') {
                setTimeout(() => {
                    if (gusNotificationElement) {
                        gusNotificationElement.remove();
                        gusNotificationElement = null;
                    }
                }, 5000);
            }
        }
        
        function hideGusNotification() {
            if (gusNotificationElement) {
                gusNotificationElement.remove();
                gusNotificationElement = null;
            }
        }
        
        async function fetchFromGus() {
            const nipInput = document.getElementById('nip');
            const fetchButton = document.querySelector('.btn-fetch-gus');
            const nip = nipInput.value.trim().replace(/[^0-9]/g, '');
            
            // Validate NIP
            if (!nip) {
                alert('Proszę wpisać numer NIP');
                nipInput.focus();
                return;
            }
            
            if (nip.length !== 10) {
                alert('NIP musi składać się z 10 cyfr');
                nipInput.focus();
                return;
            }
            
            // Disable button and show loading
            fetchButton.disabled = true;
            fetchButton.classList.add('loading');
            const originalIcon = fetchButton.querySelector('.btn-fetch-gus-icon').textContent;
            fetchButton.querySelector('.btn-fetch-gus-icon').textContent = '⏳';
            fetchButton.querySelector('.btn-fetch-gus-text').textContent = 'Pobieranie...';
            
            showGusNotification('Pobieranie danych z GUS REGON...', 'loading');
            
            try {
                const response = await fetch('/api/bir/fetch_company.php?nip=' + encodeURIComponent(nip));
                const result = await response.json();
                
                if (result.success && result.data) {
                    // Fill form fields
                    const data = result.data;
                    
                    if (data.name) {
                        document.getElementById('company_name').value = data.name;
                    }
                    if (data.regon) {
                        document.getElementById('regon').value = data.regon;
                    }
                    if (data.krs) {
                        document.getElementById('krs').value = data.krs;
                    }
                    if (data.address) {
                        document.getElementById('address').value = data.address;
                    }
                    if (data.email) {
                        document.getElementById('email').value = data.email;
                    }
                    if (data.phone) {
                        document.getElementById('phone').value = data.phone;
                    }
                    if (data.website) {
                        document.getElementById('website').value = data.website;
                    }
                    
                    showGusNotification('✓ Dane firmy zostały pobrane z GUS: ' + data.name, 'success');
                    
                    // Focus on first empty field
                    document.getElementById('company_name').focus();
                    
                } else {
                    const errorMsg = result.error || 'Nie znaleziono firmy o podanym NIP';
                    showGusNotification('✗ ' + errorMsg, 'error');
                    
                    // If it's a server/SOAP error, suggest checking diagnostics
                    if (errorMsg.includes('SOAP') || errorMsg.includes('Server') || errorMsg.includes('Internal')) {
                        console.error('GUS API Error:', errorMsg);
                        console.info('Run diagnostics at: /api/bir/test.php');
                    }
                }
                
            } catch (error) {
                console.error('GUS API Error:', error);
                showGusNotification('✗ Błąd połączenia z serwerem GUS. Sprawdź konfigurację serwera.', 'error');
                console.info('Run diagnostics at: /api/bir/test.php');
            } finally {
                // Re-enable button
                fetchButton.disabled = false;
                fetchButton.classList.remove('loading');
                fetchButton.querySelector('.btn-fetch-gus-icon').textContent = originalIcon;
                fetchButton.querySelector('.btn-fetch-gus-text').textContent = 'Pobierz z GUS';
            }
        }
        
        // Allow Enter key on NIP field to trigger GUS fetch
        document.getElementById('nip').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                fetchFromGus();
            }
        });
    </script>
</body>
</html>
