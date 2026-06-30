<?php
/**
 * BRYGAD ERP - API Create Asset Booking
 * POST /api/assets/booking-create
 * 
 * WALIDACJA: Blokuje zapis jeśli istnieje konflikt z asset_events (status='planned')
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    // Odbierz dane z POST
    $asset_id = (int)($_POST['asset_id'] ?? 0);
    $worker_id = !empty($_POST['worker_id']) ? (int)$_POST['worker_id'] : null;
    $project_id = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $customer_name = trim($_POST['customer_name'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    
    // Walidacja
    if ($asset_id <= 0) {
        throw new Exception('Nie wybrano zasobu');
    }
    if (empty($start_date) || empty($end_date)) {
        throw new Exception('Podaj daty rozpoczęcia i zakończenia');
    }
    if (empty($customer_name) && empty($project_id)) {
        throw new Exception('Podaj nazwę klienta lub wybierz projekt');
    }
    
    // KLUCZOWA WALIDACJA: Sprawdź czy istnieje konflikt z asset_events (status='planned')
    $checkConflict = $pdo->prepare("
        SELECT id, title, due_date
        FROM asset_events
        WHERE asset_id = ?
        AND status = 'planned'
        AND due_date BETWEEN ? AND ?
        LIMIT 1
    ");
    $checkConflict->execute([$asset_id, $start_date, $end_date]);
    $conflict = $checkConflict->fetch();
    
    if ($conflict) {
        http_response_code(409); // Conflict
        echo json_encode([
            'success' => false,
            'error' => 'Konflikt z serwisem',
            'message' => sprintf(
                'W tym czasie zaplanowano serwis: "%s" w dniu %s. Zmień termin rezerwacji lub przesuń serwis.',
                $conflict['title'],
                $conflict['due_date']
            ),
            'conflict_event_id' => $conflict['id']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Brak konfliktu - zapisz rezerwację
    $stmt = $pdo->prepare("
        INSERT INTO asset_bookings (
            asset_id, worker_id, project_id, customer_name,
            start_date, end_date, status, description, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $asset_id,
        $worker_id,
        $project_id,
        $customer_name,
        $start_date,
        $end_date,
        $status,
        $description
    ]);
    
    $booking_id = $pdo->lastInsertId();
    
    logEvent("Dodano rezerwację zasobu #$asset_id (booking #$booking_id)", 'INFO');
    
    echo json_encode([
        'success' => true,
        'message' => 'Rezerwacja dodana pomyślnie',
        'booking_id' => $booking_id
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logEvent("API assets/booking-create error: " . $e->getMessage(), 'ERROR');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

