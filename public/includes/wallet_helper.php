<?php

if (!function_exists('walletResolveCompanyAdvanceWorkerId')) {
    function walletResolveCompanyAdvanceWorkerId(PDO $pdo, int $advanceId): int
    {
        if ($advanceId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare("
            SELECT worker_id
            FROM worker_advances
            WHERE id = ?
              AND type = 'COMPANY'
            LIMIT 1
        ");
        $stmt->execute([$advanceId]);

        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('walletGetCompanySourceWorkers')) {
    function walletGetCompanySourceWorkers(PDO $pdo, array $workerIds = [], bool $activeOnly = true): array
    {
        $where = [
            "wa.type = 'COMPANY'",
            "wa.status = 'open'",
        ];
        $params = [];

        if ($activeOnly) {
            $where[] = 'w.is_active = 1';
        }

        if (!empty($workerIds)) {
            $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
            $where[] = "wa.worker_id IN ($placeholders)";
            foreach ($workerIds as $workerId) {
                $params[] = (int)$workerId;
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                wa.worker_id,
                CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
                SUM(
                    wa.amount - COALESCE((
                        SELECT SUM(wl.amount)
                        FROM worker_ledger wl
                        WHERE wl.advance_id = wa.id
                          AND wl.amount > 0
                    ), 0)
                ) AS wallet_balance,
                COUNT(*) AS open_count
            FROM worker_advances wa
            INNER JOIN workers w ON w.id = wa.worker_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY wa.worker_id, w.first_name, w.last_name
            HAVING wallet_balance > 0.01
            ORDER BY w.last_name ASC, w.first_name ASC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('walletCreateAdvanceWithLedger')) {
    function walletCreateAdvanceWithLedger(
        PDO $pdo,
        int $workerId,
        string $type,
        float $amount,
        string $issueDate,
        string $description,
        ?int $createdBy,
        ?string $ledgerDescription = null,
        ?string $salaryPeriod = null
    ): int {
        $hasSalaryPeriod = function_exists('payrollWorkerAdvancesHasSalaryPeriod')
            && payrollWorkerAdvancesHasSalaryPeriod($pdo);

        if ($hasSalaryPeriod) {
            $stmt = $pdo->prepare("
                INSERT INTO worker_advances
                    (worker_id, type, amount, issue_date, salary_period, description, status, created_by, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, 'open', ?, NOW())
            ");
            $stmt->execute([
                $workerId,
                $type,
                abs($amount),
                $issueDate,
                $type === 'PRIVATE' ? $salaryPeriod : null,
                $description,
                $createdBy,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO worker_advances
                    (worker_id, type, amount, issue_date, description, status, created_by, created_at)
                VALUES
                    (?, ?, ?, ?, ?, 'open', ?, NOW())
            ");
            $stmt->execute([
                $workerId,
                $type,
                abs($amount),
                $issueDate,
                $description,
                $createdBy,
            ]);
        }

        $advanceId = (int)$pdo->lastInsertId();
        $ledgerText = trim((string)$ledgerDescription) !== ''
            ? $ledgerDescription
            : $description;

        if ($ledgerText === '') {
            $ledgerText = 'Zaliczka ' . ($type === 'PRIVATE' ? 'prywatna' : 'firmowa');
        }

        $stmt = $pdo->prepare("
            INSERT INTO worker_ledger
                (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
            VALUES
                (?, 'ADVANCE', ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $workerId,
            -1 * abs($amount),
            $issueDate,
            $advanceId,
            $ledgerText,
            $createdBy,
        ]);

        return $advanceId;
    }
}

if (!function_exists('walletMetaBase64UrlEncode')) {
    function walletMetaBase64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('walletMetaBase64UrlDecode')) {
    function walletMetaBase64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }
}

if (!function_exists('walletStripMeta')) {
    function walletStripMeta(?string $description): string
    {
        $text = trim((string)$description);
        return trim((string)preg_replace('/\s*\[\[SPRUTEX_META:[A-Za-z0-9\-_]+\]\]\s*$/', '', $text));
    }
}

if (!function_exists('walletExtractMeta')) {
    function walletExtractMeta(?string $description): array
    {
        $text = trim((string)$description);
        if ($text === '') {
            return [];
        }

        if (!preg_match('/\[\[SPRUTEX_META:([A-Za-z0-9\-_]+)\]\]\s*$/', $text, $matches)) {
            return [];
        }

        $decoded = walletMetaBase64UrlDecode((string)$matches[1]);
        if ($decoded === '') {
            return [];
        }

        $meta = json_decode($decoded, true);
        return is_array($meta) ? $meta : [];
    }
}

if (!function_exists('walletAppendMeta')) {
    function walletAppendMeta(string $description, array $meta): string
    {
        $clean = walletStripMeta($description);
        if (empty($meta)) {
            return $clean;
        }

        $payload = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') {
            return $clean;
        }

        return trim($clean) . ' [[SPRUTEX_META:' . walletMetaBase64UrlEncode($payload) . ']]';
    }
}

if (!function_exists('walletGenerateTransferRef')) {
    function walletGenerateTransferRef(): string
    {
        return 'wtr_' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('walletBuildTransferMeta')) {
    function walletBuildTransferMeta(string $transferRef, string $transferKind, string $transferRole, int $peerWorkerId): array
    {
        return [
            'transfer_ref' => $transferRef,
            'transfer_kind' => $transferKind,
            'transfer_role' => $transferRole,
            'peer_worker_id' => $peerWorkerId,
        ];
    }
}

if (!function_exists('walletBuildTransferDescription')) {
    function walletBuildTransferDescription(
        string $baseDescription,
        string $userNote,
        string $transferRef,
        string $transferKind,
        string $transferRole,
        int $peerWorkerId
    ): string {
        $visible = trim($baseDescription);
        $note = trim($userNote);
        if ($note !== '') {
            $visible .= ' | ' . $note;
        }

        return walletAppendMeta($visible, walletBuildTransferMeta($transferRef, $transferKind, $transferRole, $peerWorkerId));
    }
}

if (!function_exists('walletTransferMetaFromDescription')) {
    function walletTransferMetaFromDescription(?string $description): array
    {
        $meta = walletExtractMeta($description);
        if (($meta['transfer_ref'] ?? '') === '' || ($meta['transfer_role'] ?? '') === '') {
            return [];
        }

        return $meta;
    }
}

if (!function_exists('walletTransferVisibleNote')) {
    function walletTransferVisibleNote(?string $description): string
    {
        $visible = walletStripMeta($description);
        $parts = explode(' | ', $visible, 2);
        return isset($parts[1]) ? trim((string)$parts[1]) : '';
    }
}

if (!function_exists('walletVisibleDescription')) {
    function walletVisibleDescription(?string $description): string
    {
        $visible = walletStripMeta($description);
        $visible = preg_replace('/Wydatek z portfela \(wydatek #\d+\)/u', 'Wydatek z portfela', $visible);
        return trim((string)$visible);
    }
}

if (!function_exists('walletExtractWorkerIdsFromDescriptions')) {
    function walletExtractWorkerIdsFromDescriptions(array $descriptions): array
    {
        $ids = [];
        foreach ($descriptions as $description) {
            if (!is_string($description) || $description === '') {
                continue;
            }
            if (preg_match_all('/Transfer(?: na prywatną(?: do pracownika| od pracownika)?| do pracownika| od pracownika):?\s*ID\s+(\d+)/u', $description, $matches)) {
                foreach ($matches[1] as $workerId) {
                    $ids[] = (int)$workerId;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }
}

if (!function_exists('walletGetWorkerDisplayNames')) {
    function walletGetWorkerDisplayNames(PDO $pdo, array $workerIds): array
    {
        $workerIds = array_values(array_unique(array_map('intval', $workerIds)));
        $workerIds = array_values(array_filter($workerIds, static fn(int $id): bool => $id > 0));
        if (empty($workerIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                w.id,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(w.first_name, ''), ' ', COALESCE(w.last_name, ''))), ''),
                    NULLIF(u.login, ''),
                    CONCAT('ID ', w.id)
                ) AS worker_name
            FROM workers w
            LEFT JOIN users u ON u.worker_id = w.id
            WHERE w.id IN ($placeholders)
            GROUP BY w.id, w.first_name, w.last_name, u.login
        ");
        $stmt->execute($workerIds);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['id']] = trim((string)$row['worker_name']);
        }

        return $map;
    }
}

if (!function_exists('walletWorkerDisplayNameFromRow')) {
    function walletWorkerDisplayNameFromRow(array $worker, string $fallback = ''): string
    {
        $fullName = trim((string)($worker['first_name'] ?? '') . ' ' . (string)($worker['last_name'] ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        $login = trim((string)($worker['login'] ?? ''));
        if ($login !== '') {
            return $login;
        }

        if ($fallback !== '') {
            return $fallback;
        }

        $workerId = (int)($worker['id'] ?? 0);
        return $workerId > 0 ? ('ID ' . $workerId) : 'Pracownik';
    }
}

if (!function_exists('walletHumanizeDescription')) {
    function walletHumanizeDescription(?string $description, array $workerNamesById = []): string
    {
        $visible = walletVisibleDescription($description);
        if ($visible === '') {
            return '';
        }

        $visible = (string)preg_replace('/\s+\(#\d+\)(?=(?:\s*\||$))/u', '', $visible);

        if (empty($workerNamesById)) {
            return trim($visible);
        }

        $visible = (string)preg_replace_callback(
            '/(Transfer(?: na prywatną(?: do pracownika| od pracownika)?| do pracownika| od pracownika)):?\s*ID\s+(\d+)/u',
            static function (array $matches) use ($workerNamesById): string {
                $workerId = (int)($matches[2] ?? 0);
                $workerName = trim((string)($workerNamesById[$workerId] ?? ''));
                if ($workerName === '') {
                    return $matches[0];
                }
                return $matches[1] . ': ' . $workerName;
            },
            $visible
        );

        return trim($visible);
    }
}

if (!function_exists('walletResolveEntryUiType')) {
    function walletResolveEntryUiType(array $entry): string
    {
        $description = walletVisibleDescription((string)($entry['description'] ?? ''));
        $type = strtoupper(trim((string)($entry['entry_type'] ?? '')));
        $meta = walletTransferMetaFromDescription((string)($entry['description'] ?? ''));
        $amount = (float)($entry['amount'] ?? 0);
        $isOutflow = $amount > 0.01;
        $isInflow = $amount < -0.01;

        if (($meta['transfer_role'] ?? '') === 'target') {
            return 'transfer_in';
        }
        if (($meta['transfer_role'] ?? '') === 'source') {
            return ($meta['transfer_kind'] ?? '') === 'company_to_private'
                ? 'transfer_to_priv'
                : 'transfer_out';
        }
        if ($type === 'TRANSFER_OUT') {
            return 'transfer_to_priv';
        }
        if ($type === 'ADVANCE' && preg_match('/^Transfer od pracownika(?::|\s|$)/u', $description)) {
            return 'transfer_in';
        }
        if ($type === 'CASH_RETURN' && preg_match('/^Transfer na prywatną(?: do pracownika)?(?::|\s|$)/u', $description)) {
            return 'transfer_to_priv';
        }
        if ($type === 'CASH_RETURN' && preg_match('/^Transfer do pracownika(?::|\s|$)/u', $description)) {
            return 'transfer_out';
        }
        if ($type === 'EXPENSE_DOC' || !empty($entry['expense_id']) || !empty($entry['document_id'])) {
            return 'expense_doc';
        }
        if ($type === 'MANUAL_COST') {
            return 'manual_cost';
        }
        if ($type === 'SETTLEMENT_DEDUCTION' || !empty($entry['settlement_id'])) {
            return 'settlement_deduction';
        }
        if ($type === 'ADVANCE') {
            return $isOutflow ? 'outflow' : 'advance';
        }
        if ($type === 'CASH_RETURN') {
            return $isInflow ? 'inflow' : 'cash_return';
        }
        if ($type === '' && ($entry['source_kind'] ?? '') !== '') {
            return $isOutflow ? 'outflow' : 'advance';
        }
        if ($type === '' && preg_match('/^(Zaliczka firmowa|Zaliczka prywatna|Zasilenie portfela)(?::|\s|$)/u', $description)) {
            return $isOutflow ? 'outflow' : 'advance';
        }
        if ($type === '' && preg_match('/^(Zwrot gotówki|Zwrot do firmy)(?::|\s|$)/u', $description)) {
            return $isInflow ? 'inflow' : 'cash_return';
        }
        if ($type === '' && $isInflow) {
            return 'inflow';
        }
        if ($type === '' && $isOutflow) {
            return 'outflow';
        }
        if ($type === '') {
            return 'other';
        }
        return strtolower($type);
    }
}

if (!function_exists('walletResolveEntryFilterType')) {
    function walletResolveEntryFilterType(array $entry): string
    {
        return match (walletResolveEntryUiType($entry)) {
            'advance', 'inflow' => 'advance',
            'expense_doc', 'manual_cost' => 'expense',
            'transfer_in' => 'transfer_in',
            'transfer_out', 'transfer_to_priv', 'cash_return', 'outflow', 'settlement_deduction', 'other' => 'transfer_out',
            default => 'transfer_out',
        };
    }
}

if (!function_exists('walletResolveEntryLabel')) {
    function walletResolveEntryLabel(array $entry): string
    {
        return match (walletResolveEntryFilterType($entry)) {
            'advance' => 'Zasilenie',
            'expense' => 'Wydatek',
            'transfer_in' => 'Transfer przychodzący',
            'transfer_out' => 'Transfer wychodzący',
            default => 'Transfer wychodzący',
        };
    }
}

if (!function_exists('walletRecalculateAdvanceStatus')) {
    function walletRecalculateAdvanceStatus(PDO $pdo, int $advanceId): void
    {
        if ($advanceId <= 0) {
            return;
        }

        $stmt = $pdo->prepare("SELECT id, amount FROM worker_advances WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$advanceId]);
        $advance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$advance) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0)
            FROM worker_ledger
            WHERE advance_id = ?
        ");
        $stmt->execute([$advanceId]);
        $settled = (float)$stmt->fetchColumn();
        $newStatus = ((float)$advance['amount'] - $settled) <= 0.01 ? 'closed' : 'open';

        $stmt = $pdo->prepare("UPDATE worker_advances SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $advanceId]);
    }
}

if (!function_exists('walletDeleteAdvanceCascade')) {
    function walletDeleteAdvanceCascade(PDO $pdo, int $advanceId): void
    {
        throw new RuntimeException('Twarde usuwanie zaliczek i ledgerów jest zablokowane. Użyj korekty księgowej.');
    }
}

if (!function_exists('walletFindTransferTargetAdvance')) {
    function walletFindTransferTargetAdvance(PDO $pdo, string $transferRef, bool $forUpdate = false): ?array
    {
        if ($transferRef === '') {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM worker_advances
            WHERE description LIKE ?
            " . ($forUpdate ? "FOR UPDATE" : "") . "
        ");
        $stmt->execute(['%' . $transferRef . '%']);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $advance) {
            $meta = walletTransferMetaFromDescription((string)($advance['description'] ?? ''));
            if (($meta['transfer_ref'] ?? '') === $transferRef && ($meta['transfer_role'] ?? '') === 'target') {
                $advance['_transfer_meta'] = $meta;
                return $advance;
            }
        }

        return null;
    }
}

if (!function_exists('walletFindTransferSourceEntries')) {
    function walletFindTransferSourceEntries(PDO $pdo, string $transferRef, bool $forUpdate = false): array
    {
        if ($transferRef === '') {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT wl.*, wa.type AS advance_type
            FROM worker_ledger wl
            INNER JOIN worker_advances wa ON wa.id = wl.advance_id
            WHERE wl.description LIKE ?
              AND wl.entry_type = 'CASH_RETURN'
              AND wa.type = 'COMPANY'
            ORDER BY wl.entry_date ASC, wl.id ASC
            " . ($forUpdate ? "FOR UPDATE" : "") . "
        ");
        $stmt->execute(['%' . $transferRef . '%']);

        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $meta = walletTransferMetaFromDescription((string)($entry['description'] ?? ''));
            if (($meta['transfer_ref'] ?? '') === $transferRef && ($meta['transfer_role'] ?? '') === 'source') {
                $entry['_transfer_meta'] = $meta;
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}

if (!function_exists('walletTransferConsumedAmount')) {
    function walletTransferConsumedAmount(PDO $pdo, int $advanceId): float
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0)
            FROM worker_ledger
            WHERE advance_id = ?
        ");
        $stmt->execute([$advanceId]);
        return (float)$stmt->fetchColumn();
    }
}

if (!function_exists('walletCreateTransfer')) {
    function walletCreateTransfer(
        PDO $pdo,
        int $fromWorkerId,
        int $toWorkerId,
        float $amount,
        string $entryDate,
        string $userNote,
        ?int $createdBy,
        string $targetType = 'COMPANY',
        ?string $salaryPeriod = null
    ): array {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Operacja transferu wymaga aktywnej transakcji.');
        }
        if ($fromWorkerId <= 0 || $toWorkerId <= 0) {
            throw new RuntimeException('Transfer wymaga pracownika źródłowego i docelowego.');
        }
        if ($fromWorkerId === $toWorkerId) {
            throw new RuntimeException('Pracownik źródłowy i docelowy muszą być różni.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Kwota transferu musi być większa od 0.');
        }
        if (!in_array($targetType, ['COMPANY', 'PRIVATE'], true)) {
            throw new RuntimeException('Nieprawidłowy typ transferu docelowego.');
        }

        $stmt = $pdo->prepare("
            SELECT w.id, w.first_name, w.last_name, w.is_active, u.login
            FROM workers
            w
            LEFT JOIN users u ON u.worker_id = w.id
            WHERE w.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$toWorkerId]);
        $targetWorker = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetWorker) {
            throw new RuntimeException('Nie znaleziono pracownika docelowego.');
        }
        if ((int)$targetWorker['is_active'] !== 1) {
            throw new RuntimeException('Pracownik docelowy jest nieaktywny.');
        }

        $toName = walletWorkerDisplayNameFromRow($targetWorker, 'ID ' . $toWorkerId);
        $transferKind = $targetType === 'PRIVATE' ? 'company_to_private' : 'company_to_company';
        $transferRef = walletGenerateTransferRef();

        if ($targetType === 'PRIVATE') {
            $sourceBase = 'Transfer na prywatną do pracownika: ' . $toName;
        } else {
            $sourceBase = 'Transfer do pracownika: ' . $toName;
        }

        $sourceDescription = walletBuildTransferDescription(
            $sourceBase,
            $userNote,
            $transferRef,
            $transferKind,
            'source',
            $toWorkerId
        );

        $consume = walletConsumeCompanyFundsFifo($pdo, $fromWorkerId, $amount, $entryDate, $sourceDescription, $createdBy);

        if ($targetType === 'PRIVATE') {
            $targetBase = 'Transfer na prywatną od pracownika: ' . $consume['from_worker_name'];
        } else {
            $targetBase = 'Transfer od pracownika: ' . $consume['from_worker_name'];
        }

        $targetDescription = walletBuildTransferDescription(
            $targetBase,
            $userNote,
            $transferRef,
            $transferKind,
            'target',
            $fromWorkerId
        );

        $targetAdvanceId = walletCreateAdvanceWithLedger(
            $pdo,
            $toWorkerId,
            $targetType,
            $amount,
            $entryDate,
            $targetDescription,
            $createdBy,
            $targetDescription,
            $targetType === 'PRIVATE' ? $salaryPeriod : null
        );

        return [
            'transfer_ref' => $transferRef,
            'target_advance_id' => $targetAdvanceId,
            'target_type' => $targetType,
            'source' => $consume,
        ];
    }
}

if (!function_exists('walletUpdateTransferByTargetAdvance')) {
    function walletUpdateTransferByTargetAdvance(
        PDO $pdo,
        int $advanceId,
        float $amount,
        string $entryDate,
        string $userNote,
        ?int $createdBy
    ): array {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Edycja transferu wymaga aktywnej transakcji.');
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM worker_advances
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$advanceId]);
        $targetAdvance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetAdvance) {
            throw new RuntimeException('Nie znaleziono zaliczki transferowej.');
        }

        $meta = walletTransferMetaFromDescription((string)($targetAdvance['description'] ?? ''));
        if (($meta['transfer_role'] ?? '') !== 'target' || ($meta['transfer_ref'] ?? '') === '') {
            throw new RuntimeException('Ta zaliczka nie jest transferem portfelowym.');
        }

        $transferRef = (string)$meta['transfer_ref'];
        $sourceEntries = walletFindTransferSourceEntries($pdo, $transferRef, true);
        if (empty($sourceEntries)) {
            throw new RuntimeException('Nie znaleziono powiązanych ruchów źródłowych transferu.');
        }

        $targetConsumed = walletTransferConsumedAmount($pdo, $advanceId);
        if ($amount + 0.01 < $targetConsumed) {
            throw new RuntimeException('Nowa kwota transferu jest mniejsza niż już wykorzystane środki po stronie odbiorcy.');
        }

        $sourceWorkerId = (int)$sourceEntries[0]['worker_id'];
        $targetWorkerId = (int)$targetAdvance['worker_id'];
        $targetType = (string)$targetAdvance['type'];

        $stmt = $pdo->prepare("
            SELECT w.id, w.first_name, w.last_name, u.login
            FROM workers w
            LEFT JOIN users u ON u.worker_id = w.id
            WHERE w.id IN (?, ?)
            FOR UPDATE
        ");
        $stmt->execute([$sourceWorkerId, $targetWorkerId]);
        $workersById = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $worker) {
            $workersById[(int)$worker['id']] = walletWorkerDisplayNameFromRow($worker);
        }

        $targetWorkerName = $workersById[$targetWorkerId] ?? ('ID ' . $targetWorkerId);
        $sourceWorkerName = $workersById[$sourceWorkerId] ?? ('ID ' . $sourceWorkerId);
        $transferKind = (string)($meta['transfer_kind'] ?? ($targetType === 'PRIVATE' ? 'company_to_private' : 'company_to_company'));

        if (!empty($sourceEntries)) {
            throw new RuntimeException('Edycja transferu wymagałaby skasowania ledgerów. Operacja jest zablokowana; użyj korekty księgowej.');
        }

        $sourceAdvanceIds = array_values(array_unique(array_map(
            static fn(array $entry): int => (int)$entry['advance_id'],
            $sourceEntries
        )));
        foreach ($sourceAdvanceIds as $sourceAdvanceId) {
            walletRecalculateAdvanceStatus($pdo, $sourceAdvanceId);
        }

        if ($targetType === 'PRIVATE') {
            $sourceBase = 'Transfer na prywatną do pracownika: ' . $targetWorkerName;
            $targetBase = 'Transfer na prywatną od pracownika: ' . $sourceWorkerName;
        } else {
            $sourceBase = 'Transfer do pracownika: ' . $targetWorkerName;
            $targetBase = 'Transfer od pracownika: ' . $sourceWorkerName;
        }

        $sourceDescription = walletBuildTransferDescription(
            $sourceBase,
            $userNote,
            $transferRef,
            $transferKind,
            'source',
            $targetWorkerId
        );
        $targetDescription = walletBuildTransferDescription(
            $targetBase,
            $userNote,
            $transferRef,
            $transferKind,
            'target',
            $sourceWorkerId
        );

        $consume = walletConsumeCompanyFundsFifo($pdo, $sourceWorkerId, $amount, $entryDate, $sourceDescription, $createdBy);

        $stmt = $pdo->prepare("
            UPDATE worker_advances
            SET amount = ?,
                issue_date = ?,
                description = ?
            WHERE id = ?
        ");
        $stmt->execute([abs($amount), $entryDate, $targetDescription, $advanceId]);

        $stmt = $pdo->prepare("
            UPDATE worker_ledger
            SET amount = ?,
                entry_date = ?,
                description = ?
            WHERE advance_id = ?
              AND entry_type = 'ADVANCE'
        ");
        $stmt->execute([
            -1 * abs($amount),
            $entryDate,
            $targetDescription,
            $advanceId,
        ]);

        foreach ($sourceAdvanceIds as $sourceAdvanceId) {
            walletRecalculateAdvanceStatus($pdo, $sourceAdvanceId);
        }
        walletRecalculateAdvanceStatus($pdo, $advanceId);

        return [
            'transfer_ref' => $transferRef,
            'target_advance_id' => $advanceId,
            'source' => $consume,
        ];
    }
}

if (!function_exists('walletDeleteTransferByTargetAdvance')) {
    function walletDeleteTransferByTargetAdvance(PDO $pdo, int $advanceId): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Usunięcie transferu wymaga aktywnej transakcji.');
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM worker_advances
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$advanceId]);
        $targetAdvance = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetAdvance) {
            throw new RuntimeException('Nie znaleziono zaliczki transferowej.');
        }

        $meta = walletTransferMetaFromDescription((string)($targetAdvance['description'] ?? ''));
        if (($meta['transfer_role'] ?? '') !== 'target' || ($meta['transfer_ref'] ?? '') === '') {
            throw new RuntimeException('Ta zaliczka nie jest transferem portfelowym.');
        }

        $targetConsumed = walletTransferConsumedAmount($pdo, $advanceId);
        if ($targetConsumed > 0.01) {
            throw new RuntimeException('Nie można usunąć transferu, bo środki po stronie odbiorcy zostały już częściowo rozliczone.');
        }

        $sourceEntries = walletFindTransferSourceEntries($pdo, (string)$meta['transfer_ref'], true);
        if (empty($sourceEntries)) {
            throw new RuntimeException('Nie znaleziono powiązanych ruchów źródłowych transferu.');
        }

        $sourceAdvanceIds = array_values(array_unique(array_map(
            static fn(array $entry): int => (int)$entry['advance_id'],
            $sourceEntries
        )));

        throw new RuntimeException('Twarde usuwanie transferów portfela jest zablokowane. Użyj korekty księgowej.');
    }
}

if (!function_exists('walletConsumeCompanyFundsFifo')) {
    function walletConsumeCompanyFundsFifo(
        PDO $pdo,
        int $fromWorkerId,
        float $amount,
        string $entryDate,
        string $description,
        ?int $createdBy,
        string $entryType = 'CASH_RETURN'
    ): array {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Operacja portfela wymaga aktywnej transakcji.');
        }
        if ($fromWorkerId <= 0) {
            throw new RuntimeException('Brak pracownika źródłowego portfela.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Kwota portfela musi być większa od 0.');
        }

        $stmt = $pdo->prepare("
            SELECT w.id, w.first_name, w.last_name, u.login
            FROM workers w
            LEFT JOIN users u ON u.worker_id = w.id
            WHERE w.id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$fromWorkerId]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$worker) {
            throw new RuntimeException('Nie znaleziono pracownika źródłowego.');
        }

        $stmt = $pdo->prepare("
            SELECT
                wa.id AS advance_id,
                wa.issue_date,
                wa.amount,
                COALESCE(SUM(CASE WHEN wl.amount > 0 THEN wl.amount ELSE 0 END), 0) AS amount_settled
            FROM worker_advances wa
            LEFT JOIN worker_ledger wl ON wl.advance_id = wa.id
            WHERE wa.worker_id = ?
              AND wa.type = 'COMPANY'
              AND wa.status = 'open'
            GROUP BY wa.id, wa.issue_date, wa.amount
            HAVING (wa.amount - amount_settled) > 0.01
            ORDER BY wa.issue_date ASC, wa.id ASC
            FOR UPDATE
        ");
        $stmt->execute([$fromWorkerId]);
        $buckets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($buckets)) {
            throw new RuntimeException('Portfel źródłowy nie ma dostępnych środków.');
        }

        $remainingToAllocate = round($amount, 2);
        $allocations = [];

        foreach ($buckets as $bucket) {
            if ($remainingToAllocate <= 0.0001) {
                break;
            }

            $remainingBefore = round((float)$bucket['amount'] - (float)$bucket['amount_settled'], 2);
            if ($remainingBefore <= 0.01) {
                continue;
            }

            $allocation = min($remainingBefore, $remainingToAllocate);

            $stmt = $pdo->prepare("
                INSERT INTO worker_ledger
                    (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $fromWorkerId,
                $entryType,
                $allocation,
                $entryDate,
                (int)$bucket['advance_id'],
                $description,
                $createdBy,
            ]);

            $remainingAfter = round($remainingBefore - $allocation, 2);
            $newStatus = $remainingAfter <= 0.01 ? 'closed' : 'open';
            $stmt = $pdo->prepare("UPDATE worker_advances SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, (int)$bucket['advance_id']]);

            $allocations[] = [
                'advance_id' => (int)$bucket['advance_id'],
                'amount' => $allocation,
                'remaining_before' => $remainingBefore,
                'remaining_after' => max(0.0, $remainingAfter),
            ];

            $remainingToAllocate = round($remainingToAllocate - $allocation, 2);
        }

        if ($remainingToAllocate > 0.01) {
            throw new RuntimeException('Niewystarczające środki w portfelu źródłowym.');
        }

        return [
            'from_worker_id' => $fromWorkerId,
            'from_worker_name' => walletWorkerDisplayNameFromRow($worker, 'ID ' . $fromWorkerId),
            'allocations' => $allocations,
            'consumed_amount' => round($amount, 2),
        ];
    }
}

if (!function_exists('walletGetCompanyAdvanceBalances')) {
    function walletGetCompanyAdvanceBalances(PDO $pdo, int $workerId, bool $forUpdate = false): array
    {
        if ($workerId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                wa.id AS advance_id,
                wa.worker_id,
                wa.issue_date,
                wa.description,
                wa.amount,
                COALESCE(SUM(CASE WHEN wl.amount > 0 THEN wl.amount ELSE 0 END), 0) AS amount_settled,
                wa.amount - COALESCE(SUM(CASE WHEN wl.amount > 0 THEN wl.amount ELSE 0 END), 0) AS amount_remaining
            FROM worker_advances wa
            LEFT JOIN worker_ledger wl ON wl.advance_id = wa.id
            WHERE wa.worker_id = ?
              AND wa.type = 'COMPANY'
              AND wa.status = 'open'
            GROUP BY wa.id, wa.worker_id, wa.issue_date, wa.description, wa.amount
            HAVING amount_remaining > 0.01
            ORDER BY wa.issue_date ASC, wa.id ASC
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$workerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('walletGetCompanyAvailableBalance')) {
    function walletGetCompanyAvailableBalance(PDO $pdo, int $workerId): float
    {
        $total = 0.0;
        foreach (walletGetCompanyAdvanceBalances($pdo, $workerId, false) as $row) {
            $total += (float)($row['amount_remaining'] ?? 0);
        }
        return round($total, 2);
    }
}

if (!function_exists('walletAllocateExpenseToCompanyAdvances')) {
    function walletAllocateExpenseToCompanyAdvances(
        PDO $pdo,
        int $workerId,
        int $expenseId,
        float $amount,
        string $entryDate,
        string $description,
        ?int $createdBy
    ): array {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Alokacja wydatku do portfela wymaga aktywnej transakcji.');
        }
        if ($workerId <= 0 || $expenseId <= 0) {
            throw new RuntimeException('Brak pracownika albo wydatku do alokacji.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Kwota wydatku musi być większa od 0.');
        }

        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM worker_expense_advance_allocations WHERE worker_expense_id = ?");
        $dupStmt->execute([$expenseId]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            throw new RuntimeException('Ten wydatek ma już alokacje do zaliczek firmowych.');
        }

        $buckets = walletGetCompanyAdvanceBalances($pdo, $workerId, true);
        $available = 0.0;
        foreach ($buckets as $bucket) {
            $available += (float)$bucket['amount_remaining'];
        }
        $available = round($available, 2);

        if ($available + 0.001 < $amount) {
            throw new RuntimeException(
                'Kwota wydatku (' . number_format($amount, 2, ',', ' ') .
                ' zł) przekracza dostępne środki w portfelu (' . number_format($available, 2, ',', ' ') . ' zł).'
            );
        }

        $ledgerDesc = 'Wydatek z portfela FIFO';
        $cleanDescription = trim($description);
        if ($cleanDescription !== '') {
            $ledgerDesc .= ': ' . mb_substr($cleanDescription, 0, 180);
        }

        $remainingToAllocate = $amount;
        $allocations = [];
        $firstAdvanceId = null;
        $firstLedgerId = null;

        foreach ($buckets as $bucket) {
            if ($remainingToAllocate <= 0.001) {
                break;
            }

            $advanceId = (int)$bucket['advance_id'];
            $remainingBefore = round((float)$bucket['amount_remaining'], 2);
            if ($remainingBefore <= 0.01) {
                continue;
            }

            $allocated = min($remainingBefore, $remainingToAllocate);
            $allocated = round($allocated, 2);

            $ledgerStmt = $pdo->prepare("
                INSERT INTO worker_ledger
                    (worker_id, entry_type, amount, entry_date, advance_id, expense_id, description, created_by, created_at)
                VALUES
                    (?, 'EXPENSE_DOC', ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ledgerStmt->execute([
                $workerId,
                $allocated,
                $entryDate,
                $advanceId,
                $expenseId,
                $ledgerDesc,
                $createdBy,
            ]);
            $ledgerId = (int)$pdo->lastInsertId();

            $allocStmt = $pdo->prepare("
                INSERT INTO worker_expense_advance_allocations
                    (worker_expense_id, worker_advance_id, amount, created_at)
                VALUES
                    (?, ?, ?, NOW())
            ");
            $allocStmt->execute([$expenseId, $advanceId, $allocated]);

            $remainingAfter = round($remainingBefore - $allocated, 2);
            $statusStmt = $pdo->prepare("UPDATE worker_advances SET status = ? WHERE id = ?");
            $statusStmt->execute([$remainingAfter <= 0.01 ? 'closed' : 'open', $advanceId]);

            if ($firstAdvanceId === null) {
                $firstAdvanceId = $advanceId;
                $firstLedgerId = $ledgerId;
            }

            $allocations[] = [
                'advance_id' => $advanceId,
                'ledger_id' => $ledgerId,
                'amount' => $allocated,
                'remaining_before' => $remainingBefore,
                'remaining_after' => max(0.0, $remainingAfter),
            ];

            $remainingToAllocate = round($remainingToAllocate - $allocated, 2);
        }

        if ($remainingToAllocate > 0.01) {
            throw new RuntimeException('Nie udało się pokryć całego wydatku z portfela FIFO.');
        }

        $updateStmt = $pdo->prepare("
            UPDATE worker_expenses
            SET wallet_advance_id = ?, wallet_ledger_id = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$firstAdvanceId, $firstLedgerId, $expenseId]);

        return [
            'worker_id' => $workerId,
            'expense_id' => $expenseId,
            'amount' => $amount,
            'available_before' => $available,
            'available_after' => round($available - $amount, 2),
            'allocations' => $allocations,
        ];
    }
}
