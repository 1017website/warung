import './bootstrap';
import QRCode from 'qrcode';

window.money = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value || 0));
window.openModal = (id) => document.getElementById(id)?.classList.add('open');
window.closeModal = (id) => document.getElementById(id)?.classList.remove('open');
window.renderQr = (canvas, value) => QRCode.toCanvas(canvas, value, { width: 240, margin: 1, color: { dark: '#21342d', light: '#ffffff' } });

document.addEventListener('click', (event) => {
    if (event.target.classList.contains('modal')) event.target.classList.remove('open');
});
