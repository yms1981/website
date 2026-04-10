@echo off
REM Programador de tareas: cada 10 minutos ejecutar este .bat
REM Ajusta la ruta a php.exe si no usas XAMPP por defecto.
cd /d "%~dp0.."
"C:\xampp\php\php.exe" "%~dp0periodic_fullvendor_sync.php"
exit /b %ERRORLEVEL%
