@echo off
REM ============================================================
REM  dshphp local launcher - GBK encoded, CRLF line endings
REM  Uses php-local.ini to repair extension_dir on this machine.
REM  NEVER upload php-local.ini to the server.
REM ============================================================
chcp 936 >nul
setlocal enabledelayedexpansion

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"
set "HOST=127.0.0.1"
set "DOCROOT=%~dp0"
set "DOCROOT=%DOCROOT:~0,-1%"

REM ---------- 1. locate php.exe ----------
set "PHP="
REM primary: project-bundled PHP in ./php/ (self-contained local runtime)
if exist "%DOCROOT%\php\php.exe" set "PHP=%DOCROOT%\php\php.exe"
REM override if caller explicitly sets PHP_BIN
if not defined PHP if defined PHP_BIN if exist "%PHP_BIN%" set "PHP=%PHP_BIN%"
REM fallback to PATH
if not defined PHP for %%i in (php.exe) do if not defined PHP set "PHP=%%~$PATH:i"
if not defined PHP (
    for %%P in ("C://php//php.exe" "D://php//php.exe" "D://phptmp//php//php.exe" "C://Tools//php//php.exe") do (
        if not defined PHP if exist "%%~P" set "PHP=%%~P"
    )
)
if not defined PHP (
    echo [!] �Ҳ��� php.exe���밲װ PHP�������� PHP_BIN ��������ָ�� php.exe��
    pause
    exit /b 1
)

REM ---------- 2. local-only php.ini ----------
REM  Probe with goto labels instead of a parenthesised block: the PHP
REM  one-liner contains a closing bracket, which CMD mis-parses inside ( ).
set "LOCAL_INI=%DOCROOT%\php-local.ini"
set "INI_ARG="
if not exist "%LOCAL_INI%" goto :INI_DONE
"%PHP%" -c "%LOCAL_INI%" -r "exit(extension_loaded('openssl') && function_exists('curl_init') ? 0 : 1);" >nul 2>&1
if errorlevel 1 goto :INI_DONE
set INI_ARG=-c "%LOCAL_INI%"
:INI_DONE

echo [i] PHP         = "%PHP%"
echo [i] PHP_INI_ARG = !INI_ARG!
echo [i] DOCROOT     = "%DOCROOT%"

REM Use the same ini for -v, so the broken global php.ini never prints warnings.
"%PHP%" !INI_ARG! -v >nul 2>&1
if errorlevel 1 (
    echo [!] PHP �޷����������������·�������á�?
    pause
    exit /b 1
)

REM ---------- 3. port check ----------
netstat -ano | findstr /i /c:":!PORT! " | findstr /i "LISTENING" >nul
if not errorlevel 1 (
    echo [!] �˿� !PORT! �ѱ�ռ�á������˿����ԣ�run-local.bat 8082
    pause
    exit /b 1
)

REM ---------- 4. start server in its own minimised window ----------
REM  A separate console keeps the server alive after this window closes.
pushd "%DOCROOT%"
start "dshphp local server" /MIN "%PHP%" !INI_ARG! -S !HOST!:!PORT! -t "%DOCROOT%"
popd

REM ---------- 5. wait for the listener and capture the real PID ----------
set "SERVER_PID="
for /L %%N in (1,1,20) do (
    if not defined SERVER_PID (
        for /f "tokens=5" %%P in ('netstat -ano ^| findstr /i /c:":!PORT! " ^| findstr /i "LISTENING"') do set "SERVER_PID=%%P"
    )
    if not defined SERVER_PID ping -n 2 127.0.0.1 >nul
)
if not defined SERVER_PID (
    echo [!] ������δ���������ֶ����У�php -S !HOST!:!PORT! -t .
) else (
    > "%DOCROOT%\SERVER_PID.txt" echo !SERVER_PID!
    echo [i] ��������������http://!HOST!:!PORT!/index.php   PID=!SERVER_PID!
)

REM ---------- 6. open browser ----------
if /I "%DSH_NO_BROWSER%"=="1" goto :NO_BROWSER
start "" "http://!HOST!:!PORT!/index.php"
:NO_BROWSER
echo [i] �رձ���Ϊ dshphp local server ����С�����ڼ���ֹͣ����
echo [i] ���� DSH_NO_BROWSER=1 �������Զ����������?
endlocal
exit /b 0
