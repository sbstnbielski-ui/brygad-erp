<?php
/**
 * Partial: single invoice row for Centrum Faktur Sprzedażowych.
 * Expected variables: $inv, $isAdminUser, $csrfToken, $__dayGroupMode (optional), $pdo
 */
$invId   = (int)$inv['id'];
$editUrl = url('finanse.faktury-sprzedazowe.edit', ['id' => $invId]);

$statusClass = statusBadgeClass($inv['status']);
$statusLbl   = statusLabel($inv['status']);

$isOverdue      = ($inv['status'] === 'issued' && (int)($inv['days_until_due'] ?? 0) < 0);
$daysUntilDue   = (int)($inv['days_until_due'] ?? 0);
$hasRetention   = !empty($inv['retention_id']);
$retComputed    = $inv['retention_computed'] ?? null;

$fkMappingRow = null;
$fkIdRow = 0;
$fkNumberRow = '';
$fkGovIdRow = '';
$fkGovStatusRow = '';
try {
    $fkStmt = $pdo->prepare("
        SELECT fakturownia_id, fakturownia_number, gov_id, gov_status, synced_at
        FROM fakturownia_invoices
        WHERE erp_invoice_sale_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $fkStmt->execute([$invId]);
    $fkMappingRow = $fkStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($fkMappingRow) {
        $fkIdRow = (int)($fkMappingRow['fakturownia_id'] ?? 0);
        $fkNumberRow = (string)($fkMappingRow['fakturownia_number'] ?? '');
        $fkGovIdRow = (string)($fkMappingRow['gov_id'] ?? '');
        $fkGovStatusRow = (string)($fkMappingRow['gov_status'] ?? '');
    }
} catch (Throwable $e) {}

$isExcluded = !empty($inv['exclude_from_analytics']);
$paidSoFar = 0;
try {
    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_net), 0) FROM invoice_sale_payments WHERE invoice_id = ?');
    $paidStmt->execute([$invId]);
    $paidSoFar = (float)$paidStmt->fetchColumn();
} catch (Throwable $e) {}
$invNetAmount = (float)($inv['amount_net'] ?? 0);
$paidPercent = $invNetAmount > 0 ? min(100, round(($paidSoFar / $invNetAmount) * 100)) : 0;
$remainToPay = max(0, $invNetAmount - $paidSoFar);
$showSourceMeta = ($source ?? 'invoice') === 'all';
?>
<td>
    <a href="<?php echo $editUrl; ?>" style="color:var(--primary);text-decoration:none;font-weight:700;">
        <?php echo e($inv['invoice_number'] ?? '-'); ?>
    </a>
    <?php if ($showSourceMeta): ?>
        <div class="row-source-meta">FV</div>
    <?php endif; ?>
    <?php if ($isExcluded): ?>
        <div style="font-size:10px;font-weight:700;color:#7c3aed;margin-top:2px;">⊘ WYKLUCZONA Z ANALIZ</div>
    <?php endif; ?>
    <?php if (!empty($inv['sync_attention_required'])): ?>
        <div style="font-size:10px;font-weight:700;color:#b45309;margin-top:2px;" title="<?php echo e($inv['sync_attention_note'] ?? 'Wymaga weryfikacji synchronizacji'); ?>">WERYFIKACJA SYNC</div>
    <?php endif; ?>
    <?php if ($fkMappingRow && $fkNumberRow !== '' && $fkNumberRow !== (string)($inv['invoice_number'] ?? '')): ?>
        <div style="font-size:10px;font-weight:700;color:#b45309;margin-top:2px;">FK: <?php echo e($fkNumberRow); ?></div>
    <?php elseif (!$fkMappingRow && $inv['status'] !== 'draft'): ?>
        <div style="font-size:10px;font-weight:700;color:#991b1b;margin-top:2px;">Brak synchronizacji z Fakturownią</div>
    <?php endif; ?>
</td>

<td title="<?php
    $clientFull = trim((string)($inv['client_name'] ?? ''));
    echo e($clientFull !== '' ? $clientFull : '-');
?>">
    <?php
    echo e($clientFull === '' ? '-' : (mb_strlen($clientFull) > 30 ? mb_substr($clientFull, 0, 28) . '…' : $clientFull));
    ?>
    <?php if (!empty($inv['client_nip']) && $clientFull !== ''): ?>
        <div style="font-size:11px;color:var(--text-muted);">NIP: <?php echo e($inv['client_nip']); ?></div>
    <?php endif; ?>
