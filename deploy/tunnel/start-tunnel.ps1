# Publishes the local Apache site (http://127.0.0.1:8080) at a public https://*.trycloudflare.com link.
# Start the site first (start-site.ps1). The link works while this window stays open and changes each time.
# Uses a Cloudflare "quick tunnel": no Cloudflare account, no firewall or router changes.
$cloudflared = 'C:\Users\draft\AppData\Local\CodexTicketSystem\cloudflared\cloudflared.exe'
if (-not (Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue)) {
    Write-Output 'The site is not running. Run start-site.ps1 first.'
    exit 1
}
Write-Output 'Starting the tunnel. Look for the https://....trycloudflare.com link below and share it with your team.'
& $cloudflared tunnel --no-autoupdate --url http://127.0.0.1:8080
