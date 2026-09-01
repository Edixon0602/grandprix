param(
    [int]$Steps = 20,
    [int]$IntervalSeconds = 2,
    [string]$Url = "http://localhost:8081/api/traccar-webhook.php"
)

$secret = "fe891bb0356b69a04fcbe286182b0357a9bff4d3a895be6f0d6a0c0e027e5b66"
$url = $Url

$lat = 10.711537
$lon = -71.683568

for ($i = 0; $i -lt $Steps; $i++) {
    $lat += 0.00035
    $lon += 0.00035
    $speed = 6 + (Get-Random -Minimum 0 -Maximum 22)
    $now = (Get-Date).ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss") + "+00:00"

    $body = @{
        deviceId   = 1
        latitude   = $lat
        longitude  = $lon
        speed      = $speed
        valid      = $true
        serverTime = $now
        fixTime    = $now
    } | ConvertTo-Json -Compress

    try {
        $resp = Invoke-RestMethod -Uri $url -Method Post `
            -Headers @{ "X-Grandprix-Webhook" = $secret } `
            -ContentType "application/json" -Body $body
        "Paso {0}/{1}  lat={2}  lon={3}  vel={4}  realtimeDelivered={5}" -f `
            ($i + 1), $Steps, ([math]::Round($lat, 6)), ([math]::Round($lon, 6)), $speed, $resp.realtimeDelivered
    } catch {
        "Error en paso $($i + 1): $($_.Exception.Message)"
    }

    Start-Sleep -Seconds $IntervalSeconds
}
