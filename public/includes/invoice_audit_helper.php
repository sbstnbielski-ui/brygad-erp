<?php

if (!function_exists('invoiceAuditLog')) {
    function invoiceAuditLog(
        PDO $pdo,
        ?int $invoiceSaleId,
        string $action,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null,
        ?string $reason = null,
        string $source = 'erp',
        ?int $externalFakturowniaId = null,
        ?string $externalGovId = null
    ): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO invoice_audit_log
                    (invoice_sale_id, action, old_values, new_values, user_id, reason, source, external_fakturownia_id, external_gov_id)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $invoiceSaleId ?: null,
                mb_substr($action, 0, 80),
                $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $userId ?: null,
                $reason ? mb_substr($reason, 0, 500) : null,
                mb_substr($source ?: 'erp', 0, 80),
                $externalFakturowniaId ?: null,
                $externalGovId ?: null,
            ]);
        } catch (Throwable $e) {
            if (function_exists('logEvent')) {
                logEvent('Invoice audit skipped: ' . $e->getMessage(), 'WARNING');
            }
        }
    }
}

if (!function_exists('invoiceFakturowniaMappingBySaleId')) {
    function invoiceFakturowniaMappingBySaleId(PDO $pdo, int $invoiceSaleId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM fakturownia_invoices
            WHERE erp_invoice_sale_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$invoiceSaleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

