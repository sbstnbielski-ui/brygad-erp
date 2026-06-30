<?php
/**
 * BRYGAD ERP v3.0 - Masowe Przypisanie Stawek
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('hr.workers'));
    exit;
}

$projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$rateRegular = trim($_POST['rate_regular'] ?? '');
$rateSaturday = !empty($_POST['rate_saturday']) ? trim($_POST['rate_saturday']) : null;
$rateSunday = !empty($_POST['rate_sunday']) ? trim($_POST['rate_sunday']) : null;
$rateNight = !empty($_POST['rate_night']) ? trim($_POST['rate_night']) : null;
$rateOvertime = !empty($_POST['rate_overtime']) ? trim($_POST['rate_overtime']) : null;
$overwrite = isset($_POST['overwrite']);
$applyToAll = isset($_POST['apply_to_all']);
$selectedWorkerIds = !empty($_POST['selected_worker_ids']) ? $_POST['selected_worker_ids'] : [];

if (!$projectId) {
    $errors[] = 'Projekt jest wymagany.';
}

if (empty($rateRegular) || !is_numeric($rateRegular) || $rateRegular < 0) {
    $errors[] = 'Stawka podstawowa musi być liczbą większą lub równą 0.';
}

if ($rateSaturday !== null && (!is_numeric($rateSaturday) || $rateSaturday < 0)) {
    $errors[] = 'Stawka za sobotę musi być liczbą większą lub równą 0.';
}

if ($rateSunday !== null && (!is_numeric($rateSunday) || $rateSunday < 0)) {
    $errors[] = 'Stawka za niedzielę musi być liczbą większą lub równą 0.';
}

if ($rateNight !== null && (!is_numeric($rateNight) || $rateNight < 0)) {
    $errors[] = 'Stawka za pracę nocną musi być liczbą większą lub równą 0.';
}

if ($rateOvertime !== null && (!is_numeric($rateOvertime) || $rateOvertime < 0)) {
    $errors[] = 'Stawka za nadgodziny musi być liczbą większą lub równą 0.';
}

// Walidacja wyboru pracowników
if (!$applyToAll && empty($selectedWorkerIds)) {
    $errors[] = 'Musisz wybrać przynajmniej jednego pracownika lub zaznaczyć opcję "Zastosuj do wszystkich pracowników".';
}

if (empty($errors)) {
    try {
        $pdo->beginTransaction();
        
        $validFrom = date('Y-m-d');
        $updatedCount = 0;
        $insertedCount = 0;
        
        // Określenie listy pracowników
        if ($applyToAll) {
            // Wszyscy aktywni pracownicy
            $stmt = $pdo->query("SELECT id FROM workers WHERE is_active = 1");
            $workers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Tylko wybrani pracownicy (z walidacją że są aktywni)
            $placeholders = str_repeat('?,', count($selectedWorkerIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT id FROM workers WHERE id IN ($placeholders) AND is_active = 1");
            $stmt->execute($selectedWorkerIds);
            $workers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Sprawdzenie czy wszyscy wybrani pracownicy są aktywni
            if (count($workers) !== count($selectedWorkerIds)) {
                throw new Exception('Niektórzy wybrani pracownicy są nieaktywni.');
            }
        }
        
        foreach ($workers as $workerId) {
            if ($overwrite) {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM worker_rates 
                    WHERE worker_id = ? AND project_id = ? AND valid_to IS NULL
                    LIMIT 1
                ");
                $checkStmt->execute([$workerId, $projectId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $updateStmt = $pdo->prepare("
                        UPDATE worker_rates 
                        SET base_rate = ?, 
                            saturday_rate = ?, 
                            sunday_rate = ?, 
                            night_rate = ?,
                            overtime_rate = ?
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $rateRegular,
                        $rateSaturday,
                        $rateSunday,
                        $rateNight,
                        $rateOvertime,
                        $existing['id']
                    ]);
                    $updatedCount++;
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO worker_rates 
                        (worker_id, project_id, base_rate, saturday_rate, sunday_rate, night_rate, overtime_rate, valid_from, valid_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
                    ");
                    $insertStmt->execute([
                        $workerId,
                        $projectId,
                        $rateRegular,
                        $rateSaturday,
                        $rateSunday,
                        $rateNight,
                        $rateOvertime,
                        $validFrom
                    ]);
                    $insertedCount++;
                }
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM worker_rates 
                    WHERE worker_id = ? AND project_id = ? AND valid_to IS NULL
                    LIMIT 1
                ");
                $checkStmt->execute([$workerId, $projectId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing) {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO worker_rates 
                        (worker_id, project_id, base_rate, saturday_rate, sunday_rate, night_rate, overtime_rate, valid_from, valid_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
                    ");
                    $insertStmt->execute([
                        $workerId,
                        $projectId,
                        $rateRegular,
                        $rateSaturday,
                        $rateSunday,
                        $rateNight,
                        $rateOvertime,
                        $validFrom
                    ]);
                    $insertedCount++;
                }
            }
        }
        
        $pdo->commit();
        
        $action = $overwrite ? 'zaktualizowano' : 'dodano';
        $total = $updatedCount + $insertedCount;
        $scope = $applyToAll ? 'wszystkich pracowników' : count($workers) . ' wybranych pracowników';
        logEvent("Masowe przypisanie stawek: $action $total stawek dla projektu ID $projectId ($scope)", 'INFO');
        
        header("Location: " . url('hr.workers') . "?success=bulk_rates&count=$total");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors[] = 'Błąd przypisywania stawek. Spróbuj ponownie.';
        logEvent("Błąd masowego przypisywania stawek: " . $e->getMessage(), 'ERROR');
    }
}

$_SESSION['bulk_rates_errors'] = $errors;
header("Location: " . url('hr.workers') . "?error=bulk_rates");
exit;

