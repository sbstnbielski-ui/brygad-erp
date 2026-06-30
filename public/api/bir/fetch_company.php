<?php
/**
 * BRYGAD ERP - API BIR GUS Integration
 * Pobieranie danych firmy z GUS REGON po NIP
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';

// Check authentication
startSecureSession();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nieautoryzowany dostęp']);
    exit;
}

// Validate input
$nip = trim($_GET['nip'] ?? $_POST['nip'] ?? '');

if (empty($nip)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak numeru NIP']);
    exit;
}

// Clean NIP - remove spaces and dashes
$nip = preg_replace('/[^0-9]/', '', $nip);

if (strlen($nip) !== 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'NIP musi mieć 10 cyfr']);
    exit;
}

/**
 * BIR API Client - Using cURL for better control
 */
class BirApiClient
{
    private $apiUrl = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
    private $apiKey = 'a6cf459b1ddf480ebf7d'; // Klucz produkcyjny GUS
    private $sid = null;

    public function __construct()
    {
        // Check if cURL is available
        if (!function_exists('curl_init')) {
            throw new Exception("Rozszerzenie PHP cURL nie jest zainstalowane na serwerze");
        }
    }
    
    /**
     * Send SOAP request using cURL
     */
    private function sendSoapRequest($action, $soapBody)
    {
        $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">
  <soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">
    <wsa:To>https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</wsa:To>
    <wsa:Action>' . $action . '</wsa:Action>
  </soap:Header>
  <soap:Body>
    ' . $soapBody . '
  </soap:Body>
</soap:Envelope>';

        $ch = curl_init($this->apiUrl);
        
        $headers = [
            'Content-Type: application/soap+xml; charset=utf-8',
            'Content-Length: ' . strlen($soapEnvelope)
        ];
        
        // Add session ID to header if available
        if ($this->sid) {
            $headers[] = 'sid: ' . $this->sid;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapEnvelope,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: " . $httpCode);
        }
        
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Extract XML from MTOM/multipart response if needed
        $body = $this->extractXmlFromMultipart($body);
        
        return $body;
    }
    
    /**
     * Extract XML from MTOM/multipart SOAP response
     */
    private function extractXmlFromMultipart($response)
    {
        // Check if this is a multipart response
        if (strpos($response, '--uuid:') !== false || strpos($response, 'Content-Type: application/xop+xml') !== false) {
            // This is a multipart MTOM response
            // Find the XML part between multipart boundaries
            
            // Look for the XML envelope start
            $envelopeStart = strpos($response, '<s:Envelope');
            if ($envelopeStart === false) {
                $envelopeStart = strpos($response, '<soap:Envelope');
            }
            
            // Look for the XML envelope end
            $envelopeEnd = strpos($response, '</s:Envelope>');
            if ($envelopeEnd === false) {
                $envelopeEnd = strpos($response, '</soap:Envelope>');
            }
            
            if ($envelopeStart !== false && $envelopeEnd !== false) {
                // Extract just the XML envelope
                $xmlLength = $envelopeEnd - $envelopeStart + strlen('</s:Envelope>');
                $xml = substr($response, $envelopeStart, $xmlLength);
                return $xml;
            }
        }
        
        // Return as-is if not multipart or couldn't extract
        return $response;
    }

