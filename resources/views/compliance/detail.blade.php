@extends('layouts.app')

@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background-color: #f8f9fa; }
.card { border: none; border-radius: 10px; }
.badge-cetak { background: #0d6efd; }
.badge-rekam { background: #6f42c1; }
.table th { font-size: .82rem; white-space: nowrap; }
.table td { font-size: .85rem; vertical-align: middle; }
.title-cell { max-width: 280px; }
</style>

<div class="container-fluid mt-4 px-4">

    {{-- Breadcrumb --}}
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('compliance.index') }}">Compliance</a></li>
            <li class="breadcrumb-item active">Detail Penerbit</li>
        </ol>
    </nav>

    {{-- Header --}}
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h4 class="mb-1 fw-bold">
                        <i class="fas fa-building text-primary me-2"></i>
                        {{ $penerbit->NAME }}
                    </h4>
                    <div class="text-muted" style="font-size:.9rem">
                        @if($penerbit->ALAMAT)
                            <i class="fas fa-map-marker-alt me-1"></i> {{ $penerbit->ALAMAT }}
                            @if($penerbit->CITY) – {{ $penerbit->CITY }} @endif
                            @if($penerbit->PROVINSI) ({{ $penerbit->PROVINSI }}) @endif
                        @endif
                    </div>
                    <div class="mt-1">
                        <span class="badge {{ $penerbit->KATEGORI_ID == 1 ? 'bg-info' : ($penerbit->KATEGORI_ID == 2 ? 'bg-warning text-dark' : 'bg-secondary') }}">
                            {{ $kategoriLabel }}
                        </span>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <div class="text-muted small mb-2">
                        @php
                            $typeLabel = match($dateFilter['type']) {
                                'bulan' => 'Bulan', 'range' => 'Range', default => 'Tahun',
                            };
                        @endphp
                        Filter: {{ $typeLabel }}
                        {{ date('d M Y', strtotime($dateFilter['start'])) }}
                        – {{ date('d M Y', strtotime($dateFilter['end'] . ' -1 day')) }}
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button onclick="doDetailExport()" class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </button>
                        <a href="{{ route('compliance.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Kembali
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary mini-cards --}}
    @php
        $totalJudul = (int)($summary->TOTAL ?? 0);
        $sudah      = (int)($summary->SUDAH ?? 0);
        $belum      = $totalJudul - $sudah;
        $terlambat  = (int)($summary->TERLAMBAT ?? 0);
        $cetak      = (int)($summary->CETAK ?? 0);
        $rekam      = $totalJudul - $cetak;
    @endphp
    <div class="row g-3 mb-4">
        <div class="col">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body py-2">
                    <h6 class="text-muted mb-0" style="font-size:.8rem">Total Judul</h6>
                    <h3 class="text-primary fw-bold mb-0">{{ number_format($totalJudul) }}</h3>
                    <small class="text-muted">📄 {{ $cetak }} cetak &nbsp;|&nbsp; 🎬 {{ $rekam }} rekam</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center h-100 border-top border-3 border-success">
                <div class="card-body py-2">
                    <h6 class="text-muted mb-0" style="font-size:.8rem">Sudah KCKR</h6>
                    <h3 class="text-success fw-bold mb-0">{{ number_format($sudah) }}</h3>
                    @if($totalJudul > 0)
                        <small class="text-muted">{{ round($sudah/$totalJudul*100,1) }}%</small>
                    @endif
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center h-100 border-top border-3 border-warning">
                <div class="card-body py-2">
                    <h6 class="text-muted mb-0" style="font-size:.8rem">Belum KCKR</h6>
                    <h3 class="text-warning fw-bold mb-0">{{ number_format($belum) }}</h3>
                    @if($totalJudul > 0)
                        <small class="text-muted">{{ round($belum/$totalJudul*100,1) }}%</small>
                    @endif
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center h-100 border-top border-3 border-danger">
                <div class="card-body py-2">
                    <h6 class="text-muted mb-0" style="font-size:.8rem">Terlambat</h6>
                    <h3 class="text-danger fw-bold mb-0">{{ number_format($terlambat) }}</h3>
                    @if($totalJudul > 0)
                        <small class="text-muted">{{ round($terlambat/$totalJudul*100,1) }}%</small>
                    @endif
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm text-center h-100 border-top border-3 border-info">
                <div class="card-body py-2">
                    <h6 class="text-muted mb-0" style="font-size:.8rem">% Kepatuhan</h6>
                    @php $pct = $totalJudul > 0 ? round($sudah/$totalJudul*100,1) : 0; @endphp
                    <h3 class="fw-bold mb-0 {{ $pct >= 81 ? 'text-success' : ($pct >= 61 ? 'text-info' : ($pct >= 41 ? 'text-warning' : ($pct >= 21 ? 'text-secondary' : 'text-danger'))) }}">
                        {{ $pct }}%
                    </h3>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter pencarian judul --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center" style="cursor:pointer" onclick="toggleDetailFilter()">
            <span class="fw-bold"><i class="fas fa-search me-2"></i>Filter</span>
            <i class="fas fa-chevron-down" id="detailFilterChevron"></i>
        </div>
        <div id="detailFilterBody" style="display:none">
            <div class="card-body">
                <form method="GET" action="{{ request()->url() }}">
                    {{-- Teruskan date filter params sebagai hidden --}}
                    <input type="hidden" name="filter_type"  value="{{ $dateFilter['type'] }}">
                    @if($dateFilter['type'] === 'tahun')
                        <input type="hidden" name="filter_year"  value="{{ request('filter_year', 2026) }}">
                    @elseif($dateFilter['type'] === 'bulan')
                        <input type="hidden" name="filter_year"  value="{{ request('filter_year', 2026) }}">
                        <input type="hidden" name="filter_month" value="{{ request('filter_month', 1) }}">
                    @else
                        <input type="hidden" name="start_date" value="{{ request('start_date') }}">
                        <input type="hidden" name="end_date"   value="{{ request('end_date') }}">
                    @endif

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold" style="font-size:.85rem">Judul</label>
                            <input type="text" name="search_judul" class="form-control form-control-sm"
                                value="{{ $filters['searchJudul'] }}" placeholder="Cari judul...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold" style="font-size:.85rem">ISBN</label>
                            <input type="text" name="search_isbn" class="form-control form-control-sm"
                                value="{{ $filters['searchIsbn'] }}" placeholder="Cari ISBN...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold" style="font-size:.85rem">Pengarang</label>
                            <input type="text" name="search_pengarang" class="form-control form-control-sm"
                                value="{{ $filters['searchPengarang'] }}" placeholder="Cari pengarang...">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold" style="font-size:.85rem">Jilid</label>
                            <input type="text" name="search_jilid" class="form-control form-control-sm"
                                value="{{ $filters['searchJilid'] }}" placeholder="Cari jilid...">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label fw-bold" style="font-size:.85rem">Jenis</label>
                            <select name="filter_jenis" class="form-select form-select-sm">
                                <option value="">-- Semua --</option>
                                <option value="cetak" {{ $filters['filterJenis'] === 'cetak' ? 'selected' : '' }}>📄 Cetak</option>
                                <option value="rekam" {{ $filters['filterJenis'] === 'rekam' ? 'selected' : '' }}>🎬 Rekam</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold" style="font-size:.85rem">Status KCKR</label>
                            <select name="filter_status" class="form-select form-select-sm">
                                <option value="">-- Semua --</option>
                                <option value="sudah" {{ $filters['filterStatus'] === 'sudah' ? 'selected' : '' }}>✅ Sudah</option>
                                <option value="belum" {{ $filters['filterStatus'] === 'belum' ? 'selected' : '' }}>⏳ Belum</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold" style="font-size:.85rem">Terlambat</label>
                            <select name="filter_terlambat" class="form-select form-select-sm">
                                <option value="">-- Semua --</option>
                                <option value="ya"    {{ $filters['filterTerlambat'] === 'ya'    ? 'selected' : '' }}>⚠️ Ya</option>
                                <option value="tidak" {{ $filters['filterTerlambat'] === 'tidak' ? 'selected' : '' }}>✓ Tidak</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            @php
                                $dMin = $dateFilter['start'];
                                $dMax = date('Y-m-d', strtotime($dateFilter['end'] . ' -1 day'));
                            @endphp
                            <label class="form-label fw-bold" style="font-size:.85rem">
                                Tgl Daftar
                                <small class="text-muted fw-normal">({{ $dMin }} s/d {{ $dMax }})</small>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="date" name="tgl_daftar_start" class="form-control"
                                    min="{{ $dMin }}" max="{{ $dMax }}"
                                    value="{{ $filters['tglDaftarStart'] ?: $dMin }}">
                                <span class="input-group-text">–</span>
                                <input type="date" name="tgl_daftar_end" class="form-control"
                                    min="{{ $dMin }}" max="{{ $dMax }}"
                                    value="{{ $filters['tglDaftarEnd'] ?: $dMax }}">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold" style="font-size:.85rem">Tgl KCKR</label>
                            <div class="input-group input-group-sm">
                                <input type="date" name="tgl_kckr_start" class="form-control"
                                    value="{{ $filters['tglKckrStart'] }}">
                                <span class="input-group-text">–</span>
                                <input type="date" name="tgl_kckr_end" class="form-control"
                                    value="{{ $filters['tglKckrEnd'] }}">
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i> Cari
                        </button>
                        <a href="{{ request()->url() }}?{{ http_build_query(array_filter([
                            'filter_type'  => $dateFilter['type'],
                            'filter_year'  => request('filter_year'),
                            'filter_month' => request('filter_month'),
                            'start_date'   => request('start_date'),
                            'end_date'     => request('end_date'),
                        ])) }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card shadow-sm" id="tableCard">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Daftar Judul ({{ number_format($total) }})</h5>
            @if($lastPage > 1)
                <small>Hal {{ $page }} dari {{ $lastPage }}</small>
            @endif
        </div>
        <div class="table-responsive">
            @if(count($titles) === 0)
                <div class="text-center text-muted py-5">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>Tidak ada data judul yang cocok</p>
                </div>
            @else
            <table class="table table-hover table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px">No</th>
                        <th>Judul</th>
                        <th>Pengarang</th>
                        <th style="width:80px">Jilid</th>
                        <th style="width:130px">ISBN</th>
                        <th>Keterangan</th>
                        <th style="width:80px" class="text-center">Jenis</th>
                        <th style="width:95px" class="text-center">Tgl Daftar</th>
                        <th style="width:95px" class="text-center">Deadline</th>
                        <th style="width:95px" class="text-center">Tgl KCKR</th>
                        <th style="width:80px" class="text-center">Status</th>
                        <th style="width:80px" class="text-center">Terlambat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($titles as $i => $row)
                    @php
                        $isKckr      = $row->STATUS_KCKR === 'Sudah';
                        $isTerlambat = $row->IS_TERLAMBAT === 'Ya';
                        $isCetak     = $row->JENIS_MEDIA === '1';
                        $trClass     = $isTerlambat ? 'table-danger' : ($isKckr ? 'table-success' : '');
                    @endphp
                    <tr class="{{ $trClass }}">
                        <td class="text-center text-muted">{{ ($page - 1) * $perPage + $i + 1 }}</td>
                        <td class="title-cell">
                            <strong>{{ $row->TITLE ?? '(Tanpa Judul)' }}</strong>
                            @if($row->TAHUN_TERBIT)
                                <br><small class="text-muted">{{ $row->TAHUN_TERBIT }}{{ $row->TEMPAT_TERBIT ? ', ' . $row->TEMPAT_TERBIT : '' }}</small>
                            @endif
                        </td>
                        <td><small>{{ $row->KEPENG ?? '-' }}</small></td>
                        <td class="text-center"><small>{{ $row->JILID_VOLUME ?? '-' }}</small></td>
                        <td class="text-center"><small class="font-monospace">{{ $row->ISBN_NO ?? '-' }}</small></td>
                        <td><small class="text-muted">{{ $row->KETERANGAN ?? '-' }}</small></td>
                        <td class="text-center">
                            @if($isCetak)
                                <span class="badge badge-cetak">📄 Cetak</span>
                            @else
                                <span class="badge badge-rekam">🎬 Rekam</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <small>{{ $row->TGL_DAFTAR ? date('d/m/Y', strtotime($row->TGL_DAFTAR)) : '-' }}</small>
                        </td>
                        <td class="text-center">
                            <small class="{{ $isTerlambat ? 'text-danger fw-bold' : '' }}">
                                {{ $row->DEADLINE_KCKR ? date('d/m/Y', strtotime($row->DEADLINE_KCKR)) : '-' }}
                            </small>
                        </td>
                        <td class="text-center">
                            <small>{{ $row->RECEIVED_DATE_KCKR ? date('d/m/Y', strtotime($row->RECEIVED_DATE_KCKR)) : '-' }}</small>
                        </td>
                        <td class="text-center">
                            @if($isKckr)
                                <span class="badge bg-success">✅ Sudah</span>
                            @else
                                <span class="badge bg-warning text-dark">⏳ Belum</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($isTerlambat)
                                <span class="badge bg-danger">⚠️ Ya</span>
                            @else
                                <span class="badge bg-success">✓ Tidak</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>

        {{-- Pagination --}}
        @if($lastPage > 1)
        @php $qParams = request()->except('page'); @endphp
        <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted">
                Menampilkan {{ number_format(($page - 1) * $perPage + 1) }}–{{ number_format(min($page * $perPage, $total)) }}
                dari {{ number_format($total) }} judul
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item {{ $page <= 1 ? 'disabled' : '' }}">
                        <a class="page-link" href="{{ $page > 1 ? request()->fullUrlWithQuery(array_merge($qParams, ['page' => $page - 1])) . '#tableCard' : '#' }}">‹</a>
                    </li>
                    @php $pStart = max(1, $page - 2); $pEnd = min($lastPage, $page + 2); @endphp
                    @if($pStart > 1)
                        <li class="page-item"><a class="page-link" href="{{ request()->fullUrlWithQuery(array_merge($qParams, ['page' => 1])) }}#tableCard">1</a></li>
                        @if($pStart > 2)<li class="page-item disabled"><span class="page-link">…</span></li>@endif
                    @endif
                    @for($i = $pStart; $i <= $pEnd; $i++)
                        <li class="page-item {{ $i === $page ? 'active' : '' }}">
                            <a class="page-link" href="{{ request()->fullUrlWithQuery(array_merge($qParams, ['page' => $i])) }}#tableCard">{{ $i }}</a>
                        </li>
                    @endfor
                    @if($pEnd < $lastPage)
                        @if($pEnd < $lastPage - 1)<li class="page-item disabled"><span class="page-link">…</span></li>@endif
                        <li class="page-item"><a class="page-link" href="{{ request()->fullUrlWithQuery(array_merge($qParams, ['page' => $lastPage])) }}#tableCard">{{ $lastPage }}</a></li>
                    @endif
                    <li class="page-item {{ $page >= $lastPage ? 'disabled' : '' }}">
                        <a class="page-link" href="{{ $page < $lastPage ? request()->fullUrlWithQuery(array_merge($qParams, ['page' => $page + 1])) . '#tableCard' : '#' }}">›</a>
                    </li>
                </ul>
            </nav>
        </div>
        @endif
    </div>
