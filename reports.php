<?php
/**
 * ==== LAPORAN ====
 * Rekap aset & mutasi per periode tanggal + export CSV / print
 * Akses: admin, spv, mgr
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
auth_require();

$user = auth_user();

// Check if user has report access — admin, spv, mgr semua bisa
$user_role    = $user['role'] ?? '';
$allowed_roles = ['admin', 'spv', 'mgr'];
if (!in_array($user_role, $allowed_roles)) {
    header('Location: dashboard.php');
    exit;
}

$conn = db();

// Get report parameters
$report_type = $_GET['type'] ?? 'assets';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$exportMode = isset($_GET['export']); // semua mode export = no limit
$rowLimit = 1000;
$isTruncated = false;

// Normalize: requests_ra, requests_sa, dll dianggap sebagai 'requests' untuk tampilan/export
$report_type_base = str_starts_with($report_type, 'requests') ? 'requests' : $report_type;

// Filter tambahan untuk Data Aset
$filter_cabang   = trim($_GET['filter_cabang'] ?? '');
$filter_toko     = trim($_GET['filter_toko'] ?? '');
$filter_kategori = trim($_GET['filter_kategori'] ?? '');
$filter_status   = trim($_GET['filter_status'] ?? '');
$filter_search   = trim($_GET['filter_search'] ?? '');
$filter_sj       = trim($_GET['filter_sj'] ?? '');   // pencarian No. Surat Jalan (laporan mutasi)

// Sort — default by tanggal (id DESC untuk assets, created_at DESC untuk lainnya)
$sort_col = $_GET['sort_col'] ?? '';
$sort_dir = ($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$sort_dir_toggle = $sort_dir === 'ASC' ? 'DESC' : 'ASC';

// Helper format tanggal dengan nama hari (Indonesia)
function tglHari(string $dateStr, bool $withTime = false): string {
    $hari = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $ts = strtotime($dateStr);
    $namaHari = $hari[date('l', $ts)] ?? date('l', $ts);
    return $namaHari . ', ' . date('d/m/Y', $ts) . ($withTime ? ' ' . date('H:i', $ts) : '');
}
function tglHariNow(bool $withTime = true): string {
    $hari = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $namaHari = $hari[date('l')] ?? date('l');
    return $namaHari . ', ' . date('d/m/Y') . ($withTime ? ' ' . date('H:i:s') : '');
}

// Helper: buat URL sort untuk header kolom
function sortUrl(string $col): string {
    global $sort_col, $sort_dir_toggle, $sort_dir;
    $params = $_GET;
    $params['sort_col'] = $col;
    $params['sort_dir'] = ($sort_col === $col) ? $sort_dir_toggle : 'ASC';
    unset($params['export']);
    return '?' . http_build_query($params);
}
function sortArrow(string $col): string {
    global $sort_col, $sort_dir;
    if ($sort_col !== $col) return '<span style="opacity:.35;font-size:10px;"> ⇅</span>';
    return $sort_dir === 'ASC'
        ? '<span style="color:#fbbf24;font-size:10px;"> ▲</span>'
        : '<span style="color:#fbbf24;font-size:10px;"> ▼</span>';
}

// Whitelist kolom sort per tipe
$allowed_assets   = ['id','cabang','toko','sub_code','kategori','keterangan','no_seri','kuantitas','biaya_perolehan','masa_manfaat_bln','beban_penyusutan_bln','umur_jalan_bln','akumulasi_penyusutan','status'];
$allowed_requests = ['id','asset_code','asset_name','from_loc','to_loc','requester_name','status','created_at'];
$allowed_approvals= ['id','type','asset_name','relocation_id','created_at'];

// Ambil distinct values untuk dropdown filter aset
$opt_cabangs   = $conn->query("SELECT DISTINCT cabang FROM assets_real WHERE cabang IS NOT NULL AND cabang != '' ORDER BY cabang")->fetchAll(PDO::FETCH_COLUMN);
$opt_tokos     = $conn->query("SELECT DISTINCT toko FROM assets_real WHERE toko IS NOT NULL AND toko != '' ORDER BY toko")->fetchAll(PDO::FETCH_COLUMN);
$opt_kategoris = $conn->query("SELECT DISTINCT kategori FROM assets_real WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
$opt_statuses  = $conn->query("SELECT DISTINCT status FROM assets_real WHERE status IS NOT NULL AND status != '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);

$data = [];
$title = '';

switch ($report_type) {
    case 'assets':
        $title = 'Laporan Data Aset';
        $where = [];
        $params = [];
        if ($filter_cabang)   { $where[] = 'cabang = ?';   $params[] = $filter_cabang; }
        if ($filter_toko)     { $where[] = 'toko = ?';     $params[] = $filter_toko; }
        if ($filter_kategori) { $where[] = 'kategori = ?'; $params[] = $filter_kategori; }
        if ($filter_status)   { $where[] = 'status = ?';   $params[] = $filter_status; }
        if ($filter_search)   { $where[] = '(keterangan LIKE ? OR no_seri LIKE ?)'; $params[] = "%{$filter_search}%"; $params[] = "%{$filter_search}%"; }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limitClause = $exportMode ? '' : " LIMIT {$rowLimit}";
        // Default sort: id DESC
        $eff_col = (in_array($sort_col, $allowed_assets) && $sort_col) ? $sort_col : 'id';
        $stmt = $conn->prepare("SELECT id, cabang, toko, sub_code, kategori, keterangan, no_seri, kuantitas, biaya_perolehan, masa_manfaat_bln, beban_penyusutan_bln, umur_jalan_bln, akumulasi_penyusutan, status FROM assets_real {$whereClause} ORDER BY {$eff_col} {$sort_dir}" . $limitClause);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $isTruncated = !$exportMode && count($data) >= $rowLimit;
        break;

    case 'requests':
    case 'requests_ra':
    case 'requests_sa':
    case 'requests_ps':
    case 'requests_pa':
    case 'requests_pp':
        // Tentukan filter jenis_sj berdasarkan tipe
        $jenis_filter = '';
        $jenis_params = [$start_date, $end_date];
        $jenis_titles = [
            'requests'    => 'Laporan Semua Mutasi',
            'requests_ra' => 'Laporan Relokasi Aset',
            'requests_sa' => 'Laporan Sewa Aset',
            'requests_ps' => 'Laporan Pengembalian Sewa',
            'requests_pa' => 'Laporan Pinjam Aset',
            'requests_pp' => 'Laporan Pengembalian Pinjam',
        ];
        $title = $jenis_titles[$report_type] ?? 'Laporan Mutasi';

        if ($report_type !== 'requests') {
            $jenis_code = strtoupper(substr($report_type, 9)); // requests_ra → RA
            $jenis_filter = " AND r.jenis_sj = ?";
            $jenis_params[] = $jenis_code;
        }

        // Filter pencarian No. Surat Jalan (misal: SJ-RA-GA-PRG-F4JJ-05-09-2026/000001)
        if ($filter_sj !== '') {
            $jenis_filter .= " AND (r.sj_number LIKE ? OR r.reason LIKE ?)";
            $jenis_params[] = "%{$filter_sj}%";
            $jenis_params[] = "%{$filter_sj}%";
        }

        $limitClause = $exportMode ? '' : " LIMIT {$rowLimit}";
        $eff_col = (in_array($sort_col, $allowed_requests) && $sort_col) ? 'r.'.$sort_col : 'r.created_at';
        if ($sort_col === 'from_loc')       $eff_col = 'l1.name';
        if ($sort_col === 'to_loc')         $eff_col = 'l2.name';
        if ($sort_col === 'asset_code')     $eff_col = 'a.asset_code';
        if ($sort_col === 'asset_name')     $eff_col = 'a.name';
        if ($sort_col === 'requester_name') $eff_col = 'u.name';
        $stmt = $conn->prepare("
            SELECT r.*, a.asset_code, a.name as asset_name,
                   ar.no_seri as real_no_seri,
                   CASE WHEN l1.name IN ('Gudang GA','Toko 001') THEN COALESCE(ar.toko, l1.name) ELSE l1.name END as from_loc,
                   CASE WHEN l2.name IN ('Gudang GA','Toko 001') THEN COALESCE(ar.toko, l2.name) ELSE l2.name END as to_loc,
                   u.name as requester_name,
                   u2.name as approver_name, ro.name as approver_role
            FROM relocations r
            LEFT JOIN assets a ON a.id = r.asset_id
            LEFT JOIN assets_real ar ON ar.id = r.asset_id
            LEFT JOIN locations l1 ON l1.id = r.from_location_id
            LEFT JOIN locations l2 ON l2.id = r.to_location_id
            LEFT JOIN users u ON u.id = r.created_by
            LEFT JOIN users u2 ON u2.id = r.approved_by
            LEFT JOIN roles ro ON ro.id = u2.role_id
            WHERE DATE(r.created_at) BETWEEN ? AND ?{$jenis_filter}
            ORDER BY {$eff_col} {$sort_dir}
            " . $limitClause);
        $stmt->execute($jenis_params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $isTruncated = !$exportMode && count($data) >= $rowLimit;
        break;

    case 'approvals':
        $title = 'Laporan Anomali';
        $limitClause = $exportMode ? '' : " LIMIT {$rowLimit}";
        
        // Cek nama kolom type di anomaly_alerts (bisa 'type' atau 'alert_type')
        $typeCol = 'type';
        try {
            $colCheck = $conn->query("SHOW COLUMNS FROM anomaly_alerts LIKE 'type'")->fetchAll();
            if (empty($colCheck)) {
                $colCheck2 = $conn->query("SHOW COLUMNS FROM anomaly_alerts LIKE 'alert_type'")->fetchAll();
                $typeCol = !empty($colCheck2) ? 'alert_type' : "'UNKNOWN'";
            }
        } catch (Exception $e) { $typeCol = "'UNKNOWN'"; }
        
        $stmt = $conn->prepare("
            SELECT aa.id, aa.relocation_id, aa.asset_id, aa.score, aa.message, aa.created_at,
                   aa.{$typeCol} as alert_type,
                   a.asset_code, a.name as asset_name,
                   ar.no_seri as real_no_seri,
                   r.reason, r.status as relocation_status,
                   CASE WHEN l1.name IN ('Gudang GA','Toko 001') THEN COALESCE(ar2.toko, l1.name) ELSE l1.name END as from_loc,
                   CASE WHEN l2.name IN ('Gudang GA','Toko 001') THEN COALESCE(ar2.toko, l2.name) ELSE l2.name END as to_loc,
                   u1.name as requester_name
            FROM anomaly_alerts aa
            LEFT JOIN relocations r ON r.id = aa.relocation_id
            LEFT JOIN assets a ON a.id = aa.asset_id
            LEFT JOIN assets_real ar ON ar.id = aa.asset_id
            LEFT JOIN assets_real ar2 ON ar2.id = r.asset_id
            LEFT JOIN locations l1 ON l1.id = r.from_location_id
            LEFT JOIN locations l2 ON l2.id = r.to_location_id
            LEFT JOIN users u1 ON u1.id = r.created_by
            WHERE DATE(aa.created_at) BETWEEN ? AND ?
            ORDER BY aa.created_at {$sort_dir}
            " . $limitClause);
        $stmt->execute([$start_date, $end_date]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $isTruncated = !$exportMode && count($data) >= $rowLimit;
        break;
}

// ── Export Excel (HTML Table format) ──
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $namaLaporan = ['assets' => 'Laporan Data Aset', 'requests' => 'Laporan Mutasi', 'approvals' => 'Laporan Anomali'][$report_type] ?? 'Laporan';
    $filename = $namaLaporan . ' - ' . date('d-m-Y') . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    if ($report_type === 'assets') {
        $headers = ['ID', 'Cabang', 'Toko', 'Sub Code', 'Kategori', 'Keterangan', 'No Seri', 'Kuantitas', 'Biaya Perolehan', 'Masa Manfaat (bln)', 'Beban Penyusutan (bln)', 'Umur Jalan (bln)', 'Akumulasi Penyusutan', 'Status'];
        $rows = array_map(fn($r) => [$r['id'],$r['cabang'],$r['toko'],$r['sub_code'],$r['kategori'],$r['keterangan'],$r['no_seri'],$r['kuantitas'],$r['biaya_perolehan'],$r['masa_manfaat_bln'],$r['beban_penyusutan_bln'],$r['umur_jalan_bln'],$r['akumulasi_penyusutan'],$r['status']], $data);
    } elseif ($report_type_base === 'requests') {
        $headers = ['ID', 'Tanggal', 'Jam', 'No Seri', 'Nama Aset', 'Asal', 'Tujuan', 'Pemohon', 'Status', 'Diproses Oleh', 'Jenis SJ', 'No Surat Jalan', 'Via'];
        $rows = array_map(function($r) use ($conn) {
            $via = '-';
            if ($r['status'] !== 'PENDING') { try { $lStmt=$conn->prepare("SELECT note FROM approvals_log WHERE relocation_id=? AND action IN('APPROVE','REJECT') ORDER BY created_at DESC LIMIT 1"); $lStmt->execute([$r['id']]); $n=$lStmt->fetchColumn(); $via=($n && stripos($n,'telegram')!==false)?'Telegram':'Web'; } catch(Exception $e){} }
            $noSeri = !empty($r['real_no_seri']) ? $r['real_no_seri'] : '-';
            $approver = !empty($r['approver_name']) ? $r['approver_name'] : '-';
            if (!empty($r['approver_role'])) $approver .= ' (' . ucfirst($r['approver_role']) . ')';
            $ts = strtotime($r['created_at']);
            $tgl = tglHari($r['created_at'], false);
            $jam = date('H:i', $ts);
            $jenisSjLabel = jenis_sj_label($r['jenis_sj'] ?? 'RA');
            return [$r['id'], $tgl, $jam, $noSeri, $r['asset_name'] ?? '-', $r['from_loc'] ?? '-', $r['to_loc'] ?? '-', $r['requester_name'] ?? '-', $r['status'], $approver, $jenisSjLabel, $r['reason'] ?? '-', $via];
        }, $data);
    } else {
        $headers = ['ID', 'No Seri', 'Nama Aset', 'Tipe Anomali', 'Pesan', 'Relokasi ID', 'Asal', 'Tujuan', 'Pemohon', 'Status', 'Tanggal', 'Jam'];
        $rows = array_map(function($r) {
            $ts = strtotime($r['created_at']);
            $tgl = tglHari($r['created_at'], false);
            $jam = date('H:i', $ts);
            return [$r['id'], $r['real_no_seri'] ?? $r['asset_code'] ?? '-', $r['asset_name'] ?? '-', $r['alert_type'] ?? '-', $r['message'] ?? '-', $r['relocation_id'] ?? '-', $r['from_loc'] ?? '-', $r['to_loc'] ?? '-', $r['requester_name'] ?? '-', $r['relocation_status'] ?? '-', $tgl, $jam];
        }, $data);
    }

    // HTML Table format — Excel membaca ini tanpa warning
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Laporan</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
    echo '<body>';
    echo '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">';

    // Header row
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th style="background-color:#0b5ea8;color:#ffffff;font-weight:bold;font-size:11px;text-align:center;">' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr>';

    // Data rows
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            $val = htmlspecialchars((string)$cell);
            // Force text format untuk nomor seri agar tidak jadi scientific notation
            if (preg_match('/^\d{5,}$/', (string)$cell)) {
                echo '<td style="mso-number-format:\@;font-size:10px;">' . $val . '</td>';
            } else {
                echo '<td style="font-size:10px;">' . $val . '</td>';
            }
        }
        echo '</tr>';
    }

    echo '</table></body></html>';
    exit;
}

// ── Export TXT ──
if (isset($_GET['export']) && $_GET['export'] === 'txt') {
    $namaLaporan = ['assets' => 'Laporan Data Aset', 'requests' => 'Laporan Mutasi', 'approvals' => 'Laporan Anomali'][$report_type] ?? 'Laporan';
    $filename = $namaLaporan . ' - ' . date('d-m-Y') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($report_type === 'assets') {
        $headers = ['ID', 'Cabang', 'Toko', 'Sub Code', 'Kategori', 'Keterangan', 'No Seri', 'Kuantitas', 'Biaya Perolehan', 'Masa Manfaat (bln)', 'Beban Penyusutan (bln)', 'Umur Jalan (bln)', 'Akumulasi Penyusutan', 'Status'];
        $rows = array_map(fn($r) => [$r['id'],$r['cabang'],$r['toko'],$r['sub_code'],$r['kategori'],$r['keterangan'],$r['no_seri'],$r['kuantitas'],$r['biaya_perolehan'],$r['masa_manfaat_bln'],$r['beban_penyusutan_bln'],$r['umur_jalan_bln'],$r['akumulasi_penyusutan'],$r['status']], $data);
    } elseif ($report_type_base === 'requests') {
        $headers = ['ID', 'Tanggal', 'Jam', 'No Seri', 'Nama Aset', 'Asal', 'Tujuan', 'Pemohon', 'Status', 'Diproses Oleh', 'Jenis SJ', 'No Surat Jalan', 'Via'];
        $rows = array_map(function($r) use ($conn) {
            $via = '-';
            if ($r['status'] !== 'PENDING') { try { $lStmt=$conn->prepare("SELECT note FROM approvals_log WHERE relocation_id=? AND action IN('APPROVE','REJECT') ORDER BY created_at DESC LIMIT 1"); $lStmt->execute([$r['id']]); $n=$lStmt->fetchColumn(); $via=($n && stripos($n,'telegram')!==false)?'Telegram':'Web'; } catch(Exception $e){} }
            $noSeri = !empty($r['real_no_seri']) ? $r['real_no_seri'] : '-';
            $approver = !empty($r['approver_name']) ? $r['approver_name'] : '-';
            if (!empty($r['approver_role'])) $approver .= ' (' . ucfirst($r['approver_role']) . ')';
            $ts = strtotime($r['created_at']);
            $tgl = tglHari($r['created_at'], false);
            $jam = date('H:i', $ts);
            $jenisSjLabel = jenis_sj_label($r['jenis_sj'] ?? 'RA');
            return [$r['id'], $tgl, $jam, $noSeri, $r['asset_name'] ?? '-', $r['from_loc'] ?? '-', $r['to_loc'] ?? '-', $r['requester_name'] ?? '-', $r['status'], $approver, $jenisSjLabel, $r['reason'] ?? '-', $via];
        }, $data);
    } else {
        $headers = ['ID', 'No Seri', 'Nama Aset', 'Tipe Anomali', 'Pesan', 'Relokasi ID', 'Asal', 'Tujuan', 'Pemohon', 'Status', 'Tanggal', 'Jam'];
        $rows = array_map(function($r) {
            $ts = strtotime($r['created_at']);
            $tgl = tglHari($r['created_at'], false);
            $jam = date('H:i', $ts);
            return [$r['id'], $r['real_no_seri'] ?? $r['asset_code'] ?? '-', $r['asset_name'] ?? '-', $r['alert_type'] ?? '-', $r['message'] ?? '-', $r['relocation_id'] ?? '-', $r['from_loc'] ?? '-', $r['to_loc'] ?? '-', $r['requester_name'] ?? '-', $r['relocation_status'] ?? '-', $tgl, $jam];
        }, $data);
    }

    $sep = str_repeat('-', 120) . "\n";
    echo "SISTEM RELOKASI ASET - PT INDOMARCO PRISMATAMA GENERAL AFFAIR - CAB PARUNG\n";
    echo "Tipe  : " . strtoupper($report_type) . "\n";
    echo "Tanggal Generate : " . tglHariNow() . "\n";
    echo "Total Data : " . count($data) . "\n";
    echo $sep;
    echo implode("\t| ", $headers) . "\n";
    echo $sep;
    foreach ($rows as $row) {
        echo implode("\t| ", array_map('strval', $row)) . "\n";
    }
    echo $sep;
    exit;
}

// ── Export PDF (HTML print) ──
if (isset($_GET['export']) && in_array($_GET['export'], ['pdf', 'pdf_download', 'pdf_designer'], true)) {
    $isDownload = $_GET['export'] === 'pdf_download';

    // Build activeFilters for PDF
    $activeFilters = [];
    if ($filter_cabang)   $activeFilters[] = ['label' => 'Cabang',     'value' => $filter_cabang];
    if ($filter_toko)     $activeFilters[] = ['label' => 'Toko',       'value' => $filter_toko];
    if ($filter_kategori) $activeFilters[] = ['label' => 'Kategori',   'value' => $filter_kategori];
    if ($filter_status)   $activeFilters[] = ['label' => 'Status',     'value' => $filter_status];
    if ($filter_search)   $activeFilters[] = ['label' => 'Kata Kunci', 'value' => $filter_search];
    if ($filter_sj)       $activeFilters[] = ['label' => 'No. SJ',    'value' => $filter_sj];
    if ($report_type === 'assets') {
        $fmtRp = fn($v) => 'Rp ' . number_format((int)($v ?? 0));
        $headers = ['ID', 'Cabang', 'Toko', 'Sub Code', 'Kategori', 'Keterangan', 'No Seri', 'Kuantitas', 'Biaya Perolehan', 'Masa Manfaat (bln)', 'Beban Penyusutan (bln)', 'Umur Jalan (bln)', 'Akumulasi Penyusutan', 'Status'];
        $rows = array_map(fn($r) => [$r['id'],$r['cabang'],$r['toko'],$r['sub_code'],$r['kategori'],$r['keterangan'],$r['no_seri'],$r['kuantitas'],$fmtRp($r['biaya_perolehan']),(int)($r['masa_manfaat_bln'] ?? 0),$fmtRp($r['beban_penyusutan_bln']),(int)($r['umur_jalan_bln'] ?? 0),$fmtRp($r['akumulasi_penyusutan']),$r['status']], $data);
    } elseif ($report_type_base === 'requests') {
        $headers = ['ID', 'Tanggal', 'Jam', 'No Seri', 'Nama Aset', 'Asal', 'Tujuan', 'Pemohon', 'Status', 'Diproses Oleh', 'Jenis SJ', 'No Surat Jalan', 'Via'];
        $rows = array_map(function($r) use ($conn) {
            $via = '-';
            if ($r['status'] !== 'PENDING') { try { $lStmt=$conn->prepare("SELECT note FROM approvals_log WHERE relocation_id=? AND action IN('APPROVE','REJECT') ORDER BY created_at DESC LIMIT 1"); $lStmt->execute([$r['id']]); $n=$lStmt->fetchColumn(); $via=($n && stripos($n,'telegram')!==false)?'Telegram':'Web'; } catch(Exception $e){} }
            $noSeri = !empty($r['real_no_seri']) ? $r['real_no_seri'] : '-';
            $approver = !empty($r['approver_name']) ? $r['approver_name'] : '-';
            if (!empty($r['approver_role'])) $approver .= ' (' . ucfirst($r['approver_role']) . ')';
            $ts = strtotime($r['created_at']);
            $tgl = tglHari($r['created_at'], false);
            $jam = date('H:i', $ts);
            $jenisSjLabel = jenis_sj_label($r['jenis_sj'] ?? 'RA');
            return [$r['id'], $tgl, $jam, $noSeri, $r['asset_name'] ?? '-', $r['from_loc'] ?? '-', $r['to_loc'] ?? '-', $r['requester_name'] ?? '-', $r['status'], $approver, $jenisSjLabel, $r['reason'] ?? '-', $via];
        }, $data);
    } else {
        $headers = ['ID', 'No Seri', 'Nama Aset', 'Tipe Anomali', 'Pesan', 'Relokasi ID', 'Asal', 'Tujuan', 'Pemohon', 'Status', 'Tanggal', 'Jam'];
        $rows = array_map(function($r) {
            $ts = strtotime($r['created_at']);
            $tgl = tglHari($r['created_at'], false);
            $jam = date('H:i', $ts);
            return [$r['id'], $r['real_no_seri'] ?? $r['asset_code'] ?? '-', $r['asset_name'] ?? '-', $r['alert_type'] ?? '-', $r['message'] ?? '-', $r['relocation_id'] ?? '-', $r['from_loc'] ?? '-', $r['to_loc'] ?? '-', $r['requester_name'] ?? '-', $r['relocation_status'] ?? '-', $tgl, $jam];
        }, $data);
    }
    $typeLabel = ['assets'=>'Data Aset','requests'=>'Permintaan Relokasi','approvals'=>'Anomali'][$report_type] ?? $report_type;
    // Encode logo sebagai base64 agar tampil di file download maupun preview
    $logoPath = __DIR__ . '/images/logo-sra.png';
    $logoBase64 = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';

    /* ═══ PDF DESIGNER — halaman atur layout + preview sebelum cetak (di tab sama) ═══ */
    if ($_GET['export'] === 'pdf_designer') {
        $pdJsonFlags    = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $numericHeaders = ['Kuantitas','Biaya Perolehan','Masa Manfaat (bln)','Beban Penyusutan (bln)','Umur Jalan (bln)','Akumulasi Penyusutan'];
        $pdCols = [];
        foreach ($headers as $ci => $h) {
            $pdCols[] = ['idx' => $ci, 'label' => $h, 'num' => in_array($h, $numericHeaders, true)];
        }
        $backUrl         = '?' . http_build_query(array_diff_key($_GET, ['export' => '']));
        $docTitleDefault = 'Laporan ' . $typeLabel;
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PDF Designer — Laporan <?= htmlspecialchars($typeLabel) ?></title>
<link rel="icon" type="image/png" sizes="64x64" href="images/logo-sra.png">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Arial,sans-serif; background:#eef2f7; color:#0f172a; }
.pd-topbar { position:sticky; top:0; z-index:50; display:flex; align-items:center; gap:12px; padding:10px 16px; background:#fff; border-bottom:1px solid #e2e8f0; box-shadow:0 1px 10px rgba(15,23,42,.06); flex-wrap:wrap; }
.pd-topbar h1 { font-size:1rem; font-weight:800; flex:1; min-width:240px; }
.pd-btn { border:none; border-radius:8px; padding:8px 16px; font-size:.85rem; font-weight:700; cursor:pointer; font-family:inherit; }
.pd-btn-back { background:#f1f5f9; color:#0f172a; }
.pd-btn-back:hover { background:#e2e8f0; }
.pd-btn-print { background:linear-gradient(135deg,#ef4444,#b91c1c); color:#fff; }
.pd-btn-print:hover { filter:brightness(1.08); }
.pd-layout { display:flex; height:calc(100vh - 53px); }
.pd-settings { width:300px; flex-shrink:0; overflow-y:auto; padding:18px; background:#f8fafc; border-right:1px solid #e2e8f0; }
.pd-settings h3 { font-size:1rem; font-weight:800; color:#0f172a; }
.pd-sub { font-size:.75rem; color:#64748b; margin:2px 0 14px; line-height:1.4; }
.pd-group { margin-bottom:12px; }
.pd-group > label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#475569; margin-bottom:5px; }
.pd-settings select, .pd-settings input[type=text], .pd-settings input[type=number] { width:100%; padding:7px 9px; border:1px solid #cbd5e1; border-radius:7px; font-size:.85rem; background:#fff; color:#0f172a; font-family:inherit; }
.pd-check { display:flex; align-items:center; gap:7px; font-size:.83rem; color:#334155; padding:3px 0; cursor:pointer; }
.pd-check input { accent-color:#dc2626; width:15px; height:15px; flex-shrink:0; }
#pd-cols { max-height:200px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:7px; padding:6px 10px; background:#fff; }
.pd-hint { font-size:.72rem; color:#64748b; margin-top:10px; line-height:1.5; }
.pd-preview { flex:1; overflow:auto; padding:24px; background:#d7dfea; position:relative; }
#pd-scale-info { position:sticky; top:0; float:right; font-size:.72rem; color:#334155; background:rgba(255,255,255,.9); padding:3px 10px; border-radius:20px; border:1px solid #cbd5e1; margin-bottom:6px; z-index:2; }
#pd-paper-scale { width:fit-content; margin:0 auto; }
#pd-paper { display:flex; flex-direction:column; gap:18px; width:fit-content; margin:0 auto; }
.pd-sheet { background:#fff; box-shadow:0 6px 30px rgba(15,23,42,.28); font-family:'Segoe UI',sans-serif; position:relative; }
.pd-frame { position:relative; }
.pd-multi .pd-frame { outline:1px dashed #cbd5e1; outline-offset:-1px; }
.pd-frame-inner { overflow-wrap:break-word; }
.pd-logo { height:52px; width:auto; margin-bottom:6px; display:block; }
.pd-doc-title { font-weight:800; color:#0f172a; margin-bottom:4px; overflow-wrap:break-word; }
.pd-doc-meta { color:#64748b; margin-bottom:12px; overflow-wrap:break-word; }
.pd-runhead { font-weight:800; color:#0f172a; margin-bottom:2px; display:flex; justify-content:space-between; align-items:baseline; gap:10px; }
.pd-runhead .rh-t { overflow-wrap:break-word; }
.pd-runhead .rh-p { color:#94a3b8; white-space:nowrap; flex-shrink:0; }
.pd-runmeta { color:#94a3b8; margin-bottom:8px; }
.pd-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
.pd-chips span { display:inline-flex; align-items:center; gap:4px; background:#f0f6ff; border:1px solid #c7d9f5; border-radius:20px; padding:3px 10px; font-size:9px; color:#1e40af; }
.pd-chips b { color:#0b5ea8; }
.pd-t { width:100%; border-collapse:collapse; table-layout:auto; }
.pd-t th { color:#fff; text-align:left; white-space:nowrap; }
.pd-t td { vertical-align:top; }
.pd-t td.num { text-align:right; white-space:nowrap; }
.pd-t.pd-nowrap td { white-space:nowrap; }
.pd-sec { margin:16px 0 8px; padding-bottom:4px; border-bottom:1px solid #e2e8f0; font-size:.66rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; }
.pd-actions { margin-top:14px; }
.pd-actions .pd-btn { width:100%; }
#pd-pages-info { position:sticky; top:0; float:left; font-size:.72rem; color:#0f172a; background:rgba(255,255,255,.92); padding:3px 10px; border-radius:20px; border:1px solid #cbd5e1; margin-bottom:6px; z-index:2; line-height:1.5; }
.pd-custom-input { padding-right:32px !important; -moz-appearance:textfield; appearance:textfield; }
.pd-custom-input::-webkit-outer-spin-button, .pd-custom-input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.pd-swap { position:relative; }
.pd-swap select, .pd-swap input { width:100%; }
.pd-back { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:22px; height:22px; padding:0; border:none; border-radius:50%; background:#eef2f7; color:#64748b; font-size:13px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; }
.pd-back:hover { background:#e2e8f0; color:#0f172a; }
.pd-openmodal { width:100%; margin-top:6px; }
#pd-margin-summary { font-size:.68rem; color:#64748b; margin-top:6px; line-height:1.5; }
/* Pop-up margin & teks */
.pd-modal-bg { position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; z-index:60; padding:16px; }
.pd-modal-bg.open { display:flex; }
.pd-modal { background:#fff; border-radius:14px; width:440px; max-width:100%; max-height:90vh; overflow:auto; box-shadow:0 20px 60px rgba(2,6,23,.35); }
.pd-modal header { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; border-bottom:1px solid #e2e8f0; }
.pd-modal header b { color:#0f172a; font-size:.9rem; }
.pd-modal header button { background:none; border:none; font-size:20px; line-height:1; cursor:pointer; color:#64748b; }
.pd-modal-body { padding:14px 16px; display:flex; flex-direction:column; gap:16px; }
.pd-modal-sec { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; }
.pd-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.pd-grid4 { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.pd-modal label { display:flex; flex-direction:column; gap:4px; font-size:.72rem; font-weight:600; color:#475569; }
.pd-modal input, .pd-modal select { width:100%; padding:7px 9px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; font-size:.8rem; background:#fff; color:#0f172a; }
.pd-modal footer { display:flex; gap:8px; justify-content:flex-end; padding:12px 16px; border-top:1px solid #e2e8f0; }
/* Warna tema tetap tercetak walau "Background graphics" tidak dicentang */
.pd-sheet, .pd-sheet * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
@media (max-width:900px) {
    .pd-layout { flex-direction:column; height:auto; }
    .pd-settings { width:auto; border-right:none; border-bottom:1px solid #e2e8f0; }
    .pd-preview { min-height:60vh; }
}
/* ── Saat mencetak/unduh: hanya lembar laporan yang dicetak ── */
@media print {
    body { background:#fff !important; }
    .pd-topbar, .pd-settings, #pd-scale-info, #pd-pages-info { display:none !important; }
    .pd-layout { display:block; height:auto; }
    .pd-preview { overflow:visible !important; padding:0 !important; background:#fff !important; }
    #pd-paper-scale { zoom:1 !important; }
    #pd-paper { width:auto !important; }
    .pd-sheet { width:auto !important; min-height:0 !important; padding:0 !important; box-shadow:none !important; break-after:page; page-break-after:always; }
    .pd-sheet:last-child { break-after:auto; page-break-after:auto; }
    .pd-frames { height:auto !important; }
    .pd-frame { break-inside:avoid; page-break-inside:avoid; }
    .pd-t { width:100%; }
    .pd-t thead { display:table-header-group; }
    .pd-t tr { page-break-inside:avoid; }
}
</style>
</head>
<body>
<style id="pd-page-style">@page { size: 297mm 210mm; margin: 12mm; }</style>
<style id="pd-table-style"></style>
<div class="pd-topbar">
    <button class="pd-btn pd-btn-back" onclick="location.href='<?= htmlspecialchars($backUrl) ?>'">&larr;</button>
    <h1>&#128424; PDF Designer - Laporan <?= htmlspecialchars($typeLabel) ?>
        <span style="font-weight:600;color:#64748b;font-size:.8rem;"><?= number_format(count($data)) ?> baris &middot; data penuh</span></h1>
    <button class="pd-btn pd-btn-print" onclick="window.print()">&darr; Unduh PDF</button>
</div>
<div class="pd-layout">
    <aside class="pd-settings">
        <h3>Edit Unduhan PDF</h3>
        <div class="pd-sec">Dokumen</div>
        <div class="pd-group">
            <label for="pd-title">Judul Dokumen</label>
            <input type="text" id="pd-title" value="<?= htmlspecialchars($docTitleDefault) ?>" oninput="applyPdfDesign()">
        </div>
        <div class="pd-group">
            <label for="pd-copies">Jumlah Salinan (Pages)</label>
            <div class="pd-swap">
                <select id="pd-copies" onchange="pdCustomToggle('pd-copies','pd-copies-custom')">
                    <option value="1" selected>1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                    <option value="custom">Custom&hellip;</option>
                </select>
                <span id="pd-copies-custom-wrap" style="display:none;position:relative;">
                    <input type="number" id="pd-copies-custom" class="pd-custom-input" min="1" max="200" value="10" oninput="applyPdfDesign()" placeholder="1&ndash;200">
                    <button type="button" class="pd-back" onclick="pdCustomBack('pd-copies','pd-copies-custom')" title="Kembali ke daftar pilihan">&times;</button>
                </span>
            </div>
        </div>

        <div class="pd-sec">Halaman &amp; Kertas</div>
        <div class="pd-group">
            <label for="pd-paper-size">Ukuran Kertas</label>
            <select id="pd-paper-size" onchange="applyPdfDesign()">
                <option value="a3">A3 (297 &times; 420 mm)</option>
                <option value="a4" selected>A4 (210 &times; 297 mm)</option>
                <option value="a5">A5 (148 &times; 210 mm)</option>
                <option value="b4">B4 (250 &times; 353 mm)</option>
                <option value="b5">B5 (176 &times; 250 mm)</option>
                <option value="f4">F4 / Folio (215 &times; 330 mm)</option>
                <option value="letter">Letter (216 &times; 279 mm)</option>
                <option value="legal">Legal (216 &times; 356 mm)</option>
                <option value="tabloid">Tabloid (279 &times; 432 mm)</option>
            </select>
        </div>

        <div class="pd-group">
            <label for="pd-orient">Orientasi</label>
            <select id="pd-orient" onchange="applyPdfDesign()">
                <option value="landscape" selected>Lanskap</option>
                <option value="portrait">Potret</option>
            </select>
        </div>

        <div class="pd-group">
            <label for="pd-margin">Margin Kertas</label>
            <select id="pd-margin" onchange="pdMarginChanged()">
                <option value="8">Sempit (8 mm)</option>
                <option value="12" selected>Normal (12 mm)</option>
                <option value="20">Lebar (20 mm)</option>
                <option value="custom">Custom&hellip;</option>
            </select>
            <div id="pd-margin-custom-box" style="display:none;">
                <button type="button" class="pd-btn pd-openmodal" onclick="pdModalToggle(true)">&#9881; Atur Margin &amp; Teks&hellip;</button>
                <div id="pd-margin-summary">Atas 12 &middot; Bawah 12 &middot; Kiri 12 &middot; Kanan 12 mm</div>
            </div>
        </div>

        <div class="pd-group">
            <label for="pd-pps">Pages per Sheet (hal. per lembar)</label>
            <select id="pd-pps" onchange="applyPdfDesign()">
                <option value="1" selected>1 normal</option>
                <option value="2">2 berdampingan</option>
                <option value="4">4 kuadran 2&times;2</option>
            </select>
        </div>

        <div class="pd-sec">Tampilan</div>
        <div class="pd-group">
            <label for="pd-font">Ukuran Font</label>
            <select id="pd-font" onchange="applyPdfDesign()">
                <option value="7">Sangat kecil</option>
                <option value="8">Kecil</option>
                <option value="9.5" selected>Sedang</option>
                <option value="11">Besar</option>
                <option value="13">Sangat besar</option>
            </select>
        </div>

        <div class="pd-group">
            <label for="pd-scale">Skala Isi (Zoom Cetak)</label>
            <div class="pd-swap">
                <select id="pd-scale" onchange="pdCustomToggle('pd-scale','pd-scale-custom')">
                    <option value="50">50%</option>
                    <option value="75">75%</option>
                    <option value="85">85%</option>
                    <option value="100" selected>100%</option>
                    <option value="125">125%</option>
                    <option value="150">150%</option>
                    <option value="custom">Custom&hellip;</option>
                </select>
                <span id="pd-scale-custom-wrap" style="display:none;position:relative;">
                    <input type="number" id="pd-scale-custom" class="pd-custom-input" min="25" max="400" value="100" oninput="applyPdfDesign()" placeholder="25&ndash;400">
                    <button type="button" class="pd-back" onclick="pdCustomBack('pd-scale','pd-scale-custom')" title="Kembali ke daftar pilihan">&times;</button>
                </span>
            </div>
        </div>

        <div class="pd-group">
            <label for="pd-theme">Tema Warna</label>
            <select id="pd-theme" onchange="applyPdfDesign()">
                <option value="merah" selected>Merah</option>
                <option value="biru">Biru</option>
                <option value="hijau">Hijau</option>
                <option value="abu">Abu-abu</option>
                <option value="ungu">Ungu</option>
                <option value="oranye">Oranye</option>
                <option value="teal">Teal / Tosca</option>
                <option value="coklat">Coklat</option>
                <option value="hitam">Hitam-Putih</option>
            </select>
        </div>

        <div class="pd-group">
            <label>Gaya Tampilan</label>
            <label class="pd-check"><input type="checkbox" id="pd-zebra" checked onchange="applyPdfDesign()"> Baris zebra (selang-seling)</label>
            <label class="pd-check" style="cursor:default;color:#64748b;"><input type="checkbox" checked disabled> Anti-overflow aktif: teks selalu muat di kertas</label>
        </div>

        <div class="pd-sec">Kolom yang Dicetak</div>
        <div class="pd-group">
            <label>Tampilkan Kolom</label>
            <div id="pd-cols"></div>
        </div>

        <div class="pd-actions">
            <button class="pd-btn pd-btn-print" onclick="window.print()">&darr; Unduh PDF</button>
        </div>
    </aside>

    <!-- Pop-up: atur margin per sisi & pengaturan teks -->
    <div class="pd-modal-bg" id="pd-modal-bg" onclick="pdModalClose(event)">
        <div class="pd-modal" role="dialog" aria-modal="true" onclick="event.stopPropagation()">
            <header><b>&#9881; Margin &amp; Pengaturan Teks</b><button type="button" onclick="pdModalToggle(false)" title="Tutup">&times;</button></header>
            <div class="pd-modal-body">
                <div>
                    <div class="pd-modal-sec">Margin Kertas (mm, per sisi)</div>
                    <div class="pd-grid4" style="margin-top:8px;">
                        <label>Atas<input type="number" id="pd-m-top" min="0" max="60" step="0.5" value="12" oninput="this.dataset.touched=1;applyPdfDesign()"></label>
                        <label>Bawah<input type="number" id="pd-m-bottom" min="0" max="60" step="0.5" value="12" oninput="this.dataset.touched=1;applyPdfDesign()"></label>
                        <label>Kiri<input type="number" id="pd-m-left" min="0" max="60" step="0.5" value="12" oninput="this.dataset.touched=1;applyPdfDesign()"></label>
                        <label>Kanan<input type="number" id="pd-m-right" min="0" max="60" step="0.5" value="12" oninput="this.dataset.touched=1;applyPdfDesign()"></label>
                    </div>
                </div>
                <div>
                    <div class="pd-modal-sec">Perataan</div>
                    <div class="pd-grid2" style="margin-top:8px;">
                        <label>Rata teks (horizontal)
                            <select id="pd-align" onchange="applyPdfDesign()">
                                <option value="left" selected>Rata kiri</option>
                                <option value="center">Rata tengah</option>
                                <option value="right">Rata kanan</option>
                                <option value="justify">Rata kiri-kanan (justify)</option>
                            </select>
                        </label>
                        <label>Rata isi halaman (vertikal)
                            <select id="pd-valign" onchange="applyPdfDesign()">
                                <option value="top" selected>Ke atas</option>
                                <option value="middle">Di tengah</option>
                                <option value="bottom">Ke bawah</option>
                            </select>
                        </label>
                    </div>
                </div>
                <div>
                    <div class="pd-modal-sec">Spasi &amp; Jarak Teks</div>
                    <div class="pd-grid2" style="margin-top:8px;">
                        <label>Spasi baris (1 = rapat)
                            <input type="number" id="pd-lineheight" min="1" max="3" step="0.05" value="1.4" oninput="applyPdfDesign()">
                        </label>
                        <label>Spasi huruf (px)
                            <input type="number" id="pd-letterspacing" min="-1" max="5" step="0.1" value="0" oninput="applyPdfDesign()">
                        </label>
                    </div>
                </div>
                <div style="font-size:.72rem;color:#64748b;line-height:1.5;">Semua perubahan langsung terlihat di preview di belakang pop-up ini. Klik area gelap atau tombol &times; untuk menutup.</div>
            </div>
            <footer>
                <button type="button" class="pd-btn" style="width:auto;background:#e2e8f0;color:#334155;" onclick="pdTextReset()">Reset</button>
                <button type="button" class="pd-btn pd-btn-print" style="width:auto;" onclick="pdModalToggle(false)">Terapkan</button>
            </footer>
        </div>
    </div>

    <main class="pd-preview">
        <div id="pd-pages-info">&plusmn; menghitung&hellip;</div>
        <div id="pd-scale-info">Zoom preview: 100%</div>
        <div id="pd-paper-scale">
            <div id="pd-paper"><!-- Lembar laporan dirender oleh JS --></div>
        </div>
    </main>
</div>
<script>
// ═══ PDF DESIGNER (laporan) — logic ═══
var PD_PAPERS = {
    a3:     { w:297,   h:420 },
    a4:     { w:210,   h:297 },
    a5:     { w:148,   h:210 },
    b4:     { w:250,   h:353 },
    b5:     { w:176,   h:250 },
    f4:     { w:215,   h:330 },
    letter: { w:215.9, h:279.4 },
    legal:  { w:215.9, h:355.6 },
    tabloid:{ w:279.4, h:431.8 }
};
var PD_GRIDS  = { 1:[1,1], 2:[2,1], 4:[2,2] }; // [kolom, baris] frame halaman per lembar
var PD_THEMES = {
    merah:  { head:'#e01a2b', zebra:'#fdecec', border:'#e5e7eb' },
    biru:   { head:'#0284c7', zebra:'#eff6ff', border:'#e5e7eb' },
    hijau:  { head:'#15803d', zebra:'#f0fdf4', border:'#e5e7eb' },
    abu:    { head:'#4b5563', zebra:'#f3f4f6', border:'#e5e7eb' },
    ungu:   { head:'#7c3aed', zebra:'#f5f3ff', border:'#e5e7eb' },
    oranye: { head:'#ea580c', zebra:'#fff7ed', border:'#e5e7eb' },
    teal:   { head:'#0f766e', zebra:'#f0fdfa', border:'#e5e7eb' },
    coklat: { head:'#92400e', zebra:'#fdf8ef', border:'#e5e7eb' },
    hitam:  { head:'#1f2937', zebra:'#f3f4f6', border:'#e5e7eb' }
};
var PD_COLS = <?= json_encode($pdCols, $pdJsonFlags) ?>;
var PD_ROWS = <?= json_encode($rows, $pdJsonFlags) ?>;
var PD_META = <?= json_encode([
    'tipe'    => $typeLabel,
    'periode' => tglHari($start_date) . ' s/d ' . tglHari($end_date),
    'total'   => count($data),
    'oleh'    => ($user['name'] ?? '-'),
    'uname'   => ($user_role !== '' ? $user_role : ($user['name'] ?? 'user')),
    'filters' => $activeFilters,
    'logo'    => ($logoBase64 ?: '')
], $pdJsonFlags) ?>;
var _pdPaperW = 297 * 96 / 25.4;
var _pdNow = (function(){
    var n = new Date();
    return ('0'+n.getDate()).slice(-2)+'/'+('0'+(n.getMonth()+1)).slice(-2)+'/'+n.getFullYear()+
           ' '+('0'+n.getHours()).slice(-2)+':'+('0'+n.getMinutes()).slice(-2);
})();
var _pdDate = (function(){
    var n = new Date();
    return ('0'+n.getDate()).slice(-2)+'-'+('0'+(n.getMonth()+1)).slice(-2)+'-'+n.getFullYear();
})();

function pdEl(id){ return document.getElementById(id); }
function escHtml(str){
    return String(str).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function buildPdfCols(){
    var wrap = pdEl('pd-cols');
    wrap.innerHTML = PD_COLS.map(function(c, i){
        return '<label class="pd-check"><input type="checkbox" class="pd-col" data-idx="'+i+'" checked> '+escHtml(c.label)+'</label>';
    }).join('');
    wrap.querySelectorAll('input.pd-col').forEach(function(inp){
        inp.addEventListener('change', applyPdfDesign);
    });
}

function pdCustomToggle(selId, inpId){
    var sel  = pdEl(selId);
    var inp  = pdEl(inpId);
    var wrap = pdEl(inpId + '-wrap');
    if (sel.value === 'custom') {
        if (!sel.dataset.last) sel.dataset.last = sel.options[0] ? sel.options[0].value : '1';
        sel.style.display  = 'none';   // dropdown digantikan input di posisi yang sama
        wrap.style.display = 'block';
        inp.focus();
        inp.select();
    } else {
        sel.dataset.last   = sel.value;
        sel.style.display  = '';
        wrap.style.display = 'none';
    }
    applyPdfDesign();
}

function pdCustomBack(selId, inpId){
    var sel  = pdEl(selId);
    var wrap = pdEl(inpId + '-wrap');
    sel.style.display  = '';
    wrap.style.display = 'none';
    if (sel.value === 'custom') sel.value = sel.dataset.last || '1';
    applyPdfDesign();
}

function pdModalToggle(open){
    pdEl('pd-modal-bg').classList.toggle('open', !!open);
}

function pdModalClose(e){
    if (e.target === pdEl('pd-modal-bg')) pdModalToggle(false);
}

function pdMarginChanged(){
    var sel   = pdEl('pd-margin');
    var isCus = sel.value === 'custom';
    pdEl('pd-margin-custom-box').style.display = isCus ? '' : 'none';
    if (isCus) {
        // Prefill 4 sisi dengan nilai preset terakhir (jika belum diubah manual)
        ['top','bottom','left','right'].forEach(function(k){
            var inp = pdEl('pd-m-' + k);
            if (!inp.dataset.touched) inp.value = sel.dataset.lastPreset || '12';
        });
        pdModalToggle(true);
    } else {
        sel.dataset.lastPreset = sel.value;
    }
    applyPdfDesign();
}

function pdTextReset(){
    pdEl('pd-m-top').value = 12;
    pdEl('pd-m-bottom').value = 12;
    pdEl('pd-m-left').value = 12;
    pdEl('pd-m-right').value = 12;
    pdEl('pd-align').value = 'left';
    pdEl('pd-valign').value = 'top';
    pdEl('pd-lineheight').value = 1.4;
    pdEl('pd-letterspacing').value = 0;
    ['top','bottom','left','right'].forEach(function(k){ pdEl('pd-m-' + k).dataset.touched = ''; });
    applyPdfDesign();
}

function pdSettings(){
    var paper = PD_PAPERS[pdEl('pd-paper-size').value] || PD_PAPERS.a4;
    var land  = pdEl('pd-orient').value === 'landscape';
    var pps   = parseInt(pdEl('pd-pps').value, 10) || 1;
    var grid  = PD_GRIDS[pps] || PD_GRIDS[1];

    // Jumlah salinan: preset atau custom (1-200)
    var copiesSel = pdEl('pd-copies').value;
    var copies = (copiesSel === 'custom')
        ? (parseInt(pdEl('pd-copies-custom').value, 10) || 1)
        : (parseInt(copiesSel, 10) || 1);
    copies = Math.max(1, Math.min(200, copies));

    // Skala isi: preset atau custom (25-400%)
    var scaleSel = pdEl('pd-scale').value;
    var scale = (scaleSel === 'custom')
        ? (parseFloat(pdEl('pd-scale-custom').value) || 100)
        : (parseFloat(scaleSel) || 100);
    scale = Math.max(25, Math.min(400, scale));

    // Margin: preset seragam atau custom per sisi (dari pop-up)
    var mSel  = pdEl('pd-margin').value;
    var mBase = parseFloat(mSel) || 12;
    var num   = function(id, fb){ var v = parseFloat(pdEl(id).value); return isNaN(v) ? fb : v; };
    var mT = mBase, mB = mBase, mL = mBase, mR = mBase;
    if (mSel === 'custom') {
        mT = Math.max(0, Math.min(60, num('pd-m-top', 12)));
        mB = Math.max(0, Math.min(60, num('pd-m-bottom', 12)));
        mL = Math.max(0, Math.min(60, num('pd-m-left', 12)));
        mR = Math.max(0, Math.min(60, num('pd-m-right', 12)));
    }

    return {
        pw:     land ? paper.h : paper.w,
        ph:     land ? paper.w : paper.h,
        mT:     mT, mR: mR, mB: mB, mL: mL,
        fontPt: parseFloat(pdEl('pd-font').value) || 9.5,
        scale:  scale,
        pps:    pps,
        gcols:  grid[0],
        grows:  grid[1],
        copies: copies,
        align:  pdEl('pd-align').value || 'left',
        valign: pdEl('pd-valign').value || 'top',
        lineH:  Math.max(1, Math.min(3, parseFloat(pdEl('pd-lineheight').value) || 1.4)),
        ls:     Math.max(-1, Math.min(5, parseFloat(pdEl('pd-letterspacing').value) || 0)),
        theme:  PD_THEMES[pdEl('pd-theme').value] || PD_THEMES.merah,
        zebra:  pdEl('pd-zebra').checked,
        title:  pdEl('pd-title').value.trim() || 'Laporan'
    };
}

// ── Pembagian baris per halaman (chunk): halaman pertama & lanjutan beda tinggi kepala ──
function pdChunkRows(rows, first, rest){
    var chunks = [], i = 0;
    while (i < rows.length) {
        var take = Math.max(1, chunks.length ? rest : first);
        chunks.push({ start: i, rows: rows.slice(i, i + take) });
        i += take;
    }
    if (!chunks.length) chunks.push({ start: 0, rows: [] });
    return chunks;
}

// ── Kepala halaman: besar (halaman 1) & kecil/berjalan (halaman lanjutan) ──
function pdHeadBig(s, basePx){
    var h = PD_META.logo ? '<img class="pd-logo" src="' + PD_META.logo + '" alt="Logo SRA">' : '';
    h += '<div class="pd-doc-title" style="font-size:' + Math.round(s.fontPt * 1.6 * (s.scale / 100)) + 'pt;">' + escHtml(s.title) + '</div>';
    h += '<div class="pd-doc-meta" style="font-size:' + (basePx * 0.85).toFixed(1) + 'px;">Tipe: ' + escHtml(PD_META.tipe) +
         '  •  Periode: ' + escHtml(PD_META.periode) +
         '  •  Total: ' + PD_META.total + ' baris' +
         '  •  Generate: ' + escHtml(PD_META.oleh) +
         '  •  Dicetak: ' + _pdNow + '</div>';
    h += '<div class="pd-chips">' + (PD_META.filters || []).map(function(f){
        return '<span><b>' + escHtml(f.label) + ':</b> ' + escHtml(f.value) + '</span>';
    }).join('') + '</div>';
    return h;
}

function pdHeadRun(s, basePx, pageNo, total){
    return '<div class="pd-runhead" style="font-size:' + (basePx * 1.05).toFixed(1) + 'px;">' +
           '<span class="rh-t">' + escHtml(s.title) + '</span>' +
           '<span class="rh-p">Hal. ' + pageNo + '/' + total + '</span></div>' +
           '<div class="pd-runmeta" style="font-size:' + (basePx * 0.8).toFixed(1) + 'px;">' +
           escHtml(PD_META.tipe) + '  •  ' + escHtml(PD_META.periode) + '</div>';
}

// ── Tabel satu frame (chunk baris) ──
function pdTableHtml(s, cols, chunk){
    var html = '<table class="pd-t pd-nowrap"><thead><tr>';
    cols.forEach(function(c){
        html += '<th style="background:' + s.theme.head + (c.num ? ';text-align:right;' : '') + ';">' + escHtml(c.label) + '</th>';
    });
    html += '</tr></thead><tbody>';
    chunk.rows.forEach(function(r, i){
        var bg = (s.zebra && ((chunk.start + i) % 2) === 1) ? ' style="background:' + s.theme.zebra + ';"' : '';
        html += '<tr' + bg + '>';
        cols.forEach(function(c){
            var v = r[c.idx];
            if (v === undefined || v === null || v === '') v = '-';
            html += '<td' + (c.num ? ' class="num"' : '') + '>' + escHtml(v) + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody></table>';
    return html;
}

// ── Render semua lembar: salinan → lembar (sheet) → frame (halaman) → tabel ──
function pdRender(s, chunks, cols, basePx){
    var px = 96 / 25.4;
    var W  = s.pw * px, H = s.ph * px;
    _pdPaperW = W;
    var z   = Math.pow(1 / s.pps, 0.5);                                  // zoom isi: 1, 0.707, 0.5
    var gap = s.pps > 1 ? 8 : 0;
    var fw  = (W - (s.mL + s.mR) * px - gap * (s.gcols - 1)) / s.gcols;  // frame = halaman kecil
    var fh  = (H - (s.mT + s.mB) * px - gap * (s.grows - 1)) / s.grows;
    var innerW = fw / z, innerH = fh / z;                                // ruang layout sebelum zoom
    var vAlign = s.valign === 'middle' ? 'center' : (s.valign === 'bottom' ? 'flex-end' : 'flex-start');

    var padX  = Math.max(2, Math.round(8 * s.scale / 100));
    var padY  = Math.max(2, Math.round(5 * s.scale / 100));
    var padTX = Math.max(2, Math.round(8 * s.scale / 100));
    var padTY = Math.max(2, Math.round(6 * s.scale / 100));

    pdEl('pd-page-style').textContent =
        '@page { size: ' + s.pw + 'mm ' + s.ph + 'mm; margin: ' + s.mT + 'mm ' + s.mR + 'mm ' + s.mB + 'mm ' + s.mL + 'mm; }';
    // Anti-overflow: sel selalu boleh membungkus kata agar tidak pernah keluar kertas
    pdEl('pd-table-style').textContent =
        '.pd-t td, .pd-t th { overflow-wrap:break-word; word-break:break-word; }' +
        '.pd-t td { padding:' + padY + 'px ' + padX + 'px; border-bottom:1px solid ' + s.theme.border + '; }' +
        '.pd-t th { padding:' + padTY + 'px ' + padTX + 'px; }';

    var bigHead = pdHeadBig(s, basePx);
    var total   = chunks.length;
    var html    = '';
    for (var c = 0; c < s.copies; c++) {
        for (var sh = 0; sh < total; sh += s.pps) {
            html += '<div class="pd-sheet" style="width:' + W + 'px;min-height:' + H + 'px;padding:' +
                    (s.mT * px) + 'px ' + (s.mR * px) + 'px ' + (s.mB * px) + 'px ' + (s.mL * px) + 'px;">';
            html += '<div class="pd-frames' + (s.pps > 1 ? ' pd-multi' : '') +
                    '" style="display:grid;grid-template-columns:repeat(' + s.gcols + ',1fr);grid-template-rows:repeat(' + s.grows + ',1fr);gap:' + gap + 'px;' +
                    (s.pps > 1 ? 'height:' + (H - (s.mT + s.mB) * px) + 'px;' : '') + '">';
            for (var f = sh; f < Math.min(sh + s.pps, total); f++) {
                var frameSt = (s.pps > 1)
                    ? 'width:' + fw + 'px;height:' + fh + 'px;overflow:hidden;'
                    : 'min-height:' + fh + 'px;';
                if (s.valign !== 'top') frameSt += 'display:flex;align-items:' + vAlign + ';';
                var innerSt = 'zoom:' + z + ';width:' + innerW + 'px;' +
                    (s.pps > 1 ? 'height:' + innerH + 'px;overflow:hidden;' : 'min-height:' + fh + 'px;') +
                    'text-align:' + s.align + ';line-height:' + s.lineH + ';letter-spacing:' + s.ls + 'px;';
                html += '<div class="pd-frame" style="' + frameSt + '">' +
                        '<div class="pd-frame-inner" style="' + innerSt + '">' +
                        (f === 0 ? bigHead : pdHeadRun(s, basePx, f + 1, total)) +
                        pdTableHtml(s, cols, chunks[f]) +
                        '</div></div>';
            }
            html += '</div></div>';
        }
    }
    var paper = pdEl('pd-paper');
    paper.innerHTML = html;
    paper.querySelectorAll('table.pd-t').forEach(function(t){
        t.style.fontSize = basePx + 'px';
    });
    return { innerH: innerH, innerW: innerW };
}

// ── Anti-overflow lebar (selalu aktif): kecilkan font sampai SEMUA tabel muat di lebarnya ──
function pdFitTables(maxPx){
    var tables  = document.querySelectorAll('#pd-paper table.pd-t');
    var f = maxPx, guard = 0;
    var worstOf = function(){
        var w = 0;
        tables.forEach(function(t){
            var d = t.scrollWidth - t.clientWidth;
            if (d > w) w = d;
        });
        return w;
    };
    while (worstOf() > 1 && f > 5 && guard < 80) {
        f -= 0.5;
        tables.forEach(function(t){ t.style.fontSize = f + 'px'; });
        guard++;
    }
    var wrapped = worstOf() > 1; // darurat terakhir: lepaskan nowrap agar teks membungkus di dalam kertas
    tables.forEach(function(t){ t.classList.toggle('pd-nowrap', !wrapped); });
    return { fontPx: f, wrapped: wrapped };
}

function applyPdfDesign(){
    var s     = pdSettings();
    var idxOn = {};
    document.querySelectorAll('#pd-cols input.pd-col').forEach(function(inp){
        if (inp.checked) idxOn[inp.getAttribute('data-idx')] = true;
    });
    var cols = PD_COLS.filter(function(c, i){ return idxOn[i]; });
    if (!cols.length) cols = PD_COLS.slice();

    var px     = 96 / 25.4;
    var basePx = s.fontPt * 96 / 72 * (s.scale / 100);

    // Nama file saat "Save as PDF" = judul dokumen + tanggal + role pengunduh
    document.title = s.title + ' - ' + _pdDate + ' - ' + (PD_META.uname || 'user');

    // Perkiraan awal tinggi elemen (px) untuk membagi baris per halaman
    var rowH    = basePx * Math.max(1.2, s.lineH) + 2 * Math.max(2, Math.round(5 * s.scale / 100)) + 1;
    var theadH  = basePx * 1.5  + 2 * Math.max(2, Math.round(6 * s.scale / 100)) + 2;
    var headBig = PD_META.logo ? 150 : 105;
    var headRun = 42;
    var z       = Math.pow(1 / s.pps, 0.5);
    var gap     = s.pps > 1 ? 8 : 0;
    var fhIn    = (s.ph * px - (s.mT + s.mB) * px - gap * (s.grows - 1)) / s.grows / z;

    var estF   = Math.max(1, Math.floor((fhIn - headBig - theadH) / rowH * 0.95));
    var estR   = Math.max(1, Math.floor((fhIn - headRun - theadH) / rowH * 0.95));
    var chunks = pdChunkRows(PD_ROWS, estF, estR);
    var geo    = pdRender(s, chunks, cols, basePx);
    var fit    = pdFitTables(basePx);

    // Koreksi otomatis: kalau ada frame yang isinya melebihi tinggi halamannya,
    // kurangi baris per halaman lalu render ulang (maks 3 iterasi) — isi tidak terpotong.
    var tries = 0, worst = 0;
    while (tries < 3) {
        worst = 0;
        document.querySelectorAll('#pd-paper .pd-frame-inner').forEach(function(el){
            if (el.scrollHeight > worst) worst = el.scrollHeight;
        });
        if (worst <= geo.innerH + 1) break;
        var ratio = (geo.innerH / worst) * 0.92;
        estF   = Math.max(1, Math.floor(estF * ratio));
        estR   = Math.max(1, Math.floor(estR * ratio));
        chunks = pdChunkRows(PD_ROWS, estF, estR);
        geo    = pdRender(s, chunks, cols, fit.fontPx);
        fit    = pdFitTables(fit.fontPx);
        tries++;
    }

    // Info jumlah halaman di preview (+ peringatan bila masih ada isi terpotong)
    var pages  = chunks.length * s.copies;
    var sheets = Math.ceil(chunks.length / s.pps) * s.copies;
    pdEl('pd-pages-info').innerHTML =
        '&plusmn; ' + pages + ' halaman &bull; ' + sheets + ' lembar' +
        (s.pps > 1 ? ' (' + s.pps + ' hal/lembar)' : '') +
        (worst > geo.innerH + 1
            ? '<br><span style="color:#b91c1c;">&#9888; Ada isi terpotong &mdash; kecilkan Skala/Font atau perbesar kertas</span>'
            : '');
    if (pdEl('pd-margin').value === 'custom') {
        var alignLbl = ({ left:'kiri', center:'tengah', right:'kanan', justify:'kiri-kanan' })[s.align] || s.align;
        pdEl('pd-margin-summary').textContent =
            'Atas ' + s.mT + ' · Bawah ' + s.mB + ' · Kiri ' + s.mL + ' · Kanan ' + s.mR + ' mm' +
            ' · rata ' + alignLbl + ' · spasi baris ' + s.lineH + ' · spasi huruf ' + s.ls + 'px';
    }
    pdScalePreview();
}

function pdScalePreview(){
    var pane  = document.querySelector('.pd-preview');
    var avail = pane.clientWidth - 48;
    var s     = Math.min(1, avail / _pdPaperW);
    pdEl('pd-paper-scale').style.zoom = s;
    pdEl('pd-scale-info').textContent =
        'Zoom preview: ' + Math.round(s * 100) + '%';
}

buildPdfCols();
applyPdfDesign();
window.addEventListener('resize', pdScalePreview);
</script>
</body>
</html>
    <?php
        exit;
    }

    if ($isDownload) {
        header('Content-Type: text/html; charset=utf-8');
        $namaLaporan = ['assets' => 'Laporan Data Aset', 'requests' => 'Laporan Mutasi', 'approvals' => 'Laporan Anomali'][$report_type] ?? 'Laporan';
        header('Content-Disposition: attachment; filename="' . $namaLaporan . ' - ' . date('d-m-Y') . '.html"');
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan <?= htmlspecialchars($typeLabel) ?> - <?= date('d/m/Y') ?></title>
<link rel="icon" type="image/png" sizes="64x64" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']),'/') . '/images/logo-sra.png' ?>">
<link rel="shortcut icon" type="image/png" href="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']),'/') . '/images/logo-sra.png' ?>">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; font-size: 11px; color: #111; background: #fff; }

    /* ── Screen preview wrapper ── */
    .preview-wrapper {
        max-width: 1100px;
        margin: 0 auto;
        padding: 24px;
    }

    /* ── Print button bar (hanya tampil di layar, hilang saat print) ── */
    .print-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #0b5ea8;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    .print-bar span { font-size: 13px; font-weight: 600; }
    .print-bar button {
        background: white;
        color: #0b5ea8;
        border: none;
        padding: 8px 20px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .print-bar button:hover { background: #e8f0fb; }

    /* ── Document ── */
    .doc {
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 32px 36px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    .doc-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 3px solid #0b5ea8;
        padding-bottom: 14px;
        margin-bottom: 16px;
    }
    .doc-header-left h1 { font-size: 16px; color: #0b5ea8; font-weight: 700; }
    .doc-header-left p  { font-size: 10px; color: #555; margin-top: 3px; }
    .doc-header-right   { text-align: right; font-size: 10px; color: #555; line-height: 1.6; }

    .doc-meta {
        display: flex;
        gap: 24px;
        background: #f0f6ff;
        border-radius: 6px;
        padding: 10px 14px;
        margin-bottom: 16px;
        font-size: 10px;
    }
    .doc-meta div { display: flex; flex-direction: column; gap: 2px; }
    .doc-meta label { font-weight: 700; color: #0b5ea8; font-size: 9px; text-transform: uppercase; }
    .doc-meta span  { color: #222; }

    table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    thead tr { background: #0b5ea8; }
    thead th { color: white; padding: 7px 8px; text-align: left; font-size: 10px; font-weight: 700; }
    tbody tr:nth-child(even) td { background: #f5f8ff; }
    tbody tr:hover td { background: #e8f0fb; }
    tbody td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; color: #222; }

    /* Layar saja: preview bisa di-slide kiri-kanan (Shift+Scroll / swipe)
       jika tabel melebihi lebar layar — saat print tetap mengikuti A4 */
    @media screen {
        .doc { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .doc table { min-width: max-content; }
    }

    .doc-footer {
        margin-top: 20px;
        border-top: 1px solid #ddd;
        padding-top: 10px;
        display: flex;
        justify-content: space-between;
        font-size: 9px;
        color: #888;
    }

    /* ── Print styles ── */
    @page {
        size: A4;
        margin: 15mm 10mm;
    }

    /* ── Mobile / responsive styles ── */
    @media (max-width: 768px) {
        .preview-wrapper { padding: 12px; }
        .print-bar { flex-direction: column; align-items: flex-start; gap: 8px; padding: 10px 14px; }
        .print-bar span { font-size: 12px; }
        .print-bar > div { width: 100%; }
        .print-bar button { width: 100%; text-align: center; }
        .doc { padding: 16px; }
        .doc-header { flex-direction: column; gap: 8px; }
        .doc-header-right { text-align: left; }
        .doc-meta { flex-direction: column; gap: 8px; }
        table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; }
        tbody td, thead th { white-space: nowrap; }
    }

    @media print {
        body { background: white; margin: 0; }
        .preview-wrapper { padding: 0; max-width: 100%; }
        .print-bar { display: none !important; }
        .doc {
            border: none;
            box-shadow: none;
            border-radius: 0;
            padding: 0;
        }
        table { page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }

        /* Hilangkan URL/header/footer bawaan browser */
        @page { margin: 10mm; }
    }
</style>
</head>
<body>
<div class="preview-wrapper">

    <!-- Print bar (hilang saat print) -->
    <div class="print-bar">
        <span>Preview Laporan <?= htmlspecialchars($typeLabel) ?> — <?= number_format(count($data)) ?> data</span>
        <div style="display:flex;gap:8px;">
            <button onclick="window.print()" style="background:#dc2626;color:white;border:none;padding:8px 16px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;">↓ Unduh PDF</button>
        </div>
    </div>

    <script>
    // Auto-trigger print dialog saat halaman dibuka (untuk langsung save as PDF)
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 800);
    });
    </script>

    <!-- Document -->
    <div class="doc">
        <div class="doc-header">
            <div class="doc-header-left">
                <?php if ($logoBase64): ?>
                <img src="<?= $logoBase64 ?>" alt="SRA Logo" style="height:52px; width:auto; margin-bottom:6px; display:block;">
                <?php endif; ?>
                <h1>Laporan <?= htmlspecialchars($typeLabel) ?></h1>
                <p>Sistem Relokasi Aset - PT Indomarco Prismatama</p>
                <p>General Affair - Cab Parung</p>
            </div>
        </div>

        <div class="doc-meta">
            <div><label>Tipe Laporan</label><span><?= htmlspecialchars($typeLabel) ?></span></div>
            <div><label>Periode</label><span><?= tglHari($start_date) ?> s/d <?= tglHari($end_date) ?></span></div>
            <div><label>Total Baris</label><span><?= number_format(count($data)) ?></span></div>
            <div><label>Generate Oleh</label><span><?= htmlspecialchars($user['name'] ?? '-') ?></span></div>
        </div>

        <?php if (!empty($activeFilters)): ?>
        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;">
            <?php foreach ($activeFilters as $f): ?>
            <span style="display:inline-flex; align-items:center; gap:4px; background:#f0f6ff; border:1px solid #c7d9f5; border-radius:20px; padding:3px 10px; font-size:9px; color:#1e40af;">
                <span style="font-weight:700; color:#0b5ea8;"><?= htmlspecialchars($f['label']) ?>:</span>
                <span><?= htmlspecialchars($f['value']) ?></span>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr><?php foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>'; ?></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <?php foreach ($row as $cell) echo '<td>' . htmlspecialchars((string)$cell) . '</td>'; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="doc-footer">
            <span>Sistem Relokasi Aset - PT Indomarco Prismatama General Affair - Cab Parung &copy; <?= date('Y') ?></span>
            <span>Dicetak: <?= tglHariNow() ?></span>
        </div>
    </div>

</div>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - SRA Indomaret Parung</title>
    <?php echo asset_css('css/layout-simple.css'); ?>
    <?php echo asset_css('css/indomaret-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/layout-override.css'); ?>
    <?php echo_loading_css(); ?>
    <style>
        .report-filters {
            background: var(--color-card);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Baris 1: Tipe + Tanggal + Generate */
        .filter-row-1 {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-row-1 .filter-group { flex: 1; min-width: 160px; }
        .filter-row-1 .filter-group.grow { flex: 0 0 auto; }

        /* Baris 2: Filter aset (muncul/sembunyi) */
        .filter-row-2 {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px dashed var(--color-border);
        }

        .filter-row-2 .filter-group { flex: 1; min-width: 160px; }

        .filter-row-2-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #0b5ea8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-row-2-label::before {
            content: '';
            display: inline-block;
            width: 3px;
            height: 14px;
            background: #0b5ea8;
            border-radius: 2px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            color: var(--color-text);
            margin-bottom: 0.4rem;
            font-size: 0.82rem;
        }

        .filter-input {
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background: var(--color-bg);
            color: var(--color-text);
            font-size: 0.875rem;
            height: 38px;
        }

        .btn-generate {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 0 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            height: 38px;
            white-space: nowrap;
            font-size: 0.875rem;
        }

        .btn-generate:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }

        .report-table {
            background: var(--color-card);
            border: 1px solid var(--color-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .report-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-text);
        }

        .export-btn {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .export-btn-excel { background: linear-gradient(135deg, #16a34a, #15803d); color:white; text-decoration:none; padding:0.5rem 1rem; border-radius:8px; font-weight:600; font-size:0.9rem; }
        .export-btn-txt   { background: linear-gradient(135deg, #6b7280, #4b5563); color:white; text-decoration:none; padding:0.5rem 1rem; border-radius:8px; font-weight:600; font-size:0.9rem; }
        .export-btn-pdf   { background: linear-gradient(135deg, #ef4444, #dc2626); color:white; text-decoration:none; padding:0.5rem 1rem; border-radius:8px; font-weight:600; font-size:0.9rem; }
        .export-btn:hover, .export-btn-excel:hover, .export-btn-txt:hover, .export-btn-pdf:hover { opacity:0.88; transform:translateY(-1px); }
        .export-group { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

        /* Sortable header */
        .report-table table th {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
            transition: background 0.15s;
        }
        .report-table table th:hover { background: rgba(11,94,168,0.08); }
    </style>
</head>
<body class="sidebar-hidden">
    <?php $page_title = 'Laporan'; $page_icon = ''; include __DIR__.'/app/header-sidebar.php'; ?>
    <div class="content-area">
        <div class="page-header">
            <h2 class="page-title">Laporan Sistem</h2>
            <p class="page-subtitle">Generate dan export laporan data</p>
        </div>

        <!-- Report Filters -->
        <div class="report-filters">
            <form method="GET" id="reportForm">
                <!-- Baris 1: Tipe + Tanggal + Generate -->
                <div class="filter-row-1">
                    <div class="filter-group">
                        <label class="filter-label">Tipe Laporan</label>
                        <select name="type" class="filter-input" id="reportType" onchange="this.form.submit()">
                            <option value="assets" <?= $report_type == 'assets' ? 'selected' : '' ?>>Data Aset</option>
                            <option value="requests" <?= $report_type == 'requests' ? 'selected' : '' ?>>Semua Mutasi</option>
                            <option value="requests_ra" <?= $report_type == 'requests_ra' ? 'selected' : '' ?>>Relokasi Aset</option>
                            <option value="requests_sa" <?= $report_type == 'requests_sa' ? 'selected' : '' ?>>Sewa Aset</option>
                            <option value="requests_ps" <?= $report_type == 'requests_ps' ? 'selected' : '' ?>>Pengembalian Sewa</option>
                            <option value="requests_pa" <?= $report_type == 'requests_pa' ? 'selected' : '' ?>>Pinjam Aset</option>
                            <option value="requests_pp" <?= $report_type == 'requests_pp' ? 'selected' : '' ?>>Pengembalian Pinjam</option>
                            <option value="approvals" <?= $report_type == 'approvals' ? 'selected' : '' ?>>Laporan Anomali</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Tanggal Mulai</label>
                        <input type="date" name="start_date" value="<?= $start_date ?>" class="filter-input">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Tanggal Akhir</label>
                        <input type="date" name="end_date" value="<?= $end_date ?>" class="filter-input">
                    </div>
                    <div class="filter-group grow" style="justify-content:flex-end;">
                        <button type="submit" class="btn-generate">Generate Laporan</button>
                    </div>
                    <div class="filter-group" id="sjSearchGroup" style="justify-content:flex-end;<?= $report_type_base === 'requests' ? '' : 'display:none;' ?>">
                        <label class="filter-label">Cari No. Surat Jalan</label>
                        <input type="text" name="filter_sj" id="sjSearch" class="filter-input" placeholder="Contoh: SJ-RA-GA-PRG-F4JJ-05-09-2026/000001 atau sebagian: F4JJ" value="<?= htmlspecialchars($filter_sj) ?>" autocomplete="off">
                    </div>
                </div>

                <!-- Baris 2: Filter tambahan khusus Data Aset -->
                <div id="assetFilters" style="display:<?= $report_type === 'assets' ? 'block' : 'none' ?>;">
                    <div class="filter-row-2">
                        <div class="filter-group">
                            <label class="filter-label">Cabang</label>
                            <select name="filter_cabang" class="filter-input" onchange="this.form.submit()">
                                <option value="">Semua Cabang</option>
                                <?php foreach ($opt_cabangs as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>" <?= $filter_cabang === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Toko</label>
                            <select name="filter_toko" class="filter-input" onchange="this.form.submit()">
                                <option value="">Semua Toko</option>
                                <?php foreach ($opt_tokos as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>" <?= $filter_toko === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Kategori</label>
                            <select name="filter_kategori" class="filter-input" onchange="this.form.submit()">
                                <option value="">Semua Kategori</option>
                                <?php foreach ($opt_kategoris as $k): ?>
                                    <option value="<?= htmlspecialchars($k) ?>" <?= $filter_kategori === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="filter_status" class="filter-input" onchange="this.form.submit()">
                                <option value="">Semua Status</option>
                                <?php foreach ($opt_statuses as $s): ?>
                                    <option value="<?= htmlspecialchars($s) ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group" style="flex:1.5;">
                            <label class="filter-label">Cari Keterangan</label>
                            <input type="text" name="filter_search" class="filter-input" placeholder="Contoh: microwave, T08..." value="<?= htmlspecialchars($filter_search) ?>">
                        </div>
                    </div>
                </div>

                <script>
                (function() {
                    var input = document.getElementById('sjSearch');
                    if (!input) return;
                    var timer = null, lastSubmitted = input.value;
                    // Auto cari 600ms setelah berhenti mengetik
                    input.addEventListener('input', function() {
                        clearTimeout(timer);
                        timer = setTimeout(function() {
                            if (input.value === lastSubmitted) return;
                            lastSubmitted = input.value;
                            input.form.submit();
                        }, 600);
                    });
                    // Enter langsung cari (tanpa menunggu debounce)
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            clearTimeout(timer);
                            if (input.value !== lastSubmitted) {
                                lastSubmitted = input.value;
                                input.form.submit();
                            }
                        }
                    });
                })();
                </script>
            </form>
        </div>

        <!-- Report Results -->
        <?php 
        $activeFilters = [];
        if ($filter_cabang)   $activeFilters[] = ['label' => 'Cabang',     'value' => $filter_cabang];
        if ($filter_toko)     $activeFilters[] = ['label' => 'Toko',       'value' => $filter_toko];
        if ($filter_kategori) $activeFilters[] = ['label' => 'Kategori',   'value' => $filter_kategori];
        if ($filter_status)   $activeFilters[] = ['label' => 'Status',     'value' => $filter_status];
        if ($filter_search)   $activeFilters[] = ['label' => 'Kata Kunci', 'value' => $filter_search];
        if ($filter_sj)       $activeFilters[] = ['label' => 'No. SJ',    'value' => $filter_sj];
        if ($sort_col) {
            $sortLabels = ['id'=>'ID','cabang'=>'Cabang','toko'=>'Toko','sub_code'=>'Sub Code','kategori'=>'Kategori','keterangan'=>'Keterangan','no_seri'=>'No Seri','kuantitas'=>'Kuantitas','biaya_perolehan'=>'Biaya Perolehan','masa_manfaat_bln'=>'Masa Manfaat','beban_penyusutan_bln'=>'Beban Penyusutan','umur_jalan_bln'=>'Umur Jalan','akumulasi_penyusutan'=>'Akumulasi Penyusutan','status'=>'Status','created_at'=>'Tanggal','updated_at'=>'Tanggal','asset_code'=>'Kode Aset','asset_name'=>'Nama Aset','from_loc'=>'Asal','to_loc'=>'Tujuan','requester_name'=>'Pemohon','approver_name'=>'Approver'];
            $activeFilters[] = ['label' => 'Urutan', 'value' => ($sortLabels[$sort_col] ?? $sort_col) . ' ' . ($sort_dir === 'ASC' ? '(A→Z)' : '(Z→A)')];
        }
        ?>
        <?php if (!empty($data)): ?>
        <div class="report-table">
            <div class="report-header">
                <h3 class="report-title"><?= $title ?> (<?= number_format(count($data)) ?> data)</h3>
                <div class="export-group">
                    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>" class="export-btn-excel">Export Excel</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'txt'])) ?>" class="export-btn-txt">Export TXT</a>
                    <button onclick="openPdfDesigner()" class="export-btn-pdf" style="border:none; cursor:pointer; background:linear-gradient(135deg,#0284c7,#0369a1);" title="Atur judul, kertas, orientasi, margin, font, warna &amp; kolom dengan preview sebelum unduh">&#128424; Atur &amp; Unduh PDF</button>
                </div>
            </div>
            <?php if ($isTruncated): ?>
            <div style="padding: 0 1.5rem 1rem; color: #b91c1c; font-size: 0.95rem;">
                Menampilkan <?= number_format($rowLimit) ?> data pertama. Silakan gunakan filter tanggal atau tipe laporan yang lebih sempit agar data tidak terlalu banyak dan halaman tidak lambat.
            </div>
            <?php endif; ?>

            <div class="table-scroll">
            <table>
                <thead>
                    <?php 
                    // Helper inline untuk th sortable
                    $th = function(string $col, string $label) {
                        $url = sortUrl($col);
                        $arrow = sortArrow($col);
                        return "<th style=\"cursor:pointer;user-select:none;white-space:nowrap;\" onclick=\"window.location='$url'\">$label$arrow</th>";
                    };
                    ?>
                    <?php if ($report_type == 'assets'): ?>
                    <tr>
                        <?= $th('id','ID') ?>
                        <?= $th('cabang','Cabang') ?>
                        <?= $th('toko','Toko') ?>
                        <?= $th('sub_code','Sub Code') ?>
                        <?= $th('kategori','Kategori') ?>
                        <?= $th('keterangan','Keterangan') ?>
                        <?= $th('no_seri','No Seri') ?>
                        <?= $th('kuantitas','Kuantitas') ?>
                        <?= $th('biaya_perolehan','Biaya Perolehan') ?>
                        <?= $th('masa_manfaat_bln','Masa Manfaat<br>(bln)') ?>
                        <?= $th('beban_penyusutan_bln','Beban Penyusutan<br>(bln)') ?>
                        <?= $th('umur_jalan_bln','Umur Jalan<br>(bln)') ?>
                        <?= $th('akumulasi_penyusutan','Akumulasi<br>Penyusutan') ?>
                        <?= $th('status','Status') ?>
                    </tr>
                    <?php elseif ($report_type_base == 'requests'): ?>
                    <tr>
                        <?= $th('id','ID') ?>
                        <?= $th('created_at','Tanggal') ?>
                        <th>No Seri</th>
                        <?= $th('asset_name','Nama Aset') ?>
                        <?= $th('from_loc','Asal') ?>
                        <?= $th('to_loc','Tujuan') ?>
                        <?= $th('requester_name','Pemohon') ?>
                        <?= $th('status','Status') ?>
                        <th>Diproses Oleh</th>
                        <th>Jenis SJ</th>
                        <th>No Surat Jalan</th>
                        <th>Via</th>
                    </tr>
                    <?php elseif ($report_type == 'approvals'): ?>
                    <tr>
                        <th>No</th>
                        <th>No Seri</th>
                        <?= $th('asset_name','Nama Aset') ?>
                        <th>Tipe Anomali</th>
                        <th>Pesan</th>
                        <th>ID Mutasi</th>
                        <th>Asal → Tujuan</th>
                        <th>Pemohon</th>
                        <th>Status Mutasi</th>
                        <?= $th('created_at','Tanggal') ?>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php $anomaly_no = 0; ?>
                    <?php foreach ($data as $row): ?>
                    <?php if ($report_type == 'assets'): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td><?= htmlspecialchars($row['cabang'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['toko'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['sub_code'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['kategori'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['keterangan'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['no_seri'] ?? '-') ?></td>
                        <td><?= (int)($row['kuantitas'] ?? 1) ?></td>
                        <td>Rp<?= number_format($row['biaya_perolehan'] ?? 0) ?></td>
                        <td><?= (int)($row['masa_manfaat_bln'] ?? 0) ?></td>
                        <td>Rp<?= number_format($row['beban_penyusutan_bln'] ?? 0) ?></td>
                        <td><?= (int)($row['umur_jalan_bln'] ?? 0) ?></td>
                        <td>Rp<?= number_format($row['akumulasi_penyusutan'] ?? 0) ?></td>
                        <td><?= htmlspecialchars($row['status'] ?? 'Active') ?></td>
                    </tr>
                    <?php elseif ($report_type_base == 'requests'): ?>
                    <tr>
                        <td>#<?= $row['id'] ?></td>
                        <td><?= tglHari($row['created_at'], true) ?></td>
                        <td><?= !empty($row['real_no_seri']) ? htmlspecialchars($row['real_no_seri']) : '-' ?></td>
                        <td><?= htmlspecialchars($row['asset_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['from_loc'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['to_loc'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['requester_name'] ?? '-') ?></td>
                        <td><span class="status-badge status-<?= strtolower($row['status']) ?>"><?= ucfirst(strtolower($row['status'])) ?></span></td>
                        <td><?= !empty($row['approver_name']) ? htmlspecialchars($row['approver_name']) : '-' ?><?php if (!empty($row['approver_role'])): ?> <small>(<?= ucfirst($row['approver_role']) ?>)</small><?php endif; ?></td>
                        <td><?php
                            $jCode = $row['jenis_sj'] ?? 'RA';
                            $jLabel = jenis_sj_label($jCode);
                            $jColors = ['RA'=>['#dbeafe','#1d4ed8'],'SA'=>['#d1fae5','#065f46'],'PS'=>['#fef3c7','#92400e'],'PA'=>['#ede9fe','#5b21b6'],'PP'=>['#fce7f3','#9d174d']];
                            $jC = $jColors[$jCode] ?? $jColors['RA'];
                            echo "<span style=\"background:{$jC[0]};color:{$jC[1]};padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;white-space:nowrap;\">{$jLabel}</span>";
                        ?></td>
                        <td><?= !empty($row['reason']) ? htmlspecialchars($row['reason']) : '-' ?></td>
                        <td><?php
                            $via = '-';
                            if ($row['status'] !== 'PENDING') {
                                $via = 'Web';
                                try { $lStmt = $conn->prepare("SELECT note FROM approvals_log WHERE relocation_id=? AND action IN('APPROVE','REJECT') ORDER BY created_at DESC LIMIT 1"); $lStmt->execute([$row['id']]); $n=$lStmt->fetchColumn(); if($n && stripos($n,'telegram')!==false) $via='Telegram'; } catch(Exception $e){}
                            }
                            echo $via;
                        ?></td>
                    </tr>
                    <?php elseif ($report_type == 'approvals'): ?>
                    <tr>
                        <td><?= ++$anomaly_no ?></td>
                        <td><?= htmlspecialchars($row['real_no_seri'] ?? $row['asset_code'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['asset_name'] ?? '-') ?></td>
                        <td><span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:4px;font-size:.78rem;font-weight:600;"><?= htmlspecialchars($row['alert_type'] ?? '-') ?></span></td>
                        <td><?= htmlspecialchars($row['message'] ?? '-') ?></td>
                        <td><?= $row['relocation_id'] ? '#' . $row['relocation_id'] : '-' ?></td>
                        <td><?= htmlspecialchars($row['from_loc'] ?? '-') ?> → <?= htmlspecialchars($row['to_loc'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['requester_name'] ?? '-') ?></td>
                        <td><?php if (!empty($row['relocation_status'])): ?><span class="status-badge status-<?= strtolower($row['relocation_status']) ?>"><?= ucfirst(strtolower($row['relocation_status'])) ?></span><?php else: ?>-<?php endif; ?></td>
                        <td><?= tglHari($row['created_at'], true) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /.table-scroll -->
        </div>
        <?php else: ?>
        <div class="report-table">
            <div class="report-header">
                <h3 class="report-title">Tidak ada data untuk ditampilkan</h3>
            </div>
            <div style="padding: 2rem; text-align: center; color: var(--color-text-secondary);">
                Silakan ubah filter atau pilih tipe laporan lain.
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php echo asset_js('js/app-ui.js'); ?>
    <?php echo_loading_js(); ?>
    <script>
    function toggleAssetFilters() {
        const type = document.getElementById('reportType').value;
        const filters = document.getElementById('assetFilters');
        filters.style.display = type === 'assets' ? 'block' : 'none';
    }

    function openPdfDesigner() {
        // Halaman PDF Designer di tab yang sama: atur layout dulu (judul, kertas, margin, font, warna, kolom), baru unduh
        var url = '?<?= http_build_query(array_merge(array_diff_key($_GET, ['export'=>'']), ['export'=>'pdf_designer'])) ?>';
        window.location.href = url;
    }
    </script>
</body>
</html>