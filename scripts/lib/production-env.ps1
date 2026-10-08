# Shared helpers for running artisan against PRODUCTION from your own machine (Render free has no shell).
# Dot-source it: . "$PSScriptRoot\lib\production-env.ps1"
#
# Secrets are read with a hidden prompt (never a parameter, so they are not in shell history), live only in
# this PowerShell process's environment, and are removed by Clear-ProductionEnv. Nothing is written to disk.
# Windows PowerShell 5.1 compatible.

$script:ProductionVars = @()

function Read-Secret([string]$Prompt) {
    $secure = Read-Host -Prompt $Prompt -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function Set-ProcessEnv([string]$Name, [string]$Value) {
    [Environment]::SetEnvironmentVariable($Name, $Value, 'Process')
    if ($script:ProductionVars -notcontains $Name) { $script:ProductionVars += $Name }
}

function Clear-ProductionEnv {
    foreach ($name in $script:ProductionVars) {
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }
    $script:ProductionVars = @()
    [GC]::Collect()
}

function Confirm-Typed([string]$Question, [string]$Expected = 'yes') {
    $answer = Read-Host "$Question Type '$Expected' to continue"
    return ($answer -ceq $Expected)
}

# Checks the repo state and asks for the Neon DIRECT url + APP_KEY. Returns the parsed url (for pg_dump) or
# throws. Sets APP_ENV=production, APP_DEBUG=false and the database settings for this process only.
function Initialize-ProductionEnv {
    $root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
    Set-Location $root

    if (Test-Path 'bootstrap\cache\config.php') {
        throw 'A cached config exists (bootstrap\cache\config.php), so env vars would be ignored. Run "php artisan config:clear" first.'
    }

    Write-Host ''
    Write-Host 'Paste the Neon DIRECT connection string (Neon Console > Connect, "Connection pooling" OFF).' -ForegroundColor Cyan
    Write-Host 'The input is hidden. It is not saved to disk or shell history.'
    $url = (Read-Secret 'Neon DIRECT url').Trim()

    if ($url -notmatch '^postgres(ql)?://[^:/@]+:[^@]+@[^/]+/[^?]+') {
        throw 'That does not look like a Postgres connection string (postgresql://user:password@host/db).'
    }
    $uri = [Uri]$url
    if ($uri.Host -like '*-pooler*') {
        throw 'That is the POOLED url. Migrations and pg_dump need the DIRECT one (turn "Connection pooling" off in Neon).'
    }
    if ($uri.Host -notlike '*ap-southeast-1*') {
        if (-not (Confirm-Typed "Warning: host is not in ap-southeast-1 (Singapore). Is this really production?")) {
            throw 'Stopped.'
        }
    }
    if ($url -notmatch 'sslmode=') {
        $url += $(if ($url.Contains('?')) { '&' } else { '?' }) + 'sslmode=require'
    }

    Write-Host ''
    Write-Host 'Paste the production APP_KEY (from Bitwarden; starts with base64:). Hidden input.' -ForegroundColor Cyan
    Write-Host 'The app refuses to boot in production without it.'
    $appKey = (Read-Secret 'APP_KEY').Trim()
    if ($appKey -notlike 'base64:*') { throw 'APP_KEY must start with base64:' }

    Set-ProcessEnv 'APP_ENV' 'production'
    Set-ProcessEnv 'APP_DEBUG' 'false'
    Set-ProcessEnv 'APP_URL' 'https://crm.hybridmonks.com'
    Set-ProcessEnv 'APP_KEY' $appKey
    Set-ProcessEnv 'DB_CONNECTION' 'pgsql'
    Set-ProcessEnv 'DB_URL' $url
    # A url without a port would otherwise pick up DB_PORT from the local .env (e.g. 5434).
    Set-ProcessEnv 'DB_PORT' $(if ($uri.Port -gt 0) { [string]$uri.Port } else { '5432' })
    Set-ProcessEnv 'DB_SSLMODE' 'require'
    # Same stores as the live app, so cache clears (e.g. settings) reach production.
    Set-ProcessEnv 'CACHE_STORE' 'database'
    Set-ProcessEnv 'SESSION_DRIVER' 'database'
    # Logs to this console only; nothing written under storage/logs.
    Set-ProcessEnv 'LOG_CHANNEL' 'stderr'
    Set-ProcessEnv 'LOG_LEVEL' 'warning'
    # No email from these commands unless a script switches it on (crm:mail-test).
    Set-ProcessEnv 'MAIL_MAILER' 'array'

    $appKey = $null
    return $uri
}

# The first `php` on PATH may lack pdo_pgsql (e.g. Herd Lite); pick one that has it.
function Find-Php {
    $candidates = @()
    $onPath = Get-Command php -All -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath | ForEach-Object { $_.Source } }
    $candidates += 'D:\php84\php.exe'
    foreach ($php in $candidates) {
        if ((Test-Path $php) -and ((& $php -m) -contains 'pdo_pgsql')) { return $php }
    }
    throw 'No PHP with the pdo_pgsql extension found. See README.md "Local setup" (Windows).'
}

function Invoke-Artisan {
    if (-not $script:Php) { $script:Php = Find-Php }
    & $script:Php artisan @args --no-ansi
    if ($LASTEXITCODE -ne 0) { throw "php artisan $($args -join ' ') failed (exit $LASTEXITCODE)." }
}
