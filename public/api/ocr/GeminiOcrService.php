<?php
/**
 * Gemini OCR Service
 * Obsługa skanowania faktur przez Gemini 3.0 Flash API
 */

class GeminiOcrService {
    
    private $apiKey;
    private $invoiceType = 'cost'; // 'cost' lub 'sale'
    private $model = 'gemini-3-flash-preview';
    private $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    public function __construct($apiKey, $invoiceType = 'cost') {
        $this->apiKey = $apiKey;
        $this->invoiceType = $invoiceType;
        $this->model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3-flash-preview';
    }
    
    /**
     * Skanuje fakturę i zwraca wyparsowane dane
     * @param string $filePath Ścieżka do pliku (PDF lub obrazek)
     * @param string $invoiceType Typ faktury: 'cost' (kosztowa) lub 'sale' (sprzedażowa)
     * @return array Wyparsowane dane faktury
     * @throws Exception W przypadku błędu
     */
    public function scanInvoice($filePath, $invoiceType = 'cost') {
        
        // Sprawdź czy plik istnieje
        if (!file_exists($filePath)) {
            throw new Exception("Plik nie istnieje: $filePath");
        }
        
        // Odczytaj plik i zakoduj base64
        $fileContent = file_get_contents($filePath);
        $base64Content = base64_encode($fileContent);
        
        // Określ MIME type
        $mimeType = $this->getMimeType($filePath);
        
        // Przygotuj prompt (użyj typu z parametru lub z instancji)
        $prompt = $this->getPrompt($invoiceType ?: $this->invoiceType);
        
        // Przygotuj request do Gemini API
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Content
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'topK' => 32,
                'topP' => 1,
                'maxOutputTokens' => 2048,
            ]
        ];
        
        // Wywołaj API
        $response = $this->callGeminiApi($requestData);
        
        // Parsuj odpowiedź
        $parsedData = $this->parseResponse($response, $invoiceType);
        
        return $parsedData;
    }
    
    /**
     * Prompt dla Gemini do parsowania faktury
     * @param string $invoiceType 'cost' lub 'sale'
     */
    private function getPrompt($invoiceType = 'cost') {
        
        if ($invoiceType === 'sale') {
            // Prompt dla faktury SPRZEDAŻOWEJ (wystawionej przez nas)
            return <<<PROMPT
Jesteś ekspertem w analizie polskich faktur VAT. Przeanalizuj FAKTURĘ SPRZEDAŻOWĄ i wyciągnij następujące dane.

ZWRÓĆ DANE W FORMACIE JSON (TYLKO JSON, BEZ DODATKOWEGO TEKSTU):

{
  "invoice_number": "numer faktury (np. FS/2026/01/123)",
  "issue_date": "data wystawienia w formacie YYYY-MM-DD",
  "sale_date": "data sprzedaży w formacie YYYY-MM-DD",
  "due_date": "termin płatności w formacie YYYY-MM-DD",
  "place_of_issue": "miejsce wystawienia (np. Czerwonak)",
  "client_name": "nazwa NABYWCY/klienta (nie sprzedawcy!)",
  "client_nip": "NIP nabywcy (tylko cyfry)",
  "client_address": "adres nabywcy",
  "payment_method": "sposób płatności (transfer/cash/card/other)",
  "bank_account": "numer konta bankowego do przelewu",
  "amount_net": kwota netto jako liczba (np. 10000.00),
  "amount_vat": kwota VAT jako liczba (np. 2300.00),
  "amount_gross": kwota brutto jako liczba (np. 12300.00),
  "currency": "waluta (PLN/EUR/USD)",
  "items": [
    {
      "name": "nazwa pozycji/usługi",
      "quantity": ilość jako liczba (np. 1.00),
      "unit": "jednostka (szt/usł/godz/m2/mb/kg/kpl)",
      "unit_price": cena jednostkowa netto jako liczba,
      "vat_rate": "stawka VAT (23/8/5/0/zw)",
      "amount_net": kwota netto pozycji,
      "amount_vat": kwota VAT pozycji,
      "amount_gross": kwota brutto pozycji
    }
  ],
  "confidence": poziom pewności od 0 do 1 (np. 0.95)
}

WAŻNE:
- To jest faktura SPRZEDAŻOWA - szukaj danych NABYWCY (klienta), nie sprzedawcy!
- Wyciągnij WSZYSTKIE pozycje z faktury do tablicy "items"
- Jeśli pole nie jest znalezione, zwróć null
- Daty ZAWSZE w formacie YYYY-MM-DD
- Kwoty jako liczby (bez spacji, bez PLN)
- NIP tylko cyfry (usuń kreski i spacje)
- Zwróć TYLKO czysty JSON, bez markdown, bez ```json
PROMPT;
        } else {
            // Prompt dla faktury KOSZTOWEJ (otrzymanej)
            return <<<PROMPT
Jesteś ekspertem w analizie polskich faktur VAT. Przeanalizuj FAKTURĘ KOSZTOWĄ i wyciągnij następujące dane.

ZWRÓĆ DANE W FORMACIE JSON (TYLKO JSON, BEZ DODATKOWEGO TEKSTU):

{
  "number": "numer faktury (np. FV/2026/01/123)",
  "issue_date": "data wystawienia w formacie YYYY-MM-DD",
  "due_date": "termin płatności w formacie YYYY-MM-DD (jeśli jest)",
  "sale_date": "data sprzedaży w formacie YYYY-MM-DD (jeśli jest)",
  "vendor_name": "nazwa SPRZEDAWCY/dostawcy",
  "vendor_nip": "NIP sprzedawcy (tylko cyfry, bez kresek)",
  "amount_net": kwota netto jako liczba (np. 1000.00),
  "amount_vat": kwota VAT jako liczba (np. 230.00),
  "amount_gross": kwota brutto jako liczba (np. 1230.00),
  "items": [
    {
      "name": "nazwa pozycji/usługi",
      "quantity": ilość jako liczba (np. 1.00),
      "unit": "jednostka (szt/usł/godz/m2/mb/kg/kpl)",
      "unit_price": cena jednostkowa netto jako liczba,
      "vat_rate": "stawka VAT (23/8/5/0/zw)",
      "amount_net": kwota netto pozycji,
      "amount_vat": kwota VAT pozycji,
      "amount_gross": kwota brutto pozycji
    }
  ],
  "confidence": poziom pewności od 0 do 1 (np. 0.95)
}

WAŻNE:
- Wyciągnij WSZYSTKIE pozycje z faktury do tablicy "items"
- Jeśli pole nie jest znalezione, zwróć null
- Daty ZAWSZE w formacie YYYY-MM-DD
- Kwoty jako liczby (bez spacji, bez PLN)
- NIP tylko cyfry (usuń kreski i spacje)
- Zwróć TYLKO czysty JSON, bez markdown, bez ```json
PROMPT;
        }
    }
    
    /**
     * Wywołanie Gemini API z retry logic dla błędów 503
     */
    private function callGeminiApi($requestData, $retryCount = 0) {
        $maxRetries = 3;
        $baseDelay = 2; // sekundy
        
        $url = $this->endpoint . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 sekund timeout
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Błąd połączenia z Gemini API: $error");
        }
        
        curl_close($ch);
        
        // Obsługa błędu 503 - model przeciążony
        if ($httpCode === 503) {
            if ($retryCount < $maxRetries) {
                // Exponential backoff: 2s, 4s, 8s
                $delay = $baseDelay * pow(2, $retryCount);
                error_log("Gemini API overloaded (503), retry $retryCount/$maxRetries after {$delay}s");
                sleep($delay);
                return $this->callGeminiApi($requestData, $retryCount + 1);
            } else {
                throw new Exception("Gemini API jest obecnie przeciążony. Spróbuj ponownie za chwilę (już za kilka sekund powinno działać).");
            }
        }
        
        // Obsługa innych błędów HTTP
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = "Błąd API Gemini (kod $httpCode)";
            
            if (isset($errorData['error']['message'])) {
                $errorMessage .= ": " . $errorData['error']['message'];
            }
            
            // Dodatkowe info dla błędów 429 (rate limit)
            if ($httpCode === 429) {
                $errorMessage .= " (Przekroczono limit zapytań - poczekaj chwilę)";
            }
            
            throw new Exception($errorMessage);
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Błąd parsowania odpowiedzi JSON: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Parsowanie odpowiedzi z Gemini
     * @param array $response Odpowiedź z API
     * @param string $invoiceType Typ faktury
     */
    private function parseResponse($response, $invoiceType = 'cost') {
        
        // Sprawdź strukturę odpowiedzi
        if (!isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception("Nieprawidłowa struktura odpowiedzi API");
        }
        
        $text = $response['candidates'][0]['content']['parts'][0]['text'];
        
        // Usuń markdown jeśli jest
        $text = preg_replace('/```json\s*/', '', $text);
        $text = preg_replace('/```\s*/', '', $text);
        $text = trim($text);
        
        // Parsuj JSON
        $data = json_decode($text, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Nie udało się sparsować danych faktury: " . json_last_error_msg() . "\nOdpowiedź: $text");
        }
        
        // Walidacja i formatowanie danych
        return $this->validateAndFormat($data, $invoiceType);
    }
    
    /**
     * Walidacja i formatowanie danych
     * @param array $data Dane z Gemini
     * @param string $invoiceType Typ faktury
     */
    private function validateAndFormat($data, $invoiceType = 'cost') {
        
        if ($invoiceType === 'sale') {
            // Faktura sprzedażowa
            $result = [
                'invoice_number' => $data['invoice_number'] ?? null,
                'issue_date' => $this->validateDate($data['issue_date'] ?? null),
                'sale_date' => $this->validateDate($data['sale_date'] ?? null),
                'due_date' => $this->validateDate($data['due_date'] ?? null),
                'place_of_issue' => $data['place_of_issue'] ?? null,
                'client_name' => $data['client_name'] ?? null,
                'client_nip' => $this->cleanNip($data['client_nip'] ?? null),
                'client_address' => $data['client_address'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'bank_account' => $data['bank_account'] ?? null,
                'amount_net' => $this->validateAmount($data['amount_net'] ?? null),
                'amount_vat' => $this->validateAmount($data['amount_vat'] ?? null),
                'amount_gross' => $this->validateAmount($data['amount_gross'] ?? null),
                'currency' => $data['currency'] ?? 'PLN',
                'items' => $this->validateItems($data['items'] ?? []),
                'confidence' => floatval($data['confidence'] ?? 0.5)
            ];
        } else {
            // Faktura kosztowa
            $result = [
                'number' => $data['number'] ?? null,
                'issue_date' => $this->validateDate($data['issue_date'] ?? null),
                'due_date' => $this->validateDate($data['due_date'] ?? null),
                'sale_date' => $this->validateDate($data['sale_date'] ?? null),
                'vendor_name' => $data['vendor_name'] ?? null,
                'vendor_nip' => $this->cleanNip($data['vendor_nip'] ?? null),
                'amount_net' => $this->validateAmount($data['amount_net'] ?? null),
                'amount_vat' => $this->validateAmount($data['amount_vat'] ?? null),
                'amount_gross' => $this->validateAmount($data['amount_gross'] ?? null),
                'items' => $this->validateItems($data['items'] ?? []),
                'confidence' => floatval($data['confidence'] ?? 0.5)
            ];
        }
        
        return $result;
    }
    
    /**
     * Walidacja daty (format YYYY-MM-DD)
     */
    private function validateDate($date) {
        if (empty($date)) return null;
        
        // Sprawdź format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        return null;
    }
    
    /**
     * Czyszczenie NIP (tylko cyfry)
     */
    private function cleanNip($nip) {
        if (empty($nip)) return null;
        
        $cleaned = preg_replace('/[^0-9]/', '', $nip);
        
        return $cleaned ?: null;
    }
    
    /**
     * Walidacja kwoty
     */
    private function validateAmount($amount) {
        if ($amount === null) return null;
        
        // Konwertuj na float
        $float = floatval($amount);
        
        return $float > 0 ? round($float, 2) : null;
    }
    
    /**
     * Walidacja pozycji faktury
     */
    private function validateItems($items) {
        if (!is_array($items)) {
            return [];
        }
        
        $validated = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            
            $validated[] = [
                'name' => $item['name'] ?? '',
                'quantity' => $this->validateAmount($item['quantity'] ?? null) ?: 1,
                'unit' => $item['unit'] ?? 'szt',
                'unit_price' => $this->validateAmount($item['unit_price'] ?? null) ?: 0,
                'vat_rate' => $item['vat_rate'] ?? '23',
                'amount_net' => $this->validateAmount($item['amount_net'] ?? null) ?: 0,
                'amount_vat' => $this->validateAmount($item['amount_vat'] ?? null) ?: 0,
                'amount_gross' => $this->validateAmount($item['amount_gross'] ?? null) ?: 0
            ];
        }
        
        return $validated;
    }
    
    /**
     * Określenie MIME type
     */
    private function getMimeType($filePath) {
        // Najpierw sprawdź rzeczywisty MIME type pliku
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType) {
                return $mimeType;
            }
        }
        
        // Fallback: użyj extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        return $mimeTypes[$extension] ?? 'image/jpeg';
    }
}

