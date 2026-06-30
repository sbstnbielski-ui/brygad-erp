<?php
/**
 * BRYGAD ERP - Eksport Finansów (UI)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Eksport Finansów</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
        }
        .breadcrumb {
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        .page-header p {
            color: #666;
            margin-top: 8px;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 40px;
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
        }
        
        /* Opcje zakresu */
        .range-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .range-option {
            padding: 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            background: white;
        }
        .range-option:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
        .range-option.active {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
        }
        .range-option input {
            display: none;
        }
        .range-option-icon {
            font-size: 24px;
            margin-bottom: 8px;
            color: #667eea;
        }
        .range-option-label {
            font-weight: 600;
            font-size: 14px;
            display: block;
        }
        .range-option-desc {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        
        /* Zakres dat */
        .custom-date-range {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .custom-date-range.show {
            display: block;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        /* Przycisk */
        .btn {
            padding: 16px 40px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 16px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .form-actions {
            margin-top: 30px;
            display: flex;
            gap: 15px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('finanse'); ?>">Finanse</a> / 
            Eksport JSON
        </div>
        
        <div class="page-header">
            <h2>Eksport Finansów do JSON</h2>
            <p>Pobierz dane finansowe w formacie JSON dla analizy AI</p>
        </div>
        
        <div class="card">
            <form method="GET" action="export-json-v2.php" id="exportForm">
                <div class="section-title">Wybierz zakres</div>
                
                <div class="range-options">
                    <label class="range-option active">
                        <input type="radio" name="range" value="day" checked>
                        <span class="range-option-icon">D</span>
                        <span class="range-option-label">Dzisiaj</span>
                        <span class="range-option-desc"><?php echo date('d.m.Y'); ?></span>
                    </label>
                    
                    <label class="range-option">
                        <input type="radio" name="range" value="week">
                        <span class="range-option-icon">W</span>
                        <span class="range-option-label">Ostatni tydzień</span>
                        <span class="range-option-desc">7 dni wstecz</span>
                    </label>
                    
                    <label class="range-option">
                        <input type="radio" name="range" value="month">
                        <span class="range-option-icon">M</span>
                        <span class="range-option-label">Bieżący miesiąc</span>
                        <span class="range-option-desc"><?php echo date('F Y'); ?></span>
                    </label>
                    
                    <label class="range-option">
                        <input type="radio" name="range" value="custom">
                        <span class="range-option-icon">R</span>
                        <span class="range-option-label">Zakres dat</span>
                        <span class="range-option-desc">Wybierz daty</span>
                    </label>
                </div>
                
                <div class="custom-date-range" id="customRange">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Data od</label>
                            <input type="date" name="date_from" value="<?php echo date('Y-m-01'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Data do</label>
                            <input type="date" name="date_to" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        Pobierz JSON
                    </button>
                    <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Obsługa wyboru zakresu
        document.querySelectorAll('.range-option input').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.range-option').forEach(opt => opt.classList.remove('active'));
                this.closest('.range-option').classList.add('active');
                
                // Pokaż/ukryj zakres dat
                const customRange = document.getElementById('customRange');
                if (this.value === 'custom') {
                    customRange.classList.add('show');
                } else {
                    customRange.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>
