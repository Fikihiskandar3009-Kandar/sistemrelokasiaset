<?php
/**
 * ==== PERMINTAAN RELOKASI (MUTASI) ====
 * Daftar permintaan sesuai role: admin = semua, SPV = level SPV, MGR = level MGR. Approve/reject manual + alur token franchise
 * Akses: admin, spv, mgr | Terkait: api/approve_manual.php, api/franchise_token_*.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
auth_require();
$user = auth_user();

$pdo = db();
$where = [];
$params = [];

// Auto-migrate franchise columns if needed
try {
    $cols = $pdo->query("SHOW COLUMNS FROM relocations LIKE 'franchise_unlocked'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE relocations ADD COLUMN franchise_unlocked TINYINT(1) NOT NULL DEFAULT 0 AFTER approval_code");
        $pdo->exec("ALTER TABLE relocations ADD COLUMN franchise_note TEXT NULL AFTER franchise_unlocked");
    }
} catch (Exception $e) { /* ignore */ }

// Auto-migrate kolom delegasi SPV (wakil MGR)
ensure_delegation_columns($pdo);

// Filter berdasarkan role — 3 role: admin, spv, mgr
if ($user['role'] === 'admin') {
    // Admin lihat semua permintaan
} elseif ($user['role'] === 'spv') {
    // SPV lihat permintaan level SPV saja
    $where[]  = "r.approval_level = 'SPV'";
} elseif ($user['role'] === 'mgr') {
    // MGR lihat permintaan level MGR saja
    $where[]  = "r.approval_level = 'MGR'";
} else {
    // Role tidak dikenal → redirect
    header('Location: dashboard.php'); exit;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Sorting setup
$allowed_sort_columns = ['id', 'created_at', 'no_seri', 'sub_code', 'kuantitas', 'asset_name', 'from_loc', 'to_loc', 'created_by_name', 'status', 'approved_by_name', 'jenis_sj', 'reason', 'via'];
$sort_by = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort_columns) ? $_GET['sort'] : 'created_at';
$sort_order = isset($_GET['order']) && $_GET['order'] === 'ASC' ? 'ASC' : 'DESC';
$next_sort_order = $sort_order === 'ASC' ? 'DESC' : 'ASC';

// Build ORDER BY clause
$order_column_map = [
    'id' => 'r.id',
    'created_at' => 'r.created_at',
    'no_seri' => 'ar.no_seri',
    'sub_code' => 'ar.sub_code',
    'kuantitas' => 'ar.kuantitas',
    'asset_name' => 'a.name',
    'from_loc' => 'l1.name',
    'to_loc' => 'l2.name',
    'created_by_name' => 'u1.name',
    'status' => 'r.status',
    'approved_by_name' => 'u2.name',
    'jenis_sj' => 'r.jenis_sj',
    'reason' => 'r.reason',
    'via' => 'r.updated_at'
];
$order_column = $order_column_map[$sort_by] ?? 'r.created_at';

