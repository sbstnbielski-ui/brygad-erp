<?php
/**
 * BRYGAD ERP - rate_helper.php
 * Wspoldzielona logika pobierania stawek i obliczania kosztow pracy.
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/absence_helper.php';

function workerRateFieldNames(): array
{
    static $fields = [
        'base_rate',
        'overtime_rate',
        'saturday_rate',
        'saturday_overtime_rate',
        'sunday_rate',
        'sunday_overtime_rate',
        'night_rate',
        'night_overtime_rate',
        'delegation_rate',
        'delegation_overtime_rate',
        'vacation_rate',
        'sick_rate',
    ];

    return $fields;
}

function workerRateSelectList(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $columns = [];
    foreach (workerRateFieldNames() as $field) {
        $columns[] = $prefix . $field;
    }
    return implode(', ', $columns);
}

function normalizeRateValue($value): ?float
{
    if ($value === '' || $value === null) {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function dateMinusOneDay(string $date): string
{
    return date('Y-m-d', strtotime($date . ' -1 day'));
}

function datePlusOneDay(string $date): string
{
    return date('Y-m-d', strtotime($date . ' +1 day'));
}

/**
 * Pobiera stawke pracownika z fallbackiem scope: etap -> projekt -> bazowa.
 */
function getWorkerRate(PDO $pdo, int $workerId, ?int $projectId, string $date, ?int $costNodeId = null): ?array
{
    $projectId = ($projectId && $projectId > 0) ? (int)$projectId : null;
    $costNodeId = ($costNodeId && $costNodeId > 0) ? (int)$costNodeId : null;
    $rateColumns = workerRateSelectList();

    $combos = [];
    if ($projectId !== null && $costNodeId !== null) {
        $combos[] = ['project_id = ? AND cost_node_id = ?', [$projectId, $costNodeId]];
    }
    if ($projectId !== null) {
        $combos[] = ['project_id = ? AND cost_node_id IS NULL', [$projectId]];
    }
    if ($costNodeId !== null) {
        $combos[] = ['project_id IS NULL AND cost_node_id = ?', [$costNodeId]];
    }
    $combos[] = ['project_id IS NULL AND cost_node_id IS NULL', []];

    foreach ([true, false] as $withValidTo) {
        foreach ($combos as [$comboWhere, $comboParams]) {
            $sql = "
                SELECT {$rateColumns}
                FROM worker_rates
                WHERE worker_id = ?
                  AND valid_from <= ?
            ";
            $params = [$workerId, $date];
            if ($withValidTo) {
                $sql .= ' AND (valid_to IS NULL OR valid_to >= ?)';
                $params[] = $date;
            }
            $sql .= " AND {$comboWhere}
                ORDER BY valid_from DESC
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($params, $comboParams));
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }
    }

    $stmt = $pdo->prepare("\n        SELECT {$rateColumns}\n        FROM worker_rates\n        WHERE worker_id = ?\n          AND base_rate IS NOT NULL\n        ORDER BY valid_from DESC\n        LIMIT 1\n    ");
    $stmt->execute([$workerId]);
    return $stmt->fetch() ?: null;
}

/**
 * Zwraca stawke godzinowa dla prostych podgladow.
 */
function selectRate(array $log, array $rate): array
{
    $workType = $log['work_type'] ?? 'work';

    if ($workType === 'sick') {
        $value = normalizeRateValue($rate['sick_rate'] ?? null) ?? normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0;
        return ['rate' => $value, 'label' => !empty($rate['sick_rate']) ? 'Stawka L4' : 'Stawka podstawowa'];
    }
    if ($workType === 'vacation') {
        $value = normalizeRateValue($rate['vacation_rate'] ?? null) ?? normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0;
        return ['rate' => $value, 'label' => !empty($rate['vacation_rate']) ? 'Stawka urlopowa' : 'Stawka podstawowa'];
    }
    if (!empty($log['is_delegation'])) {
        $value = normalizeRateValue($rate['delegation_rate'] ?? null) ?? normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0;
        return ['rate' => $value, 'label' => !empty($rate['delegation_rate']) ? 'Stawka delegacji' : 'Stawka podstawowa'];
    }
    if (!empty($log['is_sunday'])) {
        $value = normalizeRateValue($rate['sunday_rate'] ?? null) ?? normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0;
        return ['rate' => $value, 'label' => !empty($rate['sunday_rate']) ? 'Stawka niedzielna' : 'Stawka podstawowa'];
    }
    if (!empty($log['is_saturday'])) {
        $value = normalizeRateValue($rate['saturday_rate'] ?? null) ?? normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0;
        return ['rate' => $value, 'label' => !empty($rate['saturday_rate']) ? 'Stawka sobotnia' : 'Stawka podstawowa'];
    }
    if (!empty($log['is_night'])) {
        $value = normalizeRateValue($rate['night_rate'] ?? null) ?? normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0;
        return ['rate' => $value, 'label' => !empty($rate['night_rate']) ? 'Stawka nocna' : 'Stawka podstawowa'];
    }

    return [
        'rate' => normalizeRateValue($rate['base_rate'] ?? null) ?? 0.0,
        'label' => 'Stawka podstawowa',
    ];
}

