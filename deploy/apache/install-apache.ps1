# One-time setup: points an extracted Apache Lounge build (Apache24 folder) at this project.
# Safe to run again. No admin rights needed; nothing is installed as a Windows service.
param([string]$ApacheDir = 'C:\Users\draft\AppData\Local\CodexTicketSystem\Apache24')
$ErrorActionPreference = 'Stop'
$conf = Join-Path $ApacheDir 'conf\httpd.conf'
$site = (Resolve-Path (Join-Path $PSScriptRoot 'cts4911.conf')).Path -replace '\\', '/'
$text = Get-Content $conf -Raw
$text = $text -replace '(?m)^Define SRVROOT ".*"', ('Define SRVROOT "' + ($ApacheDir -replace '\\', '/') + '"')
$text = $text -replace '(?m)^Listen 80\s*$', '# Listen 80  (disabled: the CTS 4911 site listens on 127.0.0.1:8080)'
if ($text -notmatch [regex]::Escape($site)) { $text = $text.TrimEnd() + "`r`n`r`n# CTS 4911 ticket system`r`nInclude `"$site`"`r`n" }
Set-Content -Path $conf -Value $text -Encoding ascii
& (Join-Path $ApacheDir 'bin\httpd.exe') -t
