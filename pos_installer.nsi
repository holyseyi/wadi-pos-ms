!ifndef APP_NAME
  !define APP_NAME "HADI POS "
!endif
!ifndef VERSION
  !define VERSION "1.0"
!endif
!ifndef INSTALL_DIR
  !define INSTALL_DIR "$PROGRAMFILES\HADI POS"
!endif
!ifndef OUTFILE
  !define OUTFILE "pos_installer.exe"
!endif
!ifndef SHORTCUT_NAME
  !define SHORTCUT_NAME "HADI POS"
!endif

Name "${APP_NAME} ${VERSION}"
OutFile "${OUTFILE}"
InstallDir "${INSTALL_DIR}"
RequestExecutionLevel admin
SetCompress auto
Page directory
Page instfiles

Section "Install"
  SetOutPath "$INSTDIR"
  File /r "*.*"
  CreateDirectory "$INSTDIR\data"
  CreateDirectory "$INSTDIR\images\uploads"
  WriteUninstaller "$INSTDIR\Uninstall.exe"
  CreateShortCut "$SMPROGRAMS\${APP_NAME}\Launch POS.lnk" "$INSTDIR\run_pos.bat"
  CreateShortCut "$DESKTOP\${APP_NAME}.lnk" "$INSTDIR\run_pos.bat"
SectionEnd

Section "Uninstall"
  Delete "$INSTDIR\run_pos.bat"
  Delete "$INSTDIR\install.sh"
  Delete "$INSTDIR\Uninstall.exe"
  Delete "$SMPROGRAMS\${APP_NAME}\Launch POS.lnk"
  Delete "$DESKTOP\${APP_NAME}.lnk"
  RMDir /r "$INSTDIR"
SectionEnd
