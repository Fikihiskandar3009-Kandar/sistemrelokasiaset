<?php
/**
 * ==== FORM TAMBAH / EDIT ASET ====
 * Input manual satu aset dengan autocomplete (cabang/toko/kategori)
 * Akses: admin | Terkait: api/asset_create.php, api/asset_update.php
 * (Header dokumentasi ditambahkan saat perapian struktur skripsi 24-09-2026)
 */
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/rbac.php';
auth_require();

if (!user_can('asset.create')) {
    header('Location: dashboard.php');
    exit('Unauthorized');
}

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cabang               = trim($_POST['cabang'] ?? '');
        $toko                 = trim($_POST['toko'] ?? '');
        $sub_code             = trim($_POST['sub_code'] ?? '');
        $kategori             = trim($_POST['kategori'] ?? '');
        $keterangan           = trim($_POST['keterangan'] ?? '');
        $no_seri              = trim($_POST['no_seri'] ?? '');
        $kuantitas            = (int)($_POST['kuantitas'] ?? 1);
        $biaya_perolehan      = (int)($_POST['biaya_perolehan'] ?? 0);
        $masa_manfaat_bln     = (int)($_POST['masa_manfaat_bln'] ?? 0);
        $umur_jalan_bln       = (int)($_POST['umur_jalan_bln'] ?? 0);
        // [RUMUS PENYUSUTAN 26-09-2026] beban & akumulasi TIDAK dari input manual —
        // selalu dihitung: beban = biaya/masa, akumulasi = biaya/masa*umur (dibulatkan ke bawah)
        $penyusutan           = hitungPenyusutan($biaya_perolehan, $masa_manfaat_bln, $umur_jalan_bln);
        $beban_penyusutan_bln = $penyusutan['beban'];
        $akumulasi_penyusutan = $penyusutan['akumulasi'];
        $status               = trim($_POST['status'] ?? 'Aktif');

        if (empty($cabang) || empty($toko) || empty($kategori) || empty($keterangan)) {
            throw new Exception('Field Cabang, Toko, Kategori, dan Keterangan wajib diisi');
        }

        if ($kuantitas < 1) $kuantitas = 1;

        $stmt = db()->prepare("
            INSERT INTO assets_real
                (cabang, toko, sub_code, kategori, keterangan, no_seri, kuantitas,
                 biaya_perolehan, masa_manfaat_bln, beban_penyusutan_bln, umur_jalan_bln,
                 akumulasi_penyusutan, status, tanggal_update)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $cabang, $toko, $sub_code, $kategori, $keterangan, $no_seri, $kuantitas,
            $biaya_perolehan, $masa_manfaat_bln, $beban_penyusutan_bln, $umur_jalan_bln,
            $akumulasi_penyusutan, $status
        ]);

        if (function_exists('clearAssetFiltersCache')) clearAssetFiltersCache();

        $success = 'Aset berhasil ditambahkan';

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// [RUMUS PENYUSUTAN] pratinjau nilai untuk ditampilkan di field readonly saat form dirender
$pv = hitungPenyusutan($_POST['biaya_perolehan'] ?? 0, $_POST['masa_manfaat_bln'] ?? 0, $_POST['umur_jalan_bln'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Aset - SRA Indomaret Parung</title>
    <?php echo asset_css('css/layout-simple.css'); ?>
    <?php echo asset_css('css/indomaret-theme.css'); ?>
    <?php echo asset_css('css/dark-mode.css'); ?>
    <?php echo asset_css('css/layout-override.css'); ?>
    <?php echo_loading_css(); ?>
    <?php echo asset_js('js/app-ui.js', 'defer'); ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; }
        .container { max-width: 800px; margin: 0 auto; padding: 0.4cm !important; }
        .page-header { margin-bottom: 2rem; }
        .page-title { font-size: 1.75rem; font-weight: 700; color: var(--color-text); margin-bottom: 0.5rem; }
        .page-subtitle { color: var(--color-text-secondary); font-size: 0.95rem; }
        .card { background: var(--color-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid; font-weight: 500; }
        .alert-success { background: #d1fae5; color: #065f46; border-color: #22c55e; }
        .alert-danger  { background: #fee2e2; color: #dc2626; border-color: #ef4444; }
        .form-group { margin-bottom: 1.5rem; display: flex; flex-direction: column; }
        .form-label { font-weight: 600; color: var(--color-text); margin-bottom: 0.5rem; font-size: 0.95rem; }
        .form-input { padding: 0.75rem 1rem; border: 1px solid var(--color-border); border-radius: 8px; background: var(--color-bg); color: var(--color-text); font-size: 0.95rem; transition: all 0.3s ease; font-family: inherit; width: 100%; }
        .form-input:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
        /* placeholder redup, teks isi cerah */
        .form-input::placeholder { color: #b0bec5; font-style: italic; font-size: 0.88rem; }
        .form-input:not(:placeholder-shown) { color: var(--color-text); font-style: normal; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem; flex-wrap: wrap; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, #0284c7, #0369a1); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(14,165,233,0.3); }
        .btn-secondary { background: var(--color-card); color: var(--color-text); border: 1px solid var(--color-border); }
        .btn-secondary:hover { background: var(--color-bg-2); border-color: var(--color-primary); }

        /* Autocomplete */
        .ac-wrap { position: relative; }
        .ac-dropdown {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: var(--color-card, #fff);
            border: 1px solid var(--color-primary, #0ea5e9);
            border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            max-height: 220px; overflow-y: auto; z-index: 1000; display: none;
        }
        .ac-dropdown.open { display: block; }
        .ac-item {
            padding: 9px 14px; font-size: 0.9rem; cursor: pointer;
            color: var(--color-text, #1e293b);
            border-bottom: 1px solid var(--color-border, #e2e8f0);
            transition: background 0.15s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:hover, .ac-item.active { background: rgba(14,165,233,0.1); color: var(--color-primary, #0ea5e9); }
        .ac-item mark { background: transparent; color: var(--color-primary, #0ea5e9); font-weight: 700; }
        .ac-hint { font-size: 0.78rem; color: var(--color-text-secondary, #64748b); margin-top: 4px; }
        .ac-empty { padding: 10px 14px; font-size: 0.85rem; color: var(--color-text-secondary, #64748b); font-style: italic; }

        @media (max-width: 768px) {
            .container { padding: 1rem !important; }
            .card { padding: 1.5rem; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style> 
</head>
<body class="sidebar-hidden">
    <?php $page_title = 'Tambah Aset'; $page_icon = ''; include __DIR__.'/app/header-sidebar.php'; ?>

    <div class="content-area">
        <div class="container">
            <div class="page-header">
                <h1 class="page-title">Tambah Aset Baru</h1>
                <p class="page-subtitle">Isi form di bawah untuk menambahkan data aset</p>
            </div>

            <div class="card">
                <?php if ($success): ?>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        showPopup('success', 'Berhasil', <?= json_encode($success) ?>, function() {
                            setTimeout(function(){ window.location.href = 'assets.php'; }, 1500);
                        });
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

                <div id="manual-form">

                <form method="post" class="form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Cabang</label>
                            <div class="ac-wrap">
                                <input type="text" name="cabang" id="ac-cabang" required
                                       class="form-input" autocomplete="off"
                                       placeholder="Misal: 08 - PARUNG"
                                       value="<?= htmlspecialchars($_POST['cabang'] ?? '') ?>">
                                <div class="ac-dropdown" id="ac-dropdown-cabang"></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Toko</label>
                            <div class="ac-wrap">
                                <input type="text" name="toko" id="ac-toko" required
                                       class="form-input" autocomplete="off"
                                       placeholder="Misal: TZY1 - IDM SUKA MULYA"
                                       value="<?= htmlspecialchars($_POST['toko'] ?? '') ?>">
                                <div class="ac-dropdown" id="ac-dropdown-toko"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Kategori</label>
                            <div class="ac-wrap">
                                <input type="text" name="kategori" id="ac-kategori" required
                                       class="form-input" autocomplete="off"
                                       placeholder="Misal: C - PERALATAN KOMPUTER / EDP"
                                       value="<?= htmlspecialchars($_POST['kategori'] ?? '') ?>">
                                <div class="ac-dropdown" id="ac-dropdown-kategori"></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">No Seri</label>
                            <input type="text" name="no_seri"
                                   class="form-input"
                                   placeholder="Misal: T08.028697"
                                   value="<?= htmlspecialchars($_POST['no_seri'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Keterangan</label>
                        <div class="ac-wrap">
                            <input type="text" name="keterangan" id="ac-keterangan" required
                                   class="form-input" autocomplete="off"
                                   placeholder="Misal: MEJA KASIR TANPA LUBANG EDC"
                                   value="<?= htmlspecialchars($_POST['keterangan'] ?? '') ?>">
                            <div class="ac-dropdown" id="ac-dropdown-keterangan"></div>
                        </div>
                        <span class="ac-hint" id="keterangan-hint"></span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Sub Code</label>
                            <input type="text" name="sub_code"
                                   class="form-input"
                                   placeholder="Misal: 00000000"
                                   value="<?= htmlspecialchars($_POST['sub_code'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kuantitas</label>
                            <input type="number" name="kuantitas"
                                   class="form-input" min="1" step="1" placeholder="1"
                                   value="<?= htmlspecialchars($_POST['kuantitas'] ?? '1') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Biaya Perolehan (Rp)</label>
                            <input type="number" id="f-biaya" name="biaya_perolehan"
                                   class="form-input" min="0" step="1000" placeholder="0"
                                   value="<?= htmlspecialchars($_POST['biaya_perolehan'] ?? '0') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Masa Manfaat (bulan)</label>
                            <input type="number" id="f-masa" name="masa_manfaat_bln"
                                   class="form-input" min="0" step="1" placeholder="0 (tanah/kategori tanpa masa manfaat)"
                                   value="<?= htmlspecialchars($_POST['masa_manfaat_bln'] ?? '0') ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Beban Penyusutan (bln) (Rp) <span style="color:#0ea5e9;font-weight:600">(otomatis)</span></label>
                            <input type="number" id="f-beban" readonly
                                   class="form-input" min="0" placeholder="otomatis"
                                   style="background:var(--color-surface-secondary,#f1f5f9);cursor:not-allowed"
                                   value="<?= $pv['beban'] ?>">
                            <small style="color:var(--color-text-secondary)">= biaya_perolehan &divide; masa_manfaat</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Umur Jalan (bulan)</label>
                            <input type="number" id="f-umur" name="umur_jalan_bln"
                                   class="form-input" min="0" step="1" placeholder="0 (aset baru, belum dipakai)"
                                   value="<?= htmlspecialchars($_POST['umur_jalan_bln'] ?? '0') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Akumulasi Penyusutan (Rp) <span style="color:#0ea5e9;font-weight:600">(otomatis)</span></label>
                        <input type="number" id="f-akumulasi" readonly
                               class="form-input" min="0" placeholder="otomatis" style="max-width:320px"
                               value="<?= $pv['akumulasi'] ?>">
                        <small style="color:var(--color-text-secondary)">= (biaya_perolehan &divide; masa_manfaat) &times; umur_jalan</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="Aktif"      <?= ($_POST['status'] ?? 'Aktif') === 'Aktif'      ? 'selected' : '' ?>>Aktif</option>
                            <option value="Tidak Aktif"<?= ($_POST['status'] ?? '') === 'Tidak Aktif'     ? 'selected' : '' ?>>Tidak Aktif</option>
                            <option value="Rusak"      <?= ($_POST['status'] ?? '') === 'Rusak'           ? 'selected' : '' ?>>Rusak</option>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Simpan Aset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    (function () {
        function esc(str) {
            return String(str).replace(/[&<>"']/g, c =>
                ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        function highlight(text, q) {
            if (!q) return esc(text);
            return esc(text).replace(
                new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'),
                '<mark>$1</mark>'
            );
        }

        // Cache hasil fetch supaya tidak bolak-balik request sama
        const cache = {};

        async function fetchSuggestions(field, query, kategori) {
            const cacheKey = field + '|' + query + '|' + (kategori || '');
            if (cache[cacheKey] !== undefined) return cache[cacheKey];

            const params = new URLSearchParams({ field: field, q: query });
            if (field === 'keterangan' && kategori) params.set('kategori', kategori);

            try {
                const res  = await fetch('api/asset_autocomplete.php?' + params.toString());
                const json = await res.json();
                const data = (json.success && Array.isArray(json.data)) ? json.data : [];
                cache[cacheKey] = data;
                return data;
            } catch (e) {
                return [];
            }
        }

        function renderItems(dropdown, items, query, input) {
            if (!items.length) {
                dropdown.innerHTML = '<div class="ac-empty">Tidak ada pilihan — ketik manual juga bisa</div>';
            } else {
                dropdown.innerHTML = items.map(function(item, i) {
                    return '<div class="ac-item" data-value="' + esc(item) + '">' + highlight(item, query) + '</div>';
                }).join('');
                dropdown.querySelectorAll('.ac-item').forEach(function(el) {
                    el.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        input.value = el.dataset.value;
                        closeDD(dropdown);
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });
            }
            dropdown._ai = -1;
            dropdown.classList.add('open');
        }

        function closeDD(dropdown) {
            dropdown.classList.remove('open');
            dropdown._ai = -1;
        }

        function moveActive(dropdown, dir) {
            var items = dropdown.querySelectorAll('.ac-item');
            if (!items.length) return;
            var cur = (typeof dropdown._ai === 'number') ? dropdown._ai : -1;
            if (items[cur]) items[cur].classList.remove('active');
            var next = (cur + dir + items.length) % items.length;
            dropdown._ai = next;
            items[next].classList.add('active');
            items[next].scrollIntoView({ block: 'nearest' });
        }

        function initAC(fieldName, inputId) {
            var input    = document.getElementById(inputId);
            var dropdown = document.getElementById('ac-dropdown-' + fieldName);
            if (!input || !dropdown) return;

            var debounce = null;

            function show(query) {
                var kategori = '';
                if (fieldName === 'keterangan') {
                    var katEl = document.getElementById('ac-kategori');
                    kategori = katEl ? katEl.value.trim() : '';
                }
                fetchSuggestions(fieldName, query, kategori).then(function(items) {
                    renderItems(dropdown, items, query, input);
                });
            }

            input.addEventListener('focus', function() { show(input.value); });
            input.addEventListener('click', function() { show(input.value); });
            input.addEventListener('input', function() {
                clearTimeout(debounce);
                debounce = setTimeout(function() { show(input.value); }, 180);
            });
            input.addEventListener('keydown', function(e) {
                if (!dropdown.classList.contains('open')) return;
                if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(dropdown,  1); }
                if (e.key === 'ArrowUp')   { e.preventDefault(); moveActive(dropdown, -1); }
                if (e.key === 'Enter') {
                    var active = dropdown.querySelector('.ac-item.active');
                    if (active) {
                        e.preventDefault();
                        input.value = active.dataset.value;
                        closeDD(dropdown);
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                if (e.key === 'Escape') closeDD(dropdown);
            });
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) closeDD(dropdown);
            });
        }

        // Saat kategori berubah: reset keterangan, hapus cache, update placeholder dinamis
        var katInput  = document.getElementById('ac-kategori');
        var ketInput  = document.getElementById('ac-keterangan');
        var ketDrop   = document.getElementById('ac-dropdown-keterangan');
        var ketHint   = document.getElementById('keterangan-hint');

        function updateKeteranganPlaceholder(kat) {
            if (!ketInput) return;
            if (!kat) {
                ketInput.placeholder = 'Misal: MEJA KASIR TANPA LUBANG EDC';
                if (ketHint) ketHint.textContent = '→ Pilih Kategori terlebih dahulu agar saran keterangan lebih tepat';
                return;
            }
            // Fetch 1 contoh keterangan dari kategori ini untuk dijadikan placeholder
            var params = new URLSearchParams({ field: 'keterangan', q: '', kategori: kat });
            fetch('api/asset_autocomplete.php?' + params.toString())
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (json.success && json.data && json.data.length > 0) {
                        ketInput.placeholder = 'Misal: ' + json.data[0];
                    } else {
                        ketInput.placeholder = 'Ketik nama aset untuk kategori ini...';
                    }
                    if (ketHint) ketHint.textContent = '→ Menampilkan keterangan untuk: ' + kat;
                })
                .catch(function() {
                    ketInput.placeholder = 'Misal: MEJA KASIR TANPA LUBANG EDC';
                });
        }

        if (katInput) {
            katInput.addEventListener('change', function() {
                var kat = katInput.value.trim();
                // Reset field keterangan
                if (ketInput) ketInput.value = '';
                if (ketDrop)  closeDD(ketDrop);
                // Hapus cache keterangan supaya fetch ulang dengan kategori baru
                Object.keys(cache).forEach(function(k) {
                    if (k.indexOf('keterangan|') === 0) delete cache[k];
                });
                // Update placeholder keterangan sesuai kategori
                updateKeteranganPlaceholder(kat);
            });
        }

        // ===== [RUMUS PENYUSUTAN 26-09-2026] Auto-calc beban & akumulasi =====
        var fBiaya = document.getElementById('f-biaya'),
            fMasa  = document.getElementById('f-masa'),
            fUmur  = document.getElementById('f-umur'),
            fBeban = document.getElementById('f-beban'),
            fAku   = document.getElementById('f-akumulasi');

        function hitungPenyusutanUI() {
            var b = parseInt(fBiaya.value, 10) || 0,
                m = parseInt(fMasa.value, 10)  || 0,
                u = parseInt(fUmur.value, 10)  || 0;
            // Masa manfaat 0 / biaya 0 (mis. tanah) -> beban & akumulasi = 0
            if (b <= 0 || m <= 0) {
                fBeban.value = 0;
                fAku.value   = 0;
                return;
            }
            fBeban.value = Math.floor(b / m);            // beban = biaya / masa
            fAku.value   = Math.floor(b * u / m);        // akumulasi = biaya * umur / masa
        }
        [fBiaya, fMasa, fUmur].forEach(function (el) {
            el.addEventListener('input',  hitungPenyusutanUI);
            el.addEventListener('change', hitungPenyusutanUI);
        });
        hitungPenyusutanUI();
        // =====================================================================

        initAC('cabang',     'ac-cabang');
        initAC('toko',       'ac-toko');
        initAC('kategori',   'ac-kategori');
        initAC('keterangan', 'ac-keterangan');
    })();
    </script>
    <?php echo_loading_js(); ?>
</body>
</html>
