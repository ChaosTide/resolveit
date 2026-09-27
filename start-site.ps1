$php = 'C:\Users\draft\AppData\Local\CodexTicketSystem\php83\php.exe'
$project = Split-Path -Parent $MyInvocation.MyCommand.Path
& $php -c "$project\runtime\php.ini" -S 127.0.0.1:8080 -t "$project\public"

