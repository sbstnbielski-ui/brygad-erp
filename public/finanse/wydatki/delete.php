<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Wydatku Pracownika
 * TYLKO DLA ADMINA
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może usuwać wydatki

$pdo = getDbConnection();
$errors = [];
$defaultReturnUrl = url('finanse.wydatki');

$resolveReturnUrl = static function ($candidate, string $fallback): string {
    if (!is_string($candidate) || $candidate === '') {
        return $fallback;
    }
    $parts = parse_url($candidate);
    if ($parts === false) {
        return $fallback;
    }
    if (isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return $fallback;
    }
    return $candidate;
};

$returnUrl = $resolveReturnUrl($_POST['return_url'] ?? $_GET['return_url'] ?? null, $defaultReturnUrl);

// Obsługa POST z formularza (z confirm)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expenseId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if ($expenseId <= 0) {
        $_SESSION['error'] = 'Nieprawidłowe ID wydatku.';
        header('Location: ' . $returnUrl);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Pobierz dane wydatku (dla loga + lock)
        $stmt = $pdo->prepare("
            SELECT 
                we.*,
                w.first_name,
                w.last_name
            FROM worker_expenses we
            JOIN workers w ON we.worker_id = w.id
            WHERE we.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$expenseId]);
        $expense = $stmt->fetch();
        
        if (!$expense) {
            $pdo->rollBack();
            $_SESSION['error'] = 'Wydatek nie został znaleziony.';
            header('Location: ' . $returnUrl);
            exit;
        }

        $walletAdvanceId = isset($expense['wallet_advance_id']) ? (int)$expense['wallet_advance_id'] : 0;
        $walletLedgerId = isset($expense['wallet_ledger_id']) ? (int)$expense['wallet_ledger_id'] : 0;
        $allocationCount = 0;
        try {
            $allocStmt = $pdo->prepare("SELECT COUNT(*) FROM worker_expense_advance_allocations WHERE worker_expense_id = ?");
            $allocStmt->execute([$expenseId]);
            $allocationCount = (int)$allocStmt->fetchColumn();
        } catch (Throwable $e) {}
        $walletLinked = $walletAdvanceId > 0 || $walletLedgerId > 0 || $allocationCount > 0;

        if ($walletLinked) {
            throw new RuntimeException('Nie można usunąć wydatku powiązanego z portfelem/ledgerem. Wykonaj korektę księgową zamiast kasowania ruchów.');
        }

        // Usuń wydatek
        $stmt = $pdo->prepare("DELETE FROM worker_expenses WHERE id = ?");
        $stmt->execute([$expenseId]);

        $pdo->commit();

        $logNote = $walletLinked ? ', powiązany z portfelem' : '';
        logEvent(
            "Usunięto wydatek ID $expenseId (pracownik: {$expense['first_name']} {$expense['last_name']}, kwota: {$expense['amount']} PLN{$logNote}) przez user ID " . $_SESSION['user_id'],
            'WARNING'
        );

        $_SESSION['success'] = 'Wydatek został usunięty.';
        header('Location: ' . $returnUrl);
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logEvent("Błąd biznesowy usuwania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $returnUrl);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logEvent("Błąd usuwania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
        $_SESSION['error'] = 'Błąd usuwania wydatku.';
        header('Location: ' . $returnUrl);
        exit;
    }
} else {
    // Jeśli nie POST, przekieruj
    header('Location: ' . $returnUrl);
    exit;
}
