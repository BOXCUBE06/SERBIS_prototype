@echo off
set "ADB=%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe"
"%ADB%" shell cmd connectivity airplane-mode disable
"%ADB%" reverse tcp:8000 tcp:8000 >nul
echo Airplane mode off, API tunnel back.
