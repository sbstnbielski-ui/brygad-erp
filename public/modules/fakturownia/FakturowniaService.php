<?php
/**
 * BRYGAD ERP - Serwis biznesowy integracji Fakturownia
 */

require_once __DIR__ . '/FakturowniaClient.php';
require_once __DIR__ . '/FakturowniaMapper.php';
$sprutexInvoiceAuditHelper = dirname(__DIR__, 2) . '/includes/invoice_audit_helper.php';
if (is_file($sprutexInvoiceAuditHelper)) {
    require_once $sprutexInvoiceAuditHelper;
}
$sprutexCorrectionHelper = dirname(__DIR__, 2) . '/includes/sales_invoice_correction_helper.php';
if (is_file($sprutexCorrectionHelper)) {
    require_once $sprutexCorrectionHelper;
}

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

class FakturowniaService
{
    /** @var PDO */
    private $pdo;

    /** @var FakturowniaClient */
    private $client;

    /** @var FakturowniaMapper */
    private $mapper;

    public function __construct($pdo = null, $client = null, $mapper = null)
    {
        $this->pdo = $pdo ?: $this->getDefaultPdo();
        $this->client = $client ?: new FakturowniaClient($this->pdo);
        $this->mapper = $mapper ?: new FakturowniaMapper();
    }

