(function () {
    'use strict';

    var MONTH_NAMES = [
        'Styczen', 'Luty', 'Marzec', 'Kwiecien', 'Maj', 'Czerwiec',
        'Lipiec', 'Sierpien', 'Wrzesien', 'Pazdziernik', 'Listopad', 'Grudzien'
    ];

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function toMonthValue(dateObj) {
        return dateObj.getFullYear() + '-' + pad2(dateObj.getMonth() + 1);
    }

    function parseIsoDate(dateStr) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
            return null;
        }
        var parts = dateStr.split('-');
        var year = Number(parts[0]);
        var monthIndex = Number(parts[1]) - 1;
        var day = Number(parts[2]);
        var dateObj = new Date(year, monthIndex, day);
        if (
            dateObj.getFullYear() !== year ||
            dateObj.getMonth() !== monthIndex ||
            dateObj.getDate() !== day
        ) {
            return null;
        }
        return dateObj;
    }

    function getMonthBounds(monthValue) {
        if (!/^\d{4}-\d{2}$/.test(monthValue)) {
            return null;
        }
        var parts = monthValue.split('-');
        var year = Number(parts[0]);
        var monthIndex = Number(parts[1]) - 1;
        var fromDate = new Date(year, monthIndex, 1);
        var toDate = new Date(year, monthIndex + 1, 0);
        return {
            from: fromDate.getFullYear() + '-' + pad2(fromDate.getMonth() + 1) + '-' + pad2(fromDate.getDate()),
            to: toDate.getFullYear() + '-' + pad2(toDate.getMonth() + 1) + '-' + pad2(toDate.getDate())
        };
    }

    function isFullMonthRange(fromValue, toValue) {
        var fromDate = parseIsoDate(fromValue);
        var toDate = parseIsoDate(toValue);
        if (!fromDate || !toDate) {
            return false;
        }
        if (fromDate.getFullYear() !== toDate.getFullYear() || fromDate.getMonth() !== toDate.getMonth()) {
            return false;
        }
        if (fromDate.getDate() !== 1) {
            return false;
        }
        var monthEnd = new Date(fromDate.getFullYear(), fromDate.getMonth() + 1, 0).getDate();
        return toDate.getDate() === monthEnd;
    }

    function buildMonthSelect(defaultValue) {
        var currentYear = new Date().getFullYear();
        var select = document.createElement('select');
        select.className = 'sprx-month-picker';
        select.setAttribute('data-month-range-picker', '1');

        var customOption = document.createElement('option');
        customOption.value = '';
        customOption.textContent = 'Zakres niestandardowy';
        select.appendChild(customOption);

        for (var month = 0; month < 12; month += 1) {
            var monthDate = new Date(currentYear, month, 1);
            var monthValue = toMonthValue(monthDate);
            var option = document.createElement('option');
            option.value = monthValue;
            option.textContent = MONTH_NAMES[month] + ' ' + currentYear;
            select.appendChild(option);
        }

        select.value = defaultValue;
        return select;
    }

    function detectInitialMonth(fromInput, toInput) {
        var currentYear = new Date().getFullYear();

        if (isFullMonthRange(fromInput.value, toInput.value)) {
            var monthValue = fromInput.value.slice(0, 7);
            if (monthValue.slice(0, 4) === String(currentYear)) {
                return monthValue;
            }
            return '';
        }
        return '';
    }

    function maybeAutoSubmit(formElement, fromInput, toInput) {
        var onChangeFrom = fromInput.getAttribute('onchange') || '';
        var onChangeTo = toInput.getAttribute('onchange') || '';
        var isFilterForm =
            formElement.classList.contains('filter-bar') ||
            formElement.id === 'filterForm' ||
            formElement.getAttribute('role') === 'search';
        var shouldAutoSubmit =
            formElement.getAttribute('data-month-picker-auto-submit') === '1' ||
            isFilterForm ||
            onChangeFrom.indexOf('submit') !== -1 ||
            onChangeTo.indexOf('submit') !== -1;

        if (!shouldAutoSubmit) {
            return;
        }
        if (typeof formElement.requestSubmit === 'function') {
            formElement.requestSubmit();
        } else {
            formElement.submit();
        }
    }

    function enhanceForm(formElement) {
        if (!formElement || formElement.getAttribute('data-month-picker-enhanced') === '1') {
            return;
        }

        var fromInput = formElement.querySelector('input[type="date"][name="date_from"]');
        var toInput = formElement.querySelector('input[type="date"][name="date_to"]');

        if (!fromInput || !toInput || fromInput.disabled || toInput.disabled) {
            return;
        }
        if (formElement.querySelector('[data-month-range-picker="1"]')) {
            return;
        }
        if (
            formElement.querySelector('input[type="month"]') ||
            formElement.querySelector('select[name="month"]') ||
            formElement.querySelector('input[name="period"]') ||
            formElement.querySelector('select[name="period"]')
        ) {
            return;
        }

        var initialMonth = detectInitialMonth(fromInput, toInput);
        var monthSelect = buildMonthSelect(initialMonth);
        var monthLabel = document.createElement('label');
        monthLabel.textContent = 'Miesiac';

        var monthGroup = document.createElement('div');
        monthGroup.className = 'filter-group sprx-month-group';
        monthGroup.appendChild(monthLabel);
        monthGroup.appendChild(monthSelect);

        var insertTarget = fromInput.closest('.filter-group') || fromInput.parentElement;
        if (insertTarget && insertTarget.parentElement) {
            insertTarget.parentElement.insertBefore(monthGroup, insertTarget);
        } else {
            formElement.insertBefore(monthGroup, formElement.firstChild);
        }

        monthSelect.addEventListener('change', function () {
            if (!monthSelect.value) {
                return;
            }
            var bounds = getMonthBounds(monthSelect.value);
            if (!bounds) {
                return;
            }
            fromInput.value = bounds.from;
            toInput.value = bounds.to;
            maybeAutoSubmit(formElement, fromInput, toInput);
        });

        function syncMonthToCustomRange() {
            if (isFullMonthRange(fromInput.value, toInput.value)) {
                monthSelect.value = fromInput.value.slice(0, 7);
            } else {
                monthSelect.value = '';
            }
        }

        fromInput.addEventListener('change', syncMonthToCustomRange);
        toInput.addEventListener('change', syncMonthToCustomRange);

        formElement.setAttribute('data-month-picker-enhanced', '1');
    }

    function initMonthPickers() {
        var forms = document.querySelectorAll('form');
        forms.forEach(enhanceForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMonthPickers);
    } else {
        initMonthPickers();
    }
})();
