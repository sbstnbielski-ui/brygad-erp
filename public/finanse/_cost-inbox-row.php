<?php
/**
 * Partial: single invoice row for Centrum Faktur table.
 * Expected variables: $inv, $isFakturownia, $rawId, $fakturowniaSubdomain
 */
$src = $inv['source'];
$invType = isset($inv['invoice_type']) ? $inv['invoice_type'] : 'cost';
$isCostCorrection = $src === 'fakturownia' && (($inv['document_kind'] ?? '') === 'correction');

$sourceLabels = [
    'fakturownia' => 'Fakturownia',
    'legacy'      => 'Stare faktury',
    'documents'   => 'Dokumenty ERP',
    'manual'      => 'Ręczna',
];
$sourceLabel = isset($sourceLabels[$src]) ? $sourceLabels[$src] : $src;
$currentInboxUrl = $_SERVER['REQUEST_URI'] ?? url('finanse.fakturownia-cost-inbox');
if (!is_string($currentInboxUrl) || strpos($currentInboxUrl, '/') !== 0 || strpos($currentInboxUrl, '//') === 0) {
    $currentInboxUrl = url('finanse.fakturownia-cost-inbox');
}

$linkUrl = '#';
if ($src === 'fakturownia') {
    $linkUrl = url('finanse.fakturownia-cost-inbox.view', ['id' => $rawId, 'return_to' => $currentInboxUrl]);
} elseif ($src === 'legacy') {
    $linkUrl = url('finanse.faktury.edit', ['id' => $rawId]);
} elseif ($src === 'documents') {
    $linkUrl = url('finanse.fakturownia-cost-inbox.document-view', ['id' => $rawId, 'return_to' => $currentInboxUrl]);
}
?>
<td class="invoice-number-cell" title="<?php echo e($inv['invoice_number'] ?? '-'); ?>">
    <a href="<?php echo e($linkUrl); ?>" style="color:var(--primary);text-decoration:none;font-weight:700;">
        <span class="invoice-main"><?php echo e($inv['invoice_number'] ?? '-'); ?></span>
    </a>
    <span class="source-tag"><?php echo e($sourceLabel); ?></span>
    <?php if ($isCostCorrection): ?>
        <span class="source-tag" style="background:#fef3c7;color:#92400e;border-color:#f59e0b;">Korekta</span>
    <?php endif; ?>
</td>

<td class="supplier-cell" title="<?php echo e($inv['supplier_name']); ?>">
    <?php
    $supplierFull = $inv['supplier_name'] ?? '';
    ?>
    <span class="supplier-main"><?php echo e($supplierFull); ?></span>
    <?php if (!empty($inv['supplier_nip'])): ?>
        <div style="font-size:11px;color:var(--text-muted);">NIP: <?php echo e($inv['supplier_nip']); ?></div>
    <?php endif; ?>
</td>

<?php if (!isset($__dayGroupMode)): ?>
<td><?php echo $inv['issue_date'] ? formatDate($inv['issue_date']) : '-'; ?></td>
<?php endif; ?>

<td><?php echo $inv['due_date'] ? formatDate($inv['due_date']) : '-'; ?></td>

<td class="money-cell"><strong><?php echo $inv['amount_net'] !== null ? formatMoney($inv['amount_net']) : '-'; ?></strong></td>

<td class="money-cell"><strong><?php echo $inv['amount_gross'] !== null ? formatMoney($inv['amount_gross']) : '-'; ?></strong></td>

<td>
    <?php
    $refAmount = ($inv['amount_net'] !== null) ? (float)$inv['amount_net'] : 0;
    $allocatedNet = (float)($inv['allocated_net'] ?? 0);
    $allocPercent = $refAmount > 0 ? round(($allocatedNet / $refAmount) * 100, 1) : 0;
    ?>
    <div style="font-size:12px;">
        <strong><?php echo formatMoney($allocatedNet); ?></strong> / <?php echo formatMoney($refAmount); ?>
    </div>
    <div style="font-size:11px;color:var(--text-muted);">
        <?php echo number_format($allocPercent, 1, ',', ' '); ?>% &bull; <?php echo (int)($inv['allocations_count'] ?? 0); ?> poz.
    </div>
</td>

<td>
    <?php if ($src === 'fakturownia' && $invType === 'cost'): ?>
        <form method="POST" action="" class="inline-form" id="form-payment-<?php echo $rawId; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_payment_status">
            <input type="hidden" name="invoice_id" value="<?php echo $rawId; ?>">
            <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
                <span class="badge <?php echo paymentBadgeClass($inv['payment_status'] ?? 'unknown'); ?>">
                    <?php echo paymentLabel($inv['payment_status'] ?? 'unknown'); ?>
                </span>
                <div style="display:flex;gap:4px;align-items:center;">
                    <select name="payment_status"
                            class="inline-select"
                            onchange="markPaymentDirty(<?php echo $rawId; ?>)">
                        <?php foreach (['unpaid', 'partial', 'paid', 'unknown'] as $paymentStatus): ?>
                            <option value="<?php echo $paymentStatus; ?>"
                                <?php echo ($inv['payment_status'] ?? 'unknown') === $paymentStatus ? 'selected' : ''; ?>>
                                <?php echo paymentLabel($paymentStatus); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"
                            class="action-btn action-btn-save"
                            id="btn-payment-save-<?php echo $rawId; ?>"
                            style="display:none;"
                            title="Zapisz płatność">
                        Zapisz
                    </button>
                </div>
            </div>
        </form>
    <?php elseif (!empty($inv['payment_status'])): ?>
        <span class="badge <?php echo paymentBadgeClass($inv['payment_status']); ?>">
            <?php echo paymentLabel($inv['payment_status']); ?>
        </span>
    <?php else: ?>
        <span style="color:var(--text-muted);font-size:12px;">—</span>
    <?php endif; ?>
