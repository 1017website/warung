from pathlib import Path
from reportlab.pdfgen import canvas
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph, Table, TableStyle
from reportlab.lib.styles import ParagraphStyle
from reportlab.graphics.barcode import qr
from reportlab.graphics.shapes import Drawing
from reportlab.graphics import renderPDF
from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'output/pdf/tutorial-printer-android-rawbt.pdf'
OUT.parent.mkdir(parents=True, exist_ok=True)
pdfmetrics.registerFont(TTFont('Arial', 'C:/Windows/Fonts/arial.ttf'))
pdfmetrics.registerFont(TTFont('Arial-Bold', 'C:/Windows/Fonts/arialbd.ttf'))
pdfmetrics.registerFontFamily('Arial', normal='Arial', bold='Arial-Bold', italic='Arial', boldItalic='Arial-Bold')
W, H = A4
M = 44
CW = W - 2*M
INK = colors.HexColor('#172c25')
GREEN = colors.HexColor('#3b6252')
GRAY = colors.HexColor('#53645d')
PALE = colors.HexColor('#edf3ef')
LINE = colors.HexColor('#c5d2ca')
URL = 'https://play.google.com/store/apps/details?id=ru.a402d.rawbtprinter'
c = canvas.Canvas(str(OUT), pagesize=A4)
c.setTitle('Tutorial Printer Android dengan RawBT - PANDA dan EPPOS')
c.setAuthor('Panduan operasional website kasir')
sty = ParagraphStyle('body', fontName='Arial', fontSize=11, leading=16, textColor=INK)
small = ParagraphStyle('small', parent=sty, fontSize=9, leading=13, textColor=GRAY)

def text(s, x, y, width=CW, style=sty):
    p = Paragraph(s, style)
    _, h = p.wrap(width, 1000)
    p.drawOn(c, x, y-h)
    return y-h