    /**
     * Login to BIR service
     */
    public function login()
    {
        try {
            logEvent("BIR Login attempt with key: " . substr($this->apiKey, 0, 8) . "...", 'DEBUG');
            
            $soapBody = '<ns:Zaloguj>
      <ns:pKluczUzytkownika>' . htmlspecialchars($this->apiKey) . '</ns:pKluczUzytkownika>
    </ns:Zaloguj>';
            
            $action = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj';
            
            $response = $this->sendSoapRequest($action, $soapBody);
            
            logEvent("BIR Login response (first 500 chars): " . substr($response, 0, 500), 'DEBUG');
            
            // Parse XML response (libxml errors suppressed for better error handling)
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = "Nie udało się przetworzyć odpowiedzi XML";
                if (!empty($errors)) {
                    $errorMsg .= ": " . $errors[0]->message;
                }
                libxml_clear_errors();
                throw new Exception($errorMsg);
            }
            
            // Register namespaces (support both 's' and 'soap' prefixes)
            $xml->registerXPathNamespace('s', 'http://www.w3.org/2003/05/soap-envelope');
            $xml->registerXPathNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
            $xml->registerXPathNamespace('ns', 'http://CIS/BIR/PUBL/2014/07');
            
            // Extract SID from response - try multiple possible paths
            $sidNodes = $xml->xpath('//ns:ZalogujResult');
            if (empty($sidNodes)) {
                // Try without namespace prefix
                $sidNodes = $xml->xpath('//ZalogujResult');
            }
            
            if (empty($sidNodes) || empty((string)$sidNodes[0])) {
                logEvent("BIR Login: No SID in response. Full XML: " . $response, 'ERROR');
                throw new Exception("Nie otrzymano SID z serwera GUS");
            }
            
            $this->sid = (string)$sidNodes[0];
            
            logEvent("BIR Login successful, SID: " . substr($this->sid, 0, 10) . "...", 'INFO');
            
            return true;
        } catch (Exception $e) {
            logEvent("BIR Login Exception: " . $e->getMessage(), 'ERROR');
            throw new Exception("Błąd logowania do BIR: " . $e->getMessage());
        }
    }

    /**
     * Search for company by NIP
     */
    public function searchByNip($nip)
    {
        if (empty($this->sid)) {
            throw new Exception("Brak aktywnej sesji BIR");
        }

        try {
            logEvent("BIR Search by NIP: {$nip}", 'DEBUG');
            
            $soapBody = '<ns:DaneSzukajPodmioty>
      <ns:pParametryWyszukiwania>
        <dat:Nip xmlns:dat="http://CIS/BIR/PUBL/2014/07/DataContract">' . htmlspecialchars($nip) . '</dat:Nip>
      </ns:pParametryWyszukiwania>
    </ns:DaneSzukajPodmioty>';
            
            $action = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty';
            
            $response = $this->sendSoapRequest($action, $soapBody);
            
            logEvent("BIR Search response (first 1000 chars): " . substr($response, 0, 1000), 'DEBUG');
            
            // Parse XML response
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = "Nie udało się przetworzyć odpowiedzi XML";
                if (!empty($errors)) {
                    $errorMsg .= ": " . $errors[0]->message;
                }
                libxml_clear_errors();
                throw new Exception($errorMsg);
            }
            
            // Register namespaces
            $xml->registerXPathNamespace('s', 'http://www.w3.org/2003/05/soap-envelope');
            $xml->registerXPathNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
            $xml->registerXPathNamespace('ns', 'http://CIS/BIR/PUBL/2014/07');
            
            // Extract result
            $resultNodes = $xml->xpath('//ns:DaneSzukajPodmiotyResult');
            if (empty($resultNodes)) {
                $resultNodes = $xml->xpath('//DaneSzukajPodmiotyResult');
            }
            
            if (empty($resultNodes) || empty((string)$resultNodes[0])) {
                logEvent("BIR Search: Empty result for NIP {$nip}", 'WARNING');
                return null;
            }
            
            // Parse inner XML
            libxml_use_internal_errors(true);
            $dataXml = simplexml_load_string((string)$resultNodes[0]);
            libxml_clear_errors();
            
            if (!$dataXml || !isset($dataXml->dane)) {
                logEvent("BIR Search: No 'dane' element in XML for NIP {$nip}", 'WARNING');
                return null;
            }

            $data = [
                'regon' => (string)$dataXml->dane->Regon,
                'nazwa' => (string)$dataXml->dane->Nazwa,
                'wojewodztwo' => (string)$dataXml->dane->Wojewodztwo,
                'powiat' => (string)$dataXml->dane->Powiat,
                'gmina' => (string)$dataXml->dane->Gmina,
                'miejscowosc' => (string)$dataXml->dane->Miejscowosc,
                'kodPocztowy' => (string)$dataXml->dane->KodPocztowy,
                'ulica' => (string)$dataXml->dane->Ulica,
                'nrNieruchomosci' => (string)$dataXml->dane->NrNieruchomosci,
                'nrLokalu' => (string)$dataXml->dane->NrLokalu,
                'typ' => (string)$dataXml->dane->Typ,
                'silosID' => (string)$dataXml->dane->SilosID,
            ];
            
            logEvent("BIR Search: Found company '{$data['nazwa']}' for NIP {$nip}", 'INFO');
            
            return $data;
        } catch (Exception $e) {
            logEvent("BIR Search Exception: " . $e->getMessage(), 'ERROR');
            throw new Exception("Błąd wyszukiwania w BIR: " . $e->getMessage());
        }
    }

    /**
     * Get full company report
     */
    public function getFullReport($regon, $silosID)
    {
        if (empty($this->sid)) {
            throw new Exception("Brak aktywnej sesji BIR");
        }

        try {
            // Determine report type based on entity type
            $reportName = '';
            switch ($silosID) {
                case '1': // Typ P - osoba prawna
                    $reportName = 'BIR11OsPrawna';
                    break;
                case '2': // Typ F - osoba fizyczna
                    $reportName = 'BIR11OsFizycznaDzialalnoscCeidg';
                    break;
                case '3': // Typ LP - jednostka lokalna osoby prawnej
                    $reportName = 'BIR11JednLokalnaOsPrawnej';
                    break;
                case '4': // Typ LF - jednostka lokalna osoby fizycznej
                    $reportName = 'BIR11JednLokalnaOsFizycznej';
                    break;
                default:
                    $reportName = 'BIR11OsPrawna';
            }

            $soapBody = '<ns:DanePobierzPelnyRaport>
      <ns:pRegon>' . htmlspecialchars($regon) . '</ns:pRegon>
      <ns:pNazwaRaportu>' . htmlspecialchars($reportName) . '</ns:pNazwaRaportu>
    </ns:DanePobierzPelnyRaport>';
            
            $action = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport';
            
            $response = $this->sendSoapRequest($action, $soapBody);
            
            // Parse XML response
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response);
            
            if ($xml === false) {
                libxml_clear_errors();
                return [];
            }
            
            // Register namespaces
            $xml->registerXPathNamespace('s', 'http://www.w3.org/2003/05/soap-envelope');
            $xml->registerXPathNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
            $xml->registerXPathNamespace('ns', 'http://CIS/BIR/PUBL/2014/07');
            
            // Extract result
            $resultNodes = $xml->xpath('//ns:DanePobierzPelnyRaportResult');
            if (empty($resultNodes)) {
                $resultNodes = $xml->xpath('//DanePobierzPelnyRaportResult');
            }
            
            if (empty($resultNodes) || empty((string)$resultNodes[0])) {
                return [];
            }
            
            // Parse inner XML
            libxml_use_internal_errors(true);
            $dataXml = simplexml_load_string((string)$resultNodes[0]);
            libxml_clear_errors();
            
            if (!$dataXml || !isset($dataXml->dane)) {
                return [];
            }

            $data = [];
            foreach ($dataXml->dane->children() as $child) {
                $data[$child->getName()] = (string)$child;
            }

            return $data;
        } catch (Exception $e) {
            // Don't throw - full report is optional
            logEvent("BIR Full Report Exception: " . $e->getMessage(), 'WARNING');
            return [];
        }
    }

    /**
     * Logout from BIR service
     */
    public function logout()
    {
        if (empty($this->sid)) {
            return true;
        }

        try {
            $soapBody = '<ns:Wyloguj>
      <ns:pIdentyfikatorSesji>' . htmlspecialchars($this->sid) . '</ns:pIdentyfikatorSesji>
    </ns:Wyloguj>';
            
            $action = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Wyloguj';
            
            $this->sendSoapRequest($action, $soapBody);
            $this->sid = null;
            
            logEvent("BIR Logout successful", 'DEBUG');
            return true;
        } catch (Exception $e) {
            // Ignore logout errors
            logEvent("BIR Logout warning: " . $e->getMessage(), 'WARNING');
            return false;
        }
    }
}