/**
 * Oblicza koszt wpisu pracy na podstawie pelnego modelu stawek.
 */
function calculateSuggestedCost(array $log, ?array $rate): array
{
    if (!$rate || normalizeRateValue($rate['base_rate'] ?? null) === null) {
        return [
            'suggested' => 0.0,
            'system_rate_snapshot' => null,
            'overtime_rate' => null,
            'rate_label' => 'Brak stawki',
        ];
    }

    $baseRate = normalizeRateValue($rate['base_rate']) ?? 0.0;
    $overtimeRate = normalizeRateValue($rate['overtime_rate'] ?? null) ?? $baseRate;
    $saturdayRate = normalizeRateValue($rate['saturday_rate'] ?? null) ?? $baseRate;
    $saturdayOtRate = normalizeRateValue($rate['saturday_overtime_rate'] ?? null) ?? $overtimeRate;
    $sundayRate = normalizeRateValue($rate['sunday_rate'] ?? null) ?? $baseRate;
    $sundayOtRate = normalizeRateValue($rate['sunday_overtime_rate'] ?? null) ?? $overtimeRate;
    $nightRate = normalizeRateValue($rate['night_rate'] ?? null) ?? $baseRate;
    $nightOtRate = normalizeRateValue($rate['night_overtime_rate'] ?? null) ?? $overtimeRate;
    $delegationRate = normalizeRateValue($rate['delegation_rate'] ?? null) ?? $baseRate;
    $delegationOtRate = normalizeRateValue($rate['delegation_overtime_rate'] ?? null) ?? $overtimeRate;
    $vacationRate = normalizeRateValue($rate['vacation_rate'] ?? null) ?? $baseRate;
    $sickRate = normalizeRateValue($rate['sick_rate'] ?? null) ?? $baseRate;

    $workType = (string)($log['work_type'] ?? 'work');
    $isPaid = (int)($log['is_paid'] ?? 1);

    if (($workType === 'vacation' || $workType === 'sick') && $isPaid === 0) {
        return [
            'suggested' => 0.0,
            'system_rate_snapshot' => 0.0,
            'overtime_rate' => 0.0,
            'rate_label' => 'Absencja nieplatna',
        ];
    }

    if ($workType === 'vacation') {
        $hours = normalizeAbsenceHours($log);
        return [
            'suggested' => $hours * $vacationRate,
            'system_rate_snapshot' => $vacationRate,
            'overtime_rate' => 0.0,
            'rate_label' => 'Stawka urlopowa',
        ];
    }

    if ($workType === 'sick') {
        $hours = normalizeAbsenceHours($log);
        return [
            'suggested' => $hours * $sickRate,
            'system_rate_snapshot' => $sickRate,
            'overtime_rate' => 0.0,
            'rate_label' => 'Stawka L4',
        ];
    }

    $components = [
        ['hours' => max(0.0, (float)($log['workday_hours'] ?? 0)), 'rate' => $baseRate, 'label' => 'Robocza'],
        ['hours' => max(0.0, (float)($log['workday_overtime'] ?? 0)), 'rate' => $overtimeRate, 'label' => 'Nadgodziny'],
        ['hours' => max(0.0, (float)($log['saturday_hours'] ?? 0)), 'rate' => $saturdayRate, 'label' => 'Sobota'],
        ['hours' => max(0.0, (float)($log['saturday_overtime'] ?? 0)), 'rate' => $saturdayOtRate, 'label' => 'Sobota nadgodziny'],
        ['hours' => max(0.0, (float)($log['sunday_hours'] ?? 0)), 'rate' => $sundayRate, 'label' => 'Niedziela'],
        ['hours' => max(0.0, (float)($log['sunday_overtime'] ?? 0)), 'rate' => $sundayOtRate, 'label' => 'Niedziela nadgodziny'],
        ['hours' => max(0.0, (float)($log['night_hours'] ?? 0)), 'rate' => $nightRate, 'label' => 'Nocka'],
        ['hours' => max(0.0, (float)($log['night_overtime'] ?? 0)), 'rate' => $nightOtRate, 'label' => 'Nocka nadgodziny'],
        ['hours' => max(0.0, (float)($log['delegation_hours'] ?? 0)), 'rate' => $delegationRate, 'label' => 'Delegacja'],
        ['hours' => max(0.0, (float)($log['delegation_overtime'] ?? 0)), 'rate' => $delegationOtRate, 'label' => 'Delegacja nadgodziny'],
    ];

    $componentHours = 0.0;
    $suggested = 0.0;
    $activeLabels = [];
    foreach ($components as $component) {
        $componentHours += $component['hours'];
        $suggested += $component['hours'] * $component['rate'];
        if ($component['hours'] > 0.0001) {
            $activeLabels[] = $component['label'];
        }
    }

    if ($componentHours <= 0.0001) {
        $hours = max(0.0, (float)($log['hours'] ?? 0));
        $overtimeHours = max(0.0, (float)($log['overtime_hours'] ?? 0));
        $suggested = ($hours * $baseRate) + ($overtimeHours * $overtimeRate);
        $activeLabels = $overtimeHours > 0.0001 ? ['Robocza', 'Nadgodziny'] : ['Robocza'];
    }

    $rateLabel = count($activeLabels) > 1 ? 'Stawka mieszana' : ($activeLabels[0] ?? 'Stawka podstawowa');

    return [
        'suggested' => $suggested,
        'system_rate_snapshot' => $baseRate,
        'overtime_rate' => $overtimeRate,
        'rate_label' => $rateLabel,
    ];
}

