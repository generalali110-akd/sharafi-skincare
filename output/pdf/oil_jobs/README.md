# استخراج شناسنامه های شغلی پیمانکاری صنعت نفت

فایل PDF بررسی شد: 2090 صفحه دارد و متن داخلی آن به دلیل نبود نگاشت Unicode قابل استخراج مستقیم نیست. خروجی ابزارهای `pdfplumber` و `pypdf` به شکل کدهای glyph و متن نامعتبر برمی گردد، بنابراین استخراج قابل جستجو باید با OCR فارسی انجام شود.

## فایل ابزار

`tools/extract_oil_jobs_pdf.py`

## فایل های اجرایی

- `Run_Test_10_Pages.cmd` برای تست سریع 10 صفحه اول
- `Run_Oil_Jobs_Extractor.cmd` برای استخراج کامل PDF

ابتدا فایل تست را اجرا کنید. اگر خروجی OCR قابل قبول بود، فایل اجرای کامل را اجرا کنید.

این ابزار پس از نصب Tesseract OCR با زبان فارسی، خروجی های زیر را می سازد:

- `oil_jobs_searchable.xlsx` برای Excel با AutoFilter و ستون های قابل مرتب سازی
- `oil_jobs_searchable.csv` برای ورود به هر ابزار داده
- `oil_jobs_searchable.json` برای استفاده برنامه نویسی
- `oil_jobs_searchable.html` برای جستجو، فیلتر و مرتب سازی در مرورگر

## دستور اجرا

```powershell
& "C:\Users\Yas\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe" tools\extract_oil_jobs_pdf.py --tesseract "C:\Program Files\Tesseract-OCR\tesseract.exe"
```

برای تست چند صفحه اول:

```powershell
& "C:\Users\Yas\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe" tools\extract_oil_jobs_pdf.py --tesseract "C:\Program Files\Tesseract-OCR\tesseract.exe" --limit 10
```

## وضعیت فعلی سیستم

- رندر صفحه ها با `pypdfium2` موفق است.
- نمونه صفحه در `tmp/pdf_extract/sample_page_001.png` ساخته شد.
- OCR داخلی ویندوز فقط `en-US` دارد.
- `tesseract.exe` روی سیستم پیدا نشد.