</td>

<?php if (!isset($__dayGroupMode)): ?>
<td><?php echo $inv['issue_date'] ? formatDate($inv['issue_date']) : '-'; ?></td>
<?php endif; ?>

<td>
    <?php echo $inv['due_date'] ? formatDate($inv['due_date']) : '-'; ?>
    <?php if ($isOverdue): ?>
        <br><span class="overdue-warning">Zaległość: <?php echo abs($daysUntilDue); ?> dni</span>
    <?php elseif ($inv['status'] === 'issued' && $daysUntilDue > 0 && $daysUntilDue <= 7): ?>
        <br><span style="color: #f59e0b; font-size: 12px;">Za <?php echo $daysUntilDue; ?> dni</span>
    <?php endif; ?>
</td>

<td><?php echo formatMoney($inv['amount_net']); ?></td>
<td><strong><?php echo formatMoney($inv['amount_gross']); ?></strong></td>

<td>
    <button type="button" class="status-badge-btn <?php echo $statusClass; ?>"
            onclick="openStatusPortal(event, this, <?php echo $invId; ?>)">
        <?php echo $statusLbl; ?>
        <?php if ($paidSoFar > 0 && $inv['status'] === 'partially_paid'): ?>
            <span class="paid-hint">(<?php echo $paidPercent; ?>%)</span>
        <?php endif; ?>
    </button>

    <template class="status-panel-tpl" data-inv-id="<?php echo $invId; ?>">
        <div class="status-portal">
            <div class="status-panel-header">Status faktury</div>
            <div class="status-panel-options">
                <?php
                $statusOptions = [
                    'draft' => 'Szkic',
                    'issued' => 'Wystawiona',
                    'paid' => 'Opłacona',
                    'partially_paid' => 'Częśc. opłacona',
                    'cancelled' => 'Anulowana',
                ];
                foreach ($statusOptions as $sVal => $sLabel):
                    $isActive = $inv['status'] === $sVal;
                ?>
                <form method="POST" action="" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                    <input type="hidden" name="new_status" value="<?php echo $sVal; ?>">
                    <button type="<?php echo $isActive ? 'button' : 'submit'; ?>"
                            class="sp-status-opt <?php echo $isActive ? 'sp-status-active' : ''; ?>"
                            <?php echo !$isActive ? 'onclick="return confirm(\'Zmienić status na: ' . $sLabel . '?\')"' : ''; ?>>
                        <?php echo $sLabel; ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>

            <?php if ($paidSoFar > 0): ?>
            <div class="status-panel-progress">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width:<?php echo $paidPercent; ?>%"></div>
                </div>
                <div class="progress-label"><?php echo formatMoney($paidSoFar); ?> / <?php echo formatMoney($invNetAmount); ?> netto</div>
            </div>
            <?php endif; ?>

            <div class="status-panel-payment">
                <div class="status-panel-header" style="margin-top:8px;">Dodaj wpłatę</div>
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_payment">
                    <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                    <div class="sp-pay-grid">
                        <div>
                            <label>Kwota netto</label>
                            <input type="number" name="payment_amount_net" step="0.01" min="0.01"
                                   placeholder="<?php echo number_format($remainToPay, 2, '.', ''); ?>" required>
                        </div>
                        <div>
                            <label>Data wpływu</label>
                            <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div style="grid-column:1/-1;">
                            <label>Notatka</label>
                            <input type="text" name="payment_notes" placeholder="Opcjonalnie" maxlength="500">
                        </div>
                    </div>
                    <button type="submit" class="sp-pay-submit">Zapisz wpłatę</button>
                </form>
            </div>

            <?php if ($paidSoFar > 0): ?>
            <div style="border-top:1px solid #e2e8f0;margin-top:10px;padding-top:10px;">
                <form method="POST" action="" onsubmit="return confirm('Usunąć wszystkie ręczne wpłaty i cofnąć status do Wystawiona? Zmiana trafi też do Fakturowni.');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="reset_to_issued">
                    <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                    <button type="submit" class="sp-pay-submit" style="background:#ef4444;border-color:#dc2626;width:100%;">
                        Zeruj — cofnij do wystawionej
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </template>
</td>

