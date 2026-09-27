# Starts the ticket system at http://127.0.0.1:8080 on Apache (see deploy\apache).
# -Dev uses PHP's built-in test server instead, e.g. if Apache is not installed.
param([switch]$Dev)
$php = 'C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe'
$httpd = 'C:\Users\draft\AppData\Local\CodexTicketSystem\Apache24\bin\httpd.exe'
$project = Split-Path -Parent $MyInvocation.MyCommand.Path
if (Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue) {
    Write-Output 'The ticket system is already running at http://127.0.0.1:8080'
    exit 0
}
if (-not $Dev -and (Test-Path $httpd)) {
    Write-Output 'Apache is serving the ticket system at http://127.0.0.1:8080 (close this window or press Ctrl+C to stop).'
    & $httpd
} else {
    & $php -c "$project\runtime\php.ini" -S 127.0.0.1:8080 -t "$project\public"
}
