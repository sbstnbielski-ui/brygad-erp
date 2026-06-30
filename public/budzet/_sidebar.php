<?php
/**
 * BRYGAD ERP - Budżet Domowy - Lewy panel szybkich akcji
 */

if (!defined('HB_HOUSEHOLD_ID')) {
    die('Direct access not allowed');
}

$canEdit = HB_CAN_EDIT;
$currentPeriod = date('Y-m');
?>

<aside class="sidebar-actions">
    <div class="sidebar-actions-header">
        <h3>Szybkie Akcje</h3>
    </div>
    
    <?php if ($canEdit): ?>
    <div class="sidebar-actions-body">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Dodaj</div>
            <a href="<?php echo url('budzet.rachunki.dodaj'); ?>" data-keywords="dodaj rachunek bill">
                + Dodaj rachunek
            </a>
            <a href="<?php echo url('budzet.transakcje.create'); ?>" data-keywords="dodaj transakcja transakcję transaction">
                + Dodaj transakcję
            </a>
            <a href="<?php echo url('budzet.konta'); ?>?action=add" data-keywords="dodaj konto account">
                + Dodaj konto
            </a>
            <a href="<?php echo url('budzet.kategorie'); ?>?action=add" data-keywords="dodaj kategoria kategorię category">
                + Dodaj kategorię
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Zarządzanie</div>
            <a href="<?php echo url('budzet.rachunki.definicje'); ?>" data-keywords="zarządzaj rachunki definicje">
                Zarządzaj rachunkami
            </a>
        </div>
        
        <div class="sidebar-section">
            <div class="sidebar-section-title">Konfiguracja</div>
            <a href="<?php echo url('budzet.konta'); ?>" data-keywords="konta accounts">
                Konta
            </a>
            <a href="<?php echo url('budzet.kategorie'); ?>" data-keywords="kategorie categories">
                Kategorie
            </a>
            <a href="<?php echo url('budzet.domownicy'); ?>" data-keywords="domownicy members household użytkownicy">
                Domownicy
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="sidebar-actions-body">
        <p style="padding: 16px; color: #666; font-size: 13px; text-align: center;">
            Użyj menu u góry strony do nawigacji
        </p>
    </div>
    <?php endif; ?>
</aside>

