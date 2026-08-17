<#
    EMPOWER - start every part of the system.

    Opens one window per service so each keeps its own log, and stops early with
    a clear message if something is missing rather than letting a service fail
    silently in a hidden window.

    Usage:
        .\start.ps1            # API + frontend + OCR
        .\start.ps1 -NoOcr     # skip the OCR service
        .\start.ps1 -Stop      # stop everything

    Note: this file is deliberately plain ASCII. Windows PowerShell 5.1 reads a
    UTF-8 script without a BOM as ANSI, which turns any dash or accented
    character into mojibake and breaks parsing partway through the file.
#>

param(
    [switch]$NoOcr,
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

function Write-Ok($message)   { Write-Host "  $message" -ForegroundColor Green }
function Write-Warn($message) { Write-Host "  $message" -ForegroundColor Yellow }
function Write-Err($message)  { Write-Host "  $message" -ForegroundColor Red }

function Test-Port($port) {
    $null -ne (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
}

# ---------------------------------------------------------------------- stop

if ($Stop) {
    Write-Host ""
    Write-Host "Stopping EMPOWER" -ForegroundColor White
    Write-Host ""

    Get-Process php -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Write-Ok "Laravel API stopped"

    Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Write-Ok "Frontend stopped"

    Get-Process python -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Write-Ok "OCR service stopped"

    Write-Host ""
    exit 0
}

# ------------------------------------------------------------------- checks

Write-Host ""
Write-Host "EMPOWER - Recruitment and Deployment Management System" -ForegroundColor White
Write-Host "Checking prerequisites" -ForegroundColor White
Write-Host ""

# PHP 8.2 or newer. XAMPP ships 8.0, which Laravel 12 refuses to run on, and the
# resulting error is not obvious, so it is checked up front.
try {
    $phpVersion = (& php -r "echo PHP_VERSION;" 2>$null)
    $parts = $phpVersion.Split('.')

    if ([int]$parts[0] -lt 8 -or ([int]$parts[0] -eq 8 -and [int]$parts[1] -lt 2)) {
        Write-Err "PHP $phpVersion found, but Laravel 12 needs 8.2 or newer."
        Write-Warn "PHP 8.3 is installed at C:\php83. Put it ahead of XAMPP on your PATH."
        exit 1
    }
    Write-Ok "PHP $phpVersion"
} catch {
    Write-Err "PHP was not found on your PATH."
    exit 1
}

try {
    Write-Ok "Node $(node -v)"
} catch {
    Write-Err "Node.js was not found on your PATH."
    exit 1
}

if (-not (Test-Path (Join-Path $root 'backend\laravel\.env'))) {
    Write-Err "backend\laravel\.env is missing. Copy .env.example and fill in the Supabase settings."
    exit 1
}
Write-Ok "Environment file present"

if (-not (Test-Path (Join-Path $root 'frontend\node_modules'))) {
    Write-Warn "Frontend dependencies missing. Running npm install..."
    Push-Location (Join-Path $root 'frontend')
    & npm.cmd install
    Pop-Location
}
Write-Ok "Frontend dependencies present"

# The database is Supabase, so it is remote. A paused free-tier project is the
# most common cause of the application appearing broken, and it is worth finding
# out here rather than at the login screen.
Write-Host ""
Write-Host "Checking the database" -ForegroundColor White
Write-Host ""

Push-Location (Join-Path $root 'backend\laravel')
try {
    $probe = & php artisan db:show --json 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Ok "Supabase reachable"
    } else {
        Write-Warn "Could not reach Supabase."
        Write-Warn "A free-tier project pauses after about a week idle. Open the"
        Write-Warn "Supabase dashboard once to wake it, then run this script again."
    }
} catch {
    Write-Warn "Database check could not run; continuing anyway."
}
Pop-Location

# ------------------------------------------------------------------- launch

Write-Host ""
Write-Host "Starting services" -ForegroundColor White
Write-Host ""

if (Test-Port 8000) {
    Write-Warn "Port 8000 in use - leaving the existing API alone"
} else {
    # NOTE: PHP's built-in server handles one request at a time, so the
    # dashboard's three parallel calls queue behind each other. The usual
    # remedy, PHP_CLI_SERVER_WORKERS, needs fork() and therefore does
    # nothing on Windows - measured here at 9331ms sequential against
    # 8574ms 'concurrent', i.e. no gain. It is deliberately not set.
    # The fix that does work is fewer round trips to Supabase; see the
    # consolidated aggregates in DashboardController.
    $cmd = "Set-Location '$root\backend\laravel'; " +
           "Write-Host 'EMPOWER API on http://127.0.0.1:8000' -ForegroundColor Cyan; " +
           "php artisan serve --host=127.0.0.1 --port=8000"
    Start-Process powershell -ArgumentList '-NoExit', '-Command', $cmd
    Write-Ok "Laravel API      http://127.0.0.1:8000"
}

if (Test-Port 5173) {
    Write-Warn "Port 5173 in use - leaving the existing frontend alone"
} else {
    $cmd = "Set-Location '$root\frontend'; " +
           "Write-Host 'EMPOWER frontend on http://localhost:5173' -ForegroundColor Cyan; " +
           "npm run dev"
    Start-Process powershell -ArgumentList '-NoExit', '-Command', $cmd
    Write-Ok "Frontend         http://localhost:5173"
}

if (-not $NoOcr) {
    if (Test-Port 8001) {
        Write-Warn "Port 8001 in use - leaving the existing OCR service alone"
    } else {
        # Must match OCR_SERVICE_TOKEN in backend\laravel\.env, or Laravel's
        # requests are refused with a 401.
        $cmd = "Set-Location '$root\backend\ocr_service'; " +
               "`$env:OCR_SERVICE_TOKEN = 'empower-dev-token'; " +
               "Write-Host 'EMPOWER OCR on http://127.0.0.1:8001' -ForegroundColor Cyan; " +
               "Write-Host 'Each scan takes roughly 40s - oneDNN must stay off for PaddlePaddle 3.x.' -ForegroundColor DarkGray; " +
               "python -m uvicorn main:app --host 127.0.0.1 --port 8001"
        Start-Process powershell -ArgumentList '-NoExit', '-Command', $cmd
        Write-Ok "OCR service      http://127.0.0.1:8001"
    }
}

Write-Host ""
Write-Host "Open http://localhost:5173" -ForegroundColor White
Write-Host ""
Write-Host "  Administrator   admin@cdemanpower.local   ChangeMe123!" -ForegroundColor DarkGray
Write-Host "  HR staff        hr@cdemanpower.local      ChangeMe123!" -ForegroundColor DarkGray
Write-Host ""
Write-Host "Stop everything with:  .\start.ps1 -Stop" -ForegroundColor DarkGray
Write-Host ""
