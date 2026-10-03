# ============================================================
# Setup Ulang Webhook Telegram (3 bot) - untuk ngrok static domain
# Pemakaian:
#   .\setup_telegram_webhook.ps1 -Domain kamu-xyz.ngrok-free.app
#   .\setup_telegram_webhook.ps1 -Domain kamu-xyz.ngrok-free.app -Check
# Token & secret dibaca otomatis dari file .env di folder yang sama.
# ============================================================
param(
    [Parameter(Mandatory = $true)][string]$Domain,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
$envFile = Join-Path $PSScriptRoot '.env'
if (-not (Test-Path $envFile)) { Write-Error ".env tidak ditemukan di $envFile"; exit 1 }

# -- Parse .env --
$cfg = @{}
Get-Content $envFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -match '^([^#;=]+)=(.*)$') {
        $cfg[$matches[1].Trim()] = $matches[2].Trim()
    }
}

$secret = $cfg['TELEGRAM_WEBHOOK_SECRET']
$tokens = @{}
$tokens['admin'] = $cfg['TELEGRAM_BOT_TOKEN_ADMIN']
$tokens['spv']   = $cfg['TELEGRAM_BOT_TOKEN_SPV']
$tokens['mgr']   = $cfg['TELEGRAM_BOT_TOKEN_MGR']

if (-not $secret -or -not $tokens['admin']) {
    Write-Error 'TELEGRAM_WEBHOOK_SECRET / TELEGRAM_BOT_TOKEN_* tidak lengkap di .env'
    exit 1
}

# Buang skema & trailing slash kalau user menempel URL lengkap
$Domain = $Domain -replace '^https?://', '' -replace '/$', ''
$base = "https://$Domain/sistem_relokasi_aset_indomaret/api/telegram_webhook.php"

Write-Output "Base URL : $base"
Write-Output ('=' * 60)

foreach ($role in @('admin','spv','mgr')) {
    $tok = $tokens[$role]
    if (-not $tok) { Write-Output "[$role] token tidak ada di .env - skip"; continue }

    if ($Check) {
        # -- Mode cek status --
        try {
            $i = Invoke-RestMethod -Uri "https://api.telegram.org/bot$tok/getWebhookInfo" -TimeoutSec 15
            $lastErr = '-'
            if ($i.result.last_error_message) { $lastErr = $i.result.last_error_message }
            Write-Output ("[{0}] url     : {1}" -f $role, $i.result.url)
            Write-Output ("[{0}] pending : {1}  |  last_error: {2}" -f $role, $i.result.pending_update_count, $lastErr)
        } catch {
            Write-Output "[$role] GAGAL cek: $($_.Exception.Message)"
        }
    } else {
        # -- Mode daftar webhook --
        $webhookUrl = $base + '?secret=' + $secret + '&bot=' + $role
        $body = @{
            url                  = $webhookUrl
            allowed_updates      = '["message","callback_query"]'
            drop_pending_updates = 'false'
        }
        try {
            $r = Invoke-RestMethod -Uri "https://api.telegram.org/bot$tok/setWebhook" -Method Post -Body $body -TimeoutSec 15
            if ($r.ok) {
                Write-Output "[$role] OK - webhook terdaftar: $webhookUrl"
            } else {
                Write-Output "[$role] GAGAL: $($r.description)"
            }
        } catch {
            Write-Output "[$role] GAGAL: $($_.Exception.Message)"
        }
    }
    Write-Output ('-' * 60)
}

if (-not $Check) {
    Write-Output ''
    Write-Output 'Selesai. Verifikasi dengan: .\setup_telegram_webhook.ps1 -Domain <domain> -Check'
    Write-Output 'Lalu tes dari Telegram: kirim /status atau klik tombol - harus respons instan.'
}
