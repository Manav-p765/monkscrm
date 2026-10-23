# Runs ONE allowed artisan command against PRODUCTION from your machine (Render free has no shell).
#
#   powershell -ExecutionPolicy Bypass -File scripts\production-artisan.ps1 crm:mail-test you@hybridmonks.com
#   powershell -ExecutionPolicy Bypass -File scripts\production-artisan.ps1 crm:create-admin
#   powershell -ExecutionPolicy Bypass -File scripts\production-artisan.ps1 migrate:status
#
# Secrets are asked for with hidden prompts and cleared on exit. Schema changes: migrate-production.ps1.

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\lib\production-env.ps1"

$allowed = @('crm:mail-test', 'crm:create-admin', 'migrate:status')
$command = $args[0]
$rest = @($args | Select-Object -Skip 1)

if ($allowed -notcontains $command) {
    Write-Host "Usage: production-artisan.ps1 <command> [args]. Allowed: $($allowed -join ', ')" -ForegroundColor Red
    exit 1
}

try {
    $script:Php = Find-Php
    Initialize-ProductionEnv | Out-Null

    if ($command -eq 'crm:mail-test') {
        Write-Host ''
        Write-Host 'Paste the production RESEND_API_KEY (from Bitwarden). Hidden input.' -ForegroundColor Cyan
        Set-ProcessEnv 'RESEND_API_KEY' (Read-Secret 'RESEND_API_KEY').Trim()
        Set-ProcessEnv 'MAIL_MAILER' 'resend'
        Set-ProcessEnv 'MAIL_FROM_ADDRESS' 'noreply@hybridmonks.com'
        Set-ProcessEnv 'MAIL_FROM_NAME' 'HybridMonks CRM'
    }

    Write-Host ''
    Write-Host "== php artisan $command (production) ==" -ForegroundColor Cyan
    Invoke-Artisan $command @rest
}
catch {
    Write-Host "Stopped: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
finally {
    Clear-ProductionEnv
    Write-Host '(Production settings cleared from this PowerShell process.)'
}
