<?php
/**
 * BRYGAD ERP - Kalendarz Zasobów (hybrid)
 * Pokazuje eventy z asset_events + asset_bookings
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Domyślny okres: bieżący miesiąc
$currentMonth = date('Y-m');
$from = date('Y-m-01');
$to = date('Y-m-t');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalendarz Zasobów - <?php echo e(APP_NAME); ?></title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            font-size: 32px;
            color: #333;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .event-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .event-item {
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            transition: all 0.2s;
        }
        
        .event-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-color: #d1d5db;
        }
        
        .event-item.maintenance {
            border-left: 4px solid #f59e0b;
            background: #fefce8;
        }
        
        .event-item.work {
            border-left: 4px solid #3b82f6;
            background: #eff6ff;
        }
        
        .event-content {
            flex: 1;
        }
        
        .event-title {
            font-weight: 600;
            color: #111827;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .event-details {
            font-size: 13px;
            color: #6b7280;
        }
        
        .event-date {
            font-size: 13px;
            color: #9ca3af;
            text-align: right;
            white-space: nowrap;
        }
        
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
        }
        
        .btn-secondary {
            background: white;
            color: #6b7280;
            border-color: #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        .loading {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>📅 Kalendarz Zasobów</h1>
            <a href="<?php echo url('assets'); ?>" class="btn btn-secondary">← Powrót</a>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Wydarzenia w miesiącu: <?php echo date('m/Y'); ?></h2>
                <div>
                    <span style="margin-right: 20px;">🟡 Serwis/Termin</span>
                    <span>🔵 Rezerwacja</span>
                </div>
            </div>
            <div class="card-body">
                <div id="calendar-events" class="event-list">
                    <div class="loading">Ładowanie wydarzeń...</div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Fetch events from API
        async function loadCalendar() {
            try {
                const from = '<?php echo $from; ?>';
                const to = '<?php echo $to; ?>';
                
                const response = await fetch(`/api/assets/calendar.php?from=${from}&to=${to}`);
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || 'Błąd pobierania danych');
                }
                
                const container = document.getElementById('calendar-events');
                const events = result.data;
                
                if (events.length === 0) {
                    container.innerHTML = '<div class="loading">Brak wydarzeń w tym miesiącu.</div>';
                    return;
                }
                
                container.innerHTML = '';
                
                events.forEach(event => {
                    const item = document.createElement('div');
                    item.className = `event-item ${event.kind}`;
                    
                    const kindLabel = event.kind === 'maintenance' ? '🔧 Serwis/Termin' : '🚗 Rezerwacja';
                    const dateText = event.start === event.end 
                        ? formatDate(event.start)
                        : `${formatDate(event.start)} - ${formatDate(event.end)}`;
                    
                    let details = `${kindLabel} • ${event.asset_name}`;
                    if (event.kind === 'maintenance') {
                        details += ` • ${event.event_category || ''}`;
                    } else if (event.worker_name) {
                        details += ` • ${event.worker_name}`;
                    }
                    
                    item.innerHTML = `
                        <div class="event-content">
                            <div class="event-title">${escapeHtml(event.title)}</div>
                            <div class="event-details">${details}</div>
                        </div>
                        <div class="event-date">${dateText}</div>
                    `;
                    
                    container.appendChild(item);
                });
                
            } catch (error) {
                console.error('Calendar error:', error);
                document.getElementById('calendar-events').innerHTML = 
                    '<div class="loading" style="color: #dc2626;">Błąd ładowania kalendarza: ' + error.message + '</div>';
            }
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('pl-PL', {day: '2-digit', month: '2-digit', year: 'numeric'});
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Load on page load
        document.addEventListener('DOMContentLoaded', loadCalendar);
    </script>
</body>
</html>

