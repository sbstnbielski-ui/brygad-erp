<?php
/**
 * BRYGAD ERP - Budżet Domowy - Edytuj transakcję
 * ETAP 3
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';

$pdo         = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit     = HB_CAN_EDIT;
$userId      = $_SESSION['user_id'];

if (!$canEdit) {
    die('Brak uprawnień do edycji.');
}

$transactionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$backPeriod    = isset($_GET['back_period']) ? $_GET['back_period'] : null;

if (!$transactionId) {
    redirect(url('budzet.transakcje'));
}

// Pobierz transakcję (sprawdź że należy do tego household)
try {
    $stmt = $pdo->prepare("
        SELECT t.*, 
               a.name AS account_name,
               c.name AS category_name
        FROM hb_transactions t
        LEFT JOIN hb_accounts a  ON a.id = t.account_id
        LEFT JOIN hb_categories c ON c.id = t.category_id
        WHERE t.id = ? AND t.household_id = ?
        LIMIT 1
    ");
    $stmt->execute([$transactionId, $householdId]);
    $transaction = $stmt->fetch();
} catch (PDOException $e) {
    error_log("edytuj-transakcje: " . $e->getMessage());
    die('Błąd bazy danych.');
}

if (!$transaction) {
    die('Transakcja nie istnieje lub brak dostępu.');
}

// Pobierz dane do formularza
try {
    $stmtAccounts = $pdo->prepare("
        SELECT id, name, current_balance FROM hb_accounts
        WHERE household_id = ? AND is_active = 1
        ORDER BY sort_order, name
    ");
    $stmtAccounts->execute([$householdId]);
    $accounts = $stmtAccounts->fetchAll();

    $stmtCategories = $pdo->prepare("
        SELECT id, name, type FROM hb_categories
        WHERE household_id = ? AND is_active = 1
        ORDER BY type, name
    ");
    $stmtCategories->execute([$householdId]);
    $allCategories = $stmtCategories->fetchAll();
} catch (PDOException $e) {
    error_log("edytuj-transakcje form data: " . $e->getMessage());
    die('Błąd pobierania danych formularza.');
}

$error = null;

// -------------------------------------------------------
// Obsługa POST
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $direction         = $_POST['direction']         ?? '';
    $accountId         = (int)($_POST['account_id']  ?? 0);
    $amount            = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
    $date              = $_POST['date']              ?? '';
    $categoryId        = !empty($_POST['category_id'])        ? (int)$_POST['category_id']        : null;
    $transferAccountId = !empty($_POST['transfer_account_id']) ? (int)$_POST['transfer_account_id'] : null;
    $description       = trim($_POST['description'] ?? '');
    $visibility        = in_array($_POST['visibility'] ?? '', ['shared', 'private']) ? $_POST['visibility'] : 'shared';
    $ownerUserId       = ($visibility === 'private') ? $userId : null;
    $includeInTotal    = ($visibility === 'shared') ? 1 : (isset($_POST['include_in_household_total']) ? 1 : 0);

    if (!in_array($direction, ['income', 'expense', 'transfer'])) {
        $error = 'Wybierz typ transakcji.';
    } elseif (!$accountId) {
        $error = 'Wybierz konto.';
    } elseif ($amount <= 0) {
        $error = 'Kwota musi być większa od 0.';
    } elseif (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Podaj poprawną datę.';
    } elseif ($direction === 'transfer' && !$transferAccountId) {
        $error = 'Dla transferu wybierz konto docelowe.';
    } elseif ($direction === 'transfer' && $transferAccountId === $accountId) {
        $error = 'Konto źródłowe i docelowe nie mogą być takie same.';
    } elseif (in_array($direction, ['income', 'expense']) && !$categoryId) {
        $error = 'Wybierz kategorię.';
    }

    if (!$error) {
        try {
            $transactionPeriod = date('Y-m-01', strtotime($date));
            if ($direction === 'transfer') {
                $categoryId = null;
            }

            $stmt = $pdo->prepare("
                UPDATE hb_transactions SET
                    direction                  = ?,
                    account_id                 = ?,
                    transfer_account_id        = ?,
                    amount                     = ?,
                    date                       = ?,
                    period                     = ?,
                    category_id                = ?,
                    description                = ?,
                    visibility                 = ?,
                    owner_user_id              = ?,
                    include_in_household_total = ?
                WHERE id = ? AND household_id = ?
            ");
            $stmt->execute([
                $direction,
                $accountId,
                $transferAccountId,
                $amount,
                $date,
                $transactionPeriod,
                $categoryId,
                $description,
                $visibility,
                $ownerUserId,
                $includeInTotal,
                $transactionId,
                $householdId,
            ]);

            logEvent("Edytowano transakcję ID=$transactionId (household=$householdId)", 'INFO');

            $redirectPeriod = $backPeriod ?: substr($transactionPeriod, 0, 7);
            redirect(url('budzet.transakcje', ['period' => $redirectPeriod]) . '&edited=1');

        } catch (PDOException $e) {
            error_log("edytuj-transakcje save: " . $e->getMessage());
            $error = 'Błąd podczas zapisywania transakcji.';
        }
    }

    // Po błędzie — wypełnij formularz wartościami z POST
    if ($error) {
        $transaction['direction']           = $direction;
        $transaction['account_id']          = $accountId;
        $transaction['transfer_account_id'] = $transferAccountId;
        $transaction['amount']              = $amount;
        $transaction['date']                = $date;
        $transaction['category_id']         = $categoryId;
        $transaction['description']         = $description;
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj transakcję</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; }
        .header { background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
        .header-content { max-width: 1400px; margin: 0 auto; padding: 16px 30px; display: flex; justify-content: space-between; align-items: center; }
        .logo-section { display: flex; align-items: center; gap: 16px; }
        .logo-section img { height: 44px; border-radius: 6px; }
        .logo-text h1 { font-size: 22px; color: #333; }
        .logo-text p { font-size: 12px; color: #666; }
        .user-section { display: flex; align-items: center; gap: 14px; }
        .user-name { font-weight: 600; color: #333; font-size: 14px; }
        .btn-header { padding: 7px 14px; background: linear-gradient(135deg,#0f172a,#334155); color: white; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; }
        .container { max-width: 800px; margin: 0 auto; padding: 30px; }
        .breadcrumb { margin-bottom: 18px; color: #666; font-size: 13px; }
        .breadcrumb a { color: #667eea; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .page-header { margin-bottom: 24px; }
        .page-header h2 { font-size: 26px; color: #1f2937; }
        .page-header .subtitle { font-size: 13px; color: #6b7280; margin-top: 4px; }
        .form-card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 7px; font-weight: 600; font-size: 13px; color: #374151; }
        .form-group input,
        .form-group select,
        .form-group textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #d1d5db; border-radius: 7px; font-size: 14px; font-family: inherit; background: white; transition: border .2s, box-shadow .2s; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,.1); }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; }
        .btn { padding: 10px 24px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .2s; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; transform: translateY(-1px); }
        .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .btn-secondary:hover { background: #e5e7eb; }
        .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; padding: 14px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
        .hidden { display: none; }
        .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 12px; }
    </style>
    <script>
        function toggleFields() {
            var direction = document.getElementById('direction').value;
            var categoryRow = document.getElementById('category-row');
            var transferAccountRow = document.getElementById('transfer-account-row');

            if (direction === 'transfer') {
                categoryRow.classList.add('hidden');
                transferAccountRow.classList.remove('hidden');
            } else {
                categoryRow.classList.remove('hidden');
                transferAccountRow.classList.add('hidden');
            }
            updateCategoryOptions(direction);
        }

        function togglePrivacyOptions() {
            var vis = document.getElementById('visibility_select').value;
            var row = document.getElementById('include-total-row');
            row.classList.toggle('hidden', vis !== 'private');
        }

        function updateCategoryOptions(type) {
            if (type === 'transfer') return;
            var categorySelect = document.getElementById('category_id');
            var options = categorySelect.querySelectorAll('option');
            options.forEach(function(opt) {
                if (opt.value === '') return;
                var optType = opt.getAttribute('data-type');
                opt.style.display = (optType === type) ? '' : 'none';
            });
            var selected = categorySelect.selectedOptions[0];
            if (selected && selected.getAttribute('data-type') !== type && selected.value !== '') {
                categorySelect.value = '';
            }
        }
    </script>
</head>
<body>
<header class="header">
    <div class="header-content">
        <div class="logo-section">
            <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP">
            <div class="logo-text">
                <h1><?php echo e(APP_NAME); ?></h1>
                <p>Budżet Domowy</p>
            </div>
        </div>
        <div class="user-section">
            <span class="user-name"><?php echo e($userName); ?></span>
            <a href="<?php echo HB_HOME_URL; ?>" class="btn-header">Panel główny</a>
            <a href="<?php echo url('logout'); ?>"    class="btn-header">Wyloguj</a>
        </div>
    </div>
</header>

<div class="container">
    <div class="breadcrumb">
        <a href="<?php echo url('budzet'); ?>">Budżet</a> /
        <a href="<?php echo url('budzet.transakcje', ['period' => $backPeriod ?? substr($transaction['period'], 0, 7)]); ?>">Transakcje</a> /
        Edytuj transakcję #<?php echo $transactionId; ?>
    </div>

    <div class="page-header">
        <h2>Edytuj transakcję</h2>
        <p class="subtitle">Zmień kwotę, datę, kategorię lub opis</p>
    </div>

    <?php if ($error): ?>
    <div class="alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" action="<?php echo url('budzet.transakcje.edit', ['id' => $transactionId, 'back_period' => $backPeriod ?? '']); ?>">

            <div class="form-group">
                <label>Typ transakcji <span style="color:#dc2626">*</span></label>
                <select name="direction" id="direction" required onchange="toggleFields()">
                    <option value="income"   <?php if ($transaction['direction'] === 'income')   echo 'selected'; ?>>Przychód</option>
                    <option value="expense"  <?php if ($transaction['direction'] === 'expense')  echo 'selected'; ?>>Wydatek</option>
                    <option value="transfer" <?php if ($transaction['direction'] === 'transfer') echo 'selected'; ?>>Transfer</option>
                </select>
            </div>

            <div class="form-group">
                <label>Konto źródłowe <span style="color:#dc2626">*</span></label>
                <select name="account_id" required>
                    <option value="">-- Wybierz konto --</option>
                    <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>"
                            <?php if ($transaction['account_id'] == $acc['id']) echo 'selected'; ?>>
                        <?php echo e($acc['name']); ?>
                        (Saldo: <?php echo hb_format_money((float)$acc['current_balance']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group <?php echo $transaction['direction'] !== 'transfer' ? 'hidden' : ''; ?>" id="transfer-account-row">
                <label>Konto docelowe <span style="color:#dc2626">*</span></label>
                <select name="transfer_account_id">
                    <option value="">-- Wybierz konto --</option>
                    <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>"
                            <?php if ($transaction['transfer_account_id'] == $acc['id']) echo 'selected'; ?>>
                        <?php echo e($acc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group <?php echo $transaction['direction'] === 'transfer' ? 'hidden' : ''; ?>" id="category-row">
                <label>Kategoria <span style="color:#dc2626">*</span></label>
                <select name="category_id" id="category_id">
                    <option value="">-- Wybierz kategorię --</option>
                    <?php foreach ($allCategories as $cat): ?>
                    <option
                        value="<?php echo $cat['id']; ?>"
                        data-type="<?php echo $cat['type']; ?>"
                        <?php if ($transaction['category_id'] == $cat['id']) echo 'selected'; ?>
                        <?php if ($cat['type'] !== $transaction['direction'] && $transaction['direction'] !== 'transfer') echo 'style="display:none"'; ?>
                    >
                        <?php echo e($cat['name']); ?>
                        (<?php echo $cat['type'] === 'income' ? 'Przychód' : 'Wydatek'; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Kwota (PLN) <span style="color:#dc2626">*</span></label>
                <input type="number" name="amount" step="0.01" min="0.01"
                       value="<?php echo number_format((float)$transaction['amount'], 2, '.', ''); ?>"
                       required>
            </div>

            <div class="form-group">
                <label>Data <span style="color:#dc2626">*</span></label>
                <input type="date" name="date"
                       value="<?php echo e($transaction['date']); ?>"
                       required>
            </div>

            <div class="form-group">
                <label>Opis (opcjonalnie)</label>
                <textarea name="description" rows="3"><?php echo e($transaction['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Widoczność</label>
                <select name="visibility" id="visibility_select" onchange="togglePrivacyOptions()">
                    <option value="shared"  <?php if (($transaction['visibility'] ?? 'shared') === 'shared')  echo 'selected'; ?>>Wspólna (widoczna dla wszystkich domowników)</option>
                    <option value="private" <?php if (($transaction['visibility'] ?? 'shared') === 'private') echo 'selected'; ?>>Prywatna (tylko Ty widzisz)</option>
                </select>
            </div>

            <div class="form-group <?php echo (($transaction['visibility'] ?? 'shared') !== 'private') ? 'hidden' : ''; ?>" id="include-total-row">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:normal;">
                    <input type="checkbox" name="include_in_household_total" value="1"
                           <?php if (!empty($transaction['include_in_household_total'])) echo 'checked'; ?>>
                    <span>Uwzględnij w sumach domowych</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                <a href="<?php echo url('budzet.transakcje', ['period' => $backPeriod ?? substr($transaction['period'], 0, 7)]); ?>"
                   class="btn btn-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</div>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
</footer>
</body>
</html>

