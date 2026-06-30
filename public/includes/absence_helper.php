<?php

if (!function_exists('isAbsenceWorkType')) {
    function isAbsenceWorkType(?string $workType): bool
    {
        return $workType === 'vacation' || $workType === 'sick';
    }
}

if (!function_exists('isAbsenceLog')) {
    function isAbsenceLog(array $log): bool
    {
        return isAbsenceWorkType((string)($log['work_type'] ?? 'work'));
    }
}

if (!function_exists('normalizeAbsenceDays')) {
    function normalizeAbsenceDays(array $log): float
    {
        $workType = (string)($log['work_type'] ?? 'work');
        if (!isAbsenceWorkType($workType)) {
            return 0.0;
        }

        $rawDays = $log['absence_days'] ?? null;
        if ($rawDays !== null && $rawDays !== '' && is_numeric($rawDays)) {
            return max(0.0, round((float)$rawDays, 2));
        }

        if ($workType === 'vacation') {
            $hours = max(
                0.0,
                (float)($log['vacation_hours'] ?? ($log['hours'] ?? 0))
            );
            return round($hours / 8, 2);
        }

        $hours = max(
            0.0,
            (float)($log['sickleave_hours'] ?? ($log['hours'] ?? 0))
        );
        return round($hours / 8, 2);
    }
}

if (!function_exists('normalizeAbsenceHours')) {
    function normalizeAbsenceHours(array $log): float
    {
        return round(normalizeAbsenceDays($log) * 8, 2);
    }
}

if (!function_exists('normalizeVacationDays')) {
    function normalizeVacationDays(array $log): float
    {
        return (string)($log['work_type'] ?? 'work') === 'vacation'
            ? normalizeAbsenceDays($log)
            : 0.0;
    }
}

if (!function_exists('normalizeSickDays')) {
    function normalizeSickDays(array $log): float
    {
        return (string)($log['work_type'] ?? 'work') === 'sick'
            ? normalizeAbsenceDays($log)
            : 0.0;
    }
}

if (!function_exists('sumVacationDays')) {
    function sumVacationDays(iterable $logs): float
    {
        $total = 0.0;
        foreach ($logs as $log) {
            if (is_array($log)) {
                $total += normalizeVacationDays($log);
            }
        }
        return round($total, 2);
    }
}

if (!function_exists('sumSickDays')) {
    function sumSickDays(iterable $logs): float
    {
        $total = 0.0;
        foreach ($logs as $log) {
            if (is_array($log)) {
                $total += normalizeSickDays($log);
            }
        }
        return round($total, 2);
    }
}
