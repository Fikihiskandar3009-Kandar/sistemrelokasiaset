<?php
/**
 * ==== DASHBOARD ====
 * Statistik ringkas (jumlah aset, mutasi, pending) + tabel permintaan terbaru + sorting kolom
 * Akses: semua role login
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
auth_require();

$user = auth_user();
$conn = db();

// Sort parameter
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$allowed_sorts = ['id', 'asset_code', 'asset_name', 'from_loc', 'to_loc', 'requester_name', 'status', 'created_at', 'approver_name', 'jenis_sj', 'reason', 'via'];

// Validate sort parameters
if (!in_array($sort_by, $allowed_sorts)) {
    $sort_by = 'created_at';
}
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Helper function to generate sort URL
function get_sort_url($column) {
    global $sort_by, $sort_order;
    $new_order = ($sort_by === $column && $sort_order === 'ASC') ? 'DESC' : 'ASC';
    return '?sort_by=' . urlencode($column) . '&sort_order=' . urlencode($new_order);
}

// Helper function to get sort indicator
function get_sort_indicator($column) {
    global $sort_by, $sort_order;
    if ($sort_by === $column) {
        return $sort_order === 'ASC' ? ' &#9650;' : ' &#9660;';
    }
    return '';
}

// Statistik Dashboard (gunakan schema yang tersedia)
try {
    $stats = [
        'total_assets' => (int)$conn->query("SELECT COUNT(*) FROM assets_real")->fetchColumn(),
        'pending_requests' => (int)$conn->query("SELECT COUNT(*) FROM relocations WHERE status='PENDING'")->fetchColumn(),
        'approved_today' => (int)$conn->query("SELECT COUNT(*) FROM relocations WHERE status='APPROVED' AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY")->fetchColumn(),
        'rejected_today' => (int)$conn->query("SELECT COUNT(*) FROM relocations WHERE status='REJECTED' AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY")->fetchColumn(),
        // [PERBAIKAN LAJU SISTEM] rentang tanggal langsung (bukan DATE(updated_at)=CURDATE()
        // yang memaksa scan penuh) agar query memakai index idx_status_updated (sargable)
        'total_stores' => (int)$conn->query("SELECT COUNT(DISTINCT toko) FROM assets_real")->fetchColumn()
    ];
} catch (Exception $e) {
    $stats = [
        'total_assets' => 0,
        'pending_requests' => 0,
        'approved_today' => 0,
        'rejected_today' => 0,
        'total_stores' => 0
    ];
}

// ── Data Diagram Dashboard (Chart.js) ────────────────────────────
$charts = [
    'status'       => ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0],
    'jenis'        => [],
    'trend'        => ['labels' => [], 'values' => []],
    'kategori'     => ['labels' => [], 'values' => []],
    'toko'         => ['labels' => [], 'values' => []],
    'aset'         => ['aktif' => 0, 'nonaktif' => 0, 'total' => 0, 'toko' => 0, 'kategori' => 0],
    'total_mutasi' => 0,
];
try {
    // 1) Status mutasi: Pending / Disetujui / Ditolak
    foreach ($conn->query("SELECT status, COUNT(*) c FROM relocations GROUP BY status") as $r) {
        if (isset($charts['status'][$r['status']])) $charts['status'][$r['status']] = (int)$r['c'];
    }
    $charts['total_mutasi'] = array_sum($charts['status']);

    // 2) Jenis mutasi (persentase seluruh mutasi) — urut terbesar dulu
    $q = $conn->query("SELECT jenis_sj, COUNT(*) c FROM relocations GROUP BY jenis_sj ORDER BY c DESC");
    foreach ($q as $r) {
        $charts['jenis'][] = [
            'label' => jenis_sj_label((string)$r['jenis_sj']),
            'code'  => (string)$r['jenis_sj'],
            'value' => (int)$r['c'],
        ];
    }

    // 3) Tren mutasi 14 hari terakhir (hari tanpa mutasi tetap muncul = 0)
    $trend = [];
    for ($i = 13; $i >= 0; $i--) $trend[date('Y-m-d', strtotime("-{$i} day"))] = 0;
    $q = $conn->query("SELECT DATE(created_at) d, COUNT(*) c FROM relocations WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)");
    foreach ($q as $r) {
        if (isset($trend[$r['d']])) $trend[$r['d']] = (int)$r['c'];
    }
    foreach ($trend as $d => $c) {
        $charts['trend']['labels'][] = date('d/m', strtotime($d));
        $charts['trend']['values'][] = $c;
    }

    // 4) Aset per kategori — Top 8, urut terbesar dulu
    $q = $conn->query("SELECT kategori, COUNT(*) c FROM assets_real GROUP BY kategori ORDER BY c DESC LIMIT 8");
    foreach ($q as $r) {
        $charts['kategori']['labels'][] = (string)$r['kategori'];
        $charts['kategori']['values'][] = (int)$r['c'];
    }

    // 5) Ringkasan aset & toko
    $r = $conn->query("SELECT COUNT(*) total, COUNT(DISTINCT toko) toko, SUM(status='Aktif') aktif, SUM(status<>'Aktif') nonaktif, COUNT(DISTINCT kategori) kat FROM assets_real")->fetch();
    $charts['aset'] = [
        'aktif'    => (int)($r['aktif'] ?? 0),
        'nonaktif' => (int)($r['nonaktif'] ?? 0),
        'total'    => (int)($r['total'] ?? 0),
        'toko'     => (int)($r['toko'] ?? 0),
        'kategori' => (int)($r['kat'] ?? 0),
    ];

    // 6) Toko/lokasi tujuan mutasi terbanyak — Top 6, urut terbesar dulu
    //    [PERBAIKAN 27-09-2026] Lokasi NON-TOKO dikecualikan dari ranking ini:
    //    "Gudang GA", lokasi "CADANGAN" (CGA1/CGA2/CGA3), dan "DC" (depot) —
    //    itu gudang/depot, bukan toko, jadi tidak masuk daftar tujuan mutasi toko.
    //    Catatan: cek kode dibungkus "IS NULL OR" — code boleh NULL di DB dan
    //    "NULL NOT LIKE ..." bernilai NULL (bukan TRUE) yang justru membuang baris.
    $q = $conn->query("SELECT l2.name toko, COUNT(*) c
        FROM relocations r
        LEFT JOIN locations l2 ON l2.id = r.to_location_id
        WHERE l2.id IS NULL
           OR (l2.name NOT LIKE '%Gudang%'
               AND l2.name NOT LIKE '%CADANGAN%'
               AND l2.name NOT LIKE '% - DC%'
               AND (l2.code IS NULL OR l2.code NOT LIKE 'GUDANG%'))
        GROUP BY l2.name ORDER BY c DESC LIMIT 6");
    foreach ($q as $r) {
        $charts['toko']['labels'][] = (string)($r['toko'] !== null && $r['toko'] !== '' ? $r['toko'] : '—');
        $charts['toko']['values'][] = (int)$r['c'];
    }
} catch (Exception $e) {
    // diagram kosong — dashboard tetap jalan tanpa grafik
}

// [PERBAIKAN LAJU SISTEM] Fingerprint ringan untuk auto-refresh cerdas
// (ditempel ke window._dashFp di bawah + di-poll via api/dashboard_fingerprint.php)
try { $dashFp = dashboard_fingerprint($conn); } catch (Exception $e) { $dashFp = ''; }

// Map column names for sorting
$sort_map = [
    'id' => 'r.id',
    'asset_code' => 'a.asset_code',
    'asset_name' => 'a.name',
    'from_loc' => 'l1.name',
    'to_loc' => 'l2.name',
    'requester_name' => 'u1.name',
    'status' => 'r.status',
    'created_at' => 'r.created_at',
    'approver_name' => 'u2.name',
    'jenis_sj' => 'r.jenis_sj',
    'reason' => 'r.reason',
    'via' => 'r.updated_at'
];

$order_by = $sort_map[$sort_by] . ' ' . $sort_order;

// Recent requests
try {
    $recent = $conn->query("
        SELECT 
            r.*, 
            a.asset_code,
            a.name as asset_name,
            a.value_amount,
            ar.no_seri as real_no_seri,
            ar.sub_code as real_sub_code,
            ar.kuantitas as real_kuantitas,
            ar.toko as asset_toko,
            CASE WHEN l1.name = 'Gudang GA' OR l1.name = 'Toko 001' THEN COALESCE(ar.toko, l1.name) ELSE l1.name END as from_loc,
            CASE WHEN l2.name = 'Gudang GA' OR l2.name = 'Toko 001' THEN COALESCE(ar.toko, l2.name) ELSE l2.name END as to_loc,
            u1.name as requester_name,
            u2.name as approver_name,
            ro.name as approver_role,
            (SELECT COUNT(*) FROM relocation_items ri WHERE ri.relocation_id = r.id) as item_count
        FROM relocations r
        LEFT JOIN assets a ON a.id = r.asset_id
        LEFT JOIN assets_real ar ON ar.id = r.asset_id
        LEFT JOIN locations l1 ON l1.id = r.from_location_id
        LEFT JOIN locations l2 ON l2.id = r.to_location_id
        LEFT JOIN users u1 ON u1.id = r.created_by
        LEFT JOIN users u2 ON u2.id = r.approved_by
        LEFT JOIN roles ro ON ro.id = u2.role_id
        ORDER BY r.created_at DESC
        LIMIT 20
    ")->fetchAll();
} catch (Exception $e) {
    $recent = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SRA Indomaret Parung</title>
    <?php echo asset_css('css/layout-simple.css'); ?>
    <?php echo asset_css('css/indomaret-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/layout-override.css'); ?>
    <?php echo_loading_css(); ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* ═══════════ DIAGRAM DASHBOARD (Chart.js) ═══════════ */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin: 14px 0 6px;
        }
        .chart-card {
            background: var(--color-card, #ffffff);
            border: 1px solid var(--color-border, #e5e7eb);
            border-radius: 14px;
            padding: 14px 14px 10px;
            box-shadow: 0 1px 3px rgba(15,23,42,.06);
            position: relative;
            overflow: visible; /* kartu boleh menimpa kartu tetangga saat zoom — gaya Dock macOS */
            transform: scale(1);
            transform-origin: center center;
            /* [ANTI-BLUR 27-09-2026] transisi dipersingkat (.55s → .3s): zoom
               selesai lebih cepat sehingga bitmap canvas segera dirender ulang
               tajam; diagram sendiri dirender 2x via devicePixelRatio. */
            transition: transform .3s cubic-bezier(.18,.89,.32,1.18) 0s, box-shadow .25s ease;
            will-change: transform;
        }
        .chart-card.span-2 { grid-column: span 2; }
        .chart-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
        .chart-title { font-size: .86rem; font-weight: 700; color: #111827; letter-spacing: .01em; }
        .chart-sub { font-size: .66rem; color: #9ca3af; font-weight: 500; margin-top: 2px; }
        .chart-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .chart-chip {
            font-size: .66rem; font-weight: 700; padding: 3px 10px; border-radius: 999px;
            background: #eff6ff; color: #1d4ed8; border: 1px solid #dbeafe;
            font-variant-numeric: tabular-nums; white-space: nowrap;
        }
        .chart-chip.gold  { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .chart-chip.green { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .chart-chip.red   { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

        /* Efek Dock macOS: KARTU + diagram ngezoom BERSAMA (satu kesatuan).
           ARAH ZOOM MENGIKUTI POSISI KARTU — persis Dock di tepi layar macOS:
           kartu sisi KIRI membesar ke kanan, kartu sisi KANAN membesar ke kiri,
           sehingga zoom selalu ke ARAH DALAM dan tidak pernah melewati tepi
           halaman/layar.
           [ANTI-BLUR 27-09-2026] Intensitas zoom diturunkan 15% → 6% dan delay
           120ms → 50ms: teks/bentuk diagram tidak lagi blur & pusing dibaca
           (canvas dirender bitmap 2x via Chart.defaults.devicePixelRatio di
           js/dashboard-charts.js, jadi hasil zoom tetap tajam). */
        .chart-canvas-wrap {
            position: relative;
            height: 215px;
        }
        .chart-card.pos-left  { transform-origin: left center; }
        .chart-card.pos-right { transform-origin: right center; }
        .chart-card:hover {
            transform: scale(1.06);
            transition-delay: .05s;
            z-index: 40;
            box-shadow: 0 10px 26px rgba(15,23,42,.16);
        }
        .chart-empty {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: #9ca3af; font-size: .78rem; font-weight: 600;
            background: rgba(255,255,255,.65); border-radius: 10px;
        }

        /* ── Kekuatan sinyal integrasi (Telegram & Ngrok/Webhook) ── */
        .signal-grid { display: flex; flex-direction: column; gap: 10px; margin-top: 2px; }
        .sig-block {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 12px;
            background: linear-gradient(180deg,#f8fafc,#f1f5f9);
            border: 1px solid #e2e8f0;
        }
        .sig-icon {
            width: 34px; height: 34px; border-radius: 10px; flex: 0 0 auto;
            display: flex; align-items: center; justify-content: center; font-size: 1rem;
        }
        .sig-info { flex: 1; min-width: 0; }
        .sig-name { font-size: .78rem; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 6px; }
        .sig-ms { font-size: .66rem; color: #6b7280; font-variant-numeric: tabular-nums; margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sig-state { font-size: .6rem; font-weight: 800; letter-spacing: .07em; }
        .sig-bars { display: flex; align-items: flex-end; gap: 3px; height: 24px; flex: 0 0 auto; }
        .sig-bars i { width: 6px; border-radius: 2px 2px 1px 1px; background: #e2e8f0; transition: background .35s ease, box-shadow .35s ease; }
        .sig-bars i:nth-child(1) { height: 7px; }
        .sig-bars i:nth-child(2) { height: 12px; }
        .sig-bars i:nth-child(3) { height: 17px; }
        .sig-bars i:nth-child(4) { height: 23px; }
        .sig-bars[data-level="4"] i { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,.55); }
        .sig-bars[data-level="3"] i:nth-child(-n+3) { background: #4ade80; box-shadow: 0 0 5px rgba(74,222,128,.45); }
        .sig-bars[data-level="2"] i:nth-child(-n+2) { background: #f59e0b; box-shadow: 0 0 5px rgba(245,158,11,.45); }
        .sig-bars[data-level="1"] i:nth-child(1) { background: #ef4444; box-shadow: 0 0 5px rgba(239,68,68,.5); }
        .sig-bars.pulse i { animation: sigPulse 1.1s ease-in-out infinite; }
        @keyframes sigPulse { 0%,100% { opacity: .35; } 50% { opacity: 1; } }
        .chip-live {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .6rem; font-weight: 800; letter-spacing: .08em;
            color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0;
            padding: 2px 8px; border-radius: 999px; flex: 0 0 auto;
        }
        .chip-live::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #10b981; animation: liveDot 1.4s infinite; }
        @keyframes liveDot { 0%,100% { opacity: .4; } 50% { opacity: 1; } }

        /* Responsive diagram — jumlah kolom & ARAH/besaran zoom menyesuaikan device,
           kartu tidak pernah membesar melewati tepi halaman */
        @media (min-width: 721px) and (max-width: 1100px) {
            /* Grid 2 kolom: kartu full-width (span-2) zoom-nya dikecilkan;
               kartu setengah lebar tetap tumbuh ke arah dalam halaman */
            .charts-grid { grid-template-columns: repeat(2, 1fr); }
            .chart-card.pos-left,
            .chart-card.pos-right { transform-origin: center center; }
            .chart-card.span-2:hover { transform: scale(1.03); }
            .charts-grid .chart-card:nth-child(2) { transform-origin: left center; }  /* Status → kolom kiri */
            .charts-grid .chart-card:nth-child(3) { transform-origin: right center; } /* Jenis  → kolom kanan */
            .charts-grid .chart-card:nth-child(6) { transform-origin: left center; }  /* Sinyal → kolom kiri */
        }
        @media (max-width: 720px) {
            /* Grid 1 kolom: semua kartu full-width — zoom mini dari tengah */
            .charts-grid { grid-template-columns: 1fr; }
            .chart-card.span-2 { grid-column: auto; }
            .chart-canvas-wrap { height: 190px; }
            .chart-card,
            .chart-card.pos-left,
            .chart-card.pos-right { transform-origin: center center; }
            .chart-card:hover { transform: scale(1.02); }
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            transition: background-color 0.3s ease, color 0.3s ease;
            margin: 0 !important;
            padding: 0 !important;
        }

        
        .main-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        .content-wrapper {
            flex: 1;
            width: 100%;
            transition: all 0.3s ease;
            padding: 0.4cm;
            padding-top: 0.4cm;
            margin: 0;
        }
        
        /* Header sudah dihandle oleh CSS files */
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .header-logo img {
            height: 40px;
            width: auto;
        }
        
        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #0b5ea8, #0ea5e9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .content-area {
            padding: 30px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .page-header {
            margin-bottom: 2rem;
            padding: 0;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: var(--color-text-secondary);
            font-size: 0.95rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--color-card);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-icon.blue {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
        }
        
        .stat-icon.yellow {
            background: linear-gradient(135deg, #f4c300, #eab308);
        }
        
        .stat-icon.green {
            background: linear-gradient(135deg, #22c55e, #16a34a);
        }
        
        .stat-icon.purple {
            background: linear-gradient(135deg, #a855f7, #9333ea);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--color-text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .table-container {
            background: var(--color-card);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--color-border);
        }
        
        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-text);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: var(--color-bg);
        }
        
        th {
            padding: 1rem 1.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--color-text-secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        th a {
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.2s ease;
        }

        th a:hover {
            color: var(--color-text);
        }

        .sort-indicator {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            margin-left: 0.25rem;
        }
        
        td {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--color-border);
            color: var(--color-text);
        }
        
        tbody tr {
            transition: background-color 0.2s ease;
        }
        
        tbody tr:hover {
            background: var(--color-bg);
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-cancelled {
            background: #e5e7eb;
            color: #4b5563;
        }

        /* -- Telegram Widget -- */
        .tg-widget {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 8px 12px;
            width: 240px;
            flex-shrink: 0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .tg-widget-header {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        .tg-status-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            margin-left: auto;
            flex-shrink: 0;
        }
        .tg-connected { background: #22c55e; box-shadow: 0 0 4px rgba(34,197,94,0.5); }
        .tg-disconnected { background: #9ca3af; }
        .tg-widget-body { display: flex; flex-direction: column; gap: 4px; }
        .tg-info-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }
        .tg-info-label {
            font-size: 0.65rem;
            color: #9ca3af;
            font-weight: 500;
            white-space: nowrap;
        }
        .tg-info-val {
            font-size: 0.68rem;
            font-weight: 600;
            color: #374151;
            font-family: monospace;
        }
        .tg-badge {
            font-size: 0.6rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            white-space: nowrap;
        }
        .tg-badge-connected { background: #d1fae5; color: #065f46; }
        .tg-badge-disconnected { background: #fee2e2; color: #991b1b; }
        .tg-badge-auto { background: #dbeafe; color: #1d4ed8; }
        .tg-badge-off { background: #f3f4f6; color: #6b7280; }
        .tg-actions {
            display: flex;
            gap: 4px;
            margin-top: 6px;
            flex-wrap: wrap;
        }
        .tg-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .tg-btn-ping { background: #f0f9ff; color: #0369a1; border-color: #bae6fd; }
        .tg-btn-ping:hover { background: #0ea5e9; color: #fff; border-color: #0ea5e9; }
        .tg-btn-edit { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
        .tg-btn-edit:hover { background: #374151; color: #fff; }
        .tg-btn-guide { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .tg-btn-guide:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
        .tg-btn-connect { background: #0b5ea8; color: #fff; border-color: #0b5ea8; }
        .tg-btn-connect:hover { background: #0a4f8c; }
        @media (max-width: 768px) {
            .tg-widget { width: 100%; align-self: auto; }
        }
        
        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
            }
            
            .sidebar.collapsed + .content-wrapper {
                margin-left: 0;
            }
            
            .modern-header {
                padding: 1rem;
            }
            
            .header-title {
                font-size: 1.2rem;
            }
            
            .content-area {
                padding: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            table {
                font-size: 0.85rem;
            }
            
            th, td {
                padding: 0.75rem 1rem;
            }
        }
    </style>
</head>
<body class="sidebar-hidden">
    <?php $page_title = 'Dashboard'; $page_icon = ''; include __DIR__.'/app/header-sidebar.php'; ?>
    <div class="content-area">
                <?php
                // Ambil data telegram user
                $tgData = $conn->prepare("SELECT telegram_chat_id, auto_approve FROM users WHERE id = ?");
                $tgData->execute([$user['id']]);
                $tgUser = $tgData->fetch(PDO::FETCH_ASSOC);
                $hasTelegram = !empty($tgUser['telegram_chat_id']);
                $autoApprove = (bool)($tgUser['auto_approve'] ?? false);
                // Status ngrok/webhook (cek proses cepat, tanpa panggil API Telegram)
                $ngrokOnline = integration_ngrok_is_running();
                $ngrokDomain = integration_ngrok_domain();
                ?>
                <div class="page-header" style="margin-bottom:1.5rem !important;display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px;">
                    <div style="flex:1;min-width:0;">
                        <h2 class="page-title">Selamat Datang, <?= htmlspecialchars($user['name']) ?></h2>
                        <p class="page-subtitle">Sistem Relokasi Aset General Affair - Cab PRG</p>
                    </div>
                    <!-- Status Telegram + Ngrok (ringkas) + tombol Ping & Pengaturan -->
                    <div style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.9);border:1px solid #e5e7eb;border-radius:8px;padding:5px 10px;font-size:0.68rem;flex-shrink:0;flex-wrap:wrap;max-width:100%;">
                        <!-- Indikator Telegram: logo + TG + titik (hijau = konek, merah = tidak) -->
                        <?php if ($hasTelegram): ?>
                        <span style="display:inline-flex;align-items:center;gap:5px;cursor:default;" title="Telegram: terhubung &mdash; Chat ID <?= htmlspecialchars((string)($tgUser['telegram_chat_id'] ?? '')) ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="#229ED9"><path d="M21.95 3.7l-3.12 14.73c-.23 1.04-.85 1.3-1.72.8l-4.76-3.5-2.3 2.2c-.25.26-.47.47-.96.47l.34-4.84 8.82-7.98c.38-.34-.08-.53-.6-.19l-10.9 6.87-4.7-1.47c-1.02-.32-1.04-.96.21-1.43l18.35-7.07c.85-.31 1.6.19 1.32 1.4z"/></svg>
                            <span style="font-weight:400;color:#374151;font-size:0.66rem;letter-spacing:.02em;">TG</span>
                            <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 5px rgba(34,197,94,0.6);"></span>
                        </span>
                        <?php else: ?>
                        <span style="display:inline-flex;align-items:center;gap:5px;cursor:default;" title="Telegram: belum terhubung &mdash; buka Pengaturan (&#9881;) &rarr; tab Telegram">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="#9ca3af"><path d="M21.95 3.7l-3.12 14.73c-.23 1.04-.85 1.3-1.72.8l-4.76-3.5-2.3 2.2c-.25.26-.47.47-.96.47l.34-4.84 8.82-7.98c.38-.34-.08-.53-.6-.19l-10.9 6.87-4.7-1.47c-1.02-.32-1.04-.96.21-1.43l18.35-7.07c.85-.31 1.6.19 1.32 1.4z"/></svg>
                            <span style="font-weight:400;color:#374151;font-size:0.66rem;letter-spacing:.02em;">TG</span>
                            <span style="width:7px;height:7px;border-radius:50%;background:#ef4444;box-shadow:0 0 5px rgba(239,68,68,0.55);"></span>
                        </span>
                        <?php endif; ?>
                        <span style="color:#e5e7eb;">|</span>
                        <!-- Indikator Ngrok: logo + Connect + titik (hijau = online, merah = offline) -->
                        <span style="display:inline-flex;align-items:center;gap:5px;cursor:default;" title="Ngrok: <?= $ngrokOnline ? 'ONLINE' : 'OFFLINE' ?><?= $ngrokDomain ? ' &mdash; ' . htmlspecialchars($ngrokDomain) : '' ?> &mdash; detail di Pengaturan (&#9881;) &rarr; tab Ngrok/Webhook">
                            <svg width="13" height="13" viewBox="0 0 24 24"><rect width="24" height="24" rx="6" fill="#1F1E37"/><text x="12" y="17" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="14" font-weight="800" fill="#ffffff">n</text></svg>
                            <span style="font-weight:400;color:#374151;font-size:0.66rem;letter-spacing:.02em;">Connect</span>
                            <span style="width:7px;height:7px;border-radius:50%;background:<?= $ngrokOnline ? '#22c55e;box-shadow:0 0 5px rgba(34,197,94,0.6);' : '#ef4444;box-shadow:0 0 5px rgba(239,68,68,0.55);' ?>"></span>
                        </span>
                        <span style="color:#e5e7eb;">|</span>
                        <!-- Aksi ringkas -->
                        <button class="tg-btn tg-btn-ping" onclick="pingTelegram(this)" title="Ping Telegram (tes koneksi)" style="padding:3px 7px;font-size:0.72rem;line-height:1;">&#128225;</button>
                        <button class="tg-btn tg-btn-edit" onclick="integrationOpen('telegram')" title="Pengaturan Telegram &amp; Ngrok/Webhook" style="padding:3px 7px;font-size:0.72rem;line-height:1;">&#9881;</button>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <a href="assets.php" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?= number_format($stats['total_assets']) ?></div>
                                <div class="stat-label">Total Aset</div>
                            </div>
                            <div class="stat-icon blue" style="display:flex;align-items:center;justify-content:center;">
                                <svg width="20" height="20" fill="none" stroke="#1a56db" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
                            </div>
                        </div>
                    </a>

                    <a href="requests.php" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?= number_format($stats['pending_requests']) ?></div>
                                <div class="stat-label">Permintaan Pending</div>
                            </div>
                            <div class="stat-icon yellow" style="display:flex;align-items:center;justify-content:center;">
                                <svg width="20" height="20" fill="none" stroke="#92400e" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                        </div>
                    </a>

                    <a href="requests.php" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?= number_format($stats['approved_today']) ?></div>
                                <div class="stat-label">Disetujui Hari Ini</div>
                            </div>
                            <div class="stat-icon green" style="display:flex;align-items:center;justify-content:center;">
                                <svg width="20" height="20" fill="none" stroke="#0e7c4a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                        </div>
                    </a>

                    <a href="requests.php" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value" style="color:<?= $stats['rejected_today'] > 0 ? '#dc2626' : 'inherit' ?>"><?= number_format($stats['rejected_today']) ?></div>
                                <div class="stat-label">Ditolak Hari Ini</div>
                            </div>
                            <div class="stat-icon" style="background:linear-gradient(135deg,#fca5a5,#f87171);display:flex;align-items:center;justify-content:center;">
                                <svg width="20" height="20" fill="none" stroke="#991b1b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            </div>
                        </div>
                    </a>

                    <a href="assets.php" class="stat-card" style="text-decoration:none;color:inherit;cursor:pointer;">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value"><?= number_format($stats['total_stores']) ?></div>
                                <div class="stat-label">Total Toko</div>
                            </div>
                            <div class="stat-icon purple" style="display:flex;align-items:center;justify-content:center;">
                                <svg width="20" height="20" fill="none" stroke="#6d28d9" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- ════════ DIAGRAM DASHBOARD ════════ -->
                <div class="charts-grid">

                    <!-- 1. Tren mutasi 14 hari — line chart gaya trading -->
                    <div class="chart-card span-2 pos-left">
                        <div class="chart-head">
                            <div>
                                <div class="chart-title">Tren Mutasi Aset — 14 Hari Terakhir</div>
                                <div class="chart-sub">Jumlah permintaan relokasi per hari (semua jenis)</div>
                            </div>
                            <span class="chart-chip"><?= number_format(array_sum($charts['trend']['values'])) ?> mutasi</span>
                        </div>
                        <div class="chart-canvas-wrap"><canvas id="chTrend"></canvas></div>
                    </div>

                    <!-- 2. Status mutasi — donut dengan total di tengah -->
                    <div class="chart-card pos-right">
                        <div class="chart-head">
                            <div>
                                <div class="chart-title">Status Mutasi</div>
                                <div class="chart-sub">Pending · Disetujui · Ditolak</div>
                            </div>
                        </div>
                        <div class="chart-canvas-wrap"><canvas id="chStatus"></canvas></div>
                    </div>
                    <!-- 3. Jenis mutasi — polar area, persentase seluruh mutasi -->
                    <div class="chart-card pos-left">
                        <div class="chart-head">
                            <div>
                                <div class="chart-title">Jenis Mutasi</div>
                                <div class="chart-sub">Persentase dari seluruh mutasi</div>
                            </div>
                        </div>
                        <div class="chart-canvas-wrap"><canvas id="chJenis"></canvas></div>
                    </div>

                    <!-- 4. Aset per kategori — bar horizontal + ringkasan aset/toko -->
                    <div class="chart-card span-2 pos-right">
                        <div class="chart-head">
                            <div>
                                <div class="chart-title">Aset per Kategori — Top 8</div>
                                <div class="chart-sub">Distribusi <?= number_format($charts['aset']['total']) ?> aset dalam <?= number_format($charts['aset']['kategori']) ?> kategori</div>
                            </div>
                        </div>
                        <div class="chart-chips">
                            <span class="chart-chip">Total Aset: <?= number_format($charts['aset']['total']) ?></span>
                            <span class="chart-chip gold">Total Toko: <?= number_format($charts['aset']['toko']) ?></span>
                            <span class="chart-chip green">Aktif: <?= number_format($charts['aset']['aktif']) ?></span>
                            <span class="chart-chip red">Non-Aktif: <?= number_format($charts['aset']['nonaktif']) ?></span>
                        </div>
                        <div class="chart-canvas-wrap"><canvas id="chKategori"></canvas></div>
                    </div>
                    <!-- 5. Toko tujuan terbanyak — bar horizontal -->
                    <div class="chart-card span-2 pos-left">
                        <div class="chart-head">
                            <div>
                                <div class="chart-title">Tujuan Mutasi Terbanyak — Top 6</div>
                                <div class="chart-sub">Lokasi/toko dengan permintaan mutasi terbanyak</div>
                            </div>
                        </div>
                        <div class="chart-canvas-wrap"><canvas id="chToko"></canvas></div>
                    </div>

                    <!-- 6. Kekuatan sinyal integrasi — live -->
                    <div class="chart-card signal-card pos-right">
                        <div class="chart-head">
                            <div>
                                <div class="chart-title">Sinyal Integrasi</div>
                                <div class="chart-sub">Telegram &amp; Ngrok/Webhook</div>
                            </div>
                            <span class="chip-live">LIVE</span>
                        </div>
                        <div class="signal-grid">
                            <div class="sig-block">
                                <div class="sig-icon" style="background:#eff6ff;">&#9992;&#65039;</div>
                                <div class="sig-info">
                                    <div class="sig-name">Telegram <span class="sig-state" id="sigTgState" style="color:#9ca3af;">CEK&hellip;</span></div>
                                    <div class="sig-ms" id="sigTgMs">mengukur kekuatan sinyal&hellip;</div>
                                </div>
                                <div class="sig-bars pulse" id="sigTgBars" data-level="0"><i></i><i></i><i></i><i></i></div>
                            </div>
                            <div class="sig-block">
                                <div class="sig-icon" style="background:#1F1E37;color:#fff;font-weight:800;font-size:.75rem;">n</div>
                                <div class="sig-info">
                                    <div class="sig-name">Ngrok / Webhook <span class="sig-state" id="sigNkState" style="color:#9ca3af;">CEK&hellip;</span></div>
                                    <div class="sig-ms" id="sigNkMs">mengukur kekuatan sinyal&hellip;</div>
                                </div>
                                <div class="sig-bars pulse" id="sigNkBars" data-level="0"><i></i><i></i><i></i><i></i></div>
                            </div>
                            <div style="font-size:.62rem;color:#9ca3af;line-height:1.55;">
                                Telegram = latensi API bot (pesan keluar). Ngrok/Webhook = ketersediaan tunnel untuk pesan masuk (approve/reject via Telegram). Kontrol penuh di <a href="settings.php" style="color:#0b5ea8;font-weight:600;">Pengaturan</a>.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Requests Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h3 class="table-title">Permintaan Terakhir</h3>
                    </div>
                    <!-- Wrapper scroll horizontal: kolom tidak dipaksa sempit,
                         kelebihan lebar digeser kiri-kanan (Shift+Scroll / swipe) -->
                    <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th><a href="<?= get_sort_url('id') ?>" style="text-decoration:none;color:inherit;">ID<?= get_sort_indicator('id') ?></a></th>
                                <th><a href="<?= get_sort_url('created_at') ?>" style="text-decoration:none;color:inherit;">Tanggal <?=get_sort_indicator('created_at') ?></a></th>
                                <th>No Seri</th>
                                <th>Sub Code</th>
                                <th><a href="<?= get_sort_url('asset_name') ?>" style="text-decoration:none;color:inherit;">Nama Aset<?= get_sort_indicator('asset_name') ?></a></th>
                                <th>Kuantitas</th>
                                <th><a href="<?= get_sort_url('from_loc') ?>" style="text-decoration:none;color:inherit;">Asal<?= get_sort_indicator('from_loc') ?></a></th>
                                <th><a href="<?= get_sort_url('to_loc') ?>" style="text-decoration:none;color:inherit;">Tujuan<?= get_sort_indicator('to_loc') ?></a></th>
                                <th><a href="<?= get_sort_url('requester_name') ?>" style="text-decoration:none;color:inherit;">Pemohon<?= get_sort_indicator('requester_name') ?></a></th>
                                <th><a href="<?= get_sort_url('status') ?>" style="text-decoration:none;color:inherit;">Status<?= get_sort_indicator('status') ?></a></th>
                                <th><a href="<?= get_sort_url('approver_name') ?>" style="text-decoration:none;color:inherit;">Diproses Oleh<?= get_sort_indicator('approver_name') ?></a></th>
                                <th><a href="<?= get_sort_url('jenis_sj') ?>" style="text-decoration:none;color:inherit;">Jenis SJ<?= get_sort_indicator('jenis_sj') ?></a></th>
                                <th><a href="<?= get_sort_url('reason') ?>" style="text-decoration:none;color:inherit;">No Surat Jalan<?= get_sort_indicator('reason') ?></a></th>
                                <th><a href="<?= get_sort_url('via') ?>" style="text-decoration:none;color:inherit;">Via<?= get_sort_indicator('via') ?></a></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent)): ?>
                            <tr>
                                <td colspan="14" style="text-align: center; padding: 2rem; color: var(--color-text-secondary);">
                                    Belum ada data permintaan
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recent as $req): 
                                    $hasMultiItems = ($req['item_count'] ?? 0) > 1;
                                    $dashMultiItems = [];
                                    if ($hasMultiItems) {
                                        try {
                                            $miStmt = $conn->prepare("
                                                SELECT ri.asset_id, 
                                                       COALESCE(ar2.no_seri, a2.asset_code, '') as item_no_seri,
                                                       COALESCE(ar2.sub_code, '') as item_sub_code,
                                                       COALESCE(ar2.kuantitas, 1) as item_kuantitas,
                                                       COALESCE(a2.name, ar2.keterangan, '') as item_name,
                                                       COALESCE(a2.value_amount, 0) as item_value
                                                FROM relocation_items ri
                                                LEFT JOIN assets a2 ON a2.id = ri.asset_id
                                                LEFT JOIN assets_real ar2 ON ar2.id = ri.asset_id
                                                WHERE ri.relocation_id = ?
                                            ");
                                            $miStmt->execute([$req['id']]);
                                            $dashMultiItems = $miStmt->fetchAll(PDO::FETCH_ASSOC);
                                        } catch(Exception $e) { $dashMultiItems = []; }
                                    }
                                    // Via
                                    $viaText = '-';
                                    if ($req['status'] !== 'PENDING') {
                                        $viaText = 'Web';
                                        try {
                                            $logStmt = $conn->prepare("SELECT note FROM approvals_log WHERE relocation_id = ? AND action IN ('APPROVE','REJECT') ORDER BY created_at DESC LIMIT 1");
                                            $logStmt->execute([$req['id']]);
                                            $logNote = $logStmt->fetchColumn();
                                            if ($logNote && stripos($logNote, 'telegram') !== false) $viaText = 'Telegram';
                                        } catch (Exception $e) {}
                                    }
                                ?>
                                <tr<?php if ($hasMultiItems): ?> class="expandable-row" onclick="toggleExpandRow(this)" style="cursor:pointer;"<?php endif; ?>>
                                    <td>
                                        #<?= $req['id'] ?>
                                        <?php if ($hasMultiItems): ?>
                                            <br><span class="expand-arrow" style="font-size:10px;color:#6b7280;">&#9654;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($req['real_no_seri'] ?? $req['asset_code'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($req['real_sub_code'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($req['asset_name'] ?? '-') ?></td>
                                    <td><?= (int)($req['real_kuantitas'] ?? 1) ?></td>
                                    <td><?= htmlspecialchars($req['from_loc'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($req['to_loc'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($req['requester_name'] ?? '-') ?></td>
                                    <td>
                                        <?php $statusClass = strtolower((string)$req['status']); ?>
                                        <span class="status-badge status-<?= $statusClass ?>">
                                            <?= ucfirst(strtolower((string)$req['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($req['approver_name'])): ?>
                                            <?= htmlspecialchars($req['approver_name']) ?>
                                            <br><small style="color:#6b7280;"><?= ucfirst($req['approver_role'] ?? '') ?></small>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $jenisSjCode = $req['jenis_sj'] ?? 'RA';
                                        $jenisSjLabel = jenis_sj_label($jenisSjCode);
                                        $jenisSjColors = [
                                            'RA' => ['bg' => '#dbeafe', 'color' => '#1d4ed8'],
                                            'SA' => ['bg' => '#d1fae5', 'color' => '#065f46'],
                                            'PS' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                            'PA' => ['bg' => '#ede9fe', 'color' => '#5b21b6'],
                                            'PP' => ['bg' => '#fce7f3', 'color' => '#9d174d'],
                                        ];
                                        $jColor = $jenisSjColors[$jenisSjCode] ?? $jenisSjColors['RA'];
                                        ?>
                                        <span style="background:<?= $jColor['bg'] ?>;color:<?= $jColor['color'] ?>;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;white-space:nowrap;"><?= $jenisSjLabel ?></span>
                                    </td>
                                    <td><?= !empty($req['reason']) ? htmlspecialchars($req['reason']) : '-' ?></td>
                                    <td>
                                        <?php if ($req['status'] !== 'PENDING'): ?>
                                            <span style="background:<?= $viaText === 'Telegram' ? '#dbeafe' : '#f3f4f6' ?>;color:<?= $viaText === 'Telegram' ? '#1d4ed8' : '#374151' ?>;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;"><?= $viaText ?></span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($hasMultiItems && !empty($dashMultiItems)): ?>
                                <tr class="expand-detail-row" style="display:none;">
                                    <td colspan="14" style="padding:0;border-top:none;">
                                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:0;margin:0 8px 8px;overflow-x:auto;">
                                            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                                                <thead>
                                                    <tr style="background:#e2e8f0;">
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">No</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Tanggal</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">No Seri</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Sub Code</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Nama Aset</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Kuantitas</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Asal</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Tujuan</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Pemohon</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Status</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Diproses Oleh</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Jenis SJ</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">No Surat Jalan</th>
                                                        <th style="padding:6px 10px;text-align:left;font-weight:600;color:#374151;">Via</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($dashMultiItems as $idx => $item): ?>
                                                    <tr style="border-bottom:1px solid #e5e7eb;">
                                                        <td style="padding:5px 10px;color:#6b7280;"><?= $idx + 1 ?></td>
                                                        <td style="padding:5px 10px;"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                                                        <td style="padding:5px 10px;font-weight:600;color:#0066cc;"><?= htmlspecialchars($item['item_no_seri'] ?: '-') ?></td>
                                                        <td style="padding:5px 10px;"><?= htmlspecialchars($item['item_sub_code'] ?: '-') ?></td>
                                                        <td style="padding:5px 10px;"><?= htmlspecialchars($item['item_name'] ?: '-') ?></td>
                                                        <td style="padding:5px 10px;"><?= (int)($item['item_kuantitas'] ?? 1) ?></td>
                                                        <td style="padding:5px 10px;"><?= htmlspecialchars($req['from_loc'] ?? '-') ?></td>
                                                        <td style="padding:5px 10px;"><?= htmlspecialchars($req['to_loc'] ?? '-') ?></td>
                                                        <td style="padding:5px 10px;"><?= htmlspecialchars($req['requester_name'] ?? '-') ?></td>
                                                        <td style="padding:5px 10px;"><span class="status-badge status-<?= strtolower($req['status']) ?>"><?= ucfirst(strtolower($req['status'])) ?></span></td>
                                                        <td style="padding:5px 10px;"><?= !empty($req['approver_name']) ? htmlspecialchars($req['approver_name']) : '-' ?></td>
                                                        <td style="padding:5px 10px;"><?php
                                                            $djCode = $req['jenis_sj'] ?? 'RA';
                                                            $djColors = ['RA'=>['#dbeafe','#1d4ed8'],'SA'=>['#d1fae5','#065f46'],'PS'=>['#fef3c7','#92400e'],'PA'=>['#ede9fe','#5b21b6'],'PP'=>['#fce7f3','#9d174d']];
                                                            $djC = $djColors[$djCode] ?? $djColors['RA'];
                                                            echo "<span style=\"background:{$djC[0]};color:{$djC[1]};padding:2px 6px;border-radius:3px;font-size:.7rem;font-weight:600;\">".jenis_sj_label($djCode)."</span>";
                                                        ?></td>
                                                        <td style="padding:5px 10px;"><?= !empty($req['reason']) ? htmlspecialchars($req['reason']) : '-' ?></td>
                                                        <td style="padding:5px 10px;"><?= $viaText ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div><!-- /.table-scroll -->
                </div>
    </div>
    <?php echo asset_js('js/app-ui.js'); ?>
    <?php echo asset_js('js/integration-panel.js'); ?>
    <?php echo_loading_js(); ?>
    <?php /* Chart.js LOKAL (js/chart.umd.js) — dashboard jalan tanpa internet */ ?>
    <?php echo asset_js('js/chart.umd.js'); ?>
    <script>
    /* Data diagram dari server → dipakai js/dashboard-charts.js */
    window._dashCharts = <?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    /* Fingerprint data awal → pembanding polling api/dashboard_fingerprint.php */
    window._dashFp = <?= json_encode($dashFp) ?>;
    </script>
    <?php echo asset_js('js/dashboard-charts.js'); ?>
    <script>
    // [PERBAIKAN LAJU SISTEM 27-09-2026] Auto-refresh cerdas:
    // sebelumnya location.reload() penuh tiap 15 detik → server menghitung ulang
    // SEMUA statistik + browser me-render ulang halaman terus-menerus (berat,
    // terasa lambat). Kini cukup cek fingerprint ringan tiap 10 detik — reload
    // penuh HANYA saat data benar-benar berubah.
    var _dashboardBusy = false; // flag: sedang ada aksi, jangan reload
    var _dashFp   = window._dashFp || '';
    var _dashBase = location.pathname.replace(/[^\/]*$/, '');

    function _dashCanReload() {
        if (document.getElementById('app-popup')) return false;
        if (document.querySelector('.modal[style*="block"]')) return false;
        if (document.querySelector('.notif-panel.open')) return false;
        if (document.querySelector('.expand-detail-row[style*="table-row"]')) return false;
        if (document.getElementById('tg-form-overlay')) return false;
        if (document.getElementById('tg-guide-overlay')) return false;
        if (document.getElementById('integration-overlay')) return false;
        if (document.getElementById('intg-guide')) return false;
        return true;
    }

    setInterval(function() {
        if (_dashboardBusy) return;
        if (window._chartsHover) return; // user sedang memeriksa diagram (efek zoom aktif) — jangan reload
        if (document.hidden) return;     // tab tidak terlihat → hemat resource, cek nanti
        if (!_dashCanReload()) return;   // ada popup/modal terbuka → tunda
        fetch(_dashBase + 'api/dashboard_fingerprint.php', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.ok || !res.fp) return;
                if (res.fp !== _dashFp) {
                    _dashFp = res.fp;
                    location.reload(); // data berubah → muat ulang sekali
                }
            })
            .catch(function() { /* server sibuk — coba lagi di tick berikutnya */ });
    }, 10000);

    // Expand row untuk multi-item
    function toggleExpandRow(row) {
        if (event.target.closest('button') || event.target.closest('input') || event.target.closest('a')) return;
        var detailRow = row.nextElementSibling;
        if (detailRow && detailRow.classList.contains('expand-detail-row')) {
            var isHidden = detailRow.style.display === 'none' || detailRow.style.display === '';
            detailRow.style.display = isHidden ? 'table-row' : 'none';
            row.classList.toggle('expanded', isHidden);
        }
    }
    </script>
</body>
</html>
