<?php
require __DIR__.'/app/bootstrap.php';
auth_require();
$user = auth_user();

$pdo = db();

// Pagination setup
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Search filter (optional)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Filter setup
$filter_cabang = isset($_GET['filter_cabang']) ? trim($_GET['filter_cabang']) : '';
$filter_toko = isset($_GET['filter_toko']) ? trim($_GET['filter_toko']) : '';
$filter_kategori = isset($_GET['filter_kategori']) ? trim($_GET['filter_kategori']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';

// Build WHERE clause
$where_conditions = [];
$search_params = [];

if ($search) {
    $where_conditions[] = "(a.cabang LIKE :search_cabang OR a.toko LIKE :search_toko OR a.kategori LIKE :search_kategori OR a.no_seri LIKE :search_no_seri OR a.keterangan LIKE :search_keterangan)";
    $search_param = "%{$search}%";
    $search_params[':search_cabang'] = $search_param;
    $search_params[':search_toko'] = $search_param;
    $search_params[':search_kategori'] = $search_param;
    $search_params[':search_no_seri'] = $search_param;
    $search_params[':search_keterangan'] = $search_param;
}

if ($filter_cabang) {
    $where_conditions[] = "a.cabang = :filter_cabang";
    $search_params[':filter_cabang'] = $filter_cabang;
}

if ($filter_toko) {
    $where_conditions[] = "a.toko = :filter_toko";
    $search_params[':filter_toko'] = $filter_toko;
}

if ($filter_kategori) {
    $where_conditions[] = "a.kategori = :filter_kategori";
    $search_params[':filter_kategori'] = $filter_kategori;
}

if ($filter_status) {
    $where_conditions[] = "a.status = :filter_status";
    $search_params[':filter_status'] = $filter_status;
}

$search_query = count($where_conditions) > 0 ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Sorting setup
// [RESTORASI KOLOM PENYUSUTAN 27-09-2026] masa/beban/umur kembali bisa disort (sempat terhapus saat optimasi 27-09)
$allowed_sort_columns = ['cabang', 'toko', 'sub_code', 'kategori', 'keterangan', 'no_seri', 'kuantitas', 'biaya_perolehan', 'masa_manfaat_bln', 'beban_penyusutan_bln', 'umur_jalan_bln', 'akumulasi_penyusutan', 'status'];
$sort_by = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort_columns) ? $_GET['sort'] : 'id';
$sort_order = isset($_GET['order']) && $_GET['order'] === 'ASC' ? 'ASC' : 'DESC';
$next_sort_order = $sort_order === 'ASC' ? 'DESC' : 'ASC';

// Get total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM assets_real a {$search_query}");
$count_stmt->execute($search_params);
$total = $count_stmt->fetch()['total'];
$total_pages = ceil($total / $per_page);

