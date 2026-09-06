import json, pathlib, zipfile
from pypdf import PdfReader
import pypdfium2 as pdfium

root = pathlib.Path(__file__).resolve().parent
previews = root / 'pdf' / 'previews'
previews.mkdir(exist_ok=True)
results = []
for file in sorted((root / 'pdf').glob('*.pdf')):
    reader = PdfReader(file)
    texts = [page.extract_text() or '' for page in reader.pages]
    assert len(reader.pages) > 5, file
    assert all('POS Warung' in text for text in texts), file
    assert 'Jika proses tidak berhasil' in texts[-1], file
    images = sum(len(page.images) for page in reader.pages)
    assert images > 0, file
    results.append({'file': file.name, 'pages': len(reader.pages), 'image_placements': images, 'bytes': file.stat().st_size})
    if 'Developer' in file.name or 'Kasir' in file.name:
        doc = pdfium.PdfDocument(str(file))
        screenshot_index = next(i for i,t in enumerate(texts) if 'bagian 1/' in t or 'tablet 768 px' in t)
        for number in sorted(set([0, screenshot_index, len(reader.pages)-2])):
            doc[number].render(scale=1.2).to_pil().save(previews / f'{file.stem}-{number+1}.png')
        doc.close()
(root / 'pdf' / 'verification.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
with zipfile.ZipFile(root / 'User-Guide-Semua-Role.zip', 'w', zipfile.ZIP_DEFLATED) as archive:
    for file in sorted((root / 'pdf').glob('*.pdf')):
        archive.write(file, file.name)
print(json.dumps(results, indent=2))