/**
 * Wstawia nowy rekord stawki i porzadkuje nakladajace sie zakresy dla tego samego scope.
 */
function upsertWorkerRateInterval(PDO $pdo, array $payload): int
{
    $workerId = (int)($payload['worker_id'] ?? 0);
    $projectId = !empty($payload['project_id']) ? (int)$payload['project_id'] : null;
    $costNodeId = !empty($payload['cost_node_id']) ? (int)$payload['cost_node_id'] : null;
    $validFrom = (string)($payload['valid_from'] ?? '');
    $validTo = !empty($payload['valid_to']) ? (string)$payload['valid_to'] : null;
    $scopeEnd = $validTo ?? '9999-12-31';
    $fields = workerRateFieldNames();

    $selectFields = 'id, worker_id, project_id, cost_node_id, valid_from, valid_to, ' . workerRateSelectList();
    $sql = "SELECT {$selectFields} FROM worker_rates WHERE worker_id = ?";
    $params = [$workerId];

    if ($projectId !== null) {
        $sql .= ' AND project_id = ?';
        $params[] = $projectId;
    } else {
        $sql .= ' AND project_id IS NULL';
    }

    if ($costNodeId !== null) {
        $sql .= ' AND cost_node_id = ?';
        $params[] = $costNodeId;
    } else {
        $sql .= ' AND cost_node_id IS NULL';
    }

    $sql .= ' AND valid_from <= ? AND COALESCE(valid_to, \'9999-12-31\') >= ? ORDER BY valid_from ASC';
    $params[] = $scopeEnd;
    $params[] = $validFrom;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $overlappingRates = $stmt->fetchAll();

    $updateValidToStmt = $pdo->prepare('UPDATE worker_rates SET valid_to = ? WHERE id = ?');
    $updateValidFromStmt = $pdo->prepare('UPDATE worker_rates SET valid_from = ? WHERE id = ?');
    $deleteStmt = $pdo->prepare('DELETE FROM worker_rates WHERE id = ?');

    $insertColumns = array_merge(['worker_id', 'project_id', 'cost_node_id'], $fields, ['valid_from', 'valid_to']);
    $insertPlaceholders = implode(', ', array_fill(0, count($insertColumns), '?'));
    $insertSql = 'INSERT INTO worker_rates (' . implode(', ', $insertColumns) . ') VALUES (' . $insertPlaceholders . ')';
    $insertStmt = $pdo->prepare($insertSql);

    foreach ($overlappingRates as $existingRate) {
        $existingStart = (string)$existingRate['valid_from'];
        $existingEnd = !empty($existingRate['valid_to']) ? (string)$existingRate['valid_to'] : '9999-12-31';
        $keepBefore = $existingStart < $validFrom;
        $keepAfter = $existingEnd > $scopeEnd;

        if ($keepBefore && $keepAfter) {
            $updateValidToStmt->execute([dateMinusOneDay($validFrom), (int)$existingRate['id']]);

            $cloneParams = [
                $workerId,
                $projectId,
                $costNodeId,
            ];
            foreach ($fields as $field) {
                $cloneParams[] = $existingRate[$field] !== null ? (float)$existingRate[$field] : null;
            }
            $cloneParams[] = datePlusOneDay($scopeEnd);
            $cloneParams[] = $existingRate['valid_to'] ?: null;
            $insertStmt->execute($cloneParams);
            continue;
        }

        if ($keepBefore) {
            $updateValidToStmt->execute([dateMinusOneDay($validFrom), (int)$existingRate['id']]);
            continue;
        }

        if ($keepAfter) {
            $updateValidFromStmt->execute([datePlusOneDay($scopeEnd), (int)$existingRate['id']]);
            continue;
        }

        $deleteStmt->execute([(int)$existingRate['id']]);
    }

    $insertParams = [
        $workerId,
        $projectId,
        $costNodeId,
    ];
    foreach ($fields as $field) {
        $insertParams[] = $payload[$field] ?? null;
    }
    $insertParams[] = $validFrom;
    $insertParams[] = $validTo;
    $insertStmt->execute($insertParams);

    return (int)$pdo->lastInsertId();
}

