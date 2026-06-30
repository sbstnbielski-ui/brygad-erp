<?php
/**
 * API: klucz publiczny VAPID (Web Push)
 * GET /api/push/vapid-key.php
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/config/push.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane zalogowanie']);
    exit;
}

$publicKey = getVapidPublicKey();

if ($publicKey === '') {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Web Push nie jest skonfigurowany. Ustaw VAPID_PUBLIC_KEY w .env',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'publicKey' => $publicKey,
]);
