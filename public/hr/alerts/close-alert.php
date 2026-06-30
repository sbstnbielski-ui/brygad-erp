<?php
require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$alertId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($alertId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT worker_id FROM hr_alerts WHERE id = ?");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch();
        
        if ($alert) {
            $stmt = $pdo->prepare("
                UPDATE hr_alerts 
                SET status = 'closed', closed_at = NOW(), closed_note = 'Zamknięty ręcznie', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$alertId]);
            
            logEvent("Zamknięto alert ID $alertId", 'INFO');
            
            header("Location: " . url('hr.workers.documents', ['worker_id' => $alert['worker_id']]));
            exit;
        }
    } catch (PDOException $e) {
        logEvent("Błąd zamykania alertu: " . $e->getMessage(), 'ERROR');
    }
}

header('Location: ' . url('hr.workers'));
exit;

