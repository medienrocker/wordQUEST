# wordQUEST – lokaler Smoke-Test (statischer Server muss laufen)
# Nutzung:  python -m http.server 8080
#           .\scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8080/

param(
  [string] $BaseUrl = "http://127.0.0.1:8080/"
)

$ErrorActionPreference = "Stop"
$base = $BaseUrl.TrimEnd("/") + "/"

$paths = @(
  "",
  "index.html",
  "style.css",
  "manifest.webmanifest",
  "sw.js",
  "wordlists/index.json",
  "wordlists/food.json"
)

foreach ($p in $paths) {
  $uri = $base + $p
  $r = Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 20
  if ($r.StatusCode -ne 200) {
    throw "HTTP $($r.StatusCode) für $uri"
  }
  Write-Host "OK 200 $uri"
}

$html = (Invoke-WebRequest -Uri $base -UseBasicParsing -TimeoutSec 20).Content
$markers = @(
  "wordQUEST",
  "getActiveVocab",
  "manifest.webmanifest",
  "wq.srs.leitner.v1",
  "practice-due-count",
  "getDueWords"
)
foreach ($m in $markers) {
  if ($html.IndexOf($m, [StringComparison]::Ordinal) -lt 0) {
    throw "Fehlender Marker in index: $m"
  }
}

Write-Host "ALL CHECKS OK"
