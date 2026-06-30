<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Projektu
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$project_id = $_POST['project_id'] ?? null;

if (!$project_id) {
    header("Location: /projekty/index.php");
    exit;
}

// Pobierz dane projektu (włącznie z project_type)
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    header("Location: /projekty/index.php");
    exit;
}

// Zapamiętaj typ projektu, żeby po usunięciu wrócić do właściwego widoku
$project_type = $project['project_type'] ?? 'standard';

// Sprawdź czy projekt ma powiązane dane
$stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM work_logs WHERE project_id = ?) as work_logs_count,
        (SELECT COUNT(*) FROM worker_expenses WHERE project_id = ?) as expenses_count,
        (SELECT COUNT(*) FROM documents WHERE project_id = ?) as documents_count,
        (SELECT COUNT(*) FROM project_cost_nodes WHERE project_id = ?) as cost_nodes_count,
        (SELECT COUNT(*) FROM project_revenues WHERE project_id = ?) as revenues_count
");
$stmt->execute([$project_id, $project_id, $project_id, $project_id, $project_id]);
$usage = $stmt->fetch();

$has_data = ($usage['work_logs_count'] > 0 || $usage['expenses_count'] > 0 || 
             $usage['documents_count'] > 0 || $usage['cost_nodes_count'] > 0 || 
             $usage['revenues_count'] > 0);

try {
    $pdo->beginTransaction();
    
    // Jeśli projekt ma dane, usuń wszystko kaskadowo
    if ($has_data) {
        // Usuń work_logs
        $stmt = $pdo->prepare("DELETE FROM work_logs WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // Usuń worker_expenses
        $stmt = $pdo->prepare("DELETE FROM worker_expenses WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // Usuń documents (faktury/materiały)
        $stmt = $pdo->prepare("DELETE FROM documents WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // Usuń project_cost_nodes (etapy)
        $stmt = $pdo->prepare("DELETE FROM project_cost_nodes WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // Usuń project_revenues (umowy/aneksy)
        $stmt = $pdo->prepare("DELETE FROM project_revenues WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        logEvent("KASKADOWE USUWANIE projektu {$project['name']}: work_logs={$usage['work_logs_count']}, expenses={$usage['expenses_count']}, documents={$usage['documents_count']}, nodes={$usage['cost_nodes_count']}, revenues={$usage['revenues_count']}", 'WARNING');
    }
    
    // Usuń projekt
    $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    
    $pdo->commit();
    
    logEvent("Usunięto projekt: {$project['name']} (ID: {$project_id}, typ: {$project_type})", 'WARNING');
    
    // Przekieruj do właściwego widoku (duże projekty vs mikroprojekty)
    header("Location: /projekty/index.php?success=deleted&project_type=" . urlencode($project_type));
    exit;
    
} catch (PDOException $e) {
    $pdo->rollBack();
    logEvent("Błąd usuwania projektu ID {$project_id}: " . $e->getMessage(), 'ERROR');
    header("Location: /projekty/view.php?id=" . $project_id . "&error=delete_failed");
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd ogólny usuwania projektu ID {$project_id}: " . $e->getMessage(), 'ERROR');
    header("Location: /projekty/view.php?id=" . $project_id . "&error=delete_failed");
    exit;
}
