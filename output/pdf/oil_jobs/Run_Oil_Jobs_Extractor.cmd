@echo off
setlocal

set "ROOT=%~dp0..\..\.."
set "PYTHON=C:\Users\Yas\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe"
set "SCRIPT=%ROOT%\tools\extract_oil_jobs_pdf.py"
set "PDF=H:\شناسنامه هاي شغلي استاندارد مشاغل پيمانكاري صنعت نفت.pdf"
set "OUT=%ROOT%\output\pdf\oil_jobs"
set "TESSERACT=C:\Program Files\Tesseract-OCR\tesseract.exe"

echo Oil Jobs PDF Extractor
echo.

if not exist "%PYTHON%" (
  echo Python runtime was not found:
  echo %PYTHON%
  echo.
  pause
  exit /b 1
)

if not exist "%SCRIPT%" (
  echo Extractor script was not found:
  echo %SCRIPT%
  echo.
  pause
  exit /b 1
)

if not exist "%PDF%" (
  echo PDF file was not found:
  echo %PDF%
  echo.
  pause
  exit /b 1
)

if not exist "%TESSERACT%" (
  echo Tesseract OCR was not found:
  echo %TESSERACT%
  echo.
  echo Install Tesseract OCR with Persian language data, then run this file again.
  echo.
  pause
  exit /b 1
)

echo Starting OCR extraction. This can take a long time for 2090 pages.
echo Output folder:
echo %OUT%
echo.

"%PYTHON%" "%SCRIPT%" --pdf "%PDF%" --out "%OUT%" --tesseract "%TESSERACT%"

if errorlevel 1 (
  echo.
  echo Extraction failed. See the message above.
  pause
  exit /b 1
)

echo.
echo Extraction completed.
echo Opening output folder...
start "" "%OUT%"
pause
