<?php

if (!function_exists('payrollNormalizeMonth')) {
    function payrollNormalizeMonth(?string $month, ?string $fallbackDate = null): string
    {
        $month = trim((string)$month);

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $month . '-01';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $month)) {
            return substr($month, 0, 7) . '-01';
        }

        $timestamp = $fallbackDate && strtotime($fallbackDate) !== false
            ? strtotime($fallbackDate)
            : time();

        return date('Y-m-01', $timestamp);
    }
}

if (!function_exists('payrollIsMonthInputValid')) {
    function payrollIsMonthInputValid(?string $month): bool
    {
        $month = trim((string)$month);
        return $month === '' || (bool)preg_match('/^\d{4}-\d{2}$/', $month);
    }
}

if (!function_exists('payrollMonthInputValue')) {
    function payrollMonthInputValue(?string $period, ?string $fallbackDate = null): string
    {
        return substr(payrollNormalizeMonth($period, $fallbackDate), 0, 7);
    }
}

if (!function_exists('payrollMonthLabel')) {
    function payrollMonthLabel(?string $period): string
    {
        if (!$period || strtotime($period) === false) {
            return '';
        }

        $months = [
            1 => 'styczeń',
            2 => 'luty',
            3 => 'marzec',
            4 => 'kwiecień',
            5 => 'maj',
            6 => 'czerwiec',
            7 => 'lipiec',
            8 => 'sierpień',
            9 => 'wrzesień',
            10 => 'październik',
            11 => 'listopad',
            12 => 'grudzień',
        ];

        $ts = strtotime($period);
        return ($months[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
    }
}

if (!function_exists('payrollWorkerAdvancesHasSalaryPeriod')) {
    function payrollWorkerAdvancesHasSalaryPeriod(PDO $pdo): bool
    {
        static $hasColumn = null;

        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `worker_advances` LIKE 'salary_period'");
            $hasColumn = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasColumn = false;
        }

        return $hasColumn;
    }
}

if (!function_exists('payrollAdvancePeriodSql')) {
    function payrollAdvancePeriodSql(PDO $pdo, string $alias = 'wa'): string
    {
        $monthFromIssueDate = "STR_TO_DATE(DATE_FORMAT({$alias}.issue_date, '%Y-%m-01'), '%Y-%m-%d')";

        if (!payrollWorkerAdvancesHasSalaryPeriod($pdo)) {
            return $monthFromIssueDate;
        }

        return "COALESCE(NULLIF({$alias}.salary_period, '0000-00-00'), {$monthFromIssueDate})";
    }
}

if (!function_exists('payrollSalaryPeriodSelectSql')) {
    function payrollSalaryPeriodSelectSql(PDO $pdo, string $alias = 'wa'): string
    {
        return payrollWorkerAdvancesHasSalaryPeriod($pdo)
            ? "{$alias}.salary_period"
            : "NULL";
    }
}
