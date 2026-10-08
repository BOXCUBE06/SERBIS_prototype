@echo off
rem Starts the API on 127.0.0.1:8000 against serbis_capture, in its own window.
start "SERBIS API (serbis_capture)" cmd /k "cd /d "%~dp0..\..\..\..\Backend\SERBIS-Backend" && set "DB_DATABASE=serbis_capture" && php artisan serve --host=127.0.0.1 --port=8000"
echo API starting on http://127.0.0.1:8000 (serbis_capture).
