<?php
/**
 * ==== PENGATURAN ====
 * Profil & password pribadi + panel integrasi Telegram/Ngrok. Konfigurasi global (webhook, token ngrok) hanya admin; kartu dirender js/integration-panel.js
 * Akses: admin, spv, mgr | Terkait: api/integration.php, js/integration-panel.js
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
auth_require();

$user = auth_user();

// Semua role bisa akses
$user_role    = $user['role'] ?? '';
$allowed_roles = ['admin', 'spv', 'mgr'];
if (!in_array($user_role, $allowed_roles)) {
    header('Location: dashboard.php'); exit;
}

$conn = db();
$message = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $initials = strtoupper(trim($_POST['initials'] ?? ''));
        $initials = substr(preg_replace('/[^A-Z]/', '', $initials), 0, 3);

        if ($name && $email) {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, initials=? WHERE id=?");
            if ($stmt->execute([$name, $email, $initials ?: null, $user['id']])) {
                $_SESSION['user']['name']     = $name;
                $_SESSION['user']['email']    = $email;
                $_SESSION['user']['initials'] = $initials;
                $user = auth_user();
                $message = 'Profil berhasil diperbarui';
            } else { $message = 'Gagal memperbarui profil.'; $msg_type = 'error'; }
        } else { $message = 'Nama dan email tidak boleh kosong.'; $msg_type = 'error'; }

    } elseif (isset($_POST['change_password'])) {
        $cur  = $_POST['current_password']  ?? '';
        $new  = $_POST['new_password']       ?? '';
        $conf = $_POST['confirm_password']   ?? '';
        if (!$cur || !$new || !$conf) {
            $message = 'Semua field wajib diisi.'; $msg_type = 'error';
        } elseif ($new !== $conf) {
            $message = 'Password baru tidak cocok.'; $msg_type = 'error';
        } elseif (strlen($new) < 8) {
            $message = 'Password baru minimal 8 karakter.'; $msg_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt->execute([$user['id']]);
            $hash = $stmt->fetchColumn();
            if (hash('sha256', $cur) === $hash) {
                $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE id=?");
                if ($stmt->execute([hash('sha256', $new), $user['id']])) {
                    $message = 'Password berhasil diubah';
                } else { $message = 'Gagal mengubah password.'; $msg_type = 'error'; }
            } else { $message = 'Password saat ini salah.'; $msg_type = 'error'; }
        }
    }
}

$role_labels = ['admin' => 'Administrator', 'spv' => 'Supervisor', 'mgr' => 'Manager'];
$role_label  = $role_labels[$user['role']] ?? ucfirst($user['role']);

// Status integrasi Telegram & Ngrok untuk kartu ringkas
try {
    $tgStmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
    $tgStmt->execute([$user['id']]);
    $settingsChatId = $tgStmt->fetchColumn();
} catch (Exception $e) { $settingsChatId = null; }
$settingsTgConnected = !empty($settingsChatId);
$settingsNgrokOnline = integration_ngrok_is_running();
$settingsNgrokDomain = integration_ngrok_domain();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pengaturan Sistem - SRA Indomaret Parung</title>
<?php echo asset_css('css/layout-simple.css'); ?>
<?php echo asset_css('css/indomaret-theme.css'); ?>
<?php echo asset_css('css/dark-mode.css'); ?>
<?php echo asset_css('css/layout-override.css'); ?>
<?php echo_loading_css(); ?>
<style>
/* ── Layout ── */
.settings-wrap { display:flex; flex-direction:column; gap:1.25rem; }
.settings-row  { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
.settings-row.single { grid-template-columns:1fr; }

/* ── Card ── */
.s-card {
    background:var(--color-card);
    border:1px solid var(--color-border);
    border-radius:14px;
    padding:1.25rem 1.5rem;
    box-shadow:0 2px 10px rgba(0,0,0,.06);
}
.s-card-header {
    display:flex; align-items:center; gap:.6rem;
    margin-bottom:1.25rem;
    padding-bottom:.9rem;
    border-bottom:2px solid var(--color-border);
}
.s-card-icon { font-size:1.4rem; }
.s-card-title { font-size:1.05rem; font-weight:700; color:var(--color-text); }
.s-card-sub   { font-size:.8rem; color:var(--color-text-secondary); margin-top:.1rem; }

/* ── User badge ── */
.user-badge {
    display:flex; align-items:center; gap:1rem;
    background:linear-gradient(135deg,rgba(11,94,168,.08),rgba(14,165,233,.04));
    border:1px solid rgba(14,165,233,.2);
    border-radius:10px; padding:1rem 1.25rem; margin-bottom:1.25rem;
}
.user-avatar-lg {
    width:52px; height:52px; border-radius:50%;
    background:linear-gradient(135deg,#0b5ea8,#0ea5e9);
    color:#fff; font-size:1.4rem; font-weight:800;
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0;
}
.user-badge-info { flex:1; }
.user-badge-name { font-weight:700; font-size:1rem; color:var(--color-text); }
.user-badge-meta { font-size:.8rem; color:var(--color-text-secondary); margin-top:.2rem; }
.role-pill {
    display:inline-block; padding:2px 10px; border-radius:20px;
    font-size:.75rem; font-weight:700;
}
.role-admin { background:#dbeafe; color:#1d4ed8; }
.role-spv   { background:#d1fae5; color:#065f46; }
.role-mgr   { background:#fef3c7; color:#92400e; }

/* ── Form ── */
.s-form { display:flex; flex-direction:column; gap:1rem; }
.s-form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.form-group { display:flex; flex-direction:column; gap:.4rem; }
.form-label { font-weight:600; font-size:.88rem; color:var(--color-text); }
.form-input {
    padding:.7rem .9rem; border:1px solid var(--color-border);
    border-radius:8px; background:var(--color-bg); color:var(--color-text);
    font-size:.9rem; font-family:inherit; transition:all .2s;
}
.form-input:focus { outline:none; border-color:#0ea5e9; box-shadow:0 0 0 3px rgba(14,165,233,.1); }
.form-input[readonly] { background:var(--color-bg-2,#f8fafc); color:var(--color-text-secondary); cursor:not-allowed; }

/* ── Buttons ── */
.btn-save {
    background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff;
    border:none; padding:.7rem 1.5rem; border-radius:8px;
    font-weight:700; font-size:.9rem; cursor:pointer;
    transition:all .2s; align-self:flex-start;
    display:inline-flex; align-items:center; gap:.4rem;
}
.btn-save:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(34,197,94,.3); }
.btn-primary-blue {
    background:linear-gradient(135deg,#0ea5e9,#0284c7); color:#fff;
    border:none; padding:.7rem 1.5rem; border-radius:8px;
    font-weight:700; font-size:.9rem; cursor:pointer;
    transition:all .2s; align-self:flex-start;
    display:inline-flex; align-items:center; gap:.4rem;
}
.btn-primary-blue:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(14,165,233,.3); }

/* ── Alert ── */
.alert { padding:.9rem 1.1rem; border-radius:8px; margin-bottom:1.25rem;
         border-left:4px solid; font-weight:500; font-size:.9rem; }
.alert-success { background:#d1fae5; color:#065f46; border-color:#22c55e; }
.alert-error   { background:#fee2e2; color:#dc2626; border-color:#ef4444; }

/* ── Company profile ── */
.company-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.company-item {
    background:var(--color-bg,#f8fafc); border-radius:10px;
    padding:1rem 1.1rem; border:1px solid var(--color-border);
}
.company-item-icon { font-size:1.5rem; margin-bottom:.4rem; }
.company-item-title { font-weight:700; font-size:.88rem; color:var(--color-text); margin-bottom:.3rem; }
.company-item-text  { font-size:.82rem; color:var(--color-text-secondary); line-height:1.5; }
.company-item-text ul { margin:.3rem 0 0 1rem; padding:0; }
.company-item-text li { margin-bottom:.2rem; }
.company-full { grid-column:1/-1; }

/* ── Org chart ── */
.org-tree { display:flex; flex-direction:column; gap:.5rem; }
.org-head {
    background:linear-gradient(135deg,#0b5ea8,#0ea5e9); color:#fff;
    border-radius:8px; padding:.6rem 1rem; font-weight:700; font-size:.88rem;
    text-align:center;
}
.org-children { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; margin-top:.25rem; }
.org-child {
    background:var(--color-bg,#f8fafc); border:1px solid var(--color-border);
    border-radius:8px; padding:.5rem .75rem; font-size:.78rem;
    color:var(--color-text); text-align:center; line-height:1.4;
}
.org-child span { display:block; font-size:1rem; margin-bottom:.2rem; }

/* ── Divider ── */
.section-divider {
    display:flex; align-items:center; gap:.75rem;
    font-size:.8rem; font-weight:700; color:var(--color-text-secondary);
    text-transform:uppercase; letter-spacing:.8px; margin:.25rem 0;
}
.section-divider::before, .section-divider::after {
    content:''; flex:1; height:1px; background:var(--color-border);
}

@media(max-width:768px) {
    .settings-row { grid-template-columns:1fr; }
    .s-form-row   { grid-template-columns:1fr; }
    .company-grid { grid-template-columns:1fr; }
    .org-children { grid-template-columns:repeat(2,1fr); }
}
</style>
</head>
<body class="sidebar-hidden">
<?php $page_title='Pengaturan Sistem'; $page_icon=''; include __DIR__.'/app/header-sidebar.php'; ?>

<div class="content-area">
<div style="max-width:100%;margin:0;padding:.75rem 1.5rem;">

    <!-- Page header -->
    <div style="margin-bottom:1rem;">
        <h1 style="font-size:1.4rem;font-weight:800;color:var(--color-text);margin-bottom:.2rem;">Pengaturan Sistem</h1>
        <p style="font-size:.85rem;color:var(--color-text-secondary);">Kelola profil akun dan keamanan</p>
    </div>

    <?php if ($message): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        showPopup('<?= $msg_type === 'success' ? 'success' : 'error' ?>', '<?= $msg_type === 'success' ? 'Berhasil' : 'Error' ?>', <?= json_encode($message) ?>);
    });
    </script>
    <?php endif; ?>

    <div class="settings-wrap">

        <!-- ── Baris 1: Profil + Password ── -->
        <div class="section-divider">Akun & Keamanan</div>
        <div class="settings-row">

            <!-- Profil -->
            <div class="s-card">
                <div class="s-card-header">
                    <span class="s-card-icon">◉</span>
                    <div>
                        <div class="s-card-title">Informasi Profil</div>
                        <div class="s-card-sub">Data akun yang terdaftar di sistem</div>
                    </div>
                </div>

                <!-- Badge user -->
                <div class="user-badge">
                    <div class="user-avatar-lg"><?= user_initials($user) ?></div>
                    <div class="user-badge-info">
                        <div class="user-badge-name"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
                        <div class="user-badge-meta">
                            <?= htmlspecialchars($user['email'] ?? '-') ?> &nbsp;·&nbsp;
                            <span class="role-pill role-<?= $user['role'] ?>"><?= $role_label ?></span>
                        </div>
                        <div class="user-badge-meta" style="margin-top:.3rem;">
                            NIK: <?= htmlspecialchars($user['nik'] ?? '-') ?>
                            &nbsp;·&nbsp; Login terakhir: <?= date('d/m/Y H:i', strtotime($user['last_login'] ?? 'now')) ?>
                        </div>
                    </div>
                </div>

                <form method="POST" class="s-form">
                    <!-- Preview avatar inisial -->
                    <div style="display:flex;align-items:center;gap:1rem;padding:.75rem 1rem;
                                background:var(--color-bg,#f8fafc);border-radius:10px;
                                border:1px solid var(--color-border);margin-bottom:.25rem;">
                        <div id="avatar-preview" style="width:52px;height:52px;border-radius:50%;
                            background:linear-gradient(135deg,#f4c300,#fbbf24);
                            display:flex;align-items:center;justify-content:center;
                            font-size:1.1rem;font-weight:800;color:#0b5ea8;flex-shrink:0;
                            border:3px solid rgba(11,94,168,.2);">
                            <?= htmlspecialchars($user['initials'] ?? strtoupper(substr($user['name']??'U',0,2))) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:.88rem;color:var(--color-text);">Avatar Profil</div>
                            <div style="font-size:.75rem;color:var(--color-text-secondary);">Inisial tampil di semua halaman</div>
                        </div>
                    </div>

                    <div class="s-form-row">
                        <div class="form-group">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" name="name" class="form-input" required
                                   value="<?= htmlspecialchars($user['name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-input" required
                                   value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="s-form-row">
                        <div class="form-group">
                            <label class="form-label">NIK</label>
                            <input type="text" class="form-input" readonly
                                   value="<?= htmlspecialchars($user['nik'] ?? '-') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-input" readonly value="<?= $role_label ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Inisial Profil <span style="font-size:.75rem;color:var(--color-text-secondary);font-weight:400;">(maks. 3 huruf, tampil sebagai avatar)</span></label>
                        <div style="display:flex;align-items:center;gap:.75rem;">
                            <input type="text" name="initials" id="initials-input"
                                   class="form-input" maxlength="3"
                                   placeholder="Misal: ADM"
                                   style="max-width:120px;text-transform:uppercase;font-weight:700;font-size:1rem;text-align:center;letter-spacing:2px;"
                                   value="<?= htmlspecialchars($user['initials'] ?? strtoupper(substr($user['name']??'',0,2))) ?>">
                            <span style="font-size:.8rem;color:var(--color-text-secondary);">← Ketik untuk lihat preview avatar di atas</span>
                        </div>
                    </div>
                    <button type="submit" name="update_profile" class="btn-save">Simpan Perubahan</button>
                </form>
            </div>

            <!-- Password -->
            <div class="s-card">
                <div class="s-card-header">
                    <span class="s-card-icon">◈</span>
                    <div>
                        <div class="s-card-title">Ubah Password</div>
                        <div class="s-card-sub">Pastikan password baru minimal 8 karakter</div>
                    </div>
                </div>

                <form method="POST" class="s-form">
                    <div class="form-group">
                        <label class="form-label">Password Saat Ini</label>
                        <input type="password" name="current_password" class="form-input"
                               placeholder="Masukkan password lama" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password Baru</label>
                        <input type="password" name="new_password" class="form-input" id="new_pass"
                               placeholder="Minimal 8 karakter" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Konfirmasi Password Baru</label>
                        <input type="password" name="confirm_password" class="form-input" id="conf_pass"
                               placeholder="Ulangi password baru" required>
                    </div>
                    <div id="pass-match" style="font-size:.8rem;display:none;"></div>
                    <button type="submit" name="change_password" class="btn-primary-blue">Ubah Password</button>
                </form>
            </div>
        </div>

        <!-- ── Baris 2: Profil Perusahaan ── -->
        <div class="section-divider">Informasi Sistem</div>
        <div class="settings-row single">
            <div class="s-card">
                <div class="s-card-header">
                    <span class="s-card-icon">▣</span>
                    <div>
                        <div class="s-card-title">Info Aplikasi</div>
                        <div class="s-card-sub">Versi dan detail sistem yang sedang berjalan</div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;">
                    <?php foreach ([
                        ['Perusahaan','PT Indomarco Prismatama'],
                        ['Aplikasi','Sistem Relokasi Aset GA'],
                        ['Versi','1.0.0'],
                        ['Platform','Web Based (PHP)'],
                        ['Tanggal Server',date('d M Y')],
                        ['Zona Waktu','Asia/Jakarta (WIB)'],
                    ] as [$lbl,$val]): ?>
                    <div style="padding:.7rem 1rem;border-radius:8px;background:var(--color-bg,#f8fafc);border:1px solid var(--color-border);">
                        <div style="font-size:.7rem;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.2rem;"><?= $lbl ?></div>
                        <div style="font-size:.85rem;font-weight:700;color:var(--color-text);"><?= $val ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Baris 3: Integrasi Telegram & Ngrok/Webhook ── -->
        <div class="section-divider">Integrasi &mdash; Telegram &amp; Ngrok/Webhook</div>
        <div class="settings-row">
            <div class="s-card">
                <div class="s-card-header">
                    <span class="s-card-icon">&#128225;</span>
                    <div>
                        <div class="s-card-title">Telegram</div>
                        <div class="s-card-sub">Koneksi chat ID, token bot &amp; ping</div>
                    </div>
                    <span style="margin-left:auto;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:6px;background:<?= $settingsTgConnected ? '#d1fae5;color:#065f46' : '#fee2e2;color:#991b1b' ?>;">
                        <?= $settingsTgConnected ? 'TERHUBUNG' : 'BELUM TERHUBUNG' ?>
                    </span>
                </div>
                <div style="font-size:.82rem;color:var(--color-text-secondary);margin-bottom:1rem;line-height:1.6;">
                    <?php if ($settingsTgConnected): ?>
                        Chat ID Anda: <b style="font-family:monospace;color:var(--color-text);"><?= htmlspecialchars($settingsChatId) ?></b>
                    <?php else: ?>
                        Akun Anda belum menghubungkan Telegram. Buka panel untuk mengatur Chat ID &amp; token bot.
                    <?php endif; ?>
                </div>
                <button type="button" onclick="integrationOpen('telegram')" class="btn-primary-blue" style="align-self:flex-start;">Buka Panel Telegram</button>
            </div>

            <div class="s-card">
                <div class="s-card-header">
                    <span class="s-card-icon">&#128279;</span>
                    <div>
                        <div class="s-card-title">Ngrok / Webhook</div>
                        <div class="s-card-sub">Tunnel online/off, domain, authtoken &amp; webhook</div>
                    </div>
                    <span style="margin-left:auto;font-size:.72rem;font-weight:800;padding:3px 10px;border-radius:6px;background:<?= $settingsNgrokOnline ? '#d1fae5;color:#065f46' : '#f3f4f6;color:#6b7280' ?>;">
                        <?= $settingsNgrokOnline ? 'ONLINE' : 'OFFLINE' ?>
                    </span>
                </div>
                <div style="font-size:.82rem;color:var(--color-text-secondary);margin-bottom:1rem;line-height:1.6;">
                    <?php if ($settingsNgrokDomain): ?>
                        Domain: <b style="font-family:monospace;color:var(--color-text);"><?= htmlspecialchars($settingsNgrokDomain) ?></b>
                    <?php else: ?>
                        Domain ngrok belum diatur. Buka panel untuk mengisi domain, authtoken &amp; email.
                    <?php endif; ?>
                </div>
                <button type="button" onclick="integrationOpen('webhook')" class="btn-primary-blue" style="align-self:flex-start;">Buka Panel Ngrok/Webhook</button>
            </div>
        </div>

    </div><!-- /settings-wrap -->
</div>
</div>

<script>
// Real-time password match check
var np = document.getElementById('new_pass');
var cp = document.getElementById('conf_pass');
var pm = document.getElementById('pass-match');
function checkMatch() {
    if (!cp.value) { pm.style.display='none'; return; }
    pm.style.display = 'block';
    if (np.value === cp.value) {
        pm.style.color = '#16a34a';
        pm.textContent = 'Password cocok';
    } else {
        pm.style.color = '#dc2626';
        pm.textContent = 'Password tidak cocok';
    }
}
if (np && cp) { np.addEventListener('input', checkMatch); cp.addEventListener('input', checkMatch); }
</script>
<script>
// Preview inisial real-time
var initInput   = document.getElementById('initials-input');
var avatarPrev  = document.getElementById('avatar-preview');
if (initInput && avatarPrev) {
    initInput.addEventListener('input', function() {
        var val = this.value.toUpperCase().replace(/[^A-Z]/g,'').substring(0,3);
        this.value = val;
        avatarPrev.textContent = val || '?';
    });
}
</script>
<?php echo asset_js('js/app-ui.js'); ?>
<?php echo asset_js('js/integration-panel.js'); ?>
<?php echo_loading_js(); ?>
</body>
</html>
