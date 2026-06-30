<?php
/**
 * BRYGAD ERP - CRON: Generowanie alertów HR
 * Uruchom raz dziennie: 0 8 * * * php /path/to/cron_hr_alerts.php
 */

require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół

$pdo = getDbConnection();

try {
    // Pobierz aktywne dokumenty z datą ważności
    $stmt = $pdo->query("
        SELECT 
            wd.id,
            wd.worker_id,
            wd.title,
            wd.valid_to,
            wd.reminder_days,
            dt.name as type_name,
            dt.default_reminder_days
        FROM worker_documents wd
        JOIN document_types dt ON wd.document_type_id = dt.id
        WHERE wd.status = 'active' 
        AND wd.valid_to IS NOT NULL
        AND dt.has_validity = 1
    ");
    
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $today = date('Y-m-d');
    $alertsCreated = 0;
    $alertsUpdated = 0;
    
    foreach ($documents as $doc) {
        $validTo = $doc['valid_to'];
        $reminderDays = $doc['reminder_days'] ?? $doc['default_reminder_days'] ?? 30;
        $remindAt = date('Y-m-d', strtotime($validTo . " -$reminderDays days"));
        
        // Określ typ alertu
        if ($validTo < $today) {
            $alertType = 'expired';
        } elseif ($remindAt <= $today) {
            $alertType = 'expiring';
        } else {
            continue; // Jeszcze nie czas na alert
        }
        
        // Sprawdź czy alert już istnieje
        $stmt = $pdo->prepare("
            SELECT id, status FROM hr_alerts 
            WHERE document_id = ? AND alert_type = ?
        ");
        $stmt->execute([$doc['id'], $alertType]);
        $existingAlert = $stmt->fetch();
        
        if ($existingAlert) {
            // Aktualizuj istniejący alert (jeśli zamknięty, otwórz ponownie)
            if ($existingAlert['status'] === 'closed') {
                $stmt = $pdo->prepare("
                    UPDATE hr_alerts 
                    SET status = 'open', 
                        due_date = ?, 
                        remind_at = ?,
                        closed_at = NULL,
                        closed_note = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$validTo, $remindAt, $existingAlert['id']]);
                $alertsUpdated++;
            }
        } else {
            // Utwórz nowy alert (trigger automatycznie ustawi worker_id)
            $stmt = $pdo->prepare("
                INSERT INTO hr_alerts 
                (document_id, alert_type, due_date, remind_at, status, created_at)
                VALUES (?, ?, ?, ?, 'open', NOW())
            ");
            $stmt->execute([$doc['id'], $alertType, $validTo, $remindAt]);
            $alertsCreated++;
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] HR Alerts CRON: Utworzono $alertsCreated, zaktualizowano $alertsUpdated\n";
    logEvent("CRON HR Alerts: Utworzono $alertsCreated, zaktualizowano $alertsUpdated", 'INFO');
    
} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    logEvent("CRON HR Alerts ERROR: " . $e->getMessage(), 'ERROR');
}