<td>
    <?php if ($hasRetention && $retComputed): ?>
        <?php if ($retComputed !== 'settled'): ?>
            <button type="button" class="retention-link" onclick="toggleRetForm(<?php echo $invId; ?>)" title="Edytuj retencję">
                <span class="badge <?php echo retentionBadgeClass($retComputed); ?>">
                    <?php echo retentionLabel($retComputed); ?>
                </span>
                <span class="retention-link-meta">
                    <?php echo formatMoney($inv['retention_amount']); ?>
                    (<?php echo number_format((float)$inv['retention_percent'], 1, ',', ''); ?>%)
                    <?php if ($inv['retention_return_date']): ?>
                        <br>zwrot: <?php echo formatDate($inv['retention_return_date']); ?>
                    <?php endif; ?>
                </span>
            </button>
        <?php else: ?>
            <span class="badge <?php echo retentionBadgeClass($retComputed); ?>">
                <?php echo retentionLabel($retComputed); ?>
            </span>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                <?php echo formatMoney($inv['retention_amount']); ?>
                (<?php echo number_format((float)$inv['retention_percent'], 1, ',', ''); ?>%)
                <?php if ($inv['retention_return_date']): ?>
                    <br>zwrot: <?php echo formatDate($inv['retention_return_date']); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <span style="color:var(--text-muted);font-size:12px;">—</span>
    <?php endif; ?>
</td>

