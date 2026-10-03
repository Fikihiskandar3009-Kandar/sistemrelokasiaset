<?php
/**
 * ==== PROFIL USER ====
 * Edit nama/email, ganti password, status koneksi Telegram
 * Akses: semua role | Terkait: api/telegram_connect.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
auth_require();

$user = auth_user();
$pdo  = db();
$message = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        if (!$name || !$email) {
            $error = 'Nama dan email tidak boleh kosong';
        } else {
            try {
                $pdo->prepare('UPDATE users SET name=?, email=? WHERE id=?')->execute([$name, $email, $user['id']]);
                $_SESSION['user']['name']  = $name;
                $_SESSION['user']['email'] = $email;
                $user = auth_user();
                $message = 'Profil berhasil diperbarui';
            } catch (Exception $e) { $error = 'Gagal: '.$e->getMessage(); }
        }
    }

    if ($action === 'change_password') {
        $old  = $_POST['old_password']     ?? '';
        $new  = $_POST['new_password']     ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if (!$old || !$new || !$conf) {
            $error = 'Semua field password harus diisi';
        } elseif ($new !== $conf) {
            $error = 'Password baru tidak cocok';
        } elseif (strlen($new) < 6) {
            $error = 'Password minimal 6 karakter';
        } else {
            $stmt = $pdo->prepare('SELECT password, password_hash FROM users WHERE id=?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();
            $stored = $row['password_hash'] ?? $row['password'] ?? '';
            $valid  = hash('sha256', $old) === $stored;
            if (!$valid) {
                $error = 'Password lama tidak sesuai';
            } else {
                try {
                    $newHash = hash('sha256', $new);
                    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                        ->execute([$newHash, $user['id']]);
                    $message = 'Password berhasil diubah';
                } catch (Exception $e) { $error = 'Gagal: '.$e->getMessage(); }
            }
        }
    }
}

$role_map = ['admin'=>'Administrator','spv'=>'Supervisor','mgr'=>'Manager'];
$role_label = $role_map[$user['role']] ?? ucfirst($user['role']);
$role_color = ['admin'=>'#1d4ed8','spv'=>'#065f46','mgr'=>'#92400e'][$user['role']] ?? '#374151';
$role_bg    = ['admin'=>'#dbeafe','spv'=>'#d1fae5','mgr'=>'#fef3c7'][$user['role']] ?? '#f3f4f6';
$initials   = user_initials($user);

// Ambil data terbaru dari DB supaya last_login dan created_at fresh
try {
    $fresh = $pdo->prepare("SELECT last_login, last_logout, created_at FROM users WHERE id = ?");
    $fresh->execute([$user['id']]);
    $freshData = $fresh->fetch();
    $user['last_login']  = $freshData['last_login']  ?? $user['last_login']  ?? null;
    $user['last_logout'] = $freshData['last_logout'] ?? null;
    $user['created_at']  = $freshData['created_at']  ?? $user['created_at']  ?? null;
} catch (Exception $e) { /* silent */ }

// Format tanggal bergabung
$hari_id = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
            'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$bln_id  = ['January'=>'Januari','February'=>'Februari','March'=>'Maret',
            'April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli',
            'August'=>'Agustus','September'=>'September','October'=>'Oktober',
            'November'=>'November','December'=>'Desember'];

function fmt_tgl($datetime) {
    global $hari_id, $bln_id;
    if (empty($datetime)) return 'Belum tercatat';
    $ts   = strtotime($datetime);
    $hari = $hari_id[date('l', $ts)] ?? date('l', $ts);
    $tgl  = date('d', $ts);
    $bln  = $bln_id[date('F', $ts)] ?? date('F', $ts);
    $thn  = date('Y', $ts);
    $jam  = date('H:i', $ts);
    return $hari . ', ' . $tgl . ' ' . $bln . ' ' . $thn . ', ' . $jam;
}

