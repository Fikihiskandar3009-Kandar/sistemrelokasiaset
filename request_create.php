<?php
/**
 * ==== BUAT PERMINTAAN RELOKASI ====
 * Pilih aset (tunggal / massal), lokasi asal-tujuan, nomor SJ otomatis (RA/SA/PS/PA/PP)
 * Akses: admin | Terkait: api/request_store.php, api/request_store_bulk.php, api/generate_sj.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/rbac.php';
auth_require();
$user = auth_user();

// Hanya admin yang bisa buat permintaan
if ($user['role'] !== 'admin') {
    header('Location: dashboard.php'); exit;
}

$pdo = db();

// Fetch assets with location info from assets_real for more details
$assets = $pdo->query("
    SELECT a.*, l.name as current_location,
           ar.cabang, ar.toko, ar.kategori, ar.no_seri, ar.keterangan,
           ar.sub_code, ar.kuantitas
    FROM assets a 
    LEFT JOIN locations l ON l.id = a.location_id
    LEFT JOIN assets_real ar ON ar.id = a.id
    ORDER BY a.asset_code
")->fetchAll();

// Also get simple assets list for bulk modal
$assets_real_list = $pdo->query("
    SELECT id, cabang, toko, sub_code, kategori, no_seri, keterangan, kuantitas, biaya_perolehan, akumulasi_penyusutan, status
    FROM assets_real
    ORDER BY cabang, toko, kategori
")->fetchAll();

// Get toko-location mapping dari assets_real (semua toko)
$toko_locations = [];
$all_toko = [];
$toko_location_map = [];
$all_toko_map = [];

try {
    $toko_locations = $pdo->query("
        SELECT DISTINCT toko, cabang
        FROM assets_real
        WHERE toko IS NOT NULL AND toko != ''
        ORDER BY toko
    ")->fetchAll();

    $all_toko = $pdo->query("
        SELECT DISTINCT toko, cabang
        FROM assets_real
        WHERE toko IS NOT NULL AND toko != ''
        ORDER BY cabang, toko
    ")->fetchAll();

    // Ambil semua locations untuk mapping
    $all_locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll();
    $location_by_name = [];
    foreach ($all_locations as $loc) {
        $location_by_name[$loc['name']] = $loc['id'];
    }

    // Auto-create locations untuk toko yang belum ada
    foreach ($all_toko as $t) {
        $tokoName = $t['toko'];
        if (!isset($location_by_name[$tokoName])) {
            try {
                $pdo->prepare("INSERT IGNORE INTO locations (name) VALUES (?)")->execute([$tokoName]);
                $newId = $pdo->lastInsertId();
                if (!$newId) {
                    $newId = $pdo->query("SELECT id FROM locations WHERE name = " . $pdo->quote($tokoName))->fetchColumn();
                }
                $location_by_name[$tokoName] = $newId ?: 1;
            } catch (Exception $e) {
                $location_by_name[$tokoName] = 1;
            }
        }
    }

    // Convert to key-value array for JavaScript
    foreach ($toko_locations as $tl) {
        $locId = $location_by_name[$tl['toko']] ?? 1;
        $toko_location_map[$tl['toko']] = [
            'location_id' => (int)$locId,
            'location_name' => $tl['toko'],
            'cabang' => $tl['cabang']
        ];
    }

    foreach ($all_toko as $t) {
        $locId = $location_by_name[$t['toko']] ?? 1;
        $all_toko_map[$t['toko']] = [
            'location_id' => (int)$locId,
            'location_name' => $t['toko'],
            'cabang' => $t['cabang']
        ];
    }
} catch (Exception $e) {
    // Jika error, tetap lanjut dengan data kosong
    error_log("request_create.php toko mapping error: " . $e->getMessage());
}

$locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Permintaan Relokasi - SRA Indomaret Parung</title>
    <?php echo asset_css('css/layout-simple.css'); ?>
    <?php echo asset_css('css/indomaret-theme.css'); ?>
    <?php echo asset_css('css/app-theme.css'); ?>
    <?php echo asset_css('css/modern-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/layout-override.css'); ?>
    <?php echo_loading_css(); ?>
    <?php echo asset_js('js/app-ui.js', 'defer'); ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
        
        .content-area {
            padding: 0.4cm;
            max-width: 100%;
        }
        
        .container {
            max-width: 100%;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            width: 100%;
            min-height: 100%;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .nav a {
            padding: 8px 15px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.3s;
        }
        
        .nav a:hover {
            background: #0056b3;
        }
        
        .header {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            flex: 1;
        }
        
        .asset-info {
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            display: none;
            font-size: 0.9rem;
        }

        .form-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-row button {
            width: auto;
            padding: 8px 15px;
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .form-row button:hover {
            background: #138496;
        }

        button[type="submit"] {
            width: 100%;
            padding: 12px;
        }

        .autocomplete-input {
            position: relative;
            width: 100%;
            min-width: 0;
        }

        .autocomplete-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: 1px solid #ddd;
            max-height: 300px;
            overflow-y: auto;
            display: none;
            z-index: 1001;
            border-radius: 0 0 4px 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-top: -1px;
        }

        .autocomplete-list.show {
            display: block !important;
        }

        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
        }

        .autocomplete-item:hover {
            background: #e8f4f8;
            color: #007bff;
        }

        /* Inline validation errors */
        .field-error {
            color: #dc2626;
            font-size: .8rem;
            font-weight: 600;
            margin-top: 4px;
            padding: 2px 0;
        }
        .input-error {
            border-color: #dc2626 !important;
            background: #fef2f2 !important;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item strong {
            color: #007bff;
            display: block;
            margin-bottom: 2px;
        }

        .autocomplete-item small {
            color: #666;
            font-size: 0.85em;
        }

        .bulk-page {
            display: none;
            background: #f5f5f5;
            border-radius: 0;
            box-shadow: none;
            margin: 0;
            padding: 0;
            overflow: hidden;
            border: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            width: 100%;
            height: 100vh;
        }

        .bulk-page.active {
            display: flex;
        }

        .bulk-card {
            display: flex;
            flex-direction: column;
            flex: 1;
            width: 100%;
            height: 100vh;
            background: #f5f5f5;
            overflow: hidden;
        }

        
        #mainFormWrapper {
            display: block;
        }
        
        #mainFormWrapper.hidden-by-bulk {
            display: none;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0;
            background: transparent;
            color: #fff;
            display: none;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.1rem;
            letter-spacing: 0.3px;
            display: none;
        }

        .close-btn {
            font-size: 26px;
            font-weight: bold;
            cursor: pointer;
            color: #e8f1ff;
            line-height: 1;
        }

        .close-btn:hover {
            color: #ffffff;
        }

        .modal-body {
            flex: 1;
            padding: 10px 20px 6px;
            display: flex;
            flex-direction: column;
            gap: 0;
            overflow: hidden;
            background: #f5f5f5;
            min-height: 0;
            width: 100%;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            margin-bottom: 0;
            flex-wrap: wrap;
            align-items: center;
            flex-shrink: 0;
        }

        .filter-group select {
            flex: 1;
            min-width: 100px;
            padding: 8px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .filter-group input {
            flex: 2;
            padding: 8px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        .filter-group button {
            padding: 8px 12px;
            background: #0d6efd;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .filter-group button:hover {
            background: #0b5ed7;
        }

        .asset-list {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            border: none;
            padding: 6px 10px 10px;
            border-radius: 0;
            background: #f5f5f5;
            width: 100%;
        }

        .asset-list::-webkit-scrollbar {
            width: 8px;
        }

        .asset-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .asset-list::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .asset-list::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .asset-item {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
            display: grid;
            grid-template-columns: 18px 1fr;
            grid-auto-rows: min-content;
            column-gap: 8px;
            row-gap: 4px;
            font-size: 0.82rem;
            min-height: 155px;
            height: 155px;
            overflow: hidden;
        }

        .asset-item:hover {
            background: #f5f9ff;
            border-color: #007bff;
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
        }

        .asset-item:focus {
            outline: 2px solid #007bff;
            outline-offset: 2px;
            background: #f5f9ff;
            border-color: #007bff;
        }

        .asset-item.selected {
            background: #e7f3ff;
            border-color: #28a745;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.15);
        }

        .asset-item input[type="checkbox"] {
            margin-top: 2px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            grid-column: 1;
            grid-row: 1 / 3;
        }

        .asset-item-text {
            font-size: 0.76rem;
            font-weight: 600;
            color: #333;
            line-height: 1.25;
            grid-column: 2;
        }

        .asset-item-desc {
            font-size: 0.68rem;
            color: #666;
            margin-top: 2px;
            line-height: 1.2;
            grid-column: 2;
            display: -webkit-box;
            -webkit-line-clamp: 6;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Selected assets card grid */
        .selected-assets-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }

        @media (max-width: 1200px) {
            .selected-assets-grid { grid-template-columns: repeat(5, 1fr); }
        }
        @media (max-width: 900px) {
            .selected-assets-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 600px) {
            .selected-assets-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .selected-asset-card {
            background: #fff;
            border: 2px solid #28a745;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 0.76rem;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .selected-asset-card .sac-name {
            font-weight: 700;
            color: #007bff;
            font-size: 0.95rem;
            line-height: 1.2;
            padding-right: 4px;
            margin-bottom: 2px;
        }

        .selected-asset-card .sac-code {
            font-weight: 700;
            color: #333;
            font-size: 0.78rem;
            line-height: 1.2;
            padding-right: 4px;
        }

        .selected-asset-card .sac-meta {
            color: #555;
            font-size: 0.68rem;
            line-height: 1.3;
        }

        .selected-asset-card .sac-remove {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-top: 6px;
            background: transparent;
            color: #dc3545;
            border: 1.5px solid #dc3545;
            border-radius: 4px;
            padding: 4px 0;
            width: 100%;
            font-size: 0.70rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: background 0.15s, color 0.15s;
        }

        .selected-asset-card .sac-remove:hover {
            background: #dc3545;
            color: #fff;
        }

        body[data-theme="dark"] .selected-asset-card {
            background: var(--color-card) !important;
            border-color: #28a745 !important;
        }

        body[data-theme="dark"] .selected-asset-card .sac-meta,
        body[data-theme="dark"] .selected-asset-card .sac-code {
            color: var(--color-text) !important;
        }

        /* Dark mode support for request & bulk pages */
        body[data-theme="dark"] .form-container,
        body[data-theme="dark"] .bulk-page,
        body[data-theme="dark"] .bulk-card,
        body[data-theme="dark"] .modal-body {
            background: var(--color-bg) !important;
            color: var(--color-text) !important;
        }

        body[data-theme="dark"] .asset-item {
            background: var(--color-card) !important;
            border-color: var(--color-border) !important;
        }

        body[data-theme="dark"] .asset-item-text,
        body[data-theme="dark"] .asset-item-desc {
            color: var(--color-text) !important;
        }

        body[data-theme="dark"] .filter-group input,
        body[data-theme="dark"] .filter-group select,
        body[data-theme="dark"] #modalSearch {
            background: var(--color-card) !important;
            color: var(--color-text) !important;
            border-color: var(--color-border) !important;
        }

        body[data-theme="dark"] .selected-count,
        body[data-theme="dark"] .modal-actions {
            background: var(--color-bg) !important;
            border-color: var(--color-border) !important;
        }

        body[data-theme="dark"] .asset-list {
            background: var(--color-bg) !important;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding: 8px 20px 12px;
            margin-top: 0;
            border-top: 1px solid #ddd;
            flex-shrink: 0;
            background: #f5f5f5;
            position: sticky;
            bottom: 0;
            z-index: 10;
        }

        .modal-actions button {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .btn-bulk-add {
            background: #28a745;
            color: white;
        }

        .btn-bulk-add:hover {
            background: #218838;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }

        .selected-count {
            padding: 10px 20px;
            background: #d4edda;
            border-radius: 0;
            margin-bottom: 0;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
            border-bottom: 1px solid #c3e6cb;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .nav {
                width: 100%;
            }
            
            .nav a {
                flex: 1;
                text-align: center;
            }
            
            .form-container {
                padding: 15px;
            }

            .form-row {
                flex-direction: column;
                align-items: stretch;
            }

            .form-row button {
                width: 100%;
            }

            .asset-list {
                grid-template-columns: repeat(3, 1fr);
            }

            .bulk-card {
                height: 100vh;
                max-height: 100vh;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group select,
            .filter-group input,
            .filter-group button {
                width: 100%;
            }

            .modal-content {
                width: 96vw;
                max-width: 96vw;
                aspect-ratio: unset;
                height: 85vh;
            }
            
            .container {
                max-width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .header h2 {
                font-size: 1.3em;
            }
            
            .nav a {
                padding: 6px 10px;
                font-size: 0.85em;
            }
            
            .form-container {
                padding: 10px;
            }
            
            .filter-group {
                flex-direction: column;
            }
            
            .filter-group select,
            .filter-group input {
                width: 100%;
            }
            
            .asset-list {
                grid-template-columns: 1fr;
            }

            .asset-item {
                height: auto !important;
                min-height: auto !important;
            }
        }
        
        @media (min-width: 481px) and (max-width: 768px) {
            .asset-list {
                grid-template-columns: repeat(2, 1fr);
            }

            .asset-item {
                height: auto !important;
                min-height: auto !important;
            }
        }
        
        @media (min-width: 769px) {
            .form-row {
                gap: 10px;
                align-items: flex-end;
            }
            
            .form-row .form-group {
                flex: 1;
            }
            
            .form-row button {
                width: auto;
                padding: 8px 15px;
            }
        }
    </style>
</head>
<body class="sidebar-hidden">
    <?php $page_title = 'Buat Permintaan'; $page_icon = ''; include __DIR__.'/app/header-sidebar.php'; ?>
    <div class="content-area">
        <?php if (!empty($_SESSION['error'])): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                showPopup('error', 'Error', <?= json_encode($_SESSION['error']) ?>);
            });
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                showPopup('success', 'Berhasil', <?= json_encode($_SESSION['success']) ?>);
            });
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <div id="mainFormWrapper" class="form-container">
            <?php if (count($assets) === 0): ?>
                <div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 20px; color: #856404;">
                    <strong>! Perhatian:</strong> Tidak ada aset yang tersedia. Silakan hubungi administrator untuk mengimpor data aset terlebih dahulu.
                </div>
            <?php endif; ?>
            
            <?php if (count($locations) < 2): ?>
                <div style="padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 20px; color: #721c24;">
                    <strong>! Perhatian:</strong> Tidak ada lokasi yang tersedia. Silakan hubungi administrator untuk menambahkan lokasi terlebih dahulu.
                </div>
            <?php endif; ?>

            <form method="post" action="#" id="mainForm">
                <input type="hidden" name="from_location_id" id="from_location_id" value="0">
                <div class="form-group">
                    <label for="asset_id">Pilih Aset</label>
                    <div class="form-row" style="display:flex; align-items:stretch; gap:8px; overflow:visible;">
                        <div class="form-group autocomplete-input" style="flex: 1; position: relative; margin:0; min-width:0;">
                            <input type="text" id="asset_search" placeholder="Ketik kode, kategori, atau deskripsi untuk mencari..." 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; height:38px;">
                            <div id="assetAutocomplete" class="autocomplete-list"></div>
                        </div>
                        <button type="button" onclick="openBulkPage()" style="flex-shrink:0; background: #17a2b8; color:#fff; border:none; border-radius:4px; padding: 0 16px; height:38px; font-weight:600; font-size:.9rem; cursor:pointer; white-space:nowrap; position:relative; z-index:1;">
                            Pilih Massal
                        </button>
                    </div>
                </div>

                <!-- Selected Assets Display Section -->
                <div class="form-group" id="selectedAssetsSection" style="display: none;">
                    <label style="font-weight: bold; color: #28a745;">
                        Aset yang Dipilih : <span id="selectedAssetsCount" style="color:#007bff; font-weight:700;">0 Item Terpilih</span>
                    </label>
                    
                    <!-- Display toko locations -->
                    <div id="tokoLocationsDisplay" style="margin-bottom: 15px; padding: 12px; background: #e3f2fd; border: 1px solid #90caf9; border-radius: 4px; display: none;">
                        <strong style="color: #1976d2;">Toko Sumber:</strong>
                        <div id="tokoLocationsList" style="margin-top: 8px;"></div>
                    </div>
                    
                    <div id="selectedAssetsList" style="border: 2px solid #28a745; border-radius: 4px; padding: 10px; background: #f0fff4; max-height: 400px; overflow-y: auto;">
                        <!-- Selected items will appear here -->
                    </div>
                </div>

                <div class="form-group">
                    <label for="jenis_sj">Jenis Surat Jalan *</label>
                    <select name="jenis_sj" id="jenis_sj" onchange="onJenisSjChange()"
                            style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem; background: white;">
                        <option value="RA">Relokasi Aset</option>
                        <option value="SA">Sewa Aset (F)</option>
                        <option value="PS">Pengembalian Sewa (F)</option>
                        <option value="PA">Pinjam Aset (T & F)</option>
                        <option value="PP">Pengembalian Pinjam (F & T)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="to_location_input">Ke Lokasi (Toko Tujuan) *</label>
                    <div style="position: relative;">
                        <input type="text" id="to_location_input" placeholder="Ketik nama toko tujuan..." 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px;">
                        <input type="hidden" name="to_location_id" id="to_location_id" value="">
                        <div id="tokoDestinationAutocomplete" class="autocomplete-list" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-top: 1px solid #ddd; max-height: 300px; overflow-y: auto; display: none; z-index: 1001; border-radius: 0 0 4px 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="sj_display">No Surat Jalan</label>
                    <div style="position:relative;">
                        <input type="text" id="sj_display" readonly
                               style="width:100%; padding:8px 12px; border:1px solid #ddd; border-radius:4px;
                                      font-family:inherit; font-size:.9rem; background:#f3f4f6;
                                      color:#374151; cursor:not-allowed; box-sizing:border-box;">
                    </div>
                    <input type="hidden" name="reason" id="sj_number" value="">
                </div>

                <!-- Warning & alasan untuk aset non-aktif -->
                <div id="inactive-warning" style="display:none; background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
                    <div style="font-weight:600; color:#92400e; margin-bottom:8px;">Aset yang dipilih berstatus Non-Aktif / Rusak, isi alasan untuk melanjutkan *</div>
                    <p style="font-size:.85rem; color:#78350f; margin-bottom:10px;"></p>
                    <label for="inactive_reason" style="font-weight:600; color:#92400e; font-size:.85rem;"></label>
                    <textarea name="inactive_reason" id="inactive_reason" placeholder="Contoh: Barang perlu dipindahkan ke gudang untuk proses penghapusan aset..." style="width:100%; min-height:60px; padding:8px 10px; border:1px solid #f59e0b; border-radius:4px; font-family:inherit; font-size:.9rem; margin-top:4px;"></textarea>
                </div>

                <!-- Warning & keterangan untuk relokasi franchise -->
                <div id="franchise-warning" style="display:none; background:#eff6ff; border:1px solid #3b82f6; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
                    <div style="font-weight:600; color:#1d4ed8; margin-bottom:8px;">Relokasi ke/dari Toko Franchise (F)</div>
                    <p style="font-size:.85rem; color:#1e40af; margin-bottom:10px;">Relokasi melibatkan toko franchise memerlukan <b>kode token akses 1 kali</b> dari Manager/Supervisor. Setelah submit, kode akan dikirim otomatis ke Telegram atasan.</p>
                    <label for="franchise_note" style="font-weight:600; color:#1d4ed8; font-size:.85rem;">Keterangan Alasan Relokasi Franchise *</label>
                    <textarea name="franchise_note" id="franchise_note" placeholder="Jelaskan alasan kenapa aset perlu direlokasi ke/dari toko franchise..." style="width:100%; min-height:60px; padding:8px 10px; border:1px solid #3b82f6; border-radius:4px; font-family:inherit; font-size:.9rem; margin-top:4px;"></textarea>
                </div>

                <button type="submit" id="submitBtn" disabled style="background: #ccc; cursor: not-allowed;">Kirim Permintaan (Pilih Aset Terlebih Dahulu)</button>
            </form>
        </div>

        <!-- Bulk Selection Page -->
        <div id="bulkPage" class="bulk-page">
            <div class="bulk-card">
                <!-- Filter Section -->
                <div style="background: white; padding: 14px 20px; border-bottom: 1px solid #ddd; flex-shrink: 0;">
                    <div class="filter-group" style="gap: 12px;">
                        <input type="text" id="modalSearch" placeholder="Cari: kode, kategori, deskripsi..." 
                               style="flex: 1 1 200px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem;" onkeyup="filterAssets()">
                        
                        <select id="filterCabang" onchange="updateTokoFilter(); filterAssets()" style="flex: 1; min-width: 130px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; background: white; cursor: pointer;">
                            <option value="">Semua Cabang</option>
                            <?php 
                            $cabangs = array_unique(array_column($assets_real_list, 'cabang'));
                            sort($cabangs);
                            foreach ($cabangs as $cab): 
                            ?>
                                <option value="<?= htmlspecialchars($cab) ?>"><?= htmlspecialchars($cab) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="filterToko" onchange="updateKategoriFilter(); filterAssets()" style="flex: 1; min-width: 120px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; background: white; cursor: pointer;">
                            <option value="">Semua Toko</option>
                            <?php 
                            $tokos = array_unique(array_column($assets_real_list, 'toko'));
                            sort($tokos);
                            foreach ($tokos as $toko): 
                            ?>
                                <option value="<?= htmlspecialchars($toko) ?>"><?= htmlspecialchars($toko) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="filterKategori" onchange="filterAssets()" style="flex: 1; min-width: 120px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; background: white; cursor: pointer;">
                            <option value="">Semua Kategori</option>
                            <?php 
                            $kategoris = array_unique(array_column($assets_real_list, 'kategori'));
                            sort($kategoris);
                            foreach ($kategoris as $kat): 
                            ?>
                                <option value="<?= htmlspecialchars($kat) ?>"><?= htmlspecialchars($kat) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" onclick="resetFilter()" style="flex: 0 0 auto; background: #0d6efd; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 500; font-size: 0.9rem;">Reset</button>
                        <button type="button" id="selectAllFilteredBtn" onclick="selectAllFiltered()" title="Pilih semua aset yang sedang terfilter" style="flex: 0 0 auto; background: #fff; color: #198754; border: 2px solid #198754; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 0.9rem; display:flex; align-items:center; gap:5px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            Pilih Semua
                        </button>
                        <button type="button" id="reloadAssetsBtn" onclick="reloadAssetsData()" title="Muat ulang data aset terbaru dari server" style="flex: 0 0 auto; background: #198754; color: white; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: 500; font-size: 0.9rem;">Muat Ulang</button>
                    </div>
                </div>

                <div class="modal-body">
                    <div id="selectedCount" class="selected-count" style="display: none;">
                        <span id="countValue">0</span> aset dipilih
                    </div>

                    <div id="assetListContainer" class="asset-list">
                        <!-- Assets will be populated here -->
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn-cancel" onclick="closeBulkPage()">Kembali ke Form</button>
                        <button type="button" class="btn-bulk-add" onclick="bulkAddAssets()">Tambah Terpilih</button>
                    </div>
                </div>
            </div>
        </div>

    <script>
        // Helper function to escape HTML
        function htmlEscape(str) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return str.replace(/[&<>"']/g, m => map[m]);
        }

        const assetsData = <?= json_encode($assets_real_list) ?>;
        const tokoLocationMap = <?= json_encode($toko_location_map) ?>;
        const allTokoMap = <?= json_encode($all_toko_map) ?>;
        const locationsData = <?= json_encode($locations) ?>;

        // ── Mutable copy yang bisa di-refresh tanpa reload halaman ──
        let currentAssetsData = [...assetsData];
        let currentAllTokoMap = Object.assign({}, allTokoMap);
        const assetsMap = {};
        
        // Build assets map from original assets
        <?php foreach ($assets as $asset): ?>
        assetsMap[<?= $asset['id'] ?>] = {
            code: '<?= htmlspecialchars($asset['asset_code']) ?>',
            name: '<?= htmlspecialchars($asset['name']) ?>',
            value: <?= $asset['value_amount'] ?>,
            location: '<?= htmlspecialchars($asset['current_location'] ?? 'N/A') ?>',
            locationId: <?= $asset['location_id'] ?>
        };
        <?php endforeach; ?>

        let selectedAssets = {}; // Object to store selected assets: { id: assetData }
        let filteredAssets = [...currentAssetsData];
        let displayedAssets = [];
        let itemsPerPage = 50;
        let currentPage = 0;
        let assetScrollAttached = false;

        // ── PRELOAD dari halaman Mutasi (assets.php) ──
        // Hanya populate selectedAssets di sini; display dipanggil di akhir script
        let _mutasiPreloadAssets = null;
        (function() {
            const raw = localStorage.getItem('mutasi_preload');
            if (!raw) return;
            try {
                const assets = JSON.parse(raw);
                if (!Array.isArray(assets) || assets.length === 0) return;
                assets.forEach(function(a) {
                    if (!a.id) return;
                    selectedAssets[a.id] = {
                        id: a.id,
                        no_seri: a.no_seri || '',
                        sub_code: a.sub_code || '',
                        kategori: a.kategori || '',
                        toko: a.toko || '',
                        keterangan: a.keterangan || '',
                        kuantitas: a.kuantitas || 1,
                        biaya_perolehan: a.biaya_perolehan || 0,
                        akumulasi_penyusutan: a.akumulasi_penyusutan || 0,
                        status: a.status || 'Aktif'
                    };
                });
                localStorage.removeItem('mutasi_preload');
                _mutasiPreloadAssets = true;
            } catch(e) {
                localStorage.removeItem('mutasi_preload');
            }
        })();

        // Autocomplete functionality
        const searchInput = document.getElementById('asset_search');
        const autocompleteList = document.getElementById('assetAutocomplete');

        if (searchInput && autocompleteList) {
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                if (query.length === 0) {
                    autocompleteList.classList.remove('show');
                    return;
                }

                const filtered = currentAssetsData.filter(asset => 
                    asset.no_seri?.toLowerCase().includes(query) ||
                    asset.kategori?.toLowerCase().includes(query) ||
                    asset.keterangan?.toLowerCase().includes(query) ||
                    asset.cabang?.toLowerCase().includes(query) ||
                    asset.toko?.toLowerCase().includes(query)
                ).slice(0, 10);

                if (filtered.length > 0) {
                    autocompleteList.innerHTML = filtered.map(asset => `
                        <div class="autocomplete-item" onclick="addAssetToSelection(${asset.id}, '${htmlEscape(asset.no_seri)}', '${htmlEscape(asset.kategori)}', '${htmlEscape(asset.toko)}', '${htmlEscape(asset.keterangan)}', '${asset.biaya_perolehan}', '${htmlEscape(asset.status || 'Aktif')}')">
                            <strong>${htmlEscape(asset.no_seri)}</strong><br>
                            <small>${htmlEscape(asset.kategori)} - ${htmlEscape(asset.toko)} - ${htmlEscape(asset.keterangan)}${asset.sub_code ? ' - ' + htmlEscape(asset.sub_code) : ''} (Qty: ${asset.kuantitas || 1})</small>
                        </div>
                    `).join('');
                    autocompleteList.classList.add('show');
                } else {
                    autocompleteList.classList.remove('show');
                }
            });
        }

        function addAssetToSelection(id, noSeri, kategori, toko, keterangan, biaya, status) {
            // Jika sudah dipilih, hapus; jika belum, tambah
            if (selectedAssets[id]) {
                delete selectedAssets[id];
            } else {
                const src = currentAssetsData.find(a => Number(a.id) === Number(id));
                selectedAssets[id] = {
                    id: id,
                    no_seri: noSeri,
                    sub_code: src ? (src.sub_code || '') : '',
                    kategori: kategori,
                    toko: toko,
                    keterangan: keterangan,
                    kuantitas: src ? (src.kuantitas || 1) : 1,
                    biaya_perolehan: biaya,
                    akumulasi_penyusutan: src ? (src.akumulasi_penyusutan || 0) : 0,
                    status: status || 'Aktif'
                };
            }
            
            document.getElementById('asset_search').value = '';
            autocompleteList.classList.remove('show');
            updateSelectedAssetsDisplay();
        }

        function htmlEscape(text) {
            return text ? text.replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char])) : '';
        }

        // Bulk modal functions - OPTIMIZED for performance
        function openBulkPage() {
            const bulkPage = document.getElementById('bulkPage');
            const mainForm = document.getElementById('mainFormWrapper');
            bulkPage.classList.add('active');
            mainForm.classList.add('hidden-by-bulk');
            currentPage = 0;
            filteredAssets = [...currentAssetsData];
            displayedAssets = [];
            const listContainer = document.getElementById('assetListContainer');
            if (listContainer) {
                listContainer.innerHTML = '';
            }
            if (!assetScrollAttached) {
                attachAssetListScroll();
                assetScrollAttached = true;
            }
            loadMoreAssets();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function closeBulkPage() {
            const bulkPage = document.getElementById('bulkPage');
            const mainForm = document.getElementById('mainFormWrapper');
            bulkPage.classList.remove('active');
            mainForm.classList.remove('hidden-by-bulk');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function loadMoreAssets() {
            const start = currentPage * itemsPerPage;
            const end = start + itemsPerPage;
            const newItems = filteredAssets.slice(start, end);
            
            if (newItems.length === 0) return;
            
            displayedAssets.push(...newItems);
            currentPage++;
            
            // Use fragment untuk faster rendering
            const fragment = document.createDocumentFragment();
            const container = document.getElementById('assetListContainer');
            
            newItems.forEach((asset, idx) => {
                const div = document.createElement('div');
                div.className = 'asset-item';
                div.tabIndex = 0;
                div.dataset.assetId = asset.id;
                div.onclick = () => toggleAssetCheckbox(asset.id, div);
                div.onkeydown = (e) => {
                    if (e.key === ' ' || e.key === 'Enter') {
                        e.preventDefault();
                        toggleAssetCheckbox(asset.id, div);
                    }
                };
                div.innerHTML = `
                    <input type="checkbox" class="asset-checkbox" data-id="${asset.id}" tabindex="-1" onchange="updateSelectedCount()">
                    <div class="asset-item-text">
                        <strong>${htmlEscape(asset.no_seri)}</strong>
                    </div>
                    <div class="asset-item-desc">
                        <strong style="color: #0066cc;">${htmlEscape(asset.keterangan || '-')}</strong><br>
                        ${htmlEscape(asset.kategori)} | ${htmlEscape(asset.toko)}${asset.sub_code ? ' | ' + htmlEscape(asset.sub_code) : ''}<br>
                        <em>Rp${Number(asset.biaya_perolehan || 0).toLocaleString()} | Qty: ${asset.kuantitas || 1}</em>
                    </div>
                `;
                // Restore checkbox state jika sudah dipilih sebelumnya
                if (selectedAssets[asset.id]) {
                    div.classList.add('selected');
                    div.querySelector('.asset-checkbox').checked = true;
                }
                fragment.appendChild(div);
            });
            
            container.appendChild(fragment);
        }

        function toggleAssetCheckbox(assetId, element) {
            const checkbox = element.querySelector('.asset-checkbox');
            checkbox.checked = !checkbox.checked;
            element.classList.toggle('selected');
            
            // Sinkron dengan selectedAssets
            if (checkbox.checked) {
                const asset = currentAssetsData.find(a => a.id === assetId);
                if (asset && !selectedAssets[assetId]) {
                    selectedAssets[assetId] = {
                        id: asset.id,
                        no_seri: asset.no_seri,
                        sub_code: asset.sub_code || '',
                        kategori: asset.kategori,
                        toko: asset.toko,
                        keterangan: asset.keterangan,
                        kuantitas: asset.kuantitas || 1,
                        biaya_perolehan: asset.biaya_perolehan,
                        akumulasi_penyusutan: asset.akumulasi_penyusutan || 0,
                        status: asset.status || 'Aktif'
                    };
                }
            } else {
                delete selectedAssets[assetId];
            }
            
            updateSelectedCount();
            updateSelectAllBtn();
        }

        function updateSelectedCount() {
            // Hitung dari selectedAssets (source of truth), bukan dari DOM
            const count = Object.keys(selectedAssets).length;
            const countDiv = document.getElementById('selectedCount');
            if (count > 0) {
                countDiv.style.display = 'block';
                document.getElementById('countValue').textContent = count;
            } else {
                countDiv.style.display = 'none';
            }
        }

        function removeAssetFromSelection(assetId) {
            delete selectedAssets[assetId];
            updateSelectedAssetsDisplay();
        }

        function updateSelectedAssetsDisplay() {
            const section = document.getElementById('selectedAssetsSection');
            const listDiv = document.getElementById('selectedAssetsList');
            const tokoDisplay = document.getElementById('tokoLocationsDisplay');
            const tokoList = document.getElementById('tokoLocationsList');
            const count = Object.keys(selectedAssets).length;

            if (count === 0) {
                section.style.display = 'none';
                listDiv.innerHTML = '';
                tokoDisplay.style.display = 'none';
                document.getElementById('submitBtn').disabled = true;
                document.getElementById('submitBtn').style.background = '#ccc';
                document.getElementById('submitBtn').style.cursor = 'not-allowed';
                document.getElementById('submitBtn').textContent = 'Kirim Permintaan (Pilih Aset Terlebih Dahulu)';
                document.getElementById('from_location_id').value = '';
                // Clear SJ
                document.getElementById('sj_display').value = '';
                document.getElementById('sj_number').value = '';
                return;
            }

            section.style.display = 'block';
            document.getElementById('submitBtn').disabled = false;
            document.getElementById('submitBtn').style.background = '#007bff';
            document.getElementById('submitBtn').style.cursor = 'pointer';
            document.getElementById('submitBtn').textContent = `Kirim Permintaan (${count} Aset)`;

            // Cek apakah ada aset non-aktif/rusak
            const inactiveWarning = document.getElementById('inactive-warning');
            const inactiveReasonField = document.getElementById('inactive_reason');
            const hasInactive = Object.values(selectedAssets).some(a => 
                a.status && a.status !== 'Aktif'
            );
            if (hasInactive) {
                inactiveWarning.style.display = 'block';
                inactiveReasonField.required = true;
            } else {
                inactiveWarning.style.display = 'none';
                inactiveReasonField.required = false;
                inactiveReasonField.value = '';
            }

            // Get unique toko from selected assets
            const tokosSet = new Set();
            Object.values(selectedAssets).forEach(asset => {
                tokosSet.add(asset.toko);
            });
            const tokos = Array.from(tokosSet).sort();

            // Get location IDs from toko mapping
            const locationIds = new Set();
            tokos.forEach(toko => {
                const locInfo = tokoLocationMap[toko] || currentAllTokoMap[toko];
                if (locInfo) {
                    locationIds.add(locInfo.location_id);
                }
            });

            // Display toko locations
            let tokoHtml = '';
            tokos.forEach(toko => {
                const locInfo = tokoLocationMap[toko] || currentAllTokoMap[toko];
                const cabang = locInfo ? locInfo.cabang : 'Cabang Tidak Diketahui';
                tokoHtml += `<div style="padding: 8px 0; border-bottom: 1px solid #90caf9;"><strong style="color: #1565c0;">${htmlEscape(toko)}</strong> → ${htmlEscape(cabang)}</div>`;
            });
            tokoList.innerHTML = tokoHtml;
            tokoDisplay.style.display = 'block';

            // Auto-set from_location_id (hidden field) if all assets from same location
            if (locationIds.size === 1) {
                const fromLocId = Array.from(locationIds)[0];
                // Create or update hidden input
                let hiddenInput = document.querySelector('input[name="from_location_id"]');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'from_location_id';
                    document.getElementById('mainForm').appendChild(hiddenInput);
                }
                hiddenInput.value = fromLocId;
            } else if (locationIds.size > 1) {
                // Multiple locations - will need to handle in submission
                let hiddenInput = document.querySelector('input[name="from_location_id"]');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'from_location_id';
                    document.getElementById('mainForm').appendChild(hiddenInput);
                }
                hiddenInput.value = Array.from(locationIds)[0]; // Use first location
            }

            // Build display of selected assets as small cards
            let html = '<div class="selected-assets-grid">';
            Object.values(selectedAssets).forEach(asset => {
                html += `
                    <div class="selected-asset-card">
                        <div class="sac-name">${htmlEscape(asset.keterangan || '-')}</div>
                        <div class="sac-code">${htmlEscape(asset.no_seri || '-')}</div>
                        <div class="sac-meta">${htmlEscape(asset.kategori || '-')}</div>
                        <div class="sac-meta">${htmlEscape(asset.sub_code || '-')}</div>
                        <div class="sac-meta">${htmlEscape(asset.toko || '-')}</div>
                        <div class="sac-meta">${asset.kuantitas || 1}</div>
                        <button type="button" class="sac-remove" onclick="removeAssetFromSelection(${asset.id})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            Hapus
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            
            listDiv.innerHTML = html;

            // Update count label di samping "Aset yang Dipilih"
            const countLabel = document.getElementById('selectedAssetsCount');
            if (countLabel) {
                countLabel.textContent = count + ' Item Terpilih';
            }

            // Trigger auto-generate SJ jika toko tujuan sudah diisi
            maybeGenerateSj();
            // Cek franchise warning
            checkFranchiseWarning();
        }

        // Debounced filter function for better performance
        let filterTimeout;
        function filterAssets() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const cabang = document.getElementById('filterCabang').value;
                const toko = document.getElementById('filterToko').value;
                const kategori = document.getElementById('filterKategori').value;
                const modalSearch = document.getElementById('modalSearch')?.value || '';

                filteredAssets = currentAssetsData.filter(asset => {
                    const matchFilter = (!cabang || asset.cabang === cabang) &&
                           (!toko || asset.toko === toko) &&
                           (!kategori || asset.kategori === kategori);
                    
                    const matchSearch = !modalSearch || 
                           asset.no_seri?.toLowerCase().includes(modalSearch.toLowerCase()) ||
                           asset.kategori?.toLowerCase().includes(modalSearch.toLowerCase()) ||
                           asset.keterangan?.toLowerCase().includes(modalSearch.toLowerCase());
                    
                    return matchFilter && matchSearch;
                });

                // Reset pagination and reload
                currentPage = 0;
                displayedAssets = [];
                document.getElementById('assetListContainer').innerHTML = '';
                loadMoreAssets();
                // Sinkronkan label tombol Pilih/Batalkan Semua
                setTimeout(updateSelectAllBtn, 350);
            }, 300); // Debounce 300ms
        }

        function resetFilter() {
            document.getElementById('filterCabang').value = '';
            document.getElementById('filterToko').value = '';
            document.getElementById('filterKategori').value = '';
            if (document.getElementById('modalSearch')) {
                document.getElementById('modalSearch').value = '';
            }
            updateKategoriFilter(); // Reset kategori options
            filterAssets();
        }

        function selectAllFiltered() {
            const btn = document.getElementById('selectAllFilteredBtn');
            // Cek apakah semua filtered sudah terpilih
            const allSelected = filteredAssets.length > 0 && filteredAssets.every(a => selectedAssets[a.id]);

            if (allSelected) {
                // Batalkan semua yang terfilter
                filteredAssets.forEach(asset => {
                    delete selectedAssets[asset.id];
                });
                // Update DOM
                document.querySelectorAll('.asset-item').forEach(el => {
                    const id = Number(el.dataset.assetId);
                    if (filteredAssets.find(a => a.id === id)) {
                        el.classList.remove('selected');
                        const cb = el.querySelector('.asset-checkbox');
                        if (cb) cb.checked = false;
                    }
                });
                if (btn) {
                    btn.style.color = '#198754';
                    btn.style.borderColor = '#198754';
                    btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Pilih Semua`;
                }
            } else {
                // Pilih semua yang terfilter
                filteredAssets.forEach(asset => {
                    if (!selectedAssets[asset.id]) {
                        selectedAssets[asset.id] = {
                            id: asset.id,
                            no_seri: asset.no_seri,
                            sub_code: asset.sub_code || '',
                            kategori: asset.kategori,
                            toko: asset.toko,
                            keterangan: asset.keterangan,
                            kuantitas: asset.kuantitas || 1,
                            biaya_perolehan: asset.biaya_perolehan,
                            akumulasi_penyusutan: asset.akumulasi_penyusutan || 0,
                            status: asset.status || 'Aktif'
                        };
                    }
                });
                // Update DOM
                document.querySelectorAll('.asset-item').forEach(el => {
                    const id = Number(el.dataset.assetId);
                    if (selectedAssets[id]) {
                        el.classList.add('selected');
                        const cb = el.querySelector('.asset-checkbox');
                        if (cb) cb.checked = true;
                    }
                });
                if (btn) {
                    btn.style.color = '#dc3545';
                    btn.style.borderColor = '#dc3545';
                    btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Batalkan Semua`;
                }
            }

            updateSelectedCount();
            updateSelectedAssetsDisplay();
        }

        // Update tampilan tombol Pilih/Batalkan Semua sesuai state saat ini
        function updateSelectAllBtn() {
            const btn = document.getElementById('selectAllFilteredBtn');
            if (!btn || filteredAssets.length === 0) return;
            const allSelected = filteredAssets.every(a => selectedAssets[a.id]);
            if (allSelected) {
                btn.style.color = '#dc3545';
                btn.style.borderColor = '#dc3545';
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Batalkan Semua`;
            } else {
                btn.style.color = '#198754';
                btn.style.borderColor = '#198754';
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Pilih Semua`;
            }
        }

        // ── Muat ulang data aset dari server (tanpa reload halaman) ──────────
        function reloadAssetsData() {
            const btn = document.getElementById('reloadAssetsBtn');
            if (btn) { btn.disabled = true; btn.textContent = 'Memuat...'; }

            fetch('api/assets_list.php')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentAssetsData = data.assets;
                        // Update peta toko tujuan
                        Object.assign(currentAllTokoMap, data.allTokoMap);

                        // Rebuild dropdown filter
                        rebuildFilterDropdowns();
                        filterAssets();

                        if (btn) { btn.textContent = 'Diperbarui'; }
                        setTimeout(() => {
                            if (btn) { btn.disabled = false; btn.textContent = 'Muat Ulang'; }
                        }, 1500);
                    } else {
                        if (btn) { btn.disabled = false; btn.textContent = 'Muat Ulang'; }
                        alert('Gagal memuat ulang data: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(() => {
                    if (btn) { btn.disabled = false; btn.textContent = 'Muat Ulang'; }
                    alert('Koneksi gagal, coba lagi.');
                });
        }

        function rebuildFilterDropdowns() {
            const cabangs = [...new Set(currentAssetsData.map(a => a.cabang).filter(Boolean))].sort();
            const tokos   = [...new Set(currentAssetsData.map(a => a.toko).filter(Boolean))].sort();
            const kats    = [...new Set(currentAssetsData.map(a => a.kategori).filter(Boolean))].sort();

            const cabangSel = document.getElementById('filterCabang');
            const tokoSel   = document.getElementById('filterToko');
            const katSel    = document.getElementById('filterKategori');

            if (cabangSel) {
                const prev = cabangSel.value;
                cabangSel.innerHTML = '<option value="">Semua Cabang</option>';
                cabangs.forEach(c => {
                    const o = document.createElement('option');
                    o.value = c; o.textContent = c;
                    cabangSel.appendChild(o);
                });
                cabangSel.value = prev;
            }
            if (tokoSel) {
                const prev = tokoSel.value;
                tokoSel.innerHTML = '<option value="">Semua Toko</option>';
                tokos.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t; o.textContent = t;
                    tokoSel.appendChild(o);
                });
                tokoSel.value = prev;
            }
            if (katSel) {
                const prev = katSel.value;
                katSel.innerHTML = '<option value="">Semua Kategori</option>';
                kats.forEach(k => {
                    const o = document.createElement('option');
                    o.value = k; o.textContent = k;
                    katSel.appendChild(o);
                });
                katSel.value = prev;
            }
        }

        // Update Toko filter based on selected Cabang
        function updateTokoFilter() {
            const selectedCabang = document.getElementById('filterCabang').value;
            const tokoSelect = document.getElementById('filterToko');
            const currentToko = tokoSelect.value;
            
            // Get unique tokos for selected cabang (or all if no cabang selected)
            const tokos = selectedCabang 
                ? [...new Set(currentAssetsData
                    .filter(asset => asset.cabang === selectedCabang)
                    .map(asset => asset.toko))]
                : [...new Set(currentAssetsData.map(asset => asset.toko))];
            
            tokos.sort();
            
            // Preserve selected value if it's still available
            const options = [''];
            tokoSelect.innerHTML = '<option value="">Semua Toko</option>';
            
            tokos.forEach(toko => {
                const option = document.createElement('option');
                option.value = toko;
                option.textContent = toko;
                tokoSelect.appendChild(option);
                options.push(toko);
            });
            
            // Keep previous selection if still valid, otherwise reset
            if (options.includes(currentToko)) {
                tokoSelect.value = currentToko;
            } else {
                tokoSelect.value = '';
            }
            
            updateKategoriFilter();
        }

        // Update Kategori filter based on selected Toko (and Cabang)
        function updateKategoriFilter() {
            const selectedCabang = document.getElementById('filterCabang').value;
            const selectedToko = document.getElementById('filterToko').value;
            const kategoriSelect = document.getElementById('filterKategori');
            const currentKategori = kategoriSelect.value;
            
            // Get unique kategoris for selected toko/cabang combination
            let kategoris = currentAssetsData;
            
            if (selectedCabang) {
                kategoris = kategoris.filter(asset => asset.cabang === selectedCabang);
            }
            
            if (selectedToko) {
                kategoris = kategoris.filter(asset => asset.toko === selectedToko);
            }
            
            kategoris = [...new Set(kategoris.map(asset => asset.kategori))];
            kategoris.sort();
            
            // Rebuild kategori options
            const options = [''];
            kategoriSelect.innerHTML = '<option value="">Semua Kategori</option>';
            
            kategoris.forEach(kat => {
                const option = document.createElement('option');
                option.value = kat;
                option.textContent = kat;
                kategoriSelect.appendChild(option);
                options.push(kat);
            });
            
            // Keep previous selection if still valid, otherwise reset
            if (options.includes(currentKategori)) {
                kategoriSelect.value = currentKategori;
            } else {
                kategoriSelect.value = '';
            }
        }

        function bulkAddAssets() {
            const checked = document.querySelectorAll('.asset-checkbox:checked');
            if (checked.length === 0) {
                showPopup('warning', 'Perhatian', 'Pilih minimal 1 aset!');
                return;
            }

            // Get asset data for selected checkboxes
            checked.forEach(checkbox => {
                const assetId = parseInt(checkbox.dataset.id);
                // Find the asset in currentAssetsData
                const asset = currentAssetsData.find(a => a.id === assetId);
                if (asset && !selectedAssets[assetId]) {
                    selectedAssets[assetId] = {
                        id: asset.id,
                        no_seri: asset.no_seri,
                        sub_code: asset.sub_code || '',
                        kategori: asset.kategori,
                        toko: asset.toko,
                        keterangan: asset.keterangan,
                        kuantitas: asset.kuantitas || 1,
                        biaya_perolehan: asset.biaya_perolehan,
                        akumulasi_penyusutan: asset.akumulasi_penyusutan || 0,
                        status: asset.status || 'Aktif'
                    };
                }
            });

            closeBulkPage();
            updateSelectedAssetsDisplay();
            
            // Scroll to selected assets section
            document.getElementById('selectedAssetsSection').scrollIntoView({ behavior: 'smooth' });
        }

        // Infinite scroll dalam modal
        let scrollTimeout;
        function attachAssetListScroll() {
            const container = document.getElementById('assetListContainer');
            if (!container) return;
            container.addEventListener('scroll', function() {
                const bulkActive = document.getElementById('bulkPage').classList.contains('active');
                if (!bulkActive) return;

                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(() => {
                    if (container.scrollTop + container.clientHeight >= container.scrollHeight - 200) {
                        loadMoreAssets();
                    }
                }, 100);
            });
        }

        // Close autocomplete on background click
        document.addEventListener('click', function(e) {
            if (searchInput && autocompleteList && e.target !== searchInput && e.target !== autocompleteList) {
                autocompleteList.classList.remove('show');
            }
        });

        // Ke Lokasi (destination toko) autocomplete
        const toLocationInput = document.getElementById('to_location_input');
        const toLocationAutocomplete = document.getElementById('tokoDestinationAutocomplete');
        const toLocationIdInput = document.getElementById('to_location_id');

        if (toLocationInput && toLocationAutocomplete && toLocationIdInput) {
            let tokoActiveIdx = -1;

            document.addEventListener('click', function(e) {
                if (e.target !== toLocationInput && !toLocationAutocomplete.contains(e.target)) {
                    toLocationAutocomplete.style.display = 'none';
                }
            });

            toLocationInput.addEventListener('focus', function() {
                if (this.value.length >= 1) this.dispatchEvent(new Event('input'));
            });

            toLocationInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                tokoActiveIdx = -1;
                if (query.length === 0) {
                    toLocationAutocomplete.style.display = 'none';
                    toLocationIdInput.value = '';
                    return;
                }

                // Filter toko by query
                const filtered = Object.entries(currentAllTokoMap)
                    .filter(([toko, data]) => 
                        toko.toLowerCase().includes(query) ||
                        data.cabang.toLowerCase().includes(query) ||
                        data.location_name.toLowerCase().includes(query)
                    )
                    .slice(0, 15);

                if (filtered.length > 0) {
                    toLocationAutocomplete.innerHTML = filtered.map(([toko, data], i) => `
                        <div class="autocomplete-item" data-idx="${i}" onclick="selectTokoDestination('${htmlEscape(toko)}', '${data.location_id}', '${htmlEscape(data.cabang)}', '${htmlEscape(data.location_name)}')" style="padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.15s;">
                            <strong style="color: #007bff; display: block; margin-bottom: 2px;">${htmlEscape(toko)}</strong>
                            <small style="color: #666; font-size: 0.85em;">${htmlEscape(data.cabang)} → ${htmlEscape(data.location_name)}</small>
                        </div>
                    `).join('');
                    // Tambahkan opsi "Input Toko Baru +" di bawah
                    const upperQuery = query.toUpperCase();
                    toLocationAutocomplete.innerHTML += `
                        <div class="autocomplete-item" onclick="selectTokoDestinationNew('${htmlEscape(upperQuery)}')" style="padding:8px 12px;cursor:pointer;border-top:2px solid #e5e7eb;background:#f0fdf4;color:#16a34a;font-weight:700;font-size:.82rem;">
                            Input Toko Baru +
                        </div>`;
                    toLocationAutocomplete.style.display = 'block';
                } else {
                    const upperQuery = query.toUpperCase();
                    toLocationAutocomplete.innerHTML = `
                        <div style="padding:8px 12px;color:#999;font-size:.82rem;">Tidak ditemukan di database</div>
                        <div class="autocomplete-item" onclick="selectTokoDestinationNew('${htmlEscape(upperQuery)}')" style="padding:8px 12px;cursor:pointer;background:#f0fdf4;color:#16a34a;font-weight:700;font-size:.82rem;">
                            Input Toko Baru +
                        </div>`;
                    toLocationAutocomplete.style.display = 'block';
                }
            });

            // Keyboard navigation (panah atas/bawah + Enter)
            toLocationInput.addEventListener('keydown', function(e) {
                const items = toLocationAutocomplete.querySelectorAll('.autocomplete-item');
                if (!items.length) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    tokoActiveIdx = Math.min(tokoActiveIdx + 1, items.length - 1);
                    items.forEach((el, i) => el.style.background = i === tokoActiveIdx ? '#e8f4f8' : '');
                    items[tokoActiveIdx].scrollIntoView({block:'nearest'});
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    tokoActiveIdx = Math.max(tokoActiveIdx - 1, 0);
                    items.forEach((el, i) => el.style.background = i === tokoActiveIdx ? '#e8f4f8' : '');
                    items[tokoActiveIdx].scrollIntoView({block:'nearest'});
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (tokoActiveIdx >= 0 && items[tokoActiveIdx]) {
                        items[tokoActiveIdx].click();
                    }
                } else if (e.key === 'Escape') {
                    toLocationAutocomplete.style.display = 'none';
                }
            });
        }

        function selectTokoDestination(toko, locationId, cabang, locationName) {
            if (toLocationInput && toLocationIdInput && toLocationAutocomplete) {
                toLocationInput.value = toko + ' (' + cabang + ')';
                toLocationIdInput.value = locationId;
                toLocationAutocomplete.style.display = 'none';
            }
            // Reset SJ agar diregenerasi dengan toko tujuan terbaru
            resetAndGenerateSj();
            // Cek franchise
            checkFranchiseWarning();
        }

        function selectTokoDestinationNew(tokoName) {
            if (toLocationInput && toLocationIdInput && toLocationAutocomplete) {
                var upperName = tokoName.toUpperCase();
                toLocationInput.value = upperName;
                // Set value khusus untuk toko baru — prefix "NEW:" agar backend tahu
                toLocationIdInput.value = 'NEW:' + upperName;
                toLocationAutocomplete.style.display = 'none';
                // Tampilkan indikator toko baru
                var existing = document.getElementById('new-toko-indicator');
                if (existing) existing.remove();
                var indicator = document.createElement('div');
                indicator.id = 'new-toko-indicator';
                indicator.style.cssText = 'margin-top:4px;font-size:.75rem;color:#16a34a;font-weight:600;';
                indicator.textContent = 'Toko baru akan dibuat otomatis: ' + upperName;
                toLocationInput.parentNode.appendChild(indicator);
            }
            // Reset SJ agar diregenerasi
            resetAndGenerateSj();
            // Cek franchise
            checkFranchiseWarning();
        }

        /**
         * Deteksi apakah lokasi asal atau tujuan adalah toko franchise (awalan F + digit)
         * Pattern: F031, F002, F0BN, dll
         */
        function isFranchiseLocation(name) {
            if (!name) return false;
            name = name.trim().toUpperCase();
            // F + minimal 2 digit (F031, F002)
            if (/^F\d{2,}/.test(name)) return true;
            // F + digit + alphanumeric (F0BN, F0TO)
            if (/^F\d[A-Z0-9]/.test(name)) return true;
            return false;
        }

        function checkFranchiseWarning() {
            var franchiseWarning = document.getElementById('franchise-warning');
            var franchiseNote = document.getElementById('franchise_note');
            if (!franchiseWarning) return;

            // Cek toko tujuan
            var tokoTujuan = (document.getElementById('to_location_input')?.value || '').trim();
            var isFrcTujuan = isFranchiseLocation(tokoTujuan);

            // Cek toko asal dari selected assets
            var isFrcAsal = false;
            var selectedVals = Object.values(selectedAssets);
            if (selectedVals.length > 0) {
                isFrcAsal = selectedVals.some(function(a) { return isFranchiseLocation(a.toko || ''); });
            }

            if (isFrcTujuan || isFrcAsal) {
                franchiseWarning.style.display = 'block';
                franchiseNote.required = true;
            } else {
                franchiseWarning.style.display = 'none';
                franchiseNote.required = false;
                franchiseNote.value = '';
            }
        }

        // ── Auto-generate SJ Number ──
        // Dipanggil saat aset dipilih DAN toko tujuan sudah terisi
        let sjGenerating = false;

        function resetAndGenerateSj() {
            const sjInput   = document.getElementById('sj_number');
            const sjDisplay = document.getElementById('sj_display');
            if (sjInput)   sjInput.value   = '';
            if (sjDisplay) sjDisplay.value = '';
            sjGenerating = false;
            maybeGenerateSj();
        }

        function onJenisSjChange() {
            // Ketika jenis SJ diubah, regenerasi nomor SJ
            resetAndGenerateSj();
        }

        function maybeGenerateSj() {
            const assetIds   = Object.keys(selectedAssets);
            const toLocation = document.getElementById('to_location_id')?.value;
            const sjInput    = document.getElementById('sj_number');
            const sjDisplay  = document.getElementById('sj_display');
            const jenisSj    = document.getElementById('jenis_sj')?.value || 'RA';

            if (!assetIds.length || !toLocation) return;
            if (sjInput && sjInput.value) return;
            if (sjGenerating) return;

            sjGenerating = true;

            const formData = new FormData();
            formData.append('asset_id', assetIds[0]);
            formData.append('to_location_id', toLocation);
            formData.append('jenis_sj', jenisSj);

            fetch('api/generate_sj.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.sj_number) {
                        if (sjDisplay) sjDisplay.value = data.sj_number;
                        if (sjInput)   sjInput.value   = data.sj_number;
                    }
                })
                .catch(() => { /* silent fail — validasi saat submit akan menangkap */ })
                .finally(() => { sjGenerating = false; });
        }

        // Override form submission to include selected assets
        const mainFormElement = document.getElementById('mainForm');
        if (mainFormElement) {
            // Debug: pastikan event listener terpasang
            console.log('[DEBUG] Form submit handler attached. selectedAssets:', Object.keys(selectedAssets).length);
            
            mainFormElement.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('[DEBUG] Form submitted. selectedAssets:', JSON.stringify(Object.keys(selectedAssets)));
                
                // Reset semua error
                document.querySelectorAll('.field-error').forEach(el => el.remove());
                document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
                
                let hasError = false;
                
                function showFieldError(fieldId, message) {
                    const field = document.getElementById(fieldId);
                    if (!field) return;
                    field.classList.add('input-error');
                    const err = document.createElement('div');
                    err.className = 'field-error';
                    err.textContent = message;
                    field.parentNode.insertBefore(err, field.nextSibling);
                    if (!hasError) { field.focus(); field.scrollIntoView({behavior:'smooth', block:'center'}); }
                    hasError = true;
                }

                const assetCount = Object.keys(selectedAssets).length;
                if (assetCount === 0) {
                    showFieldError('asset_search', 'Pilih minimal 1 aset');
                }

                const toLocation = document.getElementById('to_location_id').value;
                if (!toLocation) {
                    showFieldError('to_location_input', 'Pilih lokasi/toko tujuan atau ketik nama toko baru');
                    hasError = true;
                }

                const reason = document.getElementById('sj_number').value.trim();
                if (!reason) {
                    showFieldError('sj_display', 'No Surat Jalan belum terbuat. Pastikan aset dan toko tujuan sudah dipilih.');
                    hasError = true;
                }

                // Validasi: jika ada aset non-aktif, inactive_reason wajib diisi
                const hasInactive = Object.values(selectedAssets).some(a => a.status && a.status !== 'Aktif');
                const inactiveReason = document.getElementById('inactive_reason').value.trim();
                if (hasInactive && !inactiveReason) {
                    showFieldError('inactive_reason', 'Alasan mutasi barang non-aktif wajib diisi');
                }

                // Validasi: jika franchise, franchise_note wajib diisi
                const franchiseNoteEl = document.getElementById('franchise_note');
                const franchiseNoteVal = franchiseNoteEl ? franchiseNoteEl.value.trim() : '';
                const franchiseWarningVisible = document.getElementById('franchise-warning')?.style.display !== 'none';
                if (franchiseWarningVisible && !franchiseNoteVal) {
                    showFieldError('franchise_note', 'Keterangan alasan relokasi franchise wajib diisi');
                }

                if (hasError) return;

                // Double-check selectedAssets punya data
                const assetValues = Object.values(selectedAssets);
                if (assetValues.length === 0) {
                    showFieldError('asset_search', 'Tidak ada aset yang dipilih. Silakan pilih ulang.');
                    return;
                }

                // Submit via AJAX agar bisa tangkap error
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
                submitBtn.textContent = 'Mengirim...';

                const formData = new FormData();
                
                // Asset IDs
                assetValues.forEach((asset, idx) => {
                    formData.append('asset_ids[' + idx + ']', asset.id);
                });

                // Location
                const fromLocInput = document.querySelector('input[name="from_location_id"]');
                formData.append('from_location_id', fromLocInput ? fromLocInput.value : '0');
                formData.append('to_location_id', toLocation);
                formData.append('reason', reason);
                formData.append('jenis_sj', document.getElementById('jenis_sj')?.value || 'RA');
                if (inactiveReason) {
                    formData.append('inactive_reason', inactiveReason);
                }
                if (franchiseNoteVal) {
                    formData.append('franchise_note', franchiseNoteVal);
                }

                fetch('api/request_store_bulk.php', {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) { return response.text(); })
                .then(function(text) {
                    console.log('[DEBUG] Server response:', text);
                    try {
                        var res = JSON.parse(text);
                        if (res.success) {
                            var popupMsg = res.message;
                            if (res.is_franchise) {
                                popupMsg += '\n\nKode kunci akses 1 kali buat sudah terkirim ke Telegram MGR/SPV. Masukkan kode token di halaman Permintaan.';
                            }
                            showPopup('success', 'Berhasil!', popupMsg, function() {
                                setTimeout(function(){ window.location.href = 'requests.php'; }, 1500);
                            });
                        } else {
                            showPopup('error', 'Gagal', res.message || 'Terjadi kesalahan.');
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Kirim Permintaan (' + assetValues.length + ' Aset)';
                        }
                    } catch(e) {
                        showPopup('error', 'Server Error', (text || '').substring(0, 300) || 'Response tidak valid.');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Kirim Permintaan (' + assetValues.length + ' Aset)';
                    }
                })
                .catch(function(err) {
                    var popup = (typeof showPopup === 'function') ? showPopup : function(t,ti,m) { alert(ti + ': ' + m); };
                    popup('error', 'Error Koneksi', 'Gagal menghubungi server: ' + (err.message || err));
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Kirim Permintaan (' + assetValues.length + ' Aset)';
                });
            });
        }

        // Jalankan preload mutasi jika ada (setelah semua fungsi terdefinisi)
        if (_mutasiPreloadAssets) {
            updateSelectedAssetsDisplay();
            const _preloadSection = document.getElementById('selectedAssetsSection');
            if (_preloadSection) {
                setTimeout(function() {
                    _preloadSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 300);
            }
        }

    </script>
    <?php echo_loading_js(); ?>
    </div><!-- content-area -->
</body>
</html>



