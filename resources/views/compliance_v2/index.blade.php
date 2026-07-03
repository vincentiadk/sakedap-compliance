@extends('layouts.app')

@section('content')
<div class="container-fluid px-4">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0 fw-bold">Compliance KCKR 2026+</h4>
            <small class="text-muted">Berbasis Tanggal Terbit &mdash; Deadline: 20 Hari Kerja</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-success" onclick="doExport()">
                <i class="bi bi-file-earmark-excel"></i> Export Excel
            </button>
        </div>
    </div>

    @if(isset($error))
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    {{-- Filter Panel --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">

                {{-- Filter Tipe Tanggal --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Tipe Filter</label>
                    <select class="form-select form-select-sm" id="filterType" onchange="onFilterTypeChange()">
                        <option value="tahun">Per Tahun</option>
                        <option value="bulan">Per Bulan</option>
                        <option value="range">Rentang Tanggal</option>
                    </select>
                </div>

                <div id="filterTahunWrap" class="col-auto">
                    <label class="form-label form-label-sm mb-1">Tahun</label>
                    <select class="form-select form-select-sm" id="filterYear">
                        @for($y = 2026; $y <= 2030; $y++)
                            <option value="{{ $y }}" {{ $y == 2026 ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>

                <div id="filterBulanWrap" class="col-auto d-none">
                    <label class="form-label form-label-sm mb-1">Bulan</label>
                    <select class="form-select form-select-sm" id="filterMonth">
                        @foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $bln)
                            <option value="{{ $i+1 }}">{{ $bln }}</option>
                        @endforeach
                    </select>
                </div>

                <div id="filterRangeWrap" class="col-auto d-none">
                    <label class="form-label form-label-sm mb-1">Dari</label>
                    <input type="date" class="form-control form-control-sm" id="startDate">
                </div>
                <div id="filterRangeWrap2" class="col-auto d-none">
                    <label class="form-label form-label-sm mb-1">Sampai</label>
                    <input type="date" class="form-control form-control-sm" id="endDate">
                </div>

                {{-- Provinsi --}}
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">Provinsi</label>
                    <select class="form-select form-select-sm" id="provinceFilter" multiple size="1" style="height:31px">
                        @foreach($provinces as $prov)
                            <option value="{{ $prov->ID }}">{{ $prov->NAMAPROPINSI }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Kategori --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Kategori</label>
                    <select class="form-select form-select-sm" id="filterKategori">
                        <option value="">Semua</option>
                        <option value="1">Pemerintah</option>
                        <option value="2">Swasta</option>
                    </select>
                </div>

                {{-- Hutang Terbit --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Hutang Terbit</label>
                    <select class="form-select form-select-sm" id="filterHutang">
                        <option value="">Semua</option>
                        <option value="ya">Ada Hutang</option>
                        <option value="tidak">Tidak Ada</option>
                    </select>
                </div>

                {{-- Lewat Teguran --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Lewat Teguran</label>
                    <select class="form-select form-select-sm" id="filterTeguran">
                        <option value="">Semua</option>
                        <option value="ya">Lewat Teguran</option>
                        <option value="tidak">Tidak</option>
                    </select>
                </div>

                {{-- Filter KCKR --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Status KCKR</label>
                    <select class="form-select form-select-sm" id="filterKckr">
                        <option value="">Semua</option>
                        <option value="sudah">Ada KCKR</option>
                        <option value="belum">Belum KCKR</option>
                    </select>
                </div>

                {{-- Filter % KCKR --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">% KCKR</label>
                    <select class="form-select form-select-sm" id="filterPersentase">
                        <option value="">Semua</option>
                        <option value="0-20">0–20% (Sangat Tidak Patuh)</option>
                        <option value="21-40">21–40% (Tidak Patuh)</option>
                        <option value="41-60">41–60% (Cukup Patuh)</option>
                        <option value="61-80">61–80% (Patuh)</option>
                        <option value="81-100">81–100% (Sangat Patuh)</option>
                    </select>
                </div>

                {{-- Search --}}
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">Cari Penerbit</label>
                    <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Nama penerbit...">
                </div>

                <div class="col-auto">
                    <button class="btn btn-primary btn-sm mt-3" onclick="loadData(1)">Tampilkan</button>
                    <button class="btn btn-outline-secondary btn-sm mt-3" onclick="resetFilter()">Reset</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="mb-3" id="summaryCards">

        {{-- Baris atas: Penerbit + label grup --}}
        <div class="row g-2 mb-1 align-items-center">
            <div class="col-auto">
                <div class="card border-0 shadow-sm text-center py-2 px-3 h-100">
                    <div class="fs-4 fw-bold text-primary" id="sumPenerbit">-</div>
                    <small class="text-muted">Penerbit</small>
                </div>
            </div>
            <div class="col">
                <div class="d-flex flex-column gap-1">
                    {{-- Grup Status Terbit --}}
                    <div>
                        <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;letter-spacing:.05em;text-transform:uppercase">
                            📄 Status Terbit
                        </div>
                        <div class="row g-2">
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2 border-secondary">
                                    <div class="fs-5 fw-bold text-secondary" id="sumJudul">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Total Judul</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2 border-success">
                                    <div class="fs-5 fw-bold text-success" id="sumTerbit">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Sudah Terbit</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2" style="border-color:#adb5bd!important">
                                    <div class="fs-5 fw-bold" style="color:#6c757d" id="sumBelumTerbit">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Belum Terbit</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2 border-warning">
                                    <div class="fs-5 fw-bold text-warning" id="sumHutang">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Hutang Terbit</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2 border-danger">
                                    <div class="fs-5 fw-bold text-danger" id="sumTeguran">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Lewat Teguran</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Grup Status KCKR --}}
                    <div>
                        <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;letter-spacing:.05em;text-transform:uppercase">
                            ✅ Status KCKR
                        </div>
                        <div class="row g-2">
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2 border-info">
                                    <div class="fs-5 fw-bold text-info" id="sumKckr">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Sudah KCKR</small>
                                </div>
                            </div>
                            <div class="col">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2" style="border-color:#fd7e14!important">
                                    <div class="fs-5 fw-bold" style="color:#fd7e14" id="sumTagihanKckr">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Tagihan KCKR</small>
                                </div>
                            </div>
                            <div class="col-auto" style="min-width:160px">
                                <div class="card border-0 shadow-sm text-center py-2 border-top border-2 border-primary h-100 px-2">
                                    <div class="fs-5 fw-bold text-primary" id="sumPctKckr">-</div>
                                    <small class="text-muted" style="font-size:.72rem">Rata-rata % KCKR</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabel --}}
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" style="font-size:.82rem">
                    <thead id="tableHead" class="table-dark">
                        <tr>
                            <th rowspan="2" class="sortable text-center align-middle" data-col="NAME">#</th>
                            <th rowspan="2" class="sortable align-middle" data-col="NAME">Nama Penerbit</th>
                            <th rowspan="2" class="align-middle">Kategori</th>
                            <th rowspan="2" class="align-middle">Kota</th>
                            <th rowspan="2" class="sortable text-center align-middle" data-col="TOTAL_JUDUL">Total Judul</th>
                            <th colspan="2" class="text-center border-start">Status Terbit</th>
                            <th colspan="2" class="text-center border-start text-warning-emphasis">Keterlambatan Terbit</th>
                            <th colspan="3" class="text-center border-start">Sudah KCKR</th>
                            <th colspan="3" class="text-center border-start">Belum KCKR</th>
                            <th rowspan="2" class="text-center align-middle border-start sortable" data-col="PERSENTASE_KCKR">% KCKR</th>
                            <th rowspan="2" class="text-center align-middle">Aksi</th>
                        </tr>
                        <tr>
                            <th class="text-center border-start bg-success bg-opacity-25">Terbit</th>
                            <th class="text-center bg-secondary bg-opacity-25">Belum</th>
                            <th class="text-center border-start bg-warning bg-opacity-25 sortable" data-col="HUTANG_TERBIT">Hutang</th>
                            <th class="text-center bg-danger bg-opacity-25 sortable" data-col="LEWAT_TEGURAN">Lewat Teguran</th>
                            <th class="text-center border-start bg-info bg-opacity-25 sortable" data-col="SUDAH_KCKR">Total</th>
                            <th class="text-center bg-info bg-opacity-25">Cetak</th>
                            <th class="text-center bg-info bg-opacity-25">Rekam</th>
                            <th class="text-center border-start">Total</th>
                            <th class="text-center">Cetak</th>
                            <th class="text-center">Rekam</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr><td colspan="18" class="text-center py-4 text-muted">Pilih filter lalu klik Tampilkan</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center py-1">
            <small class="text-muted" id="pageInfo"></small>
            <div id="pagination"></div>
        </div>
    </div>

</div>

{{-- Loading Modal --}}
<div class="modal fade" id="exportModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold">Menyiapkan File Excel</h6>
            </div>
            <div class="modal-body">
                <div class="progress mb-2" style="height:8px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                         id="exportProgress" style="width:0%"></div>
                </div>
                <small class="text-muted" id="exportStatus">Memproses data...</small>
            </div>
        </div>
    </div>
</div>

<style>
.sortable { cursor: pointer; user-select: none; }
.sortable:hover { background: rgba(255,255,255,.15); }
th.sorted-asc::after  { content: ' ▲'; font-size:.65rem; }
th.sorted-desc::after { content: ' ▼'; font-size:.65rem; }
</style>

<script>
let currentPage = 1, currentSort = 'NAME', currentDir = 'ASC';

function onFilterTypeChange() {
    const t = document.getElementById('filterType').value;
    document.getElementById('filterTahunWrap').classList.toggle('d-none', t !== 'tahun');
    document.getElementById('filterBulanWrap').classList.toggle('d-none', t !== 'bulan');
    ['filterRangeWrap','filterRangeWrap2'].forEach(id =>
        document.getElementById(id).classList.toggle('d-none', t !== 'range'));
}

function buildParams(page = 1) {
    const type = document.getElementById('filterType').value;
    const params = new URLSearchParams({
        filter_type: type,
        page, sort_col: currentSort, sort_dir: currentDir,
    });
    if (type === 'tahun') params.set('filter_year', document.getElementById('filterYear').value);
    if (type === 'bulan') {
        params.set('filter_year',  document.getElementById('filterYear').value);
        params.set('filter_month', document.getElementById('filterMonth').value);
    }
    if (type === 'range') {
        params.set('start_date', document.getElementById('startDate').value);
        params.set('end_date',   document.getElementById('endDate').value);
    }
    const prov = [...document.getElementById('provinceFilter').selectedOptions].map(o => o.value);
    prov.forEach(v => params.append('province_ids[]', v));
    const v = (id) => document.getElementById(id).value;
    if (v('filterKategori'))   params.set('kategori',       v('filterKategori'));
    if (v('filterHutang'))     params.set('filter_hutang',  v('filterHutang'));
    if (v('filterTeguran'))    params.set('filter_teguran', v('filterTeguran'));
    if (v('filterKckr'))       params.set('filter_kckr',    v('filterKckr'));
    if (v('filterPersentase')) params.set('persentase',     v('filterPersentase'));
    if (v('searchInput'))      params.set('search',         v('searchInput'));
    return params;
}

function loadData(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '<tr><td colspan="18" class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Memuat...</td></tr>';

    fetch('{{ route("compliance_v2.data") }}?' + buildParams(page))
        .then(r => r.json())
        .then(res => {
            if (res.error) { tbody.innerHTML = `<tr><td colspan="18" class="text-center text-danger">${res.error}</td></tr>`; return; }
            renderTable(res);
            renderPagination(res);
            updateSummary(res);
        })
        .catch(() => tbody.innerHTML = '<tr><td colspan="18" class="text-center text-danger">Gagal memuat data.</td></tr>');
}

function renderTable(res) {
    const tbody = document.getElementById('tableBody');
    if (!res.data.length) {
        tbody.innerHTML = '<tr><td colspan="18" class="text-center py-4 text-muted">Tidak ada data.</td></tr>';
        return;
    }
    let no = (res.current_page - 1) * res.per_page + 1;
    tbody.innerHTML = res.data.map(r => {
        const hutangBadge  = r.HUTANG_TERBIT  > 0 ? `<span class="badge bg-warning text-dark">${r.HUTANG_TERBIT}</span>`  : `<span class="text-muted">0</span>`;
        const teguranBadge = r.LEWAT_TEGURAN  > 0 ? `<span class="badge bg-danger">${r.LEWAT_TEGURAN}</span>`             : `<span class="text-muted">0</span>`;
        const judulTerbit  = parseInt(r.JUDUL_TERBIT || 0);
        const pct          = parseFloat(r.PERSENTASE_KCKR || 0);
        const pctColor     = pct >= 80 ? 'success' : pct >= 50 ? 'warning' : 'danger';
        const pctBadge     = judulTerbit === 0
            ? `<span class="badge bg-secondary">-</span>`
            : `<span class="badge bg-${pctColor}">${pct}%</span>`;
        const detailUrl    = `{{ url('/compliance-v2/detail') }}/${r.ID}?` + buildParams().toString();
        return `<tr>
            <td class="text-muted text-center">${no++}</td>
            <td><a href="${detailUrl}" class="text-decoration-none fw-semibold">${r.NAME}</a></td>
            <td><span class="badge bg-secondary">${r.KATEGORI}</span></td>
            <td>${r.CITY || '-'}</td>
            <td class="text-center">${r.TOTAL_JUDUL}</td>
            <td class="text-center text-success fw-semibold">${r.JUDUL_TERBIT}</td>
            <td class="text-center text-muted">${r.JUDUL_BELUM_TERBIT}</td>
            <td class="text-center">${hutangBadge}</td>
            <td class="text-center">${teguranBadge}</td>
            <td class="text-center text-info fw-semibold">${r.SUDAH_KCKR}</td>
            <td class="text-center text-muted">${r.SUDAH_KCKR_CETAK}</td>
            <td class="text-center text-muted">${r.SUDAH_KCKR_REKAM}</td>
            <td class="text-center">${r.BELUM_KCKR}</td>
            <td class="text-center text-muted">${r.BELUM_KCKR_CETAK}</td>
            <td class="text-center text-muted">${r.BELUM_KCKR_REKAM}</td>
            <td class="text-center">${pctBadge}</td>
            <td class="text-center"><a href="${detailUrl}" class="btn btn-xs btn-outline-primary" style="font-size:.7rem;padding:1px 6px">Detail</a></td>
        </tr>`;
    }).join('');
}

function updateSummary(res) {
    let sumJ=0, sumT=0, sumBT=0, sumH=0, sumTeg=0, sumK=0, sumTagihan=0, pctSum=0, pctCount=0;
    res.data.forEach(r => {
        sumJ       += parseInt(r.TOTAL_JUDUL)        || 0;
        sumT       += parseInt(r.JUDUL_TERBIT)       || 0;
        sumBT      += parseInt(r.JUDUL_BELUM_TERBIT) || 0;
        sumH       += parseInt(r.HUTANG_TERBIT)      || 0;
        sumTeg     += parseInt(r.LEWAT_TEGURAN)      || 0;
        sumK       += parseInt(r.SUDAH_KCKR)         || 0;
        sumTagihan += parseInt(r.BELUM_KCKR)         || 0;
        if (parseInt(r.JUDUL_TERBIT || 0) > 0) {
            pctSum += parseFloat(r.PERSENTASE_KCKR) || 0;
            pctCount++;
        }
    });
    const avgPct = pctCount > 0 ? (pctSum / pctCount).toFixed(1) : '0.0';
    document.getElementById('sumPenerbit').textContent    = res.total;
    document.getElementById('sumJudul').textContent       = sumJ.toLocaleString('id');
    document.getElementById('sumTerbit').textContent      = sumT.toLocaleString('id');
    document.getElementById('sumBelumTerbit').textContent = sumBT.toLocaleString('id');
    document.getElementById('sumHutang').textContent      = sumH.toLocaleString('id');
    document.getElementById('sumTeguran').textContent     = sumTeg.toLocaleString('id');
    document.getElementById('sumKckr').textContent        = sumK.toLocaleString('id');
    document.getElementById('sumTagihanKckr').textContent = sumTagihan.toLocaleString('id');
    document.getElementById('sumPctKckr').textContent     = avgPct + '%';
    document.getElementById('pageInfo').textContent       =
        `Menampilkan ${res.data.length} dari ${res.total} penerbit (hal. ${res.current_page}/${res.last_page})`;
}

function renderPagination(res) {
    const el = document.getElementById('pagination');
    if (res.last_page <= 1) { el.innerHTML = ''; return; }
    let html = '<nav><ul class="pagination pagination-sm mb-0">';
    html += `<li class="page-item ${res.current_page===1?'disabled':''}"><a class="page-link" href="#" onclick="loadData(${res.current_page-1});return false">&laquo;</a></li>`;
    const start = Math.max(1, res.current_page-2), end = Math.min(res.last_page, res.current_page+2);
    if (start > 1) html += `<li class="page-item"><a class="page-link" href="#" onclick="loadData(1);return false">1</a></li>${start>2?'<li class="page-item disabled"><span class="page-link">…</span></li>':''}`;
    for (let p=start; p<=end; p++) html += `<li class="page-item ${p===res.current_page?'active':''}"><a class="page-link" href="#" onclick="loadData(${p});return false">${p}</a></li>`;
    if (end < res.last_page) html += `${end<res.last_page-1?'<li class="page-item disabled"><span class="page-link">…</span></li>':''}<li class="page-item"><a class="page-link" href="#" onclick="loadData(${res.last_page});return false">${res.last_page}</a></li>`;
    html += `<li class="page-item ${res.current_page===res.last_page?'disabled':''}"><a class="page-link" href="#" onclick="loadData(${res.current_page+1});return false">&raquo;</a></li>`;
    html += '</ul></nav>';
    el.innerHTML = html;
}

// Sorting
document.querySelectorAll('th.sortable').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (currentSort === col) currentDir = currentDir === 'ASC' ? 'DESC' : 'ASC';
        else { currentSort = col; currentDir = 'ASC'; }
        document.querySelectorAll('th').forEach(t => t.classList.remove('sorted-asc','sorted-desc'));
        th.classList.add(currentDir === 'ASC' ? 'sorted-asc' : 'sorted-desc');
        loadData(1);
    });
});

// Export
function doExport() {
    const token   = Date.now().toString(36) + Math.random().toString(36).slice(2,6);
    const params  = buildParams(1);
    params.delete('page'); params.delete('sort_col'); params.delete('sort_dir');
    params.set('download_token', token);
    const url     = '{{ route("compliance_v2.export") }}?' + params.toString();
    const modal   = new bootstrap.Modal(document.getElementById('exportModal'));
    const bar     = document.getElementById('exportProgress');
    const status  = document.getElementById('exportStatus');
    let pct = 0;
    modal.show();
    window.location.href = url;
    const animInterval = setInterval(() => {
        if (pct < 85) { pct += 2; bar.style.width = pct + '%'; }
    }, 400);
    const pollInterval = setInterval(() => {
        if (document.cookie.includes('dl_' + token)) {
            clearInterval(animInterval); clearInterval(pollInterval);
            bar.style.width = '100%';
            status.textContent = 'Selesai! File sedang diunduh.';
            setTimeout(() => modal.hide(), 1200);
            document.cookie = 'dl_' + token + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        }
    }, 800);
    setTimeout(() => { clearInterval(animInterval); clearInterval(pollInterval); modal.hide(); }, 300000);
}

function resetFilter() {
    document.getElementById('filterType').value     = 'tahun';
    document.getElementById('filterYear').value     = '2026';
    document.getElementById('filterKategori').value = '';
    document.getElementById('filterHutang').value   = '';
    document.getElementById('filterTeguran').value  = '';
    document.getElementById('filterKckr').value       = '';
    document.getElementById('filterPersentase').value = '';
    document.getElementById('searchInput').value      = '';
    [...document.getElementById('provinceFilter').options].forEach(o => o.selected = false);
    onFilterTypeChange();
}

// Enter key di search
document.getElementById('searchInput').addEventListener('keydown', e => { if(e.key==='Enter') loadData(1); });
</script>
@endsection
