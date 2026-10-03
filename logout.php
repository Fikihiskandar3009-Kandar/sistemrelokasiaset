<?php
/**
 * ==== HALAMAN LOGOUT ====
 * Hapus session, catat last_logout ke DB, kirim notif logout, redirect ke login.php
 * Akses: user login | Terkait: app/auth.php (auth_logout)
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
auth_logout();
header('Location: login.php');
exit;
