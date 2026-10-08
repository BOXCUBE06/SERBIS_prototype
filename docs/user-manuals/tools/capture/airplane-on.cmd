@echo off
rem Offline shots. adb reverse carries the API past airplane mode, so it is removed too.
set "ADB=%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe"
"%ADB%" shell cmd connectivity airplane-mode enable
"%ADB%" reverse --remove tcp:8000
echo Airplane mode on, API tunnel removed. Run airplane-off when done.
