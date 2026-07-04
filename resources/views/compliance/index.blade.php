@extends('layouts.app')

@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background-color: #f8f9fa; }
.card { border: none; border-radius: 10px; }
.stat-card { transition: transform .15s; }
.stat-card:hover { transform: translateY(-3px); }
.province-list { max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 6px; padding: 8px 12px; background: #fff; }
.province-list label { display: block; font-size: .875rem; padding: 2px 0; cursor: pointer; }
.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.sortable:hover { background: #e9ecef; }
.sort-icon { font-size: .7rem; opacity: .4; }
.sort-icon.active { opacity: 1; color: #0d6efd; }


/* Export modal */
#exportModal .modal-header { background: #1976D2; color: #fff; }
#exportProgressBar { transition: width .4s ease; }
#exportStepList { list-style: none; padding: 0; margin: 0; font-size: .875rem; }
#exportStepList li { padding: 4px 0; color: #6c757d; }
#exportStepList li.done { color: #198754; }
#exportStepList li.active { color: #0d6efd; font-weight: bold; }
#exportStepList li.done::before { content: "✅ "; }
#exportStepList li.active::before { content: "⏳ "; }
#exportStepList li:not(.done):not(.active)::before { content: "⬜ "; }

.page-link { cursor: pointer; }
</style>

{{-- Export Modal --}}
<div class="modal fade" id="exportModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i>Menyiapkan File Excel</h5>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small id="exportProgressLabel" class="text-muted">Memulai...</small>
                        <small id="exportProgressPct" class="text-muted fw-bold">0%</small>
                    </div>
                    <div class="progress" style="height:12px;border-radius:6px">
                        <div id="exportProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                            role="progressbar" style="width:0%"></div>
                    </div>
                </div>
                <ul id="exportStepList">
                    <li id="step1">Menghubungi server</li>
                    <li id="step2">Mengambil data penerbit</li>
                    <li id="step3">Memproses detail judul</li>
                    <li id="step4">Membuat file Excel</li>
                    <li id="step5">Mengirim file</li>
                </ul>
                <div class="mt-3 text-center text-muted" style="font-size:.8rem">
                    <i class="fas fa-info-circle"></i>
                    Jangan tutup halaman ini. File akan otomatis terunduh.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-4">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0 fw-bold">Compliance KCKR s.d. 2025</h4>
            <small class="text-muted">Berbasis Tanggal ISBN diberikan &mdash; Deadline: KC 3 bulan, KR 1 tahun sejak ISBN diberikan (pemerintah: 3 bulan)</small>
        </div>
        <div class="d-flex gap-2">
            <a href="#" onclick="goToDashboard(); return false;" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
        </div>
    </div>

    @if(isset($error))
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    {{-- Filter --}}
    @php
        $initFilterType = request('filter_type', 'tahun');
        $initYear  = min((int) request('filter_year', 2025), 2025);
        $initMonth = (int) request('filter_month', date('n'));
        $initProvinceIds = array_map('intval', request('province_ids', []));
    @endphp
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end flex-wrap">

                {{-- Tipe filter --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Tipe Filter</label>
                    <select class="form-select form-select-sm" id="filterType" onchange="onFilterTypeChange()">
                        <option value="tahun" {{ $initFilterType==='tahun' ? 'selected':'' }}>Per Tahun</option>
                        <option value="bulan" {{ $initFilterType==='bulan' ? 'selected':'' }}>Per Bulan</option>
                        <option value="range" {{ $initFilterType==='range' ? 'selected':'' }}>Rentang</option>
                    </select>
                </div>

                {{-- Tahun --}}
                <div id="wrapTahun" class="col-auto filter-wrap">
                    <label class="form-label form-label-sm mb-1">Tahun</label>
                    <select class="form-select form-select-sm" id="filterYear">
                        @for($y = 2025; $y >= 2015; $y--)
                            <option value="{{ $y }}" {{ $y==$initYear ? 'selected':'' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>

                {{-- Bulan --}}
                <div id="wrapBulan" class="col-auto filter-wrap d-none">
                    <label class="form-label form-label-sm mb-1">Bulan</label>
                    <select class="form-select form-select-sm" id="filterMonth">
                        @foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i=>$bln)
                            <option value="{{ $i+1 }}" {{ ($i+1)==$initMonth ? 'selected':'' }}>{{ $bln }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Range --}}
                <div id="wrapRangeFrom" class="col-auto filter-wrap d-none">
                    <label class="form-label form-label-sm mb-1">Dari</label>
                    <input type="date" class="form-control form-control-sm" id="startDate" value="{{ request('start_date','2025-01-01') }}">
                </div>
                <div id="wrapRangeTo" class="col-auto filter-wrap d-none">
                    <label class="form-label form-label-sm mb-1">Sampai</label>
                    <input type="date" class="form-control form-control-sm" id="endDate" value="{{ request('end_date','2025-12-31') }}">
                </div>

                {{-- Provinsi --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Provinsi</label>
                    <select class="form-select form-select-sm" id="provinceFilter" multiple size="1" style="height:31px;min-width:160px">
                        @foreach($provinces ?? [] as $prov)
                            <option value="{{ $prov->ID }}" {{ in_array((int)$prov->ID, $initProvinceIds) ? 'selected':'' }}>
                                {{ $prov->NAMAPROPINSI }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Kategori --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Kategori</label>
                    <select class="form-select form-select-sm" id="filterKategori">
                        <option value="">Semua</option>
                        <option value="1" {{ request('kategori')==='1' ? 'selected':'' }}>Pemerintah</option>
                        <option value="2" {{ request('kategori')==='2' ? 'selected':'' }}>Swasta</option>
                    </select>
                </div>

                {{-- Rekomendasi --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Rekomendasi</label>
                    <select class="form-select form-select-sm" id="filterRekomendasi">
                        <option value="">Semua</option>
                        <option value="blokir_kckr">Blokir SS KCKR</option>
                        <option value="baik">Baik</option>
                    </select>
                </div>

                {{-- % KCKR --}}
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">% KCKR</label>
                    <select class="form-select form-select-sm" id="filterPersentase">
                        <option value="">Semua</option>
                        <option value="0-20"   {{ request('persentase')==='0-20'   ? 'selected':'' }}>0–20%</option>
                        <option value="21-40"  {{ request('persentase')==='21-40'  ? 'selected':'' }}>21–40%</option>
                        <option value="41-60"  {{ request('persentase')==='41-60'  ? 'selected':'' }}>41–60%</option>
                        <option value="61-80"  {{ request('persentase')==='61-80'  ? 'selected':'' }}>61–80%</option>
                        <option value="81-100" {{ request('persentase')==='81-100' ? 'selected':'' }}>81–100%</option>
                    </select>
                </div>

                {{-- Cari penerbit --}}
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

    {{-- Summary --}}
    <div id="summarySection" style="display:none">
        <p class="text-muted fw-bold mb-1" style="font-size:.85rem">TOTAL KESELURUHAN</p>
        <div class="row g-3 mb-2" id="summaryCards"></div>
        <p class="text-muted fw-bold mb-1 mt-3" style="font-size:.85rem">SUBTOTAL SESUAI FILTER</p>
        <div class="row g-3 mb-4" id="subtotalCards"></div>
    </div>

    {{-- Tabel --}}
    <div class="card shadow-sm">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Data Penerbit</h5>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-success" id="totalBadge"></span>
                <button class="btn btn-sm btn-light" onclick="doExport(0)" title="Export ringkasan penerbit (Excel)">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button class="btn btn-sm btn-outline-light" onclick="doExport(1)" title="Export lengkap dengan detail judul (Excel)">
                    <i class="fas fa-list"></i> Excel+Judul
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle">No</th>
                        <th rowspan="2" class="align-middle sortable" onclick="setSort('NAME')">
                            Nama Penerbit <span class="sort-icon" id="sort_NAME">⇅</span>
                        </th>
                        <th rowspan="2" class="align-middle">Kategori</th>
                        <th rowspan="2" class="align-middle">Provinsi</th>
                        <th rowspan="2" class="text-center align-middle sortable" onclick="setSort('JUMLAHJUDUL')">
                            Jml Judul <span class="sort-icon" id="sort_JUMLAHJUDUL">⇅</span>
                        </th>
                        <th colspan="3" class="text-center bg-success bg-opacity-25 sortable" onclick="setSort('JUMLAHSUDAHKCKR')">
                            Sudah KCKR <span class="sort-icon" id="sort_JUMLAHSUDAHKCKR">⇅</span>
                        </th>
                        <th colspan="3" class="text-center bg-warning bg-opacity-25 sortable" onclick="setSort('JUMLAHBELUMKCKR')">
                            Belum KCKR <span class="sort-icon" id="sort_JUMLAHBELUMKCKR">⇅</span>
                        </th>
                        <th rowspan="2" class="text-center align-middle sortable" onclick="setSort('JUMLAHTERLAMBATKCKR')">
                            Terlambat <span class="sort-icon" id="sort_JUMLAHTERLAMBATKCKR">⇅</span>
                        </th>
                        <th rowspan="2" class="text-center align-middle">Tepat Waktu</th>
                        <th rowspan="2" class="text-center align-middle sortable" onclick="setSort('PERSENTASE_KCKR')">
                            % KCKR <span class="sort-icon" id="sort_PERSENTASE_KCKR">⇅</span>
                        </th>
                        <th rowspan="2" class="text-center align-middle">Rekomendasi</th>
                    </tr>
                    <tr>
                        <th class="text-center bg-success bg-opacity-25" style="font-weight:normal;font-size:.8rem">Total</th>
                        <th class="text-center bg-success bg-opacity-25" style="font-weight:normal;font-size:.8rem">Cetak</th>
                        <th class="text-center bg-success bg-opacity-25" style="font-weight:normal;font-size:.8rem">Rekam</th>
                        <th class="text-center bg-warning bg-opacity-25" style="font-weight:normal;font-size:.8rem">Total</th>
                        <th class="text-center bg-warning bg-opacity-25" style="font-weight:normal;font-size:.8rem">Cetak</th>
                        <th class="text-center bg-warning bg-opacity-25" style="font-weight:normal;font-size:.8rem">Rekam</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="15" class="text-center text-muted py-5">Pilih filter dan klik Tampilkan</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center" id="paginationWrap" style="display:none!important">
            <small class="text-muted" id="paginationInfo"></small>
            <nav><ul class="pagination pagination-sm mb-0" id="pagination"></ul></nav>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;
let sortCol = 'CREATEDATE';
let sortDir = 'DESC';

function setSort(col) {
    if (sortCol === col) {
        sortDir = sortDir === 'DESC' ? 'ASC' : 'DESC';
    } else {
        sortCol = col;
        sortDir = 'DESC';
    }
    updateSortIcons();
    loadData(1);
}

function updateSortIcons() {
    document.querySelectorAll('.sort-icon').forEach(el => {
        el.classList.remove('active');
        el.textContent = '⇅';
    });
    const el = document.getElementById('sort_' + sortCol);
    if (el) {
        el.classList.add('active');
        el.textContent = sortDir === 'DESC' ? '↓' : '↑';
    }
}

function getFilters(page) {
    const params = new URLSearchParams();
    params.set('page', page ?? currentPage);

    const filterType = document.getElementById('filterType').value;
    params.set('filter_type', filterType);

    if (filterType === 'tahun') {
        params.set('filter_year', document.getElementById('filterYear').value);
    } else if (filterType === 'bulan') {
        params.set('filter_month', document.getElementById('filterMonth').value);
        params.set('filter_year',  document.getElementById('filterYear').value);
    } else {
        params.set('start_date', document.getElementById('startDate').value);
        params.set('end_date',   document.getElementById('endDate').value);
    }

    const v = id => document.getElementById(id).value;
    if (v('filterKategori'))    params.set('kategori',            v('filterKategori'));
    if (v('filterRekomendasi')) params.set('filter_rekomendasi',  v('filterRekomendasi'));
    if (v('filterPersentase'))  params.set('persentase',          v('filterPersentase'));
    if (v('searchInput'))      params.set('search',     v('searchInput'));
    params.set('sort_col', sortCol);
    params.set('sort_dir', sortDir);

    [...document.getElementById('provinceFilter').selectedOptions].forEach(o => {
        params.append('province_ids[]', o.value);
    });

    return params;
}

function loadData(page) {
    currentPage = page ?? 1;
    document.getElementById('tableBody').innerHTML =
        '<tr><td colspan="15" class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Memuat...</td></tr>';

    const params = getFilters(currentPage);

    fetch('{{ route("compliance.data") }}?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (res.error) {
                alert('Error: ' + res.error);
                return;
            }
            renderSummary(res.summary, res.subtotal);
            renderTable(res.data, res.current_page, res.per_page);
            renderPagination(res.current_page, res.last_page, res.total, res.per_page);
        })
        .catch(e => alert('Gagal memuat data: ' + e));
}

function fmt(n) { return Number(n ?? 0).toLocaleString('id-ID'); }

function statCardBreakdown(label, total, cetak, rekam, color, border) {
    return `<div class="col">
        <div class="card shadow-sm stat-card text-center h-100 ${border ?? ''}">
            <div class="card-body py-2">
                <h6 class="text-muted mb-0" style="font-size:.8rem">${label}</h6>
                <h4 class="text-${color} fw-bold mb-0">${fmt(total)}</h4>
                <div class="d-flex justify-content-center gap-2 mt-1">
                    <small class="text-muted">📄 Cetak: <strong>${fmt(cetak)}</strong></small>
                    <small class="text-muted">🎬 Rekam: <strong>${fmt(rekam)}</strong></small>
                </div>
            </div>
        </div>
    </div>`;
}

function statCard(label, value, color, border) {
    return `<div class="col">
        <div class="card shadow-sm stat-card text-center h-100 ${border ?? ''}">
            <div class="card-body py-2">
                <h6 class="text-muted mb-0" style="font-size:.8rem">${label}</h6>
                <h4 class="text-${color} fw-bold mb-0">${fmt(value)}</h4>
            </div>
        </div>
    </div>`;
}

function renderSummary(s, sub) {
    document.getElementById('summarySection').style.display = '';

    const makeCards = (d, bordered) => {
        const b = (cls) => bordered ? `border-top border-3 border-${cls}` : '';
        return [
            statCard('Total Penerbit', d.TOTAL_PENERBIT, 'primary', b('primary')),
            statCard('Total ISBN',     d.TOTAL_ISBN,     'info',    b('info')),
            statCardBreakdown('Sudah KCKR', d.TOTAL_SUDAH_KCKR, d.TOTAL_SUDAH_CETAK, d.TOTAL_SUDAH_REKAM, 'success', b('success')),
            statCardBreakdown('Belum KCKR', d.TOTAL_BELUM_KCKR, d.TOTAL_BELUM_CETAK, d.TOTAL_BELUM_REKAM, 'warning', b('warning')),
            statCardBreakdown('Terlambat', d.TOTAL_TERLAMBAT, d.TOTAL_TERLAMBAT_CETAK, d.TOTAL_TERLAMBAT_REKAM, 'danger', b('danger')),
        ].join('');
    };

    document.getElementById('summaryCards').innerHTML  = makeCards(s, false);
    document.getElementById('subtotalCards').innerHTML = makeCards(sub, true);
}

function pctBadge(pct) {
    const p = parseFloat(pct ?? 0);
    const cls = p >= 81 ? 'bg-success' : p >= 61 ? 'bg-info text-dark' : p >= 41 ? 'bg-warning text-dark' : p >= 21 ? 'bg-secondary' : 'bg-danger';
    return `<span class="badge ${cls}">${p.toFixed(1)}%</span>`;
}

function kategoriBadge(k) {
    if (k === 'Pemerintah') return `<span class="badge bg-info">Pemerintah</span>`;
    if (k === 'Swasta')     return `<span class="badge bg-warning text-dark">Swasta</span>`;
    return `<span class="badge bg-secondary">Lainnya</span>`;
}

function detailUrl(penerbitId) {
    const params = getFilters(1);
    // hanya kirim filter tanggal
    ['page','sort_col','sort_dir','kategori','persentase','search'].forEach(k => params.delete(k));
    // hapus province_ids[] dari params
    const cleaned = new URLSearchParams();
    for (const [k, v] of params.entries()) {
        if (!k.startsWith('province_ids')) cleaned.append(k, v);
    }
    return `{{ url('/compliance/detail') }}/${penerbitId}?${cleaned.toString()}`;
}

function renderTable(rows, page, perPage) {
    const tbody = document.getElementById('tableBody');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="15" class="text-center text-muted py-4"><i class="fas fa-inbox"></i> Tidak ada data</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map((row, i) => {
        const no = (page - 1) * perPage + i + 1;
        const alamat = row.ALAMAT ? `<br><small class="text-muted">📍 ${row.ALAMAT}</small>` : '';
        return `<tr>
            <td>${no}</td>
            <td><a href="${detailUrl(row.ID)}" class="text-decoration-none text-dark"><strong>${row.NAME ?? ''}</strong></a>${alamat}</td>
            <td>${kategoriBadge(row.KATEGORI)}</td>
            <td>${row.PROVINSI ?? ''}</td>
            <td class="text-center"><span class="badge bg-primary">${row.JUMLAHJUDUL ?? 0}</span></td>
            <td class="text-center"><span class="badge bg-success">${row.JUMLAHSUDAHKCKR ?? 0}</span></td>
            <td class="text-center text-muted">${row.SUDAHKCKR_CETAK ?? 0}</td>
            <td class="text-center text-muted">${row.SUDAHKCKR_REKAM ?? 0}</td>
            <td class="text-center"><span class="badge bg-warning text-dark">${row.JUMLAHBELUMKCKR ?? 0}</span></td>
            <td class="text-center text-muted">${row.BELUMKCKR_CETAK ?? 0}</td>
            <td class="text-center text-muted">${row.BELUMKCKR_REKAM ?? 0}</td>
            <td class="text-center"><span class="badge bg-danger">${row.JUMLAHTERLAMBATKCKR ?? 0}</span></td>
            <td class="text-center"><span class="badge bg-success">${row.JUMLAHTEPATWAKTUKCKR ?? 0}</span></td>
            <td class="text-center">${pctBadge(row.PERSENTASE_KCKR)}</td>
            <td class="text-center">${(row.JUMLAHTERLAMBATKCKR > 0 && parseFloat(row.PERSENTASE_KCKR) <= 20)
                ? `<span class="badge" style="background:#fd7e14">Blokir SS KCKR</span>`
                : `<span class="badge bg-success">Baik</span>`}</td>
        </tr>`;
    }).join('');
}

function renderPagination(current, last, total, perPage) {
    const wrap = document.getElementById('paginationWrap');
    const info = document.getElementById('paginationInfo');
    const ul   = document.getElementById('pagination');
    document.getElementById('totalBadge').textContent = fmt(total) + ' penerbit ditemukan';

    if (last <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.removeProperty('display');

    const from = (current - 1) * perPage + 1;
    const to   = Math.min(current * perPage, total);
    info.textContent = `Menampilkan ${fmt(from)}–${fmt(to)} dari ${fmt(total)} data`;

    let pages = '';

    const btn = (page, label, disabled, active) =>
        `<li class="page-item ${disabled ? 'disabled' : ''} ${active ? 'active' : ''}">
            <a class="page-link" ${!disabled ? `onclick="goToPage(${page})"` : ''}>${label}</a>
        </li>`;

    pages += btn(current - 1, '‹', current <= 1, false);

    const start = Math.max(1, current - 2);
    const end   = Math.min(last, current + 2);

    if (start > 1) { pages += btn(1, '1', false, false); if (start > 2) pages += btn(null, '…', true, false); }
    for (let i = start; i <= end; i++) pages += btn(i, i, false, i === current);
    if (end < last) { if (end < last - 1) pages += btn(null, '…', true, false); pages += btn(last, last, false, false); }

    pages += btn(current + 1, '›', current >= last, false);

    ul.innerHTML = pages;
}

function goToPage(page) {
    document.getElementById('tableBody').scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => loadData(page), 300);
}

function onFilterTypeChange() {
    const type = document.getElementById('filterType').value;
    const map = { tahun: ['wrapTahun'], bulan: ['wrapBulan','wrapTahun'], range: ['wrapRangeFrom','wrapRangeTo'] };
    ['wrapTahun','wrapBulan','wrapRangeFrom','wrapRangeTo'].forEach(id => {
        const el = document.getElementById(id);
        const active = (map[type] || []).includes(id);
        el.classList.toggle('d-none', !active);
        el.querySelectorAll('input,select').forEach(inp => inp.disabled = !active);
    });
}

function resetFilter() {
    sortCol = 'CREATEDATE';
    sortDir = 'DESC';
    updateSortIcons();
    document.getElementById('filterType').value        = 'tahun';
    document.getElementById('filterYear').value        = '2025';
    document.getElementById('filterKategori').value     = '';
    document.getElementById('filterRekomendasi').value = '';
    document.getElementById('filterPersentase').value  = '';
    document.getElementById('searchInput').value       = '';
    [...document.getElementById('provinceFilter').options].forEach(o => o.selected = false);
    onFilterTypeChange();
    document.getElementById('summarySection').style.display = 'none';
    document.getElementById('tableBody').innerHTML = '<tr><td colspan="10" class="text-center text-muted py-5">Pilih filter dan klik Tampilkan</td></tr>';
    document.getElementById('paginationWrap').style.display = 'none';
    document.getElementById('totalBadge').textContent = '';
}

function goToDashboard() {
    const allowed = ['filter_type','filter_year','filter_month','start_date','end_date'];
    const params  = getFilters(1);
    const out     = new URLSearchParams();
    for (const [k, v] of params.entries()) {
        if (allowed.includes(k) || k.startsWith('province_ids')) out.append(k, v);
    }
    window.location.href = '{{ route("dashboard") }}?' + out.toString();
}

let _exportPollInterval = null;
let _exportAnimInterval = null;

function doExport(withDetail) {
    const token = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    const params = getFilters(1);
    params.delete('page');
    params.delete('sort_col');
    params.delete('sort_dir');
    if (withDetail) params.set('with_detail', '1');
    params.set('download_token', token);

    // Tampilkan modal
    const steps = withDetail
        ? ['step1','step2','step3','step4','step5']
        : ['step1','step2','step4','step5'];
    if (!withDetail) document.getElementById('step3').style.display = 'none';
    else             document.getElementById('step3').style.display = '';

    // Timeline progress (detik): ringkasan ~10s, +judul ~60s
    const totalSec  = withDetail ? 90 : 15;
    const stepTimes = withDetail
        ? [0, 2, 8, 65, 80]   // kapan tiap step "active" (detik)
        : [0, 2, null, 8, 12];

    // Reset state
    document.querySelectorAll('#exportStepList li').forEach(li => {
        li.classList.remove('done','active');
    });
    setExportProgress(0, 'Memulai...');

    const modal = new bootstrap.Modal(document.getElementById('exportModal'));
    modal.show();

    // Mulai download
    window.location.href = '{{ route("compliance.export") }}?' + params.toString();

    // Animasi progress
    const startTime = Date.now();
    clearInterval(_exportAnimInterval);
    _exportAnimInterval = setInterval(() => {
        const elapsed = (Date.now() - startTime) / 1000;
        const pct     = Math.min(90, Math.round(elapsed / totalSec * 90));
        setExportProgress(pct, getProgressLabel(elapsed, withDetail));

        // Tandai step sebagai active/done
        steps.forEach((sid, idx) => {
            const t = stepTimes[idx];
            if (t === null) return;
            const nextT = stepTimes[idx + 1] ?? totalSec + 1;
            const el = document.getElementById(sid);
            if (!el) return;
            if (elapsed >= nextT) {
                el.classList.remove('active'); el.classList.add('done');
            } else if (elapsed >= t) {
                el.classList.remove('done'); el.classList.add('active');
            }
        });
    }, 500);

    // Poll cookie untuk deteksi selesai
    clearInterval(_exportPollInterval);
    _exportPollInterval = setInterval(() => {
        if (document.cookie.includes('dl_' + token)) {
            // Selesai!
            clearInterval(_exportPollInterval);
            clearInterval(_exportAnimInterval);
            setExportProgress(100, 'File berhasil dikirim!');
            document.querySelectorAll('#exportStepList li').forEach(li => {
                li.classList.remove('active'); li.classList.add('done');
            });
            document.getElementById('exportProgressBar').classList.remove('progress-bar-animated');
            // Hapus cookie
            document.cookie = 'dl_' + token + '=; Max-Age=0; path=/';
            setTimeout(() => modal.hide(), 1500);
        }
    }, 800);

    // Timeout 5 menit — tutup modal meski cookie belum ada
    setTimeout(() => {
        clearInterval(_exportPollInterval);
        clearInterval(_exportAnimInterval);
        modal.hide();
    }, 300000);
}

function setExportProgress(pct, label) {
    document.getElementById('exportProgressBar').style.width = pct + '%';
    document.getElementById('exportProgressPct').textContent  = pct + '%';
    document.getElementById('exportProgressLabel').textContent = label;
}

function getProgressLabel(sec, withDetail) {
    if (sec < 2)  return 'Menghubungi server...';
    if (sec < 8)  return 'Mengambil data penerbit...';
    if (withDetail && sec < 65) return 'Memproses detail judul...';
    if (sec < (withDetail ? 80 : 12)) return 'Membuat file Excel...';
    return 'Mengirim file ke browser...';
}

// Init
onFilterTypeChange();

@if(request()->hasAny(['filter_type', 'filter_year', 'filter_month', 'start_date', 'province_ids']))
document.addEventListener('DOMContentLoaded', function() { loadData(1); });
@endif
</script>
@endsection
