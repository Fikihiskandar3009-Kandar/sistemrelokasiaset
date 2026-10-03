<?php
/**
 * ==== HALAMAN RESET PASSWORD ====
 * Form password baru menggunakan token dari tabel password_reset
 * Akses: publik (butuh token valid)
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';

$error = '';
$success = '';

// Cek session
if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_email'])) {
    header('Location: forgot-password.php');
    exit;
}

// Cek timeout (1 jam)
if (time() - $_SESSION['reset_token_time'] > 3600) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_role'], $_SESSION['reset_token'], $_SESSION['reset_token_time']);
    header('Location: forgot-password.php?error=Token+expired');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $verifyEmail = $_POST['verify_email'] ?? '';
    
    // Validasi
    if (!$newPassword || !$confirmPassword || !$verifyEmail) {
        $error = 'Semua field harus diisi';
    } elseif ($verifyEmail !== $_SESSION['reset_email']) {
        $error = 'Email verifikasi tidak sesuai. Reset password gagal.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Password baru tidak cocok';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password minimal 6 karakter';
    } elseif (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $error = 'Password harus memiliki campuran huruf besar, huruf kecil, dan angka';
    } else {
        try {
            $pdo = db();
            
            // Update password
            $hashedPassword = hash('sha256', $newPassword);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hashedPassword, $_SESSION['reset_user_id']]);
            
            // Hapus reset token
            $stmt = $pdo->prepare('DELETE FROM password_reset WHERE user_id = ?');
            $stmt->execute([$_SESSION['reset_user_id']]);
            
            // Hapus session
            unset($_SESSION['reset_user_id'], $_SESSION['reset_email'], $_SESSION['reset_role'], $_SESSION['reset_token'], $_SESSION['reset_token_time']);
            
            $success = 'Password berhasil diubah! Silakan login dengan password baru.';
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
    <title>Reset Password - SRA Indomaret Parung</title>
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
        .rp-wrap {
            width: 100%; max-width: 900px;
            animation: fadeUp .5s ease;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:none} }
        .rp-logo { display:flex; justify-content:center; margin-bottom:1.25rem; }
        .rp-logo img { max-width:200px; width:100%; height:auto; }
        
        .rp-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .rp-card {
            background:#fff; border-radius:16px;
            box-shadow:0 20px 50px rgba(0,0,0,.2);
            padding:1.75rem 2rem;
        }
        .rp-title { font-size:1.15rem; font-weight:700; color:#0b3d6e; margin:0 0 .2rem; }
        .rp-sub   { font-size:.8rem; color:#6b7280; margin:0 0 1.25rem; }
        .rp-alert {
            padding:.75rem .9rem; border-radius:8px; margin-bottom:1rem;
            border-left:3px solid; font-size:.83rem; font-weight:500;
        }
        .rp-alert-err { background:#fee2e2; color:#991b1b; border-color:#dc2626; }
        .rp-alert-ok  { background:#dcfce7; color:#166534; border-color:#16a34a; }
        .rp-divider {
            font-size:.7rem; font-weight:700; color:#9ca3af;
            text-transform:uppercase; letter-spacing:.6px;
            border-top:1px solid #e5e7eb; padding-top:.75rem; margin:.75rem 0 .6rem;
        }
        .rp-group { margin-bottom:.85rem; }
        .rp-label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.3rem; }
        .rp-input {
            width:100%; padding:.65rem .9rem;
            border:1.5px solid #e5e7eb; border-radius:8px;
            font-size:.875rem; background:#f9fafb; color:#111827;
            transition:border-color .2s, box-shadow .2s; font-family:inherit;
        }
        .rp-input:focus {
            outline:none; border-color:#0ea5e9; background:#fff;
            box-shadow:0 0 0 3px rgba(14,165,233,.1);
        }
        .rp-hint {
            background:#f0f7ff; border-left:3px solid #0b5ea8;
            border-radius:7px; padding:.65rem .85rem;
            font-size:.78rem; color:#374151; margin-bottom:.85rem;
        }
        .rp-hint ul { margin:.3rem 0 0 1rem; padding:0; }
        .rp-hint li { margin-bottom:.2rem; }
        .rp-verify-note {
            background:#fffbeb; border:1px solid #fcd34d;
            border-radius:8px; padding:.65rem .85rem;
            font-size:.78rem; color:#92400e; margin-bottom:.85rem;
        }
        .rp-btn {
            width:100%; padding:.75rem;
            background:linear-gradient(135deg,#0b5ea8,#0ea5e9) !important;
            color:#fff !important; border:none !important; border-radius:8px !important;
            font-size:.9rem !important; font-weight:700 !important; cursor:pointer !important;
            transition:all .2s !important; font-family:inherit !important;
            box-shadow:0 4px 12px rgba(11,94,168,.25) !important;
            margin-bottom:.6rem !important;
            display:flex !important; align-items:center !important; justify-content:center !important;
        }
        .rp-btn:hover { transform:translateY(-1px) !important; box-shadow:0 6px 16px rgba(11,94,168,.35) !important; }
        .rp-links { display:flex; gap:.75rem; }
        .rp-link {
            flex:1; padding:.6rem; text-align:center;
            background:#f3f4f6; color:#0b5ea8; border:1.5px solid #e5e7eb;
            border-radius:8px; font-size:.8rem; font-weight:600;
            text-decoration:none; transition:all .2s;
        }
        .rp-link:hover { background:#e5e7eb; border-color:#0b5ea8; }
        .theme-toggle { display:none !important; }
        
        @media(max-width:768px) {
            .rp-grid { grid-template-columns: 1fr; }
        }
        @media(max-width:480px) {
            body { padding:12px; align-items:flex-start; padding-top:30px; }
            .rp-card { padding:1.25rem; }
        }
    </style>
</head>
<body class="auth">


    <div class="rp-wrap">
        <div class="rp-logo">
            <img src="./images/logo-sra.png?v=<?= filemtime(__DIR__.'/images/logo-sra.png') ?>" alt="SRA">
        </div>

        <?php if ($success): ?>
            <div class="rp-card">
                <div class="rp-alert rp-alert-ok"><?= htmlspecialchars($success) ?></div>
                <a href="login.php" class="rp-btn" style="display:flex !important;text-align:center;text-decoration:none;margin-top:.5rem;">Kembali ke Login</a>
            </div>
        <?php else: ?>

        <div class="rp-grid">
            <!-- Card 1: Password Baru -->
            <div class="rp-card">
                <h2 class="rp-title">Reset Password</h2>
                <p class="rp-sub">Buat password baru yang aman untuk akun Anda</p>

                <?php if ($error): ?>
                    <div class="rp-alert rp-alert-err"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" id="resetForm">
                    <div class="rp-group">
                        <label class="rp-label">Password Baru</label>
                        <input type="password" name="new_password" class="rp-input" required placeholder="Minimal 6 karakter">
                    </div>
                    <div class="rp-group">
                        <label class="rp-label">Konfirmasi Password Baru</label>
                        <input type="password" name="confirm_password" class="rp-input" required placeholder="Ulangi password baru">
                    </div>

                    <div class="rp-hint">
                        <strong style="display:block;margin-bottom:.35rem;color:#0b5ea8;font-size:.8rem;">Persyaratan Password</strong>
                        <ul>
                            <li>Minimal 6 karakter</li>
                            <li>Kombinasi huruf besar dan huruf kecil</li>
                            <li>Minimal 1 angka</li>
                        </ul>
                    </div>
                </form>
            </div>

            <!-- Card 2: Verifikasi -->
            <div class="rp-card">
                <h2 class="rp-title">Verifikasi Identitas</h2>
                <p class="rp-sub">Konfirmasi email Anda untuk keamanan</p>

                <div class="rp-verify-note">
                    Masukkan email yang terdaftar pada akun Anda untuk mengkonfirmasi perubahan password.
                </div>

                <div class="rp-group">
                    <label class="rp-label">Email Verifikasi</label>
                    <input type="email" name="verify_email" form="resetForm" class="rp-input" required
                           placeholder="<?= htmlspecialchars($_SESSION['reset_email'] ?? 'email@indomaret.com') ?>">
                </div>

                <button type="submit" form="resetForm" class="rp-btn">Simpan Password Baru</button>
                <div class="rp-links">
                    <a href="forgot-password.php" class="rp-link">&larr; Kembali</a>
                    <a href="login.php" class="rp-link">Halaman Login</a>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <?php echo asset_js('js/app-ui.js'); ?>
    <?php echo_loading_js(); ?>
</body>
</html>
