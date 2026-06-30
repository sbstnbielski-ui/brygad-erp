<?php
/**
 * Endpoint OCR - Skanowanie faktury przez Gemini API
 * POST /api/ocr/gemini-process.php
 */

header('Content-Type: application/json; charset=utf-8');

// Sprawdź metodę
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda POST wymagana']);
    exit;
}

// Sprawdź czy plik został przesłany
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak pliku lub błąd uploadu']);
    exit;
}

// Załaduj konfigurację
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/GeminiOcrService.php';

$geminiApiKey = getenv('GEMINI_API_KEY') ?: '';

if ($geminiApiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Brak konfiguracji GEMINI_API_KEY']);
    exit;
}

try {
    
    $uploadedFile = $_FILES['file'];
    
    // Typ faktury: 'cost' (kosztowa) lub 'sale' (sprzedażowa)
    $invoiceType = $_POST['invoice_type'] ?? 'cost';
    if (!in_array($invoiceType, ['cost', 'sale'])) {
        $invoiceType = 'cost';
    }
    
    // Walidacja typu pliku
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($uploadedFile['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Nieprawidłowy typ pliku. Dozwolone: PDF, JPG, PNG, GIF, WEBP'
        ]);
        exit;
    }
    
    // Walidacja rozmiaru (max 20MB)
    $maxSize = 20 * 1024 * 1024; // 20MB
    if ($uploadedFile['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Plik zbyt duży. Maksymalny rozmiar: 20MB'
        ]);
        exit;
    }
    
    // Inicjalizuj serwis OCR z odpowiednim typem faktury
    $ocrService = new GeminiOcrService($geminiApiKey, $invoiceType);
    
    // Skanuj fakturę
    $result = $ocrService->scanInvoice($uploadedFile['tmp_name'], $invoiceType);
    
    // Zwróć wynik
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $result,
        'message' => 'Faktura zeskanowana pomyślnie'
    ]);
    
} catch (Exception $e) {
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
}

