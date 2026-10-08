@echo off
rem Saves the emulator screen as <ID>.png in Downloads\SERBIS-screenshots\mobile
rem and in docs\user-manuals\screenshots\raw. Usage: shot MOB-12 [--force]
setlocal
if "%~1"=="" (echo Usage: shot MOB-xx [--force] & exit /b 1)
set "ADB=%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe"
set "OUT=%USERPROFILE%\Downloads\SERBIS-screenshots\mobile"
set "RAW=%~dp0..\..\screenshots\raw"
if not exist "%OUT%" mkdir "%OUT%"
if not exist "%RAW%" mkdir "%RAW%"
if exist "%OUT%\%~1.png" if /i not "%~2"=="--force" (echo %~1.png already exists. Add --force to replace it. & exit /b 1)
"%ADB%" exec-out screencap -p > "%OUT%\%~1.png" || (echo Screenshot failed. Is the emulator running? & exit /b 1)
copy /y "%OUT%\%~1.png" "%RAW%\%~1.png" >nul
for %%F in ("%RAW%\%~1.png") do echo Saved %OUT%\%~1.png and %%~fF
