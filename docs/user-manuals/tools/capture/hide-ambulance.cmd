@echo off
rem MOB-42: stop offering Ambulance to organization accounts. Run restore-audience afterwards.
set "DB_DATABASE=serbis_capture"
php "%~dp0seed-capture.php" --hide-ambulance-for-organizations
