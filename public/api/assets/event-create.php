<?php
/**
 * BRYGAD ERP - API Create Asset Event (Termin/Serwis/UDT)
 * POST /api/assets/event-create
 * 
 * Dodaje termin/serwis/UDT do asset_events + opcjonalnie upload pliku (attachment_path)
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
    $event_category = $_POST['event_category'] ?? 'service';
    $title = trim($_POST['title'] ?? '');
    $due_date = $_POST['due_date'] ?? '';
    $remind_days_before = (int)($_POST['remind_days_before'] ?? 14);
    $cost_net = !empty($_POST['cost_net']) ? (float)$_POST['cost_net'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $status = 'planned'; // Nowe eventy zawsze 'planned'
    
    // Walidacja
    if ($asset_id <= 0) {
        throw new Exception('Nie wybrano zasobu');
    }
    if (empty($title)) {
        throw new Exception('Podaj tytuł zdarzenia');
    }
    if (empty($due_date)) {
        throw new Exception('Podaj datę terminu');
    }
    if (!in_array($event_category, ['technical', 'insurance', 'service', 'repair', 'other'])) {
        throw new Exception('Nieprawidłowa kategoria');
    }
    
    // Upload pliku (jeśli jest)
    $attachment_path = null;
    if (!empty($_FILES['attachment']['name'])) {
        $upload_dir = ROOT_PATH . '/uploads/assets/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        
        if (!in_array($file_ext, $allowed_ext)) {
            throw new Exception('Niedozwolone rozszerzenie pliku');
        }
        
        $filename = 'asset_' . $asset_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $target_path = $upload_dir . $filename;
        
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
            throw new Exception('Błąd przesyłania pliku');
        }
        
        $attachment_path = 'uploads/assets/' . $filename;
    }
    
    // Zapisz event
    $stmt = $pdo->prepare("
        INSERT INTO asset_events (
            asset_id, event_category, title, due_date, status,
            remind_days_before, attachment_path, cost_net, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $asset_id,
        $event_category,
        $title,
        $due_date,
        $status,
        $remind_days_before,
        $attachment_path,
        $cost_net,
        $notes
    ]);
    
    $event_id = $pdo->lastInsertId();
    
    logEvent("Dodano termin zasobu #$asset_id (event #$event_id): $title", 'INFO');
    
    echo json_encode([
        'success' => true,
        'message' => 'Termin dodany pomyślnie',
        'event_id' => $event_id
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logEvent("API assets/event-create error: " . $e->getMessage(), 'ERROR');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