// Get paginated data with optimized query
$order_clause = "ORDER BY a.{$sort_by} {$sort_order}";
$stmt = $pdo->prepare("
    SELECT a.id, a.cabang, a.toko, a.sub_code, a.kategori, a.keterangan, a.no_seri, 
           a.kuantitas, a.biaya_perolehan, a.masa_manfaat_bln, a.beban_penyusutan_bln, 
           a.umur_jalan_bln, a.akumulasi_penyusutan, a.status
    FROM assets_real a
    {$search_query}
    {$order_clause}
    LIMIT :limit OFFSET :offset
");

// Bind parameters with proper types for better performance
foreach ($search_params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function untuk sort link
function sortLink($column, $label) {
    global $sort_by, $sort_order, $next_sort_order, $per_page, $search, $page, $filter_cabang, $filter_toko, $filter_kategori, $filter_status;
    $is_current_sort = $sort_by === $column;
    $arrow = $is_current_sort ? ($sort_order === 'ASC' ? ' ▲' : ' ▼') : '';
    $order_param = $is_current_sort ? $next_sort_order : 'ASC';
    $url = "?sort={$column}&order={$order_param}&page={$page}&per_page={$per_page}";
    if ($search) $url .= "&search=" . urlencode($search);
    if ($filter_cabang) $url .= "&filter_cabang=" . urlencode($filter_cabang);
    if ($filter_toko) $url .= "&filter_toko=" . urlencode($filter_toko);
    if ($filter_kategori) $url .= "&filter_kategori=" . urlencode($filter_kategori);
    if ($filter_status) $url .= "&filter_status=" . urlencode($filter_status);
    return "<a href=\"{$url}\" style=\"color: #007bff; text-decoration: none; cursor: pointer;\">{$label}{$arrow}</a>";
}

// Get unique values for filters with caching (cache untuk 5 menit)
$cache_key = 'asset_filters_' . md5('filters');
$cache_time = 300; // 5 menit

if (!isset($_SESSION[$cache_key]) || (time() - ($_SESSION[$cache_key]['time'] ?? 0)) > $cache_time) {
    // Single optimized query to get all distinct values at once
    $filter_data = [
        'cabangs' => [],
        'tokos' => [],
        'kategoris' => [],
        'statuses' => []
    ];
    
    // Get distinct cabangs
    $stmt = $pdo->query("SELECT DISTINCT cabang FROM assets_real WHERE cabang IS NOT NULL AND cabang != '' ORDER BY cabang LIMIT 1000");
    $filter_data['cabangs'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get distinct tokos  
    $stmt = $pdo->query("SELECT DISTINCT toko FROM assets_real WHERE toko IS NOT NULL AND toko != '' ORDER BY toko LIMIT 1000");
    $filter_data['tokos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get distinct kategoris
    $stmt = $pdo->query("SELECT DISTINCT kategori FROM assets_real WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori LIMIT 1000");
    $filter_data['kategoris'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get distinct statuses
    $stmt = $pdo->query("SELECT DISTINCT status FROM assets_real WHERE status IS NOT NULL AND status != '' ORDER BY status LIMIT 100");
    $filter_data['statuses'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $_SESSION[$cache_key] = [
        'time' => time(),
        'data' => $filter_data
    ];
}

$cabangs = $_SESSION[$cache_key]['data']['cabangs'];
$tokos = $_SESSION[$cache_key]['data']['tokos'];
$kategoris = $_SESSION[$cache_key]['data']['kategoris'];
$statuses = $_SESSION[$cache_key]['data']['statuses'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Aset - SRA Indomaret Parung</title>
    
    <!-- Preconnect to CDN for faster loading -->
    <link rel="preconnect" href="https://cdn.datatables.net">
    <link rel="preconnect" href="https://code.jquery.com">
    
    <!-- CSS Files -->
    <?php echo asset_css('css/layout-simple.css'); ?>
    <?php echo asset_css('css/app-theme.css'); ?>
    <?php echo asset_css('css/modern-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/layout-override.css'); ?>
    <?php echo_loading_css(); ?>
    <?php echo asset_js('js/app-ui.js', 'defer'); ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; }
        .container { max-width: 100%; margin: 0 auto; padding: 0.4cm !important; padding-top: 0.4cm !important; }
        .header { background: white; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header h2 { margin-bottom: 0; color: #1f2937; font-size: 18px; font-weight: 700; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav a { padding: 6px 12px; background: #ffffff; color: #0b5ea8; text-decoration: none; border-radius: 8px; transition: all 0.2s; border: 1px solid #dbe4f0; font-weight: 600; font-size: 0.9rem; }
        .nav a:hover { background: #f3f7fb; border-color: #c7d7ea; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 0.95rem; transition: all 0.3s; }
        .btn-primary { background: #28a745; color: white; }
        .btn-primary:hover { background: #218838; transform: translateY(-1px); }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-warning:hover { background: #e0a800; transform: translateY(-1px); }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; transform: translateY(-1px); }
        .btn-sm { padding: 5px 10px; font-size: 0.85rem; }
        
        /* Force hover effect for action buttons */
        #actionButtonsContainer .btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2) !important;
        }
        .toolbar { background: white; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .toolbar-info { margin-left: auto; color: #666; font-size: 0.9rem; white-space: nowrap; }
        .search-filter form { display: flex !important; flex-wrap: wrap !important; gap: 8px !important; align-items: center !important; width: 100% !important; }
        .search-filter .filter-input { flex: 2 1 0 !important; min-width: 0 !important; height: 36px !important; }
        .search-filter select { flex: 1 1 0 !important; min-width: 0 !important; height: 36px !important; width: 100% !important; max-width: none !important; }
        .search-filter .btn-inline { padding: 8px 14px !important; border: none !important; border-radius: 4px !important; cursor: pointer !important; white-space: nowrap !important; height: 36px !important; flex-shrink: 0 !important; }
        .search-filter .btn-primary-inline { background: #007bff !important; color: #fff !important; }
        .search-filter .btn-secondary-inline { background: #6c757d !important; color: #fff !important; text-decoration: none !important; display: inline-flex !important; align-items: center !important; }
        .search-filter .btn-filter-inline { background: #17a2b8 !important; color: #fff !important; }
        .search-filter { background: white; padding: 12px; border-radius: 8px; margin-bottom: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .pagination-info { text-align: center; color: #666; margin: 8px 0; font-size: 0.9rem; background: white; padding: 8px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .dataTables_wrapper { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .dataTables_paginate { margin-top: 15px; }
        .dataTables_length { margin-bottom: 15px; }
        th { background: #f8f9fa; font-weight: 700; color: #1f2937; font-size: 13px; }
        tbody tr:hover { background: #f9f9f9; }
        input[type="checkbox"] { cursor: pointer; }
        /* ===== [SCROLL HORIZONTAL TABEL ASET 27-09-2026] =====
           Dulu: tabel dipaksa width:100% (layout-override) sehingga 14 kolom
           selalu diremaskan ke lebar layar -> teks dempet & tidak bisa digeser.
           Kini: tabel dibungkus .table-scroll (pola kanonik proyek, sama dengan
           dashboard.php & reports.php — sumber: responsive-fix.css):
           - PC/laptop : tahan Shift + scroll roda mouse (Shift+Scroll)
           - HP/tablet : swipe kiri-kanan di area tabel
           Responsif tetap prioritas: lebar kolom mengikuti konten (max-content,
           tidak patah baris), mobile dapat padding ringkas khusus di bawah. */
        .table-scroll {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
        }
        .table-scroll > table {
            /* [TABEL LANDSCAPE 27-09-2026] tabel melebar PENUH mengikuti layar (landscape,
               bukan sempit "potret"). min-width 1400px menjaga 14 kolom tetap lega; di layar
               sempit tabel tetap bisa digeser lewat scroll horizontal .table-scroll. */
            width: 100% !important;          /* landscape: isi penuh container/layar */
            min-width: 1400px !important;    /* jaga agar kolom tidak dempet di layar sempit */
            max-width: none !important;      /* netralisir clamp apa pun */
            table-layout: auto !important;
        }
        .table-scroll > table th,
        .table-scroll > table td {
            white-space: nowrap;             /* teks sel satu baris -> rapi, kelebihan lebar ditampung scroll */
        }
        @media (min-width: 769px) {
            .table-scroll > table th { padding: 12px 18px !important; } /* desktop: napas lebih longgar */
            .table-scroll > table td { padding: 12px 18px !important; }
        }
        @media (max-width: 768px) {
            .table-scroll > table th,
            .table-scroll > table td { padding: 8px 10px !important; }  /* mobile: ringkas seperti kanonik responsive-fix.css */
        }
        /* ===== [HAPUS ASET VERIFIKASI+NOTIF 27-09-2026] Modal verifikasi hapus =====
           Alur wajib (format user): klik Hapus -> modal NIK+password admin ->
           hapus -> notif Telegram ke SEMUA user. Responsif: lebar mengikuti
           layar, tombol menumpuk full-width di HP, teks selalu terbaca. */
        #deleteVerifyModal .modal-content { max-width: 460px; }
        .del-verify-banner { background: #fff3cd; border: 1px solid #ffc107; color: #856404; border-radius: 6px; padding: 10px 12px; font-size: 13px; margin-bottom: 14px; line-height: 1.5; }
        .del-verify-list { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; color: #374151; max-height: 150px; overflow-y: auto; }
        .del-verify-list div { padding: 2px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .del-verify-error { background: #fdecea; border: 1px solid #f5c6cb; color: #b02a37; border-radius: 6px; padding: 9px 12px; font-size: 13px; margin-bottom: 12px; display: none; line-height: 1.5; }
        .del-verify-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; flex-wrap: wrap; }
        @media (max-width: 480px) {
            #deleteVerifyModal .modal-body { padding: 16px; }
            .del-verify-actions { flex-direction: column-reverse; }  /* tombol utama di atas, mudah dijangkau jempol */
            .del-verify-actions .btn { width: 100%; }
        }
        /* ===== [REDESIGN MODAL EDIT PROFESIONAL + KUNCI KEUANGAN 27-09-2026] =====
           1) Modal add/edit jadi 2 slide: (1) Data Aset, (2) Biaya & Penyusutan —
              form dalam form yang TERKUNCI: wajib verifikasi NIK + password admin
              (server-side: api/finance_auth_verify.php + gate finance_unlock di
              api/asset_create.php / asset_update.php / asset_update_bulk.php).
           2) Edit Keseluruhan (bulk) direstyling profesional; bagian Masa Manfaat &
              Umur Jalan ikut terkunci (memicu hitung ulang penyusutan).
           3) Responsif: satu kolom & tombol full-width di layar kecil.
           4) Tanpa emoji dekoratif (aturan #3) — gembok = SVG monochrome fungsional.
           5) [WARNA + LANDSCAPE 27-09-2026] warna selaras tema biru halaman (#0b5ea8 — sama
              dengan .form-card-title & .btn-primary), bukan abu gelap; modal Edit Keseluruhan
              dibuat LANDSCAPE (lebar 1100px, isi 2 kolom berdampingan) agar tidak potret. */
        .ef-steps { display: flex; align-items: center; gap: 10px; margin: 2px 0 18px; flex-wrap: wrap; }
        /* [STEP KLIK 27-09-2026] chip langkah bisa diklik untuk pindah slide (pengganti tombol panah) */
        .ef-step { display: flex; align-items: center; gap: 8px; font-size: .82rem; font-weight: 600; color: #64748b; padding: 7px 14px; border: 1px solid #e2e8f0; border-radius: 999px; background: #f8fafc; cursor: pointer; user-select: none; transition: background .15s, border-color .15s, color .15s; }
        .ef-step:hover { border-color: #9dc3e8; color: #0b5ea8; background: #f4f9fe; }
        .ef-step .ef-num { width: 22px; height: 22px; border-radius: 50%; background: #e2e8f0; color: #475569; display: flex; align-items: center; justify-content: center; font-size: .72rem; font-weight: 700; flex-shrink: 0; }
        .ef-step.active { color: #0b5ea8; border-color: #9dc3e8; background: #f4f9fe; box-shadow: 0 1px 3px rgba(11,94,168,.10); }
        .ef-step.active .ef-num { background: #0b5ea8; color: #fff; }
        /* [STEP WARNA 27-09-2026] state "done" (angka hijau) DIHAPUS — permintaan user:
           angka step yang sudah dilewati tetap abu (#e2e8f0/#475569 dari .ef-num),
           hanya step aktif yang biru (baris di atas). */
        .ef-line { flex: 1 1 26px; height: 1px; background: #e2e8f0; min-width: 26px; }
        /* chip Terkunci/Terbuka dihapus 27-09-2026 — status bagian keuangan sudah kelihatan dari ikon gembok */
        .ef-pane { display: none; }
        .ef-pane.active { display: block; }
        .ef-section-title { font-size: .72rem; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: #0b5ea8; border-left: 3px solid #0b5ea8; padding-left: 9px; margin: 2px 0 12px; }
        #dataModal .form-card-title, #bulkEditModal .form-card-title { color: #0b5ea8; }
        #dataModal input[type="text"], #dataModal input[type="number"], #dataModal select,
        #bulkEditModal input[type="text"], #bulkEditModal input[type="number"], #bulkEditModal select {
            border: 1px solid #d1d5db; border-radius: 8px; padding: 9px 12px; font-size: .9rem;
            color: #1f2937; background: #fff; transition: border-color .15s, box-shadow .15s;
        }
        #dataModal input[type="text"]:focus, #dataModal input[type="number"]:focus, #dataModal select:focus,
        #bulkEditModal input[type="text"]:focus, #bulkEditModal input[type="number"]:focus, #bulkEditModal select:focus {
            outline: none; border-color: #0b5ea8; box-shadow: 0 0 0 3px rgba(11,94,168,.12);
        }
        #dataModal input[readonly] { background: #f1f5f9; color: #475569; cursor: default; }
        .ef-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: .88rem; border: 1px solid transparent; cursor: pointer; transition: background .15s, border-color .15s; }
        .ef-btn-primary { background: #0b5ea8; color: #fff; }
        .ef-btn-primary:hover { background: #0a4f8c; }
        .ef-btn-primary:disabled { background: #94a3b8; cursor: not-allowed; }
        .ef-btn-ghost { background: #fff; color: #0b5ea8; border-color: #c8dcf0; }
        .ef-btn-ghost:hover { background: #eef5fc; }
        /* [TOMBOL IKON SVG 27-09-2026] tombol "Buka" pakai ikon SVG gembok terbuka (gaya lucide
           lock-open, stroke currentColor/putih) — tampilan ikon profesional & konsisten di semua
           OS/browser, tidak lagi tergantung font emoji. Saat verifikasi berjalan ikon disembunyikan
           dan diganti spinner CSS via class .ef-btn-loading (JS hanya add/remove class). */
        .ef-btn-unlock { padding: 9px 12px; line-height: 1; }
        .ef-btn-unlock svg { width: 15px; height: 15px; display: block; }
        .ef-btn-unlock.ef-btn-loading { position: relative; }
        .ef-btn-unlock.ef-btn-loading svg { display: none; }
        .ef-btn-unlock.ef-btn-loading::after { content: ''; position: absolute; left: 50%; top: 50%; width: 13px; height: 13px; margin: -6.5px 0 0 -6.5px; border: 2px solid rgba(255,255,255,.45); border-top-color: #fff; border-radius: 50%; animation: efUnlockSpin .7s linear infinite; }
        @keyframes efUnlockSpin { to { transform: rotate(360deg); } }
        /* Bagian keuangan TERKUNCI (form dalam form) */
        .ef-locked { border: 1px dashed #b9d4ee; border-radius: 10px; padding: 26px 20px; text-align: center; background: #f4f9fe; }
        .ef-lock-ico { width: 44px; height: 44px; margin: 0 auto 10px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #0b5ea8; }
        .ef-lock-ico svg { width: 20px; height: 20px; }
        .ef-locked h3 { font-size: .95rem; margin: 0 0 6px; color: #0b5ea8; font-weight: 700; }
        .ef-locked p { font-size: .8rem; color: #64748b; margin: 0 auto 14px; max-width: 430px; line-height: 1.55; }
        .ef-lock-form { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; max-width: 540px; margin: 0 auto; }
        .ef-lock-form input { flex: 1 1 150px; padding: 9px 12px !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; font-size: .88rem; min-width: 0; }
        .ef-lock-error { display: none; margin: 12px auto 0; max-width: 540px; font-size: .8rem; color: #b02a37; background: #fdecea; border: 1px solid #f5c6cb; border-radius: 8px; padding: 8px 12px; line-height: 1.5; }
        /* [VALIDASI TEKS 27-09-2026] kolom NIK/password kosong TIDAK dijadikan merah lagi —
           pesan "NIK dan password wajib diisi." muncul di .ef-lock-error di bawah form,
           sama persis seperti modal hapus (#deleteVerifyError). Rule .ef-input-error dihapus. */
        #efFinanceBody { border: 1px solid #d7e6f6; border-radius: 10px; padding: 16px; background: #fff; }
        .ef-fin-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .ef-fin-fields .full { grid-column: 1 / -1; }
        .ef-fin-fields .form-group { margin: 0; }
        /* Kartu field bulk (ganti inline-style lama) */
        .ef-field { border: 1px solid #e2e8f0; padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; background: #fff; transition: border-color .15s; }
        /* [RESPONSIF FORM 27-09-2026] input/select di kartu field Edit Keseluruhan penuh 1 baris —
           dulu tanpa width eksplisit input mengikuti lebar default browser (~170px) sehingga
           terlihat sempit di kartu 2 kolom; checkbox di .ef-checkrow tidak tersentuh (type text/number saja). */
        .ef-field input[type="text"], .ef-field input[type="number"], .ef-field select { width: 100%; }
        .ef-field:focus-within { border-color: #0b5ea8; }
        .ef-checkrow { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .ef-checkrow input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .ef-checkrow label { margin: 0; font-weight: 600; cursor: pointer; font-size: .85rem; color: #334155; }
        .ef-field .form-group { margin-bottom: 0; }
        .ef-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 11px 14px; font-size: .8rem; line-height: 1.55; margin: 15px 0; }
        .ef-bulk-lock { border: 1px dashed #b9d4ee; border-radius: 10px; padding: 14px; background: #f4f9fe; }
        /* [BULK LANDSCAPE 27-09-2026] modal Edit Keseluruhan melebar mengikuti layar (bukan
           potret): konten 2 kolom berdampingan; lock/warning/tombol full-width. */
        #bulkEditModal .modal-content { max-width: 1100px; }
        #bulkEditForm { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; align-items: start; }
        #bulkEditForm .ef-field { margin-bottom: 0; }
        #bulkEditForm .ef-bulk-lock, #bulkEditForm .ef-warn, #bulkEditForm .form-actions { grid-column: 1 / -1; }
        /* [BULK BIAYA & PENYUSUTAN 27-09-2026] isi bagian terkunci Edit Keseluruhan: grid 2 kolom
           (biaya/masa/umur/beban berdampingan, akumulasi full-width) mengikuti gaya edit satuan. */
        #efBulkFinanceBody { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; margin-top: 2px; }
        #efBulkFinanceBody .ef-field { margin-bottom: 0; }
        #efBulkFinanceBody .full { grid-column: 1 / -1; }
        #efBulkFinanceBody input[readonly] { background: #f8fafc !important; color: #94a3b8; cursor: not-allowed; }
        @media (max-width: 640px) {
            .ef-fin-fields { grid-template-columns: 1fr; }
            #bulkEditForm { grid-template-columns: 1fr; }
            #efBulkFinanceBody { grid-template-columns: 1fr; }
            .ef-line { display: none; }
            .ef-steps { gap: 8px; }
        }
        @media (max-width: 480px) {
            .ef-lock-form { flex-direction: column; }
            .ef-lock-form .ef-btn, .ef-lock-form input { width: 100%; flex: auto; }
            .ef-lock-form .ef-btn-unlock { width: auto; flex: 0 0 auto; align-self: flex-end; }
            .ef-steps { margin-bottom: 14px; }
        }
        /* ===== MODAL ===== */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow-y: auto; }
        .modal-content { background-color: #fefefe; margin: 20px auto; padding: 0; border-radius: 12px; width: 95%; max-width: 900px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); max-height: calc(100vh - 40px); overflow-y: auto; }
        .modal-header { padding: 18px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #0b5ea8 0%, #1d7ed8 100%); border-radius: 12px 12px 0 0; }
        .modal-header h2 { color: #fff; font-size: 1.1rem; font-weight: 600; margin: 0; letter-spacing: .3px; }
        .modal-body { padding: 20px 24px; }
        .close { color: rgba(255,255,255,0.8); font-size: 26px; font-weight: bold; cursor: pointer; line-height: 1; background: none; border: none; }
        .close:hover { color: #fff; }
        /* 2-card grid layout */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid .form-group-full { grid-column: 1 / -1; }
        .form-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
        .form-card-title { font-size: 0.8rem; font-weight: 700; color: #0b5ea8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; }
        .form-group { margin-bottom: 14px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #1f2937; font-size: 0.9rem; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; font-size: 14px; background: #fff; color: #1f2937; transition: border-color 0.2s, box-shadow 0.2s; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus { outline: none; border-color: #0b5ea8; box-shadow: 0 0 0 3px rgba(11,94,168,0.12); }
        .form-group textarea { resize: vertical; min-height: 75px; }
        .form-group input:disabled, .form-group textarea:disabled { background-color: #f3f4f6; color: #9ca3af; cursor: not-allowed; }
        .form-group input:enabled, .form-group textarea:enabled { background-color: #fff; color: #1f2937; cursor: text; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap; padding-top: 16px; border-top: 1px solid #e5e7eb; }
        /* Status badge in table */
        .badge-aktif { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.82rem; font-weight: 600; background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-nonaktif { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 0.82rem; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert { padding: 12px 15px; border-radius: 4px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Pagination */
        .pg-btn { padding: 8px 12px; border-radius: 4px; text-decoration: none; font-size: 0.9rem; display: inline-flex; align-items: center; }
        .pg-btn-active { background: #007bff; color: white !important; }
        .pg-btn-active:hover { background: #0056b3; }
        .pg-btn-current { background: #007bff; color: white; font-weight: bold; }
        .pg-btn-page { background: var(--color-card); color: #007bff; border: 1px solid var(--color-border); }
        .pg-btn-page:hover { background: var(--color-bg-2); }
        .pg-ellipsis { color: var(--color-text-muted); padding: 0 4px; }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; align-items: flex-start; }
            .nav { width: 100%; }
            .nav a { flex: 1; text-align: center; }
            .toolbar { flex-direction: column; }
            .toolbar-info { margin-left: 0; width: 100%; text-align: left; }
            .btn { width: 100%; }
            table { font-size: 0.92rem; }
            th, td { padding: 8px 10px; }
            /* Modal responsive */
            .modal-content { margin: 0; width: 100%; max-width: 100%; border-radius: 12px 12px 0 0; position: fixed; bottom: 0; left: 0; right: 0; max-height: 95vh; }
            .form-grid { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .form-actions button { width: 100%; }
        }
    </style>
</head>
<body class="sidebar-hidden">
    <?php $page_title = 'Data Aset (' . number_format($total) . ' data)'; $page_icon = ''; include __DIR__.'/app/header-sidebar.php'; ?>
    <div class="content-area">
    <div class="container">
        <div class="sticky-header">
            <div id="alertBox"></div>

            <!-- Search & Filter -->
            <div class="search-filter">
                <form method="get">
                    <input class="filter-input" type="text" name="search" placeholder="Cari berdasarkan cabang, toko, kategori..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-inline btn-primary-inline">Cari</button>
                    <?php if ($search || $filter_cabang || $filter_toko || $filter_kategori || $filter_status): ?>
                        <a href="assets.php" class="btn-inline btn-secondary-inline">Reset</a>
                    <?php endif; ?>

                    <select name="filter_cabang" onchange="this.form.submit()">
                        <option value="">Semua Cabang</option>
                        <?php foreach ($cabangs as $cabang): ?>
                            <option value="<?= htmlspecialchars($cabang) ?>" <?= $filter_cabang === $cabang ? 'selected' : '' ?>><?= htmlspecialchars($cabang) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="filter_toko" onchange="this.form.submit()">
                        <option value="">Semua Toko</option>
                        <?php foreach ($tokos as $toko): ?>
                            <option value="<?= htmlspecialchars($toko) ?>" <?= $filter_toko === $toko ? 'selected' : '' ?>><?= htmlspecialchars($toko) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="filter_kategori" onchange="this.form.submit()">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($kategoris as $kategori): ?>
                            <option value="<?= htmlspecialchars($kategori) ?>" <?= $filter_kategori === $kategori ? 'selected' : '' ?>><?= htmlspecialchars($kategori) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="filter_status" onchange="this.form.submit()">
                        <option value="">Semua Status</option>
                        <option value="Aktif" <?= $filter_status === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
                        <option value="Non Aktif" <?= $filter_status === 'Non Aktif' ? 'selected' : '' ?>>Non Aktif</option>
                    </select>

                    <button type="submit" class="btn-inline btn-filter-inline">Filter</button>

                    <!-- Preserve pagination and sort params in filter form -->
                    <input type="hidden" name="page" value="1">
                    <input type="hidden" name="per_page" value="<?= $per_page ?>">
                    <input type="hidden" name="sort" value="<?= $sort_by ?>">
                    <input type="hidden" name="order" value="<?= $sort_order ?>">
                </form>
            </div>
            
            <div class="toolbar">
                <?php if ($user['role'] === 'admin'): ?>
                <!-- Toggle Button untuk Show/Hide Action Buttons -->
                <button class="btn btn-toggle" id="toggleActionsBtn" onclick="toggleActionButtons()" style="display:none; position:relative; width:40px; height:40px; padding:8px; background:var(--color-card) !important; border:1px solid var(--color-border) !important; border-radius:6px; cursor:pointer; transition:all 0.3s; flex-shrink:0; box-shadow:0 2px 4px rgba(0,0,0,0.08);">
                    <span class="toggle-icon" style="display:flex; flex-direction:column; justify-content:space-between; width:100%; height:14px;">
                        <span class="line line-1" style="display:block; width:100%; height:2px; background:var(--color-primary); border-radius:1px; transition:all 0.3s;"></span>
                        <span class="line line-2" style="display:block; width:100%; height:2px; background:var(--color-primary); border-radius:1px; transition:all 0.3s;"></span>
                    </span>
                </button>
                
                <!-- Action Buttons Container dengan Slide Animation -->
                <div id="actionButtonsContainer" style="display:none; overflow:hidden; max-width:0; opacity:0; transition:max-width 0.4s ease-in-out, opacity 0.3s;">
                    <div style="display:flex; gap:8px; white-space:nowrap; padding-left:8px;">
                        <button class="btn btn-danger" onclick="deleteSelected()" id="deleteBtn" style="display:inline-flex !important; align-items:center !important; justify-content:center !important; transition:all 0.3s !important;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">Hapus</button>
                        <button class="btn btn-warning" onclick="editSelected()" id="editBtn" style="display:inline-flex !important; align-items:center !important; justify-content:center !important; transition:all 0.3s !important;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">Edit</button>
                        <?php if ($user['role'] === 'admin'): ?>
                        <button class="btn btn-primary" onclick="openBulkEditModal()" id="bulkEditBtn" style="display:inline-flex !important; align-items:center !important; justify-content:center !important; transition:all 0.3s !important;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">Edit Keseluruhan</button>
                        <button class="btn" onclick="mutasiSelected()" id="mutasiBtn" style="display:inline-flex !important; align-items:center !important; justify-content:center !important; transition:all 0.3s !important; background:#198754 !important; color:#fff !important; border-color:#198754 !important;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">&#8644; Mutasi</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="toolbar-info">
                    Menampilkan <?= number_format(($page - 1) * $per_page + 1) ?> - <?= number_format(min($page * $per_page, $total)) ?> dari <?= number_format($total) ?> data | Halaman <?= $page ?>/<?= $total_pages ?>
                </div>
            </div>
        </div>

        <div style="margin-top: 20px;">

        <!-- [SCROLL HORIZONTAL TABEL ASET 27-09-2026] bungkus tabel agar bisa
             digeser horizontal: Shift+Scroll (PC) / swipe (HP). Tidak mengubah
             struktur sel/kolom — indeks td untuk bulk-edit tetap sama. -->
        <div class="table-scroll" id="assetsTableScroll">
        <table id="assetsTable" class="display" style="width:100%">
            <thead>
                <tr>
                    <?php if ($user['role'] === 'admin'): ?>
                    <th style="width: 30px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                    <?php endif; ?>
                    <th><?= sortLink('cabang', 'Cabang') ?></th>
                    <th><?= sortLink('toko', 'Toko') ?></th>
                    <th><?= sortLink('sub_code', 'Sub Code') ?></th>
                    <th><?= sortLink('kategori', 'Kategori') ?></th>
                    <th><?= sortLink('keterangan', 'Keterangan') ?></th>
                    <th><?= sortLink('no_seri', 'No Seri') ?></th>
                    <th><?= sortLink('kuantitas', 'Kuantitas') ?></th>
                    <th><?= sortLink('biaya_perolehan', 'Biaya Perolehan') ?></th>
                    <!-- [LABEL KOLOM 2 BARIS 27-09-2026] satuan (bln) dipisah ke baris kedua agar header rapi -->
                    <th><?= sortLink('masa_manfaat_bln', 'Masa Manfaat<br>(bln)') ?></th>
                    <th><?= sortLink('beban_penyusutan_bln', 'Beban Penyusutan<br>(bln)') ?></th>
                    <th><?= sortLink('umur_jalan_bln', 'Umur Jalan<br>(Bln)') ?></th>
                    <th><?= sortLink('akumulasi_penyusutan', 'Akumulasi<br>Penyusutan') ?></th>
                    <th><?= sortLink('status', 'Status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assets as $asset): ?>
                <tr data-id="<?= $asset['id'] ?>">
                    <?php if ($user['role'] === 'admin'): ?>
                    <td><input type="checkbox" class="row-checkbox" value="<?= $asset['id'] ?>"></td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($asset['cabang'] ?? '') ?></td>
                    <td><?= htmlspecialchars($asset['toko'] ?? '') ?></td>
                    <td><?= htmlspecialchars($asset['sub_code'] ?? '') ?></td>
                    <td><?= htmlspecialchars($asset['kategori'] ?? '') ?></td>
                    <td><?= htmlspecialchars($asset['keterangan'] ?? '') ?></td>
                    <td><?= htmlspecialchars($asset['no_seri'] ?? '') ?></td>
                    <td><?= (int)($asset['kuantitas'] ?? 1) ?></td>
                    <td>Rp<?= number_format($asset['biaya_perolehan'] ?? 0) ?></td>
                    <!-- [RESTORASI KOLOM PENYUSUTAN 27-09-2026] urutan kolom mengikuti DB: biaya, masa, beban, umur, akumulasi -->
                    <td><?= $asset['masa_manfaat_bln'] !== null ? (int)$asset['masa_manfaat_bln'] : '-' ?></td>
                    <td>Rp<?= number_format($asset['beban_penyusutan_bln'] ?? 0) ?></td>
                    <td><?= $asset['umur_jalan_bln'] !== null ? (int)$asset['umur_jalan_bln'] : '-' ?></td>
                    <td>Rp<?= number_format($asset['akumulasi_penyusutan'] ?? 0) ?></td>
                    <td>
                        <?php 
                        $rawStatus = trim($asset['status'] ?? '');
                        $isAktif = (strtolower($rawStatus) === 'aktif' || $rawStatus === '1');
                        if ($isAktif): ?>
                            <span class="badge-aktif">Aktif</span>
                        <?php else: ?>
                            <span class="badge-nonaktif">Non Aktif</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /.table-scroll [SCROLL HORIZONTAL TABEL ASET 27-09-2026] -->
        
        <!-- Pagination Controls -->
        <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; align-items: center;">
            <!-- Per page selector -->
            <?php
            // Build query string that preserves all active filters + sort
            $filter_params = http_build_query(array_filter([
                'search'          => $search,
                'filter_cabang'   => $filter_cabang,
                'filter_toko'     => $filter_toko,
                'filter_kategori' => $filter_kategori,
                'filter_status'   => $filter_status,
                'sort'            => $sort_by !== 'id' ? $sort_by : '',
                'order'           => $sort_by !== 'id' ? $sort_order : '',
            ]));
            $filter_qs = $filter_params ? '&' . $filter_params : '';
            ?>
            <form method="get" style="display: flex; gap: 10px; align-items: center;">
                <label for="per_page" style="margin: 0;">Tampilkan per halaman:</label>
                <select name="per_page" id="per_page" onchange="this.form.submit()" style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="25"   <?= $per_page == 25   ? 'selected' : '' ?>>25 data</option>
                    <option value="50"   <?= $per_page == 50   ? 'selected' : '' ?>>50 data</option>
                    <option value="75"   <?= $per_page == 75   ? 'selected' : '' ?>>75 data</option>
                    <option value="100"  <?= $per_page == 100  ? 'selected' : '' ?>>100 data</option>
                    <option value="500"  <?= $per_page == 500  ? 'selected' : '' ?>>500 data</option>
                    <option value="750"  <?= $per_page == 750  ? 'selected' : '' ?>>750 data</option>
                    <option value="1000" <?= $per_page == 1000 ? 'selected' : '' ?>>1000 data</option>
                    <option value="5000" <?= $per_page == 5000 ? 'selected' : '' ?>>5000 data</option>
                    <option value="10000" <?= $per_page == 10000 ? 'selected' : '' ?>>10000 data</option>
                </select>
                <!-- Preserve page 1 when changing per_page, and carry all filters -->
                <input type="hidden" name="page" value="1">
                <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                <?php if ($filter_cabang): ?><input type="hidden" name="filter_cabang" value="<?= htmlspecialchars($filter_cabang) ?>"><?php endif; ?>
                <?php if ($filter_toko): ?><input type="hidden" name="filter_toko" value="<?= htmlspecialchars($filter_toko) ?>"><?php endif; ?>
                <?php if ($filter_kategori): ?><input type="hidden" name="filter_kategori" value="<?= htmlspecialchars($filter_kategori) ?>"><?php endif; ?>
                <?php if ($filter_status): ?><input type="hidden" name="filter_status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
                <?php if ($sort_by !== 'id'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>"><input type="hidden" name="order" value="<?= htmlspecialchars($sort_order) ?>"><?php endif; ?>
            </form>
        </div>
        
        <!-- Page navigation -->
        <div style="margin-top: 15px; display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;">
            <?php if ($page > 1): ?>
                <a href="?page=1&per_page=<?= $per_page ?><?= $filter_qs ?>" class="pg-btn pg-btn-active">« Pertama</a>
                <a href="?page=<?= $page - 1 ?>&per_page=<?= $per_page ?><?= $filter_qs ?>" class="pg-btn pg-btn-active">‹ Sebelumnya</a>
            <?php endif; ?>
            
            <!-- Page numbers -->
            <div style="display: flex; gap: 5px; flex-wrap: wrap; justify-content: center; align-items: center;">
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1) {
                    echo '<span class="pg-ellipsis">...</span>';
                }
                
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <?php if ($i == $page): ?>
                        <span class="pg-btn pg-btn-current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?>&per_page=<?= $per_page ?><?= $filter_qs ?>" class="pg-btn pg-btn-page"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($end_page < $total_pages) {
                    echo '<span class="pg-ellipsis">...</span>';
                }
                ?>
            </div>
            
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?>&per_page=<?= $per_page ?><?= $filter_qs ?>" class="pg-btn pg-btn-active">Berikutnya ›</a>
                <a href="?page=<?= $total_pages ?>&per_page=<?= $per_page ?><?= $filter_qs ?>" class="pg-btn pg-btn-active">Terakhir »</a>
            <?php endif; ?>
        </div>
        </div>
    </div>
    </div>

    <!-- Modal Add/Edit -->
    <div id="dataModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Tambah Data Aset</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="dataForm">
                    <input type="hidden" id="assetId">
                    <!-- [FORM DALAM FORM 27-09-2026] 2 slide: (1) Data Aset, (2) Biaya & Penyusutan
                         TERKUNCI — wajib verifikasi NIK+password admin sebelum bisa mengubah nilai
                         keuangan (server-side: api/finance_auth_verify.php + gate finance_unlock).
     [STEP KLIK 27-09-2026] pindah slide cukup klik chip langkah di atas (tombol panah →/← dihapus). -->
                    <div class="ef-steps">
                        <div class="ef-step active" id="efStep1" onclick="efGoStep(1)" title="Klik untuk buka Data Aset"><span class="ef-num">1</span> Data Aset</div>
                        <div class="ef-line"></div>
                        <div class="ef-step" id="efStep2" onclick="efGoStep(2)" title="Klik untuk buka Biaya & Penyusutan"><span class="ef-num">2</span> Biaya &amp; Penyusutan</div>
                    </div>

                    <!-- SLIDE 1: Data Aset -->
                    <div class="ef-pane active" id="efPane1">
                        <div class="form-grid">
                        <!-- Card kiri: Info Lokasi -->
                        <div class="form-card">
                            <div class="form-card-title">Informasi Lokasi</div>
                            <div class="form-group" style="position:relative;">
                                <label>Cabang <span style="color:#e53e3e">*</span></label>
                                <input type="text" id="cabang" required autocomplete="on" placeholder="Contoh: 08 - PARUNG" list="dl-cabang">
                                <div class="ac-drop" id="ac-drop-cabang"></div>
                            </div>
                            <div class="form-group" style="position:relative;">
                                <label>Toko <span style="color:#e53e3e">*</span></label>
                                <input type="text" id="toko" required autocomplete="on" placeholder="Nama toko..." list="dl-toko">
                                <div class="ac-drop" id="ac-drop-toko"></div>
                            </div>
                            <div class="form-group" style="position:relative;">
                                <label>Kategori <span style="color:#e53e3e">*</span></label>
                                <input type="text" id="kategori" required autocomplete="on" placeholder="Contoh: T - PERALATAN TOKO" list="dl-kategori">
                                <div class="ac-drop" id="ac-drop-kategori"></div>
                            </div>
                            <div class="form-group" style="position:relative;">
                                <label>Keterangan <span style="color:#e53e3e">*</span></label>
                                <input type="text" id="keterangan" required autocomplete="on" placeholder="Deskripsi aset..." list="dl-keterangan">
                                <div class="ac-drop" id="ac-drop-keterangan"></div>
                            </div>
                        </div>
                        <!-- Card kanan: Detail Aset -->
                        <div class="form-card">
                            <div class="form-card-title">Detail Aset</div>
                            <div class="form-group">
                                <label>No Seri <span style="color:#e53e3e">*</span></label>
                                <input type="text" id="no_seri" required placeholder="Nomor seri aset">
                            </div>
                            <div class="form-group">
                                <label>Sub Code</label>
                                <input type="text" id="sub_code" placeholder="Misal: 00000000">
                            </div>
                            <div class="form-group">
                                <label>Kuantitas</label>
                                <input type="number" id="kuantitas" placeholder="1" min="1" step="1" value="1">
                            </div>
                            <div class="form-group">
                                <label>Status <span style="color:#e53e3e">*</span></label>
                                <select id="status" required>
                                    <option value="Aktif">Aktif</option>
                                    <option value="Non Aktif">Non Aktif</option>
                                </select>
                            </div>
                        </div>
                        </div><!-- /.form-grid -->
                    </div><!-- /#efPane1 -->
                    <!-- SLIDE 2: Biaya & Penyusutan — TERKUNCI (form dalam form) -->
                    <div class="ef-pane" id="efPane2">
                        <div class="ef-locked" id="efFinanceLock">
                            <div class="ef-lock-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
                            <h3>Biaya & Penyusutan</h3>
                            <p>Perubahan biaya perolehan otomatis menghitung ulang beban &amp; akumulasi penyusutan. Masukkan NIK &amp; password admin untuk membuka bagian ini.</p>
                            <div class="ef-lock-form">
                                <input type="text" id="efLockNik" placeholder="NIK admin" autocomplete="off">
                                <input type="password" id="efLockPassword" placeholder="Password admin" autocomplete="new-password">
                                <button type="button" class="ef-btn ef-btn-primary ef-btn-unlock" id="efUnlockBtn" onclick="efUnlockFinance()" title="Buka bagian keuangan (NIK &amp; password admin)" aria-label="Buka bagian keuangan"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg></button>
                            </div>
                            <div class="ef-lock-error" id="efLockError"></div>
                        </div>

                        <div id="efFinanceBody" style="display:none;">
                            <div class="ef-section-title">Biaya &amp; Penyusutan</div>
                            <div class="ef-fin-fields">
                                <div class="form-group">
                                    <label>Biaya Perolehan (Rp) <span style="color:#e53e3e">*</span></label>
                                    <input type="number" id="biaya_perolehan" placeholder="0" min="0">
                                </div>
                                <div class="form-group">
                                    <label>Masa Manfaat (bulan)</label>
                                    <input type="number" id="masa_manfaat_bln" placeholder="0 (tanah / tanpa masa manfaat)" min="0" step="1" value="0">
                                </div>
                                <div class="form-group">
                                    <label>Beban Penyusutan (bln) (Rp) <span style="color:#16a34a;font-weight:600">(otomatis)</span></label>
                                    <input type="number" id="beban_penyusutan_bln" readonly placeholder="otomatis" min="0">
                                    <small style="color:#6b7280">= biaya_perolehan &divide; masa_manfaat</small>
                                </div>
                                <div class="form-group">
                                    <label>Umur Jalan (bulan)</label>
                                    <input type="number" id="umur_jalan_bln" placeholder="0 (aset baru, belum dipakai)" min="0" step="1" value="0">
                                </div>
                                <div class="form-group full">
                                    <label>Akumulasi Penyusutan (Rp) <span style="color:#16a34a;font-weight:600">(otomatis)</span></label>
                                    <input type="number" id="akumulasi_penyusutan" readonly placeholder="otomatis" min="0">
                                    <small style="color:#6b7280">= (biaya_perolehan &divide; masa_manfaat) &times; umur_jalan</small>
                                </div>
                            </div>
                        </div>
                    </div><!-- /#efPane2 -->

                    <div class="form-actions">
                        <button type="button" class="ef-btn ef-btn-ghost" onclick="closeModal()">Batal</button>
                        <button type="submit" class="ef-btn ef-btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Bulk Edit -->
    <div id="bulkEditModal" class="modal">
        <div class="modal-content"><!-- [BULK LANDSCAPE] lebar diatur CSS #bulkEditModal .modal-content (1100px) -->
            <div class="modal-header">
                <h2>Edit Keseluruhan Data</h2>
                <button class="close" onclick="closeBulkEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color:#4b5563;margin-bottom:16px;font-size:0.9rem;">Centang field yang ingin diubah untuk <strong id="bulkEditCount">0</strong> data terpilih. Field yang tidak dicentang tidak akan berubah.</p>
            <form id="bulkEditForm">
                <!-- Cabang -->
                <div class="ef-field">
                    <div class="ef-checkrow">
                        <input type="checkbox" id="editCabang" value="cabang">
                        <label for="editCabang">Cabang</label>
                    </div>
                    <div style="position:relative;">
                        <input type="text" id="bulk_cabang" placeholder="Ketik untuk cari cabang..." disabled autocomplete="on"
                            list="dl-cabang">
                        <div class="ac-drop" id="ac-drop-bulk_cabang"></div>
                    </div>
                </div>
                
                <!-- Toko -->
                <div class="ef-field">
                    <div class="ef-checkrow">
                        <input type="checkbox" id="editToko" value="toko">
                        <label for="editToko">Toko</label>
                    </div>
                    <div style="position:relative;">
                        <input type="text" id="bulk_toko" placeholder="Ketik untuk cari toko, atau nama toko baru..." disabled autocomplete="on"
                            list="dl-toko">
                        <div class="ac-drop" id="ac-drop-bulk_toko"></div>
                    </div>
                </div>
                
                <!-- Kategori -->
                <div class="ef-field">
                    <div class="ef-checkrow">
                        <input type="checkbox" id="editKategori" value="kategori">
                        <label for="editKategori">Kategori</label>
                    </div>
                    <div style="position:relative;">
                        <input type="text" id="bulk_kategori" placeholder="Ketik untuk cari kategori..." disabled autocomplete="on"
                            list="dl-kategori">
                        <div class="ac-drop" id="ac-drop-bulk_kategori"></div>
                    </div>
                </div>

                <!-- Keterangan -->
                <div class="ef-field">
                    <div class="ef-checkrow">
                        <input type="checkbox" id="editKeterangan" value="keterangan">
                        <label for="editKeterangan">Keterangan</label>
                    </div>
                    <div style="position:relative;">
                        <input type="text" id="bulk_keterangan" placeholder="Ketik untuk cari keterangan..." disabled autocomplete="on"
                            list="dl-keterangan">
                        <div class="ac-drop" id="ac-drop-bulk_keterangan"></div>
                    </div>
                </div>
                
                <!-- Status -->
                <div class="ef-field">
                    <div class="ef-checkrow">
                        <input type="checkbox" id="editStatus" value="status">
                        <label for="editStatus">Status</label>
                    </div>
                    <select id="bulk_status" disabled>
                        <option value="">-- Pilih Status --</option>
                        <option value="Aktif">Aktif</option>
                        <option value="Non Aktif">Non Aktif</option>
                    </select>
                </div>

                <!-- [KUNCI KEUANGAN EDIT 27-09-2026] Biaya perolehan / masa manfaat / umur jalan memicu hitung ulang
                     beban & akumulasi penyusutan per baris (rumus 26-09-2026) -> TERKUNCI:
                     wajib verifikasi NIK+password admin (flag finance_unlock server-side). -->
                <div class="ef-bulk-lock" id="efBulkFinanceArea">
                    <div class="ef-locked" id="efBulkFinanceLock" style="padding:18px 16px;">
                        <div class="ef-lock-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
                        <h3 style="font-size:.9rem;">Biaya &amp; Penyusutan</h3>
                        <p>Mengubah biaya perolehan / masa manfaat / umur jalan menghitung ulang beban &amp; akumulasi penyusutan semua aset terpilih. Verifikasi NIK &amp; password admin untuk membuka.</p>
                        <div class="ef-lock-form">
                            <input type="text" id="efBulkLockNik" placeholder="NIK admin" autocomplete="off">
                            <input type="password" id="efBulkLockPassword" placeholder="Password admin" autocomplete="new-password">
                            <button type="button" class="ef-btn ef-btn-primary ef-btn-unlock" id="efBulkUnlockBtn" onclick="efUnlockBulkFinance()" title="Buka bagian keuangan (NIK &amp; password admin)" aria-label="Buka bagian keuangan"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg></button>
                        </div>
                        <div class="ef-lock-error" id="efBulkLockError"></div>
                    </div>
                    <div id="efBulkFinanceBody" style="display:none;">
                        <div class="ef-field">
                            <div class="ef-checkrow">
                                <input type="checkbox" id="editBiaya" value="biaya_perolehan">
                                <label for="editBiaya">Biaya Perolehan (Rp) <span style="color:#e53e3e">*</span></label>
                            </div>
                            <input type="number" id="bulk_biaya" placeholder="0" min="0" disabled>
                            <small style="color:#6b7280;display:block;margin-top:6px;">Nilai ini diterapkan ke semua aset terpilih (menimpa biaya perolehan masing-masing).</small>
                        </div>
                        <div class="ef-field">
                            <div class="ef-checkrow">
                                <input type="checkbox" id="editMasaManfaat" value="masa_manfaat_bln">
                                <label for="editMasaManfaat">Masa Manfaat (bulan)</label>
                            </div>
                            <input type="number" id="bulk_masamanfaat" placeholder="0 = tanah / kategori tanpa masa manfaat" min="0" step="1" disabled>
                        </div>
                        <div class="ef-field">
                            <div class="ef-checkrow">
                                <input type="checkbox" id="editUmurJalan" value="umur_jalan_bln">
                                <label for="editUmurJalan">Umur Jalan (bulan)</label>
                            </div>
                            <input type="number" id="bulk_umurjalan" placeholder="0 = aset baru, belum dipakai" min="0" step="1" disabled>
                        </div>
                        <div class="ef-field">
                            <label style="font-weight:600;font-size:.85rem;color:#334155;display:block;margin-bottom:8px;">Beban Penyusutan (bln) (Rp) <span style="color:#16a34a;font-weight:600">(otomatis)</span></label>
                            <input type="text" readonly disabled placeholder="otomatis per aset">
                            <small style="color:#6b7280;display:block;margin-top:6px;">= biaya_perolehan &divide; masa_manfaat (tiap aset)</small>
                        </div>
                        <div class="ef-field full">
                            <label style="font-weight:600;font-size:.85rem;color:#334155;display:block;margin-bottom:8px;">Akumulasi Penyusutan (Rp) <span style="color:#16a34a;font-weight:600">(otomatis)</span></label>
                            <input type="text" readonly disabled placeholder="otomatis per aset">
                            <small style="color:#6b7280;display:block;margin-top:6px;">= (biaya_perolehan &divide; masa_manfaat) &times; umur_jalan (tiap aset)</small>
                        </div>
                    </div>
                </div>

                <div class="ef-warn">
                    <strong>Perhatian:</strong> Hanya field yang dicentang yang akan diubah.
                </div>

                <div class="form-actions"><!-- [RESPONSIF FORM 27-09-2026] inline style lama (display:flex;justify-content:flex-end)
                     DIHAPUS: inline menimpa media query ≤768px (.form-actions column + tombol full-width)
                     sehingga di HP tombol Batal/Simpan tidak menumpuk rapi seperti modal edit satuan. -->
                    <button type="button" class="ef-btn ef-btn-ghost" onclick="closeBulkEditModal()">Batal</button>
                    <button type="button" class="ef-btn ef-btn-primary" onclick="submitBulkEdit()">Simpan</button>
                </div>
            </form>
            </div><!-- /.modal-body -->
        </div>
    </div>

    <!-- ===== [HAPUS ASET VERIFIKASI+NOTIF 27-09-2026] Modal verifikasi NIK+password =====
         Alur hapus wajib lewat sini: 1 item (hapus per baris) maupun banyak (centang + Hapus).
         PENTING: modal HARUS berdiri sendiri DI LUAR #bulkEditModal & #dataModal (induk yang
         display:none akan menyembunyikan modal ini walau .show() dipanggil).
         Setelah verifikasi sukses & data terhapus, API mengirim notif Telegram ke SEMUA user. -->
    <div id="deleteVerifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Verifikasi Hapus Data</h2>
                <button class="close" onclick="closeDeleteVerifyModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="del-verify-banner">
                    <strong>Aksi permanen &amp; tidak bisa dibatalkan.</strong> Hapus data wajib diverifikasi dengan NIK &amp; password admin. Setelah berhasil, notifikasi Telegram otomatis dikirim ke semua user.
                </div>
                <div class="del-verify-list" id="deleteVerifyList"></div>
                <div class="form-group">
                    <label for="del_verify_nik">NIK Admin</label>
                    <input type="text" id="del_verify_nik" autocomplete="username" placeholder="Masukkan NIK admin">
                </div>
                <div class="form-group">
                    <label for="del_verify_password">Password Admin</label>
                    <input type="password" id="del_verify_password" autocomplete="current-password" placeholder="Masukkan password admin">
                </div>
                <div class="del-verify-error" id="deleteVerifyError"></div>
                <div class="del-verify-actions">
                    <button type="button" class="btn" onclick="closeDeleteVerifyModal()" style="padding: 8px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Batal</button>
                    <button type="button" class="btn btn-danger" id="deleteVerifySubmitBtn" onclick="submitDeleteVerify()" style="padding: 8px 20px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Ya, Hapus Sekarang</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        const currentUserId = <?= json_encode($user['id'] ?? null) ?>;

        $(document).ready(function() {
            // Handle checkbox changes
            $(document).on('change', '.row-checkbox', updateActionButtons);
            
            // Form submission
            $('#dataForm').on('submit', saveAsset);
        });

        function toggleSelectAll(checkbox) {
            $('.row-checkbox').prop('checked', checkbox.checked);
            updateActionButtons();
        }

        function updateActionButtons() {
            const checkedCount = $('.row-checkbox:checked').length;
            const toggleBtn = $('#toggleActionsBtn');
            
            if (checkedCount > 0) {
                toggleBtn.show();
                // Auto hide action buttons when selection changes
                hideActionButtons();
            } else {
                toggleBtn.hide();
                hideActionButtons();
            }
            
            // Update button visibility based on selection
            $('#deleteBtn').toggle(checkedCount > 0);
            $('#editBtn').toggle(checkedCount > 0);
            $('#bulkEditBtn').toggle(checkedCount > 1);
            $('#mutasiBtn').toggle(checkedCount > 0);
        }
        
        let actionButtonsVisible = false;
        
        function toggleActionButtons() {
            if (actionButtonsVisible) {
                hideActionButtons();
            } else {
                showActionButtons();
            }
        }
        
        function showActionButtons() {
            const container = $('#actionButtonsContainer');
            const toggleBtn = $('#toggleActionsBtn');
            const icon = toggleBtn.find('.toggle-icon');
            
            // Show container
            container.css('display', 'block');
            
            // Animate slide from left to right
            setTimeout(() => {
                container.css({
                    'max-width': '600px',
                    'opacity': '1'
                });
            }, 10);
            
            // Transform toggle button to X (cross) - keep blue color
            icon.css({
                'justify-content': 'center',
                'height': '100%'
            });
            toggleBtn.find('.line-1').css({
                'transform': 'rotate(45deg)',
                'position': 'absolute',
                'top': '50%',
                'left': '8px',
                'right': '8px',
                'width': 'calc(100% - 16px)',
                'margin-top': '-1px',
                'background': '#0b5ea8'
            });
            toggleBtn.find('.line-2').css({
                'transform': 'rotate(-45deg)',
                'position': 'absolute',
                'top': '50%',
                'left': '8px',
                'right': '8px',
                'width': 'calc(100% - 16px)',
                'margin-top': '-1px',
                'background': '#0b5ea8'
            });
            toggleBtn.css({
                'background': '#f3f7fb !important',
                'border-color': '#0b5ea8 !important'
            });
            
            actionButtonsVisible = true;
        }
        
        function hideActionButtons() {
            const container = $('#actionButtonsContainer');
            const toggleBtn = $('#toggleActionsBtn');
            const icon = toggleBtn.find('.toggle-icon');
            
            // Animate slide back (hide)
            container.css({
                'max-width': '0',
                'opacity': '0'
            });
            
            // Transform back to hamburger (2 lines)
            icon.css({
                'justify-content': 'space-between',
                'height': '14px'
            });
            toggleBtn.find('.line-1').css({
                'transform': 'none',
                'position': 'relative',
                'top': 'auto',
                'left': 'auto',
                'right': 'auto',
                'width': '100%',
                'margin-top': '0',
                'background': '#0b5ea8'
            });
            toggleBtn.find('.line-2').css({
                'transform': 'none',
                'position': 'relative',
                'top': 'auto',
                'left': 'auto',
                'right': 'auto',
                'width': '100%',
                'margin-top': '0',
                'background': '#0b5ea8'
            });
            toggleBtn.css({
                'background': '#ffffff !important',
                'border-color': '#dbe4f0 !important'
            });
            
            // Hide container after animation
            setTimeout(() => {
                container.css('display', 'none');
            }, 400);
            
            actionButtonsVisible = false;
        }

        function openAddModal() {
            $('#assetId').val('');
            $('#modalTitle').text('Tambah Data Aset');
            $('#dataForm')[0].reset();
            efLockFinance(); // [KUNCI KEUANGAN 27-09-2026] mulai dari slide 1, keuangan terkunci
            efGoStep(1);
            hitungPenyusutanUI();
            $('#dataModal').show();
        }

        function closeModal() {
            $('#dataModal').hide();
            $('#dataForm')[0].reset();
            efLockFinance(); // kunci kembali bagian keuangan
            efGoStep(1);
        }

        function editRow(id) {
            $.get('api/asset_get.php?id=' + id, function(data) {
                if (data.success) {
                    const asset = data.data;
                    $('#assetId').val(id);
                    $('#modalTitle').text('Edit Data Aset');
                    $('#cabang').val(asset.cabang);
                    $('#toko').val(asset.toko);
                    $('#kategori').val(asset.kategori);
                    $('#keterangan').val(asset.keterangan);
                    $('#no_seri').val(asset.no_seri);
                    $('#sub_code').val(asset.sub_code ?? '');
                    $('#kuantitas').val(asset.kuantitas ?? 1);
                    $('#biaya_perolehan').val(asset.biaya_perolehan);
                    $('#masa_manfaat_bln').val(asset.masa_manfaat_bln ?? 0);
                    $('#beban_penyusutan_bln').val(asset.beban_penyusutan_bln ?? 0);
                    $('#umur_jalan_bln').val(asset.umur_jalan_bln ?? 0);
                    $('#akumulasi_penyusutan').val(asset.akumulasi_penyusutan);
                    $('#status').val(asset.status);
                    efLockFinance(); // [KUNCI KEUANGAN 27-09-2026] slide keuangan selalu terkunci saat dibuka
                    efGoStep(1);
                    hitungPenyusutanUI();
                    $('#dataModal').show();
                }
            }, 'json');
        }

        function editSelected() {
            const checkedIds = $('.row-checkbox:checked').map(function() { return this.value; }).get();
            if (checkedIds.length === 1) {
                editRow(checkedIds[0]);
            } else if (checkedIds.length > 1) {
                openBulkEditModal();
            }
        }

        function saveAsset(e) {
            e.preventDefault();
            const id = $('#assetId').val();

            // [FORM DALAM FORM 27-09-2026] field keuangan hanya dikirim bila slide
            // "Biaya & Penyusutan" sudah dibuka dengan NIK+password admin; bila tidak,
            // server memakai nilai lama (hanya data umum yang berubah).
            const finOpen = $('#efFinanceBody').data('unlocked') === true;
            if (!id && !finOpen) {
                showPopup('warning', 'Bagian Terkunci', 'Tambah aset wajib mengisi biaya perolehan. Klik langkah "2 Biaya & Penyusutan" di atas lalu buka bagian keuangan dengan NIK & password admin.');
                return;
            }

            const data = {
                cabang: $('#cabang').val(),
                toko: $('#toko').val(),
                sub_code: $('#sub_code').val(),
                kategori: $('#kategori').val(),
                keterangan: $('#keterangan').val(),
                no_seri: $('#no_seri').val(),
                kuantitas: parseInt($('#kuantitas').val()) || 1,
                status: $('#status').val()
            };
            if (finOpen) {
                // [RUMUS PENYUSUTAN 26-09-2026] beban & akumulasi DIHITUNG ULANG otomatis oleh API
                // dari biaya_perolehan / masa_manfaat / umur_jalan — nilai manual selalu diabaikan server.
                data.biaya_perolehan = $('#biaya_perolehan').val();
                data.masa_manfaat_bln = parseInt($('#masa_manfaat_bln').val(), 10) || 0;
                data.umur_jalan_bln = parseInt($('#umur_jalan_bln').val(), 10) || 0;
            }

            const url = id ? 'api/asset_update.php?id=' + id : 'api/asset_create.php';
            const method = id ? 'PUT' : 'POST';

            $.ajax({
                url: url,
                type: method,
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(response) {
                    if (response.success) {
                        showPopup('success', 'Berhasil', 'Data berhasil disimpan!', function() {
                            closeModal();
                            reloadAfter();
                        });
                    } else {
                        showPopup('error', 'Gagal', response.message || 'Gagal menyimpan data');
                    }
                },
                error: function() {
                    showPopup('error', 'Error', 'Terjadi kesalahan server');
                }
            });
        }

        // ===== [RUMUS PENYUSUTAN 26-09-2026] Auto-calc beban & akumulasi (modal add/edit) =====
        // Rumus sama persis dengan asset_form.php & app/penyusutan.php: floor(biaya/masa), floor(biaya*umur/masa)
        function hitungPenyusutanUI() {
            var b = parseInt($('#biaya_perolehan').val(), 10) || 0,
                m = parseInt($('#masa_manfaat_bln').val(), 10) || 0,
                u = parseInt($('#umur_jalan_bln').val(), 10) || 0;
            // Masa manfaat 0 / biaya 0 (mis. tanah) -> beban & akumulasi = 0
            if (b <= 0 || m <= 0) {
                $('#beban_penyusutan_bln').val(0);
                $('#akumulasi_penyusutan').val(0);
                return;
            }
            $('#beban_penyusutan_bln').val(Math.floor(b / m));      // beban = biaya / masa
            $('#akumulasi_penyusutan').val(Math.floor(b * u / m));  // akumulasi = biaya * umur / masa
        }
        $('#biaya_perolehan, #masa_manfaat_bln, #umur_jalan_bln').on('input change', hitungPenyusutanUI);

        // ===== [FORM DALAM FORM: KUNCI KEUANGAN EDIT 27-09-2026] =====
        // Slide 2 modal add/edit & bagian penyusutan modal Edit Keseluruhan TERKUNCI:
        // verifikasi NIK+password admin via api/finance_auth_verify.php. Server menyetel
        // flag $_SESSION['finance_unlock'] (TTL 15 menit, sekali pakai per simpan) yang
        // diverifikasi ULANG di api/asset_create.php / asset_update.php /
        // asset_update_bulk.php — kunci bukan hanya di UI, tapi juga di server.
        function efGoStep(n) {
            $('#efPane1, #efPane2').removeClass('active');
            $('#efStep1, #efStep2').removeClass('active');
            if (n === 2) {
                $('#efPane2').addClass('active');
                $('#efStep2').addClass('active');
                if ($('#efFinanceBody').data('unlocked')) {
                    $('#biaya_perolehan').trigger('focus');
                } else {
                    $('#efLockNik').trigger('focus');
                }
            } else {
                $('#efPane1').addClass('active');
                $('#efStep1').addClass('active');
            }
        }

        function efSetLocked(locked) {
            const $body = $('#efFinanceBody'), $lock = $('#efFinanceLock');
            $('#biaya_perolehan, #masa_manfaat_bln, #umur_jalan_bln').prop('disabled', locked);
            if (locked) {
                $body.data('unlocked', false).hide();
                $lock.show();
                $('#efLockNik, #efLockPassword').val('');
                $('#efLockError').hide();
            } else {
                $body.data('unlocked', true).show();
                $lock.hide();
                hitungPenyusutanUI();
            }
        }
        function efLockFinance() { efSetLocked(true); }

        // [VALIDASI TEKS 27-09-2026] NIK/password kosong: tampilkan pesan di bawah form
        // (#efLockError) seperti modal hapus — tidak lagi menandai kolom dengan merah.
        function efUnlockFinance() {
            const nik = $.trim($('#efLockNik').val() || ''),
                  pass = $('#efLockPassword').val() || '',
                  $btn = $('#efUnlockBtn'), $err = $('#efLockError');
            $err.hide();
            if (!nik || !pass) { $err.text('NIK dan password wajib diisi.').show(); return; }
            $btn.prop('disabled', true).addClass('ef-btn-loading');
            fetch('api/finance_auth_verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nik: nik, password: pass })
            }).then(function(r) { return r.json(); }).then(function(res) {
                $btn.prop('disabled', false).removeClass('ef-btn-loading');
                if (res.success) {
                    efSetLocked(false);
                    $('#efLockPassword').val('');
                } else {
                    $err.text(res.message || 'Verifikasi gagal.').show();
                }
            }).catch(function() {
                $btn.prop('disabled', false).removeClass('ef-btn-loading');
                $err.text('Terjadi kesalahan server saat verifikasi.').show();
            });
        }
        $('#efLockNik, #efLockPassword').on('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); efUnlockFinance(); }
        });

        // --- Kunci keuangan versi modal Edit Keseluruhan (bulk) ---
        function efSetBulkLocked(locked) {
            const $body = $('#efBulkFinanceBody'), $lock = $('#efBulkFinanceLock');
            $('#editBiaya, #editMasaManfaat, #editUmurJalan').prop('disabled', locked);
            if (locked) {
                $body.data('unlocked', false).hide();
                $lock.show();
                $('#efBulkLockNik, #efBulkLockPassword').val('');
                $('#efBulkLockError').hide();
            } else {
                $body.data('unlocked', true).show();
                $lock.hide();
            }
        }
        function efLockBulkFinance() { efSetBulkLocked(true); }

        function efUnlockBulkFinance() {
            const nik = $.trim($('#efBulkLockNik').val() || ''),
                  pass = $('#efBulkLockPassword').val() || '',
                  $btn = $('#efBulkUnlockBtn'), $err = $('#efBulkLockError');
            $err.hide();
            if (!nik || !pass) { $err.text('NIK dan password wajib diisi.').show(); return; }
            $btn.prop('disabled', true).addClass('ef-btn-loading');
            fetch('api/finance_auth_verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nik: nik, password: pass })
            }).then(function(r) { return r.json(); }).then(function(res) {
                $btn.prop('disabled', false).removeClass('ef-btn-loading');
                if (res.success) {
                    efSetBulkLocked(false);
                    $('#efBulkLockPassword').val('');
                } else {
                    $err.text(res.message || 'Verifikasi gagal.').show();
                }
            }).catch(function() {
                $btn.prop('disabled', false).removeClass('ef-btn-loading');
                $err.text('Terjadi kesalahan server saat verifikasi.').show();
            });
        }
        $('#efBulkLockNik, #efBulkLockPassword').on('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); efUnlockBulkFinance(); }
        });

        // ===== [HAPUS ASET VERIFIKASI+NOTIF 27-09-2026] =====
        // Alur wajib hapus (single & massal): modal verifikasi NIK+password admin.
        // Dulu: showConfirm biasa -> AJAX TIDAK mengirim NIK/password -> server
        //       menolak (auth_verify_delete_credentials, HTTP 400/403/429) -> jQuery
        //       dataType:'json' gagal parse -> popup error "Unexpected ...".
        // Kini: modal verifikasi mengirim kredensial; pesan validasi tampil rapi di
        //       modal; setelah hapus sukses API mengirim notif Telegram ke SEMUA user
        //       (role admin/spv/mgr). Modal & tombol responsif di semua layar.
        var delTargets = { ids: [] };

        function openDeleteVerifyModal(ids) {
            delTargets.ids = ids.map(function(v) { return parseInt(v, 10); });
            var $list = $('#deleteVerifyList').empty();
            if (delTargets.ids.length === 1) {
                $list.html('<div>Menghapus <strong>1 data</strong> aset (ID: ' + delTargets.ids[0] + ')</div>');
            } else {
                $list.html('<div>Menghapus <strong>' + delTargets.ids.length + ' data</strong> aset</div>' +
                           '<div>ID: ' + delTargets.ids.join(', ') + '</div>');
            }
            $('#del_verify_nik').val('');
            $('#del_verify_password').val('');
            $('#deleteVerifyError').hide().text('');
            $('#deleteVerifySubmitBtn').prop('disabled', false).html('Ya, Hapus Sekarang');
            $('#deleteVerifyModal').show();
            setTimeout(function() { $('#del_verify_nik').trigger('focus'); }, 150);
        }

        function closeDeleteVerifyModal() {
            $('#deleteVerifyModal').hide();
            delTargets.ids = [];
        }

        function submitDeleteVerify() {
            var nik = $.trim($('#del_verify_nik').val());
            var pw  = $('#del_verify_password').val();
            var $err = $('#deleteVerifyError');
            $err.hide().text('');
            if (!nik || !pw) {
                $err.text('NIK dan password wajib diisi.').show();
                return;
            }
            var $btn = $('#deleteVerifySubmitBtn');
            var origLabel = $btn.html();
            $btn.prop('disabled', true).html('Memverifikasi & menghapus...');
            var isBulk = delTargets.ids.length > 1;
            var url = isBulk ? 'api/asset_delete_bulk.php' : 'api/asset_delete.php';
            var payload = isBulk
                ? { ids: delTargets.ids, nik: nik, password: pw }
                : { id: delTargets.ids[0], nik: nik, password: pw };
            $.ajax({
                url: url,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        closeDeleteVerifyModal();
                        showPopup('success', 'Berhasil', response.message || 'Data berhasil dihapus!', function() { reloadAfter(); });
                    } else {
                        $btn.prop('disabled', false).html(origLabel);
                        $err.text((response && response.message) || 'Verifikasi gagal.').show();
                    }
                },
                error: function(xhr) {
                    // HTTP 400/403/429 dari server tetap JSON (need_verify):
                    // tampilkan pesan verifikasi di modal, BUKAN popup "Unexpected ...".
                    $btn.prop('disabled', false).html(origLabel);
                    var msg = 'Terjadi kesalahan server. Coba lagi.';
                    try {
                        var r = xhr.responseJSON;
                        if (!r && xhr.responseText) { r = JSON.parse(xhr.responseText); }
                        if (r && r.message) msg = r.message;
                    } catch (e) {}
                    $err.text(msg).show();
                }
            });
        }

        function deleteRow(id) {
            if (document.activeElement) document.activeElement.blur();
            openDeleteVerifyModal([id]);
        }

        function mutasiSelected() {
            const checkedBoxes = $('.row-checkbox:checked');
            if (checkedBoxes.length === 0) {
                showPopup('warning', 'Perhatian', 'Pilih minimal 1 aset yang ingin dimutasi');
                return;
            }

            // Kumpulkan data aset dari baris yang dicentang
            // Kolom: [0]=checkbox, [1]=cabang, [2]=toko, [3]=sub_code, [4]=kategori, [5]=keterangan,
            //        [6]=no_seri, [7]=kuantitas, [8]=biaya, [9]=masa_manfaat, [10]=beban_penyusutan,
            //        [11]=umur_jalan, [12]=akumulasi, [13]=status
            const assets = [];
            checkedBoxes.each(function() {
                const id = parseInt(this.value);
                const tds = $(this).closest('tr').find('td');
                assets.push({
                    id: id,
                    no_seri:           tds.eq(6).text().trim(),
                    kategori:          tds.eq(4).text().trim(),
                    sub_code:          tds.eq(3).text().trim(),
                    toko:              tds.eq(2).text().trim(),
                    keterangan:        tds.eq(5).text().trim(),
                    kuantitas:         parseInt(tds.eq(7).text().replace(/[^0-9]/g, '')) || 1,
                    biaya_perolehan:   parseInt(tds.eq(8).text().replace(/[^0-9]/g, '')) || 0,
                    akumulasi_penyusutan: parseInt(tds.eq(12).text().replace(/[^0-9]/g, '')) || 0,
                    status:            tds.eq(13).find('.badge-aktif').length ? 'Aktif' : (tds.eq(13).find('.badge-nonaktif').length ? 'Non Aktif' : 'Aktif')
                });
            });

            // Simpan ke localStorage lalu redirect ke halaman buat permintaan
            localStorage.setItem('mutasi_preload', JSON.stringify(assets));
            window.location.href = 'request_create.php';
        }

        function deleteSelected() {
            // [HAPUS ASET VERIFIKASI+NOTIF 27-09-2026] beralih ke modal verifikasi
            // NIK+password admin (tidak lagi showConfirm biasa tanpa kredensial).
            const checkedIds = $('.row-checkbox:checked').map(function() { return this.value; }).get();
            if (checkedIds.length === 0) {
                showPopup('warning', 'Perhatian', 'Pilih data yang ingin dihapus');
                return;
            }
            if (document.activeElement) document.activeElement.blur();
            openDeleteVerifyModal(checkedIds);
        }

        // [HAPUS ASET VERIFIKASI+NOTIF 27-09-2026] Enter di modal verifikasi = submit
        $('#del_verify_nik, #del_verify_password').on('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); submitDeleteVerify(); }
        });

        function openBulkEditModal() {
            const checkedCount = $('.row-checkbox:checked').length;
            if (checkedCount < 2) {
                showPopup('warning', 'Perhatian', 'Pilih minimal 2 data untuk edit keseluruhan');
                return;
            }
            $('#bulkEditCount').text(checkedCount);
            $('#bulkEditForm')[0].reset();
            // Disable semua input + bersihkan nilai + tutup dropdown
            $('#bulk_cabang, #bulk_toko, #bulk_kategori, #bulk_keterangan, #bulk_status, #bulk_biaya, #bulk_masamanfaat, #bulk_umurjalan').prop('disabled', true).val('');
            $('.ac-drop[id^="ac-drop-bulk_"]').hide();
            $('#editCabang, #editToko, #editKategori, #editKeterangan, #editStatus, #editBiaya, #editMasaManfaat, #editUmurJalan').prop('checked', false);
            efLockBulkFinance(); // [KUNCI KEUANGAN 27-09-2026] bagian penyusutan selalu terkunci saat modal dibuka
            $('#bulkEditModal').show();
        }

        function closeBulkEditModal() {
            // Chiudi tutti i dropdown autocomplete prima di nascondere il modal
            $('.ac-drop[id^="ac-drop-bulk_"]').hide();
            $('#bulkEditModal').hide();
            $('#bulkEditForm')[0].reset();
        }

        $(document).on('change', '#editCabang, #editToko, #editKategori, #editKeterangan, #editStatus, #editBiaya, #editMasaManfaat, #editUmurJalan', function() {
            const fieldName = this.id.replace('edit', '').toLowerCase();
            const inputId = 'bulk_' + fieldName;
            const $field = $('#' + inputId);
            if (this.checked) {
                $field.prop('disabled', false).css({'background':'#fff','color':'#1f2937'}).focus();
            } else {
                $field.prop('disabled', true).css({'background':'#f3f4f6','color':'#9ca3af'}).val('');
            }
        });

        $('#bulkEditForm').on('submit', function(e) {
            e.preventDefault();
            submitBulkEdit();
        });

        function submitBulkEdit() {
            const checkedIds = $('.row-checkbox:checked').map(function() { return this.value; }).get();
            const updateData = {};

            // [KUNCI KEUANGAN EDIT 27-09-2026] biaya perolehan / masa / umur hanya boleh dikirim bila
            // bagian penyusutan sudah dibuka dengan NIK+password admin (server menolak tanpa flag).
            if (($('#editBiaya').is(':checked') || $('#editMasaManfaat').is(':checked') || $('#editUmurJalan').is(':checked'))
                && $('#efBulkFinanceBody').data('unlocked') !== true) {
                showPopup('warning', 'Bagian Terkunci', 'Buka bagian "Biaya & Penyusutan" dengan NIK & password admin terlebih dahulu.');
                return;
            }

            if ($('#editCabang').is(':checked')) updateData.cabang = $('#bulk_cabang').val();
            if ($('#editToko').is(':checked')) updateData.toko = $('#bulk_toko').val();
            if ($('#editKategori').is(':checked')) updateData.kategori = $('#bulk_kategori').val();
            if ($('#editKeterangan').is(':checked')) updateData.keterangan = $('#bulk_keterangan').val();
            if ($('#editStatus').is(':checked')) updateData.status = $('#bulk_status').val();
            // [RESTORASI KOLOM PENYUSUTAN 27-09-2026] biaya/masa/umur diedit massal → beban & akumulasi
            // dihitung ulang otomatis per baris oleh api/asset_update_bulk.php (rumus 26-09-2026)
            if ($('#editBiaya').is(':checked'))       updateData.biaya_perolehan  = Math.max(0, parseInt($('#bulk_biaya').val(), 10) || 0);
            if ($('#editMasaManfaat').is(':checked')) updateData.masa_manfaat_bln = Math.max(0, parseInt($('#bulk_masamanfaat').val(), 10) || 0);
            if ($('#editUmurJalan').is(':checked'))   updateData.umur_jalan_bln   = Math.max(0, parseInt($('#bulk_umurjalan').val(), 10) || 0);

            if (Object.keys(updateData).length === 0) {
                showPopup('warning', 'Perhatian', 'Pilih minimal 1 field untuk diubah');
                return;
            }

            // Blur semua elemen aktif agar tidak ada sisa event keyboard dari form
            if (document.activeElement) document.activeElement.blur();

            showConfirm(
                'warning',
                'Konfirmasi Perubahan',
                'Yakin ingin mengubah <strong>' + checkedIds.length + ' data</strong> sekaligus?',
                function() {
                    $.ajax({
                        url: 'api/asset_update_bulk.php',
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ ids: checkedIds, data: updateData }),
                        success: function(response) {
                            // API returns {ok: true/false} — cek response.ok bukan response.success
                            if (response.ok || response.success) {
                                showPopup('success', 'Berhasil', response.message || 'Data berhasil diperbarui!', function() {
                                    closeBulkEditModal();
                                    reloadAfter();
                                });
                            } else {
                                showPopup('error', 'Gagal', response.message || 'Gagal memperbarui data');
                            }
                        },
                        error: function() {
                            showPopup('error', 'Error', 'Terjadi kesalahan server');
                        },
                        dataType: 'json'
                    });
                },
                null,
                'Ya, Ubah'
            );
        }

        window.onclick = function(event) {
            const dataModal = document.getElementById("dataModal");
            const bulkEditModal = document.getElementById("bulkEditModal");
            const deleteVerifyModal = document.getElementById("deleteVerifyModal");
            if (event.target === dataModal) {
                closeModal();
            }
            if (event.target === bulkEditModal) {
                closeBulkEditModal();
            }
            if (event.target === deleteVerifyModal) {
                closeDeleteVerifyModal();
            }
        }

        // ===== AUTOCOMPLETE - update datalist keterangan saat kategori dipilih =====
        (function() {
            var _ketCache = {};

            function _updateKetDL(kat) {
                var key = kat || '__all__';
                if (_ketCache[key]) { _applyKet(_ketCache[key]); return; }
                var url = 'api/asset_autocomplete.php?field=keterangan&q=&limit=300' + (kat ? '&kategori=' + encodeURIComponent(kat) : '');
                fetch(url).then(function(r){ return r.json(); }).then(function(j){
                    var items = (j.success && j.data) ? j.data : [];
                    _ketCache[key] = items;
                    _applyKet(items);
                }).catch(function(){});
            }

            function _applyKet(items) {
                var dl = document.getElementById('dl-keterangan');
                if (!dl) return;
                dl.innerHTML = items.map(function(v){
                    return '<option value="' + String(v).replace(/"/g,'&quot;').replace(/</g,'&lt;') + '">';
                }).join('');
            }

            // Saat kategori berubah → update datalist keterangan
            document.addEventListener('change', function(e) {
                if (e.target.id === 'kategori' || e.target.id === 'bulk_kategori') {
                    _updateKetDL(e.target.value);
                }
            });
            document.addEventListener('input', function(e) {
                if (e.target.id === 'kategori' || e.target.id === 'bulk_kategori') {
                    clearTimeout(e.target._ktt);
                    e.target._ktt = setTimeout(function(){ _updateKetDL(e.target.value); }, 400);
                }
            });
        })();
    </script>
    <style>
        .ac-drop { display:none; }
    </style>

    <?php
    // Ambil keterangan unik untuk datalist
    $keterangans = $pdo->query("SELECT DISTINCT keterangan FROM assets_real WHERE keterangan IS NOT NULL AND keterangan != '' ORDER BY keterangan LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
    ?>

    <!-- ===== DATALIST untuk autocomplete native ===== -->
    <datalist id="dl-cabang">
        <?php foreach ($cabangs as $v): ?>
        <option value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
    </datalist>
    <datalist id="dl-toko">
        <?php foreach ($tokos as $v): ?>
        <option value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
    </datalist>
    <datalist id="dl-kategori">
        <?php foreach ($kategoris as $v): ?>
        <option value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
    </datalist>
    <datalist id="dl-keterangan">
        <?php foreach ($keterangans as $v): ?>
        <option value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
    </datalist>

    <?php echo_loading_js(); ?>
</body>
</html>



