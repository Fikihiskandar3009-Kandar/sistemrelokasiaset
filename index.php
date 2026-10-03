<?php
/**
 * ==== GERBANG APLIKASI (ROOT) ====
 * Selalu redirect ke halaman login.
 * Akses: publik
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';

// Always show login first when visiting the app root.
header('Location: login.php');
exit;
