@echo off
REM ===========================================================================
REM  scripts\iniciar-servidor.bat
REM  --------------------------------------------------------------------------
REM  Inicia el servicio web usando el servidor embebido de PHP.
REM  Haga doble clic sobre este archivo o ejecutelo desde la consola.
REM
REM  Evidencia GA7-220501096-AA5-EV01
REM ===========================================================================

REM Se ubica la raiz del proyecto (carpeta superior a \scripts).
cd /d "%~dp0\.."

echo.
echo ============================================================
echo  Servicio Web de Registro y Autenticacion
echo ============================================================
echo.

REM Se busca el interprete de PHP: primero en el PATH, luego en XAMPP.
where php >nul 2>nul
if %errorlevel%==0 (
    set "PHP_EXE=php"
) else (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_EXE=C:\xampp\php\php.exe"
    ) else (
        echo [ERROR] No se encontro PHP. Instale XAMPP o agregue PHP al PATH.
        pause
        exit /b 1
    )
)

echo  Servidor:  http://localhost:8000
echo  Cliente:   http://localhost:8000/cliente/index.html
echo  API:       http://localhost:8000/api
echo.
echo  Presione CTRL + C para detener el servidor.
echo ============================================================
echo.

REM -t public   -> la raiz publica es la carpeta public
REM public\index.php -> actua como enrutador (front controller)
"%PHP_EXE%" -S localhost:8000 -t public public\index.php