$requests = $pdo->prepare("
    SELECT 
        r.*,
        a.asset_code,
        a.name as asset_name,
        a.value_amount,
        ar.no_seri as real_no_seri,
        ar.sub_code as real_sub_code,
        ar.kuantitas as real_kuantitas,
        ar.status as asset_status,
        CASE WHEN l1.name IN ('Gudang GA','Toko 001') THEN COALESCE(ar.toko, l1.name) ELSE l1.name END as from_loc,
        CASE WHEN l2.name IN ('Gudang GA','Toko 001') THEN COALESCE(ar.toko, l2.name) ELSE l2.name END as to_loc,
        u1.name as created_by_name,
        u2.name as approved_by_name,
        ro.name as approver_role,
       COALESCE(r.delegated_spv, 0) as delegated_spv,
        (SELECT COUNT(*) FROM anomaly_alerts WHERE relocation_id = r.id) as anomaly_count,
        (SELECT COUNT(*) FROM relocation_items WHERE relocation_id = r.id) as item_count,
        COALESCE(r.franchise_unlocked, 0) as franchise_unlocked,
        r.franchise_note
    FROM relocations r
    JOIN assets a ON a.id = r.asset_id
    LEFT JOIN assets_real ar ON ar.id = r.asset_id
    JOIN locations l1 ON l1.id = r.from_location_id
    JOIN locations l2 ON l2.id = r.to_location_id
    JOIN users u1 ON u1.id = r.created_by
    LEFT JOIN users u2 ON u2.id = r.approved_by
    LEFT JOIN roles ro ON ro.id = u2.role_id
    $whereClause
    ORDER BY {$order_column} {$sort_order}
");
$requests->execute($params);
$requests = $requests->fetchAll();

// Helper function untuk sort link
function sortLink($column, $label) {
    global $sort_by, $sort_order, $next_sort_order;
    $is_current_sort = $sort_by === $column;
    $arrow = $is_current_sort ? ($sort_order === 'ASC' ? ' ▲' : ' ▼') : '';
    $order_param = $is_current_sort ? $next_sort_order : 'ASC';
    $url = "?sort={$column}&order={$order_param}";
    return "<a href=\"{$url}\" style=\"color: #007bff; text-decoration: none; cursor: pointer;\">{$label}{$arrow}</a>";
}

// Get and clear session messages
$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Permintaan - SRA Indomaret Parung</title>
    <?php echo asset_css('css/layout-simple.css'); ?>
    <?php echo asset_css('css/app-theme.css'); ?>
    <?php echo asset_css('css/modern-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/layout-override.css'); ?>
    <?php echo_loading_css(); ?>
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
            padding: 30px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-close {
            cursor: pointer;
            font-weight: bold;
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
            border-radius: 4px;
            transition: background 0.3s;
        }
        
        .nav a:hover {
            background: #0056b3;
            text-decoration: none;
        }
        
        .status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            display: inline-block;
        }
        
        .status-PENDING {
            background: #ffeeba;
            color: #856404;
        }
        
        .status-APPROVED {
            background: #d4edda;
            color: #155724;
        }
        
        .status-REJECTED {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-CANCELLED {
            background: #e2e3e5;
            color: #495057;
        }
        
        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8em;
            display: inline-block;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .approve-form {
            display: inline;
        }
        
        .header {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .btn {
            padding: 6px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        .btn-approve {
            background: #28a745;
            color: white;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .btn-resend {
            background: #f0f9ff;
            color: #0369a1;
            border: 1px solid #7dd3fc;
            font-size: 1rem;
            padding: 5px 8px;
            font-weight: 600;
            white-space: nowrap;
            transition: all 0.2s ease;
            line-height: 1;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-resend:hover {
            background: #0ea5e9;
            color: #fff;
            border-color: #0ea5e9;
            transform: scale(1.1);
        }
        
        .expandable-row:hover {
            background: #f8fafc;
        }
        
        .expandable-row .expand-arrow {
            display: inline-block;
            font-size: 8px;
            transition: transform 0.2s ease;
        }
        
        .expandable-row.expanded .expand-arrow {
            transform: rotate(90deg);
        }
        
        .expand-detail-row td {
            background: transparent !important;
        }
        
        .expand-detail-content table {
            display: table !important;
            white-space: normal !important;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .table-responsive {
                margin-bottom: 15px;
            }
            
            table {
                font-size: 0.85rem;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .btn {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body class="sidebar-hidden">
    <?php $page_title = 'Permintaan'; $page_icon = ''; include __DIR__.'/app/header-sidebar.php'; ?>
    <div class="content-area">
        <?php if ($success_message): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                showPopup('success', 'Berhasil', <?= json_encode($success_message) ?>);
            });
            </script>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                showPopup('error', 'Error', <?= json_encode($error_message) ?>);
            });
            </script>
        <?php endif; ?>

        <div class="table-responsive" style="margin-top: 20px;">
            <table>
                <thead>
                    <tr>
                        <th><?= sortLink('id', 'ID') ?></th>
                        <th><?= sortLink('created_at', 'Tanggal') ?></th>
                        <th><?= sortLink('no_seri', 'No Seri') ?></th>
                        <th><?= sortLink('sub_code', 'Sub Code') ?></th>
                        <th><?= sortLink('asset_name', 'Nama Aset') ?></th>
                        <th><?= sortLink('kuantitas', 'Kuantitas') ?></th>
                        <th><?= sortLink('from_loc', 'Asal') ?></th>
                        <th><?= sortLink('to_loc', 'Tujuan') ?></th>
                        <th><?= sortLink('created_by_name', 'Pemohon') ?></th>
                        <th><?= sortLink('status', 'Status') ?></th>
                        <th><?= sortLink('approved_by_name', 'Diproses Oleh') ?></th>
                        <th><?= sortLink('jenis_sj', 'Jenis SJ') ?></th>
                        <th><?= sortLink('reason', 'No Surat Jalan') ?></th>
                        <th>Via</th>
                        <?php if ($user['role'] === 'spv' || $user['role'] === 'mgr'): ?>
                        <th style="position:relative;white-space:nowrap;">
                            Aksi
                            <span id="bulkMenuBtn" onclick="toggleBulkMenu(event)" style="cursor:pointer;font-size:9px;color:#6b7280;display:none;margin-left:2px;">▼</span>
                            <div id="bulkMenuDrop" style="display:none;position:absolute;left:0;top:100%;margin-top:2px;background:#fff;border:1px solid #d1d5db;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.1);z-index:9999;padding:3px;display:none;flex-direction:column;">
                                <button onclick="bulkAction('APPROVE')" style="padding:4px 8px;background:#16a34a;color:#fff;border:none;border-radius:3px;font-size:.7rem;font-weight:600;cursor:pointer;margin-bottom:2px;white-space:nowrap;">Setujui Semua</button>
                                <button onclick="bulkAction('REJECT')" style="padding:4px 8px;background:#dc2626;color:#fff;border:none;border-radius:3px;font-size:.7rem;font-weight:600;cursor:pointer;white-space:nowrap;">Tolak Semua</button>
                            </div>
                        </th>
                        <th style="width:28px;text-align:center;"><input type="checkbox" id="selectAllReq" onchange="toggleSelectAllReq(this)" title="Pilih Semua"></th>
                        <?php else: ?>
                        <th>Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
            <tbody>
                <?php if (count($requests) > 0): ?>
                    <?php foreach ($requests as $no => $req): ?>
                    <?php
                        // Determine Via from approvals_log
                        $via = '-';
                        if ($req['status'] !== 'PENDING') {
                            $via = 'Web';
                            try {
                                $lStmt = $pdo->prepare("SELECT note FROM approvals_log WHERE relocation_id=? AND action IN('APPROVE','REJECT') ORDER BY created_at DESC LIMIT 1");
                                $lStmt->execute([$req['id']]);
                                $n = $lStmt->fetchColumn();
                                if ($n && stripos($n, 'telegram') !== false) $via = 'Telegram';
                            } catch(Exception $e) {}
                        }
                        // No Seri: prefer real_no_seri, fallback to no_seri from assets
                        $noSeri = !empty($req['real_no_seri']) ? $req['real_no_seri'] : (!empty($req['no_seri']) ? $req['no_seri'] : '-');
                        $hasMultiItems = ($req['item_count'] ?? 0) > 1;
                        $multiItems = [];
                        if ($hasMultiItems) {
                            try {
                                $itemsStmt = $pdo->prepare("
                                    SELECT ri.asset_id, 
                                           COALESCE(ar.no_seri, a.asset_code, '') as item_no_seri,
                                           COALESCE(ar.sub_code, '') as item_sub_code,
                                           COALESCE(ar.kuantitas, 1) as item_kuantitas,
                                           COALESCE(a.name, ar.keterangan, '') as item_name,
                                           COALESCE(a.value_amount, 0) as item_value
                                    FROM relocation_items ri
                                    LEFT JOIN assets a ON a.id = ri.asset_id
                                    LEFT JOIN assets_real ar ON ar.id = ri.asset_id
                                    WHERE ri.relocation_id = ?
                                ");
                                $itemsStmt->execute([$req['id']]);
                                $multiItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch(Exception $e) { $multiItems = []; }
                        }
                    ?>
                    <tr<?php if ($hasMultiItems): ?> class="expandable-row" onclick="toggleExpandRow(this)" style="cursor:pointer;"<?php endif; ?>>
                        <td>
                            #<?= $req['id'] ?>
                            <?php if ($hasMultiItems): ?>
                                <br><span class="expand-arrow" style="font-size:10px;color:#6b7280;">▶</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                        <td style="font-weight: bold; color: #0066cc;"><?= htmlspecialchars($noSeri) ?></td>
                        <td><?= htmlspecialchars($req['real_sub_code'] ?? '-') ?></td>
                        <td>
                            <?= htmlspecialchars($req['asset_name']) ?>
                            <?php if ($req['anomaly_count'] > 0): ?>
                                <span class="badge badge-warning">! <?= $req['anomaly_count'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)($req['real_kuantitas'] ?? 1) ?></td>
                        <td><?= htmlspecialchars($req['from_loc']) ?></td>
                        <td><?= htmlspecialchars($req['to_loc']) ?></td>
                        <td><?= htmlspecialchars($req['created_by_name']) ?></td>
                        <td><span class="status status-<?= $req['status'] ?>"><?= $req['status'] ?></span></td>
                        <td><?= !empty($req['approved_by_name']) ? htmlspecialchars($req['approved_by_name']) : '-' ?><?php if (!empty($req['approver_role'])): ?> <small>(<?= $req['approver_role'] === 'spv' && !empty($req['delegated_spv']) ? 'SPV — Diwakilkan MGR' : ucfirst($req['approver_role']) ?>)</small><?php endif; ?></td>
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
                        <td><?= $via ?></td>
                        <td>
                            <?php if ($req['status'] === 'PENDING' && 
                                    // MGR = approver utama; SPV hanya jika level SPV (data lama) atau sudah di-WAKILKAN (delegasi admin)
                                    (($user['role'] === 'mgr' && $req['approval_level'] === 'MGR') || 
                                     ($user['role'] === 'spv' && ($req['approval_level'] === 'SPV' || !empty($req['delegated_spv']))))): ?>
                                <?php 
                                $isInactive = !empty($req['asset_status']) && $req['asset_status'] !== 'Aktif';
                                $inactiveReason = htmlspecialchars($req['inactive_reason'] ?? '', ENT_QUOTES);
                                $assetStatus = htmlspecialchars($req['asset_status'] ?? '', ENT_QUOTES);
                                $assetNameEsc = htmlspecialchars($req['asset_name'] ?? '', ENT_QUOTES);
                                // Detect franchise
                                $reqFromLocSpv = $req['from_loc'] ?? '';
                                $reqToLocSpv = $req['to_loc'] ?? '';
                                $isFranchiseSpv = (preg_match('/^F\d{2,}/i', $reqFromLocSpv) || preg_match('/^F\d[A-Z0-9]/i', $reqFromLocSpv) ||
                                                   preg_match('/^F\d{2,}/i', $reqToLocSpv) || preg_match('/^F\d[A-Z0-9]/i', $reqToLocSpv));
                                ?>
                                <?php if ($isFranchiseSpv): ?>
                                    <div style="display:flex;gap:4px;align-items:center;">
                                        <button type="button" class="btn" style="background:#eff6ff;color:#1d4ed8;border:1px solid #93c5fd;font-size:.7rem;padding:5px 8px;font-weight:600;line-height:1;border-radius:6px;cursor:pointer;"
                                            onclick="openMgrFranchisePopup(<?= $req['id'] ?>, '<?= $assetNameEsc ?>', '<?= htmlspecialchars($req['from_loc'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($req['to_loc'] ?? '', ENT_QUOTES) ?>', '<?= $req['approval_level'] ?>')"
                                            title="Franchise — Token diperlukan">
                                            FRC
                                        </button>
                                        <button type="button" class="btn btn-reject"
                                            onclick="confirmApproval(<?= $req['id'] ?>, '<?= $req['approval_code'] ?>', 'REJECT', '<?= $assetNameEsc ?>', '')">Tolak</button>
                                    </div>
                                <?php elseif ($isInactive): ?>
                                    <div style="display:flex;gap:4px;align-items:center;">
                                        <button type="button" class="btn" style="background:#fef3c7;color:#92400e;border:1px solid #f59e0b;font-size:.72rem;padding:4px 8px;font-weight:700;white-space:nowrap;"
                                            onclick="showInactiveDetail(<?= $req['id'] ?>, '<?= $req['approval_code'] ?>', '<?= $assetNameEsc ?>', '<?= $assetStatus ?>', '<?= $inactiveReason ?>')">
                                            Non Aktif
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div style="display:flex;gap:4px;">
                                        <button type="button" class="btn btn-approve" 
                                            onclick="confirmApproval(<?= $req['id'] ?>, '<?= $req['approval_code'] ?>', 'APPROVE', '<?= $assetNameEsc ?>', '')">Setujui</button>
                                        <button type="button" class="btn btn-reject"
                                            onclick="confirmApproval(<?= $req['id'] ?>, '<?= $req['approval_code'] ?>', 'REJECT', '<?= $assetNameEsc ?>', '')">Tolak</button>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($req['status'] === 'PENDING' && $user['role'] === 'admin'): ?>
                                <?php
                                // Detect if this is a franchise relocation
                                $reqFromLoc = $req['from_loc'] ?? '';
                                $reqToLoc = $req['to_loc'] ?? '';
                                $isFranchiseReq = (preg_match('/^F\d{2,}/i', $reqFromLoc) || preg_match('/^F\d[A-Z0-9]/i', $reqFromLoc) ||
                                                   preg_match('/^F\d{2,}/i', $reqToLoc) || preg_match('/^F\d[A-Z0-9]/i', $reqToLoc));
                                // Cek apakah franchise sudah unlocked
                                $franchiseUnlocked = !empty($req['franchise_unlocked']);
                                $franchiseNote = htmlspecialchars($req['franchise_note'] ?? '', ENT_QUOTES);
                                ?>
                                <div style="display:flex;gap:4px;align-items:center;">
                                    <?php if ($isFranchiseReq && !$franchiseUnlocked): ?>
                                        <button type="button" class="btn" style="background:#eff6ff;color:#1d4ed8;border:1px solid #93c5fd;font-size:.7rem;padding:5px 8px;font-weight:600;line-height:1;border-radius:6px;cursor:pointer;transition:all 0.2s;"
                                            onclick="openFranchiseTokenPopup(<?= $req['id'] ?>, '<?= htmlspecialchars($req['asset_name'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($req['from_loc'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($req['to_loc'] ?? '', ENT_QUOTES) ?>', '<?= $franchiseNote ?>', '<?= $req['approval_level'] ?>')"
                                            title="Franchise Token — Masukkan kode akses">
                                            FRC
                                        </button>
                                    <?php elseif ($isFranchiseReq && $franchiseUnlocked): ?>
                                        <button type="button" class="btn btn-resend" 
                                            onclick="resendNotification(<?= $req['id'] ?>, '<?= htmlspecialchars($req['asset_name'] ?? '', ENT_QUOTES) ?>', '<?= $req['approval_level'] ?>')"
                                            title="Franchise Unlocked — Kirim ulang notif approval">
                                            Notif
                                        </button>
                                        <span style="font-size:.65rem;color:#16a34a;font-weight:600;">OK</span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-resend" 
                                            onclick="resendNotification(<?= $req['id'] ?>, '<?= htmlspecialchars($req['asset_name'] ?? '', ENT_QUOTES) ?>', '<?= $req['approval_level'] ?>')">
                                            Notif
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($user['role'] === 'spv' || $user['role'] === 'mgr'): ?>
                        <td style="text-align:center;">
                            <?php if ($req['status'] === 'PENDING' && 
                                    (($user['role'] === 'mgr' && $req['approval_level'] === 'MGR') || 
                                     ($user['role'] === 'spv' && ($req['approval_level'] === 'SPV' || !empty($req['delegated_spv']))))): ?>
                                <input type="checkbox" class="req-checkbox" data-id="<?= $req['id'] ?>" data-code="<?= $req['approval_code'] ?>" onchange="updateBulkBar()">
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if ($hasMultiItems && !empty($multiItems)): ?>
                    <tr class="expand-detail-row" style="display:none;">
                        <td colspan="16" style="padding:0;border-top:none;">
                            <div class="expand-detail-content" style="background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:0;margin:0 8px 8px;overflow-x:auto;">
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
                                        <?php foreach ($multiItems as $idx => $item): ?>
                                        <tr style="border-bottom:1px solid #e5e7eb;">
                                            <td style="padding:5px 10px;color:#6b7280;"><?= $idx + 1 ?></td>
                                            <td style="padding:5px 10px;"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td>
                                            <td style="padding:5px 10px;font-weight:600;color:#0066cc;"><?= htmlspecialchars($item['item_no_seri'] ?: '-') ?></td>
                                            <td style="padding:5px 10px;"><?= htmlspecialchars($item['item_sub_code'] ?: '-') ?></td>
                                            <td style="padding:5px 10px;"><?= htmlspecialchars($item['item_name'] ?: '-') ?></td>
                                            <td style="padding:5px 10px;"><?= (int)($item['item_kuantitas'] ?? 1) ?></td>
                                            <td style="padding:5px 10px;"><?= htmlspecialchars($req['from_loc']) ?></td>
                                            <td style="padding:5px 10px;"><?= htmlspecialchars($req['to_loc']) ?></td>
                                            <td style="padding:5px 10px;"><?= htmlspecialchars($req['created_by_name']) ?></td>
                                            <td style="padding:5px 10px;"><span class="status status-<?= $req['status'] ?>"><?= $req['status'] ?></span></td>
                                            <td style="padding:5px 10px;"><?= !empty($req['approved_by_name']) ? htmlspecialchars($req['approved_by_name']) : '-' ?></td>
                                            <td style="padding:5px 10px;"><span style="background:<?= ($jenisSjColors[$req['jenis_sj'] ?? 'RA'] ?? $jenisSjColors['RA'])['bg'] ?>;color:<?= ($jenisSjColors[$req['jenis_sj'] ?? 'RA'] ?? $jenisSjColors['RA'])['color'] ?>;padding:2px 6px;border-radius:3px;font-size:.7rem;font-weight:600;"><?= jenis_sj_label($req['jenis_sj'] ?? 'RA') ?></span></td>
                                            <td style="padding:5px 10px;"><?= !empty($req['reason']) ? htmlspecialchars($req['reason']) : '-' ?></td>
                                            <td style="padding:5px 10px;"><?= $via ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="16" style="text-align: center; padding: 30px; color: #999;">
                            Belum ada permintaan relokasi. <a href="request_create.php">Buat permintaan baru</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>
    </div><!-- content-area -->
    <?php echo asset_js('js/app-ui.js'); ?>
    <?php echo_loading_js(); ?>
    <script>
    // Popup detail aset non-aktif — tampilkan alasan + tombol approve/reject
    function showInactiveDetail(relocationId, code, assetName, assetStatus, inactiveReason) {
        var msg = '<div style="text-align:left;">'
            + '<p><strong>Aset:</strong> ' + assetName + '</p>'
            + '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:12px;margin:12px 0;">'
            + '<strong style="color:#92400e;">Status: ' + assetStatus + '</strong><br>'
            + '<span style="color:#78350f;font-size:.85rem;">Alasan mutasi: ' + (inactiveReason || 'Tidak ada keterangan') + '</span>'
            + '</div>'
            + '<p style="color:#6b7280;font-size:.82rem;">Pilih aksi untuk permintaan ini:</p>'
            + '</div>';

        // Tampilkan popup custom dengan 3 tombol: Setujui, Tolak, Batal
        var old = document.getElementById('app-popup');
        if (old) old.remove();

        var overlay = document.createElement('div');
        overlay.id = 'app-popup';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:99999;';
        overlay.innerHTML = '<div style="background:#fff;border-radius:12px;padding:32px;max-width:450px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);animation:popIn 0.2s ease;">'
            + '<div style="width:56px;height:56px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;font-weight:700;color:#d97706;"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>'
            + '<h3 style="margin:0 0 12px;font-size:1.1rem;color:#1f2937;text-align:center;">Aset Non Aktif</h3>'
            + msg
            + '<div style="display:flex;gap:8px;justify-content:center;margin-top:16px;">'
            + '<button onclick="closePopup()" style="padding:10px 20px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:8px;font-size:.88rem;font-weight:500;cursor:pointer;">Batal</button>'
            + '<button onclick="closePopup();confirmApproval(' + relocationId + ',\'' + code + '\',\'REJECT\',\'' + assetName + '\',\'\')" style="padding:10px 20px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;">Tolak</button>'
            + '<button onclick="closePopup();confirmApproval(' + relocationId + ',\'' + code + '\',\'APPROVE\',\'' + assetName + '\',\'\')" style="padding:10px 20px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;">Setujui</button>'
            + '</div></div>';
        document.body.appendChild(overlay);

        // Keyboard: Enter = Setujui, Escape = Batal
        overlay._keyHandler = function(e) {
            if (e.key === 'Escape') { closePopup(); }
            else if (e.key === 'Enter') { closePopup(); confirmApproval(relocationId, code, 'APPROVE', assetName, ''); }
        };
        document.addEventListener('keydown', overlay._keyHandler);
    }

    function confirmApproval(relocationId, code, action, assetName, inactiveInfo) {
        var isApprove = action === 'APPROVE';
        var type = isApprove ? 'warning' : 'danger';
        var title = inactiveInfo ? 'Aset Non Aktif' : (isApprove ? 'Setujui Permintaan?' : 'Tolak Permintaan?');
        var msg = '<p><strong>Aset:</strong> ' + assetName + '</p>';
        
        if (inactiveInfo) {
            msg += inactiveInfo;
            msg += '<p style="margin-top:12px;font-weight:600;color:#1f2937;">Pilih aksi:</p>';
        } else {
            msg += '<p><strong>Aksi:</strong> ' + (isApprove ? 'Approve (Setujui)' : 'Reject (Tolak)') + '</p>';
        }

        msg += '<p style="margin-top:8px;color:#6b7280;font-size:.82rem;">Aksi ini tidak bisa dibatalkan.</p>';

        // Untuk non-aktif: tampilkan 2 tombol (Setujui + Tolak) dalam 1 popup
        if (inactiveInfo) {
            var old = document.getElementById('app-popup');
            if (old) old.remove();

            var overlay = document.createElement('div');
            overlay.id = 'app-popup';
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:99999;';
            overlay.innerHTML = '<div style="background:#fff;border-radius:12px;padding:32px;max-width:450px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);animation:popIn 0.2s ease;">'
                + '<div style="width:56px;height:56px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;font-weight:700;color:#d97706;"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>'
                + '<h3 style="margin:0 0 8px;font-size:1.15rem;color:#1f2937;">' + title + '</h3>'
                + '<div style="margin:0 0 20px;font-size:.88rem;color:#6b7280;line-height:1.6;text-align:left;">' + msg + '</div>'
                + '<div style="display:flex;gap:8px;justify-content:center;">'
                + '<button id="popup-reject-btn" style="padding:10px 20px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;">Tolak</button>'
                + '<button id="popup-approve-btn" style="padding:10px 20px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;">Setujui</button>'
                + '<button id="popup-cancel-btn" style="padding:10px 20px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:8px;font-size:.88rem;font-weight:500;cursor:pointer;">Batal</button>'
                + '</div></div>';
            document.body.appendChild(overlay);

            document.getElementById('popup-approve-btn').onclick = function() { overlay.remove(); doApproval(relocationId, code, 'APPROVE'); };
            document.getElementById('popup-reject-btn').onclick = function() { overlay.remove(); doApproval(relocationId, code, 'REJECT'); };
            document.getElementById('popup-cancel-btn').onclick = function() { overlay.remove(); };

            // Keyboard
            document.addEventListener('keydown', function handler(e) {
                if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', handler); }
            });
            return;
        }

        // Untuk aset aktif: popup konfirmasi biasa
        showConfirm(type, title, msg, function() {
            doApproval(relocationId, code, action);
        });
    }

    function doApproval(relocationId, code, action) {
        var isApprove = action === 'APPROVE';
        var formData = new FormData();
        formData.append('relocation_id', relocationId);
        formData.append('code', code);
        formData.append('action', action);

        fetch('api/approve_manual.php', { method: 'POST', body: formData })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var res = JSON.parse(text);
                showPopup('success', isApprove ? 'Disetujui!' : 'Ditolak!', res.message || 'Berhasil diproses.', function() { reloadAfter(); });
            } catch(e) {
                showPopup('success', 'Berhasil', 'Permintaan berhasil diproses.', function() { reloadAfter(); });
            }
        })
        .catch(function(err) {
            showPopup('error', 'Error', 'Gagal: ' + err.message);
        });
    }
    // Auto-reload setiap 15 detik untuk deteksi perubahan status permintaan
    setInterval(function() {
        if (!document.getElementById('app-popup') 
            && !document.querySelector('.notif-toast')
            && !new URLSearchParams(window.location.search).get('highlight')
            && !document.querySelector('.notif-panel.open')
            && !document.querySelector('.req-checkbox:checked')
            && !document.querySelector('.expand-detail-row[style*="table-row"]')) {
            location.reload();
        }
    }, 15000);

    // ===== EXPAND ROW (Multi-item) =====
    function toggleExpandRow(row) {
        // Jangan expand jika klik tombol di dalam row
        if (event.target.closest('button') || event.target.closest('input') || event.target.closest('a')) return;
        
        var detailRow = row.nextElementSibling;
        if (detailRow && detailRow.classList.contains('expand-detail-row')) {
            var isHidden = detailRow.style.display === 'none' || detailRow.style.display === '';
            detailRow.style.display = isHidden ? 'table-row' : 'none';
            row.classList.toggle('expanded', isHidden);
        }
    }

    // ===== RESEND NOTIFICATION (Admin only) =====
    // ===== KIRIM ULANG NOTIFIKASI — pilih MGR (utama) / SPV (wakil, butuh verifikasi password admin) =====
    function resendNotification(relocationId, assetName) {
        var old = document.getElementById('app-popup');
        if (old) old.remove();

        var overlay = document.createElement('div');
        overlay.id = 'app-popup';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;';

        overlay.innerHTML = '<div style="position:relative;background:#fff;border-radius:14px;padding:28px 32px;max-width:460px;width:92%;box-shadow:0 25px 60px rgba(0,0,0,0.25);animation:popIn 0.2s ease;">'
            + '<button onclick="closePopup()" title="Tutup" aria-label="Tutup" style="position:absolute;top:10px;right:10px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;background:transparent;color:#9ca3af;border:none;border-radius:8px;font-size:1.3rem;line-height:1;cursor:pointer;padding:0;" onmouseover="this.style.color=\'#1f2937\';this.style.background=\'#f3f4f6\'" onmouseout="this.style.color=\'#9ca3af\';this.style.background=\'transparent\'">&times;</button>'
            + '<h3 style="margin:0 0 4px;font-size:1.1rem;color:#1f2937;padding-right:32px;">Kirim Ulang Notifikasi — #' + relocationId + '</h3>'
            + '<p style="margin:0 0 14px;font-size:.8rem;color:#6b7280;">Aset: ' + assetName + '</p>'
            // Langkah 1: pilih target
            + '<div style="display:flex;flex-direction:column;gap:10px;">'
            + '<button onclick="doResend(' + relocationId + ', \'mgr\')" style="padding:12px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;text-align:left;">Kirim ke Manager (MGR) — <b>Utama</b></button>'
            + '<button onclick="showSpvPasswordStep()" style="padding:12px;background:#fef3c7;color:#92400e;border:1px solid #f59e0b;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;text-align:left;">Kirim ke Supervisor (SPV) — <b>Wakil</b><br><span style="font-size:.72rem;font-weight:400;"></span></button>'
            + '</div>'
            // Langkah 2 (SPV): verifikasi password akun admin
            + '<div id="spvPassStep" style="display:none;margin-top:14px;padding-top:14px;border-top:1px dashed #e5e7eb;">'
            + '<p style="margin:0 0 8px;font-size:.82rem;color:#374151;font-weight:600;">Verifikasi 2 Langkah:</p>'
            + '<input type="password" id="adminPassInput" placeholder="Password akun admin" style="width:100%;padding:10px 12px;border:2px solid #f59e0b;border-radius:8px;font-size:.9rem;margin-bottom:6px;" autocomplete="current-password">'
            + '<p id="spvPassError" style="color:#dc2626;font-size:.78rem;margin:0 0 8px;display:none;"></p>'
            + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
            + '<button onclick="doResend(' + relocationId + ', \'spv\')" style="padding:10px 16px;background:#d97706;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Kirim ke SPV</button>'
            + '<button onclick="showSpvPasswordStep(true)" style="padding:10px 16px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:8px;font-size:.85rem;cursor:pointer;">Kembali</button>'
            + '</div>'
            + '</div>'
            + '</div>'
            + '</div>';

        document.body.appendChild(overlay);

        overlay.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closePopup(); }
            var step = document.getElementById('spvPassStep');
            if (e.key === 'Enter' && step && step.style.display !== 'none') {
                doResend(relocationId, 'spv');
            }
        });
    }

    // Tampilkan / sembunyikan langkah verifikasi password SPV
    function showSpvPasswordStep(hide) {
        var step = document.getElementById('spvPassStep');
        if (!step) return;
        step.style.display = hide ? 'none' : 'block';
        if (!hide) {
            var err = document.getElementById('spvPassError');
            if (err) err.style.display = 'none';
            var inp = document.getElementById('adminPassInput');
            if (inp) { inp.focus(); }
        }
    }

    // Kirim request resend ke server (mgr = langsung, spv = verifikasi password admin)
    function doResend(relocationId, targetRole) {
        var formData = new FormData();
        formData.append('relocation_id', relocationId);
        formData.append('target_role', targetRole);
        if (targetRole === 'spv') {
            var passEl = document.getElementById('adminPassInput');
            var pwd = passEl ? passEl.value : '';
            if (!pwd) {
                var err0 = document.getElementById('spvPassError');
                if (err0) { err0.textContent = 'Password admin wajib diisi untuk delegasi ke SPV.'; err0.style.display = 'block'; }
                return;
            }
            formData.append('admin_password', pwd);
        }

        // Disable semua tombol popup (anti double-submit)
        var btns = document.querySelectorAll('#app-popup button');
        btns.forEach(function(b) { b.disabled = true; b.style.opacity = '0.6'; });

        fetch('api/resend_notification.php', { method: 'POST', body: formData })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var res = JSON.parse(text);
                if (res.ok) {
                    closePopup();
                    showPopup('success', 'Terkirim!', res.message || 'Notifikasi berhasil dikirim ulang.');
                } else if (res.message && res.message.toLowerCase().indexOf('password') !== -1) {
                    // Password salah → tetap di popup verifikasi, tampilkan error
                    var err = document.getElementById('spvPassError');
                    if (err) { err.textContent = res.message; err.style.display = 'block'; }
                    var step = document.getElementById('spvPassStep');
                    if (step) step.style.display = 'block';
                    btns.forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
                } else {
                    closePopup();
                    showPopup('error', 'Gagal', res.message || 'Gagal mengirim ulang notifikasi.');
                }
            } catch(e) {
                closePopup();
                showPopup('error', 'Error', 'Response tidak valid dari server.');
            }
        })
        .catch(function(err) {
            closePopup();
            showPopup('error', 'Error', 'Terjadi kesalahan: ' + err.message);
        });
    }

    // ===== FRANCHISE TOKEN POPUP (Admin) =====
    function openFranchiseTokenPopup(relocationId, assetName, fromLoc, toLoc, franchiseNote, approvalLevel) {
        var targetRole = approvalLevel === 'SPV' ? 'Supervisor (SPV)' : 'Manager (MGR)';
        
        var old = document.getElementById('app-popup');
        if (old) old.remove();

        var overlay = document.createElement('div');
        overlay.id = 'app-popup';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;';
        
        var noteHtml = franchiseNote 
            ? '<div style="background:#f0f9ff;border:1px solid #93c5fd;border-radius:6px;padding:10px;margin:10px 0;font-size:.82rem;color:#1e40af;"><strong>Keterangan:</strong><br>' + franchiseNote + '</div>'
            : '';

        overlay.innerHTML = '<div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:480px;width:92%;box-shadow:0 25px 60px rgba(0,0,0,0.25);animation:popIn 0.2s ease;">'
            + '<div style="text-align:center;margin-bottom:16px;">'
            + '<div style="width:56px;height:56px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:14px;font-weight:700;color:#2563eb;">FRC</div>'
            + '<h3 style="margin:0;font-size:1.1rem;color:#1f2937;">Token Franchise — #' + relocationId + '</h3>'
            + '<p style="margin:4px 0 0;font-size:.82rem;color:#6b7280;">Relokasi ke/dari toko franchise</p>'
            + '</div>'
            + '<div style="text-align:left;font-size:.85rem;color:#374151;line-height:1.6;">'
            + '<p><strong>Aset:</strong> ' + assetName + '</p>'
            + '<p><strong>Dari:</strong> ' + fromLoc + '</p>'
            + '<p><strong>Ke:</strong> ' + toLoc + '</p>'
            + '<p><strong>Approval Level:</strong> ' + targetRole + '</p>'
            + noteHtml
            + '</div>'
            + '<div style="margin:16px 0 12px;">'
            + '<label style="font-weight:600;font-size:.82rem;color:#374151;">Masukkan Kode Token Akses:</label>'
            + '<input type="text" id="frcTokenInput" maxlength="6" placeholder="Contoh: A1B2C3" '
            + 'style="width:100%;padding:10px 12px;border:2px solid #3b82f6;border-radius:8px;font-size:1.1rem;font-family:monospace;text-align:center;letter-spacing:3px;margin-top:6px;text-transform:uppercase;" autocomplete="off">'
            + '<p id="frcTokenError" style="color:#dc2626;font-size:.78rem;margin-top:4px;display:none;"></p>'
            + '</div>'
            + '<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:16px;">'
            + '<button onclick="validateFranchiseToken(' + relocationId + ')" style="padding:10px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Submit Token</button>'
            + '<button onclick="resendNotification(' + relocationId + ', \'' + assetName.replace(/\'/g, "\\\\'") + '\', \'' + approvalLevel + '\')" style="padding:10px 18px;background:#10b981;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Kirim Ulang Notif</button>'
            + '<button onclick="closePopup()" style="padding:10px 18px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:8px;font-size:.85rem;font-weight:500;cursor:pointer;">Batal</button>'
            + '</div>'
            + '</div>';

        document.body.appendChild(overlay);

        // Focus input
        setTimeout(function() {
            var inp = document.getElementById('frcTokenInput');
            if (inp) inp.focus();
        }, 100);

        // Enter key to submit
        overlay.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { validateFranchiseToken(relocationId); }
            if (e.key === 'Escape') { closePopup(); }
        });
    }

    // ===== FRANCHISE POPUP (MGR/SPV) =====
    function openMgrFranchisePopup(relocationId, assetName, fromLoc, toLoc, approvalLevel) {
        var old = document.getElementById('app-popup');
        if (old) old.remove();

        var overlay = document.createElement('div');
        overlay.id = 'app-popup';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;';

        overlay.innerHTML = '<div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:480px;width:92%;box-shadow:0 25px 60px rgba(0,0,0,0.25);animation:popIn 0.2s ease;">'
            + '<div style="text-align:center;margin-bottom:16px;">'
            + '<div style="width:56px;height:56px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px;"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>'
            + '<h3 style="margin:0;font-size:1.1rem;color:#1f2937;">Franchise Token — #' + relocationId + '</h3>'
            + '<p style="margin:4px 0 0;font-size:.82rem;color:#6b7280;">Approve hanya bisa via kode token 1 kali pakai</p>'
            + '</div>'
            + '<div style="text-align:left;font-size:.85rem;color:#374151;line-height:1.6;margin-bottom:12px;">'
            + '<p><strong>Aset:</strong> ' + assetName + '</p>'
            + '<p><strong>Dari:</strong> ' + fromLoc + '</p>'
            + '<p><strong>Ke:</strong> ' + toLoc + '</p>'
            + '<p><strong>Level:</strong> ' + approvalLevel + '</p>'
            + '</div>'
            + '<div id="mgrFrcTokenArea" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:14px;text-align:center;margin-bottom:14px;">'
            + '<p style="margin:0 0 8px;font-size:.8rem;color:#166534;">Memuat kode token...</p>'
            + '</div>'
            + '<div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;">'
            + '<button onclick="mgrFrcSendToAdmin(' + relocationId + ')" id="mgrFrcSendBtn" style="padding:10px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Kirim Kode ke Admin</button>'
            + '<button onclick="mgrFrcRegenToken(' + relocationId + ')" style="padding:10px 16px;background:#f59e0b;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Minta Ulang Kode</button>'
            + '<button onclick="mgrFrcReject(' + relocationId + ')" style="padding:10px 16px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Reject</button>'
            + '<button onclick="closePopup()" style="padding:10px 16px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:8px;font-size:.85rem;font-weight:500;cursor:pointer;">Batal</button>'
            + '</div>'
            + '</div>';

        document.body.appendChild(overlay);

        // Load token aktif untuk relokasi ini
        mgrFrcLoadToken(relocationId);

        overlay.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePopup();
        });
    }

    function mgrFrcLoadToken(relocationId) {
        var area = document.getElementById('mgrFrcTokenArea');
        if (!area) return;

        // Fetch active token from server
        fetch('api/franchise_token_generate.php', { method: 'POST', body: new URLSearchParams({ relocation_id: relocationId }) })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var res = JSON.parse(text);
                if (res.ok && res.token_code) {
                    // Token baru berhasil dibuat
                    area.innerHTML = '<p style="margin:0 0 6px;font-size:.75rem;color:#166534;font-weight:600;">KODE TOKEN ANDA:</p>'
                        + '<div style="font-size:1.5rem;font-family:monospace;font-weight:700;letter-spacing:4px;color:#15803d;" id="mgrFrcTokenCode">' + res.token_code + '</div>'
                        + '<p style="margin:6px 0 0;font-size:.72rem;color:#6b7280;">Berlaku 30 menit. 1 kali pakai.</p>';
                } else if (res.existing && res.token_code) {
                    // Token masih aktif — tampilkan kode yang ada
                    var expInfo = res.expires_at ? ' (s.d. ' + res.expires_at.substring(11,16) + ')' : '';
                    area.innerHTML = '<p style="margin:0 0 6px;font-size:.75rem;color:#166534;font-weight:600;">KODE TOKEN AKTIF:</p>'
                        + '<div style="font-size:1.5rem;font-family:monospace;font-weight:700;letter-spacing:4px;color:#15803d;" id="mgrFrcTokenCode">' + res.token_code + '</div>'
                        + '<p style="margin:6px 0 0;font-size:.72rem;color:#6b7280;">Token masih berlaku' + expInfo + '. 1 kali pakai.</p>';
                } else {
                    area.innerHTML = '<p style="margin:0;font-size:.8rem;color:#dc2626;">' + (res.message || 'Gagal memuat token') + '</p>';
                }
            } catch(e) {
                area.innerHTML = '<p style="margin:0;font-size:.8rem;color:#dc2626;">Error memuat token</p>';
            }
        })
        .catch(function() {
            area.innerHTML = '<p style="margin:0;font-size:.8rem;color:#dc2626;">Koneksi gagal</p>';
        });
    }

    function mgrFrcSendToAdmin(relocationId) {
        var tokenEl = document.getElementById('mgrFrcTokenCode');
        var tokenCode = tokenEl ? tokenEl.textContent.trim() : '';
        if (!tokenCode || tokenCode === '------') {
            showPopup('error', 'Error', 'Belum ada kode token. Klik "Minta Ulang Kode" dulu.');
            return;
        }

        var fd = new FormData();
        fd.append('relocation_id', relocationId);
        fd.append('token_code', tokenCode);

        fetch('api/franchise_token_share.php', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var res = JSON.parse(text);
                if (res.ok) {
                    closePopup();
                    showPopup('success', 'Berhasil!', res.message || 'Kode token berhasil dikirim ke Admin.');
                } else {
                    showPopup('error', 'Gagal', res.message || 'Gagal mengirim kode.');
                }
            } catch(e) {
                showPopup('error', 'Error', 'Response tidak valid.');
            }
        })
        .catch(function(err) { showPopup('error', 'Error', err.message); });
    }

    function mgrFrcRegenToken(relocationId) {
        var fd = new FormData();
        fd.append('relocation_id', relocationId);

        fetch('api/franchise_token_resend.php', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var res = JSON.parse(text);
                if (res.ok) {
                    closePopup();
                    showPopup('success', 'Kode Baru!', res.message || 'Kode token baru sudah dikirim ke Telegram Anda.', function() { location.reload(); });
                } else {
                    showPopup('error', 'Gagal', res.message || 'Gagal membuat kode baru.');
                }
            } catch(e) {
                showPopup('error', 'Error', 'Response tidak valid.');
            }
        })
        .catch(function(err) { showPopup('error', 'Error', err.message); });
    }

    function mgrFrcReject(relocationId) {
        showConfirm('danger', 'Tolak Relokasi Franchise?', '<p>Relokasi #' + relocationId + ' akan ditolak.</p><p style="color:#6b7280;font-size:.82rem;">Aksi ini tidak bisa dibatalkan.</p>', function() {
            var fd = new FormData();
            fd.append('relocation_id', relocationId);
            fd.append('code', '');
            fd.append('action', 'REJECT');

            fetch('api/approve_manual.php', { method: 'POST', body: fd })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                try {
                    var res = JSON.parse(text);
                    if (res.success) {
                        closePopup();
                        showPopup('success', 'Ditolak!', res.message || 'Relokasi franchise ditolak.', function() { location.reload(); });
                    } else {
                        showPopup('error', 'Gagal', res.message || 'Gagal menolak.');
                    }
                } catch(e) {
                    showPopup('error', 'Error', 'Response tidak valid.');
                }
            })
            .catch(function(err) { showPopup('error', 'Error', err.message); });
        });
    }

    function validateFranchiseToken(relocationId) {
        var input = document.getElementById('frcTokenInput');
        var errorEl = document.getElementById('frcTokenError');
        var tokenCode = (input ? input.value : '').trim().toUpperCase();

        if (!tokenCode || tokenCode.length < 4) {
            if (errorEl) { errorEl.textContent = 'Masukkan kode token (minimal 4 karakter)'; errorEl.style.display = 'block'; }
            if (input) input.focus();
            return;
        }

        // Disable input sementara
        if (input) { input.disabled = true; input.style.opacity = '0.6'; }
        if (errorEl) errorEl.style.display = 'none';

        var formData = new FormData();
        formData.append('relocation_id', relocationId);
        formData.append('token_code', tokenCode);

        fetch('api/franchise_token_validate.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.ok) {
                closePopup();
                showPopup('success', 'Berhasil Ter-Approve', res.message || 'Relokasi franchise berhasil disetujui!', function() {
                    setTimeout(function() { location.reload(); }, 1200);
                });
            } else {
                if (errorEl) { errorEl.textContent = res.message || 'Token tidak valid'; errorEl.style.display = 'block'; }
                if (input) { input.disabled = false; input.style.opacity = '1'; input.value = ''; input.focus(); }
            }
        })
        .catch(function(err) {
            if (errorEl) { errorEl.textContent = 'Error: ' + err.message; errorEl.style.display = 'block'; }
            if (input) { input.disabled = false; input.style.opacity = '1'; }
        });
    }

    function resendFranchiseToken(relocationId) {
        var formData = new FormData();
        formData.append('relocation_id', relocationId);

        fetch('api/franchise_token_resend.php', { method: 'POST', body: formData })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var res = JSON.parse(text);
                if (res.ok) {
                    showPopup('success', 'Token Dikirim Ulang!', res.message || 'Token baru telah dikirim.');
                } else {
                    showPopup('error', 'Gagal', res.message || 'Gagal mengirim ulang token.');
                }
            } catch(e) {
                console.error('Resend token response:', text);
                showPopup('error', 'Error', 'Response tidak valid dari server.');
            }
        })
        .catch(function(err) {
            showPopup('error', 'Error', 'Gagal: ' + err.message);
        });
    }

    // ===== BULK APPROVE/REJECT =====
    function toggleSelectAllReq(checkbox) {
        document.querySelectorAll('.req-checkbox').forEach(function(cb) {
            cb.checked = checkbox.checked;
        });
        updateBulkBar();
    }

    function updateBulkBar() {
        var checked = document.querySelectorAll('.req-checkbox:checked').length;
        var btn = document.getElementById('bulkMenuBtn');
        if (btn) {
            btn.style.display = checked > 0 ? 'inline' : 'none';
        }
    }

    function toggleBulkMenu(e) {
        if (e) e.stopPropagation();
        var drop = document.getElementById('bulkMenuDrop');
        if (drop) {
            var isHidden = drop.style.display === 'none' || drop.style.display === '';
            drop.style.display = isHidden ? 'flex' : 'none';
        }
    }

    // Close bulk menu saat klik di luar
    document.addEventListener('click', function(e) {
        var drop = document.getElementById('bulkMenuDrop');
        var btn = document.getElementById('bulkMenuBtn');
        if (drop && btn && !drop.contains(e.target) && !btn.contains(e.target)) {
            drop.style.display = 'none';
        }
    });

    function bulkAction(action) {
        var checked = document.querySelectorAll('.req-checkbox:checked');
        if (checked.length === 0) return;

        var drop = document.getElementById('bulkMenuDrop');
        if (drop) drop.style.display = 'none';

        var isApprove = action === 'APPROVE';
        var title = isApprove ? 'Approve ' + checked.length + ' permintaan?' : 'Reject ' + checked.length + ' permintaan?';
        var type = isApprove ? 'warning' : 'danger';

        showConfirm(type, title, '<p>Aksi ini akan memproses <strong>' + checked.length + '</strong> permintaan sekaligus.</p>', function() {
            var items = [];
            checked.forEach(function(cb) {
                items.push({ id: cb.dataset.id, code: cb.dataset.code, row: cb.closest('tr') });
            });

            var processed = 0;
            var errors = 0;

            function processNext() {
                if (processed >= items.length) {
                    var msg = (processed - errors) + ' berhasil ' + (isApprove ? 'diapprove' : 'direject');
                    if (errors > 0) msg += ', ' + errors + ' gagal';
                    showPopup('success', 'Selesai', msg);
                    updateBulkBar();
                    return;
                }

                var item = items[processed];
                var fd = new FormData();
                fd.append('relocation_id', item.id);
                fd.append('code', item.code);
                fd.append('action', action);

                fetch('api/approve_manual.php', { method: 'POST', body: fd })
                .then(function(r) { return r.text(); })
                .then(function() {
                    // Hapus baris dari DOM (tidak reload)
                    if (item.row) item.row.remove();
                    processed++;
                    processNext();
                })
                .catch(function() { errors++; processed++; processNext(); });
            }

            processNext();
        });
    }
    </script>
</body>
</html>


