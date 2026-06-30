<?php

function spxCompanyCostComboEscape($value): string
{
    if (function_exists('e')) {
        return e((string)$value);
    }

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function spxCompanyCostComboRenderAssets(): string
{
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    return <<<'HTML'
<style>
.form-hidden-control {
    display: none !important;
}
.spx-company-cost-combo-wrap {
    width: 100%;
}
.spx-company-cost-combo {
    position: relative;
    width: 100%;
}
.spx-company-cost-combo.is-disabled {
    opacity: 0.7;
}
.spx-company-cost-combo-trigger {
    width: 100%;
    min-height: 40px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #1f2937;
    border-radius: 8px;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    font-weight: 400;
    font-size: 14px;
    text-align: left;
}
.spx-company-cost-combo-trigger > span:first-child {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.spx-company-cost-combo-trigger:hover {
    border-color: #2563eb;
    background: #fff;
}
.spx-company-cost-combo-trigger:disabled {
    cursor: not-allowed;
}
.spx-company-cost-combo-trigger:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
}
.spx-company-cost-combo-panel {
    display: none;
    position: absolute;
    z-index: 40;
    top: calc(100% + 6px);
    left: 0;
    width: min(560px, calc(100vw - 40px));
    min-height: 260px;
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    box-shadow: 0 18px 40px rgba(15,23,42,0.18);
    overflow: hidden;
}
.spx-company-cost-combo.open .spx-company-cost-combo-panel {
    display: grid;
    grid-template-columns: minmax(220px, 0.9fr) minmax(280px, 1.1fr);
}
.spx-company-cost-combo-list,
.spx-company-cost-combo-subcats {
    max-height: 320px;
    overflow-y: auto;
    padding: 8px;
}
.spx-company-cost-combo-list {
    border-right: 1px solid #e5e7eb;
    background: #f8fafc;
}
.spx-company-cost-combo-item,
.spx-company-cost-combo-subitem {
    width: 100%;
    border: 0;
    background: transparent;
    color: #1f2937;
    border-radius: 6px;
    padding: 9px 10px;
    cursor: pointer;
    text-align: left;
    font: inherit;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.spx-company-cost-combo-item:hover,
.spx-company-cost-combo-item.active,
.spx-company-cost-combo-subitem:hover {
    background: #eef2ff;
    color: #3730a3;
}
.spx-company-cost-combo-subitem.empty {
    color: #6b7280;
}
.spx-company-cost-combo-arrow {
    color: #9ca3af;
    font-size: 15px;
}
.spx-company-cost-combo-mobile-note {
    display: none;
    font-size: 12px;
    color: #6b7280;
    padding: 8px 10px 0;
}
@media (max-width: 768px) {
    .spx-company-cost-combo-panel {
        position: static;
        width: 100%;
        margin-top: 6px;
    }
    .spx-company-cost-combo.open .spx-company-cost-combo-panel {
        grid-template-columns: 1fr;
    }
    .spx-company-cost-combo-list {
        border-right: 0;
        border-bottom: 1px solid #e5e7eb;
    }
    .spx-company-cost-combo-mobile-note {
        display: block;
    }
}
</style>
<script>
(function() {
    if (window.spxCompanyCostCombo) {
        return;
    }

    function parsePayload(root) {
        if (root._spxCompanyCostComboPayload) {
            return root._spxCompanyCostComboPayload;
        }
        var payloadNode = root.querySelector('.js-company-cost-combo-payload');
        var payload = {};
        if (payloadNode) {
            try {
                payload = JSON.parse(payloadNode.textContent || '{}');
            } catch (e) {
                payload = {};
            }
        }
        root._spxCompanyCostComboPayload = payload;
        return payload;
    }

    function getWrapper(root) {
        return root.closest('.spx-company-cost-combo-wrap');
    }

    function getCategorySelect(root) {
        var wrapper = getWrapper(root);
        return wrapper ? wrapper.querySelector('.js-company-cost-combo-category') : null;
    }

    function getSubcategorySelect(root) {
        var wrapper = getWrapper(root);
        return wrapper ? wrapper.querySelector('.js-company-cost-combo-subcategory') : null;
    }

    function getActiveCategoryValue(root) {
        var categorySelect = getCategorySelect(root);
        return categorySelect ? (categorySelect.value || '') : '';
    }

    function getCategoryText(categorySelect) {
        if (!categorySelect) {
            return '';
        }
        var option = categorySelect.options[categorySelect.selectedIndex];
        return option ? option.textContent : '';
    }

    function syncSubcategorySelect(root, selectedValue) {
        var payload = parsePayload(root);
        var categorySelect = getCategorySelect(root);
        var subcategorySelect = getSubcategorySelect(root);
        if (!categorySelect || !subcategorySelect) {
            return;
        }

        var current = selectedValue !== undefined ? selectedValue : (subcategorySelect.value || '');
        var emptyLabel = root.getAttribute('data-empty-subcategory-label') || 'Brak';
        var options = (payload.subcategoriesByCategory && payload.subcategoriesByCategory[categorySelect.value]) || [];

        subcategorySelect.innerHTML = '';

        var emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = emptyLabel;
        subcategorySelect.appendChild(emptyOption);

        options.forEach(function(name) {
            var option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            if (current === name) {
                option.selected = true;
            }
            subcategorySelect.appendChild(option);
        });

        if (current && options.indexOf(current) === -1) {
            var customOption = document.createElement('option');
            customOption.value = current;
            customOption.textContent = current;
            customOption.selected = true;
            subcategorySelect.appendChild(customOption);
        }
    }

    function updateLabel(root) {
        var categorySelect = getCategorySelect(root);
        var subcategorySelect = getSubcategorySelect(root);
        var labelNode = root.querySelector('.js-company-cost-combo-label');
        if (!categorySelect || !subcategorySelect || !labelNode) {
            return;
        }

        var placeholder = root.getAttribute('data-placeholder-label') || 'Wybierz kategorię i podkategorię';
        if (!categorySelect.value) {
            labelNode.textContent = placeholder;
            return;
        }

        var categoryText = getCategoryText(categorySelect) || categorySelect.value;
        labelNode.textContent = subcategorySelect.value ? (categoryText + ' / ' + subcategorySelect.value) : categoryText;
    }

    function submitIfNeeded(root) {
        if (root.getAttribute('data-submit-on-change') !== '1') {
            return;
        }

        var form = root.closest('form');
        if (form) {
            form.submit();
        }
    }

    function commitSelection(root, categoryValue, subcategoryValue, shouldClose, shouldSubmit) {
        var categorySelect = getCategorySelect(root);
        var subcategorySelect = getSubcategorySelect(root);
        if (!categorySelect || !subcategorySelect) {
            return;
        }

        categorySelect.value = categoryValue || '';
        syncSubcategorySelect(root, subcategoryValue || '');
        subcategorySelect.value = subcategoryValue || '';
        renderSubcategories(root, categorySelect.value);
        updateLabel(root);

        if (shouldClose !== false) {
            root.classList.remove('open');
        }
        if (shouldSubmit !== false) {
            submitIfNeeded(root);
        }
    }

    function renderSubcategories(root, categoryValue) {
        var payload = parsePayload(root);
        var box = root.querySelector('.js-company-cost-combo-subcats');
        if (!box) {
            return;
        }

        root.querySelectorAll('.spx-company-cost-combo-item').forEach(function(item) {
            item.classList.toggle('active', item.getAttribute('data-category-key') === categoryValue);
        });

        box.innerHTML = '';

        var options = (payload.subcategoriesByCategory && payload.subcategoriesByCategory[categoryValue]) || [];
        var emptyLabel = root.getAttribute('data-empty-subcategory-label') || 'Brak';
        var emptyCategoryLabel = root.getAttribute('data-empty-category-label') || 'Wyczyść wybór';

        if (!categoryValue) {
            var emptyCategoryButton = document.createElement('button');
            emptyCategoryButton.type = 'button';
            emptyCategoryButton.className = 'spx-company-cost-combo-subitem empty';
            emptyCategoryButton.textContent = emptyCategoryLabel;
            emptyCategoryButton.addEventListener('click', function() {
                commitSelection(root, '', '', true, true);
            });
            box.appendChild(emptyCategoryButton);
            return;
        }

        var categoryOnlyButton = document.createElement('button');
        categoryOnlyButton.type = 'button';
        categoryOnlyButton.className = 'spx-company-cost-combo-subitem empty';
        categoryOnlyButton.textContent = options.length ? emptyLabel : 'Wybierz samą kategorię';
        categoryOnlyButton.addEventListener('click', function() {
            commitSelection(root, categoryValue, '', true, true);
        });
        box.appendChild(categoryOnlyButton);

        options.forEach(function(name) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'spx-company-cost-combo-subitem';
            btn.textContent = name;
            btn.addEventListener('click', function() {
                commitSelection(root, categoryValue, name, true, true);
            });
            box.appendChild(btn);
        });
    }

    function init(root) {
        if (!root) {
            return;
        }

        parsePayload(root);

        var categorySelect = getCategorySelect(root);
        var subcategorySelect = getSubcategorySelect(root);
        var trigger = root.querySelector('.js-company-cost-combo-trigger');
        if (!categorySelect || !subcategorySelect || !trigger) {
            return;
        }

        if (!root.dataset.comboBound) {
            trigger.addEventListener('click', function() {
                if (trigger.disabled) {
                    return;
                }
                root.classList.toggle('open');
                renderSubcategories(root, getActiveCategoryValue(root));
                updateLabel(root);
            });

            root.querySelectorAll('.spx-company-cost-combo-item').forEach(function(item) {
                item.addEventListener('mouseenter', function() {
                    renderSubcategories(root, item.getAttribute('data-category-key') || '');
                });
                item.addEventListener('focus', function() {
                    renderSubcategories(root, item.getAttribute('data-category-key') || '');
                });
                item.addEventListener('click', function() {
                    var categoryValue = item.getAttribute('data-category-key') || '';
                    if (categoryValue === '') {
                        commitSelection(root, '', '', true, true);
                        return;
                    }
                    renderSubcategories(root, categoryValue);
                });
            });

            document.addEventListener('click', function(event) {
                if (!root.contains(event.target)) {
                    root.classList.remove('open');
                }
            });

            root.dataset.comboBound = '1';
        }

        syncSubcategorySelect(root, subcategorySelect.value || '');
        renderSubcategories(root, getActiveCategoryValue(root));
        updateLabel(root);
    }

    window.spxCompanyCostCombo = {
        init: init,
        initAll: function(scope) {
            var root = scope || document;
            root.querySelectorAll('.js-company-cost-combo').forEach(init);
        },
        commitSelection: commitSelection,
        refresh: function(scope) {
            var root = scope && scope.classList && scope.classList.contains('js-company-cost-combo') ? scope : null;
            if (root) {
                init(root);
                return;
            }
            this.initAll(scope || document);
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        window.spxCompanyCostCombo.initAll(document);
    });
})();
</script>
HTML;
}

function spxCompanyCostComboRender(array $config): string
{
    $idPrefix = (string)($config['id_prefix'] ?? 'company-cost');
    $categoryName = (string)($config['category_name'] ?? 'category');
    $subcategoryName = (string)($config['subcategory_name'] ?? 'subcategory');
    $categorySelectId = (string)($config['category_select_id'] ?? ($idPrefix . '-category'));
    $subcategorySelectId = (string)($config['subcategory_select_id'] ?? ($idPrefix . '-subcategory'));
    $selectedCategory = (string)($config['selected_category'] ?? '');
    $selectedSubcategory = (string)($config['selected_subcategory'] ?? '');
    $categoryLabels = (array)($config['category_labels'] ?? []);
    $subcategoriesByCategory = (array)($config['subcategories_by_category'] ?? []);
    $allSubcategoryHints = (array)($config['all_subcategory_hints'] ?? []);
    $placeholderLabel = (string)($config['placeholder_label'] ?? 'Wybierz kategorię i podkategorię');
    $emptyCategoryLabel = (string)($config['empty_category_label'] ?? 'Wyczyść wybór');
    $emptySubcategoryLabel = (string)($config['empty_subcategory_label'] ?? 'Brak');
    $helpText = (string)($config['help_text'] ?? '');
    $mobileNote = (string)($config['mobile_note'] ?? 'Kliknij kategorię, a niżej wybierz podkategorię.');
    $allowEmptyCategory = !empty($config['allow_empty_category']);
    $submitOnChange = !empty($config['submit_on_change']);
    $disabled = !empty($config['disabled']);
    $wrapperClass = trim((string)($config['wrapper_class'] ?? ''));

    $knownCategoryKeys = array_keys($categoryLabels);
    $isCustomCategory = $selectedCategory !== '' && !isset($categoryLabels[$selectedCategory]);
    $isCustomSubcategory = $selectedSubcategory !== '' && !in_array($selectedSubcategory, $allSubcategoryHints, true);
    $payload = [
        'subcategoriesByCategory' => $subcategoriesByCategory,
    ];

    ob_start();
    ?>
    <div class="spx-company-cost-combo-wrap<?php echo $wrapperClass !== '' ? ' ' . spxCompanyCostComboEscape($wrapperClass) : ''; ?>">
        <select
            id="<?php echo spxCompanyCostComboEscape($categorySelectId); ?>"
            name="<?php echo spxCompanyCostComboEscape($categoryName); ?>"
            class="form-hidden-control js-company-cost-combo-category"
            <?php echo $disabled ? 'disabled' : ''; ?>
        >
            <?php if ($allowEmptyCategory): ?>
                <option value="" <?php echo $selectedCategory === '' ? 'selected' : ''; ?>><?php echo spxCompanyCostComboEscape($emptyCategoryLabel); ?></option>
            <?php endif; ?>
            <?php foreach ($categoryLabels as $key => $label): ?>
                <option value="<?php echo spxCompanyCostComboEscape($key); ?>" <?php echo ($selectedCategory === (string)$key && !$isCustomCategory) ? 'selected' : ''; ?>>
                    <?php echo spxCompanyCostComboEscape($label); ?>
                </option>
            <?php endforeach; ?>
            <?php if ($isCustomCategory): ?>
                <option value="<?php echo spxCompanyCostComboEscape($selectedCategory); ?>" selected><?php echo spxCompanyCostComboEscape($selectedCategory); ?></option>
            <?php endif; ?>
        </select>
        <select
            id="<?php echo spxCompanyCostComboEscape($subcategorySelectId); ?>"
            name="<?php echo spxCompanyCostComboEscape($subcategoryName); ?>"
            class="form-hidden-control js-company-cost-combo-subcategory"
            <?php echo $disabled ? 'disabled' : ''; ?>
        >
            <option value=""><?php echo spxCompanyCostComboEscape($emptySubcategoryLabel); ?></option>
            <?php foreach ($allSubcategoryHints as $hint): ?>
                <option value="<?php echo spxCompanyCostComboEscape($hint); ?>" <?php echo ($selectedSubcategory === (string)$hint && !$isCustomSubcategory) ? 'selected' : ''; ?>>
                    <?php echo spxCompanyCostComboEscape($hint); ?>
                </option>
            <?php endforeach; ?>
            <?php if ($isCustomSubcategory): ?>
                <option value="<?php echo spxCompanyCostComboEscape($selectedSubcategory); ?>" selected><?php echo spxCompanyCostComboEscape($selectedSubcategory); ?></option>
            <?php endif; ?>
        </select>

        <div
            class="spx-company-cost-combo js-company-cost-combo<?php echo $disabled ? ' is-disabled' : ''; ?>"
            data-placeholder-label="<?php echo spxCompanyCostComboEscape($placeholderLabel); ?>"
            data-empty-category-label="<?php echo spxCompanyCostComboEscape($emptyCategoryLabel); ?>"
            data-empty-subcategory-label="<?php echo spxCompanyCostComboEscape($emptySubcategoryLabel); ?>"
            data-submit-on-change="<?php echo $submitOnChange ? '1' : '0'; ?>"
        >
            <script type="application/json" class="js-company-cost-combo-payload"><?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
            <button type="button" class="spx-company-cost-combo-trigger js-company-cost-combo-trigger" <?php echo $disabled ? 'disabled' : ''; ?>>
                <span class="js-company-cost-combo-label"><?php echo spxCompanyCostComboEscape($placeholderLabel); ?></span>
                <span>▾</span>
            </button>
            <div class="spx-company-cost-combo-panel">
                <div class="spx-company-cost-combo-mobile-note"><?php echo spxCompanyCostComboEscape($mobileNote); ?></div>
                <div class="spx-company-cost-combo-list">
                    <?php if ($allowEmptyCategory): ?>
                        <button type="button" class="spx-company-cost-combo-item" data-category-key="">
                            <span><?php echo spxCompanyCostComboEscape($emptyCategoryLabel); ?></span>
                            <span class="spx-company-cost-combo-arrow">○</span>
                        </button>
                    <?php endif; ?>
                    <?php foreach ($categoryLabels as $key => $label): ?>
                        <?php $subCount = count($subcategoriesByCategory[$key] ?? []); ?>
                        <button type="button" class="spx-company-cost-combo-item" data-category-key="<?php echo spxCompanyCostComboEscape($key); ?>">
                            <span><?php echo spxCompanyCostComboEscape($label); ?></span>
                            <span class="spx-company-cost-combo-arrow"><?php echo $subCount > 0 ? '›' : '✓'; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="spx-company-cost-combo-subcats js-company-cost-combo-subcats"></div>
            </div>
        </div>

        <?php if ($helpText !== ''): ?>
            <div class="help-text"><?php echo spxCompanyCostComboEscape($helpText); ?></div>
        <?php endif; ?>
    </div>
    <?php

    return (string)ob_get_clean();
}
