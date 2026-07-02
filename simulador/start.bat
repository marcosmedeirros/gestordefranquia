@echo off
REM Inicia o servidor PHP do NBA Sim (usa SQLite — nao precisa de MySQL).
echo ============================================
echo   NBA Sim - iniciando servidor PHP
echo ============================================
echo.
echo Abra no navegador:  http://localhost:8000
echo (Para parar, feche esta janela ou pressione Ctrl+C)
echo.
cd /d "%~dp0public"
"C:\xampp\php\php.exe" -S localhost:8000
pause
