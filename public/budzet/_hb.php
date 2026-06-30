<?php
/**
 * BRYGAD ERP - Budżet Domowy - Helpery
 * 
 * Funkcje pomocnicze dla modułu budżetu domowego
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

/**
 * Pobiera okres (YYYY-MM-01) z requesta lub zwraca bieżący miesiąc
 */
function hb_period_from_request(): string {
    if (isset($_GET['period']) && preg_match('/^\d{4}-\d{2}(-01)?$/', $_GET['period'])) {
        $period = $_GET['period'];
        // Normalizuj do YYYY-MM-01
        if (strlen($period) === 7) {
            $period .= '-01';
        }
        return $period;
    }
    return date('Y-m-01');
}

/**
 * Pobiera zakres dat z requesta na podstawie typu filtra
 * Zwraca: ['filter_type' => string, 'date_from' => string, 'date_to' => string, 'label' => string]
 */
function hb_date_range_from_request(): array {
    $filterType = $_GET['filter_type'] ?? 'month';
    $dateFrom = null;
    $dateTo = null;
    $label = '';
    
    switch ($filterType) {
        case 'day':
            $day = $_GET['day'] ?? date('Y-m-d');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $dateFrom = $day;
                $dateTo = $day;
                $label = date('d.m.Y', strtotime($day));
            } else {
                $dateFrom = date('Y-m-d');
                $dateTo = date('Y-m-d');
                $label = date('d.m.Y');
            }
            break;
            
        case 'month':
            $month = $_GET['month'] ?? date('Y-m');
            if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                $dateFrom = $month . '-01';
                $dateTo = date('Y-m-t', strtotime($dateFrom));
                $label = date('F Y', strtotime($dateFrom));
            } else {
                $dateFrom = date('Y-m-01');
                $dateTo = date('Y-m-t');
                $label = date('F Y');
            }
            break;
            
        case 'year':
            $year = $_GET['year'] ?? date('Y');
            if (preg_match('/^\d{4}$/', $year)) {
                $dateFrom = $year . '-01-01';
                $dateTo = $year . '-12-31';
                $label = 'Rok ' . $year;
            } else {
                $dateFrom = date('Y-01-01');
                $dateTo = date('Y-12-31');
                $label = 'Rok ' . date('Y');
            }
            break;
            
        case 'range':
            $from = $_GET['date_from'] ?? date('Y-m-01');
            $to = $_GET['date_to'] ?? date('Y-m-d');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $dateFrom = $from;
                $dateTo = $to;
                $label = date('d.m.Y', strtotime($from)) . ' - ' . date('d.m.Y', strtotime($to));
            } else {
                $dateFrom = date('Y-m-01');
                $dateTo = date('Y-m-d');
                $label = date('d.m.Y', strtotime($dateFrom)) . ' - ' . date('d.m.Y', strtotime($dateTo));
            }
            break;
            
        default:
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
            $label = date('F Y');
            $filterType = 'month';
    }
    
    return [
        'filter_type' => $filterType,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'label' => $label
    ];
}

/**
 * Zapewnia istnienie pozycji rachunków dla danego okresu
 * 
 * @param int $householdId
 * @param string $period YYYY-MM-01
 */
function hb_ensure_bill_items(int $householdId, string $period): void {
    $pdo = getDbConnection();
    
    try {
        // Pobierz aktywne rachunki
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                name,
                amount_type,
                fixed_amount,
                due_day
            FROM hb_bills
            WHERE household_id = ? AND is_active = 1
        ");
        $stmt->execute([$householdId]);
        $bills = $stmt->fetchAll();
        
        if (empty($bills)) {
            return;
        }
        
        // Pobierz liczbę dni w miesiącu
        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        
        foreach ($bills as $bill) {
            // Sprawdź czy pozycja już istnieje
            $checkStmt = $pdo->prepare("
                SELECT id FROM hb_bill_items 
                WHERE bill_id = ? AND period = ?
            ");
            $checkStmt->execute([$bill['id'], $period]);
            
            if ($checkStmt->fetch()) {
                continue; // Już istnieje
            }
            
            // Oblicz due_date
            $dueDay = min((int)$bill['due_day'], $daysInMonth);
            $dueDate = sprintf('%04d-%02d-%02d', $year, $month, $dueDay);
            
            // Oblicz amount_due
            $amountDue = $bill['amount_type'] === 'fixed' 
                ? (float)$bill['fixed_amount'] 
                : 0.00;
            
            // Wstaw pozycję
            $insertStmt = $pdo->prepare("
                INSERT INTO hb_bill_items 
                (bill_id, household_id, period, due_date, amount_due, amount_paid, status)
                VALUES (?, ?, ?, ?, ?, 0.00, 'unpaid')
            ");
            $insertStmt->execute([
                $bill['id'],
                $householdId,
                $period,
                $dueDate,
                $amountDue
            ]);
        }
    } catch (PDOException $e) {
        error_log("Error ensuring bill items: " . $e->getMessage());
    }
}

/**
 * Formatuje kwotę dla budżetu domowego
 */
function hb_format_money(float $amount, string $currency = 'PLN'): string {
    return number_format($amount, 2, ',', ' ') . ' ' . $currency;
}

/**
 * Pobiera nazwę kategorii
 */
function hb_get_category_name(PDO $pdo, ?int $categoryId): string {
    if (!$categoryId) {
        return 'Brak kategorii';
    }
    
    try {
        $stmt = $pdo->prepare("SELECT name FROM hb_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : 'Nieznana kategoria';
    } catch (PDOException $e) {
        return 'Błąd';
    }
}

/**
 * Pobiera nazwę konta
 */
function hb_get_account_name(PDO $pdo, ?int $accountId): string {
    if (!$accountId) {
        return 'Brak konta';
    }
    
    try {
        $stmt = $pdo->prepare("SELECT name FROM hb_accounts WHERE id = ?");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : 'Nieznane konto';
    } catch (PDOException $e) {
        return 'Błąd';
    }
}

/**
 * Formatuje typ transakcji
 */
function hb_format_direction(string $direction): string {
    $map = [
        'income' => 'Przychód',
        'expense' => 'Wydatek',
        'transfer' => 'Transfer'
    ];
    return $map[$direction] ?? $direction;
}

/**
 * Zwraca klasę CSS dla kierunku transakcji
 */
function hb_direction_class(string $direction): string {
    $map = [
        'income' => 'success',
        'expense' => 'danger',
        'transfer' => 'secondary'
    ];
    return $map[$direction] ?? 'secondary';
}

/**
 * Formatuje status rachunku
 */
function hb_format_bill_status(string $status): string {
    $map = [
        'unpaid' => 'Nieopłacony',
        'partial' => 'Częściowo opłacony',
        'paid' => 'Opłacony'
    ];
    return $map[$status] ?? $status;
}

/**
 * Zwraca klasę CSS dla statusu rachunku
 */
function hb_bill_status_class(string $status): string {
    $map = [
        'unpaid' => 'danger',
        'partial' => 'warning',
        'paid' => 'success'
    ];
    return $map[$status] ?? 'secondary';
}

