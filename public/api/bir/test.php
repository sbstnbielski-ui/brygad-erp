<?php
/**
 * BRYGAD ERP - BIR API Diagnostic Test
 * Test połączenia z GUS REGON API
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

$diagnostics = [];

// 1. Check PHP cURL Extension (required for BIR API)
$diagnostics['curl_extension'] = function_exists('curl_init');
$diagnostics['curl_version'] = $diagnostics['curl_extension'] ? curl_version()['version'] : 'N/A';

// 2. Check OpenSSL
$diagnostics['openssl_extension'] = extension_loaded('openssl');
$diagnostics['openssl_version'] = $diagnostics['openssl_extension'] ? OPENSSL_VERSION_TEXT : 'N/A';

// 3. Check PHP Version
$diagnostics['php_version'] = phpversion();

// 4. Check allow_url_fopen
$diagnostics['allow_url_fopen'] = ini_get('allow_url_fopen') ? true : false;

// 5. Test WSDL access
$wsdlUrl = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/wsdl/UslugaBIRzewnPubl-ver11-prod.wsdl';
$diagnostics['wsdl_url'] = $wsdlUrl;
$diagnostics['wsdl_accessible'] = false;
$diagnostics['wsdl_error'] = null;

try {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'timeout' => 10
        ]
    ]);
    
    $wsdlContent = @file_get_contents($wsdlUrl, false, $context);
    if ($wsdlContent !== false) {
        $diagnostics['wsdl_accessible'] = true;
        $diagnostics['wsdl_size'] = strlen($wsdlContent);
    } else {
        $diagnostics['wsdl_error'] = error_get_last()['message'] ?? 'Unknown error';
    }
} catch (Exception $e) {
    $diagnostics['wsdl_error'] = $e->getMessage();
}

// 6. Test cURL Connection
$diagnostics['curl_test'] = false;
$diagnostics['curl_error'] = null;

if ($diagnostics['curl_extension']) {
    try {
        $diagnostics['curl_test'] = 'success';
        
        // Get available methods (from WSDL documentation)
        $diagnostics['bir_methods'] = [
            'Zaloguj - Login to BIR service',
            'Wyloguj - Logout from BIR service',
            'DaneSzukajPodmioty - Search for entities',
            'DanePobierzPelnyRaport - Get full report',
            'GetValue - Get service value'
        ];
        
        // Try to login using cURL
        $apiKey = 'a6cf459b1ddf480ebf7d';
        $apiUrl = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
        $diagnostics['login_test'] = 'attempting';
        $diagnostics['api_key_used'] = substr($apiKey, 0, 10) . '...';
        
        try {
            // Build SOAP envelope
            $soapEnvelope = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">
  <soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">
    <wsa:To>https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc</wsa:To>
    <wsa:Action>http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj</wsa:Action>
  </soap:Header>
  <soap:Body>
    <ns:Zaloguj>
      <ns:pKluczUzytkownika>' . htmlspecialchars($apiKey) . '</ns:pKluczUzytkownika>
    </ns:Zaloguj>
  </soap:Body>
</soap:Envelope>';

            $diagnostics['soap_request'] = $soapEnvelope;
            
            $ch = curl_init($apiUrl);
            
            $headers = [
                'Content-Type: application/soap+xml; charset=utf-8',
                'Content-Length: ' . strlen($soapEnvelope)
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $soapEnvelope,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HEADER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlError = curl_error($ch);
            
            curl_close($ch);
            
            $header = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            
            $diagnostics['soap_response'] = $body;
            $diagnostics['soap_response_headers'] = $header;
            $diagnostics['http_code'] = $httpCode;
            
            // Extract XML from MTOM/multipart response if needed
            $cleanXml = $body;
            if (strpos($body, '--uuid:') !== false || strpos($body, 'Content-Type: application/xop+xml') !== false) {
                // This is a multipart MTOM response
                $envelopeStart = strpos($body, '<s:Envelope');
                if ($envelopeStart === false) {
                    $envelopeStart = strpos($body, '<soap:Envelope');
                }
                
                $envelopeEnd = strpos($body, '</s:Envelope>');
                if ($envelopeEnd === false) {
                    $envelopeEnd = strpos($body, '</soap:Envelope>');
                }
                
                if ($envelopeStart !== false && $envelopeEnd !== false) {
                    $xmlLength = $envelopeEnd - $envelopeStart + strlen('</s:Envelope>');
                    $cleanXml = substr($body, $envelopeStart, $xmlLength);
                    $diagnostics['multipart_detected'] = true;
                    $diagnostics['extracted_xml'] = $cleanXml;
                }
            }
            
            if ($curlError) {
                throw new Exception("cURL Error: " . $curlError);
            }
            
            if ($httpCode !== 200) {
                throw new Exception("HTTP Error: " . $httpCode);
            }
            
            // Parse XML response
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($cleanXml);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = "Failed to parse XML response";
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
            
            // Extract SID - try multiple possible paths
            $sidNodes = $xml->xpath('//ns:ZalogujResult');
            if (empty($sidNodes)) {
                // Try without namespace prefix
                $sidNodes = $xml->xpath('//ZalogujResult');
            }
            
            if (!empty($sidNodes) && !empty((string)$sidNodes[0])) {
                $sid = (string)$sidNodes[0];
                $diagnostics['login_test'] = 'success';
                $diagnostics['session_id'] = substr($sid, 0, 20) . '...';
                $diagnostics['logout_test'] = 'skipped';
            } else {
                $diagnostics['login_test'] = 'failed - no SID in response';
            }
            
        } catch (Exception $e) {
            $diagnostics['login_test'] = 'exception';
            $diagnostics['login_error'] = $e->getMessage();
        }
        
    } catch (Exception $e) {
        $diagnostics['curl_error'] = 'Exception: ' . $e->getMessage();
    }
}

// 7. Summary
$diagnostics['overall_status'] = 
    $diagnostics['curl_extension'] && 
    $diagnostics['openssl_extension'] && 
    $diagnostics['wsdl_accessible'] && 
    $diagnostics['curl_test'] === 'success' &&
    ($diagnostics['login_test'] ?? false) === 'success'
    ? 'OK' : 'ISSUES_DETECTED';

// Log diagnostics
logEvent("BIR API Diagnostics: " . json_encode($diagnostics), 'INFO');

echo json_encode([
    'success' => true,
    'diagnostics' => $diagnostics
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

