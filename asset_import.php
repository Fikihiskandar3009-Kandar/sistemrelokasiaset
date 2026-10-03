<?php
/**
 * ==== IMPORT ASET MASSAL ====
 * Upload Excel/CSV/TXT -> preview -> simpan (mode merge cerdas, acuan kembar: kategori+keterangan+no seri)
 * Akses: admin | Terkait: api/asset_import_preview.php, api/asset_import_save.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/rbac.php';
auth_require();
if (!user_can('asset.create')) { header('Location: dashboard.php'); exit; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import Aset - SRA Indomaret Parung</title>
<?php echo asset_css('css/layout-simple.css'); ?>
<?php echo asset_css('css/indomaret-theme.css'); ?>
<?php echo asset_css('css/dark-mode.css'); ?>
<?php echo asset_css('css/layout-override.css'); ?>
<?php echo_loading_css(); ?>
<?php echo asset_js('js/app-ui.js', 'defer'); ?>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',sans-serif; background:#f5f7fa; }
.container { max-width:1100px; margin:0 auto; padding:1.5rem; }
.page-title { font-size:1.6rem; font-weight:700; color:var(--color-text); margin-bottom:.3rem; }
.page-subtitle { color:var(--color-text-secondary); font-size:.9rem; margin-bottom:1.5rem; }

/* Steps */
.steps { display:flex; gap:0; margin-bottom:2rem; }
.step { flex:1; display:flex; align-items:center; gap:.6rem; padding:.75rem 1rem;
        background:var(--color-card); border:1px solid var(--color-border);
        font-size:.85rem; color:var(--color-text-secondary); position:relative; }
.step:not(:last-child)::after { content:'›'; position:absolute; right:-10px;
        font-size:1.4rem; color:var(--color-border); z-index:1; }
.step.active { background:rgba(14,165,233,.08); border-color:var(--color-primary);
               color:var(--color-primary); font-weight:600; }