<td>
    <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
        <div class="action-buttons" style="flex-wrap:nowrap;">
            <a href="<?php echo $editUrl; ?>" class="action-btn action-btn-edit">
                <?php echo $inv['status'] === 'draft' ? 'Edytuj' : 'Zobacz'; ?>
            </a>
            <button type="button" class="action-btn action-btn-more" onclick="openRowDropdown(event, this)" data-inv-id="<?php echo $invId; ?>">&#9660;</button>

            <template class="row-dd-tpl">
                <div class="row-dd-portal">
                    <a href="<?php echo $editUrl; ?>">Zobacz / Edytuj</a>

                    <?php if ($fkIdRow > 0): ?>
                        <a href="/api/invoices-sale/download-pdf.php?fakturownia_id=<?php echo $fkIdRow; ?>" target="_blank">Drukuj / PDF</a>
                    <?php elseif ($inv['status'] !== 'draft'): ?>
                        <span style="display:block;padding:7px 10px;color:#991b1b;font-size:12px;">Brak ID Fakturowni</span>
                    <?php endif; ?>

                    <?php
                    // Sprawdź czy faktura ma dane JST
                    $hasJst = false;
                    try {
                        $jstChkStmt = $pdo->prepare('SELECT 1 FROM invoice_sale_jst_data WHERE invoice_sale_id = ? LIMIT 1');
                        $jstChkStmt->execute([$invId]);
                        $hasJst = (bool)$jstChkStmt->fetchColumn();
                    } catch (Throwable $e) {}
                    ?>
                    <?php if ($hasJst): ?>
                        <div class="row-dd-sep"></div>
                        <a href="<?php echo $editUrl; ?>" style="color:#7c3aed;font-weight:600;">Faktura JST — podgląd / wystaw</a>
                    <?php endif; ?>

                    <?php if ($inv['status'] === 'draft'): ?>
                        <div class="row-dd-sep"></div>
                        <a href="<?php echo url('finanse.faktury-sprzedazowe.create-jst'); ?>" style="color:#7c3aed;">Nowa faktura dla JST</a>
                    <?php endif; ?>

                    <?php if ($inv['status'] !== 'draft'): ?>
                    <div class="row-dd-sep"></div>
                    <a href="<?php echo url('finanse.faktury-sprzedazowe.create'); ?>?correction_of=<?php echo $invId; ?>">Wystaw korektę</a>
                    <a href="<?php echo url('finanse.faktury-sprzedazowe.create'); ?>?clone_from=<?php echo $invId; ?>">Wystaw podobną</a>
                    <?php if ($fkIdRow > 0): ?>
                        <button type="button" onclick="closeRowDropdown();sendInvoiceEmail(<?php echo $fkIdRow; ?>,'<?php echo e($inv['invoice_number']); ?>');">Wyślij e-mail do klienta</button>
                    <?php else: ?>
                        <span style="display:block;padding:7px 10px;color:#991b1b;font-size:12px;">Brak synchronizacji z Fakturownią</span>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="row-dd-sep"></div>
                    <button type="button" onclick="closeRowDropdown();openStatusPortalById(<?php echo $invId; ?>);">Zmień status / dodaj wpłatę</button>

                    <?php if (!$hasRetention || $retComputed !== 'settled'): ?>
                    <div class="row-dd-sep"></div>
                    <button type="button" onclick="closeRowDropdown(); toggleRetForm(<?php echo $invId; ?>);">
                        <?php echo $hasRetention ? 'Edytuj retencję' : 'Dodaj retencję'; ?>
                    </button>
                    <?php endif; ?>

                    <?php if ($hasRetention && $retComputed !== 'settled'): ?>
                    <form method="POST" action="" style="margin:0;" onsubmit="return confirm('Rozliczyć retencję?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="settle_retention">
                        <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                        <button type="submit">Rozlicz retencję</button>
                    </form>
                    <?php endif; ?>

                    <div class="row-dd-sep"></div>
                    <form method="POST" action="" style="margin:0;"
                          onsubmit="return confirm('<?php echo $isExcluded ? 'Przywrócić fakturę do analiz?' : 'Wykluczyć fakturę z analiz i oznaczyć jako opłaconą w Fakturowni?'; ?>');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="toggle_exclude">
                        <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                        <button type="submit" style="color:<?php echo $isExcluded ? '#059669' : '#7c3aed'; ?>;font-weight:600;">
                            <?php echo $isExcluded ? '✓ Przywróć do analiz' : '⊘ Wyklucz z analiz'; ?>
                        </button>
                    </form>

                    <?php if ($isAdminUser): ?>
                    <div class="row-dd-sep"></div>
                    <?php $hasAlloc = !empty($inv['assigned_projects']); ?>
                    <button type="button" onclick="closeRowDropdown();openAssignProjectModal(<?php echo $invId; ?>,'<?php echo e($inv['invoice_number']); ?>');"><?php echo $hasAlloc ? 'Alokacja (' . e($inv['assigned_projects']) . ')' : 'Alokuj do projektu'; ?></button>
                    <div class="row-dd-sep"></div>
                    <form method="POST" action="<?php echo url('finanse.faktury-sprzedazowe.delete'); ?>" style="margin:0;"
                          onsubmit="return confirm('Oznaczyć fakturę <?php echo e($inv['invoice_number']); ?> jako anulowaną/usuniętą lokalnie? Dokumenty z Fakturowni/KSeF nie zostaną usunięte.');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                        <input type="hidden" name="delete_reason" value="Usunięcie/anulowanie z listy faktur">
                        <button type="submit" class="row-dd-danger">Usuń fakturę</button>
                    </form>
                    <?php endif; ?>
                </div>
            </template>
        </div>

        <?php if (!$hasRetention || $retComputed !== 'settled'): ?>
        <div class="ret-form-inline js-ret-form" data-ret-id="<?php echo $invId; ?>">
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_retention">
                <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                <div class="ret-grid">
                    <div><label>% retencji</label><input type="number" name="retention_percent" value="<?php echo $hasRetention ? number_format((float)$inv['retention_percent'], 1, '.', '') : '5'; ?>" step="0.5" min="0" max="100"></div>
                    <div><label>Kwota</label><input type="number" name="retention_amount" value="<?php echo $hasRetention ? number_format((float)$inv['retention_amount'], 2, '.', '') : round((float)$inv['amount_gross'] * 0.05, 2); ?>" step="0.01" min="0"></div>
                    <div><label>Data zwrotu</label><input type="date" name="return_date" value="<?php echo e($inv['retention_return_date'] ?? ''); ?>"></div>
                    <div><label>Przypomnienie</label><input type="date" name="reminder_date" value="<?php echo e($inv['retention_reminder_date'] ?? ''); ?>"></div>
                    <div style="grid-column:1 / -1;"><label>Notatka</label><textarea name="retention_notes" rows="2" maxlength="500" placeholder="Opcjonalna notatka"><?php echo e($inv['retention_notes'] ?? ''); ?></textarea></div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button type="submit" class="action-btn action-btn-ret"><?php echo $hasRetention ? 'Zapisz zmiany ret.' : 'Zapisz retencję'; ?></button>
                    <button type="button" class="action-btn" style="color:var(--text-muted);border-color:var(--border-light);" onclick="toggleRetForm(<?php echo $invId; ?>)">Anuluj</button>
                </div>
            </form>
            <?php if ($hasRetention): ?>
                <form method="POST" action="" class="delete-form" onsubmit="return confirm('Usunąć retencję z tej faktury?');" style="margin-top:6px;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="remove_retention">
                    <input type="hidden" name="invoice_id" value="<?php echo $invId; ?>">
                    <button type="submit" class="action-btn action-btn-delete">Usuń retencję</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</td>