def step(n, title, body, y, width=CW):
    c.setFillColor(GREEN)
    c.circle(M+11, y-11, 11, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont('Arial-Bold', 11)
    c.drawCentredString(M+11, y-15, str(n))
    y = text(f'<b>{title}</b><br/>{body}', M+34, y, width-34)
    return y-13

def note(body, y, width=CW):
    p = Paragraph(body, sty)
    _, ph = p.wrap(width-26, 1000)
    c.setFillColor(PALE)
    c.roundRect(M, y-ph-24, width, ph+24, 7, fill=1, stroke=0)
    p.drawOn(c, M+13, y-ph-12)
    return y-ph-37

def start(n, title, subtitle):
    c.setFillColor(GREEN)
    c.rect(0,H-9,W,9,fill=1,stroke=0)
    text('PANDUAN KASIR  /  ANDROID + RAWBT', M, H-30, style=small)
    title_style = ParagraphStyle('title', parent=sty, fontName='Arial-Bold', fontSize=23, leading=28)
    y = text(title, M, H-65, style=title_style)
    y = text(subtitle, M, y-10, style=small)
    c.setStrokeColor(LINE)
    c.line(M, 35, W-M, 35)
    text('Printer pengguna: PANDA / EPPOS | Android + RawBT | 58 mm', M, 27, style=small)
    c.setFont('Arial',9)
    c.setFillColor(GRAY)
    c.drawRightString(W-M, 18, f'{n} / 5')
    return y-23

def panel(title, rows, y, width=CW, caption=None):
    # Vector illustration: a guide to the choices, not an exact app screenshot.
    x=M
    height=46+len(rows)*43
    c.setFillColor(colors.white)
    c.setStrokeColor(LINE)
    c.roundRect(x,y-height,width,height,9,fill=1,stroke=1)
    c.setFillColor(PALE)
    c.roundRect(x+1,y-39,width-2,38,8,fill=1,stroke=0)
    text(f'<b>{title}</b>',x+15,y-12,width-30)
    for i,(label,value) in enumerate(rows):
        top=y-49-i*43
        text(label,x+15,top,width*0.46,small)
        text(f'<b>{value}</b>',x+width*0.48,top,width*0.48)
        if i < len(rows)-1:
            c.setStrokeColor(LINE)
            c.line(x+15,top-31,x+width-15,top-31)
    y-=height+7
    if caption: y=text(caption,M,y,width,small)-12
    return y

# Printer identification from the user's own photographs.
y=start(1,'Kenali printer pengguna','Panduan diperbarui berdasarkan foto perangkat pengguna, 7 Oktober 2026.')
y=text('Printer <b>Xantri</b> milik pengembang dipakai untuk pengujian. Perangkat pengguna pada foto adalah printer <b>PANDA dan EPPOS</b>. Pilih printer yang benar saat melakukan pairing dan tes RawBT.',M,y)-17
photos=[
    ('WhatsApp Image 2026-10-07 at 8.12.53 AM (1).jpeg','PANDA dan EPPOS meja'),
    ('WhatsApp Image 2026-10-07 at 8.13.07 AM.jpeg','EPPOS portable'),
]
pw=(CW-18)/2
for i,(filename,caption) in enumerate(photos):
    path=Path('C:/Users/M.Zulfi/Downloads')/filename
    iw,ih=Image.open(path).size
    scale=min(pw/iw,235/ih)
    dw,dh=iw*scale,ih*scale
    x=M+i*(pw+18)+(pw-dw)/2
    c.drawImage(str(path),x,y-dh,width=dw,height=dh)
    text(caption,M+i*(pw+18),y-243,pw,small)
y-=278
rows=[['Perangkat','Yang terlihat pada label foto'],
    ['PANDA PRJ-58D','Kertas 58 mm; kotak USB+BT dicentang.'],
    ['EPPOS POS58L','Label model POS58L; kertas 58 mm. Nama dan koneksi Bluetooth perlu dicek saat tes.'],
    ['EPPOS portable','Label kertas 58 mm dan MULTI BT; ada baterai lepas-pasang.']]
cells=[[Paragraph(v,ParagraphStyle('inventory',parent=sty,fontSize=10,leading=14)) for v in row] for row in rows]
t=Table(cells,colWidths=[145,CW-145])
t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),PALE),('VALIGN',(0,0),(-1,-1),'TOP'),('GRID',(0,0),(-1,-1),.5,LINE),('LEFTPADDING',(0,0),(-1,-1),10),('RIGHTPADDING',(0,0),(-1,-1),10),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8)]))
_,th=t.wrap(CW,1000);t.drawOn(c,M,y-th);y-=th+15
y=note('<b>EPPOS berbeda dengan Epson.</b> Pada website, gunakan pilihan <b>RawBT</b> untuk alur Android ini. Jangan memilih Epson ePOS hanya karena nama mereknya mirip.',y)
y=text('Foto dan identifikasi model bersumber dari kiriman pengguna. Dukungan RawBT harus dikonfirmasi dengan tes cetak masing-masing unit. Tulisan MULTI BT belum membuktikan dukungan AirPrint atau cetak langsung dari Safari iPad.',M,y,CW,small)
assert y>42
c.showPage()

# Page 2
y=start(2,'Siapkan printer dan Bluetooth','Pengaturan awal. Lakukan sekali, lalu ulangi hanya jika printer atau tablet diganti.')
y=step(1,'Siapkan printer','Sambungkan adaptor, pasang kertas thermal <b>58 mm</b>, lalu nyalakan printer. Tekan <b>FEED</b> untuk memastikan kertas keluar lancar.',y)
y=step(2,'Pasangkan printer ke tablet Android','Buka <b>Pengaturan &gt; Bluetooth</b> atau <b>Perangkat terhubung</b>. Aktifkan Bluetooth, cari perangkat baru, lalu ketuk nama printer. Untuk unit portable, pastikan baterainya terisi.',y)
y=panel('Ilustrasi pengaturan Bluetooth',[
    ('Bluetooth tablet','Aktif'),
    ('Perangkat yang dicari','Nama Bluetooth PANDA / EPPOS'),
    ('Setelah dipasangkan','Dipasangkan / Paired'),
],y,caption='Ilustrasi panduan, bukan tangkapan layar Android. Nama printer dan menu dapat berbeda.')
y=step(3,'Jika diminta PIN','Gunakan PIN yang tercantum pada buku petunjuk atau label printer. Jangan menebak PIN jika tidak tercantum; tanyakan kepada penjual.',y)
y=note('<b>Perlu dibedakan:</b> Bluetooth yang sudah dipasangkan belum berarti website bisa langsung mencetak. RawBT akan menjadi penghubung antara website dan printer.',y)
boxw=(CW-28)/3
for i,(a,b) in enumerate([('WEBSITE KASIR','Data struk'),('RAWBT ANDROID','Penghubung cetak'),('PANDA / EPPOS','Struk 58 mm')]):
    x=M+i*(boxw+14)
    c.setFillColor(PALE); c.roundRect(x,y-57,boxw,57,6,fill=1,stroke=0)
    text(f'<b>{a}</b><br/>{b}',x+10,y-10,boxw-20,small)
    if i<2:
        c.setStrokeColor(GREEN); c.line(x+boxw+2,y-29,x+boxw+12,y-29)
