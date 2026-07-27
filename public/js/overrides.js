/*
 * Tambahkan JavaScript global baru di file ini.
 * File dibaca langsung oleh browser sehingga tidak memerlukan npm run build.
 */

window.moneyValue = (inputOrValue) => {
    const value = inputOrValue instanceof HTMLInputElement ? inputOrValue.value : inputOrValue;
    const digits = String(value ?? '').replace(/\D/g, '');

    return digits === '' ? 0 : Number(digits);
};

window.formatMoneyInput = (input) => {
    if (!(input instanceof HTMLInputElement)) return;

    const cursor = input.selectionStart ?? input.value.length;
    const digitsBeforeCursor = input.value.slice(0, cursor).replace(/\D/g, '').length;
    const digits = input.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const formatted = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    input.value = formatted;
    input.setCustomValidity('');

    if (document.activeElement !== input) return;

    let nextCursor = 0;
    let digitCount = 0;

    while (nextCursor < formatted.length && digitCount < digitsBeforeCursor) {
        if (/\d/.test(formatted[nextCursor])) digitCount++;
        nextCursor++;
    }

    input.setSelectionRange(nextCursor, nextCursor);
};

window.setMoneyInputValue = (input, value) => {
    if (!(input instanceof HTMLInputElement)) return;

    input.value = value ?? '';
    window.formatMoneyInput(input);
};

window.initializeMoneyInputs = (root = document) => {
    root.querySelectorAll('[data-money-input]').forEach((input) => {
        if (input.dataset.moneyBound === 'true') return;

        input.dataset.moneyBound = 'true';
        input.addEventListener('input', () => window.formatMoneyInput(input));
        window.formatMoneyInput(input);
    });
};

document.addEventListener('DOMContentLoaded', () => window.initializeMoneyInputs());

document.addEventListener('submit', (event) => {
    const inputs = [...event.target.querySelectorAll('[data-money-input]')];
    const invalidInput = inputs.find((input) => {
        const value = window.moneyValue(input);
        const minimum = Number(input.dataset.min ?? 0);

        return input.value !== '' && value < minimum;
    });

    if (invalidInput) {
        event.preventDefault();
        invalidInput.setCustomValidity(`Nominal minimal Rp ${window.money(invalidInput.dataset.min)}.`);
        invalidInput.reportValidity();

        return;
    }

    inputs.forEach((input) => {
        input.value = input.value.replace(/\D/g, '');
    });
}, true);
