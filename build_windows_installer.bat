@echo off
setlocal enabledelayedexpansion

REM Build a standalone Windows installer for POS Pro using NSIS.
cd /d "%~dp0"

if not exist "installer_config.ini" (
  echo Error: installer_config.ini not found in %~dp0
  goto end
)

for /f "usebackq tokens=1* delims==" %%A in ("installer_config.ini") do (
  set "line=%%A"
  if not "!line:~0,1!"==";" if not "!line:~0,1!"=="#" (
    if not "!line!"=="" (
      set "%%A=%%B"
    )
  )
)

where makensis >nul 2>&1
if errorlevel 1 (
  echo Error: NSIS (makensis) was not found in PATH.
  echo Install NSIS from https://nsis.sourceforge.io/Download and rerun this script.
  goto end
)

if not defined APP_NAME set "APP_NAME=POS Pro"
if not defined VERSION set "VERSION=1.0"
if not defined INSTALL_DIR set "INSTALL_DIR=$PROGRAMFILES\POS Pro"
if not defined OUTFILE set "OUTFILE=pos_installer.exe"
if not defined SHORTCUT_NAME set "SHORTCUT_NAME=POS Pro"

set "DEFINES=/DAPP_NAME=\"%APP_NAME%\" /DVERSION=\"%VERSION%\" /DINSTALL_DIR=\"%INSTALL_DIR%\" /DOUTFILE=\"%OUTFILE%\" /DSHORTCUT_NAME=\"%SHORTCUT_NAME%\""

echo Building installer with:
echo   APP_NAME=%APP_NAME%
echo   VERSION=%VERSION%
echo   INSTALL_DIR=%INSTALL_DIR%
echo   OUTFILE=%OUTFILE%
echo   SHORTCUT_NAME=%SHORTCUT_NAME%

makensis %DEFINES% "pos_installer.nsi"
if errorlevel 1 (
  echo Error: NSIS build failed.
  goto end
)

echo Installer built successfully: %OUTFILE%

:end
endlocal