c.showPage()

# Page 3
y=start(3,'Pasang dan atur RawBT','Pilih printer di RawBT, lalu lakukan tes cetak sebelum membuka website kasir.')
y=step(4,'Instal aplikasi yang benar','Buka Google Play, cari <b>RawBT</b>, lalu instal aplikasi dari pengembang <b>402D, TOO</b>. Anda juga dapat memakai tautan atau QR di bawah.',y)
q=qr.QrCodeWidget(URL)
bx,by,ex,ey=q.getBounds()
d=Drawing(78,78,transform=[78/(ex-bx),0,0,78/(ey-by),0,0]); d.add(q)
renderPDF.draw(d,c,W-M-83,y-79)
y=text(f'<link href="{URL}" color="#245f48"><u>Buka halaman RawBT di Google Play</u></link><br/>Pindai QR dari perangkat lain, atau ketuk tautan ini saat membaca PDF di tablet.',M,y-5,CW-105)
y-=42
y=step(5,'Izinkan akses Bluetooth','Buka RawBT. Jika diminta, izinkan akses <b>Perangkat di sekitar / Nearby devices</b> atau Bluetooth. Jika pencarian meminta izin tambahan, ikuti petunjuk Android.',y)
y=step(6,'Pilih koneksi dan printer','Buka pengaturan koneksi printer di RawBT. Pilih <b>Bluetooth</b>, kemudian pilih printer yang sudah dipasangkan. Jika tersedia pilihan kertas, gunakan <b>58 mm</b>.',y)
y=panel('Ilustrasi pilihan di RawBT',[
    ('Jenis koneksi','Bluetooth'),
    ('Printer tujuan','PANDA / EPPOS yang dipasangkan'),
    ('Kertas, jika tersedia','58 mm'),
    ('Pemeriksaan','Tes cetak / Test print'),
],y,caption='Ini ringkasan pilihan yang perlu dicari. Susunan dan nama menu dapat berbeda menurut versi RawBT.')
y=step(7,'Jalankan tes cetak','Gunakan fitur <b>tes cetak</b> di RawBT. Pastikan printer mengeluarkan kertas berisi tulisan yang terbaca.',y)
y=note('<b>Berhasil jika:</b> tes dari RawBT sudah tercetak. Jika tes ini belum berhasil, perbaiki koneksi printer terlebih dahulu sebelum melanjutkan ke website.',y)
c.showPage()

# Page 4
y=start(4,'Atur printer di website kasir','Printer Bluetooth dipilih di RawBT. Website hanya perlu diarahkan untuk memakai RawBT.')
y=step(8,'Buka pengaturan perangkat','Buka website kasir melalui <b>Chrome di tablet Android</b>. Masuk ke <b>Pengaturan &gt; Perangkat terhubung &gt; Tambah</b>. Jika printer sudah terdaftar, pilih <b>Ubah perangkat</b>.',y)
y=panel('Ilustrasi isian perangkat di website',[
    ('Nama perangkat','PANDA Kasir / EPPOS Kasir'),
    ('Jenis','Printer struk'),
    ('Cabang','Cabang tempat printer digunakan'),
    ('Cara cetak','RawBT - Bluetooth Android, 58 mm'),
    ('Tindakan','Simpan / Sambungkan'),
],y,caption='Isi nama perangkat bebas. Pilih RawBT pada Cara cetak, bukan Umum / dialog cetak browser.')
y=step(9,'Simpan dan tes dari website','Simpan pengaturan. Buka <b>Ubah perangkat</b>, lalu ketuk <b>Tes cetak RawBT</b>. Jika Chrome meminta izin membuka aplikasi, pilih <b>Buka</b>.',y)
y=note('<b>Tidak perlu mengisi alamat IP.</b> Saat cara cetak RawBT dipilih, kolom koneksi/alamat disembunyikan. Nama printer Bluetooth tetap dipilih dari aplikasi RawBT.',y)
y=text('<b>Jika pilihan RawBT belum ada</b><br/>Muat ulang halaman. Jika tetap tidak muncul, minta pengelola memastikan pembaruan website sudah diterapkan.',M,y)
y=text('<b>Logo struk</b><br/>Unggah logo branding dan aktifkan <b>Tampilkan logo di struk</b>. Pada integrasi terbaru, logo dikirim ke RawBT untuk salinan customer dan dicetak hitam putih. Salinan dapur tidak memakai logo.',M,y-19)
c.showPage()

