@echo off
REM POS Application Launcher for Windows
SETLOCAL ENABLEDELAYEDEXPANSION

cd /d "%~dp0"

where php >nul 2>&1
if errorlevel 1 (
  echo PHP was not found in your PATH.
  echo Install PHP and add it to PATH, or set PHP_HOME to your PHP installation directory.
  pause
  exit /b 1
)

if not exist "data" mkdir "data"
if not exist "images\uploads" mkdir "images\uploads"

echo Starting POS application on port 8080...
php -S localhost:8080

ENDLOCAL