.step.done { background:rgba(34,197,94,.08); border-color:#22c55e; color:#16a34a; }
.step-num { width:24px; height:24px; border-radius:50%; background:currentColor;
            color:#fff; display:flex; align-items:center; justify-content:center;
            font-size:.75rem; font-weight:700; flex-shrink:0; }
.step.active .step-num { background:var(--color-primary); }
.step.done .step-num { background:#22c55e; }

/* Cards */
.card { background:var(--color-card); border:1px solid var(--color-border);
        border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;
        box-shadow:0 2px 8px rgba(0,0,0,.06); }
.card-title { font-size:1rem; font-weight:700; color:var(--color-text);
              margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }

/* Guide table */
.guide-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.guide-table th { background:var(--color-primary); color:#fff; padding:8px 12px;
                  text-align:left; font-weight:600; }
.guide-table td { padding:8px 12px; border-bottom:1px solid var(--color-border); }
.guide-table tr:last-child td { border-bottom:none; }
.guide-table tr:nth-child(even) td { background:rgba(14,165,233,.04); }
.badge-req { background:#fee2e2; color:#dc2626; padding:2px 8px; border-radius:10px;
             font-size:.75rem; font-weight:600; }
.badge-opt { background:#f0fdf4; color:#16a34a; padding:2px 8px; border-radius:10px;
             font-size:.75rem; font-weight:600; }

/* Upload zone */
.upload-zone { border:2px dashed var(--color-border); border-radius:12px;
               padding:3rem 2rem; text-align:center; cursor:pointer;
               transition:all .3s; position:relative; }
.upload-zone:hover, .upload-zone.dragover { border-color:var(--color-primary);
               background:rgba(14,165,233,.04); }
.upload-icon { font-size:3rem; margin-bottom:1rem; }
.upload-title { font-size:1.1rem; font-weight:600; color:var(--color-text); margin-bottom:.4rem; }
.upload-sub { font-size:.85rem; color:var(--color-text-secondary); }
.upload-input { position:absolute; inset:0; opacity:0; cursor:pointer; }
.upload-formats { display:flex; gap:.5rem; justify-content:center; margin-top:1rem; flex-wrap:wrap; }
.fmt-badge { padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:600; }
.fmt-csv { background:#dbeafe; color:#1d4ed8; }
.fmt-xlsx { background:#d1fae5; color:#065f46; }
.fmt-xls { background:#fef3c7; color:#92400e; }
.fmt-txt { background:#f3e8ff; color:#6b21a8; }

/* Template download */
.template-btns { display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem; }
.btn { padding:.6rem 1.2rem; border:none; border-radius:8px; font-weight:600;
       cursor:pointer; font-size:.88rem; display:inline-flex; align-items:center;
       gap:.4rem; text-decoration:none; transition:all .2s; }
.btn-primary { background:linear-gradient(135deg,#0ea5e9,#0284c7); color:#fff; }
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(14,165,233,.3); }
.btn-success { background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; }
.btn-success:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(34,197,94,.3); }
.btn-secondary { background:var(--color-card); color:var(--color-text);
                 border:1px solid var(--color-border); }
.btn-secondary:hover { border-color:var(--color-primary); }
.btn-danger { background:linear-gradient(135deg,#ef4444,#dc2626); color:#fff; }
.btn-sm { padding:.4rem .8rem; font-size:.8rem; }
/* Template specific colors */
.btn-tpl-excel { background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; box-shadow:0 2px 8px rgba(22,163,74,.25); }
.btn-tpl-excel:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(22,163,74,.4); }
.btn-tpl-txt   { background:linear-gradient(135deg,#7c3aed,#6d28d9); color:#fff; box-shadow:0 2px 8px rgba(124,58,237,.25); }
.btn-tpl-txt:hover   { transform:translateY(-2px); box-shadow:0 6px 16px rgba(124,58,237,.4); }

/* Progress */
.progress-bar-wrap { background:var(--color-border); border-radius:4px; height:6px;
                     overflow:hidden; margin:1rem 0; }
.progress-bar-fill { height:100%; background:linear-gradient(90deg,#0ea5e9,#22c55e);
                     border-radius:4px; transition:width .4s ease; }

/* Stats */
.stats-row { display:flex; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
.stat-box { flex:1; min-width:120px; padding:.75rem 1rem; border-radius:8px;
            text-align:center; }
.stat-box.total { background:#dbeafe; color:#1d4ed8; }
.stat-box.valid { background:#d1fae5; color:#065f46; }
.stat-box.invalid { background:#fee2e2; color:#dc2626; }
.stat-num { font-size:1.6rem; font-weight:800; }
.stat-label { font-size:.75rem; font-weight:600; margin-top:2px; }

/* Preview table */
.preview-wrap { overflow-x:auto; max-height:450px; overflow-y:auto;
                border:1px solid var(--color-border); border-radius:8px; }
.preview-table { width:100%; border-collapse:collapse; font-size:.82rem; min-width:1240px; }
.preview-table th { background:var(--color-primary); color:#fff; padding:8px 10px;
                    position:sticky; top:0; z-index:2; white-space:nowrap; }
.preview-table td { padding:6px 8px; border-bottom:1px solid var(--color-border);
                    vertical-align:middle; }
.preview-table tr.row-invalid td { background:#fff5f5; }
.preview-table tr.row-valid td { background:#f0fdf4; }
.preview-table tr:hover td { filter:brightness(.97); }
.cell-input { width:100%; padding:4px 6px; border:1px solid transparent;
              border-radius:4px; background:transparent; font-size:.82rem;
              font-family:inherit; color:var(--color-text); }
.cell-input:focus { outline:none; border-color:var(--color-primary);
                    background:var(--color-bg); }
.cell-input.error { border-color:#ef4444; background:#fff5f5; }
.row-status { font-size:.75rem; font-weight:600; padding:2px 8px; border-radius:10px;
              white-space:nowrap; }
.row-status.ok { background:#d1fae5; color:#065f46; }
.row-status.err { background:#fee2e2; color:#dc2626; }
.del-row { background:none; border:none; cursor:pointer; color:#ef4444;
           font-size:1rem; padding:2px 6px; border-radius:4px; }
.del-row:hover { background:#fee2e2; }

/* Alert */
.alert { padding:.9rem 1rem; border-radius:8px; margin-bottom:1rem;
         border-left:4px solid; font-size:.9rem; }
.alert-info    { background:#dbeafe; color:#1e40af; border-color:#3b82f6; }
.alert-success { background:#d1fae5; color:#065f46; border-color:#22c55e; }
.alert-warning { background:#fef3c7; color:#92400e; border-color:#f59e0b; }
.alert-danger  { background:#fee2e2; color:#dc2626; border-color:#ef4444; }

/* Unmapped warning */
.unmapped-list { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.4rem; }
.unmapped-badge { background:#fef3c7; color:#92400e; padding:2px 8px;
                  border-radius:10px; font-size:.78rem; font-weight:600; }

/* Section visibility */
.section { display:none; }
.section.visible { display:block; }

@media(max-width:768px) {
    .steps { flex-direction:column; }
    .step::after { display:none; }
    .stats-row { flex-direction:column; }
}
</style>
</head>
<body class="sidebar-hidden">
<?php $page_title='Import Aset'; $page_icon=''; include __DIR__.'/app/header-sidebar.php'; ?>

<div class="content-area">
<div class="container">
    <div class="page-title">Import Data Aset</div>
    <p class="page-subtitle">Upload file Excel (.xlsx/.xls) atau TXT, preview data, edit jika perlu, lalu simpan</p>

    <!-- Steps indicator -->
    <div class="steps">
        <div class="step active" id="step-1"><div class="step-num">1</div> Upload File</div>
        <div class="step" id="step-2"><div class="step-num">2</div> Preview &amp; Edit</div>
        <div class="step" id="step-3"><div class="step-num">3</div> Konfirmasi &amp; Simpan</div>
    </div>

    <!-- ── SECTION 1: Panduan + Upload ── -->
    <div class="section visible" id="sec-upload">

        <!-- Panduan format -->
        <div class="card">
            <div class="card-title">Panduan Format File</div>

            <!-- [RUMUS PENYUSUTAN 26-09-2026] Banner rumus otomatis (permanen) -->
            <div class="alert alert-info" style="font-size:.84rem;">
                <strong>RUMUS PENYUSUTAN OTOMATIS (permanen):</strong>
                <strong>beban_penyusutan_bln</strong> = biaya_perolehan &divide; masa_manfaat_bln, dan
                <strong>akumulasi_penyusutan</strong> = biaya_perolehan &divide; masa_manfaat_bln &times; umur_jalan_bln
                (contoh: 6.346.000 &divide; 48 = 132.208/bln &rarr; &times; 34 = 4.495.083).
                Nilai di file untuk kedua kolom ini <strong>diabaikan</strong> — sistem selalu menghitung ulang.
                Masa manfaat <strong>0</strong> (mis. tanah) &rarr; beban &amp; akumulasi otomatis <strong>0</strong>.
            </div>

            <p style="font-size:.85rem;color:var(--color-text-secondary);margin-bottom:1rem;">
                Baris pertama file harus berisi <strong>header kolom</strong>. Nama kolom fleksibel — sistem mendeteksi otomatis.
                File yang diupload harus berformat <strong>Excel (.xlsx/.xls)</strong> atau <strong>TXT</strong>.
            </p>

            <!-- Tabel kolom -->
            <table class="guide-table">
                <thead>
                    <tr>
                        <th>Nama Kolom</th>
                        <th>Alias yang Dikenali</th>
                        <th>Wajib?</th>
                        <th>Contoh Isi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><strong>cabang</strong></td><td>cabang, branch, kode cabang</td><td><span class="badge-req">Wajib</span></td><td>08 - PARUNG</td></tr>
                    <tr><td><strong>toko</strong></td><td>toko, store, nama toko</td><td><span class="badge-req">Wajib</span></td><td>TZY1 - IDM SUKA MULYA</td></tr>
                    <tr><td><strong>sub_code</strong></td><td>sub_code, sub code, sub kode</td><td><span class="badge-opt">Opsional</span></td><td>00000000</td></tr>
                    <tr><td><strong>kategori</strong></td><td>kategori, category, jenis</td><td><span class="badge-req">Wajib</span></td><td>C - PERALATAN KOMPUTER / EDP</td></tr>
                    <tr><td><strong>keterangan</strong></td><td>keterangan, nama aset, deskripsi</td><td><span class="badge-req">Wajib</span></td><td>PRINTER THERMAL EPSON TM-T82III</td></tr>
                    <tr><td><strong>no_seri</strong></td><td>no_seri, serial, nomor seri</td><td><span class="badge-opt">Opsional</span></td><td>C08.061244</td></tr>
                    <tr><td><strong>kuantitas</strong></td><td>kuantitas, qty, quantity, jumlah</td><td><span class="badge-opt">Opsional</span></td><td>1</td></tr>
                    <tr><td><strong>biaya_perolehan</strong></td><td>biaya perolehan, harga, nilai aset</td><td><span class="badge-opt">Opsional</span></td><td>1650000</td></tr>
                    <tr><td><strong>masa_manfaat_bln</strong></td><td>masa manfaat bln, masa manfaat</td><td><span class="badge-opt">Opsional</span></td><td>48 (96 mobil, 240 gedung, 0 tanah)</td></tr>
                    <tr><td><strong>beban_penyusutan_bln</strong></td><td>beban penyusutan bln, penyusutan per bulan</td><td><span class="badge-opt">Opsional</span></td><td>132208 (= 6.346.000 &divide; 48) — <strong>otomatis dihitung sistem</strong></td></tr>
                    <tr><td><strong>umur_jalan_bln</strong></td><td>umur jalan bln, umur jalan</td><td><span class="badge-opt">Opsional</span></td><td>12</td></tr>
                    <tr><td><strong>akumulasi_penyusutan</strong></td><td>akumulasi penyusutan, depresiasi</td><td><span class="badge-opt">Opsional</span></td><td>4495083 (= 6.346.000 &divide; 48 &times; 34) — <strong>otomatis dihitung sistem</strong></td></tr>
                    <tr><td><strong>status</strong></td><td>status, kondisi</td><td><span class="badge-opt">Opsional</span></td><td>Aktif / Tidak Aktif / Rusak</td></tr>
                </tbody>
            </table>

            <!-- Format yang didukung -->
            <div style="margin-top:1.25rem;">
                <p style="font-size:.85rem;font-weight:600;color:var(--color-text);margin-bottom:.5rem;">Format file yang didukung</p>
                <table class="guide-table">
                    <thead>
                        <tr>
                            <th>Format (nama di Excel "Save As")</th>
                            <th>Ekstensi</th>
                            <th>Pemisah Kolom</th>
                            <th>Bisa Diupload?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Excel Workbook</td><td><code>.xlsx / .xlsm</code></td><td>—</td><td><span class="badge-req">Bisa</span></td></tr>
                        <tr><td>Excel 97-2003 Workbook</td><td><code>.xls</code></td><td>—</td><td><span class="badge-req">Bisa</span></td></tr>
                        <tr><td>Text (Tab delimited)</td><td><code>.txt</code></td><td>TAB</td><td><span class="badge-req">Bisa</span></td></tr>
                        <tr><td>Unicode Text</td><td><code>.txt</code></td><td>TAB</td><td><span class="badge-req">Bisa</span></td></tr>
                        <tr><td>Text (MS-DOS) / Text (Macintosh)</td><td><code>.txt</code></td><td>TAB</td><td><span class="badge-req">Bisa</span></td></tr>
                        <tr><td>TXT dengan pemisah Pipe atau Semicolon</td><td><code>.txt</code></td><td>Pipe ( | ) atau Semicolon ( ; )</td><td><span class="badge-req">Bisa</span></td></tr>
                        <tr><td>Formatted Text (Space delimited)</td><td><code>.prn / .txt</code></td><td>Spasi</td><td><span class="badge-opt" style="background:#fee2e2;color:#dc2626;">Tidak Bisa</span></td></tr>
                    </tbody>
                </table>
                <p style="font-size:.82rem;color:var(--color-text-secondary);margin-top:.5rem;">
                    File Excel (.xlsx/.xls) bisa langsung diupload. Untuk TXT, pastikan kolom dipisah dengan karakter TAB.
                </p>
            </div>

            <!-- Contoh TXT -->
            <div style="margin-top:1.25rem;">
                <p style="font-size:.85rem;font-weight:600;color:var(--color-text);margin-bottom:.5rem;">Contoh isi file TXT (Tab delimited)</p>
                <p style="font-size:.82rem;color:var(--color-text-secondary);margin-bottom:.5rem;">Setiap kolom dipisah dengan karakter TAB. Saat simpan dari Excel sebagai "Text (Tab delimited)", TAB otomatis disisipkan antar kolom.</p>
                <div style="overflow-x:auto;">
                    <table class="guide-table" style="font-family:monospace;font-size:.82rem;">
                        <thead>
                            <tr>
                                <th>cabang</th>
                                <th>toko</th>
                                <th>sub_code</th>
                                <th>kategori</th>
                                <th>keterangan</th>
                                <th>no_seri</th>
                                <th>kuantitas</th>
                                <th>biaya_perolehan</th>
                                <th>masa_manfaat_bln</th>
                                <th>beban_penyusutan_bln</th>
                                <th>umur_jalan_bln</th>
                                <th>akumulasi_penyusutan</th>
                                <th>status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>08 - PARUNG</td>
                                <td>TZY1 - IDM SUKA MULYA</td>
                                <td>00000000</td>
                                <td>C - PERALATAN KOMPUTER / EDP</td>
                                <td>PRINTER THERMAL EPSON</td>
                                <td>C08.061244</td>
                                <td>1</td>
                                <td>6346000</td>
                                <td>48</td>
                                <td>132208</td>
                                <td>34</td>
                                <td>4495083</td>
                                <td>Aktif</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="template-btns" style="margin-top:1.25rem;">
                <a href="api/asset_import_template.php?type=xls" class="btn btn-tpl-excel btn-sm">↓ Template Excel (.xls)</a>
                <a href="api/asset_import_template.php?type=txt" class="btn btn-tpl-txt btn-sm">↓ Template TXT</a>
            </div>
        </div>

        <!-- Upload zone -->
        <div class="card">
            <div class="card-title">Upload File</div>
            <div class="upload-zone" id="upload-zone">
                <input type="file" class="upload-input" id="file-input" accept=".xlsx,.xlsm,.xls,.txt">
                <div class="upload-icon" style="font-size:2.5rem;color:#94a3b8;">&#8679;</div>
                <div class="upload-title">Klik atau seret file ke sini</div>
                <div class="upload-sub">Maksimal 512MB • 1.000.000 baris</div>
                <div class="upload-formats">
                    <span class="fmt-badge fmt-xlsx" title="Excel Workbook (.xlsx)">XLSX</span>
                    <span class="fmt-badge fmt-xls" title="Excel 97-2003 Workbook (.xls)">XLS</span>
                    <span class="fmt-badge fmt-txt" title="Text Tab delimited, Unicode Text, Text MS-DOS">TXT</span>
                </div>
            </div>
            <div id="upload-status" style="margin-top:1rem;display:none;"></div>
            <div class="progress-bar-wrap" id="upload-progress" style="display:none;">
                <div class="progress-bar-fill" id="upload-progress-fill" style="width:0%"></div>
            </div>
        </div>
    </div>

    <!-- ── SECTION 2: Preview & Edit ── -->
    <div class="section" id="sec-preview">
        <div class="card">
            <div class="card-title">Preview Data — Periksa sebelum menyimpan</div>

            <div id="unmapped-warning" style="display:none;" class="alert alert-warning">
                ! Kolom berikut tidak ditemukan di file dan akan dikosongkan:
                <div class="unmapped-list" id="unmapped-list"></div>
            </div>

            <div class="stats-row">
                <div class="stat-box total"><div class="stat-num" id="stat-total">0</div><div class="stat-label">Total Baris</div></div>
                <div class="stat-box valid"><div class="stat-num" id="stat-valid">0</div><div class="stat-label">Valid</div></div>
                <div class="stat-box invalid"><div class="stat-num" id="stat-invalid">0</div><div class="stat-label">Perlu Diperbaiki</div></div>
            </div>

            <div id="invalid-hint" class="alert alert-info" style="display:none;">
                Baris merah perlu diperbaiki. Klik langsung pada sel untuk mengedit. Baris yang masih error tidak akan disimpan.
            </div>

            <div class="preview-wrap">
                <table class="preview-table" id="preview-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Cabang *</th>
                            <th>Toko *</th>
                            <th>Sub Code</th>
                            <th>Kategori *</th>
                            <th>Keterangan *</th>
                            <th>No Seri</th>
                            <th>Kuantitas</th>
                            <th>Biaya Perolehan</th>
                            <th>Masa Manfaat<br>(bln)</th>
                            <th>Beban Penyusutan<br>(bln)</th>
                            <th>Umur Jalan<br>(bln)</th>
                            <th>Akumulasi<br>Penyusutan</th>
                            <th>Status</th>
                            <th style="width:80px;">Status Baris</th>
                            <th style="width:50px;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody id="preview-tbody"></tbody>
                </table>
            </div>
            <div id="preview-pagination"></div>

            <div style="display:flex;gap:.75rem;margin-top:1.25rem;flex-wrap:wrap;align-items:center;">
                <button class="btn btn-secondary" onclick="goBack()">← Upload Ulang</button>
                <button class="btn btn-danger" onclick="openPdfDesigner()" id="btn-pdf" title="Atur layout, kertas & warna lalu unduh PDF">Unduh PDF</button>
                <button class="btn btn-primary" onclick="goToConfirm()" id="btn-confirm">
                    Lanjut Konfirmasi
                </button>
                <span style="font-size:.82rem;color:var(--color-text-secondary);" id="confirm-hint"></span>
            </div>
        </div>
    </div>

    <!-- ── SECTION 3: Konfirmasi & Simpan ── -->
    <div class="section" id="sec-confirm">
        <div class="card">
            <div class="card-title">Konfirmasi Import</div>
            <div id="confirm-summary"></div>
            <div style="display:flex;gap:.75rem;margin-top:1.5rem;flex-wrap:wrap;">
                <button class="btn btn-secondary" onclick="goToPreview()">← Edit Lagi</button>
                <button class="btn btn-success" id="btn-save" onclick="saveData()">
                    Simpan Semua Data
                </button>
            </div>
        </div>
        <div id="save-result" style="display:none;"></div>
    </div>

</div><!-- /container -->
</div><!-- /content-area -->

<script>
// ── State ──
var previewData = [];   // array of row objects
var fileName    = '';

// ── Upload ──
var fileInput  = document.getElementById('file-input');
var uploadZone = document.getElementById('upload-zone');

uploadZone.addEventListener('dragover',  function(e){ e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', function(){ uploadZone.classList.remove('dragover'); });
uploadZone.addEventListener('drop', function(e){
    e.preventDefault(); uploadZone.classList.remove('dragover');
    var f = e.dataTransfer.files[0];
    if (f) processFile(f);
});
fileInput.addEventListener('change', function(){
    if (this.files[0]) processFile(this.files[0]);
});

function processFile(file) {
    var ext = file.name.split('.').pop().toLowerCase();
    if (!['xlsx','xlsm','xls','txt'].includes(ext)) {
        showUploadStatus('danger', 'Format tidak didukung. Gunakan Excel (.xlsx/.xlsm/.xls) atau TXT');
        return;
    }
    if (file.size > 512*1024*1024) {
        showUploadStatus('danger', 'Ukuran file melebihi 512MB');
        return;
    }
    fileName = file.name;
    showUploadStatus('info', 'Memproses file <strong>'+escHtml(file.name)+'</strong>...');
    showProgress(true);

    var fd = new FormData();
    fd.append('file', file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/asset_import_preview.php');
    xhr.upload.onprogress = function(e){
        if (e.lengthComputable) setProgress(Math.round(e.loaded/e.total*60));
    };
    xhr.onload = function(){
        setProgress(100);
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                showUploadStatus('success', 'File berhasil dibaca: <strong>'+res.total+'</strong> baris ditemukan');
                setTimeout(function(){ showProgress(false); showPreview(res); }, 400);
            } else {
                showUploadStatus('danger', escHtml(res.message));
                showProgress(false);
            }
        } catch(e) {
            var raw = xhr.responseText ? xhr.responseText.substring(0, 500) : '(kosong)';
            showUploadStatus('danger', 'Server error: ' + escHtml(raw));
            showProgress(false);
        }
    };
    xhr.onerror = function(){ showUploadStatus('danger', 'Gagal menghubungi server'); showProgress(false); };
    xhr.send(fd);
}

function showUploadStatus(type, html) {
    var el = document.getElementById('upload-status');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-'+type+'">'+html+'</div>';
}
function showProgress(show) {
    document.getElementById('upload-progress').style.display = show ? 'block' : 'none';
}
function setProgress(pct) {
    document.getElementById('upload-progress-fill').style.width = pct+'%';
}

// ── Build Preview ──
var previewPage = 0;
var previewPageSize = 100;

function showPreview(res) {
    previewData = res.preview;
    previewPage = 0;

    // Unmapped warning
    if (res.unmapped_cols && res.unmapped_cols.length) {
        document.getElementById('unmapped-warning').style.display = 'block';
        document.getElementById('unmapped-list').innerHTML =
            res.unmapped_cols.map(function(c){ return '<span class="unmapped-badge">'+escHtml(c)+'</span>'; }).join('');
    }

    updateStats();
    renderTable();
    goToSection('sec-preview', 2);
}

function renderTable() {
    var tbody = document.getElementById('preview-tbody');
    tbody.innerHTML = '';

    var start = previewPage * previewPageSize;
    var end   = Math.min(start + previewPageSize, previewData.length);
    var pageData = previewData.slice(start, end);

    pageData.forEach(function(row, localIdx) {
        var globalIdx = start + localIdx;
        tbody.appendChild(buildRow(row, globalIdx));
    });

    updateStats();
    renderPagination();
}

function renderPagination() {
    var totalPages = Math.ceil(previewData.length / previewPageSize);
    var container = document.getElementById('preview-pagination');
    if (!container) return;

    if (totalPages <= 1) { container.innerHTML = ''; return; }

    var btnBase     = 'padding:4px 12px;border-radius:4px;border:1px solid #cbd5e1;cursor:pointer;font-size:.82rem;background:#f8fafc;color:#1e293b;';
    var btnActive   = 'padding:4px 12px;border-radius:4px;border:1px solid #0b5ea8;cursor:pointer;font-size:.82rem;background:#0b5ea8;color:#fff;font-weight:700;';
    var btnDisabled = 'padding:4px 12px;border-radius:4px;border:1px solid #e2e8f0;cursor:default;font-size:.82rem;background:#f1f5f9;color:#94a3b8;';

    var html = '<div style="display:flex;gap:6px;align-items:center;justify-content:center;padding:10px 0;flex-wrap:wrap;">';
    html += '<span style="font-size:.82rem;color:#64748b;">Halaman ' + (previewPage+1) + ' / ' + totalPages + ' (' + previewData.length + ' baris)</span>';

    // Prev
    if (previewPage === 0) {
        html += '<button disabled style="' + btnDisabled + '">&#8592; Prev</button>';
    } else {
        html += '<button onclick="changePage(-1)" style="' + btnBase + '">&#8592; Prev</button>';
    }

    // Page numbers
    var startP = Math.max(0, previewPage - 2);
    var endP   = Math.min(totalPages - 1, previewPage + 2);
    for (var p = startP; p <= endP; p++) {
        var style = (p === previewPage) ? btnActive : btnBase;
        html += '<button onclick="goPage('+p+')" style="' + style + '">' + (p+1) + '</button>';
    }

    // Next
    if (previewPage >= totalPages - 1) {
        html += '<button disabled style="' + btnDisabled + '">Next &#8594;</button>';
    } else {
        html += '<button onclick="changePage(1)" style="' + btnBase + '">Next &#8594;</button>';
    }

    html += '</div>';
    container.innerHTML = html;
}

function changePage(dir) {
    var totalPages = Math.ceil(previewData.length / previewPageSize);
    previewPage = Math.max(0, Math.min(totalPages - 1, previewPage + dir));
    renderTable();
    document.getElementById('preview-tbody').closest('table').scrollIntoView({behavior:'smooth'});
}

function goPage(p) {
    previewPage = p;
    renderTable();
    document.getElementById('preview-tbody').closest('table').scrollIntoView({behavior:'smooth'});
}

function buildRow(row, i) {
    var tr = document.createElement('tr');
    tr.className = row._valid ? 'row-valid' : 'row-invalid';
    tr.dataset.idx = i;

    var fields = ['cabang','toko','sub_code','kategori','keterangan','no_seri','kuantitas','biaya_perolehan','masa_manfaat_bln','beban_penyusutan_bln','umur_jalan_bln','akumulasi_penyusutan','status'];
    // [RUMUS PENYUSUTAN 26-09-2026] kolom hasil rumus — readonly (dihitung sistem)
    var autoCalcCols = ['beban_penyusutan_bln','akumulasi_penyusutan'];
    var required = ['cabang','toko','kategori','keterangan'];

    // Row number
    var tdNum = document.createElement('td');
    tdNum.textContent = row._row || (i+2);
    tdNum.style.color = 'var(--color-text-secondary)';
    tdNum.style.fontSize = '.78rem';
    tr.appendChild(tdNum);

    fields.forEach(function(f) {
        var td = document.createElement('td');
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'cell-input' + (required.includes(f) && !row[f] ? ' error' : '');
        inp.value = row[f] !== undefined ? row[f] : '';
        inp.dataset.field = f;
        inp.dataset.idx   = i;
        // [RUMUS PENYUSUTAN 26-09-2026] kolom hasil rumus readonly (dihitung sistem)
        if (typeof autoCalcCols !== 'undefined' && autoCalcCols.indexOf(f) !== -1) {
            inp.readOnly = true;
            inp.style.background = 'var(--color-surface-secondary, #f1f5f9)';
            inp.style.cursor = 'not-allowed';
            inp.title = 'Otomatis dari rumus penyusutan — dihitung sistem saat import';
        }
        inp.addEventListener('input', function() {
            previewData[i][f] = this.value.trim();
            validateRow(i);
            updateStats();
        });
        td.appendChild(inp);
        tr.appendChild(td);
    });

    // Status badge
    var tdStatus = document.createElement('td');
    tdStatus.innerHTML = row._valid
        ? '<span class="row-status ok">Valid</span>'
        : '<span class="row-status err">Error</span>';
    tdStatus.className = 'status-cell';
    tr.appendChild(tdStatus);

    // Delete button
    var tdDel = document.createElement('td');
    var btnDel = document.createElement('button');
    btnDel.className = 'del-row';
    btnDel.title = 'Hapus baris ini';
    btnDel.textContent = '×';
    btnDel.addEventListener('click', function() {
        previewData.splice(i, 1);
        renderTable();
    });
    tdDel.appendChild(btnDel);
    tr.appendChild(tdDel);

    return tr;
}

function validateRow(i) {
    var row = previewData[i];
    var errors = [];
    if (!row.cabang)     errors.push('Cabang kosong');
    if (!row.toko)       errors.push('Toko kosong');
    if (!row.kategori)   errors.push('Kategori kosong');
    if (!row.keterangan) errors.push('Keterangan kosong');
    row._valid  = errors.length === 0;
    row._errors = errors;

    // Update row class & status cell
    var tr = document.querySelector('#preview-tbody tr[data-idx="'+i+'"]');
    if (!tr) return;
    tr.className = row._valid ? 'row-valid' : 'row-invalid';
    var statusCell = tr.querySelector('.status-cell');
    if (statusCell) {
        statusCell.innerHTML = row._valid
            ? '<span class="row-status ok">Valid</span>'
            : '<span class="row-status err">Error</span>';
    }
    // Update error class on required inputs
    tr.querySelectorAll('.cell-input').forEach(function(inp) {
        var f = inp.dataset.field;
        if (['cabang','toko','kategori','keterangan'].includes(f)) {
            inp.classList.toggle('error', !inp.value.trim());
        }
    });
}

function updateStats() {
    var total   = previewData.length;
    var valid   = previewData.filter(function(r){ return r._valid; }).length;
    var invalid = total - valid;
    document.getElementById('stat-total').textContent   = total;
    document.getElementById('stat-valid').textContent   = valid;
    document.getElementById('stat-invalid').textContent = invalid;

    var hint = document.getElementById('invalid-hint');
    hint.style.display = invalid > 0 ? 'block' : 'none';

    var confirmHint = document.getElementById('confirm-hint');
    confirmHint.textContent = invalid > 0
        ? invalid+' baris error tidak akan disimpan'
        : 'Semua baris valid';
}

// ── Navigation ──
function goToSection(secId, stepNum) {
    document.querySelectorAll('.section').forEach(function(s){ s.classList.remove('visible'); });
    document.getElementById(secId).classList.add('visible');
    document.querySelectorAll('.step').forEach(function(s, i){
        s.classList.remove('active','done');
        if (i+1 < stepNum) s.classList.add('done');
        if (i+1 === stepNum) s.classList.add('active');
    });
}
function goBack()      { goToSection('sec-upload',  1); }
function goToPreview() { goToSection('sec-preview', 2); }

function goToConfirm() {
    var valid = previewData.filter(function(r){ return r._valid; });
    if (!valid.length) {
        showPopup('warning', 'Perhatian', 'Tidak ada baris valid untuk disimpan. Perbaiki data terlebih dahulu.');
        return;
    }
    var invalid = previewData.length - valid.length;
    var html = '<div class="alert alert-info">'
        + '<strong>Ringkasan Import:</strong><br>'
        + '• Total baris: <strong>'+previewData.length+'</strong><br>'
        + '• Akan disimpan: <strong style="color:#065f46">'+valid.length+' baris valid</strong><br>'
        + (invalid ? '• Dilewati (error): <strong style="color:#dc2626">'+invalid+' baris</strong><br>' : '')
        + '</div>'
        + '<p style="font-size:.9rem;color:var(--color-text-secondary);">Klik <strong>Simpan Semua Data</strong> untuk melanjutkan. Proses ini tidak bisa dibatalkan.</p>';
    document.getElementById('confirm-summary').innerHTML = html;
    goToSection('sec-confirm', 3);
}

// ── Save ──
function saveData() {
    var valid = previewData.filter(function(r){ return r._valid; });
    if (!valid.length) return;

    var btn = document.getElementById('btn-save');
    btn.disabled = true;
    btn.textContent = 'Menyimpan...';

    // Bersihkan field internal sebelum kirim
    var rows = valid.map(function(r) {
        return {
            cabang: r.cabang, toko: r.toko, sub_code: r.sub_code || '',
            kategori: r.kategori,
            keterangan: r.keterangan, no_seri: r.no_seri || '',
            kuantitas: parseInt(r.kuantitas) || 1,
            biaya_perolehan: parseInt(r.biaya_perolehan) || 0,
            masa_manfaat_bln: parseInt(r.masa_manfaat_bln) || 0,
            umur_jalan_bln: parseInt(r.umur_jalan_bln) || 0,
            akumulasi_penyusutan: parseInt(r.akumulasi_penyusutan) || 0,
            status: r.status || 'Aktif'
        };
    });

    fetch('api/asset_import_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rows: rows })
    })
    .then(function(r){ return r.json(); })
    .then(function(res) {
        var resultEl = document.getElementById('save-result');
        resultEl.style.display = 'block';
        if (res.success) {
            resultEl.innerHTML = '<div class="alert alert-success">'
                + '<strong>Import berhasil!</strong> '+escHtml(res.message)
                + '<br><br><a href="assets.php" class="btn btn-success">Lihat Data Aset →</a>'
                + ' <button class="btn btn-danger" onclick="openPdfDesigner()">Unduh PDF</button>'
                + ' <button class="btn btn-secondary" onclick="resetAll()">Import Lagi</button>'
                + '</div>';
            btn.style.display = 'none';
        } else {
            resultEl.innerHTML = '<div class="alert alert-danger">'+escHtml(res.message)+'</div>';
            btn.disabled = false;
            btn.textContent = 'Simpan Semua Data';
        }
    })
    .catch(function() {
        document.getElementById('save-result').innerHTML =
            '<div class="alert alert-danger">Gagal menghubungi server</div>';
        btn.disabled = false;
        btn.textContent = 'Simpan Semua Data';
    });
}

function resetAll() {
    previewData = [];
    fileName    = '';
    document.getElementById('file-input').value = '';
    document.getElementById('upload-status').style.display = 'none';
    document.getElementById('unmapped-warning').style.display = 'none';
    document.getElementById('save-result').style.display = 'none';
    document.getElementById('btn-save').style.display = '';
    goToSection('sec-upload', 1);
}

function escHtml(str) {
    return String(str).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
</script>

<style>
/* ═══ PDF DESIGNER — atur & preview unduhan PDF (di tab yang sama) ═══ */
#pdf-designer { display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,.62); }
#pdf-designer.open { display:flex; }
body.pd-open { overflow:hidden !important; }
.pd-panel { flex:1; display:flex; min-height:0; margin:14px; border-radius:14px; overflow:hidden; background:#fff; box-shadow:0 20px 60px rgba(0,0,0,.35); }
.pd-settings { width:300px; flex-shrink:0; overflow-y:auto; padding:18px; background:#f8fafc; border-right:1px solid #e2e8f0; }
.pd-settings h3 { font-size:1rem; font-weight:800; color:#0f172a; }
.pd-sub { font-size:.75rem; color:#64748b; margin:2px 0 14px; line-height:1.4; }
.pd-group { margin-bottom:12px; }
.pd-group > label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#475569; margin-bottom:5px; }
.pd-settings select, .pd-settings input[type=text] { width:100%; padding:7px 9px; border:1px solid #cbd5e1; border-radius:7px; font-size:.85rem; background:#fff; color:#0f172a; font-family:inherit; }
.pd-check { display:flex; align-items:center; gap:7px; font-size:.83rem; color:#334155; padding:3px 0; cursor:pointer; }
.pd-check input { accent-color:#dc2626; width:15px; height:15px; flex-shrink:0; }
#pd-cols { max-height:170px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:7px; padding:6px 10px; background:#fff; }
.pd-actions { display:flex; gap:8px; margin-top:14px; }
.pd-actions .btn { flex:1; justify-content:center; font-size:.85rem; }
.btn-pd-print { background:linear-gradient(135deg,#ef4444,#b91c1c); color:#fff; }
.btn-pd-print:hover { filter:brightness(1.08); }
.pd-hint { font-size:.72rem; color:#64748b; margin-top:10px; line-height:1.5; }
.pd-preview { flex:1; overflow:auto; padding:24px; background:#d7dfea; position:relative; }
#pd-scale-info { position:sticky; top:0; float:right; font-size:.72rem; color:#334155; background:rgba(255,255,255,.9); padding:3px 10px; border-radius:20px; border:1px solid #cbd5e1; margin-bottom:6px; z-index:2; }
#pd-paper-scale { width:fit-content; margin:0 auto; }
#pd-paper { background:#fff; box-shadow:0 6px 30px rgba(15,23,42,.28); font-family:'Segoe UI',sans-serif; }
.pd-doc-title { font-weight:800; color:#0f172a; margin-bottom:4px; }
.pd-doc-meta { color:#64748b; margin-bottom:14px; }
#pd-table { width:100%; border-collapse:collapse; }
#pd-table th { color:#fff; text-align:left; padding:6px 8px; white-space:nowrap; }
#pd-table td { padding:5px 8px; vertical-align:top; border-bottom:1px solid #e5e7eb; }
#pd-table td.num { text-align:right; white-space:nowrap; }
#pd-table.pd-nowrap td { white-space:nowrap; }
/* Paksa warna tema tercetak walau "Background graphics" tidak dicentang */
#pd-paper, #pd-paper * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }

/* ── Saat mencetak/unduh: hanya lembar PDF designer yang dicetak ── */
@media print {
    body.pdf-printing > *:not(#pdf-designer) { display:none !important; }
    body.pdf-printing #pdf-designer { display:block !important; position:static; background:#fff !important; }
    body.pdf-printing .pd-panel { margin:0; border-radius:0; box-shadow:none; display:block; overflow:visible; }
    body.pdf-printing .pd-settings { display:none !important; }
    body.pdf-printing .pd-preview { overflow:visible !important; padding:0 !important; background:#fff !important; }
    body.pdf-printing #pd-scale-info { display:none !important; }
    body.pdf-printing #pd-paper-scale { zoom:1 !important; margin:0; }
    body.pdf-printing #pd-paper { width:auto !important; min-height:0 !important; box-shadow:none !important; padding:0 !important; }
    body.pdf-printing #pd-table { width:100%; }
    body.pdf-printing #pd-table thead { display:table-header-group; }
    body.pdf-printing #pd-table tr { page-break-inside:avoid; }
}
</style>
<!-- ═══════════ PDF DESIGNER: atur + preview + unduh (tanpa pindah tab) ═══════════ -->
<div id="pdf-designer" aria-hidden="true">
    <style id="pd-page-style">@page { size: 297mm 210mm; margin: 12mm; }</style>
    <style id="pd-table-style"></style>
    <div class="pd-panel">
        <aside class="pd-settings">
            <h3>Atur Unduhan PDF</h3>
            <div class="pd-sub">Rapikan dulu layout, kertas &amp; warnanya — lihat preview langsung di kanan, lalu unduh.</div>

            <div class="pd-group">
                <label for="pd-title">Judul Dokumen</label>
                <input type="text" id="pd-title" value="Data Import Aset" oninput="applyPdfDesign()">
            </div>

            <div class="pd-group">
                <label for="pd-paper-size">Ukuran Kertas</label>
                <select id="pd-paper-size" onchange="applyPdfDesign()">
                    <option value="a4">A4 (210 × 297 mm)</option>
                    <option value="f4">F4 / Folio (215 × 330 mm)</option>
                    <option value="letter">Letter (216 × 279 mm)</option>
                    <option value="legal">Legal (216 × 356 mm)</option>
                </select>
            </div>

            <div class="pd-group">
                <label for="pd-orient">Orientasi</label>
                <select id="pd-orient" onchange="applyPdfDesign()">
                    <option value="landscape" selected>Lanskap (melebar — anti terpotong)</option>
                    <option value="portrait">Potret</option>
                </select>
            </div>

            <div class="pd-group">
                <label for="pd-margin">Margin Kertas</label>
                <select id="pd-margin" onchange="applyPdfDesign()">
                    <option value="8">Sempit (8 mm)</option>
                    <option value="12" selected>Normal (12 mm)</option>
                    <option value="20">Lebar (20 mm)</option>
                </select>
            </div>

            <div class="pd-group">
                <label for="pd-font">Ukuran Font</label>
                <select id="pd-font" onchange="applyPdfDesign()">
                    <option value="8">Kecil</option>
                    <option value="9.5" selected>Sedang</option>
                    <option value="11">Besar</option>
                </select>
            </div>

            <div class="pd-group">
                <label for="pd-theme">Tema Warna</label>
                <select id="pd-theme" onchange="applyPdfDesign()">
                    <option value="merah" selected>Merah Indomaret</option>
                    <option value="biru">Biru</option>
                    <option value="hitam">Hitam-Putih (hemat tinta)</option>
                </select>
            </div>

            <div class="pd-group">
                <label>Gaya Tampilan</label>
                <label class="pd-check"><input type="checkbox" id="pd-zebra" checked onchange="applyPdfDesign()"> Baris zebra (selang-seling)</label>
                <label class="pd-check"><input type="checkbox" id="pd-highlight" checked onchange="applyPdfDesign()"> Sorot baris error (merah muda)</label>
                <label class="pd-check"><input type="checkbox" id="pd-fit" checked onchange="applyPdfDesign()"> Muat lebar kertas (font otomatis menyesuaikan)</label>
            </div>

            <div class="pd-group">
                <label for="pd-scope">Cakupan Baris</label>
                <select id="pd-scope" onchange="applyPdfDesign()">
                    <option value="all" selected>Semua baris</option>
                    <option value="valid">Hanya baris valid</option>
                </select>
            </div>

            <div class="pd-group">
                <label>Kolom yang Dicetak</label>
                <div id="pd-cols"></div>
            </div>

            <div class="pd-actions">
                <button class="btn btn-pd-print" onclick="pdPrint()">Unduh PDF</button>
                <button class="btn btn-secondary" onclick="closePdfDesigner()">Tutup</button>
            </div>
            <div class="pd-hint">Dialog print akan terbuka — pilih tujuan <strong>&ldquo;Save as PDF&rdquo;</strong> / <strong>&ldquo;Simpan sebagai PDF&rdquo;</strong> lalu klik Save. Tekan <strong>Esc</strong> untuk menutup.</div>
        </aside>

        <main class="pd-preview">
            <div id="pd-scale-info">Zoom preview: 100%</div>
            <div id="pd-paper-scale">
                <div id="pd-paper">
                    <div class="pd-doc-title" id="pd-doc-title">Data Import Aset</div>
                    <div class="pd-doc-meta" id="pd-doc-meta"></div>
                    <table id="pd-table"></table>
                </div>
            </div>
        </main>
    </div>
</div>
<script>
// ═══ PDF DESIGNER: logic ═══
var PD_PAPERS = { a4:{w:210,h:297}, f4:{w:215,h:330}, letter:{w:215.9,h:279.4}, legal:{w:215.9,h:355.6} };
var PD_THEMES = {
    merah: { head:'#e01a2b', zebra:'#fdecec', border:'#e5e7eb' },
    biru:  { head:'#0284c7', zebra:'#eff6ff', border:'#e5e7eb' },
    hitam: { head:'#1f2937', zebra:'#f3f4f6', border:'#e5e7eb' }
};
var PD_COLS = [
    {key:'cabang',               label:'Cabang'},
    {key:'toko',                 label:'Toko'},
    {key:'sub_code',             label:'Sub Code'},
    {key:'kategori',             label:'Kategori'},
    {key:'keterangan',           label:'Keterangan'},
    {key:'no_seri',              label:'No Seri'},
    {key:'kuantitas',            label:'Kuantitas',                 num:true},
    {key:'biaya_perolehan',      label:'Biaya Perolehan (Rp)',      num:true},
    {key:'masa_manfaat_bln',     label:'Masa Manfaat (bln)',        num:true},
    {key:'beban_penyusutan_bln', label:'Beban Penyusutan/bln (Rp)', num:true},
    {key:'umur_jalan_bln',       label:'Umur Jalan (bln)',          num:true},
    {key:'akumulasi_penyusutan', label:'Akumulasi Penyusutan (Rp)', num:true},
    {key:'status',               label:'Status'}
];
var pdColsBuilt = false;

function pdEl(id){ return document.getElementById(id); }

function openPdfDesigner() {
    if (!previewData.length) {
        showPopup('warning', 'Tidak Ada Data', 'Upload dan preview data terlebih dahulu sebelum mengunduh PDF.');
        return;
    }
    if (!pdColsBuilt) { buildPdfCols(); pdColsBuilt = true; }
    pdEl('pdf-designer').classList.add('open');
    document.body.classList.add('pd-open');
    applyPdfDesign();
}
function closePdfDesigner() {
    pdEl('pdf-designer').classList.remove('open');
    document.body.classList.remove('pd-open');
}
function pdPrint() { window.print(); }

function buildPdfCols() {
    var wrap = pdEl('pd-cols');
    wrap.innerHTML = PD_COLS.map(function(c, i){
        return '<label class="pd-check"><input type="checkbox" class="pd-col" data-idx="'+i+'" checked> '+escHtml(c.label)+'</label>';
    }).join('');
    wrap.querySelectorAll('input.pd-col').forEach(function(inp){
        inp.addEventListener('change', applyPdfDesign);
    });
}

function pdSettings() {
    var paper = PD_PAPERS[pdEl('pd-paper-size').value] || PD_PAPERS.a4;
    var land  = pdEl('pd-orient').value === 'landscape';
    return {
        pw:        land ? paper.h : paper.w,
        ph:        land ? paper.w : paper.h,
        margin:    parseFloat(pdEl('pd-margin').value) || 12,
        fontPt:    parseFloat(pdEl('pd-font').value) || 9.5,
        theme:     PD_THEMES[pdEl('pd-theme').value] || PD_THEMES.merah,
        zebra:     pdEl('pd-zebra').checked,
        highlight: pdEl('pd-highlight').checked,
        fit:       pdEl('pd-fit').checked,
        title:     pdEl('pd-title').value.trim() || 'Data Import Aset'
    };
}

function pdRows() {
    return pdEl('pd-scope').value === 'valid'
        ? previewData.filter(function(r){ return r._valid; })
        : previewData;
}

function pdFmtNum(v) {
    var n = parseInt(v);
    return isNaN(n) ? '-' : n.toLocaleString('id-ID');
}
function applyPdfDesign() {
    var s    = pdSettings();
    var rows = pdRows();
    var idxOn = {};
    document.querySelectorAll('#pd-cols input.pd-col').forEach(function(inp){
        if (inp.checked) idxOn[inp.getAttribute('data-idx')] = true;
    });
    var cols = PD_COLS.filter(function(c, i){ return idxOn[i]; });
    if (!cols.length) cols = PD_COLS.slice();

    // ── Ukuran kertas (px @96dpi) & margin ──
    var px     = 96 / 25.4;
    var paperW = s.pw * px;
    var paper  = pdEl('pd-paper');
    paper.style.width     = paperW + 'px';
    paper.style.minHeight = Math.max(s.ph * px, 150) + 'px';
    paper.style.padding   = s.margin + 'mm';

    // ── @page untuk dialog print (ukuran & margin kertas) ──
    pdEl('pd-page-style').textContent =
        '@page { size: ' + s.pw + 'mm ' + s.ph + 'mm; margin: ' + s.margin + 'mm; }';

    // ── Kepala dokumen ──
    var valid   = previewData.filter(function(r){ return r._valid; }).length;
    var invalid = previewData.length - valid;
    var now = new Date();
    var tgl = ('0'+now.getDate()).slice(-2)+'/'+('0'+(now.getMonth()+1)).slice(-2)+'/'+now.getFullYear()
            + ' ' + ('0'+now.getHours()).slice(-2)+':'+('0'+now.getMinutes()).slice(-2);
    var docTitle = pdEl('pd-doc-title');
    docTitle.textContent    = s.title;
    docTitle.style.fontSize = Math.round(s.fontPt * 1.6) + 'pt';
    pdEl('pd-doc-meta').textContent =
        'File: ' + (fileName || '-') +
        '  •  Baris: ' + rows.length + ' dari ' + previewData.length +
        (invalid ? '  (valid ' + valid + ' • error ' + invalid + ')' : '  (semua valid)') +
        '  •  Dicetak: ' + tgl;

    // ── Tabel ──
    var html = '<thead><tr><th style="background:' + s.theme.head + ';">No</th>';
    cols.forEach(function(c){
        html += '<th style="background:' + s.theme.head + (c.num ? ';text-align:right;' : '') + ';">' + escHtml(c.label) + '</th>';
    });
    html += '</tr></thead><tbody>';
    rows.forEach(function(r, i){
        var bg = '';
        if (s.highlight && !r._valid)      bg = ' style="background:#fff1f2;"';
        else if (s.zebra && (i % 2) === 1) bg = ' style="background:' + s.theme.zebra + ';"';
        html += '<tr' + bg + '><td style="color:#94a3b8;">' + (i + 1) + '</td>';
        cols.forEach(function(c){
            var v = r[c.key];
            if (c.num) v = pdFmtNum(v);
            if (v === undefined || v === null || v === '') v = '-';
            html += '<td' + (c.num ? ' class="num"' : '') + '>' + escHtml(v) + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody>';

    var tbl = pdEl('pd-table');
    tbl.innerHTML      = html;
    tbl.style.fontSize = (s.fontPt * 96 / 72) + 'px';
    tbl.classList.toggle('pd-nowrap', s.fit);
    pdEl('pd-table-style').textContent =
        '#pd-table td { border-bottom:1px solid ' + s.theme.border + '; }';

    // ── Mode muat-lebar: kecilkan font sampai tabel muat di lebar kertas ──
    if (s.fit) {
        var f = s.fontPt * 96 / 72, guard = 0;
        while (tbl.scrollWidth > tbl.clientWidth + 1 && f > 6 && guard < 40) {
            f -= 0.5;
            tbl.style.fontSize = f + 'px';
            guard++;
        }
        if (tbl.scrollWidth > tbl.clientWidth + 1) {
            tbl.classList.remove('pd-nowrap'); // darurat: biarkan teks membungkus
        }
    }

    pdScalePreview(paperW);
}

function pdScalePreview(paperW) {
    var pane  = document.querySelector('#pdf-designer .pd-preview');
    var avail = pane.clientWidth - 48;
    var s     = Math.min(1, avail / paperW);
    pdEl('pd-paper-scale').style.zoom = s;
    pdEl('pd-scale-info').textContent =
        'Zoom preview: ' + Math.round(s * 100) + '% — hasil unduhan tetap ukuran penuh';
}

// Saat mencetak: kalau designer sedang terbuka, cetak lembar PDF-nya saja
window.addEventListener('beforeprint', function(){
    if (pdEl('pdf-designer').classList.contains('open')) {
        document.body.classList.add('pdf-printing');
    }
});
window.addEventListener('afterprint', function(){
    document.body.classList.remove('pdf-printing');
});
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closePdfDesigner();
});
</script>
<?php echo_loading_js(); ?>
</body>
</html>
