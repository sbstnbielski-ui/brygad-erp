<?php
/**
 * BRYGAD ERP - Budżet Domowy - Guard
 * 
 * Sprawdza uprawnienia do modułu budżetu domowego
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

// Wymagaj logowania
requireLogin();

// Pobierz household_id i rolę użytkownika
$pdo = getDbConnection();
$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT household_id, role 
        FROM hb_household_members 
        WHERE user_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $membership = $stmt->fetch();
    
    if (!$membership) {
        // Brak dostępu
        ?>
        <!DOCTYPE html>
        <html lang="pl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo e(APP_NAME); ?> - Brak dostępu</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f7fa;
                    color: #333;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                }
                .error-box {
                    background: white;
                    padding: 40px;
                    border-radius: 12px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    text-align: center;
                    max-width: 500px;
                }
                .error-box h1 {
                    font-size: 24px;
                    margin-bottom: 15px;
                    color: #ea580c;
                }
                .error-box p {
                    font-size: 14px;
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 25px;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: 600;
                    font-size: 14px;
                    transition: all 0.2s ease;
                }
                .btn:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
                }
            </style>
        </head>
        <body>
            <div class="error-box">
                <h1>Brak dostępu do Budżetu domowego</h1>
                <p>
                    Twoje konto nie jest przypisane do żadnego budżetu domowego. 
                    Skontaktuj się z administratorem lub osobą, która zarządza budżetem domowym,
                    aby uzyskać dostęp do tego modułu.
                </p>
                <a href="<?php echo url('dashboard'); ?>" class="btn">Powrót do panelu</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    // Zapisz dane membership w zmiennych globalnych
    define('HB_HOUSEHOLD_ID', $membership['household_id']);
    define('HB_USER_ROLE', $membership['role']);

    // Viewer może tylko przeglądać
    define('HB_CAN_EDIT', $membership['role'] !== 'viewer');

    // Owner widzi wszystko (w tym private innych domowników)
    define('HB_IS_OWNER', $membership['role'] === 'owner');

    // Czy użytkownik jest też pracownikiem/adminem ERP?
    // Jeśli nie → logo i "Panel główny" wracają do budżetu, nie do ERP
    $_hbIsWorker = !empty($_SESSION['worker_id']) || ($_SESSION['role'] ?? '') === 'admin';
    define('HB_HOME_URL',   $_hbIsWorker ? url('dashboard') : url('budzet'));
    define('HB_HOME_LABEL', $_hbIsWorker ? 'Panel główny'  : 'Budżet domowy');
    
} catch (PDOException $e) {
    error_log("Household guard error: " . $e->getMessage());
    die('Wystąpił błąd podczas sprawdzania uprawnień.');
}