// Format tanggal tanpa jam (untuk hero banner)
function fmt_tgl_short($datetime) {
    global $hari_id, $bln_id;
    if (empty($datetime)) return 'Belum tercatat';
    $ts   = strtotime($datetime);
    $hari = $hari_id[date('l', $ts)] ?? date('l', $ts);
    $tgl  = date('d', $ts);
    $bln  = $bln_id[date('F', $ts)] ?? date('F', $ts);
    $thn  = date('Y', $ts);
    return $hari . ', ' . $tgl . ' ' . $bln . ' ' . $thn;
}

$join_date       = fmt_tgl($user['created_at'] ?? null);       // dengan jam (untuk detail akun)
$join_date_short = fmt_tgl_short($user['created_at'] ?? null); // tanpa jam (untuk hero)

// Format login terakhir: "Sabtu, 02 Mei 2026\nin - out : 08.00 - 10.00"
if (!empty($user['last_login'])) {
    $ts_in  = strtotime($user['last_login']);
    $hari   = $hari_id[date('l', $ts_in)] ?? date('l', $ts_in);
    $tgl    = date('d', $ts_in);
    $bln    = $bln_id[date('F', $ts_in)] ?? date('F', $ts_in);
    $thn    = date('Y', $ts_in);
    $jam_in = date('H.i', $ts_in);

    $jam_out = !empty($user['last_logout']) ? date('H.i', strtotime($user['last_logout'])) : 'aktif';

    $last_login = $hari . ', ' . $tgl . ' ' . $bln . ' ' . $thn
                . '<br><small style="color:var(--color-text-secondary);font-weight:500;">'
                . 'in - out : ' . $jam_in . ' - ' . $jam_out
                . '</small>';
} else {
    $last_login = 'Belum tercatat';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profil Saya - SRA Indomaret Parung</title>
<?php echo asset_css('css/layout-simple.css'); ?>
<?php echo asset_css('css/indomaret-theme.css'); ?>
<?php echo asset_css('css/dark-mode.css'); ?>
<?php echo asset_css('css/layout-override.css'); ?>
<?php echo_loading_css(); ?>
<style>
/* ─── Profile Page ─── */
.profile-wrap { padding: .75rem 1.5rem; max-width: 100%; }

/* Hero */
.prof-hero {
    background: linear-gradient(135deg, #0b5ea8 0%, #0284c7 55%, #0ea5e9 100%);
    border-radius: 12px; padding: 1.5rem 2rem; margin-bottom: 1.25rem;
    color: #fff; position: relative; overflow: hidden;
}
.prof-hero::after {
    content: ''; position: absolute; right: -60px; top: -60px;
    width: 220px; height: 220px; border-radius: 50%;
    background: rgba(255,255,255,.05); pointer-events: none;
}
.prof-hero-inner { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; position: relative; z-index: 1; }
.prof-avatar {
    width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #f4c300, #fbbf24);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 800; color: #0b5ea8;
    border: 3px solid rgba(255,255,255,.3);
}
.prof-hero-info { flex: 1; min-width: 160px; }
.prof-hero-name { font-size: 1.15rem; font-weight: 700; margin: 0 0 .25rem; }
.prof-hero-meta { font-size: .8rem; opacity: .85; margin-bottom: .4rem; }
.prof-role-badge {
    display: inline-block; padding: .2rem .75rem; border-radius: 20px;
    font-size: .72rem; font-weight: 700;
    background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.3);
}
.prof-hero-stats { display: flex; gap: 1.5rem; flex-wrap: wrap; }
.prof-stat { text-align: center; }
.prof-stat-val { font-size: .85rem; font-weight: 700; }
.prof-stat-lbl { font-size: .68rem; opacity: .7; text-transform: uppercase; letter-spacing: .5px; margin-top: .1rem; }

/* Alert */
.prof-alert {
    padding: .8rem 1rem; border-radius: 8px; margin-bottom: 1rem;
    border-left: 4px solid; font-size: .875rem; font-weight: 500;
    display: flex; align-items: center; gap: .5rem;
}
.prof-alert-ok  { background: #d1fae5; color: #065f46; border-color: #22c55e; }
.prof-alert-err { background: #fee2e2; color: #dc2626; border-color: #ef4444; }

/* Grid */
.prof-grid {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 1.25rem;
    align-items: start;
}

/* Card */
.prof-card {
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
}
.prof-card-head {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    display: flex; align-items: center; gap: .6rem;
}
.prof-card-head-title { font-weight: 700; font-size: .9rem; color: var(--color-text); }
.prof-card-head-sub   { font-size: .72rem; color: var(--color-text-secondary); margin-top: .1rem; }
.prof-card-body { padding: 1.1rem 1.25rem; }

/* Info list */
.info-list { display: flex; flex-direction: column; gap: .5rem; }
.info-row {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .6rem .85rem; border-radius: 8px;
    background: var(--color-bg, #f8fafc);
    border: 1px solid var(--color-border);
}
.info-lbl { font-size: .7rem; font-weight: 600; color: var(--color-text-secondary); text-transform: uppercase; letter-spacing: .4px; margin-bottom: .1rem; }
.info-val { font-size: .85rem; font-weight: 600; color: var(--color-text); }

/* Tabs */
.tab-bar { display: flex; border-bottom: 2px solid var(--color-border); margin-bottom: 1.1rem; }
.tab-btn {
    padding: .55rem 1.1rem; border: none; background: none; cursor: pointer;
    font-size: .85rem; font-weight: 600; color: var(--color-text-secondary);
    border-bottom: 2px solid transparent; margin-bottom: -2px;
    transition: all .2s; font-family: inherit;
}
.tab-btn.active { color: #0b5ea8; border-bottom-color: #0b5ea8; }
.tab-btn:hover:not(.active) { color: var(--color-text); }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Form */
.pf-form { display: flex; flex-direction: column; gap: .85rem; }
.pf-row  { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; }
.pf-grp  { display: flex; flex-direction: column; gap: .3rem; }
.pf-lbl  { font-size: .8rem; font-weight: 600; color: var(--color-text); }
.pf-inp  {
    padding: .6rem .85rem; border: 1.5px solid var(--color-border);
    border-radius: 8px; background: var(--color-bg); color: var(--color-text);
    font-size: .875rem; font-family: inherit; transition: border-color .2s, box-shadow .2s; width: 100%;
}
.pf-inp:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,.1); }
.pf-inp[readonly] { background: var(--color-bg-2, #f1f5f9); color: var(--color-text-secondary); cursor: not-allowed; }

/* Buttons */
.pf-btn {
    padding: .6rem 1.3rem; border: none; border-radius: 8px;
    font-weight: 700; font-size: .85rem; cursor: pointer;
    transition: all .2s; display: inline-flex; align-items: center; gap: .4rem;
    font-family: inherit;
}
.pf-btn-blue  { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #fff; }
.pf-btn-blue:hover  { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(14,165,233,.3); }
.pf-btn-green { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; }
.pf-btn-green:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(34,197,94,.3); }

/* Access table */
.access-row {
    display: flex; align-items: center; gap: .6rem;
    padding: .45rem .8rem; border-radius: 7px;
    background: var(--color-bg, #f8fafc); border: 1px solid var(--color-border);
    margin-bottom: .35rem;
}
.access-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.access-dot.on  { background: #22c55e; }
.access-dot.off { background: #d1d5db; }
.access-name { font-size: .82rem; font-weight: 600; color: var(--color-text); flex: 1; }
.access-desc { font-size: .72rem; color: var(--color-text-secondary); }

@media (max-width: 900px) {
    .prof-grid { grid-template-columns: 1fr; }
    .pf-row    { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .prof-hero-inner { flex-direction: column; text-align: center; }
    .prof-hero-stats { justify-content: center; }
}

/* ── Navigasi Skewed ── */
.nav-skew-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .5rem;
}
.nav-skew-btn {
    display: block;
    text-decoration: none !important;
    transform: skewX(-8deg);
    border-radius: 6px;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
    box-shadow: 2px 2px 0 rgba(0,0,0,.08);
}
.nav-skew-btn:hover {
    transform: skewX(-8deg) translateY(-2px);
    box-shadow: 3px 5px 0 rgba(0,0,0,.12);
    text-decoration: none !important;
}
.nav-skew-inner {
    display: block;
    padding: .55rem .7rem;
    background: var(--nb);
    color: var(--nc);
    font-size: .78rem;
    font-weight: 700;
    text-align: center;
    transform: skewX(8deg);
    letter-spacing: .2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Hak Akses Skewed ── */
.access-skew-list {
    display: flex;
    flex-direction: column;
    gap: .35rem;
}
.access-skew-item {
    transform: skewX(-6deg);
    border-radius: 6px;
    overflow: hidden;
    transition: transform .2s;
}
.access-skew-item:hover { transform: skewX(-6deg) translateX(3px); }
.access-skew-item.on  .access-skew-inner { background: rgba(34,197,94,.08); border-left: 3px solid #22c55e; }
.access-skew-item.off .access-skew-inner { background: rgba(209,213,219,.15); border-left: 3px solid #d1d5db; }
.access-skew-inner {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .45rem .85rem;
    transform: skewX(6deg);
}
.access-skew-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}
.access-skew-item.on  .access-skew-dot { background: #22c55e; }
.access-skew-item.off .access-skew-dot { background: #d1d5db; }
.access-skew-name {
    font-size: .82rem; font-weight: 600;
    color: var(--color-text); flex: 1;
}
.access-skew-desc {
    font-size: .72rem; color: var(--color-text-secondary);
}

@media (max-width: 768px) {
    .nav-skew-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body class="sidebar-hidden">
<?php $page_title='Profil Saya'; $page_icon=''; include __DIR__.'/app/header-sidebar.php'; ?>

<div class="content-area">
<div class="profile-wrap">

<?php if ($message): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showPopup('success', 'Berhasil', <?= json_encode($message) ?>);
});
</script>
<?php endif; ?>
<?php if ($error): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    showPopup('error', 'Error', <?= json_encode($error) ?>);
});
</script>
<?php endif; ?>

<!-- Hero Banner -->
<div class="prof-hero">
    <div class="prof-hero-inner">
        <div class="prof-avatar"><?= $initials ?></div>
        <div class="prof-hero-info">
            <div class="prof-hero-name"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
            <div class="prof-hero-meta"><?= htmlspecialchars($user['email'] ?? '-') ?> &nbsp;&middot;&nbsp; NIK: <?= htmlspecialchars($user['nik'] ?? '-') ?></div>
            <div class="prof-role-badge"><?= $role_label ?></div>
        </div>
        <div class="prof-hero-stats">
            <div class="prof-stat">
                <div class="prof-stat-val"><?= $join_date_short ?></div>
                <div class="prof-stat-lbl">Bergabung</div>
            </div>
            <div class="prof-stat">
                <div class="prof-stat-val"><?= !empty($user['last_login']) ? date('d M Y', strtotime($user['last_login'])) : '-' ?></div>
                <div class="prof-stat-lbl">Login Terakhir</div>
            </div>
            <div class="prof-stat">
                <div class="prof-stat-val">Aktif</div>
                <div class="prof-stat-lbl">Status</div>
            </div>
        </div>
    </div>
</div>

<!-- Main Grid -->
<div class="prof-grid">

<!-- Kolom kiri: Detail Akun + Navigasi (dengan tombol Edit & Ubah PW) -->
<div style="display:flex;flex-direction:column;gap:1.1rem;">

    <!-- Detail Akun -->
    <div class="prof-card">
        <div class="prof-card-head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b5ea8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <div>
                <div class="prof-card-head-title">Detail Akun</div>
                <div class="prof-card-head-sub">Informasi pengguna terdaftar</div>
            </div>
        </div>
        <div class="prof-card-body">
            <div class="info-list">
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Nama Lengkap</div>
                        <div class="info-val"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Email</div>
                        <div class="info-val"><?= htmlspecialchars($user['email'] ?? '-') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">NIK</div>
                        <div class="info-val"><?= htmlspecialchars($user['nik'] ?? '-') ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Jabatan / Role</div>
                        <div class="info-val">
                            <span style="background:<?= $role_bg ?>;color:<?= $role_color ?>;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;"><?= $role_label ?></span>
                        </div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Tanggal Bergabung</div>
                        <div class="info-val"><?= $join_date ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Login Terakhir</div>
                        <div class="info-val"><?= $last_login ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Status Akun</div>
                        <div class="info-val"><span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;">Aktif</span></div>
                    </div>
                </div>

                <?php
                // Ambil data telegram
                $tgStmt = $pdo->prepare("SELECT telegram_chat_id, auto_approve FROM users WHERE id = ?");
                $tgStmt->execute([$user['id']]);
                $tgInfo = $tgStmt->fetch(PDO::FETCH_ASSOC);
                $tgConnected = !empty($tgInfo['telegram_chat_id']);

                // Ambil Bot Token (masked) — TIDAK panggil Telegram API di PHP
                $profTokenFull = tg_token_for_role($user['role']);
                $profToken = $profTokenFull ? (substr($profTokenFull, 0, 10) . '***') : null;
                // profWebhook dimuat via JS AJAX agar tidak blokir render halaman
                $profWebhookRole = $user['role'];

                // Status runtime ngrok (ON/OFF) dari storage/integration_state.json
                $ngrokStateFile = __DIR__ . '/storage/integration_state.json';
                $ngrokState = is_file($ngrokStateFile) ? json_decode((string)@file_get_contents($ngrokStateFile), true) : null;
                $ngrokOn = !empty($ngrokState['ngrok_on']);
                ?>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Telegram ID</div>
                        <div class="info-val" style="font-family:monospace;"><?= $tgConnected ? htmlspecialchars($tgInfo['telegram_chat_id']) : '-' ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Bot API Token</div>
                        <div class="info-val" style="font-family:monospace;font-size:.8rem;"><?= $profToken ? htmlspecialchars($profToken) : '-' ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Koneksivitas</div>
                        <div class="info-val" style="font-size:.8rem;" id="prof-ngrok-val">
                            <span style="color:#9ca3af;">Memuat...</span>
                        </div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-content">
                        <div class="info-lbl">Status Telegram&nbsp;|&nbsp;Status Ngrok</div>
                        <div class="info-val" style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;">
                            <?php if ($tgConnected): ?>
                                <span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;">Terhubung</span>
                            <?php else: ?>
                                <span style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;">Belum Terhubung</span>
                            <?php endif; ?>
                            <span style="color:#cbd5e1;font-weight:700;">|</span>
                            <?php if ($ngrokOn): ?>
                                <span id="prof-ngrok-badge" style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;">Terhubung</span>
                            <?php else: ?>
                                <span id="prof-ngrok-badge" style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:10px;font-size:.78rem;font-weight:700;">Tidak Terhubung</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Tombol dihapus, pindah ke Navigasi -->
        </div>
    </div>

</div><!-- /kolom kiri -->

<!-- Kolom kanan: Navigasi (miring) + Info Sistem + Hak Akses (miring) -->
<div style="display:flex;flex-direction:column;gap:1.1rem;">

    <!-- Navigasi — style miring -->
    <div class="prof-card nav-skew-card">
        <div class="prof-card-head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b5ea8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <div>
                <div class="prof-card-head-title">Navigasi</div>
                <div class="prof-card-head-sub">Akses cepat ke halaman utama</div>
            </div>
        </div>
        <div class="prof-card-body" style="padding:.85rem 1.1rem;">
            <div class="nav-skew-grid">
                <?php foreach ([
                    ['dashboard.php',       'Dashboard',           '#0b5ea8','#dbeafe','#1d4ed8'],
                    ['assets.php',          'Data Aset',           '#16a34a','#d1fae5','#15803d'],
                    ['requests.php',        'Permintaan',          '#d97706','#fef3c7','#b45309'],
                    ['reports.php',         'Laporan',             '#7c3aed','#ede9fe','#6d28d9'],
                    ['settings.php',        'Pengaturan',          '#475569','#f1f5f9','#334155'],
                    ['settings.php#profil', 'Edit Profil',         '#0891b2','#cffafe','#0e7490'],
                    ['settings.php#password','Ubah Password',      '#059669','#d1fae5','#047857'],
                    ['logout.php',          'Keluar',              '#dc2626','#fee2e2','#b91c1c'],
                ] as [$href,$label,$color,$bg,$hover]): ?>
                <a href="<?= $href ?>" class="nav-skew-btn" style="--nb:#<?= ltrim($bg,'#') ?>;--nc:<?= $color ?>;--nh:<?= $hover ?>;">
                    <span class="nav-skew-inner"><?= $label ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Info Sistem -->
    <div class="prof-card">
        <div class="prof-card-head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b5ea8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            <div>
                <div class="prof-card-head-title">Info Sistem</div>
                <div class="prof-card-head-sub">Informasi aplikasi yang sedang berjalan</div>
            </div>
        </div>
        <div class="prof-card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                <?php foreach ([
                    ['Perusahaan',  'PT Indomarco Prismatama'],
                    ['Aplikasi',    'Sistem Relokasi Aset GA'],
                    ['Versi',       '1.0.0'],
                    ['Platform',    'Web Based (PHP)'],
                    ['Tanggal',     date('d M Y')],
                    ['Durasi Sesi', '<span id="live-clock">00:00:00</span>'],
                ] as [$lbl,$val]): ?>
                <div style="padding:.6rem .85rem;border-radius:8px;background:var(--color-bg,#f8fafc);border:1px solid var(--color-border);">
                    <div style="font-size:.68rem;color:var(--color-text-secondary);text-transform:uppercase;letter-spacing:.4px;margin-bottom:.2rem;"><?= $lbl ?></div>
                    <div style="font-size:.85rem;font-weight:600;color:var(--color-text);"><?= $val ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Hak Akses — style miring -->
    <div class="prof-card">
        <div class="prof-card-head">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0b5ea8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <div>
                <div class="prof-card-head-title">Hak Akses</div>
                <div class="prof-card-head-sub">Fitur tersedia untuk role <strong><?= $role_label ?></strong></div>
            </div>
        </div>
        <div class="prof-card-body" style="padding:.85rem 1.1rem;">
            <?php
            $access_map = [
                'admin' => [
                    [true,'Dashboard','Akses penuh'],
                    [true,'Data Aset','View, Tambah, Edit, Hapus, Import'],
                    [true,'Buat Permintaan','Buat permintaan relokasi'],
                    [true,'Lihat Permintaan','Semua permintaan'],
                    [true,'Generate Laporan','Akses penuh'],
                    [true,'Pengaturan Sistem','Akses penuh'],
                ],
                'spv' => [
                    [true,'Dashboard','Akses'],
                    [true,'Data Aset','View only'],
                    [false,'Buat Permintaan','Tidak tersedia'],
                    [true,'Lihat Permintaan','Level SPV saja'],
                    [true,'Approve / Reject','Permintaan level SPV'],
                    [true,'Generate Laporan','Akses'],
                ],
                'mgr' => [
                    [true,'Dashboard','Akses'],
                    [true,'Data Aset','View only'],
                    [false,'Buat Permintaan','Tidak tersedia'],
                    [true,'Lihat Permintaan','Level MGR saja'],
                    [true,'Approve / Reject','Permintaan level MGR'],
                    [true,'Generate Laporan','Akses'],
                ],
            ];
            ?>
            <div class="access-skew-list">
                <?php foreach ($access_map[$user['role']] ?? [] as [$on,$name,$desc]): ?>
                <div class="access-skew-item <?= $on ? 'on' : 'off' ?>">
                    <div class="access-skew-inner">
                        <span class="access-skew-dot"></span>
                        <span class="access-skew-name"><?= $name ?></span>
                        <span class="access-skew-desc"><?= $desc ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div><!-- /kolom kanan -->
</div><!-- /prof-grid -->

</div><!-- /profile-wrap -->
</div><!-- /content-area -->

<!-- Modal: Edit Profil -->
<div id="modal-edit" class="prof-modal-overlay" onclick="if(event.target===this)closeModal('modal-edit')">
    <div class="prof-modal">
        <div class="prof-modal-head">
            <span style="font-weight:700;font-size:.95rem;color:var(--color-text);">Edit Profil</span>
            <button onclick="closeModal('modal-edit')" class="prof-modal-close">&times;</button>
        </div>
        <form method="POST" class="pf-form">
            <input type="hidden" name="action" value="update_profile">
            <div class="pf-row">
                <div class="pf-grp">
                    <label class="pf-lbl">Nama Lengkap</label>
                    <input type="text" name="name" class="pf-inp" required value="<?= htmlspecialchars($user['name'] ?? '') ?>">
                </div>
                <div class="pf-grp">
                    <label class="pf-lbl">Email</label>
                    <input type="email" name="email" class="pf-inp" required value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                </div>
            </div>
            <div class="pf-row">
                <div class="pf-grp">
                    <label class="pf-lbl">NIK</label>
                    <input type="text" class="pf-inp" readonly value="<?= htmlspecialchars($user['nik'] ?? '-') ?>">
                </div>
                <div class="pf-grp">
                    <label class="pf-lbl">Role</label>
                    <input type="text" class="pf-inp" readonly value="<?= $role_label ?>">
                </div>
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.5rem;">
                <button type="button" onclick="closeModal('modal-edit')" class="pf-btn" style="background:var(--color-bg);color:var(--color-text);border:1.5px solid var(--color-border);">Batal</button>
                <button type="submit" class="pf-btn pf-btn-blue">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ubah Password -->
<div id="modal-pw" class="prof-modal-overlay" onclick="if(event.target===this)closeModal('modal-pw')">
    <div class="prof-modal">
        <div class="prof-modal-head">
            <span style="font-weight:700;font-size:.95rem;color:var(--color-text);">Ubah Password</span>
            <button onclick="closeModal('modal-pw')" class="prof-modal-close">&times;</button>
        </div>
        <form method="POST" class="pf-form">
            <input type="hidden" name="action" value="change_password">
            <div class="pf-grp">
                <label class="pf-lbl">Password Saat Ini</label>
                <input type="password" name="old_password" class="pf-inp" required placeholder="Masukkan password lama">
            </div>
            <div class="pf-grp">
                <label class="pf-lbl">Password Baru</label>
                <input type="password" name="new_password" id="new_pw" class="pf-inp" required placeholder="Minimal 6 karakter">
            </div>
            <div class="pf-grp">
                <label class="pf-lbl">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" id="conf_pw" class="pf-inp" required placeholder="Ulangi password baru">
                <div id="pw-match" style="font-size:.75rem;margin-top:.25rem;display:none;"></div>
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.5rem;">
                <button type="button" onclick="closeModal('modal-pw')" class="pf-btn" style="background:var(--color-bg);color:var(--color-text);border:1.5px solid var(--color-border);">Batal</button>
                <button type="submit" class="pf-btn pf-btn-green">Ubah Password</button>
            </div>
        </form>
    </div>
</div>

<style>
.prof-modal-overlay {
    display:none; position:fixed; inset:0; z-index:2000;
    background:rgba(0,0,0,.45); align-items:center; justify-content:center;
}
.prof-modal-overlay.open { display:flex; }
.prof-modal {
    background:var(--color-card); border-radius:14px;
    padding:1.5rem; width:100%; max-width:520px; margin:1rem;
    box-shadow:0 20px 50px rgba(0,0,0,.25);
    animation:modalIn .2s ease;
}
@keyframes modalIn { from{opacity:0;transform:translateY(-12px)} to{opacity:1;transform:none} }
.prof-modal-head {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:1.1rem; padding-bottom:.85rem;
    border-bottom:1px solid var(--color-border);
}
.prof-modal-close {
    background:none; border:none; font-size:1.4rem; cursor:pointer;
    color:var(--color-text-secondary); line-height:1; padding:0 4px;
}
.prof-modal-close:hover { color:var(--color-text); }
@media(max-width:768px) {
    .access-grid-3 { grid-template-columns:1fr 1fr !important; }
}
@media(max-width:480px) {
    .access-grid-3 { grid-template-columns:1fr !important; }
}
</style>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.prof-modal-overlay.open').forEach(function(m) {
            m.classList.remove('open');
            document.body.style.overflow = '';
        });
    }
});
(function() {
    var np = document.getElementById('new_pw');
    var cp = document.getElementById('conf_pw');
    var pm = document.getElementById('pw-match');
    function check() {
        if (!cp || !cp.value) { pm.style.display='none'; return; }
        pm.style.display = 'block';
        if (np.value === cp.value) { pm.style.color='#16a34a'; pm.textContent='Password cocok'; }
        else { pm.style.color='#dc2626'; pm.textContent='Password tidak cocok'; }
    }
    if (np && cp) { np.addEventListener('input', check); cp.addEventListener('input', check); }
})();
(function() {
    var loginTimestamp = <?= (int)($_SESSION['login_time'] ?? time()) ?>;
    var loginMs = loginTimestamp * 1000;
    function pad(n) { return n < 10 ? '0' + n : n; }
    function tick() {
        var e = Math.max(0, Math.floor((Date.now() - loginMs) / 1000));
        var el = document.getElementById('live-clock');
        if (el) el.textContent = pad(Math.floor(e/3600)) + ':' + pad(Math.floor((e%3600)/60)) + ':' + pad(e%60);
    }
    tick(); setInterval(tick, 1000);
})();

// ── Lazy load Ngrok/Webhook URL di halaman profil ──
(function() {
    // Sinkronkan badge Status Ngrok (row gabungan Status Telegram | Status Ngrok)
    function setNgrokBadge(ok) {
        var nb = document.getElementById('prof-ngrok-badge');
        if (!nb) return;
        nb.textContent = ok ? 'Terhubung' : 'Tidak Terhubung';
        nb.style.background = ok ? '#d1fae5' : '#fee2e2';
        nb.style.color = ok ? '#065f46' : '#991b1b';
    }
    fetch('api/telegram_setup.php?action=status')
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var url = '';
            if (res.ok && res.bots) {
                var botData = res.bots['admin'] || res.bots['spv'] || res.bots['mgr'];
                if (botData && botData.url) {
                    try { var p = new URL(botData.url); url = p.protocol + '//' + p.host; } catch(e) {}
                }
            } else if (res.ok && res.webhook && res.webhook.url) {
                try { var p = new URL(res.webhook.url); url = p.protocol + '//' + p.host; } catch(e) {}
            }
            setNgrokBadge(!!url);
            var el = document.getElementById('prof-ngrok-val');
            if (el) {
                el.innerHTML = url
                    ? '<span style="color:#0ea5e9;font-family:monospace;">' + url + '</span>'
                    : '<span style="color:#9ca3af;">-</span>';
            }
        })
        .catch(function() {
            setNgrokBadge(false);
            var el = document.getElementById('prof-ngrok-val');
            if (el) el.innerHTML = '<span style="color:#9ca3af;">-</span>';
        });
})();
<?php if ($message && strpos($message,'Password') !== false): ?>
openModal('modal-pw');
<?php elseif ($message): ?>
openModal('modal-edit');
<?php elseif ($error && stripos($error,'password') !== false): ?>
openModal('modal-pw');
<?php elseif ($error): ?>
openModal('modal-edit');
<?php endif; ?>
</script>
<?php echo asset_js('js/app-ui.js'); ?>
<?php echo_loading_js(); ?>
</body>
</html>