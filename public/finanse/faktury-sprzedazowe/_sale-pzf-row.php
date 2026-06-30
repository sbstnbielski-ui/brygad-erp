<?php
/**
 * Partial: single PZF row rendered in the same table as faktury sprzedażowe.
 * Expected variables: $inv, $isAdminUser, $source, $__dayGroupMode (optional)
 */
$entryId = (int)$inv['id'];
$currentSource = (string)($source ?? 'noninvoice');
$returnSource = in_array($currentSource, ['invoice', 'noninvoice', 'all'], true)
    ? $currentSource
    : 'noninvoice';
$editUrl = url('finanse.sprzedaz-pozafakturowa.edit', ['id' => $entryId, 'source' => $returnSource]);
$deleteUrl = url('finanse.sprzedaz-pozafakturowa.delete', ['id' => $entryId, 'source' => $returnSource]);
$showSourceMeta = ($source ?? 'invoice') === 'all';
$isPaid = ($inv['status'] ?? 'unpaid') === 'paid';
$daysUntilDue = (int)($inv['days_until_due'] ?? 0);
$isOverdue = !$isPaid && !empty($inv['due_date']) && $daysUntilDue < 0;
?>
<td>
    <a href="<?php echo $editUrl; ?>" style="color:var(--primary);text-decoration:none;font-weight:700;">
        <?php echo e($inv['invoice_number'] ?? '-'); ?>
    </a>
    <?php if ($showSourceMeta): ?>
        <div class="row-source-meta">PZF</div>
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
<td><?php echo !empty($inv['issue_date']) ? formatDate($inv['issue_date']) : '-'; ?></td>
<?php endif; ?>

<td>
    <?php echo !empty($inv['due_date']) ? formatDate($inv['due_date']) : '-'; ?>
    <?php if ($isOverdue): ?>
        <br><span class="overdue-warning">Zaległość: <?php echo abs($daysUntilDue); ?> dni</span>
    <?php elseif (!$isPaid && !empty($inv['due_date']) && $daysUntilDue > 0 && $daysUntilDue <= 7): ?>
        <br><span style="color:#f59e0b;font-size:12px;">Za <?php echo $daysUntilDue; ?> dni</span>
    <?php endif; ?>
</td>

<td><?php echo formatMoney($inv['amount_net'] ?? 0); ?></td>
<td><strong><?php echo formatMoney($inv['amount_gross'] ?? 0); ?></strong></td>

<td>
    <button type="button" class="status-badge-btn <?php echo $isPaid ? 'badge-paid' : 'badge-issued'; ?>"
            onclick="openStatusPortal(event, this, 'pzf-<?php echo $entryId; ?>')">
        <?php echo $isPaid ? 'Opłacona' : 'Nieopłacona'; ?>
    </button>

    <template class="status-panel-tpl" data-inv-id="pzf-<?php echo $entryId; ?>">
        <div class="status-portal">
            <div class="status-panel-header">Status przychodu</div>
            <div class="status-panel-options">
                <?php foreach (['unpaid' => 'Nieopłacona', 'paid' => 'Opłacona'] as $sVal => $sLabel):
                    $isActive = ($isPaid ? 'paid' : 'unpaid') === $sVal; ?>
                <form method="POST" action="" style="display:inline;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="change_pzf_status">
                    <input type="hidden" name="pzf_entry_id" value="<?php echo $entryId; ?>">
                    <input type="hidden" name="new_status" value="<?php echo $sVal; ?>">
                    <input type="hidden" name="source" value="<?php echo e($currentSource); ?>">
                    <?php if ($sVal === 'paid'): ?>
                    <input type="hidden" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                    <?php endif; ?>
                    <button type="<?php echo $isActive ? 'button' : 'submit'; ?>"
                            class="sp-status-opt <?php echo $isActive ? 'sp-status-active' : ''; ?>"
                            <?php echo !$isActive ? 'onclick="return confirm(\'Zmienić status na: ' . $sLabel . '?\')"' : ''; ?>>
                        <?php echo $sLabel; ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
    </template>
</td>

<td>
    <?php if (!empty($inv['assigned_projects'])): ?>
        <div style="font-size:11px;color:var(--text-muted);line-height:1.35;">
            <?php echo e($inv['assigned_projects']); ?>
        </div>
    <?php else: ?>
        <span style="color:var(--text-muted);font-size:12px;">—</span>
    <?php endif; ?>
</td>

<td>
    <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
        <div class="action-buttons" style="flex-wrap:nowrap;">
            <a href="<?php echo $editUrl; ?>" class="action-btn action-btn-edit">Zobacz</a>
            <button type="button" class="action-btn action-btn-more" onclick="openRowDropdown(event, this)" data-inv-id="pzf-<?php echo $entryId; ?>">&#9660;</button>

            <template class="row-dd-tpl">
                <div class="row-dd-portal">
                    <a href="<?php echo $editUrl; ?>">Zobacz / Edytuj</a>
                    <div class="row-dd-sep"></div>
                    <button type="button" onclick='closeRowDropdown();openAssignProjectModalPzf(<?php echo $entryId; ?>, <?php echo json_encode((string)($inv["invoice_number"] ?? "PZF"), JSON_UNESCAPED_UNICODE); ?>)'>Alokuj do projektu</button>
                    <?php if ($isAdminUser): ?>
                    <div class="row-dd-sep"></div>
                    <a href="<?php echo $deleteUrl; ?>" class="row-dd-danger" onclick="return confirm('Usunąć wpis <?php echo e(addslashes($inv['invoice_number'] ?? '')); ?>?');">Usuń wpis</a>
                    <?php endif; ?>
                </div>
            </template>
        </div>
    </div>
</td>
