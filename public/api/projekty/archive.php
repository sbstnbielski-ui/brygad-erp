<?php
/**
 * BRYGAD ERP v3.0 - API: Archiwizacja/przywracanie projektu
 * 
 * POST /api/projekty/archive.php
 * Parametry:
 *   - project_id: int - ID projektu
 *   - action: string - 'archive' lub 'restore'
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

// Sprawdź autoryzację
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

// Tylko POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

$projectId = (int)($_POST['project_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak ID projektu']);
    exit;
}

if (!in_array($action, ['archive', 'restore'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowa akcja']);
    exit;
}

$pdo = getDbConnection();

try {
    // Sprawdź czy projekt istnieje
    $stmt = $pdo->prepare("SELECT id, name, status, archived_at FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    
    if (!$project) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Projekt nie istnieje']);
        exit;
    }
    
    if ($action === 'archive') {
        // Archiwizuj projekt
        if ($project['archived_at']) {
            echo json_encode(['success' => false, 'error' => 'Projekt jest już zarchiwizowany']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE projects SET archived_at = NOW(), status = 'finished' WHERE id = ?");
        $stmt->execute([$projectId]);
        
        logEvent("Zarchiwizowano projekt: {$project['name']} (ID: {$projectId})", 'INFO');
        
        echo json_encode([
            'success' => true, 
            'message' => 'Projekt został zarchiwizowany',
            'project_id' => $projectId,
            'archived_at' => date('Y-m-d H:i:s')
        ]);
        
    } else { // restore
        // Przywróć projekt
        if (!$project['archived_at']) {
            echo json_encode(['success' => false, 'error' => 'Projekt nie jest zarchiwizowany']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE projects SET archived_at = NULL, status = 'active' WHERE id = ?");
        $stmt->execute([$projectId]);
        
        logEvent("Przywrócono projekt z archiwum: {$project['name']} (ID: {$projectId})", 'INFO');
        
        echo json_encode([
            'success' => true, 
            'message' => 'Projekt został przywrócony',
            'project_id' => $projectId
        ]);
    }
    
} catch (PDOException $e) {
    logEvent("Błąd archiwizacji projektu: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}