/**
 * Przelicza zatwierdzone wpisy pracownika w danym zakresie po zapisanej stawce.
 */
function recalculateApprovedWorkLogsForWorker(PDO $pdo, int $workerId, string $dateFrom, string $dateTo, ?int $projectId = null): array
{
    $sql = "
        SELECT
            id, worker_id, project_id, cost_node_id, date, work_type, is_paid,
            hours, overtime_hours,
            workday_hours, workday_overtime,
            saturday_hours, saturday_overtime,
            sunday_hours, sunday_overtime,
            night_hours, night_overtime,
            delegation_hours, delegation_overtime,
            absence_days,
            vacation_hours, sickleave_hours,
            is_saturday, is_sunday, is_night, is_delegation
        FROM work_logs
        WHERE worker_id = ?
          AND status = 'approved'
          AND date >= ?
          AND date <= ?
    ";
    $params = [$workerId, $dateFrom, $dateTo];
    if ($projectId !== null && $projectId > 0) {
        $sql .= ' AND project_id = ?';
        $params[] = $projectId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    $matched = count($logs);
    $updated = 0;
    if ($matched === 0) {
        return ['matched' => 0, 'updated' => 0];
    }

    $updateStmt = $pdo->prepare("\n        UPDATE work_logs\n        SET system_rate_snapshot = ?,\n            system_cost = ?,\n            final_cost = ?,\n            updated_at = NOW()\n        WHERE id = ?\n    ");

    foreach ($logs as $log) {
        $rate = getWorkerRate(
            $pdo,
            (int)$log['worker_id'],
            !empty($log['project_id']) ? (int)$log['project_id'] : null,
            (string)$log['date'],
            !empty($log['cost_node_id']) ? (int)$log['cost_node_id'] : null
        );
        $cost = calculateSuggestedCost($log, $rate);
        $updateStmt->execute([
            $cost['system_rate_snapshot'],
            $cost['suggested'],
            $cost['suggested'],
            (int)$log['id'],
        ]);
        $updated++;
    }

    return ['matched' => $matched, 'updated' => $updated];
}
