!ifndef APP_NAME
  !define APP_NAME "DziePOSMS"
!endif
!ifndef VERSION
  !define VERSION "1.1"
!endif
!ifndef INSTALL_DIR
  !define INSTALL_DIR "$LOCALAPPDATA\DziePOSMS"
!endif
!ifndef OUTFILE
  !define OUTFILE "pos_installer.exe"
!endif
!ifndef SHORTCUT_NAME
  !define SHORTCUT_NAME "DziePOSMS"
!endif

Name "${APP_NAME} ${VERSION}"
OutFile "${OUTFILE}"
InstallDir "${INSTALL_DIR}"
SetCompress auto
Page directory
Page instfiles

; Recursively delete all files and folders under a directory except the
; "data" and "uploads" folders (user data: SQLite databases and product
; photos). Entries are matched by name at any depth, so a preserved folder
; keeps everything inside it. Used by the uninstaller so uninstalling never
; removes user data kept in the install folder.
; Stack: 1 - directory to clean.
Function un.DeleteFoldersWithExclusion
  Exch $R1 ; directory to clean
  Push $R2 ; current entry name
  Push $R3 ; find handle
  ClearErrors
  FindFirst $R3 $R2 "$R1\*.*"
  dwe_top:
    StrCmp $R2 "" dwe_done
    StrCmp $R2 "." dwe_next
    StrCmp $R2 ".." dwe_next
    StrCmp $R2 "data" dwe_next    ; preserve databases and backups
    StrCmp $R2 "uploads" dwe_next ; preserve user-uploaded product photos
    IfFileExists "$R1\$R2\*.*" 0 dwe_file
      ; directory: clean its contents first, then remove it if left empty
      Push "$R1\$R2"
      Call un.DeleteFoldersWithExclusion
      RMDir "$R1\$R2"
      Goto dwe_next
    dwe_file:
      Delete "$R1\$R2"
  dwe_next:
    ClearErrors
    FindNext $R3 $R2
    IfErrors dwe_done
    Goto dwe_top
  dwe_done:
    FindClose $R3
    Pop $R3
    Pop $R2
    Pop $R1
FunctionEnd

Section "Install"
  SetOutPath "$INSTDIR"
  ; Exclude the data folder: on Windows the live database lives in %APPDATA%\DziePOSMS,
  ; so shipping the developer's data\pos.db and data\backups would seed fresh installs
  ; with test data and could clobber databases on reinstall.
  ; Also exclude the installer binary itself, otherwise a rebuild over an
  ; existing pos_installer.exe would package the old installer inside the new one.
  ; Exclude the local git history as well: it is not needed at runtime.
  File /r /x "data" /x "pos_installer.exe" /x ".git" /x ".kilo" /x ".github" /x "tests" "*.*"
  IfFileExists "$WINDIR\System32\VCRUNTIME140.dll" vcruntime_ok
    MessageBox MB_ICONINFORMATION|MB_OK "The Visual C++ runtime is required to run bundled PHP. If it is missing, install vc_redist.x64.exe from the install folder or from Microsoft."
  vcruntime_ok:
  CreateDirectory "$INSTDIR\data"
  CreateDirectory "$INSTDIR\images\uploads"
  WriteUninstaller "$INSTDIR\Uninstall.exe"
  CreateShortCut "$SMPROGRAMS\${APP_NAME}\Launch POS.lnk" "$INSTDIR\run_pos.bat"
  CreateShortCut "$DESKTOP\${APP_NAME}.lnk" "$INSTDIR\run_pos.bat"
SectionEnd

Section "Uninstall"
  Delete "$INSTDIR\run_pos.bat"
  Delete "$INSTDIR\install.sh"
  Delete "$INSTDIR\vc_redist.x64.exe"
  Delete "$INSTDIR\Uninstall.exe"
  Delete "$SMPROGRAMS\${APP_NAME}\Launch POS.lnk"
  Delete "$DESKTOP\${APP_NAME}.lnk"

  ; Remove the application but keep the data and images\uploads folders:
  ; databases, backups and user-uploaded product photos must survive uninstall.
  Push "$INSTDIR"
  Call un.DeleteFoldersWithExclusion
SectionEnd
