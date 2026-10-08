@echo off
rem Offer Ambulance to organization accounts again (undoes hide-ambulance).
set "DB_DATABASE=serbis_capture"
php "%~dp0seed-capture.php" --restore-audience
