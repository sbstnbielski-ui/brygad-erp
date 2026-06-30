<?php
/**
 * BRYGAD ERP - Ustawienia firmy (dane sprzedawcy na fakturach)
 * Singleton — jeden wiersz w company_settings
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success_message = '';

$stmt = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    $pdo->exec("INSERT INTO company_settings (id) VALUES (1)");
    $stmt = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token sesji (CSRF).';
    }

    $fields = [
        'company_name'             => trim($_POST['company_name'] ?? ''),
        'company_nip'              => trim($_POST['company_nip'] ?? ''),
        'company_regon'            => trim($_POST['company_regon'] ?? ''),
        'company_address'          => trim($_POST['company_address'] ?? ''),
        'company_city'             => trim($_POST['company_city'] ?? ''),
        'company_post_code'        => trim($_POST['company_post_code'] ?? ''),
        'company_email'            => trim($_POST['company_email'] ?? ''),
        'company_phone'            => trim($_POST['company_phone'] ?? ''),
        'company_website'          => trim($_POST['company_website'] ?? ''),
        'default_bank_account'     => trim($_POST['default_bank_account'] ?? ''),
        'default_bank_name'        => trim($_POST['default_bank_name'] ?? ''),
        'default_place_of_issue'   => trim($_POST['default_place_of_issue'] ?? 'Czerwonak'),
        'default_payment_days'     => (int)($_POST['default_payment_days'] ?? 14),
        'default_payment_method'   => trim($_POST['default_payment_method'] ?? 'transfer'),
        'default_currency'         => trim($_POST['default_currency'] ?? 'PLN'),
        'default_notes'            => trim($_POST['default_notes'] ?? ''),
        'default_description_footer' => trim($_POST['default_description_footer'] ?? ''),
        'fakturownia_department_id' => (int)($_POST['fakturownia_department_id'] ?? 0) ?: null,
        'fakturownia_lang'         => trim($_POST['fakturownia_lang'] ?? 'pl'),
        'issuer_name'              => trim($_POST['issuer_name'] ?? ''),
    ];

    if (empty($fields['company_name'])) {
        $errors[] = 'Nazwa firmy jest wymagana.';
    }

    // Logo upload
    $logo_path = $settings['logo_path'];
    if (!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Logo: dozwolone formaty to JPG, PNG, SVG, WebP.';
        } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo nie może być większe niż 2MB.';
        } else {
            $upload_dir = dirname(__DIR__) . '/uploads/company';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_name = 'logo_' . time() . '.' . $ext;
            $dest = $upload_dir . '/' . $file_name;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logo_path = 'uploads/company/' . $file_name;
            } else {
                $errors[] = 'Błąd przesyłania logo.';
            }
        }
    }

    if (empty($errors)) {
        $sets = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $sets[] = "`{$col}` = :{$col}";
            $params[":{$col}"] = $val;
        }
        $sets[] = "`logo_path` = :logo_path";
        $params[':logo_path'] = $logo_path;

        $sql = "UPDATE company_settings SET " . implode(', ', $sets) . " WHERE id = :id";
        $params[':id'] = $settings['id'];

        $pdo->prepare($sql)->execute($params);

        logEvent('Zaktualizowano ustawienia firmy', 'INFO');
        $success_message = 'Ustawienia zostały zapisane.';

        $stmt = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Ustawienia firmy</title>
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --bg-body: #f5f7fa;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --success: #16a34a;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }

        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; }
        .alerts-dropdown { display: none !important; }

        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .card { background: #fff; border-radius: 10px; padding: 30px; margin-bottom: 20px; }
        .section-title { font-size: 18px; font-weight: 700; color: #0f172a; border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 16px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
        .form-group { display: flex; flex-direction: column; min-width: 0; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; transition: all 0.2s; width: 100%; box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--success); box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .required { color: #dc2626; }

        .logo-preview { display: flex; align-items: center; gap: 15px; margin-bottom: 12px; }
        .logo-preview img { max-height: 60px; border-radius: 6px; border: 1px solid var(--border); padding: 4px; background: #fff; }

        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        .form-actions { display: flex; gap: 12px; justify-content: flex-end; padding-top: 18px; border-top: 1px solid var(--border); }
        .btn { padding: 10px 22px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-block; border: none; }
        .btn-primary { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: #fff; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.3); }
        .btn-secondary { background: #f3f4f6; color: #374151; }
        .btn-secondary:hover { background: #e5e7eb; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Ustawienia firmy
                </div>
                <h1>Dane firmy (sprzedawca)</h1>
                <p>Te dane będą automatycznie uzupełniane na każdej nowej fakturze sprzedażowej.</p>
            </div>
            <div>
                <a href="<?php echo url('finanse.fakturownia-settings'); ?>" class="btn-hero-secondary">Konsola Fakturownia</a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo e($success_message); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błędy:</strong>
                <ul style="margin: 8px 0 0 20px;">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo e($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrfField(); ?>

            <div class="card">
                <div class="section-title">Dane firmy</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nazwa firmy <span class="required">*</span></label>
                        <input type="text" name="company_name" value="<?php echo e($settings['company_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>NIP</label>
                        <input type="text" name="company_nip" value="<?php echo e($settings['company_nip']); ?>" placeholder="000-000-00-00">
                    </div>
                    <div class="form-group">
                        <label>REGON</label>
                        <input type="text" name="company_regon" value="<?php echo e($settings['company_regon']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Adres (ulica i numer)</label>
                        <input type="text" name="company_address" value="<?php echo e($settings['company_address']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Miasto</label>
                        <input type="text" name="company_city" value="<?php echo e($settings['company_city']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Kod pocztowy</label>
                        <input type="text" name="company_post_code" value="<?php echo e($settings['company_post_code']); ?>" placeholder="00-000">
                    </div>
                    <div class="form-group">
                        <label>E-mail</label>
                        <input type="email" name="company_email" value="<?php echo e($settings['company_email']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Telefon</label>
                        <input type="text" name="company_phone" value="<?php echo e($settings['company_phone']); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label>Strona www</label>
                        <input type="text" name="company_website" value="<?php echo e($settings['company_website']); ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label>Logo firmy</label>
                    <?php if (!empty($settings['logo_path'])): ?>
                        <div class="logo-preview">
                            <img src="/<?php echo e($settings['logo_path']); ?>" alt="Logo firmy">
                            <span style="font-size: 12px; color: #6b7280;"><?php echo e($settings['logo_path']); ?></span>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="logo" accept=".jpg,.jpeg,.png,.svg,.webp">
                    <div class="help-text">Maks. 2MB. JPG, PNG, SVG lub WebP. Logo pojawi się na podglądzie faktury.</div>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label>Imię i nazwisko wystawcy faktur</label>
                    <input type="text" name="issuer_name" value="<?php echo e($settings['issuer_name'] ?? ''); ?>" placeholder="np. Jan Kowalski">
                    <div class="help-text">Osoba wystawiająca faktury — wyświetlana na podglądzie i wydruku faktury.</div>
                </div>
            </div>

            <div class="card">
                <div class="section-title">Domyślne ustawienia faktur</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Domyślne konto bankowe</label>
                        <input type="text" name="default_bank_account" value="<?php echo e($settings['default_bank_account']); ?>" placeholder="XX XXXX XXXX XXXX XXXX XXXX XXXX">
                        <div class="help-text">Automatycznie wypełniane na każdej nowej fakturze.</div>
                    </div>
                    <div class="form-group">
                        <label>Nazwa banku</label>
                        <input type="text" name="default_bank_name" value="<?php echo e($settings['default_bank_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Miejsce wystawienia</label>
                        <input type="text" name="default_place_of_issue" value="<?php echo e($settings['default_place_of_issue']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Termin płatności (dni)</label>
                        <input type="number" name="default_payment_days" value="<?php echo (int)$settings['default_payment_days']; ?>" min="0" max="365">
                    </div>
                    <div class="form-group">
                        <label>Sposób płatności</label>
                        <select name="default_payment_method">
                            <?php $pm = $settings['default_payment_method']; ?>
                            <option value="transfer" <?php echo $pm === 'transfer' ? 'selected' : ''; ?>>Przelew</option>
                            <option value="cash" <?php echo $pm === 'cash' ? 'selected' : ''; ?>>Gotówka</option>
                            <option value="card" <?php echo $pm === 'card' ? 'selected' : ''; ?>>Karta</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Domyślna waluta</label>
                        <select name="default_currency">
                            <?php $cur = $settings['default_currency']; ?>
                            <option value="PLN" <?php echo $cur === 'PLN' ? 'selected' : ''; ?>>PLN</option>
                            <option value="EUR" <?php echo $cur === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                            <option value="USD" <?php echo $cur === 'USD' ? 'selected' : ''; ?>>USD</option>
                            <option value="GBP" <?php echo $cur === 'GBP' ? 'selected' : ''; ?>>GBP</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Domyślne uwagi na fakturze</label>
                        <textarea name="default_notes"><?php echo e($settings['default_notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Domyślna stopka opisu (Fakturownia)</label>
                        <textarea name="default_description_footer"><?php echo e($settings['default_description_footer'] ?? ''); ?></textarea>
                        <div class="help-text">Tekst widoczny na dole każdej faktury w Fakturowni.</div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Anuluj</a>
                <button type="submit" class="btn btn-primary">Zapisz ustawienia</button>
            </div>
        </form>
    </div>
</body>
</html>
