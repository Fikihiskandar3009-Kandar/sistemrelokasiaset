<?php
/**
 * ==== HALAMAN LOGIN ====
 * Form NIK + password. Cek tabel users JOIN roles (SHA-256), update last_login, kirim notif login.
 * Akses: publik | Terkait: app/auth.php (auth_login), forgot-password.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';

$existingUser = auth_user();

$flashError = '';
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (auth_login($_POST['nik'], $_POST['password'])) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'NIK atau password salah';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SRA Indomaret Parung</title>
    <?php echo asset_css('css/indomaret-theme.css'); ?>
    <?php echo asset_css('css/app-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/responsive-fix.css'); ?>
    <?php echo_loading_css(); ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0; padding: 16px;
            min-height: 100vh;
            background: #f1f5f9;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Inter', 'Segoe UI', sans-serif;
        }

        /* ── Card ── */
        .login-container {
            width: 100%;
            max-width: 400px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
            padding: 32px 32px 28px;
            position: relative;
            z-index: 1;
        }

        /* ── Logo ── */
        .logo {
            display: flex;
            justify-content: center;
            margin: 0;
        }
        .logo img {
            width: 100%;
            max-width: 260px;
            height: auto;
            filter: drop-shadow(0 3px 8px rgba(11,94,168,.25));
        }

        /* ── Heading ── */
        .login-container h2 {
            text-align: center;
            margin: 0;
            color: #111827;
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: -.3px;
        }

        /* ── Subtitle ── */
        .login-subtitle {
            text-align: center;
            color: #6b7280;
            font-size: .82rem;
            margin: 6px 0 18px;
            font-weight: 500;
        }

        /* ── Error ── */
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            border-left: 4px solid #dc2626;
            font-size: .85rem;
            font-weight: 500;
            animation: shake .4s;
        }
        @keyframes shake {
            0%,100% { transform:translateX(0); }
            25%      { transform:translateX(-8px); }
            75%      { transform:translateX(8px); }
        }

        /* ── Form ── */
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #374151;
            font-weight: 600;
            font-size: .82rem;
        }
        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: .9rem;
            background: #f9fafb;
            transition: all .25s;
            font-family: inherit;
        }
        .form-group input:focus {
            outline: none;
            border-color: #0ea5e9;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(14,165,233,.12);
        }

        /* ── Submit ── */
        button[type="submit"] {
            width: 100%;
            padding: 11px;
            background: #1a56db !important;
            color: #fff !important;
            border: none !important;
            border-radius: 8px !important;
            font-size: .93rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            margin-top: 6px !important;
            transition: background .2s, transform .15s, box-shadow .2s !important;
            font-family: inherit !important;
            text-align: center !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }
        button[type="submit"]:hover {
            background: #1447c0;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(26,86,219,.35);
        }
        button[type="submit"]:active {
            opacity: .9;
            transform: translateY(0);
            box-shadow: none;
        }

        /* ── Forgot link ── */
        .forgot-link {
            display: block;
            text-align: center;
            margin-top: 12px;
            font-size: .82rem;
            color: #0b5ea8;
            text-decoration: none;
            font-weight: 600;
        }
        .forgot-link:hover { text-decoration: underline; }

        /* Animasi halaman */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px) scale(.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes letterPop {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .login-container {
            animation: fadeUp .55s ease-out both;
        }
        .brand-letters span {
            display: inline-block;
            animation: letterPop .45s ease-out both;
        }
        .brand-letters span:nth-child(1) { animation-delay: .40s; }
        .brand-letters span:nth-child(2) { animation-delay: .45s; }
        .brand-letters span:nth-child(3) { animation-delay: .50s; }
        .brand-letters span:nth-child(4) { animation-delay: .55s; }
        .brand-letters span:nth-child(5) { animation-delay: .60s; }
        .brand-letters span:nth-child(6) { animation-delay: .65s; }
        .brand-letters span:nth-child(7) { animation-delay: .70s; }
        .brand-letters span:nth-child(8) { animation-delay: .75s; }
        .brand-letters span:nth-child(9) { animation-delay: .80s; }
        /* Matikan animasi bila user memilih reduce motion di OS
           (animasi .orb dimatikan lewat css/bg-anim.css) */
        @media (prefers-reduced-motion: reduce) {
            .login-container, .brand-letters span { animation: none; }
        }


        /* ── Responsive ── */
        @media (max-width: 480px) {
            body { padding: 12px; align-items: flex-start; padding-top: 40px; }
            .login-container { padding: 16px 18px 20px; border-radius: 16px; }
            .logo img { max-width: 240px; }
            .login-container h2 { font-size: 1.2rem; }
        }
    </style>
</head>
<body class="auth">
    <!-- Ornamen background melayang (murni dekoratif) -->
    <div class="orb orb-red" aria-hidden="true"></div>
    <div class="orb orb-blue" aria-hidden="true"></div>
    <div class="orb orb-yellow" aria-hidden="true"></div>
    <div class="login-container">
        <div class="logo">
            <img src="./images/logo-sra.png?v=<?= filemtime(__DIR__.'/images/logo-sra.png') ?>" alt="SRA - Sistem Relokasi Aset">
        </div>
        <p class="brand-letters" style="text-align:center;margin:0 0 4px;font-size:1.15rem;font-weight:800;
                  letter-spacing:4px;text-transform:uppercase;line-height:1;">
            <span style="-webkit-text-fill-color:#E31937;color:#E31937;">I</span><span style="-webkit-text-fill-color:#E31937;color:#E31937;">N</span><span style="-webkit-text-fill-color:#E31937;color:#E31937;">D</span><span style="-webkit-text-fill-color:#0b5ea8;color:#0b5ea8;">O</span><span style="-webkit-text-fill-color:#0b5ea8;color:#0b5ea8;">M</span><span style="-webkit-text-fill-color:#0b5ea8;color:#0b5ea8;">A</span><span style="-webkit-text-fill-color:#0b5ea8;color:#0b5ea8;">R</span><span style="-webkit-text-fill-color:#F4C300;color:#F4C300;">E</span><span style="-webkit-text-fill-color:#F4C300;color:#F4C300;">T</span>
        </p>
        <p class="login-subtitle">Silakan masuk dengan akun Anda</p>
        <?php if (!empty($existingUser)): ?>
            <div class="error">
                Anda sudah login sebagai <strong><?= htmlspecialchars($existingUser['name'] ?? '-') ?></strong>.
                <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="dashboard.php" style="text-decoration:none; padding:8px 12px; border-radius:10px; background:#0b5ea8; color:#fff; font-weight:600;">Ke Dashboard</a>
                    <a href="logout.php" style="text-decoration:none; padding:8px 12px; border-radius:10px; background:#dc2626; color:#fff; font-weight:600;">Logout</a>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error">! <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
            <?php if (!empty($flashError)): ?>
                <div class="error"><?= htmlspecialchars($flashError) ?></div>
            <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label for="nik">NIK Karyawan</label>
                <input type="text" id="nik" name="nik" required autofocus placeholder="Masukkan NIK Anda">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Masukkan password Anda">
            </div>
            <button type="submit">Masuk</button>
            <div style="text-align: center; margin-top: 14px;">
                <a href="forgot-password.php" style="color: #1a56db; text-decoration: none; font-weight: 500; font-size: 13px;">Lupa Password?</a>
            </div>
        </form>
    </div>
    <?php echo asset_js('js/app-ui.js'); ?>
    <?php echo_loading_js(); ?>
</body>
</html>