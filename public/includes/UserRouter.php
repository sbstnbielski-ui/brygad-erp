<?php
/**
 * BRYGAD ERP - UserRouter
 *
 * Centralny punkt decyzyjny routingu po logowaniu.
 *
 * Reguły biznesowe (obowiązujące):
 *  - Nie ma scenariusza "OBA".
 *  - Jeśli user jest w hb_household_members  → budzet/index.php
 *  - Jeśli NIE jest w household:
 *      admin  → dashboard.php  (ERP admin)
 *      worker → dashboard.php  (ERP worker)
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

class UserRouter
{
    /**
     * Zwraca URL przekierowania na podstawie danych usera.
     *
     * @param  PDO    $pdo
     * @param  int    $userId   ID z tabeli users
     * @param  string $role     'admin' | 'worker'
     * @return string           Względny URL (np. 'budzet/index.php')
     */
    public static function resolvePostLoginRedirect(PDO $pdo, int $userId, string $role): string
    {
        // Admin ZAWSZE trafia do centrum ERP - bez wyjątku
        if ($role === 'admin') {
            logEvent("Login routing → ERP admin dashboard (admin override)", 'INFO');
            return 'dashboard.php';
        }

        // Dla pozostałych ról: sprawdź przynależność do household
        $stmt = $pdo->prepare(
            "SELECT household_id FROM hb_household_members WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $membership = $stmt->fetch();

        if ($membership) {
            // User (nie-admin) ma household → budżet domowy
            logEvent("Login routing → budzet (household_id={$membership['household_id']})", 'INFO');
            return 'budzet/index.php';
        }

        // Worker bez household → ERP dashboard
        logEvent("Login routing → ERP worker dashboard", 'INFO');
        return 'dashboard.php';
    }
}

