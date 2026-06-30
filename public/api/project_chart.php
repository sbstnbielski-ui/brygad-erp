<?php
/**
 * BRYGAD ERP v3.0 - API: Project Chart Data
 * 
 * Endpoint: /api/project_chart.php?project_id=ID
 * 
 * Zwraca dane finansowe projektu w formacie JSON do wykresów.
 * 
 * Struktura odpowiedzi:
 * {
 *   "success": true,
 *   "data": {
 *     "project": { ... },
 *     "costs_breakdown": { "labor": X, "materials": Y },  // wartości bezwzględne dla Pie Chart
 *     "financial_summary": { "revenue": X, "profit": Y }  // profit może być ujemny
 *   }
 * }
 * 
 * UWAGA: costs_breakdown zawiera WARTOŚCI BEZWZGLĘDNE (pozytywne) dla wykresów kołowych.
 *        Nie wysyłaj Profit jako slice wykresu kołowego!
 * 
 * @since Task 4 - Financial Logic & Dashboard Updates
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();

// Sprawdź autoryzację
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Musisz być zalogowany, aby uzyskać dostęp do tych danych.'
    ]);
    exit;
}

$pdo = getDbConnection();

// Pobierz ID projektu
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Wymagany parametr: project_id'
    ]);
    exit;
}

try {
    // Pobierz dane z view_project_finances
    $stmt = $pdo->prepare("
        SELECT 
            project_id,
            project_name,
            status,
            total_revenue,
            total_labor_cost,
            total_material_cost,
            current_profit
        FROM view_project_finances 
        WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    $finances = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$finances) {
        // Projekt istnieje, ale nie ma jeszcze danych finansowych
        // Sprawdź czy projekt w ogóle istnieje
        $stmtCheck = $pdo->prepare("SELECT id, name, status FROM projects WHERE id = ?");
        $stmtCheck->execute([$projectId]);
        $project = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Projekt nie istnieje.'
            ]);
            exit;
        }
        
        // Projekt istnieje, zwróć puste dane finansowe
        $finances = [
            'project_id' => $project['id'],
            'project_name' => $project['name'],
            'status' => $project['status'],
            'total_revenue' => 0,
            'total_labor_cost' => 0,
            'total_material_cost' => 0,
            'current_profit' => 0
        ];
    }
    
    // Przygotuj dane do wykresów
    // UWAGA: costs_breakdown zawiera WARTOŚCI BEZWZGLĘDNE (abs()) dla Pie Chart
    // Nie można pokazać ujemnych wartości na wykresie kołowym
    $laborCost = (float)$finances['total_labor_cost'];
    $materialCost = (float)$finances['total_material_cost'];
    $revenue = (float)$finances['total_revenue'];
    $profit = (float)$finances['current_profit'];
    
    $response = [
        'success' => true,
        'data' => [
            'project' => [
                'id' => (int)$finances['project_id'],
                'name' => $finances['project_name'],
                'status' => $finances['status']
            ],
            // Wartości bezwzględne dla wykresów kołowych (muszą być >= 0)
            'costs_breakdown' => [
                'labor' => abs($laborCost),
                'materials' => abs($materialCost),
                'total' => abs($laborCost) + abs($materialCost)
            ],
            // Podsumowanie finansowe (profit może być ujemny)
            'financial_summary' => [
                'revenue' => $revenue,
                'total_costs' => $laborCost + $materialCost,
                'profit' => $profit,
                'profit_margin' => ($revenue > 0) ? round(($profit / $revenue) * 100, 2) : 0
            ],
            // Dodatkowe metryki
            'metrics' => [
                'is_profitable' => ($profit > 0),
                'labor_percentage' => ($laborCost + $materialCost > 0) 
                    ? round(($laborCost / ($laborCost + $materialCost)) * 100, 2) 
                    : 0,
                'materials_percentage' => ($laborCost + $materialCost > 0) 
                    ? round(($materialCost / ($laborCost + $materialCost)) * 100, 2) 
                    : 0
            ]
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    logEvent("API project_chart błąd: " . $e->getMessage(), 'ERROR');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => 'Błąd podczas pobierania danych.'
    ]);
}


