<?php
/**
 * BRYGAD ERP - Bezpieczne pobieranie plików z archiwum Fakturowni
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo 'Nieprawidłowy identyfikator pliku.';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, file_path, file_name, mime_type, file_size
     FROM fakturownia_archive_files
     WHERE id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Plik archiwum nie został znaleziony.';
    exit;
}

$relativePath = ltrim((string)$row['file_path'], '/');
$absolutePath = ROOT_PATH . '/' . $relativePath;
$allowedBase = realpath(ROOT_PATH . '/storage/fakturownia-archive');
$allowedPrefix = $allowedBase === false ? null : rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if (!file_exists($absolutePath) || !is_readable($absolutePath)) {
    http_response_code(404);
    echo 'Plik archiwum nie istnieje na dysku.';
    exit;
}

$realPath = realpath($absolutePath);
if ($allowedPrefix === null || $realPath === false || strpos($realPath, $allowedPrefix) !== 0) {
    logEvent('Archive download blocked (path outside archive): ' . $relativePath, 'WARNING');
    http_response_code(403);
    echo 'Brak dostępu do pliku.';
    exit;
}

$mimeType = (string)($row['mime_type'] ?: 'application/octet-stream');
$fileName = (string)($row['file_name'] ?: basename($absolutePath));
$size = (int)filesize($realPath);
if ($size <= 0) {
    $size = (int)($row['file_size'] ?? 0);
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $size);
$safeName = str_replace(['"', "\r", "\n"], '', $fileName);
header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;
