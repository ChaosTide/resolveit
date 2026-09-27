$php = 'C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe'
$project = Split-Path -Parent $MyInvocation.MyCommand.Path
# Also launched hidden at Windows sign-in by the "CTS Ticket System" shortcut in the Startup folder.
if (Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue) {
    Write-Output 'The ticket system is already running at http://127.0.0.1:8080'
    exit 0
}
& $php -c "$project\runtime\php.ini" -S 127.0.0.1:8080 -t "$project\public"
