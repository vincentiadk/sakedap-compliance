@extends('layouts.app')

@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
body { background-color: #f8f9fa; }
.card { border: none; border-radius: 12px; }
.stat-card { transition: transform .15s; }
.stat-card:hover { transform: translateY(-3px); }
.badge-patuh-0  { background-color: #dc3545; }
.badge-patuh-1  { background-color: #fd7e14; }
.badge-patuh-2  { background-color: #ffc107; color: #000; }
.badge-patuh-3  { background-color: #0dcaf0; color: #000; }
.badge-patuh-4  { background-color: #198754; }
.progress-bar-striped { animation: progress-bar-stripes 1s linear infinite; }
</style>

<div class="container-fluid mt-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0">📊 Dashboard Kepatuhan Penerbit KCKR</h2>
            @if($isV2 ?? false)
                <span class="badge bg-primary ms-1">Mode 2026+ — berbasis Tanggal Terbit</span>
            @endif
        </div>
        @php
            $toCompliance = array_filter([
                'filter_type'  => $dateFilter['type'] ?? 'tahun',
                'filter_year'  => request('filter_year'),
                'filter_month' => request('filter_month'),
                'start_date'   => request('start_date'),
                'end_date'     => request('end_date'),
            ]);
            if (!empty($provinceIds)) {
                $toCompliance['province_ids'] = $provinceIds;
            }
            $detailRoute = ($isV2 ?? false) ? route('compliance_v2.index') : route('compliance.index');
        @endphp
        <a href="{{ $detailRoute }}?{{ http_build_query($toCompliance) }}" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-table"></i> Lihat Detail
        </a>
    </div>

    @if(isset($error))
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    {{-- Filter --}}
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            @php
                $ft         = $dateFilter['type'] ?? 'tahun';
                $filterYear = request('filter_year', 2026);
                $filterMonth= request('filter_month', date('n'));
            @endphp
            <form method="GET" action="{{ route('dashboard') }}" class="row g-3 align-items-end">

                {{-- Tipe filter --}}
                <div class="col-md-5">
                    <label class="form-label fw-bold">Filter Tanggal</label>
                    <div class="btn-group w-100 mb-2" role="group">
                        @foreach(['tahun' => 'Per Tahun', 'bulan' => 'Per Bulan', 'range' => 'Range Tanggal'] as $val => $label)
                            <input type="radio" class="btn-check" name="filter_type" id="dash_type_{{ $val }}"
                                value="{{ $val }}" {{ $ft == $val ? 'checked' : '' }}
                                onchange="toggleDashFilter()">
                            <label class="btn btn-outline-primary btn-sm" for="dash_type_{{ $val }}">{{ $label }}</label>
                        @endforeach
                    </div>

                    <div id="dash_filter_tahun" class="dash-filter-section">
                        <select name="filter_year" class="form-select">
                            @for($y = 2026; $y >= 2015; $y--)
                                <option value="{{ $y }}" {{ $filterYear == $y ? 'selected' : '' }}>Tahun {{ $y }}</option>
                            @endfor
                        </select>
                    </div>

                    <div id="dash_filter_bulan" class="dash-filter-section" style="display:none">
                        <div class="row g-2">
                            <div class="col-6">
                                <select name="filter_month" class="form-select">
                                    @foreach(['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'] as $i => $bln)
                                        <option value="{{ $i+1 }}" {{ $filterMonth == ($i+1) ? 'selected' : '' }}>{{ $bln }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6">
                                <select name="filter_year" class="form-select">
                                    @for($y = 2026; $y >= 2015; $y--)
                                        <option value="{{ $y }}" {{ $filterYear == $y ? 'selected' : '' }}>{{ $y }}</option>
                                    @endfor
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="dash_filter_range" class="dash-filter-section" style="display:none">
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="date" name="start_date" class="form-control" value="{{ request('start_date', '2026-01-01') }}">
                            </div>
                            <div class="col-6">
                                <input type="date" name="end_date" class="form-control" value="{{ request('end_date', '2026-12-31') }}">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Provinsi --}}
                <div class="col-md-5">
                    <label class="form-label fw-bold">Provinsi</label>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="dashSelectAll(true)">Pilih Semua</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="dashSelectAll(false)">Hapus Semua</button>
                    </div>
                    <div style="max-height:180px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;padding:8px 12px;background:#fff;">
                        @foreach($provinces ?? [] as $prov)
                            <label style="display:block;font-size:.875rem;padding:2px 0;cursor:pointer;">
                                <input type="checkbox" name="province_ids[]" value="{{ $prov->ID }}"
                                    class="dash-prov-cb me-1"
                                    {{ in_array($prov->ID, $provinceIds ?? []) ? 'checked' : '' }}>
                                {{ $prov->NAMAPROPINSI }}
                            </label>
                        @endforeach
                    </div>
                    <small class="text-muted">Kosongkan = semua provinsi</small>
                </div>

                <div class="col-md-2 d-flex align-items-end gap-2 flex-column">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-sync-alt"></i> Tampilkan
                    </button>
                    <a href="{{ route('dashboard') }}?filter_type=tahun&filter_year={{ date('Y') }}"
                       class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Reset Filter
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleDashFilter() {
        const type = document.querySelector('input[name="filter_type"]:checked')?.value ?? 'tahun';
        document.querySelectorAll('.dash-filter-section').forEach(el => el.style.display = 'none');
        const el = document.getElementById('dash_filter_' + type);
        if (el) el.style.display = '';
    }
    // init on load
    function dashSelectAll(check) {
        document.querySelectorAll('.dash-prov-cb').forEach(cb => cb.checked = check);
    }

    document.addEventListener('DOMContentLoaded', function() {
        toggleDashFilter();
        const checked = document.querySelector('input[name="filter_type"]:checked');
        if (checked) {
            document.querySelectorAll('.dash-filter-section').forEach(el => el.style.display = 'none');
            const el = document.getElementById('dash_filter_' + checked.value);
            if (el) el.style.display = '';
        }
    });
    </script>

    @if(isset($total) && $total)
    {{-- Stat Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-building fa-lg"></i></div>
                    <h6 class="text-muted">Total Penerbit</h6>
                    <h2 class="text-primary fw-bold">{{ number_format($total->TOTAL_PENERBIT) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-book fa-lg"></i></div>
                    <h6 class="text-muted">Total Judul ISBN</h6>
                    <h2 class="text-info fw-bold">{{ number_format($total->TOTAL_JUDUL) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-check-circle fa-lg"></i></div>
                    <h6 class="text-muted">Total Sudah KCKR</h6>
                    <h2 class="text-success fw-bold">{{ number_format($total->TOTAL_KCKR) }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-chart-pie fa-lg"></i></div>
                    <h6 class="text-muted">Rata-rata Kepatuhan</h6>
                    <h2 class="fw-bold {{ ($total->RATA_RATA_KEPATUHAN ?? 0) >= 61 ? 'text-success' : (($total->RATA_RATA_KEPATUHAN ?? 0) >= 41 ? 'text-warning' : 'text-danger') }}">
                        {{ number_format($total->RATA_RATA_KEPATUHAN ?? 0, 1) }}%
                    </h2>
                </div>
            </div>
        </div>
    </div>

    {{-- Chart + Distribusi --}}
    <div class="row g-3 mb-4">
        {{-- Donut Chart --}}
        <div class="col-md-5">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-bold">🍩 Distribusi Kepatuhan</div>
                <div class="card-body d-flex justify-content-center align-items-center">
                    <canvas id="donutChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>

        {{-- Bar Chart --}}
        <div class="col-md-7">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-bold">📊 Jumlah Penerbit per Kategori Kepatuhan</div>
                <div class="card-body">
                    <canvas id="barChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Distribusi Cards --}}
    @php
        $levels = [
            'Sangat Tidak Patuh' => ['color' => 'danger',  'icon' => 'fa-times-circle',     'range' => '0% – 20%'],
            'Tidak Patuh'        => ['color' => 'orange',  'icon' => 'fa-exclamation-circle','range' => '21% – 40%'],
            'Cukup Patuh'        => ['color' => 'warning', 'icon' => 'fa-minus-circle',      'range' => '41% – 60%'],
            'Patuh'              => ['color' => 'info',    'icon' => 'fa-check-circle',      'range' => '61% – 80%'],
            'Sangat Patuh'       => ['color' => 'success', 'icon' => 'fa-star',              'range' => '81% – 100%'],
        ];
        $distribusiMap = collect($distribusi)->keyBy('KATEGORI_PATUH');
    @endphp

    <div class="row g-3">
        @foreach($levels as $nama => $level)
            @php
                $d      = $distribusiMap[$nama] ?? null;
                $jumlah = $d ? $d->JUMLAH : 0;
                $pct    = $total->TOTAL_PENERBIT > 0 ? round($jumlah / $total->TOTAL_PENERBIT * 100, 1) : 0;
                // hex warna per level — tidak bergantung Bootstrap class
                $hex = match($level['color']) {
                    'danger'  => '#dc3545',
                    'orange'  => '#fd7e14',
                    'warning' => '#ffc107',
                    'info'    => '#0dcaf0',
                    'success' => '#198754',
                    default   => '#6c757d',
                };
                $textDark = in_array($level['color'], ['warning']) ? 'color:#000' : '';
            @endphp
            <div class="col-md">
                <div class="card shadow-sm stat-card h-100" style="border-top:3px solid {{ $hex }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge" style="background:{{ $hex }};{{ $textDark }}">{{ $level['range'] }}</span>
                            <i class="fas {{ $level['icon'] }} fa-lg" style="color:{{ $hex }}"></i>
                        </div>
                        <h6 class="fw-bold">{{ $nama }}</h6>
                        <h3 class="fw-bold mb-1" style="color:{{ $hex }}">{{ number_format($jumlah) }}</h3>
                        <small class="text-muted">penerbit ({{ $pct }}%)</small>
                        <div class="progress mt-2" style="height:6px">
                            <div class="progress-bar" style="width:{{ $pct }}%;background:{{ $hex }}"></div>
                        </div>
                        @if($d)
                            <hr class="my-2">
                            <small class="text-muted">
                                📚 {{ number_format($d->TOTAL_JUDUL) }} judul &nbsp;|&nbsp;
                                ✅ {{ number_format($d->TOTAL_KCKR) }} KCKR &nbsp;|&nbsp;
                                📈 avg {{ $d->RATA_RATA_PCT }}%
                            </small>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Section Status Terbit (hanya tampil jika mode 2026+) ── --}}
    @if(($isV2 ?? false) && isset($total) && $total)
    <div class="row g-3 mt-2">
        <div class="col-12">
            <h5 class="fw-bold text-primary border-bottom pb-2 mb-3">
                📋 Status Terbit {{ request('filter_year', date('Y')) }}
            </h5>
        </div>

        {{-- Sudah Terbit --}}
        <div class="col-md">
            <div class="card shadow-sm stat-card h-100 border-top border-3 border-success">
                <div class="card-body text-center">
                    <div class="text-success mb-1"><i class="fas fa-check-circle fa-lg"></i></div>
                    <h6 class="text-muted">Sudah Terbit</h6>
                    <h3 class="text-success fw-bold">{{ number_format($total->TOTAL_TERBIT ?? 0) }}</h3>
                    <small class="text-muted">judul</small>
                </div>
            </div>
        </div>

        {{-- Belum Terbit --}}
        <div class="col-md">
            <div class="card shadow-sm stat-card h-100 border-top border-3 border-secondary">
                <div class="card-body text-center">
                    <div class="text-secondary mb-1"><i class="fas fa-hourglass-half fa-lg"></i></div>
                    <h6 class="text-muted">Belum Terbit</h6>
                    <h3 class="text-secondary fw-bold">{{ number_format($total->TOTAL_BELUM_TERBIT ?? 0) }}</h3>
                    <small class="text-muted">judul</small>
                </div>
            </div>
        </div>

        {{-- Hutang Terbit --}}
        <div class="col-md">
            <div class="card shadow-sm stat-card h-100 border-top border-3 border-warning">
                <div class="card-body text-center">
                    <div class="text-warning mb-1"><i class="fas fa-exclamation-triangle fa-lg"></i></div>
                    <h6 class="text-muted">Hutang Terbit</h6>
                    <h3 class="text-warning fw-bold">{{ number_format($total->TOTAL_HUTANG_TERBIT ?? 0) }}</h3>
                    <small class="text-muted">judul melewati deadline terbit</small>
                </div>
            </div>
        </div>

        {{-- Lewat Teguran --}}
        <div class="col-md">
            <div class="card shadow-sm stat-card h-100 border-top border-3 border-danger">
                <div class="card-body text-center">
                    <div class="text-danger mb-1"><i class="fas fa-bell fa-lg"></i></div>
                    <h6 class="text-muted">Lewat Batas Teguran</h6>
                    <h3 class="text-danger fw-bold">{{ number_format($total->TOTAL_LEWAT_TEGURAN ?? 0) }}</h3>
                    <small class="text-muted">judul melewati +30 hari teguran</small>
                </div>
            </div>
        </div>

        {{-- Belum KCKR --}}
        <div class="col-md">
            <div class="card shadow-sm stat-card h-100 border-top border-3 border-orange" style="border-color:#fd7e14!important">
                <div class="card-body text-center">
                    <div class="mb-1" style="color:#fd7e14"><i class="fas fa-file-invoice fa-lg"></i></div>
                    <h6 class="text-muted">Belum KCKR</h6>
                    <h3 class="fw-bold" style="color:#fd7e14">{{ number_format($total->TOTAL_BELUM_KCKR ?? 0) }}</h3>
                    <small class="text-muted">sudah terbit, belum setor KCKR</small>
                </div>
            </div>
        </div>
    </div>
    @endif

    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
@if(isset($distribusi) && count($distribusi) > 0)
    const labels  = ['Sangat Tidak Patuh', 'Tidak Patuh', 'Cukup Patuh', 'Patuh', 'Sangat Patuh'];
    const colors  = ['#dc3545', '#fd7e14', '#ffc107', '#0dcaf0', '#198754'];
    const distMap = {};
    @foreach($distribusi as $d)
        distMap['{{ $d->KATEGORI_PATUH }}'] = {{ $d->JUMLAH }};
    @endforeach
    const counts = labels.map(l => distMap[l] ?? 0);

    // Donut
    new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: counts, backgroundColor: colors, borderWidth: 2 }] },
        options: {
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
            cutout: '60%',
        }
    });

    // Bar
    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Jumlah Penerbit',
                data: counts,
                backgroundColor: colors,
                borderRadius: 6,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            },
        }
    });
@endif
</script>
@endsection
