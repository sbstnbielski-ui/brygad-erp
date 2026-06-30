<?php
/**
 * BRYGAD ERP v3.0 - Stawki Pracowników (Biblia)
 * Centralne zarządzanie stawkami: GLOBAL / PROJECT / STAGE
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może zarządzać stawkami

$pdo = getDbConnection();

// Filtry
$filterProject = isset($_GET['project']) ? (int)$_GET['project'] : 0;
$filterStage = isset($_GET['stage']) ? (int)$_GET['stage'] : 0;

// Pobierz projekty
try {
    $stmt = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active', 'planned') ORDER BY name");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    $projects = [];
}

// Pobierz etapy (jeśli wybrany projekt)
$stages = [];
if ($filterProject > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM project_cost_nodes WHERE project_id = ? AND is_active = 1 ORDER BY sort_order, name");
        $stmt->execute([$filterProject]);
        $stages = $stmt->fetchAll();
    } catch (PDOException $e) {
        $stages = [];
    }
}

// Pobierz pracowników
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll();
} catch (PDOException $e) {
    $workers = [];
}

// Pobierz stawki według filtrów
$rates = [];
foreach ($workers as $worker) {
    $workerId = $worker['id'];
    
    // Hierarchia: STAGE > PROJECT > GLOBAL
    $scopeType = 'GLOBAL';
    $scopeId = null;
    
    if ($filterStage > 0) {
        $scopeType = 'STAGE';
        $scopeId = $filterStage;
    } elseif ($filterProject > 0) {
        $scopeType = 'PROJECT';
        $scopeId = $filterProject;
    }
    
    // Pobierz stawkę dla danego zakresu
    $stmt = $pdo->prepare("
        SELECT * FROM worker_rates
        WHERE worker_id = ? 
          AND scope_type = ?
          AND (scope_id = ? OR (? IS NULL AND scope_id IS NULL))
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$workerId, $scopeType, $scopeId, $scopeId]);
    $rate = $stmt->fetch();
    
    // Jeśli nie ma stawki w tym zakresie, spróbuj pobrać z niższego poziomu
    if (!$rate) {
        if ($scopeType === 'STAGE') {
            // Próbuj PROJECT
            $stmt->execute([$workerId, 'PROJECT', $filterProject, $filterProject]);
            $rate = $stmt->fetch();
            if ($rate) {
                $rate['inherited'] = 'PROJECT';
            }
        }
        
        if (!$rate && in_array($scopeType, ['STAGE', 'PROJECT'])) {
            // Próbuj GLOBAL
            $stmt->execute([$workerId, 'GLOBAL', null, null]);
            $rate = $stmt->fetch();
            if ($rate) {
                $rate['inherited'] = 'GLOBAL';
            }
        }
    }
    
    // Jeśli nadal brak, utwórz pustą strukturę
    if (!$rate) {
        $rate = [
            'id' => null,
            'worker_id' => $workerId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'rate_base' => 0,
            'rate_overtime' => 0,
            'rate_saturday' => 0,
            'rate_saturday_overtime' => 0,
            'rate_sunday' => 0,
            'rate_sunday_overtime' => 0,
            'rate_night' => 0,
            'rate_night_overtime' => 0,
            'rate_delegation' => 0,
            'rate_delegation_overtime' => 0,
            'rate_vacation' => null,
            'rate_sickleave' => null,
            'inherited' => null
        ];
    }
    
    $rates[$workerId] = $rate;
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Stawki Pracowników</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1800px;
            margin: 0 auto;
            padding: 30px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .spx-filter-group.fg-project  { flex: 2 1 0; }
        .spx-filter-group.fg-stage    { flex: 2 1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select {
            padding: 0 8px;
            height: 38px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .spx-filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 768px) {
            .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; }
            .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; }
            .spx-filter-group select { height: 44px; font-size: 14px; }
        }
        .btn {
            padding: 10px 20px;
            height: 42px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        /* Tabela stawek */
        .rates-table-wrapper {
            overflow-x: auto;
        }
        .rates-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .rates-table thead {
            background: #f9fafb;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .rates-table th {
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .rates-table th.col-worker {
            position: sticky;
            left: 0;
            background: #f9fafb;
            z-index: 11;
            min-width: 180px;
        }
        .rates-table th.col-rate {
            text-align: right;
            min-width: 90px;
        }
        .rates-table td {
            padding: 10px;
            border-bottom: 1px solid #f3f4f6;
        }
        .rates-table td.col-worker {
            position: sticky;
            left: 0;
            background: white;
            z-index: 5;
            font-weight: 600;
            color: #333;
        }
        .rates-table tbody tr:hover td {
            background: #f9fafb;
        }
        .rates-table tbody tr:hover td.col-worker {
            background: #f0f4ff;
        }
        
        /* Edytowalne pole */
        .rate-input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            text-align: right;
            font-size: 13px;
            font-family: 'SF Mono', Monaco, monospace;
            transition: all 0.2s;
        }
        .rate-input:focus {
            outline: none;
            border-color: #667eea;
            background: #f0f4ff;
        }
        .rate-input.inherited {
            background: #fef3c7;
            color: #92400e;
            font-style: italic;
        }
        .rate-input:disabled {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }
        
        .save-btn {
            padding: 6px 12px;
            background: #22c55e;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .save-btn:hover {
            background: #16a34a;
        }
        .save-btn:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }
        
        .inherited-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 6px;
        }
        
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 15px;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #9ca3af;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2>Stawki Pracownikow (Biblia)</h2>
        </div>
        
        <div class="info-box">
            <strong>Hierarchia stawek:</strong> ETAP &gt; PROJEKT &gt; GLOBAL<br>
            System automatycznie wybiera najbardziej szczegółową stawkę dostępną dla danego zakresu.
            Żółte pola = stawka odziedziczona z wyższego poziomu.
        </div>
        
        <div class="card">
            <!-- Filtry -->
            <form method="GET" action="" class="spx-filter-bar" id="filterForm">
                <div class="spx-filter-group fg-project">
                    <label>Projekt</label>
                    <select name="project" id="projectSelect" onchange="this.form.submit()">
                        <option value="0">Stawki Globalne (domyślne)</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" <?php echo ($filterProject == $proj['id']) ? 'selected' : ''; ?>>
                                <?php echo e($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($filterProject > 0): ?>
                <div class="spx-filter-group fg-stage">
                    <label>Etap</label>
                    <select name="stage" onchange="this.form.submit()">
                        <option value="0">Stawki Projektu (bez etapu)</option>
                        <?php foreach ($stages as $stage): ?>
                            <option value="<?php echo $stage['id']; ?>" <?php echo ($filterStage == $stage['id']) ? 'selected' : ''; ?>>
                                <?php echo e($stage['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div style="margin-left: auto;">
                    <?php 
                    $scopeLabel = 'GLOBAL';
                    if ($filterStage > 0) {
                        $scopeLabel = 'STAGE (Etap)';
                    } elseif ($filterProject > 0) {
                        $scopeLabel = 'PROJECT (Projekt)';
                    }
                    ?>
                    <span style="font-size: 12px; color: #6b7280;">Aktualny zakres: <strong style="color: #667eea;"><?php echo $scopeLabel; ?></strong></span>
                </div>
            </form>
            
            <?php if (empty($workers)): ?>
                <div class="no-data">
                    Brak aktywnych pracowników.
                </div>
            <?php else: ?>
                <div class="rates-table-wrapper">
                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th class="col-worker">Pracownik</th>
                                <th class="col-rate">Bazowa</th>
                                <th class="col-rate">Nadgodz.</th>
                                <th class="col-rate">Sobota</th>
                                <th class="col-rate">Sob. Nadg.</th>
                                <th class="col-rate">Niedziela</th>
                                <th class="col-rate">Niedz. Nadg.</th>
                                <th class="col-rate">Nocka</th>
                                <th class="col-rate">Nocka Nadg.</th>
                                <th class="col-rate">Delegacja</th>
                                <th class="col-rate">Deleg. Nadg.</th>
                                <th style="text-align: center;">Akcja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workers as $worker): ?>
                                <?php 
                                $workerId = $worker['id'];
                                $rate = $rates[$workerId];
                                $isInherited = isset($rate['inherited']);
                                $rateId = $rate['id'];
                                ?>
                                <tr data-worker-id="<?php echo $workerId; ?>">
                                    <td class="col-worker">
                                        <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                        <?php if ($isInherited): ?>
                                            <span class="inherited-badge"><?php echo $rate['inherited']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_base" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_base'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_base']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_overtime" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_overtime'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_overtime']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_saturday" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_saturday'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_saturday']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_saturday_overtime" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_saturday_overtime'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_saturday_overtime']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_sunday" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_sunday'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_sunday']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_sunday_overtime" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_sunday_overtime'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_sunday_overtime']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_night" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_night'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_night']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_night_overtime" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_night_overtime'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_night_overtime']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_delegation" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_delegation'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_delegation']; ?>">
                                    </td>
                                    <td>
                                        <input type="number" class="rate-input <?php echo $isInherited ? 'inherited' : ''; ?>" 
                                               name="rate_delegation_overtime" step="0.01" min="0" 
                                               value="<?php echo number_format($rate['rate_delegation_overtime'], 2, '.', ''); ?>"
                                               data-original="<?php echo $rate['rate_delegation_overtime']; ?>">
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="button" class="save-btn" onclick="saveRate(<?php echo $workerId; ?>, <?php echo $rateId ?: 'null'; ?>)">
                                            Zapisz
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        function saveRate(workerId, rateId) {
            const row = document.querySelector(`tr[data-worker-id="${workerId}"]`);
            const inputs = row.querySelectorAll('.rate-input');
            const btn = row.querySelector('.save-btn');
            
            // Zbierz dane
            const data = {
                worker_id: workerId,
                rate_id: rateId,
                scope_type: '<?php echo $scopeType; ?>',
                scope_id: <?php echo $scopeId ?: 'null'; ?>,
                project_id: <?php echo $filterProject; ?>,
                stage_id: <?php echo $filterStage; ?>
            };
            
            inputs.forEach(input => {
                data[input.name] = parseFloat(input.value) || 0;
            });
            
            // Wyślij AJAX
            btn.disabled = true;
            btn.textContent = 'Zapisywanie...';
            
            fetch('save_rate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    btn.textContent = '✓ Zapisano';
                    btn.style.background = '#22c55e';
                    
                    // Usuń klasę inherited i zaktualizuj data-original
                    inputs.forEach(input => {
                        input.classList.remove('inherited');
                        input.dataset.original = input.value;
                    });
                    
                    // Usuń badge inherited
                    const badge = row.querySelector('.inherited-badge');
                    if (badge) badge.remove();
                    
                    setTimeout(() => {
                        btn.textContent = 'Zapisz';
                        btn.style.background = '#22c55e';
                        btn.disabled = false;
                    }, 2000);
                } else {
                    alert('Błąd zapisu: ' + (result.message || 'Nieznany błąd'));
                    btn.textContent = 'Błąd';
                    btn.style.background = '#dc3545';
                    setTimeout(() => {
                        btn.textContent = 'Zapisz';
                        btn.style.background = '#22c55e';
                        btn.disabled = false;
                    }, 2000);
                }
            })
            .catch(error => {
                alert('Błąd połączenia: ' + error);
                btn.textContent = 'Błąd';
                btn.style.background = '#dc3545';
                setTimeout(() => {
                    btn.textContent = 'Zapisz';
                    btn.style.background = '#22c55e';
                    btn.disabled = false;
                }, 2000);
            });
        }
    </script>
</body>
</html>

