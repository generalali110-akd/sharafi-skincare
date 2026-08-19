@echo off
setlocal

set "ROOT=%~dp0..\..\.."
set "PYTHON=C:\Users\Yas\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe"
set "SCRIPT=%ROOT%\tools\extract_oil_jobs_pdf.py"
set "PDF=H:\شناسنامه هاي شغلي استاندارد مشاغل پيمانكاري صنعت نفت.pdf"
set "OUT=%ROOT%\output\pdf\oil_jobs\test_10_pages"
set "TESSERACT=C:\Program Files\Tesseract-OCR\tesseract.exe"

echo Oil Jobs PDF Extractor - 10 page test
echo.

if not exist "%TESSERACT%" (
  echo Tesseract OCR was not found:
  echo %TESSERACT%
  echo.
  echo Install Tesseract OCR with Persian language data, then run this file again.
  echo.
  pause
  exit /b 1
)

"%PYTHON%" "%SCRIPT%" --pdf "%PDF%" --out "%OUT%" --tesseract "%TESSERACT%" --limit 10

if errorlevel 1 (
  echo.
  echo Test extraction failed. See the message above.
  pause
  exit /b 1
)

echo.
echo Test extraction completed.
start "" "%OUT%"
pause
