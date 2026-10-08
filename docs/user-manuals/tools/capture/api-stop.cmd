@echo off
rem Stops the capture API on 127.0.0.1:8000 (MOB-39).
for /f "tokens=5" %%P in ('netstat -ano ^| findstr /r /c:"127.0.0.1:8000 .*LISTENING"') do taskkill /PID %%P /F /T >nul && echo Stopped API (PID %%P).
