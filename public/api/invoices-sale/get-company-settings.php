<?php
/**
 * API: Pobierz dane firmy (sprzedawcy) z company_settings
 * GET - zwraca JSON z danymi sprzedawcy i domyślnymi ustawieniami faktur
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        echo json_encode(['success' => false, 'error' => 'Brak ustawień firmy'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'company_name'             => $settings['company_name'],
            'company_nip'              => $settings['company_nip'],
            'company_regon'            => $settings['company_regon'],
            'company_address'          => $settings['company_address'],
            'company_city'             => $settings['company_city'],
            'company_post_code'        => $settings['company_post_code'],
            'company_email'            => $settings['company_email'],
            'company_phone'            => $settings['company_phone'],
            'company_website'          => $settings['company_website'],
            'logo_path'                => $settings['logo_path'],
            'default_bank_account'     => $settings['default_bank_account'],
            'default_bank_name'        => $settings['default_bank_name'],
            'default_place_of_issue'   => $settings['default_place_of_issue'],
            'default_payment_days'     => (int)$settings['default_payment_days'],
            'default_payment_method'   => $settings['default_payment_method'],
            'default_currency'         => $settings['default_currency'],
            'default_notes'            => $settings['default_notes'],
            'default_description_footer' => $settings['default_description_footer'],
            'fakturownia_department_id' => $settings['fakturownia_department_id'] ? (int)$settings['fakturownia_department_id'] : null,
            'fakturownia_lang'         => $settings['fakturownia_lang'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    logEvent('Błąd get-company-settings API: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Błąd pobierania ustawień'], JSON_UNESCAPED_UNICODE);
}
