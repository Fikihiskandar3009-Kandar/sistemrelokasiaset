<?php
/**
 * ==== HALAMAN LUPA PASSWORD ====
 * User meminta reset password; token disimpan di tabel password_reset
 * Akses: publik | Terkait: forgot-password-reset.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';

$error = '';
$success = '';
$step = 1;
$tempData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik = $_POST['nik'] ?? '';
    $role = $_POST['role'] ?? '';
    
    if (!$nik || !$role) {
        $error = 'NIK dan Role harus diisi';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('
                SELECT u.id, u.nik, u.name, u.email, r.name as role 
                FROM users u 
                JOIN roles r ON r.id = u.role_id 
                WHERE u.nik = ? AND r.name = ? AND u.is_active = 1
            ');
            $stmt->execute([$nik, $role]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Generate token untuk reset password
                $resetToken = bin2hex(random_bytes(32));
                $tokenExpiry = date('Y-m-d H:i:s', time() + 3600); // 1 jam
                
                // Simpan token ke database
                $stmt = $pdo->prepare('
                    INSERT INTO password_reset (user_id, token, expires_at, created_at) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE token = ?, expires_at = ?, created_at = NOW()
                ');
                $stmt->execute([$user['id'], $resetToken, $tokenExpiry, $resetToken, $tokenExpiry]);
                
                // Simpan ke session untuk flow reset
                session_regenerate_id(true);
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['reset_nik'] = $nik;
                $_SESSION['reset_role'] = $role;
                $_SESSION['reset_token'] = $resetToken;
                $_SESSION['reset_token_time'] = time();
                
                header('Location: forgot-password-reset.php');
                exit;
            } else {
                $error = 'NIK atau Role tidak ditemukan di sistem';
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - SRA Indomaret Parung</title>
    <?php echo asset_css('css/indomaret-theme.css'); ?>
    <?php echo asset_css('css/app-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/responsive-fix.css'); ?>
    <?php echo_loading_css(); ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; padding: 20px;
            min-height: 100vh;
            background: linear-gradient(160deg, #0b3d6e 0%, #0b5ea8 40%, #0284c7 100%);
            display: flex; justify-content: center; align-items: center;
            font-family: 'Segoe UI', sans-serif;
        }
        .fp-card {
            width: 100%; max-width: 380px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(0,0,0,.2);
            padding: 2rem 2rem 1.75rem;
            animation: fadeUp .5s ease;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }
        .fp-logo { display:flex; justify-content:center; margin-bottom:1.25rem; }
        .fp-logo img { max-width:220px; width:100%; height:auto; }
        .fp-title { font-size:1.15rem; font-weight:700; color:#0b3d6e; text-align:center; margin:0 0 .25rem; }
        .fp-sub   { font-size:.8rem; color:#6b7280; text-align:center; margin:0 0 1.25rem; }
        .fp-error {
            background:#fee2e2; color:#991b1b; border-left:3px solid #dc2626;
            padding:.7rem .9rem; border-radius:7px; font-size:.82rem;
            font-weight:500; margin-bottom:1rem;
        }
        .fp-group { margin-bottom:.9rem; }
        .fp-label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.3rem; }
        .fp-input, .fp-select {
            width:100%; padding:.65rem .9rem;
            border:1.5px solid #e5e7eb; border-radius:8px;
            font-size:.875rem; background:#f9fafb; color:#111827;
            transition:border-color .2s, box-shadow .2s; font-family:inherit;
        }
        .fp-input:focus, .fp-select:focus {
            outline:none; border-color:#0ea5e9; background:#fff;
            box-shadow:0 0 0 3px rgba(14,165,233,.1);
        }
        .fp-btn {
            width:100%; padding:.75rem;
            background:linear-gradient(135deg,#0b5ea8,#0ea5e9) !important;
            color:#fff !important; border:none !important; border-radius:8px !important;
            font-size:.9rem !important; font-weight:700 !important; cursor:pointer !important;
            margin-top:.25rem !important; transition:all .2s !important; font-family:inherit !important;
            box-shadow:0 4px 12px rgba(11,94,168,.25) !important;
            display:flex !important; align-items:center !important; justify-content:center !important;
        }
        .fp-btn:hover { transform:translateY(-1px) !important; box-shadow:0 6px 16px rgba(11,94,168,.35) !important; }
        .theme-toggle { display:none !important; }
        .fp-back { text-align:center; margin-top:.9rem; }
        .fp-back a { color:#0b5ea8; text-decoration:none; font-size:.82rem; font-weight:600; }
        .fp-back a:hover { text-decoration:underline; }
        @media(max-width:480px) {
            body { padding:12px; align-items:flex-start; padding-top:40px; }
            .fp-card { padding:1.5rem 1.25rem; }
        }
    </style>
</head>
<body class="auth">

    <div class="fp-card">
        <div class="fp-logo">
            <img src="./images/logo-sra.png?v=<?= filemtime(__DIR__.'/images/logo-sra.png') ?>" alt="SRA - Sistem Relokasi Aset">
        </div>
        <h2 class="fp-title">Lupa Password</h2>
        <p class="fp-sub">Masukkan NIK dan role untuk melanjutkan reset password</p>

        <?php if ($error): ?>
            <div class="fp-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="fp-group">
                <label class="fp-label" for="nik">NIK Karyawan</label>
                <input type="text" id="nik" name="nik" class="fp-input" required autofocus placeholder="Contoh: 2015202548">
            </div>
            <div class="fp-group">
                <label class="fp-label" for="role">Role</label>
                <select id="role" name="role" class="fp-select" required>
                    <option value="">Pilih role Anda</option>
                    <option value="admin">Admin</option>
                    <option value="spv">Supervisor</option>
                    <option value="mgr">Manager</option>
                </select>
            </div>
            <button type="submit" class="fp-btn">Verifikasi &amp; Lanjutkan</button>
        </form>

        <div class="fp-back">
            <a href="login.php">&larr; Kembali ke halaman login</a>
        </div>
    </div>

    <?php echo asset_js('js/app-ui.js'); ?>
    <?php echo_loading_js(); ?>
</body>
</html>