try {
    $birClient = new BirApiClient();
    
    // Login
    $birClient->login();
    
    // Search by NIP
    $basicData = $birClient->searchByNip($nip);
    
    if (!$basicData) {
        $birClient->logout();
        echo json_encode([
            'success' => false,
            'error' => 'Nie znaleziono firmy o podanym numerze NIP w bazie GUS'
        ]);
        exit;
    }
    
    // Get full report (optional - contains more details like KRS, email etc.)
    $fullData = [];
    if (!empty($basicData['regon']) && !empty($basicData['silosID'])) {
        $fullData = $birClient->getFullReport($basicData['regon'], $basicData['silosID']);
    }
    
    // Logout
    $birClient->logout();
    
    // Format address
    $address = '';
    $addressParts = [];
    
    if (!empty($basicData['ulica'])) {
        $street = $basicData['ulica'];
        if (!empty($basicData['nrNieruchomosci'])) {
            $street .= ' ' . $basicData['nrNieruchomosci'];
        }
        if (!empty($basicData['nrLokalu'])) {
            $street .= '/' . $basicData['nrLokalu'];
        }
        $addressParts[] = $street;
    } elseif (!empty($basicData['nrNieruchomosci'])) {
        $addressParts[] = $basicData['nrNieruchomosci'];
    }
    
    if (!empty($basicData['kodPocztowy']) && !empty($basicData['miejscowosc'])) {
        $addressParts[] = $basicData['kodPocztowy'] . ' ' . $basicData['miejscowosc'];
    } elseif (!empty($basicData['miejscowosc'])) {
        $addressParts[] = $basicData['miejscowosc'];
    }
    
    $address = implode(', ', array_filter($addressParts));
    
    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'name' => $basicData['nazwa'] ?? '',
            'nip' => $nip,
            'regon' => $basicData['regon'] ?? '',
            'krs' => $fullData['fiz_regon9'] ?? $fullData['praw_regon9'] ?? '', // KRS może być w pełnym raporcie
            'address' => $address,
            'email' => $fullData['fiz_adresEmail'] ?? $fullData['praw_adresEmail'] ?? '',
            'phone' => $fullData['fiz_numerTelefonu'] ?? $fullData['praw_numerTelefonu'] ?? '',
            'website' => $fullData['fiz_adresStronyinternetowej'] ?? $fullData['praw_adresStronyinternetowej'] ?? '',
            // Additional info
            'wojewodztwo' => $basicData['wojewodztwo'] ?? '',
            'powiat' => $basicData['powiat'] ?? '',
            'gmina' => $basicData['gmina'] ?? '',
        ]
    ];
    
    // Log successful fetch
    logEvent("Pobrano dane z GUS dla NIP: {$nip} - {$basicData['nazwa']}", 'INFO');
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    
    // Log error
    logEvent("Błąd BIR API dla NIP {$nip}: " . $e->getMessage(), 'ERROR');
    
    echo json_encode([
        'success' => false,
        'error' => 'Błąd podczas pobierania danych z GUS: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

