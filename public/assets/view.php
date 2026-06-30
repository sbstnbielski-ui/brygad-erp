<?php
/**
 * BRYGAD ERP - Szczegóły zasobu (ASSET)
 * Zakładki: Terminy i dokumenty | Rezerwacje
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$asset_id = (int)($_GET['id'] ?? 0);

if ($asset_id <= 0) {
    header('Location: ' . url('assets'));
    exit;
}

// Pobierz zasób
try {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        header('Location: ' . url('assets'));
        exit;
    }
} catch (PDOException $e) {
    die('Błąd pobierania zasobu');
}

// Zakładka aktywna
$activeTab = $_GET['tab'] ?? 'events';

// Pobierz terminy (asset_events)
$events = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM asset_events 
        WHERE asset_id = ? 
        ORDER BY due_date DESC
    ");
    $stmt->execute([$asset_id]);
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania terminów: " . $e->getMessage(), 'ERROR');
}

// Pobierz rezerwacje (asset_bookings)
$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            ab.*,
            w.first_name, w.last_name,
            p.name as project_name
        FROM asset_bookings ab
        LEFT JOIN workers w ON w.id = ab.worker_id
        LEFT JOIN projects p ON p.id = ab.project_id
        WHERE ab.asset_id = ?
        ORDER BY ab.start_date DESC
    ");
    $stmt->execute([$asset_id]);
    $bookings = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania rezerwacji: " . $e->getMessage(), 'ERROR');
}

$assetTypes = [
    'car_passenger' => 'Auto osobowe',
    'car_delivery' => 'Auto dostawcze',
    'truck' => 'Ciężarówka',
    'excavator' => 'Koparka',
    'lift' => 'Podnośnik',
    'tool' => 'Narzędzie',
    'other' => 'Inne'
];

$eventCategories = [
    'technical' => 'Przegląd techniczny',
    'insurance' => 'Ubezpieczenie',
    'service' => 'Serwis',
    'repair' => 'Naprawa',
    'other' => 'Inne'
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($asset['name']); ?> - <?php echo e(APP_NAME); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 8px;
        }
        
        .page-header .subtitle {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Card z informacjami */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h2 {
            font-size: 18px;
            color: #111827;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .info-item label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: 600;
        }
        
        .info-item .value {
            font-size: 16px;
            color: #111827;
            font-weight: 500;
        }
        
        /* Zakładki */
        .tabs {
            display: flex;
            gap: 8px;
            background: white;
            padding: 4px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }
        
        .tabs a {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.2s ease;
        }
        
        .tabs a:hover {
            background: #f3f4f6;
            color: #374151;
        }
        
        .tabs a.active {
            background: #667eea;
            color: white;
        }
        
        /* Tabela terminów */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f9fafb;
        }
        
        th {
            padding: 12px 20px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #f9fafb;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.planned {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge.done {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .status-badge.overdue {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .status-badge.draft {
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .status-badge.confirmed {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge.completed {
            background: #dcfce7;
            color: #16a34a;
        }
        
        .status-badge.cancelled {
            background: #fef3c7;
            color: #d97706;
        }
        
        .date-overdue {
            color: #dc2626;
            font-weight: 600;
        }
        
        /* Button */
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.2s;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        .btn-secondary {
            background: white;
            color: #6b7280;
            border-color: #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 15px;
        }
        
        .actions-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1><?php echo e($asset['name']); ?></h1>
            <div class="subtitle">
                <?php echo $assetTypes[$asset['type']] ?? $asset['type']; ?>
                <?php if ($asset['reg_number']): ?>
                    • <?php echo e($asset['reg_number']); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Informacje podstawowe -->
        <div class="card">
            <div class="card-header">
                <h2>Informacje podstawowe</h2>
                <a href="<?php echo url('assets.edit', ['id' => $asset_id]); ?>" class="btn btn-secondary">Edytuj</a>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <label>Typ</label>
                        <div class="value"><?php echo $assetTypes[$asset['type']] ?? $asset['type']; ?></div>
                    </div>
                    <div class="info-item">
                        <label>Nr rejestracyjny / Seryjny</label>
                        <div class="value">
                            <?php echo e($asset['reg_number'] ?: $asset['serial_number'] ?: '-'); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <label>Stan licznika</label>
                        <div class="value">
                            <?php if ($asset['usage_unit'] !== 'none'): ?>
                                <?php echo number_format($asset['current_usage'], 0, ',', ' '); ?> <?php echo $asset['usage_unit']; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <label>Rok produkcji</label>
                        <div class="value"><?php echo $asset['production_year'] ?: '-'; ?></div>
                    </div>
                    <div class="info-item">
                        <label>Data zakupu</label>
                        <div class="value"><?php echo $asset['purchase_date'] ? formatDate($asset['purchase_date']) : '-'; ?></div>
                    </div>
                    <div class="info-item">
                        <label>Status</label>
                        <div class="value">
                            <?php if ($asset['is_active']): ?>
                                <span style="color: #16a34a;">● Aktywny</span>
                            <?php else: ?>
                                <span style="color: #9ca3af;">● Nieaktywny</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($asset['notes']): ?>
                    <div style="margin-top: 20px;">
                        <label style="display: block; font-size: 11px; text-transform: uppercase; color: #6b7280; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 600;">Notatki</label>
                        <div style="font-size: 14px; color: #6b7280; line-height: 1.6;">
                            <?php echo nl2br(e($asset['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Zakładki -->
        <div class="tabs">
            <a href="?id=<?php echo $asset_id; ?>&tab=events" class="<?php echo $activeTab === 'events' ? 'active' : ''; ?>">
                Terminy i dokumenty (<?php echo count($events); ?>)
            </a>
            <a href="?id=<?php echo $asset_id; ?>&tab=bookings" class="<?php echo $activeTab === 'bookings' ? 'active' : ''; ?>">
                Rezerwacje (<?php echo count($bookings); ?>)
            </a>
        </div>

        <!-- Zakładka: Terminy -->
        <?php if ($activeTab === 'events'): ?>
            <div class="actions-bar">
                <button onclick="showAddEventForm()" class="btn btn-primary">+ Dodaj termin/serwis</button>
                <a href="<?php echo url('assets'); ?>" class="btn btn-secondary">← Powrót</a>
            </div>
            
            <div class="card">
                <?php if (empty($events)): ?>
                    <div class="no-data">
                        <p>Brak terminów dla tego zasobu.</p>
                        <button onclick="showAddEventForm()" class="btn btn-primary" style="margin-top: 15px;">Dodaj pierwszy termin</button>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Kategoria</th>
                                <th>Tytuł</th>
                                <th>Data terminu</th>
                                <th>Status</th>
                                <th>Koszt netto</th>
                                <th>Załącznik</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                $isOverdue = ($event['status'] === 'planned' && strtotime($event['due_date']) < time());
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $eventCategories[$event['event_category']] ?? $event['event_category']; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo e($event['title']); ?></strong>
                                        <?php if ($event['notes']): ?>
                                            <div style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                                                <?php echo e(mb_substr($event['notes'], 0, 60)); ?><?php echo mb_strlen($event['notes']) > 60 ? '...' : ''; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $isOverdue ? 'date-overdue' : ''; ?>">
                                            <?php echo formatDate($event['due_date']); ?>
                                            <?php if ($isOverdue): ?>
                                                (po terminie)
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $event['status']; ?>">
                                            <?php 
                                            $statusLabels = ['planned' => 'Zaplanowane', 'done' => 'Wykonane', 'overdue' => 'Po terminie'];
                                            echo $statusLabels[$event['status']] ?? $event['status'];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($event['cost_net']): ?>
                                            <?php echo number_format($event['cost_net'], 2, ',', ' '); ?> PLN
                                        <?php else: ?>
                                            <span style="color: #d1d5db;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($event['attachment_path']): ?>
                                            <a href="/<?php echo e($event['attachment_path']); ?>" target="_blank" style="color: #667eea; text-decoration: none;">
                                                📄 Plik
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #d1d5db;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Zakładka: Rezerwacje -->
        <?php if ($activeTab === 'bookings'): ?>
            <div class="actions-bar">
                <button onclick="showAddBookingForm()" class="btn btn-primary">+ Dodaj rezerwację</button>
                <a href="<?php echo url('assets'); ?>" class="btn btn-secondary">← Powrót</a>
            </div>
            
            <div class="card">
                <?php if (empty($bookings)): ?>
                    <div class="no-data">
                        <p>Brak rezerwacji dla tego zasobu.</p>
                        <button onclick="showAddBookingForm()" class="btn btn-primary" style="margin-top: 15px;">Dodaj pierwszą rezerwację</button>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Okres</th>
                                <th>Klient / Projekt</th>
                                <th>Pracownik</th>
                                <th>Opis</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('d.m.Y', strtotime($booking['start_date'])); ?></strong>
                                        <span style="color: #9ca3af;"> - </span>
                                        <strong><?php echo date('d.m.Y', strtotime($booking['end_date'])); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($booking['project_name']): ?>
                                            <strong><?php echo e($booking['project_name']); ?></strong>
                                        <?php elseif ($booking['customer_name']): ?>
                                            <?php echo e($booking['customer_name']); ?>
                                        <?php else: ?>
                                            <span style="color: #d1d5db;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($booking['first_name']): ?>
                                            <?php echo e($booking['first_name'] . ' ' . $booking['last_name']); ?>
                                        <?php else: ?>
                                            <span style="color: #d1d5db;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($booking['description']): ?>
                                            <span style="font-size: 13px; color: #6b7280;">
                                                <?php echo e(mb_substr($booking['description'], 0, 50)); ?><?php echo mb_strlen($booking['description']) > 50 ? '...' : ''; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #d1d5db;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $booking['status']; ?>">
                                            <?php 
                                            $statusLabels = ['draft' => 'Projekt', 'confirmed' => 'Potwierdzone', 'completed' => 'Zakończone', 'cancelled' => 'Anulowane'];
                                            echo $statusLabels[$booking['status']] ?? $booking['status'];
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function showAddEventForm() {
            alert('Formularz dodawania terminu zostanie zaimplementowany w następnej iteracji.\nNa razie możesz dodawać terminy bezpośrednio przez API.');
        }
        
        function showAddBookingForm() {
            alert('Formularz dodawania rezerwacji zostanie zaimplementowany w następnej iteracji.\nNa razie możesz dodawać rezerwacje bezpośrednio przez API.');
        }
    </script>
</body>
</html>

