<?php

if (!function_exists('sprutexCorrectionNumberSeq')) {
    function sprutexCorrectionNumberSeq(?string $number): int
    {
        $number = trim((string)$number);
        if (!preg_match('/^K\s*0*([0-9]+)$/i', $number, $matches)) {
            return 0;
        }

        return (int)$matches[1];
    }
}

if (!function_exists('sprutexStandardInvoiceNumberSeq')) {
    function sprutexStandardInvoiceNumberSeq(?string $number, ?string $month = null, ?string $year = null): int
    {
        $number = trim((string)$number);
        if (!preg_match('/^([0-9]+)\/([0-9]{2})\/([0-9]{4})$/', $number, $matches)) {
            return 0;
        }

        if ($month !== null && $matches[2] !== $month) {
            return 0;
        }

        if ($year !== null && $matches[3] !== $year) {
            return 0;
        }

        return (int)$matches[1];
    }
}

if (!function_exists('sprutexInvoiceRowIsDeleted')) {
    function sprutexInvoiceRowIsDeleted(array $row): bool
    {
        return !empty($row['deleted_at']) && $row['deleted_at'] !== '0000-00-00 00:00:00';
    }
}

if (!function_exists('sprutexInvoiceRowHasExternalReference')) {
    function sprutexInvoiceRowHasExternalReference(array $row): bool
    {
        $sourceSystem = (string)($row['source_system'] ?? 'manual');

        return !empty($row['has_fakturownia_id'])
            || !empty($row['has_fakturownia_number'])
            || !empty($row['has_gov_id'])
            || ($sourceSystem !== '' && $sourceSystem !== 'manual')
            || (int)($row['source_external_id'] ?? 0) > 0;
    }
}

if (!function_exists('sprutexFindReusableLocalStandardInvoice')) {
    function sprutexFindReusableLocalStandardInvoice(PDO $pdo, string $invoiceNumber): array
    {
        $invoiceNumber = trim($invoiceNumber);
        $result = [
            'exists' => false,
            'blocked' => false,
            'blocking_reason' => null,
            'blocking_invoice_ids' => [],
            'reuse_invoice_id' => 0,
        ];

        if ($invoiceNumber === '' || sprutexStandardInvoiceNumberSeq($invoiceNumber) <= 0) {
            return $result;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.fakturownia_number IS NOT NULL AND fi.fakturownia_number <> '' THEN 1 ELSE 0 END) AS has_fakturownia_number,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number = ?
                GROUP BY
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at
                ORDER BY inv.id DESC
            ");
            $stmt->execute([$invoiceNumber]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    0 AS has_fakturownia_number,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number = ?
                GROUP BY
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at
                ORDER BY inv.id DESC
            ");
            $stmt->execute([$invoiceNumber]);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['exists'] = true;

            $invoiceId = (int)($row['id'] ?? 0);
            $status = (string)($row['status'] ?? '');
            $isDeleted = sprutexInvoiceRowIsDeleted($row);
            $hasExternal = sprutexInvoiceRowHasExternalReference($row);

            if ($hasExternal) {
                $result['blocked'] = true;
                $result['blocking_reason'] = 'Numer jest już powiązany z Fakturownią/KSeF.';
                $result['blocking_invoice_ids'][] = $invoiceId;
                continue;
            }

            if ($status === 'draft' && !$isDeleted) {
                $result['blocked'] = true;
                $result['blocking_reason'] = 'Ten numer ma już aktywny szkic faktury.';
                $result['blocking_invoice_ids'][] = $invoiceId;
                continue;
            }

            if ($status === 'cancelled' || $isDeleted) {
                if ($result['reuse_invoice_id'] <= 0) {
                    $result['reuse_invoice_id'] = $invoiceId;
                }
                continue;
            }

            $result['blocked'] = true;
            $result['blocking_reason'] = 'Numer istnieje w aktywnej lokalnej fakturze.';
            $result['blocking_invoice_ids'][] = $invoiceId;
        }

        if ($result['blocked']) {
            $result['reuse_invoice_id'] = 0;
        }

        return $result;
    }
}