# Page 5
y=start(5,'Cetak struk dan atasi kendala','Sesudah pengaturan awal, gunakan alur ini untuk setiap transaksi.')
y=step(10,'Pilih salinan, lalu cetak','Selesaikan pembayaran. Pada halaman struk, pilih <b>Customer + dapur</b>, <b>Customer saja</b>, atau <b>Dapur saja</b>, lalu ketuk <b>Cetak struk</b>. Pastikan cara cetaknya RawBT Android.',y)
shot=ROOT/'tmp/pdfs/rawbt-user-preview.png'
if shot.exists():
    iw,ih=Image.open(shot).size
    dw=CW; dh=dw*ih/iw; crop=205*dw/iw
    p=c.beginPath(); p.rect(M,y-crop,dw,crop)
    c.saveState(); c.clipPath(p,stroke=0,fill=0)
    c.drawImage(str(shot),M,y-dh,width=dw,height=dh)
    c.restoreState()
    y-=crop+8
    y=text('Tangkapan layar halaman cetak website dengan data uji. Pilihan printer lainnya ada di menu lipat.',M,y,CW,small)-12
y=note('<b>Cek kertas sebelum mencetak ulang.</b> Website tidak menerima konfirmasi bahwa struk sudah keluar dari printer. Jika RawBT terbuka, tunggu dan periksa hasilnya terlebih dahulu.',y)
y=text('<b>Jika ada kendala</b>',M,y)-9
rows=[['Kendala','Yang diperiksa'],
['Printer tidak ditemukan','Pastikan printer menyala, Bluetooth aktif, dan tablet dekat dengan printer.'],
['RawBT terbuka, tidak mencetak','Pilih ulang printer di RawBT. Coba tes cetak langsung dari RawBT.'],
['RawBT tidak terbuka','Pastikan RawBT terpasang. Gunakan Chrome dan izinkan pembukaan aplikasi.'],
['Kertas keluar kosong','Periksa arah gulungan dan pastikan memakai kertas thermal.'],
['Logo belum tercetak','Cek logo branding, opsi Tampilkan logo di struk, serta pembaruan website. Jika muncul pesan logo gagal, teruskan pesan itu kepada pengelola.']]
cells=[[Paragraph(v,ParagraphStyle('table',parent=sty,fontSize=10,leading=14)) for v in row] for row in rows]
t=Table(cells,colWidths=[144,CW-144])
t.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),PALE),('VALIGN',(0,0),(-1,-1),'TOP'),('GRID',(0,0),(-1,-1),.5,LINE),('LEFTPADDING',(0,0),(-1,-1),10),('RIGHTPADDING',(0,0),(-1,-1),10),('TOPPADDING',(0,0),(-1,-1),8),('BOTTOMPADDING',(0,0),(-1,-1),8)]))
_,th=t.wrap(CW,1000); t.drawOn(c,M,y-th); y-=th+15
y=text(f'<b>Referensi:</b> <link href="{URL}" color="#245f48"><u>RawBT di Google Play</u></link> dan fitur printer pada proyek website kasir. Panduan disusun 7 Oktober 2026. RawBT pada panduan ini digunakan di Android; panduan ini tidak berlaku untuk iPad.',M,y,CW,small)
assert y > 42, f'Page 4 content runs into footer: {y}'
c.save()
print(OUT)