    public function syncClient(int $erpClientId): array
    {
        try {
            $erpClient = $this->findErpClient($erpClientId);
            if (!$erpClient) {
                throw new RuntimeException('Nie znaleziono klienta ERP o ID: ' . $erpClientId);
            }

            $mapping = $this->findClientMapping($erpClientId);
            $payload = $this->mapper->contractClientToFakturownia($erpClient);

            if (!empty($mapping['fakturownia_id'])) {
                $endpoint = '/clients/' . (int)$mapping['fakturownia_id'] . '.json';
                $response = $this->client->put($endpoint, $payload);
                $fakturowniaId = (int)$mapping['fakturownia_id'];
                $mode = 'updated';
            } else {
                $response = $this->client->post('/clients.json', $payload);
                $fakturowniaId = $this->extractClientId($response['data'] ?? []);
                $mode = 'created';
            }

            if ($fakturowniaId <= 0) {
                throw new RuntimeException('Brak ID klienta w odpowiedzi Fakturowni.');
            }

            $this->saveClientMapping($erpClientId, $fakturowniaId);

            return [
                'success' => true,
                'data' => [
                    'erp_client_id' => $erpClientId,
                    'fakturownia_id' => $fakturowniaId,
                    'mode' => $mode,
                    'api' => $response['data'] ?? [],
                ],
                'error' => null,
            ];
        } catch (FakturowniaAuthException $e) {
            $this->logServiceError('/clients.json', 'POST', '[CRITICAL AUTH] ' . $e->getMessage(), ['erp_client_id' => $erpClientId]);
            if (function_exists('logEvent')) {
                logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            $this->logServiceError('/clients.json', 'POST', $e->getMessage(), ['erp_client_id' => $erpClientId]);
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    public function createInvoice(int $erpMilestoneId): array
    {
        try {
            $milestone = $this->findMilestone($erpMilestoneId);
            if (!$milestone) {
                throw new RuntimeException('Nie znaleziono rekordu project_revenues o ID: ' . $erpMilestoneId);
            }

            if (empty($milestone['investor_id'])) {
                throw new RuntimeException('Projekt nie ma przypisanego inwestora/klienta.');
            }

            $requestHash = $this->buildRequestHash($milestone);
            $existing = $this->findInvoiceByRequestHash($requestHash);

            if ($existing) {
                return [
                    'success' => true,
                    'data' => $existing + ['duplicate' => true],
                    'error' => null,
                ];
            }

            $clientSync = $this->syncClient((int)$milestone['investor_id']);
            if (!$clientSync['success']) {
                if (!empty($clientSync['auth_failure'])) {
                    return ['success' => false, 'data' => null, 'error' => $clientSync['error'], 'auth_failure' => true];
                }
                throw new RuntimeException('Nie udało się zsynchronizować klienta: ' . $clientSync['error']);
            }

            $fakturowniaClientId = (int)$clientSync['data']['fakturownia_id'];
            $contractData = $this->buildContractDataFromMilestone($milestone);
            $payload = $this->mapper->milestoneToInvoice($milestone, $contractData);
            $payload['invoice']['client_id'] = $fakturowniaClientId;

            $response = $this->client->post('/invoices.json', $payload);
            $mapped = $this->mapper->fakturowniaResponseToErp($response['data'] ?? []);
            $fakturowniaId = (int)($mapped['fakturownia_id'] ?? 0);

            if ($fakturowniaId <= 0) {
                throw new RuntimeException('Brak ID faktury w odpowiedzi Fakturowni.');
            }

            $this->saveInvoiceMapping([
                'erp_contract_id' => (int)$milestone['project_id'],
                'erp_milestone_id' => (int)$milestone['id'],
                'fakturownia_id' => $fakturowniaId,
                'fakturownia_number' => (string)($mapped['fakturownia_number'] ?? ''),
                'gov_id' => (string)($mapped['gov_id'] ?? ''),
                'gov_status' => (string)($mapped['gov_status'] ?? 'pending'),
                'status' => (string)($mapped['status'] ?? 'draft'),
                'pdf_path' => null,
                'request_hash' => $requestHash,
            ]);

            return [
                'success' => true,
                'data' => [
                    'fakturownia_id' => $fakturowniaId,
                    'fakturownia_number' => $mapped['fakturownia_number'] ?? null,
                    'gov_status' => $mapped['gov_status'] ?? 'pending',
                    'duplicate' => false,
                    'api' => $response['data'] ?? [],
                ],
                'error' => null,
            ];
        } catch (FakturowniaAuthException $e) {
            $this->logServiceError('/invoices.json', 'POST', '[CRITICAL AUTH] ' . $e->getMessage(), ['erp_milestone_id' => $erpMilestoneId]);
            if (function_exists('logEvent')) {
                logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            $this->logServiceError(
                '/invoices.json',
                'POST',
                $e->getMessage(),
                ['erp_milestone_id' => $erpMilestoneId]
            );
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    public function createInvoiceFromMilestone(int $erpMilestoneId): array
    {
        return $this->createInvoice($erpMilestoneId);
    }

    /**
     * Tworzy fakturę w Fakturowni z lokalnego modułu invoices_sale/invoice_sale_items.
     * Domyślnie NIE wysyła do KSeF; wysyłka jest osobnym świadomym krokiem.
     */
    public function createInvoiceFromSalesInvoice(int $erpInvoiceSaleId, bool $sendToKsef = false): array
    {
        try {
            $invoice = $this->findSalesInvoice($erpInvoiceSaleId);
            if (!$invoice) {
                throw new RuntimeException('Nie znaleziono faktury sprzedażowej ERP o ID: ' . $erpInvoiceSaleId);
            }

            if (empty($invoice['client_id'])) {
                throw new RuntimeException('Faktura nie ma przypisanego klienta.');
            }

            $items = $this->findSalesInvoiceItems($erpInvoiceSaleId);
            if (empty($items)) {
                throw new RuntimeException('Faktura musi zawierać przynajmniej jedną pozycję.');
            }

            $invoiceOptions = json_decode((string)($invoice['fakturownia_options_json'] ?? '{}'), true) ?: [];
            $invoice = $this->applyCorrectionPaymentTermsFromSource($invoice, $invoiceOptions);
            $erpCorrectionOfId = 0;
            if (($invoiceOptions['kind'] ?? '') === 'correction') {
                $erpCorrectionOfId = (int)($invoiceOptions['correction_of_invoice_id'] ?? 0);
            }

            $requestHash = $this->buildSalesInvoiceRequestHash($invoice, $items);
            $existing = $this->findInvoiceByRequestHash($requestHash);
            if ($existing) {
                $this->syncOfficialNumberFromFakturownia(
                    $erpInvoiceSaleId,
                    (string)($existing['fakturownia_number'] ?? ''),
                    (int)($existing['fakturownia_id'] ?? 0),
                    (string)($existing['gov_id'] ?? ''),
                    'duplicate_request_hash'
                );
                return [
                    'success' => true,
                    'data' => $existing + ['duplicate' => true],
                    'error' => null,
                ];
            }

            $clientSync = $this->syncClient((int)$invoice['client_id']);
            if (!$clientSync['success']) {
                if (!empty($clientSync['auth_failure'])) {
                    return ['success' => false, 'data' => null, 'error' => $clientSync['error'], 'auth_failure' => true];
                }
                throw new RuntimeException('Nie udało się zsynchronizować klienta: ' . $clientSync['error']);
            }

            $fakturowniaClientId = (int)$clientSync['data']['fakturownia_id'];

            // Dla faktury korygującej — ustal fakturownia_id faktury korygowanej
            $correctionFakturowniaId = null;
            if (($invoiceOptions['kind'] ?? '') === 'correction') {
                if ($erpCorrectionOfId > 0) {
                    $correctionFakturowniaId = $this->findFakturowniaIdByErpInvoiceSaleId($erpCorrectionOfId);
                }
            }

            $payload = $this->mapper->invoiceSaleToInvoice($invoice, $items, $correctionFakturowniaId);
            $payload['invoice']['client_id'] = $fakturowniaClientId;

            $endpoint = $sendToKsef ? '/invoices.json?gov_save_and_send=1' : '/invoices.json';
            $response = $this->client->post($endpoint, $payload);
            $mapped = $this->mapper->fakturowniaResponseToErp($response['data'] ?? []);
            $fakturowniaId = (int)($mapped['fakturownia_id'] ?? 0);

            if ($fakturowniaId <= 0) {
                throw new RuntimeException('Brak ID faktury w odpowiedzi Fakturowni.');
            }

            $singleProjectId = $this->extractSingleProjectIdFromAllocations($erpInvoiceSaleId);

            $this->saveInvoiceMapping([
                'erp_contract_id' => $singleProjectId,
                'erp_milestone_id' => null,
                'erp_invoice_sale_id' => $erpInvoiceSaleId,
                'fakturownia_id' => $fakturowniaId,
                'fakturownia_number' => (string)($mapped['fakturownia_number'] ?? ''),
                'gov_id' => (string)($mapped['gov_id'] ?? ''),
                'gov_status' => (string)($mapped['gov_status'] ?? 'pending'),
                'status' => (string)($mapped['status'] ?? 'sent'),
                'pdf_path' => null,
                'request_hash' => $requestHash,
            ]);

            $this->syncOfficialNumberFromFakturownia(
                $erpInvoiceSaleId,
                (string)($mapped['fakturownia_number'] ?? ''),
                $fakturowniaId,
                (string)($mapped['gov_id'] ?? ''),
                'fakturownia_create'
            );

            return [
                'success' => true,
                'data' => [
                    'erp_invoice_sale_id' => $erpInvoiceSaleId,
                    'fakturownia_id' => $fakturowniaId,
                    'fakturownia_number' => $mapped['fakturownia_number'] ?? null,
                    'gov_status' => $mapped['gov_status'] ?? 'pending',
                    'sent_to_ksef' => $sendToKsef,
                    'duplicate' => false,
                    'api' => $response['data'] ?? [],
                ],
                'error' => null,
            ];
        } catch (FakturowniaAuthException $e) {
            $this->logServiceError('/invoices.json', 'POST', '[CRITICAL AUTH] ' . $e->getMessage(), ['erp_invoice_sale_id' => $erpInvoiceSaleId]);
            if (function_exists('logEvent')) {
                logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            $this->logServiceError('/invoices.json', 'POST', $e->getMessage(), ['erp_invoice_sale_id' => $erpInvoiceSaleId]);
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Wystawia fakturę JST (KSeF Podmiot2/Podmiot3) z lokalnego modułu invoices_sale.
     *
     * Działa identycznie jak createInvoiceFromSalesInvoice(), ale:
     *  - wymaga rekordu w invoice_sale_jst_data dla tej faktury
     *  - używa invoiceSaleToInvoiceJst() zamiast invoiceSaleToInvoice()
     *  - domyślnie tworzy tylko w Fakturowni; KSeF jest osobnym krokiem
     */
    public function createJstInvoiceFromSalesInvoice(int $erpInvoiceSaleId, bool $sendToKsef = false): array
    {
        try {
            $invoice = $this->findSalesInvoice($erpInvoiceSaleId);
            if (!$invoice) {
                throw new RuntimeException('Nie znaleziono faktury sprzedażowej ERP o ID: ' . $erpInvoiceSaleId);
            }

            if (empty($invoice['client_id'])) {
                throw new RuntimeException('Faktura nie ma przypisanego klienta.');
            }

            $items = $this->findSalesInvoiceItems($erpInvoiceSaleId);
            if (empty($items)) {
                throw new RuntimeException('Faktura musi zawierać przynajmniej jedną pozycję.');
            }

            $jstStmt = $this->pdo->prepare('SELECT * FROM invoice_sale_jst_data WHERE invoice_sale_id = ? LIMIT 1');
            $jstStmt->execute([$erpInvoiceSaleId]);
            $jstData = $jstStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$jstData || trim((string)($jstData['jst_buyer_name'] ?? '')) === '') {
                throw new RuntimeException('Brak danych JST dla faktury ID: ' . $erpInvoiceSaleId . '. Uzupełnij dane nabywcy JST przed wystawieniem.');
            }

            $invoiceOptions = json_decode((string)($invoice['fakturownia_options_json'] ?? '{}'), true) ?: [];
            $invoice = $this->applyCorrectionPaymentTermsFromSource($invoice, $invoiceOptions);

            // Idempotencja — hash uwzględnia flagę JST żeby nie kolidować z normalnym wystawieniem
            $requestHash = 'jst_' . $this->buildSalesInvoiceRequestHash($invoice, $items);
            $existing = $this->findInvoiceByRequestHash($requestHash);
            if ($existing) {
                $this->syncOfficialNumberFromFakturownia(
                    $erpInvoiceSaleId,
                    (string)($existing['fakturownia_number'] ?? ''),
                    (int)($existing['fakturownia_id'] ?? 0),
                    (string)($existing['gov_id'] ?? ''),
                    'duplicate_request_hash_jst'
                );
                return [
                    'success' => true,
                    'data' => $existing + ['duplicate' => true],
                    'error' => null,
                ];
            }

            $clientSync = $this->syncClient((int)$invoice['client_id']);
            if (!$clientSync['success']) {
                if (!empty($clientSync['auth_failure'])) {
                    return ['success' => false, 'data' => null, 'error' => $clientSync['error'], 'auth_failure' => true];
                }
                throw new RuntimeException('Nie udało się zsynchronizować klienta: ' . $clientSync['error']);
            }

            $fakturowniaClientId = (int)$clientSync['data']['fakturownia_id'];

            $correctionFakturowniaId = null;
            if (($invoiceOptions['kind'] ?? '') === 'correction') {
                $erpCorrectionOfId = (int)($invoiceOptions['correction_of_invoice_id'] ?? 0);
                if ($erpCorrectionOfId > 0) {
                    $correctionFakturowniaId = $this->findFakturowniaIdByErpInvoiceSaleId($erpCorrectionOfId);
                }
            }

            $payload = $this->mapper->invoiceSaleToInvoiceJst($invoice, $items, $jstData, $correctionFakturowniaId);
            // Dla faktury JST celowo NIE ustawiamy client_id — Fakturownia musi użyć
            // pól buyer_* z danych JST (Podmiot2), a nie danych karty klienta ERP.
            // Gdyby client_id był ustawiony, Fakturownia mogłaby nadpisać buyer_name/buyer_tax_no
            // danymi z karty klienta, co wypaczałoby Podmiot2 na fakturze KSeF.
            // Fakturownia sama dopasuje klienta po buyer_tax_no lub stworzy nowego.
            unset($payload['invoice']['client_id']);

            $endpoint = $sendToKsef ? '/invoices.json?gov_save_and_send=1' : '/invoices.json';
            $response = $this->client->post($endpoint, $payload);
            $mapped = $this->mapper->fakturowniaResponseToErp($response['data'] ?? []);
            $fakturowniaId = (int)($mapped['fakturownia_id'] ?? 0);

            if ($fakturowniaId <= 0) {
                throw new RuntimeException('Brak ID faktury w odpowiedzi Fakturowni.');
            }

            $singleProjectId = $this->extractSingleProjectIdFromAllocations($erpInvoiceSaleId);

            $this->saveInvoiceMapping([
                'erp_contract_id'      => $singleProjectId,
                'erp_milestone_id'     => null,
                'erp_invoice_sale_id'  => $erpInvoiceSaleId,
                'fakturownia_id'       => $fakturowniaId,
                'fakturownia_number'   => (string)($mapped['fakturownia_number'] ?? ''),
                'gov_id'               => (string)($mapped['gov_id'] ?? ''),
                'gov_status'           => (string)($mapped['gov_status'] ?? 'pending'),
                'status'               => (string)($mapped['status'] ?? 'sent'),
                'pdf_path'             => null,
                'request_hash'         => $requestHash,
            ]);

            $this->syncOfficialNumberFromFakturownia(
                $erpInvoiceSaleId,
                (string)($mapped['fakturownia_number'] ?? ''),
                $fakturowniaId,
                (string)($mapped['gov_id'] ?? ''),
                'fakturownia_create_jst'
            );

            return [
                'success' => true,
                'data' => [
                    'erp_invoice_sale_id' => $erpInvoiceSaleId,
                    'fakturownia_id'      => $fakturowniaId,
                    'fakturownia_number'  => $mapped['fakturownia_number'] ?? null,
                    'gov_status'          => $mapped['gov_status'] ?? 'pending',
                    'sent_to_ksef'        => $sendToKsef,
                    'duplicate'           => false,
                    'api'                 => $response['data'] ?? [],
                ],
                'error' => null,
            ];
        } catch (FakturowniaAuthException $e) {
            $this->logServiceError('/invoices.json', 'POST', '[CRITICAL AUTH] ' . $e->getMessage(), ['erp_invoice_sale_id' => $erpInvoiceSaleId, 'jst' => true]);
            if (function_exists('logEvent')) {
                logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            $this->logServiceError('/invoices.json', 'POST', $e->getMessage(), ['erp_invoice_sale_id' => $erpInvoiceSaleId, 'jst' => true]);
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    public function downloadInvoicePdf(int $fakturowniaId): array
    {
        try {
            $pdfBinary = $this->client->downloadPdf((string)$fakturowniaId);
            return ['success' => true, 'data' => $pdfBinary, 'error' => null];
        } catch (FakturowniaAuthException $e) {
            $this->logServiceError('/invoices/' . $fakturowniaId . '.pdf', 'GET', '[CRITICAL AUTH] ' . $e->getMessage(), ['fakturownia_id' => $fakturowniaId]);
            if (function_exists('logEvent')) {
                logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            $this->logServiceError(
                '/invoices/' . $fakturowniaId . '.pdf',
                'GET',
                $e->getMessage(),
                ['fakturownia_id' => $fakturowniaId]
            );
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Wysyła istniejącą fakturę do KSeF (jeśli jeszcze nie wysłano).
     */
    public function sendToKsef(int $fakturowniaId): array
    {
        try {
            $endpoint = '/invoices/' . $fakturowniaId . '.json?send_to_ksef=yes';
            $response = $this->client->get($endpoint);
            $data = $response['data'] ?? [];
            $invoice = $data['invoice'] ?? $data;

            $govStatus = $this->normalizeKsefGovStatus($invoice['gov_status'] ?? 'pending');
            $govId = $invoice['gov_id'] ?? null;

            $oldMapping = $this->findInvoiceMappingByFakturowniaId($fakturowniaId);
            $stmt = $this->pdo->prepare("UPDATE fakturownia_invoices SET gov_status = ?, gov_id = ?, synced_at = NOW() WHERE fakturownia_id = ?");
            $stmt->execute([$govStatus, $govId, $fakturowniaId]);
            $this->auditKsefChange($oldMapping, $fakturowniaId, $govStatus, $govId, 'send_to_ksef');

            return ['success' => true, 'data' => ['gov_status' => $govStatus, 'gov_id' => $govId], 'error' => null];
        } catch (FakturowniaAuthException $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Usuwa dokument z Fakturowni przed wysyłką do KSeF i cofa lokalną fakturę do edycji.
     *
     * Nie wykonuje lokalnych zmian, jeśli Fakturownia nie potwierdzi usunięcia.
     */
    public function deleteFakturowniaInvoiceBeforeKsef(int $erpInvoiceSaleId): array
    {
        try {
            $invoice = $this->findSalesInvoice($erpInvoiceSaleId);
            if (!$invoice) {
                throw new RuntimeException('Nie znaleziono faktury sprzedażowej ERP o ID: ' . $erpInvoiceSaleId);
            }

            $stmt = $this->pdo->prepare("
                SELECT *
                FROM fakturownia_invoices
                WHERE erp_invoice_sale_id = ?
                  AND fakturownia_id IS NOT NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$erpInvoiceSaleId]);
            $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mapping) {
                throw new RuntimeException('Nie znaleziono mapowania Fakturowni dla tej faktury.');
            }

            $fakturowniaId = (int)($mapping['fakturownia_id'] ?? 0);
            $govId = trim((string)($mapping['gov_id'] ?? ''));
            $govStatus = strtolower(trim((string)($mapping['gov_status'] ?? 'pending')));
            if ($fakturowniaId <= 0) {
                throw new RuntimeException('Brak ID Fakturowni dla tej faktury.');
            }
            if ($govId !== '' || in_array($govStatus, ['ok', 'demo_ok', 'processing', 'demo_processing'], true)) {
                throw new RuntimeException('Nie można usunąć dokumentu, który został już wysłany lub przyjęty w KSeF.');
            }

            try {
                $deleteResponse = $this->client->delete('/invoices/' . $fakturowniaId . '.json');
            } catch (RuntimeException $e) {
                if (strpos($e->getMessage(), 'HTTP 404') === false) {
                    throw $e;
                }

                $deleteResponse = [
                    'success' => true,
                    'http_status' => 404,
                    'data' => [
                        'already_missing_in_fakturownia' => true,
                        'message' => $e->getMessage(),
                    ],
                ];
            }

            $this->pdo->beginTransaction();

            $archiveHash = md5('fakturownia-deleted-before-ksef|' . (int)$mapping['id'] . '|' . (string)($mapping['request_hash'] ?? '') . '|' . microtime(true));
            $this->pdo->prepare("
                UPDATE fakturownia_invoices
                SET erp_invoice_sale_id = NULL,
                    request_hash = ?,
                    fakturownia_number = CASE
                        WHEN fakturownia_number IS NULL OR fakturownia_number = '' THEN fakturownia_number
                        ELSE LEFT(CONCAT(fakturownia_number, '~deleted-', id), 100)
                    END,
                    synced_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$archiveHash, (int)$mapping['id']]);

            $reason = 'Dokument Fakturowni ID ' . $fakturowniaId . ' usunięty przed wysyłką do KSeF - powrót do edycji';
            $this->pdo->prepare("
                UPDATE invoices_sale
                SET status = 'draft',
                    deleted_at = NULL,
                    deleted_by = NULL,
                    delete_reason = NULL,
                    sync_attention_required = 0,
                    sync_attention_note = NULL
                WHERE id = ?
            ")->execute([$erpInvoiceSaleId]);

            invoiceAuditLog(
                $this->pdo,
                $erpInvoiceSaleId,
                'fakturownia_deleted_before_ksef',
                [
                    'invoice_status' => $invoice['status'] ?? null,
                    'mapping' => $mapping,
                ],
                [
                    'invoice_status' => 'draft',
                    'fakturownia_id' => $fakturowniaId,
                    'archived_request_hash' => $archiveHash,
                    'delete_response' => $deleteResponse['data'] ?? null,
                ],
                $this->currentUserId(),
                $reason,
                'fakturownia_before_ksef_reject',
                $fakturowniaId,
                null
            );

            $this->pdo->commit();

            if (function_exists('logEvent')) {
                logEvent('Usunięto dokument Fakturowni przed KSeF i cofnięto fakturę do edycji: ERP ID ' . $erpInvoiceSaleId . ', Fakturownia ID ' . $fakturowniaId, 'WARNING');
            }

            return [
                'success' => true,
                'data' => [
                    'erp_invoice_sale_id' => $erpInvoiceSaleId,
                    'fakturownia_id' => $fakturowniaId,
                    'status' => 'draft',
                ],
                'error' => null,
            ];
        } catch (FakturowniaAuthException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pobiera aktualny status KSeF faktury z Fakturowni.
     */
    public function getKsefStatus(int $fakturowniaId): array
    {
        try {
            $endpoint = '/invoices/' . $fakturowniaId . '.json';
            $response = $this->client->get($endpoint);
            $data = $response['data'] ?? [];
            $invoice = $data['invoice'] ?? $data;

            $govStatus = isset($invoice['gov_status'])
                ? $this->normalizeKsefGovStatus($invoice['gov_status'])
                : null;
            $govId = $invoice['gov_id'] ?? null;

            if ($govStatus) {
                $oldMapping = $this->findInvoiceMappingByFakturowniaId($fakturowniaId);
                $stmt = $this->pdo->prepare("UPDATE fakturownia_invoices SET gov_status = ?, gov_id = ?, synced_at = NOW() WHERE fakturownia_id = ?");
                $stmt->execute([$govStatus, $govId, $fakturowniaId]);
                $this->auditKsefChange($oldMapping, $fakturowniaId, $govStatus, $govId, 'ksef_status_refresh');
            }

            return [
                'success' => true,
                'data' => [
                    'gov_status' => $govStatus,
                    'gov_id' => $govId,
                    'gov_send_date' => $invoice['gov_send_date'] ?? null,
                    'gov_error_messages' => $invoice['gov_error_messages'] ?? null,
                    'gov_verification_link' => $invoice['gov_verification_link'] ?? null,
                    'gov_link' => $invoice['gov_link'] ?? null,
                ],
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendInvoiceByEmail(int $fakturowniaId, ?string $emailTo = null): array
    {
        try {
            $endpoint = '/invoices/' . $fakturowniaId . '/send_by_email.json';

            $params = [];
            if ($emailTo !== null && $emailTo !== '') {
                $params['email_to'] = $emailTo;
                $params['email_pdf'] = 'true';
                $params['update_buyer_email'] = 'true';
            }

            $queryString = !empty($params) ? '?' . http_build_query($params) : '';
            $response = $this->client->post($endpoint . $queryString, []);
            return ['success' => true, 'data' => $response['data'] ?? [], 'error' => null];
        } catch (FakturowniaAuthException $e) {
            $this->logServiceError('/invoices/' . $fakturowniaId . '/send_by_email.json', 'POST', '[CRITICAL AUTH] ' . $e->getMessage(), ['fakturownia_id' => $fakturowniaId]);
            if (function_exists('logEvent')) {
                logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage(), 'auth_failure' => true];
        } catch (Throwable $e) {
            $this->logServiceError('/invoices/' . $fakturowniaId . '/send_by_email.json', 'POST', $e->getMessage(), ['fakturownia_id' => $fakturowniaId]);
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    private function findErpClient(int $erpClientId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, nip, address, email, phone, contact_person
             FROM investors
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $erpClientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findClientMapping(int $erpClientId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, erp_client_id, fakturownia_id
             FROM fakturownia_clients
             WHERE erp_client_id = :erp_client_id
             LIMIT 1"
        );
        $stmt->execute([':erp_client_id' => $erpClientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function saveClientMapping(int $erpClientId, int $fakturowniaId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO fakturownia_clients (erp_client_id, fakturownia_id, synced_at, created_at)
             VALUES (:erp_client_id, :fakturownia_id, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                fakturownia_id = VALUES(fakturownia_id),
                synced_at = NOW()"
        );
        $stmt->execute([
            ':erp_client_id' => $erpClientId,
            ':fakturownia_id' => $fakturowniaId,
        ]);
    }

    private function findMilestone(int $erpMilestoneId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                pr.id,
                pr.project_id,
                pr.cost_node_id,
                pr.type,
                pr.name,
                pr.amount_net,
                pr.signed_date,
                p.investor_id,
                p.name AS project_name,
                i.name AS client_name,
                i.nip AS client_nip
             FROM project_revenues pr
             INNER JOIN projects p ON p.id = pr.project_id
             LEFT JOIN investors i ON i.id = p.investor_id
             WHERE pr.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $erpMilestoneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findSalesInvoice(int $erpInvoiceSaleId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                inv.id,
                inv.invoice_number,
                inv.client_id,
                inv.issue_date,
                inv.sale_date,
                inv.due_date,
                inv.payment_days,
                inv.payment_method,
                inv.bank_account,
                inv.place_of_issue,
                inv.currency,
                inv.amount_net,
                inv.amount_vat,
                inv.amount_gross,
                inv.status,
                inv.split_payment,
                inv.notes,
                inv.fakturownia_options_json,
                inv.seller_data_json,
                i.name AS client_name,
                i.nip AS client_nip
             FROM invoices_sale inv
             LEFT JOIN investors i ON i.id = inv.client_id
             WHERE inv.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $erpInvoiceSaleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function applyCorrectionPaymentTermsFromSource(array $invoice, array $invoiceOptions): array
    {
        if (($invoiceOptions['kind'] ?? '') !== 'correction' || !function_exists('sprutexCorrectionPaymentTerms')) {
            return $invoice;
        }

        $erpCorrectionOfId = (int)($invoiceOptions['correction_of_invoice_id'] ?? 0);
        if ($erpCorrectionOfId <= 0) {
            return $invoice;
        }

        $correctionPaymentTerms = sprutexCorrectionPaymentTerms($this->pdo, $erpCorrectionOfId);
        if (!empty($correctionPaymentTerms['due_date'])) {
            $invoice['due_date'] = $correctionPaymentTerms['due_date'];
        }
        if (array_key_exists('payment_days', $correctionPaymentTerms)) {
            $invoice['payment_days'] = (int)$correctionPaymentTerms['payment_days'];
        }
        if (!empty($correctionPaymentTerms['payment_method'])) {
            $invoice['payment_method'] = $correctionPaymentTerms['payment_method'];
        }

        return $invoice;
    }

    private function findSalesInvoiceItems(int $erpInvoiceSaleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                project_id,
                cost_node_id,
                item_name,
                quantity,
                unit,
                unit_price_net,
                vat_rate,
                amount_net,
                amount_vat,
                amount_gross,
                fakturownia_item_options_json,
                sort_order
             FROM invoice_sale_items
             WHERE invoice_id = :invoice_id
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([':invoice_id' => $erpInvoiceSaleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildContractDataFromMilestone(array $milestone): array
    {
        return [
            'client_name' => $milestone['client_name'] ?? '',
            'client_nip' => $milestone['client_nip'] ?? '',
            'payment_days' => 14,
            'payment_method' => 'transfer',
            'currency' => 'PLN',
            'project_name' => $milestone['project_name'] ?? '',
        ];
    }

    private function buildRequestHash(array $milestone): string
    {
        // VAT: project_revenues nie ma jeszcze kolumny vat_rate → default '23'.
        // Normalizacja musi być spójna z FakturowniaMapper::normalizeVatRate(),
        // żeby hash odpowiadał temu, co faktycznie idzie do API.
        $rawVat = trim((string)($milestone['vat_rate'] ?? '23'));
        if ($rawVat === '' || strtolower($rawVat) === 'zw') {
            $vatRate = ($rawVat === '') ? '23' : 'zw';
        } elseif (is_numeric($rawVat)) {
            $vatRate = (string)(int)$rawVat;
        } else {
            $vatRate = '23';
        }

        $raw = implode('_', [
            (string)($milestone['project_id'] ?? ''),
            (string)($milestone['id'] ?? ''),
            number_format((float)($milestone['amount_net'] ?? 0), 2, '.', ''),
            (string)($milestone['signed_date'] ?? ''),
            $vatRate,
        ]);

        return md5($raw);
    }

    private function buildSalesInvoiceRequestHash(array $invoice, array $items): string
    {
        $parts = [
            'invoice_id=' . (string)($invoice['id'] ?? ''),
            'invoice_number=' . (string)($invoice['invoice_number'] ?? ''),
            'issue_date=' . (string)($invoice['issue_date'] ?? ''),
            'sale_date=' . (string)($invoice['sale_date'] ?? ''),
            'due_date=' . (string)($invoice['due_date'] ?? ''),
            'currency=' . (string)($invoice['currency'] ?? 'PLN'),
            'amount_net=' . number_format((float)($invoice['amount_net'] ?? 0), 2, '.', ''),
            'amount_vat=' . number_format((float)($invoice['amount_vat'] ?? 0), 2, '.', ''),
            'amount_gross=' . number_format((float)($invoice['amount_gross'] ?? 0), 2, '.', ''),
        ];

        foreach ($items as $item) {
            $parts[] = implode('|', [
                (string)($item['project_id'] ?? ''),
                (string)($item['cost_node_id'] ?? ''),
                (string)($item['item_name'] ?? ''),
                number_format((float)($item['quantity'] ?? 0), 4, '.', ''),
                (string)($item['unit'] ?? ''),
                number_format((float)($item['unit_price_net'] ?? 0), 2, '.', ''),
                (string)($item['vat_rate'] ?? ''),
                number_format((float)($item['amount_net'] ?? 0), 2, '.', ''),
                number_format((float)($item['amount_vat'] ?? 0), 2, '.', ''),
                number_format((float)($item['amount_gross'] ?? 0), 2, '.', ''),
            ]);
        }

        return md5(implode('||', $parts));
    }

    private function findInvoiceByRequestHash(string $requestHash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                erp_contract_id,
                erp_milestone_id,
                erp_invoice_sale_id,
                fakturownia_id,
                fakturownia_number,
                gov_id,
                gov_status,
                status,
                request_hash
             FROM fakturownia_invoices
             WHERE request_hash = :request_hash
             LIMIT 1"
        );
        $stmt->execute([':request_hash' => $requestHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function saveInvoiceMapping(array $row): void
    {
        $oldRow = null;
        if (!empty($row['fakturownia_id'])) {
            $oldRow = $this->findInvoiceMappingByFakturowniaId((int)$row['fakturownia_id']);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO fakturownia_invoices
            (erp_contract_id, erp_milestone_id, erp_invoice_sale_id, fakturownia_id, fakturownia_number, gov_id, gov_status, status, pdf_path, request_hash, created_at, synced_at)
            VALUES
            (:erp_contract_id, :erp_milestone_id, :erp_invoice_sale_id, :fakturownia_id, :fakturownia_number, :gov_id, :gov_status, :status, :pdf_path, :request_hash, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                erp_contract_id = VALUES(erp_contract_id),
                erp_milestone_id = VALUES(erp_milestone_id),
                erp_invoice_sale_id = VALUES(erp_invoice_sale_id),
                fakturownia_id = VALUES(fakturownia_id),
                fakturownia_number = VALUES(fakturownia_number),
                gov_id = VALUES(gov_id),
                gov_status = VALUES(gov_status),
                status = VALUES(status),
                pdf_path = VALUES(pdf_path),
                synced_at = NOW(),
                updated_at = NOW()"
        );

        $stmt->execute([
            ':erp_contract_id' => $row['erp_contract_id'] ?? null,
            ':erp_milestone_id' => $row['erp_milestone_id'] ?? null,
            ':erp_invoice_sale_id' => $row['erp_invoice_sale_id'] ?? null,
            ':fakturownia_id' => $row['fakturownia_id'] ?? null,
            ':fakturownia_number' => $row['fakturownia_number'] ?? null,
            ':gov_id' => $row['gov_id'] ?? null,
            ':gov_status' => $row['gov_status'] ?? 'pending',
            ':status' => $row['status'] ?? 'draft',
            ':pdf_path' => $row['pdf_path'] ?? null,
            ':request_hash' => $row['request_hash'] ?? null,
        ]);

        if (!empty($row['erp_invoice_sale_id'])) {
            invoiceAuditLog(
                $this->pdo,
                (int)$row['erp_invoice_sale_id'],
                $oldRow ? 'fakturownia_mapping_updated' : 'fakturownia_mapping_created',
                $oldRow ?: [],
                [
                    'erp_invoice_sale_id' => $row['erp_invoice_sale_id'] ?? null,
                    'fakturownia_id' => $row['fakturownia_id'] ?? null,
                    'fakturownia_number' => $row['fakturownia_number'] ?? null,
                    'gov_id' => $row['gov_id'] ?? null,
                    'gov_status' => $row['gov_status'] ?? null,
                    'status' => $row['status'] ?? null,
                    'request_hash' => $row['request_hash'] ?? null,
                ],
                $this->currentUserId(),
                'Zapis mapowania po odpowiedzi API Fakturowni',
                'fakturownia_service',
                !empty($row['fakturownia_id']) ? (int)$row['fakturownia_id'] : null,
                !empty($row['gov_id']) ? (string)$row['gov_id'] : null
            );
        }
    }

    private function syncOfficialNumberFromFakturownia(
        int $erpInvoiceSaleId,
        string $fakturowniaNumber,
        int $fakturowniaId,
        ?string $govId,
        string $source
    ): void {
        $officialNumber = trim($fakturowniaNumber);
        if ($erpInvoiceSaleId <= 0 || $officialNumber === '') {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT id, invoice_number FROM invoices_sale WHERE id = ? LIMIT 1");
        $stmt->execute([$erpInvoiceSaleId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return;
        }

        $currentNumber = trim((string)($invoice['invoice_number'] ?? ''));
        if ($currentNumber === $officialNumber) {
            return;
        }

        try {
            $dupStmt = $this->pdo->prepare("
                SELECT id
                FROM invoices_sale
                WHERE invoice_number = ?
                  AND id <> ?
                  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                LIMIT 1
            ");
            $dupStmt->execute([$officialNumber, $erpInvoiceSaleId]);
        } catch (Throwable $e) {
            $dupStmt = $this->pdo->prepare("SELECT id FROM invoices_sale WHERE invoice_number = ? AND id <> ? LIMIT 1");
            $dupStmt->execute([$officialNumber, $erpInvoiceSaleId]);
        }
        $duplicateId = (int)$dupStmt->fetchColumn();

        if ($duplicateId > 0) {
            $note = 'Nie uzgodniono numeru z Fakturownia: numer oficjalny ' . $officialNumber . ' jest juz uzyty przez invoices_sale.id=' . $duplicateId;
            try {
                $this->pdo->prepare("
                    UPDATE invoices_sale
                    SET sync_attention_required = 1,
                        sync_attention_note = ?
                    WHERE id = ?
                ")->execute([$note, $erpInvoiceSaleId]);
            } catch (Throwable $e) {}

            invoiceAuditLog(
                $this->pdo,
                $erpInvoiceSaleId,
                'invoice_number_sync_conflict',
                ['invoice_number' => $currentNumber],
                ['fakturownia_number' => $officialNumber, 'duplicate_invoice_sale_id' => $duplicateId],
                $this->currentUserId(),
                $note,
                $source,
                $fakturowniaId ?: null,
                $govId ?: null
            );
            return;
        }

        $this->pdo->prepare("UPDATE invoices_sale SET invoice_number = ? WHERE id = ?")
            ->execute([$officialNumber, $erpInvoiceSaleId]);

        invoiceAuditLog(
            $this->pdo,
            $erpInvoiceSaleId,
            'invoice_number_synced_from_fakturownia',
            ['invoice_number' => $currentNumber],
            ['invoice_number' => $officialNumber],
            $this->currentUserId(),
            'Uzgodnienie numeru lokalnego z numerem oficjalnym Fakturowni',
            $source,
            $fakturowniaId ?: null,
            $govId ?: null
        );
    }

    private function findInvoiceMappingByFakturowniaId(int $fakturowniaId): ?array
    {
        if ($fakturowniaId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM fakturownia_invoices WHERE fakturownia_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$fakturowniaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function auditKsefChange(?array $oldMapping, int $fakturowniaId, ?string $govStatus, ?string $govId, string $source): void
    {
        $invoiceSaleId = (int)($oldMapping['erp_invoice_sale_id'] ?? 0);
        if ($invoiceSaleId <= 0) {
            return;
        }
        $oldGovStatus = (string)($oldMapping['gov_status'] ?? '');
        $oldGovId = (string)($oldMapping['gov_id'] ?? '');
        if ($oldGovStatus === (string)$govStatus && $oldGovId === (string)$govId) {
            return;
        }

        invoiceAuditLog(
            $this->pdo,
            $invoiceSaleId,
            'ksef_status_changed',
            ['gov_status' => $oldGovStatus, 'gov_id' => $oldGovId],
            ['gov_status' => $govStatus, 'gov_id' => $govId],
            $this->currentUserId(),
            'Aktualizacja statusu KSeF z Fakturowni',
            $source,
            $fakturowniaId ?: null,
            $govId ?: null
        );
    }

    private function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    private function normalizeKsefGovStatus($govStatus): string
    {
        $status = strtolower(trim((string)$govStatus));
        if (in_array($status, ['ok', 'accepted', 'success', 'done'], true)) {
            return 'ok';
        }
        if (in_array($status, ['demo_ok', 'demo_accepted'], true)) {
            return 'demo_ok';
        }
        if (in_array($status, ['processing', 'sent', 'sending', 'queued'], true)) {
            return 'processing';
        }
        if (in_array($status, ['demo_processing', 'demo_sent'], true)) {
            return 'demo_processing';
        }
        if (in_array($status, ['send_error', 'error', 'rejected'], true)) {
            return 'send_error';
        }
        if ($status === 'server_error') {
            return 'server_error';
        }
        if ($status === 'not_connected') {
            return 'not_connected';
        }
        return 'pending';
    }

    /**
     * Zwraca fakturownia_id z tabeli fakturownia_invoices dla danej faktury ERP (invoices_sale.id).
     * Używane przy korektach — żeby przekazać invoice_id / from_invoice_id do API Fakturowni.
     */
    private function findFakturowniaIdByErpInvoiceSaleId(int $erpInvoiceSaleId): ?int
    {
        // Szukaj po bezpośrednim kluczu erp_invoice_sale_id (wypełniany od wersji 2026-03-23)
        $stmt = $this->pdo->prepare(
            "SELECT fakturownia_id FROM fakturownia_invoices
             WHERE erp_invoice_sale_id = :id
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $erpInvoiceSaleId]);
        $row = $stmt->fetchColumn();
        if ($row) {
            return (int)$row;
        }

        return null;
    }

    private function extractSingleProjectIdFromAllocations(int $invoiceSaleId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT DISTINCT project_id FROM invoice_sale_allocations WHERE invoice_id = ?");
        $stmt->execute([$invoiceSaleId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    private function extractClientId(array $apiData): int
    {
        if (isset($apiData['client']['id'])) {
            return (int)$apiData['client']['id'];
        }
        if (isset($apiData['id'])) {
            return (int)$apiData['id'];
        }

        return 0;
    }

    private function logServiceError(string $endpoint, string $method, string $error, array $request = []): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO fakturownia_api_log
                (endpoint, http_method, http_status, request_json, response_json, retry_count, error_message, created_at)
                VALUES (:endpoint, :http_method, :http_status, :request_json, :response_json, :retry_count, :error_message, NOW())"
            );

            $stmt->execute([
                ':endpoint' => $endpoint,
                ':http_method' => strtoupper($method),
                ':http_status' => null,
                ':request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':response_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':retry_count' => 0,
                ':error_message' => $error,
            ]);
        } catch (Throwable $e) {
            if (function_exists('logEvent')) {
                logEvent('FakturowniaService logServiceError failed: ' . $e->getMessage(), 'ERROR');
            } else {
                error_log('FakturowniaService logServiceError failed: ' . $e->getMessage());
            }
        }
    }

    private function getDefaultPdo()
    {
        if (!function_exists('getDbConnection')) {
            require_once dirname(__DIR__, 2) . '/config/database.php';
        }

        return getDbConnection();
    }
}