</div>

{{-- Export Loading Modal --}}
<div class="modal fade" id="exportModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#1976D2;color:#fff">
                <h5 class="modal-title"><i class="fas fa-file-excel me-2"></i>Menyiapkan File Excel</h5>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small id="exportProgressLabel" class="text-muted">Memulai...</small>
                        <small id="exportProgressPct" class="text-muted fw-bold">0%</small>
                    </div>
                    <div class="progress" style="height:12px;border-radius:6px">
                        <div id="exportProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                            role="progressbar" style="width:0%;transition:width .4s ease"></div>
                    </div>
                </div>
                <div class="mt-2 text-center text-muted" style="font-size:.8rem">
                    <i class="fas fa-info-circle"></i>
                    Jangan tutup halaman ini. File akan otomatis terunduh.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function doDetailExport() {
    const token  = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    const base   = '{{ route("compliance.detail.export", $penerbit->ID) }}';
    const query  = '{{ http_build_query(request()->except("page")) }}';
    const url    = base + '?' + query + '&download_token=' + token;

    const modal  = new bootstrap.Modal(document.getElementById('exportModal'));
    modal.show();

    let pct = 0;
    document.getElementById('exportProgressBar').style.width = '0%';
    document.getElementById('exportProgressPct').textContent = '0%';
    document.getElementById('exportProgressLabel').textContent = 'Menghubungi server...';

    window.location.href = url;

    const startTime = Date.now();
    const animInt = setInterval(() => {
        const elapsed = (Date.now() - startTime) / 1000;
        pct = Math.min(88, Math.round(elapsed / 15 * 88));
        document.getElementById('exportProgressBar').style.width = pct + '%';
        document.getElementById('exportProgressPct').textContent = pct + '%';
        document.getElementById('exportProgressLabel').textContent =
            elapsed < 3  ? 'Mengambil data dari database...' :
            elapsed < 10 ? 'Memproses daftar judul...' :
                           'Membuat file Excel...';
    }, 400);

    const pollInt = setInterval(() => {
        if (document.cookie.includes('dl_' + token)) {
            clearInterval(animInt);
            clearInterval(pollInt);
            document.getElementById('exportProgressBar').style.width = '100%';
            document.getElementById('exportProgressPct').textContent = '100%';
            document.getElementById('exportProgressLabel').textContent = 'File berhasil dikirim!';
            document.getElementById('exportProgressBar').classList.remove('progress-bar-animated');
            document.cookie = 'dl_' + token + '=; Max-Age=0; path=/';
            setTimeout(() => modal.hide(), 1500);
        }
    }, 800);

    setTimeout(() => { clearInterval(animInt); clearInterval(pollInt); modal.hide(); }, 300000);
}

function toggleDetailFilter() {
    const body    = document.getElementById('detailFilterBody');
    const chevron = document.getElementById('detailFilterChevron');
    const isOpen  = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    chevron.className  = isOpen ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}

// Buka filter otomatis kalau ada filter aktif
@php
    $hasFilter = array_filter([
        $filters['searchJudul'], $filters['searchIsbn'], $filters['searchPengarang'],
        $filters['searchJilid'], $filters['filterJenis'], $filters['filterStatus'],
        $filters['filterTerlambat'],
        $filters['tglDaftarStart'], $filters['tglDaftarEnd'],
        $filters['tglKckrStart'], $filters['tglKckrEnd'],
    ]);
@endphp
@if(count($hasFilter) > 0)
document.addEventListener('DOMContentLoaded', function() {
    toggleDetailFilter();
});
@endif
</script>
@endsection
