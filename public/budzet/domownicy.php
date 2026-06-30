<?php
/**
 * BRYGAD ERP - Budżet Domowy - Zarządzanie domownikami (household members)
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';
require_once __DIR__ . '/_module_layout.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$userRole = HB_USER_ROLE;
$currentUserId = $_SESSION['user_id'];

// Tylko owner może zarządzać
$isOwner = ($userRole === 'owner');

// Obsługa POST (tylko owner)
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $newUserId = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['role'] ?? 'member';
        
        // Walidacja
        if (!$newUserId) {
            $error = 'Wybierz użytkownika.';
        } elseif (!in_array($newRole, ['owner', 'member', 'viewer'])) {
            $error = 'Nieprawidłowa rola.';
        } else {
            try {
                // Sprawdź czy user istnieje
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                $stmt->execute([$newUserId]);
                if (!$stmt->fetch()) {
                    $error = 'Użytkownik nie istnieje.';
                } else {
                    // Dodaj do household
                    $stmt = $pdo->prepare("
                        INSERT INTO hb_household_members (household_id, user_id, role)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$householdId, $newUserId, $newRole]);
                    $success = 'Domownik został dodany.';
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Ten użytkownik jest już członkiem tego budżetu.';
                } else {
                    error_log("Add member error: " . $e->getMessage());
                    $error = 'Błąd podczas dodawania domownika.';
                }
            }
        }
    } else    if ($action === 'create_and_add') {
        // Tworzenie nowego użytkownika dla budżetu domowego (bez powiązania z workers)
        $displayName = trim($_POST['display_name'] ?? '');
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $newRole = $_POST['new_role'] ?? 'member';
        
        // Walidacja
        if (!$displayName) {
            $error = 'Nazwa (imię, pseudonim) jest wymagana.';
        } elseif (!$login || strlen($login) < 3) {
            $error = 'Login musi mieć minimum 3 znaki.';
        } elseif (!$password || strlen($password) < 6) {
            $error = 'Hasło musi mieć minimum 6 znaków.';
        } elseif (!in_array($newRole, ['owner', 'member', 'viewer'])) {
            $error = 'Nieprawidłowa rola.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Sprawdź czy login jest unikalny
                $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
                $stmt->execute([$login]);
                if ($stmt->fetch()) {
                    $error = 'Login jest już zajęty.';
                    $pdo->rollBack();
                } else {
                    // Utwórz użytkownika - BEZ worker_id, tylko display_name
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (login, password_hash, display_name, worker_id, role, is_active, created_at)
                        VALUES (?, ?, ?, NULL, 'worker', 1, NOW())
                    ");
                    $stmt->execute([$login, $passwordHash, $displayName]);
                    $newUserId = $pdo->lastInsertId();
                    
                    // Dodaj do household
                    $stmt = $pdo->prepare("
                        INSERT INTO hb_household_members (household_id, user_id, role)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$householdId, $newUserId, $newRole]);
                    
                    $pdo->commit();
                    $success = 'Nowy domownik został utworzony i dodany do budżetu.';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Create and add member error: " . $e->getMessage());
                $error = 'Błąd podczas tworzenia domownika: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'change_role') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';
        
        if (!in_array($newRole, ['owner', 'member', 'viewer'])) {
            $error = 'Nieprawidłowa rola.';
        } else {
            try {
                // Sprawdź czy nie degradujemy ostatniego ownera
                if ($newRole !== 'owner') {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count 
                        FROM hb_household_members 
                        WHERE household_id = ? AND role = 'owner'
                    ");
                    $stmt->execute([$householdId]);
                    $ownerCount = $stmt->fetch()['count'];
                    
                    // Sprawdź czy target jest ownerem
                    $stmt = $pdo->prepare("
                        SELECT role FROM hb_household_members 
                        WHERE household_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$householdId, $targetUserId]);
                    $currentRole = $stmt->fetch()['role'] ?? '';
                    
                    if ($currentRole === 'owner' && $ownerCount <= 1) {
                        $error = 'Nie możesz zdegradować ostatniego właściciela. Najpierw awansuj innego domownika.';
                    }
                }
                
                if (!$error) {
                    $stmt = $pdo->prepare("
                        UPDATE hb_household_members 
                        SET role = ? 
                        WHERE household_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$newRole, $householdId, $targetUserId]);
                    $success = 'Rola została zmieniona.';
                }
            } catch (PDOException $e) {
                error_log("Change role error: " . $e->getMessage());
                $error = 'Błąd podczas zmiany roli.';
            }
        }
    } elseif ($action === 'remove') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        
        if ($targetUserId === $currentUserId) {
            $error = 'Nie możesz usunąć samego siebie. Przekaż uprawnienia właściciela innemu domownikowi, a następnie poproś go o usunięcie Twojego konta.';
        } else {
            try {
                // Sprawdź czy nie usuwamy ostatniego ownera
                $stmt = $pdo->prepare("
                    SELECT role FROM hb_household_members 
                    WHERE household_id = ? AND user_id = ?
                ");
                $stmt->execute([$householdId, $targetUserId]);
                $targetRole = $stmt->fetch()['role'] ?? '';
                
                if ($targetRole === 'owner') {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count 
                        FROM hb_household_members 
                        WHERE household_id = ? AND role = 'owner'
                    ");
                    $stmt->execute([$householdId]);
                    $ownerCount = $stmt->fetch()['count'];
                    
                    if ($ownerCount <= 1) {
                        $error = 'Nie możesz usunąć ostatniego właściciela.';
                    }
                }
                
                if (!$error) {
                    $stmt = $pdo->prepare("
                        DELETE FROM hb_household_members 
                        WHERE household_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$householdId, $targetUserId]);
                    $success = 'Domownik został usunięty.';
                }
            } catch (PDOException $e) {
                error_log("Remove member error: " . $e->getMessage());
                $error = 'Błąd podczas usuwania domownika.';
            }
        }
    }
}

// Pobierz listę członków
try {
    $stmt = $pdo->prepare("
        SELECT 
            hm.user_id, 
            hm.role, 
            u.login,
            COALESCE(u.display_name, CONCAT(w.first_name, ' ', w.last_name), u.login) AS display_name
        FROM hb_household_members hm
        JOIN users u ON u.id = hm.user_id
        LEFT JOIN workers w ON w.id = u.worker_id
        WHERE hm.household_id = ?
        ORDER BY 
            CASE hm.role 
                WHEN 'owner' THEN 1 
                WHEN 'member' THEN 2 
                WHEN 'viewer' THEN 3 
            END,
            display_name
    ");
    $stmt->execute([$householdId]);
    $members = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Members fetch error: " . $e->getMessage());
    $members = [];
}

// Pobierz dostępnych użytkowników (tylko jeśli owner)
$availableUsers = [];
if ($isOwner) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id, 
                u.login,
                COALESCE(u.display_name, CONCAT(w.first_name, ' ', w.last_name), u.login) AS display_name
            FROM users u
            LEFT JOIN workers w ON w.id = u.worker_id
            WHERE u.id NOT IN (
                SELECT user_id 
                FROM hb_household_members 
                WHERE household_id = ?
            )
            AND u.is_active = 1
            ORDER BY display_name
        ");
        $stmt->execute([$householdId]);
        $availableUsers = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Available users fetch error: " . $e->getMessage());
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// Helper function
function getDisplayName($member) {
    return $member['display_name'] ?: $member['login'];
}

function getRoleLabel($role) {
    $labels = [
        'owner' => 'Właściciel',
        'member' => 'Członek',
        'viewer' => 'Przeglądający'
    ];
    return $labels[$role] ?? $role;
}

function getRoleBadgeClass($role) {
    $classes = [
        'owner' => 'badge-owner',
        'member' => 'badge-member',
        'viewer' => 'badge-viewer'
    ];
    return $classes[$role] ?? 'badge-secondary';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Domownicy</title>
    <style>
        <?php echo hb_module_layout_styles(); ?>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo-section img { height: 50px; border-radius: 6px; }
        .logo-text h1 { font-size: 24px; color: #333; }
        .logo-text p { font-size: 13px; color: #666; }
        .user-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-name { font-weight: 600; color: #333; }
        .btn-logout, .btn-back {
            padding: 8px 16px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        .dashboard-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
            align-items: start;
        }
        
        /* Sidebar styles */
        .sidebar-actions {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0;
            position: sticky;
            top: 92px;
        }
        .sidebar-actions-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .sidebar-actions-header h3 {
            font-size: 11px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            font-weight: 600;
        }
        .sidebar-actions-body {
            padding: 8px;
        }
        .sidebar-section {
            margin-bottom: 20px;
        }
        .sidebar-section:last-child {
            margin-bottom: 8px;
        }
        .sidebar-section-title {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 12px 12px 8px 12px;
            font-weight: 600;
        }
        .sidebar-section:first-child .sidebar-section-title {
            margin-top: 4px;
        }
        .sidebar-actions a {
            display: block;
            padding: 10px 12px;
            margin-bottom: 4px;
            color: #374151;
            text-decoration: none;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-size: 13px;
            font-weight: 500;
        }
        .sidebar-actions a:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #111827;
        }
        .dashboard-content {
            min-width: 0;
        }
        
        @media (max-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            .sidebar-actions {
                position: static;
            }
        }
        
        .page-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            padding: 40px 50px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .page-header h2 { font-size: 28px; margin-bottom: 8px; }
        .page-header .subtitle { font-size: 14px; color: #94a3b8; }
        .nav-links {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .nav-links a {
            padding: 10px 20px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .nav-links a:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        .nav-links a.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .section h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group input[type="password"]:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: #d1fae5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th {
            text-align: left;
            padding: 12px;
            background: #f9fafb;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            border-bottom: 2px solid #e5e7eb;
        }
        table td {
            padding: 12px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        table tr:hover {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-owner { background: #dbeafe; color: #1e40af; }
        .badge-member { background: #d1fae5; color: #059669; }
        .badge-viewer { background: #e5e7eb; color: #6b7280; }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <?php hb_module_shell_start([
        'active' => 'domownicy',
        'title' => 'Domownicy',
        'subtitle' => 'Zarządzanie członkami budżetu domowego',
        'user_name' => $userName,
        'period_month' => date('Y-m'),
    ]); ?>
                
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>
                
                <?php if (!$isOwner): ?>
                <div class="alert alert-info">
                    Tylko właściciel może zarządzać domownikami. Aktualnie możesz tylko przeglądać listę.
                </div>
                <?php endif; ?>
                
                <?php if ($isOwner): ?>
                <!-- OPCJA 1: Utwórz nowego domownika -->
                <div class="section">
                    <h3>Utwórz nowego domownika</h3>
                    <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                        Utwórz nowe konto użytkownika dedykowane dla budżetu domowego. Ten użytkownik będzie miał dostęp tylko do modułu budżetu.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_and_add">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nazwa (imię, pseudonim) <span style="color: #dc2626;">*</span></label>
                                <input type="text" name="display_name" required placeholder="np. Ania, Jan Kowalski, Mama">
                            </div>
                            
                            <div class="form-group">
                                <label>Login <span style="color: #dc2626;">*</span></label>
                                <input type="text" name="login" required placeholder="np. ania123" minlength="3">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Hasło <span style="color: #dc2626;">*</span></label>
                                <input type="password" name="password" required placeholder="Minimum 6 znaków" minlength="6">
                            </div>
                            
                            <div class="form-group">
                                <label>Rola <span style="color: #dc2626;">*</span></label>
                                <select name="new_role" required>
                                    <option value="member">Członek (może dodawać i edytować)</option>
                                    <option value="viewer">Przeglądający (tylko odczyt)</option>
                                    <option value="owner">Właściciel (pełny dostęp)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Utwórz i dodaj domownika</button>
                        </div>
                    </form>
                </div>
                
                <!-- OPCJA 2: Dodaj istniejącego użytkownika -->
                <?php if (!empty($availableUsers)): ?>
                <div class="section">
                    <h3>Dodaj istniejącego użytkownika</h3>
                    <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                        Dodaj do budżetu użytkownika, który już ma konto w systemie.
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Użytkownik <span style="color: #dc2626;">*</span></label>
                                <select name="user_id" required>
                                    <option value="">-- Wybierz użytkownika --</option>
                                    <?php foreach ($availableUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo e(getDisplayName($user)); ?> (<?php echo e($user['login']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Rola <span style="color: #dc2626;">*</span></label>
                                <select name="role" required>
                                    <option value="member">Członek (może dodawać i edytować)</option>
                                    <option value="viewer">Przeglądający (tylko odczyt)</option>
                                    <option value="owner">Właściciel (pełny dostęp)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Dodaj użytkownika</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <div class="section">
                    <h3>Lista domowników</h3>
                    
                    <?php if (empty($members)): ?>
                        <div class="empty-state">
                            Brak domowników. <?php if ($isOwner): ?>Dodaj pierwszego domownika powyżej.<?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Imię i nazwisko / Login</th>
                                    <th>Rola</th>
                                    <?php if ($isOwner): ?>
                                    <th>Akcje</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e(getDisplayName($member)); ?></strong>
                                        <?php if ($member['user_id'] == $currentUserId): ?>
                                        <span style="color: #667eea; font-size: 12px; margin-left: 8px;">(to Ty)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getRoleBadgeClass($member['role']); ?>">
                                            <?php echo getRoleLabel($member['role']); ?>
                                        </span>
                                    </td>
                                    <?php if ($isOwner): ?>
                                    <td>
                                        <?php if ($member['user_id'] != $currentUserId): ?>
                                        <!-- Zmień rolę -->
                                        <form method="POST" style="display: inline; margin-right: 5px;">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="user_id" value="<?php echo $member['user_id']; ?>">
                                            <select name="new_role" style="padding: 4px 8px; font-size: 13px; border: 1px solid #e5e7eb; border-radius: 4px;" onchange="this.form.submit()">
                                                <option value="">Zmień rolę...</option>
                                                <option value="owner" <?php if ($member['role'] === 'owner') echo 'disabled'; ?>>→ Właściciel</option>
                                                <option value="member" <?php if ($member['role'] === 'member') echo 'disabled'; ?>>→ Członek</option>
                                                <option value="viewer" <?php if ($member['role'] === 'viewer') echo 'disabled'; ?>>→ Przeglądający</option>
                                            </select>
                                        </form>
                                        
                                        <!-- Usuń -->
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Czy na pewno usunąć <?php echo e(getDisplayName($member)); ?> z budżetu?');">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="user_id" value="<?php echo $member['user_id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                Usuń
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span style="color: #999; font-size: 13px;">Nie możesz zmienić własnych uprawnień</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <?php if ($isOwner): ?>
                <div class="alert alert-info">
                    <strong>Zasady zarządzania domownikami:</strong><br>
                    • <strong>Właściciel</strong> ma pełny dostęp i może zarządzać domownikami<br>
                    • <strong>Członek</strong> może dodawać i edytować transakcje, rachunki, budżety<br>
                    • <strong>Przeglądający</strong> ma tylko dostęp do odczytu<br>
                    • Możesz utworzyć nowego użytkownika dedykowanego dla budżetu lub dodać istniejącego użytkownika z systemu<br>
                    • Nie możesz usunąć ostatniego właściciela<br>
                    • Nie możesz usunąć samego siebie — najpierw przekaż uprawnienia
                </div>
                <?php endif; ?>
    <?php hb_module_shell_end(); ?>
</body>
</html>
