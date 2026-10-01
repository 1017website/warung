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

/*
 * Revisi 2026-10: upload file. Setiap <input type="file"> menjadi area klik/seret dengan
 * nama & ukuran file terpilih, pratinjau gambar, serta validasi jenis/ukuran sebelum dikirim.
 * Atribut opsional: data-file-hint, data-max-mb, data-current-src, data-current-label.
 */
window.formatFileSize = (bytes) => (bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`);

const fileMatchesAccept = (input, file) => {
    const rules = (input.accept || '').split(',').map((rule) => rule.trim().toLowerCase()).filter(Boolean);
    if (!rules.length) return true;
    const name = file.name.toLowerCase();
    const type = (file.type || '').toLowerCase();

    return rules.some((rule) => {
        if (rule.startsWith('.')) return name.endsWith(rule);
        if (rule.endsWith('/*')) return type.startsWith(rule.slice(0, -1));

        return type === rule;
    });
};

window.initializeFileDrops = (root = document) => {
    root.querySelectorAll('input[type="file"]').forEach((input) => {
        if (input.dataset.fileDropBound === 'true') return;
        input.dataset.fileDropBound = 'true';

        const image = (input.accept || '').includes('image');
        const maxMb = Number(input.dataset.maxMb || 0);
        const hint = input.dataset.fileHint || (image ? 'Gambar PNG atau JPG' : 'File');
        const icon = image ? 'bi-image' : 'bi-file-earmark-spreadsheet';

        const drop = document.createElement('label');
        drop.className = image ? 'file-drop is-image' : 'file-drop';
        input.insertAdjacentElement('beforebegin', drop);
        drop.append(input);
        drop.insertAdjacentHTML('beforeend', `
            <span class="file-drop-empty">
                <span class="file-drop-icon"><i class="bi ${image ? 'bi-image' : 'bi-cloud-arrow-up'}"></i></span>
                <span class="file-drop-text"><b>Klik untuk memilih file</b> atau seret file ke sini</span>
                <span class="file-drop-hint"></span>
            </span>
            <span class="file-drop-chosen" hidden>
                <span class="file-drop-thumb"></span>
                <span class="file-drop-meta"><b class="file-drop-name"></b><small class="file-drop-size"></small></span>
                <span class="file-drop-actions">
                    <span class="btn btn-outline btn-sm file-drop-change">Ganti</span>
                    <button type="button" class="btn btn-danger btn-sm file-drop-clear" aria-label="Batalkan pilihan file" title="Batalkan pilihan file"><i class="bi bi-x-lg"></i></button>
                </span>
            </span>`);
        drop.querySelector('.file-drop-hint').textContent = hint + (maxMb ? ` · maks. ${maxMb} MB` : '');
        const error = document.createElement('div');
        error.className = 'file-drop-error';
        error.setAttribute('role', 'alert');
        error.hidden = true;
        drop.insertAdjacentElement('afterend', error);

        // File yang sudah tersimpan (mis. logo cabang) tampil di area kosong sebagai acuan.
        if (input.dataset.currentSrc) {
            const current = drop.querySelector('.file-drop-icon');
            const img = new Image();
            img.src = input.dataset.currentSrc;
            img.alt = input.dataset.currentLabel || 'File saat ini';
            current.replaceChildren(img);
            drop.querySelector('.file-drop-text').innerHTML = `<b>${window.escapeHtml(input.dataset.currentLabel || 'File saat ini')}</b> · klik atau seret file untuk mengganti`;
        }

        const showError = (message) => {
            error.textContent = message;
            error.hidden = !message;
            drop.classList.toggle('is-invalid', Boolean(message));
        };
        const validate = () => {
            const file = input.files[0];
            let message = '';
            if (file && !fileMatchesAccept(input, file)) message = `Jenis file tidak sesuai. Pilih ${hint}.`;
            else if (file && maxMb && file.size > maxMb * 1048576) message = `Ukuran file ${window.formatFileSize(file.size)} melebihi batas ${maxMb} MB.`;
            input.setCustomValidity(message);
            showError(message);

            return !message;
        };
        const render = () => {
            const file = input.files[0];
            const thumb = drop.querySelector('.file-drop-thumb');
            drop.classList.toggle('has-file', Boolean(file));
            drop.querySelector('.file-drop-empty').hidden = Boolean(file);
            drop.querySelector('.file-drop-chosen').hidden = !file;
            thumb.innerHTML = `<i class="bi ${icon}"></i>`;
            if (file) {
                drop.querySelector('.file-drop-name').textContent = file.name;
                drop.querySelector('.file-drop-size').textContent = window.formatFileSize(file.size);
                if (image && (file.type || '').startsWith('image/')) {
                    const url = URL.createObjectURL(file);
                    const img = new Image();
                    img.alt = '';
                    img.onload = () => URL.revokeObjectURL(url);
                    img.src = url;
                    thumb.replaceChildren(img);
                }
            }
            validate();
        };

        input.addEventListener('change', render);
        // Pesan sendiri menggantikan balon validasi browser yang menempel pada input tersembunyi.
        input.addEventListener('invalid', (event) => {
            event.preventDefault();
            showError(input.files.length && input.validationMessage ? input.validationMessage : 'Pilih file terlebih dahulu.');
            drop.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
        drop.querySelector('.file-drop-clear').addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            input.value = '';
            render();
        });
        ['dragenter', 'dragover'].forEach((type) => drop.addEventListener(type, (event) => {
            event.preventDefault();
            drop.classList.add('is-dragging');
        }));
        ['dragleave', 'dragend', 'drop'].forEach((type) => drop.addEventListener(type, (event) => {
            if (type === 'dragleave' && drop.contains(event.relatedTarget)) return;
            drop.classList.remove('is-dragging');
        }));
        drop.addEventListener('drop', (event) => {
            event.preventDefault();
            const file = event.dataTransfer?.files?.[0];
            if (!file) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            render();
        });
        input.form?.addEventListener('reset', () => setTimeout(render));
    });
};

document.addEventListener('DOMContentLoaded', () => window.initializeFileDrops());

// Tombol kirim form upload terkunci selama file diunggah agar tidak terkirim dua kali.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || !form.querySelector('input[type="file"][data-file-drop-bound]')) return;
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
        button.dataset.originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="file-drop-spinner" aria-hidden="true"></span> Mengunggah…';
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-original-html]').forEach((button) => {
        button.innerHTML = button.dataset.originalHtml;
        button.disabled = false;
        delete button.dataset.originalHtml;
    });
});

/* Tab format pada modal Import Excel. */
document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-import-tab]');
    if (!tab) return;
    const form = tab.closest('form');
    form.querySelectorAll('[data-import-tab]').forEach((item) => {
        item.classList.toggle('active', item === tab);
        item.setAttribute('aria-selected', String(item === tab));
    });
    form.querySelectorAll('[data-import-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.importPanel !== tab.dataset.importTab;
    });
});
