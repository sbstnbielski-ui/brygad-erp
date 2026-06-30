<?php
/**
 * BRYGAD ERP - Client HTTP dla Fakturownia API
 *
 * Warstwa transportowa:
 * - GET/POST/PUT + pobieranie PDF
 * - retry dla 429/503 (Exponential Backoff)
 * - logowanie request/response do fakturownia_api_log
 */

/**
 * Wyjątek autoryzacji — rzucany przy HTTP 401 (zły/wygasły token).
 * Service powinien łapać ten wyjątek oddzielnie i traktować jako błąd krytyczny.
 */
class FakturowniaAuthException extends RuntimeException {}

class FakturowniaClient
{
    /** @var PDO */
    private $pdo;

    /** @var array */
    private $config;

    /** @var string */
    private $apiToken;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout = 30;

    /** @var int */
    private $retryMax = 3;

    public function __construct($pdo = null, array $config = null)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Rozszerzenie PHP cURL nie jest dostępne.');
        }

        $this->pdo = $pdo ?: $this->getDefaultPdo();
        $this->config = $config ?: $this->loadConfig();
        $this->apiToken = trim((string)($this->config['api_token'] ?? ''));
        $subdomain = trim((string)($this->config['subdomain'] ?? ''));
        $baseUrlTemplate = (string)($this->config['base_url'] ?? 'https://{subdomain}.fakturownia.pl');

        if ($this->apiToken === '') {
            throw new RuntimeException('Brak FAKTUROWNIA_API_TOKEN w konfiguracji.');
        }

        if ($subdomain === '') {
            throw new RuntimeException('Brak FAKTUROWNIA_SUBDOMAIN w konfiguracji.');
        }

        $this->baseUrl = rtrim(str_replace('{subdomain}', $subdomain, $baseUrlTemplate), '/');
        $this->timeout = (int)($this->config['timeout'] ?? 30);
        $this->retryMax = (int)($this->config['retry_max'] ?? 3);
    }

    public function get(string $endpoint): array
    {
        return $this->requestWithRetry('GET', $endpoint);
    }

    public function post(string $endpoint, array $data): array
    {
        return $this->requestWithRetry('POST', $endpoint, $data);
    }

    public function put(string $endpoint, array $data): array
    {
        return $this->requestWithRetry('PUT', $endpoint, $data);
    }

    public function delete(string $endpoint): array
    {
        return $this->requestWithRetry('DELETE', $endpoint);
    }

    public function changeInvoiceStatus(int $fakturowniaId, string $status): array
    {
        $endpoint = '/invoices/' . $fakturowniaId . '/change_status.json?status=' . urlencode($status);
        $url = $this->buildUrl($endpoint, true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_POSTFIELDS => '',
        ]);
        $responseBody = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decodedStatusResponse = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $decodedForLog = is_array($decodedStatusResponse)
            ? $decodedStatusResponse
            : ['raw_body' => (string)$responseBody];

        $this->logRequest(
            '/invoices/' . $fakturowniaId . '/change_status.json',
            'POST',
            $httpStatus,
            ['status' => $status],
            $decodedForLog,
            0
        );

        return [
            'http_status' => $httpStatus,
            'data' => is_array($decodedStatusResponse) ? $decodedStatusResponse : [],
        ];
    }

    public function downloadPdf(string $invoiceId): string
    {
        $endpoint = '/invoices/' . rawurlencode($invoiceId) . '.pdf';
        return $this->binaryRequestWithRetry('GET', $endpoint);
    }

    private function requestWithRetry(string $method, string $endpoint, array $data = []): array
    {
        $maxRetries = max(0, $this->retryMax);
        $baseDelay = 2;
        $lastResult = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $result = $this->jsonRequest($method, $endpoint, $data, $attempt);
            $lastResult = $result;

            if (!in_array((int)$result['http_status'], [429, 503], true)) {
                return $result;
            }

            if ($attempt === $maxRetries) {
                break;
            }

            // Exponential Backoff + jitter
            $sleepSeconds = (int)pow($baseDelay, $attempt + 1);
            $jitterMs = random_int(0, 1000);
            sleep($sleepSeconds);
            usleep($jitterMs * 1000);
        }

        $status = (int)($lastResult['http_status'] ?? 0);
        throw new RuntimeException("Fakturownia API niedostępne po {$maxRetries} próbach (status {$status}).");
    }

    private function binaryRequestWithRetry(string $method, string $endpoint): string
    {
        $maxRetries = max(0, $this->retryMax);
        $baseDelay = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $result = $this->binaryRequest($method, $endpoint, $attempt);

            if (!in_array((int)$result['http_status'], [429, 503], true)) {
                if ((int)$result['http_status'] >= 200 && (int)$result['http_status'] < 300) {
                    return (string)$result['body'];
                }

                if ((int)$result['http_status'] === 401) {
                    throw new FakturowniaAuthException(
                        'Błąd autoryzacji Fakturownia API (HTTP 401): nieprawidłowy lub wygasły token.'
                    );
                }

                throw new RuntimeException(
                    'Błąd Fakturownia API (HTTP ' . (int)$result['http_status'] . ') podczas pobierania PDF.'
                );
            }

            if ($attempt === $maxRetries) {
                break;
            }

            // Exponential Backoff + jitter
            $sleepSeconds = (int)pow($baseDelay, $attempt + 1);
            $jitterMs = random_int(0, 1000);
            sleep($sleepSeconds);
            usleep($jitterMs * 1000);
        }

        throw new RuntimeException("Fakturownia API niedostępne po {$maxRetries} próbach pobrania PDF.");
    }

    private function jsonRequest(string $method, string $endpoint, array $data, int $attempt): array
    {
        $url = $this->buildUrl($endpoint, in_array($method, ['GET', 'DELETE'], true));
        $payload = $data;

        if (in_array($method, ['POST', 'PUT'], true)) {
            $payload['api_token'] = $this->apiToken;
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (in_array($method, ['POST', 'PUT'], true)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logRequest(
                $this->normalizeEndpoint($endpoint),
                $method,
                0,
                $this->sanitizeForLog($payload),
                [],
                $attempt,
                'cURL error: ' . $error
            );
            throw new RuntimeException('Błąd połączenia z Fakturownia API: ' . $error);
        }

        curl_close($ch);

        $decoded = json_decode((string)$responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = ['raw_body' => (string)$responseBody];
        }
        $decodedForLog = is_array($decoded)
            ? $decoded
            : ['raw_body' => (string)$responseBody, 'decoded_value' => $decoded];

        $this->logRequest(
            $this->normalizeEndpoint($endpoint),
            $method,
            $httpStatus,
            $this->sanitizeForLog($payload),
            $this->sanitizeForLog($decodedForLog),
            $attempt
        );

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return [
                'success' => true,
                'http_status' => $httpStatus,
                'data' => is_array($decoded) ? $decoded : [],
            ];
        }

        if (in_array($httpStatus, [429, 503], true)) {
            return [
                'success' => false,
                'http_status' => $httpStatus,
                'data' => is_array($decoded) ? $decoded : [],
            ];
        }

        $bodyForError = is_array($decoded)
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$responseBody;

        if ($httpStatus === 401) {
            throw new FakturowniaAuthException(
                'Błąd autoryzacji Fakturownia API (HTTP 401): nieprawidłowy lub wygasły token.'
            );
        }

        throw new RuntimeException(
            'Błąd Fakturownia API (HTTP ' . $httpStatus . '): ' . $bodyForError
        );
    }

    private function binaryRequest(string $method, string $endpoint, int $attempt): array
    {
        $url = $this->buildUrl($endpoint, true);
        $headers = [
            'Accept: application/pdf',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logRequest(
                $this->normalizeEndpoint($endpoint),
                $method,
                0,
                [],
                [],
                $attempt,
                'cURL error: ' . $error
            );
            throw new RuntimeException('Błąd połączenia z Fakturownia API przy pobieraniu PDF: ' . $error);
        }

        curl_close($ch);

        $responseForLog = [
            'content_type' => $contentType,
            'size' => strlen((string)$body),
        ];

        if (strpos($contentType, 'application/json') !== false) {
            $json = json_decode((string)$body, true);
            if (is_array($json)) {
                $responseForLog['json'] = $this->sanitizeForLog($json);
            }
        }

        $this->logRequest(
            $this->normalizeEndpoint($endpoint),
            $method,
            $httpStatus,
            [],
            $responseForLog,
            $attempt
        );

        return [
            'http_status' => $httpStatus,
            'body' => (string)$body,
        ];
    }

    private function logRequest(
        string $endpoint,
        string $method,
        int $status,
        array $request,
        array $response,
        int $retries = 0,
        string $errorMessage = null
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO fakturownia_api_log
                (endpoint, http_method, http_status, request_json, response_json, retry_count, error_message, created_at)
                VALUES (:endpoint, :http_method, :http_status, :request_json, :response_json, :retry_count, :error_message, NOW())"
            );

            $stmt->execute([
                ':endpoint' => $endpoint,
                ':http_method' => strtoupper($method),
                ':http_status' => $status > 0 ? $status : null,
                ':request_json' => json_encode($this->sanitizeForLog($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':response_json' => json_encode($this->sanitizeForLog($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':retry_count' => max(0, $retries),
                ':error_message' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            if (function_exists('logEvent')) {
                logEvent('Fakturownia logRequest error: ' . $e->getMessage(), 'ERROR');
            } else {
                error_log('Fakturownia logRequest error: ' . $e->getMessage());
            }
        }
    }

    private function sanitizeForLog($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : $key;

            if (in_array($normalizedKey, ['api_token', 'authorization', 'x-api-token', 'token'], true)) {
                $sanitized[$key] = '***REDACTED***';
                continue;
            }

            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizeForLog($item);
                continue;
            }

            $sanitized[$key] = $item;
        }

        return $sanitized;
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        return '/' . ltrim($endpoint, '/');
    }

    private function buildUrl(string $endpoint, bool $includeApiToken = false): string
    {
        $url = $this->baseUrl . $this->normalizeEndpoint($endpoint);
        if (!$includeApiToken) {
            return $url;
        }

        return $this->appendTokenToUrl($url);
    }

    private function appendTokenToUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['api_token'] = $this->apiToken;

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $rebuiltQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $scheme . $host . $port . $path . ($rebuiltQuery !== '' ? '?' . $rebuiltQuery : '') . $fragment;
    }

    private function getDefaultPdo()
    {
        if (!function_exists('getDbConnection')) {
            require_once dirname(__DIR__, 2) . '/config/database.php';
        }

        return getDbConnection();
    }

    private function loadConfig(): array
    {
        $configFile = dirname(__DIR__, 2) . '/config/fakturownia.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException('Brak pliku konfiguracyjnego: config/fakturownia.php');
        }

        $config = require $configFile;
        if (!is_array($config)) {
            throw new RuntimeException('Nieprawidłowy format konfiguracji Fakturowni.');
        }

        return $config;
    }
}