if (!function_exists('sprutexHasReusableLocalDeletedStandardInvoiceNumber')) {
    function sprutexHasReusableLocalDeletedStandardInvoiceNumber(PDO $pdo, string $invoiceNumber): bool
    {
        $guard = sprutexFindReusableLocalStandardInvoice($pdo, $invoiceNumber);
        if (empty($guard['blocked']) && !empty($guard['reuse_invoice_id'])) {
            return true;
        }

        $archivedLike = $invoiceNumber . '~local-%';
        try {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.fakturownia_number IS NOT NULL AND fi.fakturownia_number <> '' THEN 1 ELSE 0 END) AS has_fakturownia_number,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number LIKE ?
                GROUP BY
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at
                LIMIT 20
            ");
            $stmt->execute([$archivedLike]);
        } catch (Throwable $e) {
            return false;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = (string)($row['status'] ?? '');
            $isDeleted = sprutexInvoiceRowIsDeleted($row);
            if (!sprutexInvoiceRowHasExternalReference($row) && ($status === 'cancelled' || $isDeleted)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('sprutexShouldIgnoreDetachedFakturowniaNumberForSequence')) {
    function sprutexShouldIgnoreDetachedFakturowniaNumberForSequence(PDO $pdo, array $row): bool
    {
        $number = trim((string)($row['fakturownia_number'] ?? ''));
        if ($number === '') {
            return false;
        }

        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (in_array($status, ['deleted_before_ksef', 'deleted', 'rejected_before_ksef'], true)) {
            return true;
        }

        if ((int)($row['erp_invoice_sale_id'] ?? 0) > 0) {
            return false;
        }

        $govId = trim((string)($row['gov_id'] ?? ''));
        $govStatus = strtolower(trim((string)($row['gov_status'] ?? '')));
        if ($govId !== '' || in_array($govStatus, ['ok', 'demo_ok', 'processing', 'demo_processing', 'accepted'], true)) {
            return false;
        }

        return sprutexHasReusableLocalDeletedStandardInvoiceNumber($pdo, $number);
    }
}

if (!function_exists('sprutexMaxLocalStandardInvoiceSeq')) {
    function sprutexMaxLocalStandardInvoiceSeq(PDO $pdo, string $month, string $year): int
    {
        $maxSeq = 0;
        $pattern = "%/{$month}/{$year}";

        try {
            $stmt = $pdo->prepare("
                SELECT
                    inv.invoice_number,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.fakturownia_number IS NOT NULL AND fi.fakturownia_number <> '' THEN 1 ELSE 0 END) AS has_fakturownia_number,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number LIKE ?
                GROUP BY
                    inv.id,
                    inv.invoice_number,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at
            ");
            $stmt->execute([$pattern]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("
                SELECT
                    inv.invoice_number,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    0 AS has_fakturownia_number,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number LIKE ?
                GROUP BY
                    inv.id,
                    inv.invoice_number,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at
            ");
            $stmt->execute([$pattern]);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $seq = sprutexStandardInvoiceNumberSeq($row['invoice_number'] ?? '', $month, $year);
            if ($seq <= 0) {
                continue;
            }

            $status = (string)($row['status'] ?? '');
            $isDeleted = sprutexInvoiceRowIsDeleted($row);
            $hasExternal = sprutexInvoiceRowHasExternalReference($row);
            if (!$hasExternal && ($status === 'cancelled' || $isDeleted)) {
                continue;
            }

            $maxSeq = max($maxSeq, $seq);
        }

        return $maxSeq;
    }
}

if (!function_exists('sprutexMaxFakturowniaStandardInvoiceSeq')) {
    function sprutexMaxFakturowniaStandardInvoiceSeq(PDO $pdo, string $month, string $year): int
    {
        $maxSeq = 0;
        $pattern = "%/{$month}/{$year}";

        try {
            $stmt = $pdo->prepare("
                SELECT fakturownia_number, erp_invoice_sale_id, fakturownia_id, gov_id, gov_status, status
                FROM fakturownia_invoices
                WHERE fakturownia_number LIKE ?
                  AND created_at >= ?
            ");
            $stmt->execute([$pattern, "{$year}-{$month}-01"]);
        } catch (Throwable $e) {
            return 0;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (sprutexShouldIgnoreDetachedFakturowniaNumberForSequence($pdo, $row)) {
                continue;
            }

            $seq = sprutexStandardInvoiceNumberSeq($row['fakturownia_number'] ?? '', $month, $year);
            if ($seq > 0) {
                $maxSeq = max($maxSeq, $seq);
            }
        }

        return $maxSeq;
    }
}

if (!function_exists('sprutexArchiveLocalInvoiceNumber')) {
    function sprutexArchiveLocalInvoiceNumber(PDO $pdo, int $invoiceId, string $reason): string
    {
        $stmt = $pdo->prepare("
            SELECT
                inv.id,
                inv.invoice_number,
                inv.status,
                inv.source_system,
                inv.source_external_id,
                inv.delete_reason,
                MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                MAX(CASE WHEN fi.fakturownia_number IS NOT NULL AND fi.fakturownia_number <> '' THEN 1 ELSE 0 END) AS has_fakturownia_number,
                MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
            FROM invoices_sale inv
            LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
            WHERE inv.id = ?
            GROUP BY
                inv.id,
                inv.invoice_number,
                inv.status,
                inv.source_system,
                inv.source_external_id,
                inv.delete_reason
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Nie znaleziono lokalnej faktury do archiwizacji numeru.');
        }

        if (sprutexInvoiceRowHasExternalReference($row)) {
            throw new RuntimeException('Nie można zarchiwizować numeru powiązanego z Fakturownią/KSeF.');
        }

        $originalNumber = trim((string)($row['invoice_number'] ?? ''));
        $base = $originalNumber !== '' ? $originalNumber : ('invoice-' . $invoiceId);
        $suffix = '~local-' . $invoiceId;
        $archivedNumber = mb_substr($base, 0, max(1, 100 - mb_strlen($suffix))) . $suffix;
        $attempt = 1;
        while (true) {
            $dup = $pdo->prepare("SELECT id FROM invoices_sale WHERE invoice_number = ? AND id <> ? LIMIT 1");
            $dup->execute([$archivedNumber, $invoiceId]);
            if (!$dup->fetchColumn()) {
                break;
            }
            $extra = '-' . $attempt++;
            $archivedNumber = mb_substr($base, 0, max(1, 100 - mb_strlen($suffix . $extra))) . $suffix . $extra;
        }

        $oldReason = trim((string)($row['delete_reason'] ?? ''));
        $newReason = trim($reason);
        if ($oldReason !== '') {
            $newReason = mb_substr($oldReason . ' | ' . $newReason, 0, 500);
        } else {
            $newReason = mb_substr($newReason, 0, 500);
        }

        $update = $pdo->prepare("
            UPDATE invoices_sale
            SET invoice_number = ?,
                status = 'cancelled',
                deleted_at = COALESCE(deleted_at, ?),
                deleted_by = COALESCE(deleted_by, ?),
                delete_reason = ?
            WHERE id = ?
        ");
        $update->execute([
            $archivedNumber,
            date('Y-m-d H:i:s'),
            $_SESSION['user_id'] ?? null,
            $newReason !== '' ? $newReason : 'Lokalna archiwizacja numeru faktury bez Fakturowni/KSeF',
            $invoiceId,
        ]);

        return $archivedNumber;
    }
}

if (!function_exists('sprutexCorrectionSourceIdFromOptions')) {
    function sprutexCorrectionSourceIdFromOptions($rawOptions): int
    {
        if (!is_string($rawOptions) || trim($rawOptions) === '') {
            return 0;
        }

        $options = json_decode($rawOptions, true);
        if (!is_array($options)) {
            return 0;
        }

        return (int)($options['correction_of_invoice_id'] ?? 0);
    }
}

if (!function_exists('sprutexCorrectionPaymentTerms')) {
    function sprutexCorrectionPaymentTerms(PDO $pdo, int $correctionOfInvoiceId): array
    {
        if ($correctionOfInvoiceId <= 0) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT due_date, payment_days, payment_method
                FROM invoices_sale
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$correctionOfInvoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        if (!$row) {
            return [];
        }

        $dueDate = trim((string)($row['due_date'] ?? ''));
        $paymentMethod = trim((string)($row['payment_method'] ?? ''));
        $paymentDaysRaw = $row['payment_days'] ?? null;

        $terms = [];
        if ($dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $terms['due_date'] = $dueDate;
        }
        if ($paymentDaysRaw !== null && $paymentDaysRaw !== '') {
            $terms['payment_days'] = (int)$paymentDaysRaw;
        }
        if ($paymentMethod !== '') {
            $terms['payment_method'] = $paymentMethod;
        }

        return $terms;
    }
}

if (!function_exists('sprutexDecodeInvoiceJsonOptions')) {
    function sprutexDecodeInvoiceJsonOptions($rawOptions): array
    {
        if (is_array($rawOptions)) {
            return $rawOptions;
        }

        if (!is_string($rawOptions) || trim($rawOptions) === '') {
            return [];
        }

        $decoded = json_decode($rawOptions, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('sprutexInvoiceSaleKind')) {
    function sprutexInvoiceSaleKind(array $invoice): string
    {
        $effectKind = strtolower(trim((string)($invoice['financial_effect_kind'] ?? '')));
        if ($effectKind === 'correction') {
            return 'correction';
        }

        $documentKind = strtolower(trim((string)($invoice['document_kind'] ?? '')));
        if ($documentKind === 'correction' || $documentKind === 'sale_correction') {
            return 'correction';
        }

        $options = sprutexDecodeInvoiceJsonOptions($invoice['fakturownia_options_json'] ?? null);
        $kind = strtolower(trim((string)($options['kind'] ?? '')));
        return $kind === 'correction' ? 'correction' : 'invoice';
    }
}

if (!function_exists('sprutexInvoiceSaleIsCorrection')) {
    function sprutexInvoiceSaleIsCorrection(array $invoice): bool
    {
        return sprutexInvoiceSaleKind($invoice) === 'correction';
    }
}

if (!function_exists('sprutexCorrectionAmount')) {
    function sprutexCorrectionAmount(array $row, array $keys, ?float $fallback = null): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return round((float)$row[$key], 2);
            }
        }

        return $fallback !== null ? round($fallback, 2) : null;
    }
}

if (!function_exists('sprutexCorrectionGrossFromNet')) {
    function sprutexCorrectionGrossFromNet(float $net, $vatRate): float
    {
        $vatRate = trim((string)$vatRate);
        if ($vatRate === '' || strtolower($vatRate) === 'zw' || strtolower($vatRate) === 'np') {
            return round($net, 2);
        }

        return round($net * (1 + ((float)$vatRate / 100)), 2);
    }
}

if (!function_exists('sprutexCalculateInvoiceSaleCorrectionEffect')) {
    function sprutexCalculateInvoiceSaleCorrectionEffect(array $items): array
    {
        $result = [
            'net' => 0.0,
            'vat' => 0.0,
            'gross' => 0.0,
            'has_missing_before' => false,
            'warnings' => [],
            'items' => [],
        ];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $result['has_missing_before'] = true;
                $result['warnings'][] = 'Pozycja korekty #' . ((int)$idx + 1) . ' ma nieprawidlowy format.';
                continue;
            }

            $itemOptions = sprutexDecodeInvoiceJsonOptions($item['fakturownia_item_options_json'] ?? null);
            $before = null;
            if (isset($item['correction_before']) && is_array($item['correction_before'])) {
                $before = $item['correction_before'];
            } elseif (isset($itemOptions['correction_before']) && is_array($itemOptions['correction_before'])) {
                $before = $itemOptions['correction_before'];
            }

            $label = trim((string)($item['item_name'] ?? ($item['name'] ?? ('#' . ((int)$idx + 1)))));
            if (!$before) {
                $result['has_missing_before'] = true;
                $result['warnings'][] = 'Brak wartosci przed korekta dla pozycji: ' . $label;
                $result['items'][] = [
                    'label' => $label,
                    'net' => 0.0,
                    'vat' => 0.0,
                    'gross' => 0.0,
                    'missing_before' => true,
                ];
                continue;
            }

            $afterNet = sprutexCorrectionAmount($item, ['amount_net'], 0.0) ?? 0.0;
            $afterGrossFallback = null;
            if (array_key_exists('amount_vat', $item) && $item['amount_vat'] !== null && $item['amount_vat'] !== '') {
                $afterGrossFallback = $afterNet + (float)$item['amount_vat'];
            }
            $afterGross = sprutexCorrectionAmount($item, ['amount_gross'], $afterGrossFallback);
            if ($afterGross === null) {
                $afterGross = sprutexCorrectionGrossFromNet($afterNet, $item['vat_rate'] ?? ($item['vat'] ?? '23'));
            }
            $afterVat = sprutexCorrectionAmount($item, ['amount_vat'], $afterGross - $afterNet) ?? 0.0;

            $beforeNet = sprutexCorrectionAmount($before, ['amount_net'], null);
            if ($beforeNet === null) {
                $beforeQty = (float)($before['quantity'] ?? 0);
                $beforePrice = (float)($before['unit_price_net'] ?? ($before['price_net'] ?? 0));
                $beforeNet = round($beforeQty * $beforePrice, 2);
            }

            $beforeGrossFallback = null;
            if (array_key_exists('amount_vat', $before) && $before['amount_vat'] !== null && $before['amount_vat'] !== '') {
                $beforeGrossFallback = $beforeNet + (float)$before['amount_vat'];
            }
            $beforeGross = sprutexCorrectionAmount($before, ['amount_gross'], $beforeGrossFallback);
            if ($beforeGross === null) {
                $beforeGross = sprutexCorrectionGrossFromNet($beforeNet, $before['vat_rate'] ?? ($before['vat'] ?? ($item['vat_rate'] ?? '23')));
            }
            $beforeVat = sprutexCorrectionAmount($before, ['amount_vat'], $beforeGross - $beforeNet) ?? 0.0;

            $effectNet = round($afterNet - $beforeNet, 2);
            $effectVat = round($afterVat - $beforeVat, 2);
            $effectGross = round($afterGross - $beforeGross, 2);

            $result['net'] = round($result['net'] + $effectNet, 2);
            $result['vat'] = round($result['vat'] + $effectVat, 2);
            $result['gross'] = round($result['gross'] + $effectGross, 2);
            $result['items'][] = [
                'label' => $label,
                'net' => $effectNet,
                'vat' => $effectVat,
                'gross' => $effectGross,
                'missing_before' => false,
            ];
        }

        return $result;
    }
}

if (!function_exists('sprutexEffectiveInvoiceSaleNet')) {
    function sprutexEffectiveInvoiceSaleNet(array $invoice): float
    {
        if (sprutexInvoiceSaleIsCorrection($invoice)) {
            return round((float)($invoice['correction_effect_net'] ?? 0), 2);
        }

        return round((float)($invoice['amount_net'] ?? 0), 2);
    }
}

if (!function_exists('sprutexEffectiveInvoiceSaleGross')) {
    function sprutexEffectiveInvoiceSaleGross(array $invoice): float
    {
        if (sprutexInvoiceSaleIsCorrection($invoice)) {
            return round((float)($invoice['correction_effect_gross'] ?? 0), 2);
        }

        return round((float)($invoice['amount_gross'] ?? 0), 2);
    }
}

if (!function_exists('sprutexFindActiveDraftCorrectionForSource')) {
    function sprutexFindActiveDraftCorrectionForSource(PDO $pdo, int $correctionOfInvoiceId): int
    {
        if ($correctionOfInvoiceId <= 0) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.source_system,
                    inv.source_external_id,
                    inv.fakturownia_options_json,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.status = 'draft'
                  AND inv.fakturownia_options_json LIKE '%correction_of_invoice_id%'
                  AND (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')
                GROUP BY
                    inv.id,
                    inv.source_system,
                    inv.source_external_id,
                    inv.fakturownia_options_json
                ORDER BY inv.id DESC
                LIMIT 100
            ");
            $stmt->execute();
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.source_system,
                    inv.source_external_id,
                    inv.fakturownia_options_json,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.status = 'draft'
                  AND inv.fakturownia_options_json LIKE '%correction_of_invoice_id%'
                GROUP BY
                    inv.id,
                    inv.source_system,
                    inv.source_external_id,
                    inv.fakturownia_options_json
                ORDER BY inv.id DESC
                LIMIT 100
            ");
            $stmt->execute();
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sourceSystem = (string)($row['source_system'] ?? 'manual');
            $hasExternal = !empty($row['has_fakturownia_id'])
                || !empty($row['has_gov_id'])
                || ($sourceSystem !== '' && $sourceSystem !== 'manual')
                || (int)($row['source_external_id'] ?? 0) > 0;
            if ($hasExternal) {
                continue;
            }

            if (sprutexCorrectionSourceIdFromOptions($row['fakturownia_options_json'] ?? null) === $correctionOfInvoiceId) {
                return (int)($row['id'] ?? 0);
            }
        }

        return 0;
    }
}

if (!function_exists('sprutexFindReusableLocalCorrectionInvoice')) {
    /**
     * Decides whether a correction number can be reused safely.
     *
     * A correction number is blocked once it exists outside ERP (Fakturownia/KSeF)
     * or belongs to an active local invoice. Local-only failed/cancelled drafts can
     * be reused so the user does not get stuck on K04 -> K05 loops after API 422.
     */
    function sprutexFindReusableLocalCorrectionInvoice(PDO $pdo, string $invoiceNumber, ?int $correctionOfInvoiceId = null): array
    {
        $invoiceNumber = trim($invoiceNumber);
        $result = [
            'exists' => false,
            'blocked' => false,
            'blocking_reason' => null,
            'blocking_invoice_ids' => [],
            'reuse_invoice_id' => 0,
        ];

        if ($invoiceNumber === '' || sprutexCorrectionNumberSeq($invoiceNumber) <= 0) {
            return $result;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    inv.fakturownia_options_json,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number = ?
                GROUP BY
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.deleted_at,
                    inv.fakturownia_options_json
                ORDER BY inv.id DESC
            ");
            $stmt->execute([$invoiceNumber]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("
                SELECT
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    NULL AS deleted_at,
                    inv.fakturownia_options_json,
                    MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                    MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
                FROM invoices_sale inv
                LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
                WHERE inv.invoice_number = ?
                GROUP BY
                    inv.id,
                    inv.status,
                    inv.source_system,
                    inv.source_external_id,
                    inv.fakturownia_options_json
                ORDER BY inv.id DESC
            ");
            $stmt->execute([$invoiceNumber]);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['exists'] = true;

            $invoiceId = (int)($row['id'] ?? 0);
            $status = (string)($row['status'] ?? '');
            $sourceSystem = (string)($row['source_system'] ?? 'manual');
            $sourceExternalId = (int)($row['source_external_id'] ?? 0);
            $isDeleted = !empty($row['deleted_at']) && $row['deleted_at'] !== '0000-00-00 00:00:00';
            $hasExternal = !empty($row['has_fakturownia_id'])
                || !empty($row['has_gov_id'])
                || ($sourceSystem !== '' && $sourceSystem !== 'manual')
                || $sourceExternalId > 0;

            if ($hasExternal) {
                $result['blocked'] = true;
                $result['blocking_reason'] = 'Numer jest już powiązany z Fakturownią/KSeF.';
                $result['blocking_invoice_ids'][] = $invoiceId;
                continue;
            }

            if ($status === 'draft' && !$isDeleted) {
                $sourceId = sprutexCorrectionSourceIdFromOptions($row['fakturownia_options_json'] ?? null);
                if ($correctionOfInvoiceId === null || $correctionOfInvoiceId <= 0 || $sourceId === (int)$correctionOfInvoiceId) {
                    if ($result['reuse_invoice_id'] <= 0) {
                        $result['reuse_invoice_id'] = $invoiceId;
                    }
                    continue;
                }

                $result['blocked'] = true;
                $result['blocking_reason'] = 'Ten numer ma już aktywną roboczą korektę do innej faktury.';
                $result['blocking_invoice_ids'][] = $invoiceId;
                continue;
            }

            if ($status === 'cancelled' || $isDeleted) {
                if ($result['reuse_invoice_id'] <= 0) {
                    $result['reuse_invoice_id'] = $invoiceId;
                }
                continue;
            }

            $result['blocked'] = true;
            $result['blocking_reason'] = 'Numer istnieje w aktywnej lokalnej fakturze.';
            $result['blocking_invoice_ids'][] = $invoiceId;
        }

        if ($result['blocked']) {
            $result['reuse_invoice_id'] = 0;
        }

        return $result;
    }
}

if (!function_exists('sprutexArchiveLocalCorrectionInvoiceNumber')) {
    function sprutexArchiveLocalCorrectionInvoiceNumber(PDO $pdo, int $invoiceId, string $reason): string
    {
        $stmt = $pdo->prepare("
            SELECT
                inv.id,
                inv.invoice_number,
                inv.status,
                inv.source_system,
                inv.source_external_id,
                inv.delete_reason,
                MAX(CASE WHEN fi.fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS has_fakturownia_id,
                MAX(CASE WHEN fi.gov_id IS NOT NULL AND fi.gov_id <> '' THEN 1 ELSE 0 END) AS has_gov_id
            FROM invoices_sale inv
            LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
            WHERE inv.id = ?
            GROUP BY
                inv.id,
                inv.invoice_number,
                inv.status,
                inv.source_system,
                inv.source_external_id,
                inv.delete_reason
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Nie znaleziono lokalnej korekty do archiwizacji.');
        }

        $sourceSystem = (string)($row['source_system'] ?? 'manual');
        $hasExternal = !empty($row['has_fakturownia_id'])
            || !empty($row['has_gov_id'])
            || ($sourceSystem !== '' && $sourceSystem !== 'manual')
            || (int)($row['source_external_id'] ?? 0) > 0;
        if ($hasExternal) {
            throw new RuntimeException('Nie można zarchiwizować numeru powiązanego z Fakturownią/KSeF.');
        }

        $originalNumber = trim((string)($row['invoice_number'] ?? ''));
        $base = $originalNumber !== '' ? $originalNumber : ('invoice-' . $invoiceId);
        $suffix = '~local-' . $invoiceId;
        $archivedNumber = mb_substr($base, 0, max(1, 100 - mb_strlen($suffix))) . $suffix;
        $attempt = 1;
        while (true) {
            $dup = $pdo->prepare("SELECT id FROM invoices_sale WHERE invoice_number = ? AND id <> ? LIMIT 1");
            $dup->execute([$archivedNumber, $invoiceId]);
            if (!$dup->fetchColumn()) {
                break;
            }
            $extra = '-' . $attempt++;
            $archivedNumber = mb_substr($base, 0, max(1, 100 - mb_strlen($suffix . $extra))) . $suffix . $extra;
        }

        $oldReason = trim((string)($row['delete_reason'] ?? ''));
        $newReason = trim($reason);
        if ($oldReason !== '') {
            $newReason = mb_substr($oldReason . ' | ' . $newReason, 0, 500);
        } else {
            $newReason = mb_substr($newReason, 0, 500);
        }

        $update = $pdo->prepare("
            UPDATE invoices_sale
            SET invoice_number = ?,
                status = 'cancelled',
                deleted_at = COALESCE(deleted_at, ?),
                deleted_by = COALESCE(deleted_by, ?),
                delete_reason = ?
            WHERE id = ?
        ");
        $update->execute([
            $archivedNumber,
            date('Y-m-d H:i:s'),
            $_SESSION['user_id'] ?? null,
            $newReason !== '' ? $newReason : 'Lokalna archiwizacja numeru korekty bez Fakturowni/KSeF',
            $invoiceId,
        ]);

        return $archivedNumber;
    }
}