</td>

<td class="note-cell">
    <?php if ($src === 'fakturownia' && $invType === 'cost'): ?>
        <div id="note-display-<?php echo $rawId; ?>">
            <span class="note-text"
                  onclick="expandNote(<?php echo $rawId; ?>)"
                  title="Kliknij, aby edytować">
                <?php echo $inv['owner_note'] ? e($inv['owner_note']) : '+ dodaj notatkę'; ?>
            </span>
        </div>
        <div id="note-edit-<?php echo $rawId; ?>" class="note-edit-area">
            <form method="POST" action="" class="inline-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action"     value="save_note">
                <input type="hidden" name="invoice_id" value="<?php echo $rawId; ?>">
                <textarea name="owner_note" class="note-textarea" rows="2" maxlength="500"><?php echo e($inv['owner_note'] ?? ''); ?></textarea>
                <div style="display:flex;gap:4px;margin-top:2px;">
                    <button type="submit" class="action-btn action-btn-note">Zapisz</button>
                    <button type="button" class="action-btn" style="color:var(--text-muted);border-color:var(--border-light);"
                            onclick="collapseNote(<?php echo $rawId; ?>)">Anuluj</button>
                </div>
            </form>
        </div>
    <?php elseif (!empty($inv['owner_note'])): ?>
        <span class="note-text" title="<?php echo e($inv['owner_note']); ?>"><?php echo e(mb_substr($inv['owner_note'], 0, 40)); ?></span>
    <?php else: ?>
        <span style="color:var(--text-muted);font-size:12px;">—</span>
    <?php endif; ?>
</td>

<td>
    <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start;">
        <?php if ($src === 'fakturownia' && $invType === 'cost'): ?>
            <form method="POST" action="" class="inline-form" id="form-status-<?php echo $rawId; ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action"     value="update_status">
                <input type="hidden" name="invoice_id" value="<?php echo $rawId; ?>">
                <div style="display:flex;gap:4px;align-items:center;">
                    <select name="workflow_status" class="inline-select"
                            id="sel-<?php echo $rawId; ?>"
                            onchange="markDirty(<?php echo $rawId; ?>)">
                        <?php
                        $statuses = ['new', 'assigned', 'accepted', 'rejected', 'archived'];
                        foreach ($statuses as $s):
                        ?>
                            <option value="<?php echo $s; ?>"
                                <?php echo $inv['workflow_status'] === $s ? 'selected' : ''; ?>>
                                <?php echo workflowLabel($s); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"
                            class="action-btn action-btn-save"
                            id="btn-save-<?php echo $rawId; ?>"
                            style="display:none;"
                            title="Zapisz status">
                        Zapisz
                    </button>
                </div>
            </form>
            <div class="action-buttons">
                <?php
                $detailsUrl = url('finanse.fakturownia-cost-inbox.view', ['id' => $rawId, 'return_to' => $currentInboxUrl]);
                ?>
                <select class="action-select" onchange="handleInboxActionSelect(this)">
                    <option value="">Wybierz akcję...</option>
                    <option value="<?php echo e($detailsUrl); ?>">Szczegóły / Alokacja</option>
                    <option value="<?php echo e($detailsUrl); ?>&target=company#allocation-form"><?php echo $isCostCorrection ? 'Rozlicz korektę' : 'Alokuj'; ?></option>
                    <?php if (!empty($inv['fakturownia_id']) && $fakturowniaSubdomain !== ''): ?>
                        <option value="ext:https://<?php echo e($fakturowniaSubdomain); ?>.fakturownia.pl/invoices/<?php echo (int)$inv['fakturownia_id']; ?>">Otwórz w Fakturowni ↗</option>
                    <?php endif; ?>
                </select>
            </div>
        <?php elseif ($src === 'legacy'): ?>
            <span class="badge <?php echo workflowBadgeClass($inv['workflow_status']); ?>">
                <?php echo workflowLabel($inv['workflow_status']); ?>
            </span>
            <div class="action-buttons">
                <a href="<?php echo url('finanse.faktury.allocate', ['id' => $rawId]); ?>" class="action-btn action-btn-view">Przypisz</a>
                <a href="<?php echo url('finanse.faktury.edit', ['id' => $rawId]); ?>" class="action-btn action-btn-note">Edytuj</a>
            </div>
        <?php elseif ($src === 'documents'): ?>
            <span class="badge <?php echo workflowBadgeClass($inv['workflow_status']); ?>">
                <?php echo workflowLabel($inv['workflow_status']); ?>
            </span>
            <div class="action-buttons">
                <?php
                $documentViewUrl = url('finanse.fakturownia-cost-inbox.document-view', ['id' => $rawId]);
                $documentAllocateUrl = $documentViewUrl . '#allocation-form';
                $documentDetailsUrl = url('dokumenty.edit', ['id' => $rawId]);
                ?>
                <select class="action-select" onchange="handleInboxActionSelect(this)">
                    <option value="">Wybierz akcję...</option>
                    <option value="<?php echo e($documentAllocateUrl); ?>">Zalokuj</option>
                    <option value="<?php echo e($documentViewUrl); ?>">Szczegóły / Alokacja</option>
                    <option value="<?php echo e($documentDetailsUrl); ?>">Stary widok dokumentu</option>
                </select>
            </div>
        <?php endif; ?>
    </div>
</td>
