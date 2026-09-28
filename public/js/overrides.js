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

/*
 * Revisi 2026-09: escapeHtml dipakai halaman Membership saat verifikasi kartu,
 * tetapi sebelumnya hanya didefinisikan di halaman Kasir sehingga pendaftaran
 * kartu selalu menampilkan "Kartu tidak ditemukan".
 */
window.escapeHtml = window.escapeHtml || ((value) => {
    const div = document.createElement('div');
    div.textContent = value ?? '';

    return div.innerHTML;
});

/* Pemilih ikon menu: <input type="hidden" data-icon-picker> + window.MENU_ICONS. */
window.menuIconHtml = (value) => {
    if (!value) return '<i class="bi bi-image"></i>';

    return value.startsWith('bi-')
        ? `<i class="bi ${window.escapeHtml(value)}"></i>`
        : `<span class="menu-icon-emoji">${window.escapeHtml(value)}</span>`;
};

window.initializeIconPickers = (root = document) => {
    const options = window.MENU_ICONS;
    if (!options) return;

    root.querySelectorAll('input[data-icon-picker]').forEach((input) => {
        if (input.dataset.iconBound === 'true') return;
        input.dataset.iconBound = 'true';

        const wrap = document.createElement('div');
        wrap.className = 'icon-picker';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'icon-picker-toggle';
        toggle.setAttribute('aria-haspopup', 'true');
        const panel = document.createElement('div');
        panel.className = 'icon-picker-panel';
        panel.hidden = true;
        panel.innerHTML = `<button type="button" class="icon-choice icon-choice-reset" data-icon="">${window.escapeHtml(input.dataset.placeholder || 'Default')}</button>`
            + Object.entries(options.bootstrap).map(([key, label]) => `<button type="button" class="icon-choice" data-icon="${key}" title="${window.escapeHtml(label)}" aria-label="${window.escapeHtml(label)}"><i class="bi ${key}"></i></button>`).join('')
            + options.emoji.map((emoji) => `<button type="button" class="icon-choice" data-icon="${emoji}" aria-label="${emoji}">${emoji}</button>`).join('')
            + '<label class="icon-custom"><span>Emoji lain</span><input type="text" maxlength="8" placeholder="Tempel emoji"></label>';

        const render = () => {
            toggle.innerHTML = `<span class="menu-icon menu-icon-sm">${window.menuIconHtml(input.value)}</span><span>${input.value ? 'Ganti ikon' : window.escapeHtml(input.dataset.placeholder || 'Pilih ikon')}</span>`;
            panel.querySelectorAll('[data-icon]').forEach((button) => button.classList.toggle('active', button.dataset.icon === input.value));
        };
        const choose = (value) => {
            input.value = value;
            panel.hidden = true;
            render();
        };

        toggle.addEventListener('click', () => { panel.hidden = !panel.hidden; });
        panel.addEventListener('click', (event) => {
            const button = event.target.closest('[data-icon]');
            if (button) choose(button.dataset.icon);
        });
        panel.querySelector('.icon-custom input').addEventListener('change', (event) => {
            const value = event.target.value.trim();
            if (value) choose(value);
            event.target.value = '';
        });

        input.insertAdjacentElement('afterend', wrap);
        wrap.append(toggle, panel);
        input.renderIconPicker = render;
        render();
    });
};

window.setIconPickerValue = (input, value) => {
    if (!input) return;
    input.value = value || '';
    input.renderIconPicker?.();
};

document.addEventListener('click', (event) => {
    document.querySelectorAll('.icon-picker-panel:not([hidden])').forEach((panel) => {
        if (!panel.parentElement.contains(event.target)) panel.hidden = true;
    });
});

document.addEventListener('DOMContentLoaded', () => window.initializeIconPickers());
