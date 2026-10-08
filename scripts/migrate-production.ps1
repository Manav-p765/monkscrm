# Runs database migrations + the base seeder against PRODUCTION (Neon) from your machine.
# Render's free tier has no shell and no pre-deploy command, so this is the deploy step for schema changes.
# Steps and recovery: RUNBOOK.md, "Run migrations".
#
#   powershell -ExecutionPolicy Bypass -File scripts\migrate-production.ps1
#
# Never pass secrets as arguments: the script asks for them with hidden prompts and clears them on exit.

$ErrorActionPreference = 'Stop'
. "$PSScriptRoot\lib\production-env.ps1"

function Find-Tool([string]$Name, [string]$Fallback) {
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    if ($Fallback) {
        $found = Get-ChildItem $Fallback -ErrorAction SilentlyContinue |
            Sort-Object { [int]($_.Directory.Parent.Name -replace '\D', '') } -Descending |
            Select-Object -First 1
        if ($found) { return $found.FullName }
    }
    return $null
}

function Backup-WithPgDump([Uri]$Uri, [string]$PgDump, [string]$Age) {
    Write-Host ''
    Write-Host 'The backup is encrypted to the backup age PUBLIC key (starts with age1; it is not a secret).' -ForegroundColor Cyan
    Write-Host 'Only the matching private key (Bitwarden) can decrypt it.'
    $recipient = (Read-Host 'age public key').Trim()
    if ($recipient -notmatch '^age1[0-9a-z]{50,}$') { throw 'That is not an age public key (age1...).' }

    $dir = Join-Path $HOME 'monks-crm-backups'
    New-Item -ItemType Directory -Force $dir | Out-Null
    $file = Join-Path $dir ('neondb-production-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.dump.age')

    # libpq reads the connection from PG* variables, so the password is never on a command line.
    $user = $Uri.UserInfo.Split(':', 2)
    Set-ProcessEnv 'PGHOST' $Uri.Host
    Set-ProcessEnv 'PGPORT' $(if ($Uri.Port -gt 0) { [string]$Uri.Port } else { '5432' })
    Set-ProcessEnv 'PGUSER' ([Uri]::UnescapeDataString($user[0]))
    Set-ProcessEnv 'PGPASSWORD' ([Uri]::UnescapeDataString($user[1]))
    Set-ProcessEnv 'PGDATABASE' $Uri.AbsolutePath.TrimStart('/')
    Set-ProcessEnv 'PGSSLMODE' 'require'

    # cmd's pipe is binary-safe (Windows PowerShell 5.1's is not), so the dump is never on disk unencrypted.
    # Start-Process passes the command line verbatim. A pipe only reports the last command's exit code,
    # so pg_dump's errors go to a file: any output there means the dump failed.
    $errFile = Join-Path $dir 'pg_dump-errors.txt'
    Write-Host "Backing up to $file ..."
    $cmdLine = "/d /s /c `"`"$PgDump`" --format=custom --no-owner --no-privileges 2>`"$errFile`" | `"$Age`" --encrypt --recipient $recipient --output `"$file`"`""
    $proc = Start-Process cmd.exe -ArgumentList $cmdLine -NoNewWindow -Wait -PassThru
    $dumpErrors = if (Test-Path $errFile) { (Get-Content $errFile -Raw) } else { '' }
    Remove-Item $errFile -ErrorAction SilentlyContinue
    if ($proc.ExitCode -ne 0 -or $dumpErrors -or -not (Test-Path $file) -or (Get-Item $file).Length -lt 1024) {
        if ($dumpErrors) { Write-Host $dumpErrors -ForegroundColor Red }
        Remove-Item $file -ErrorAction SilentlyContinue
        throw 'Backup failed: no migration was run.'
    }
    Write-Host ("Backup written: {0} ({1:N0} KB, encrypted)." -f $file, ((Get-Item $file).Length / 1KB)) -ForegroundColor Green
}

function Backup-WithNeonBranch {
    $name = 'backup-before-migrate-' + (Get-Date -Format 'yyyyMMdd-HHmm')
    Write-Host ''
    Write-Host 'No usable pg_dump (version 18+) and age, so make a Neon branch as the backup:' -ForegroundColor Yellow
    Write-Host '  1. Neon Console > project "monkcrm" > Branches > New branch'
    Write-Host '  2. Parent: production. Include data up to: Current point in time.'
    Write-Host "  3. Name: $name"
    Write-Host '  4. Create. (Free plan: 10 branches; delete old backup-* branches once a deploy is confirmed good.)'
    Write-Host 'To take a real file backup next time: install PostgreSQL 18 client tools and age (winget install FiloSottile.age).'
    if (-not (Confirm-Typed "Created the branch '$name'?" 'branch created')) { throw 'Stopped: no backup, no migration.' }
}

# Dot-sourcing (tests) loads the functions only.
if ($MyInvocation.InvocationName -eq '.') { return }

try {
    Write-Host '== Monks CRM: production migration ==' -ForegroundColor Cyan
    $script:Php = Find-Php
    $uri = Initialize-ProductionEnv

    Write-Host ''
    Write-Host '== Current migration status (production) ==' -ForegroundColor Cyan
    & $script:Php artisan migrate:status --no-ansi
    # Exit code 1 here just means the migrations table does not exist yet (first deploy).

    if (-not (Confirm-Typed 'Migrate PRODUCTION (after a backup)?')) { throw 'Stopped: nothing changed.' }

    $pgDump = Find-Tool 'pg_dump' 'C:\Program Files\PostgreSQL\*\bin\pg_dump.exe'
    $age = Find-Tool 'age' $null
    $pgDumpOk = $false
    if ($pgDump) {
        $ver = (& $pgDump --version) -replace '^\D+(\d+).*$', '$1'
        $pgDumpOk = ([int]$ver -ge 18)
        if (-not $pgDumpOk) { Write-Host "pg_dump is version $ver; Neon runs 18, so it cannot dump it." -ForegroundColor Yellow }
    }
    if ($pgDumpOk -and $age) { Backup-WithPgDump $uri $pgDump $age } else { Backup-WithNeonBranch }

    Write-Host ''
    Write-Host '== Migrating ==' -ForegroundColor Cyan
    Invoke-Artisan migrate --force
    Write-Host '== Seeding reference data (statuses, sources, tags, settings; idempotent) ==' -ForegroundColor Cyan
    Invoke-Artisan db:seed --force

    $users = (& $script:Php artisan tinker --no-ansi --execute 'echo \App\Models\User::count();' | Select-Object -Last 1).Trim()
    if ($users -eq '0') {
        Write-Host ''
        Write-Host 'No users yet (first deploy).' -ForegroundColor Cyan
        if (Confirm-Typed 'Create the first admin now?') {
            Invoke-Artisan crm:create-admin
        }
    }

    Write-Host ''
    Write-Host '== Done. Status: ==' -ForegroundColor Green
    Invoke-Artisan migrate:status
}
catch {
    Write-Host ''
    Write-Host "Stopped: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'If the migration failed half way, see RUNBOOK.md "Run migrations > If it fails".'
    exit 1
}
finally {
    Clear-ProductionEnv
    Write-Host '(Production settings cleared from this PowerShell process.)'
}
