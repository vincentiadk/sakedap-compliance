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

@media print {
    @page { size: A4 landscape; margin: 10mm; }
    nav, footer, .btn, form { display: none !important; }
    body { background: white !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    .container-fluid { padding: 0 !important; }
    canvas { max-width: 100% !important; }
}
</style>

@php
    $bulanNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $ft         = $dateFilter['type'] ?? 'tahun';
    $filterYear = request('filter_year', date('Y'));
    $filterMonth= (int) request('filter_month', date('n'));
    if ($ft === 'tahun') {
        $periodeLabel = 'Tahun ' . $filterYear;
    } elseif ($ft === 'bulan') {
        $periodeLabel = $bulanNames[$filterMonth] . ' ' . $filterYear;
    } else {
        // end_date di controller sudah +1 hari (exclusive), tampilkan -1 hari untuk label
        $endDisplay   = isset($dateFilter['end'])
            ? \Carbon\Carbon::parse($dateFilter['end'])->subDay()->format('Y-m-d')
            : '-';
        $periodeLabel = ($dateFilter['start'] ?? '-') . ' s.d. ' . $endDisplay;
    }
    $selectedProvinces = $provinceIds ?? [];
    $provinceLabel = '';
    if (!empty($selectedProvinces) && !empty($provinces)) {
        $provNames = array_map(fn($p) => $p->NAME ?? $p->NAMA ?? '', array_filter((array)$provinces, fn($p) => in_array($p->ID ?? $p->id ?? 0, $selectedProvinces)));
        $provinceLabel = implode(', ', $provNames);
    }
@endphp

<div class="container-fluid mt-4 px-4" id="dashboardContent">

{{-- Judul PDF (hanya tampil saat print) --}}
<div id="pdfHeader" style="display:none" class="mb-3 border-bottom pb-2">
    <h4 class="fw-bold mb-1">Dashboard Kepatuhan Penerbit KCKR</h4>
    <div class="text-muted" style="font-size:.9rem">
        <strong>Periode:</strong> {{ $periodeLabel }}
        @if($provinceLabel)
            &nbsp;|&nbsp; <strong>Provinsi:</strong> {{ $provinceLabel }}
        @endif
        &nbsp;|&nbsp; <strong>Dicetak:</strong> {{ \Carbon\Carbon::now('Asia/Jakarta')->translatedFormat('d F Y, H:i') }} WIB
    </div>
</div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0">📊 Dashboard Kepatuhan Penerbit</h2>
            @if($isMixed ?? false)
                <span class="badge bg-warning text-dark ms-1">Mode Campuran — pra-2026 + 2026+</span>
            @elseif($hasV2 ?? false)
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
            $detailRoute = route('compliance_v3.index');
        @endphp
        <div class="d-flex gap-2">
            <button onclick="downloadPDF()" class="btn btn-danger btn-sm">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <a href="{{ $detailRoute }}?{{ http_build_query($toCompliance) }}" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-table"></i> Lihat Detail
            </a>
        </div>
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
        document.querySelectorAll('.dash-filter-section').forEach(el => {
            const active = el.id === 'dash_filter_' + type;
            el.style.display = active ? '' : 'none';
            // disable inputs di seksi yg tidak aktif agar tidak ikut submit
            el.querySelectorAll('input, select').forEach(inp => inp.disabled = !active);
        });
    }

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
    @php
        $belumKckrTop = isset($total->TOTAL_BELUM_KCKR)
            ? $total->TOTAL_BELUM_KCKR
            : (($total->TOTAL_JUDUL ?? 0) - ($total->TOTAL_KCKR ?? 0));
    @endphp
    {{-- Stat Cards --}}
    <div class="row g-3 mb-4">
        <div class="col">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-building fa-lg"></i></div>
                    <h6 class="text-muted">Total Penerbit</h6>
                    <h2 class="text-primary fw-bold">{{ number_format($total->TOTAL_PENERBIT) }}</h2>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-book fa-lg"></i></div>
                    <h6 class="text-muted">Total Judul ISBN</h6>
                    <h2 class="text-info fw-bold">{{ number_format($total->TOTAL_JUDUL) }}</h2>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="text-muted mb-1"><i class="fas fa-check-circle fa-lg"></i></div>
                    <h6 class="text-muted">Total Sudah KCKR</h6>
                    <h2 class="text-success fw-bold">{{ number_format($total->TOTAL_KCKR) }}</h2>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body text-center">
                    <div class="mb-1" style="color:#fd7e14"><i class="fas fa-file-invoice fa-lg"></i></div>
                    <h6 class="text-muted">Belum KCKR</h6>
                    <h2 class="fw-bold" style="color:#fd7e14">{{ number_format($belumKckrTop) }}</h2>
                    <small class="text-muted" style="font-size:.72rem">judul belum setor KCKR</small>
                </div>
            </div>
        </div>
        <div class="col">
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
                    <canvas id="barChart"></canvas>
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

    {{-- ── Section Status Terbit (tampil jika ada data 2026+) ── --}}
    @if(($hasV2 ?? false) && isset($total) && $total)
    <div class="row g-3 mt-2">
        <div class="col-12">
            <h5 class="fw-bold text-primary border-bottom pb-2 mb-3">
                📋 Status Terbit
                @if($isMixed ?? false)
                    <small class="text-muted fw-normal">(data 2026+ saja)</small>
                @endif
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

        {{-- Rekomendasi Sistem – Pie Chart + Keterangan --}}
        @php
            $blokirTerbit = $total->PENERBIT_LEWAT_TEGURAN ?? 0;
            $blokirKckr   = $total->PENERBIT_BLOKIR_KCKR   ?? 0;
            $baik         = $total->PENERBIT_BAIK           ?? 0;
            $rekTotal     = $blokirTerbit + $blokirKckr + $baik;
        @endphp
        <div class="col-12 mt-3">
            <div class="card shadow-sm border-top border-3 border-secondary">
                <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.05em">🎯 Rekomendasi Sistem</span>
                    <small class="text-muted">{{ number_format($rekTotal) }} penerbit</small>
                </div>
                <div class="card-body">
                    <div class="row g-4 align-items-center">
                        {{-- Pie Chart --}}
                        <div class="col-md-5 d-flex justify-content-center">
                            <canvas id="rekChart" style="max-height:300px;max-width:380px"></canvas>
                        </div>
                        {{-- Keterangan --}}
                        <div class="col-md-7">
                            <h6 class="fw-semibold mb-3 text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:.05em">Keterangan Status Rekomendasi</h6>
                            <div class="mb-3 p-3 rounded" style="background:#fff5f5;border-left:4px solid #dc3545">
                                <div class="d-flex align-items-start gap-2">
                                    <span style="color:#dc3545;font-size:1.1rem">🔴</span>
                                    <div>
                                        <strong class="text-danger">Blokir Konfirmasi Terbit</strong>
                                        <span class="badge bg-danger ms-1">{{ number_format($blokirTerbit) }} penerbit</span>
                                        <p class="mb-1 mt-1 small text-muted">Penerbit yang memiliki judul ISBN melewati <strong>+30 hari</strong> setelah batas konfirmasi terbit tanpa melakukan konfirmasi.</p>
                                        <p class="mb-0 small"><strong>Indikator:</strong> <code>Lewat Teguran &gt; 0</code></p>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3 p-3 rounded" style="background:#fff9f0;border-left:4px solid #fd7e14">
                                <div class="d-flex align-items-start gap-2">
                                    <span style="font-size:1.1rem">🟠</span>
                                    <div>
                                        <strong style="color:#d96000">Blokir SS KCKR</strong>
                                        <span class="badge ms-1" style="background:#fd7e14">{{ number_format($blokirKckr) }} penerbit</span>
                                        <p class="mb-1 mt-1 small text-muted">Penerbit yang terlambat menyerahkan KCKR dengan tingkat kepatuhan rendah (≤ 20%), namun belum masuk kategori Blokir Konfirmasi Terbit.</p>
                                        <p class="mb-0 small"><strong>Indikator:</strong> <code>Lewat Teguran = 0 AND Terlambat KCKR &gt; 0 AND % KCKR ≤ 20%</code></p>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3 p-3 rounded" style="background:#f0fff4;border-left:4px solid #198754">
                                <div class="d-flex align-items-start gap-2">
                                    <span style="font-size:1.1rem">🟢</span>
                                    <div>
                                        <strong class="text-success">Baik</strong>
                                        <span class="badge bg-success ms-1">{{ number_format($baik) }} penerbit</span>
                                        <p class="mb-1 mt-1 small text-muted">Penerbit yang patuh: tidak ada judul melewati batas teguran, dan tidak terlambat KCKR atau kepatuhan sudah di atas 20%.</p>
                                        <p class="mb-0 small"><strong>Indikator:</strong> <code>Lewat Teguran = 0 AND (Terlambat KCKR = 0 ATAU % KCKR &gt; 20%)</code></p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-2 rounded" style="background:#f8f9fa;border:1px dashed #dee2e6">
                                <small class="text-muted"><i class="fas fa-info-circle me-1"></i>
                                    <strong>Belum KCKR:</strong>
                                    @if($isMixed ?? false)
                                        Pra-2026 = semua judul tanpa KCKR; 2026+ = judul yang sudah konfirmasi terbit namun belum setor KCKR.
                                    @else
                                        Judul yang sudah konfirmasi terbit (2026+) namun belum menyerahkan KCKR.
                                    @endif
                                    Total: <strong style="color:#fd7e14">{{ number_format($total->TOTAL_BELUM_KCKR ?? 0) }} judul</strong>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Rekomendasi V1 (hanya mode murni s.d 2025) ── --}}
    @if(!($hasV2 ?? false) && isset($total) && $total)
    @php
        $blokirKckrV1 = $total->PENERBIT_BLOKIR_KCKR ?? 0;
        $baikV1       = $total->PENERBIT_BAIK         ?? 0;
        $rekTotalV1   = $blokirKckrV1 + $baikV1;
    @endphp
    <div class="row g-3 mt-2">
        <div class="col-12">
            <div class="card shadow-sm border-top border-3 border-secondary">
                <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.05em">🎯 Rekomendasi Sistem</span>
                    <small class="text-muted">{{ number_format($rekTotalV1) }} penerbit</small>
                </div>
                <div class="card-body">
                    <div class="row g-4 align-items-center">
                        {{-- Pie Chart --}}
                        <div class="col-md-5 d-flex justify-content-center">
                            <canvas id="rekChart" style="max-height:300px;max-width:380px"></canvas>
                        </div>
                        {{-- Keterangan V1 --}}
                        <div class="col-md-7">
                            <h6 class="fw-semibold mb-3 text-secondary text-uppercase" style="font-size:.75rem;letter-spacing:.05em">Keterangan Status Rekomendasi (Pra-2026)</h6>
                            <div class="mb-3 p-3 rounded" style="background:#fff9f0;border-left:4px solid #fd7e14">
                                <div class="d-flex align-items-start gap-2">
                                    <span style="font-size:1.1rem">🟠</span>
                                    <div>
                                        <strong style="color:#d96000">Blokir SS KCKR</strong>
                                        <span class="badge ms-1" style="background:#fd7e14">{{ number_format($blokirKckrV1) }} penerbit</span>
                                        <p class="mb-1 mt-1 small text-muted">Penerbit yang terlambat menyerahkan KCKR (berdasarkan 3 bulan dari tanggal ISBN diterima) dengan kepatuhan ≤ 20%.</p>
                                        <p class="mb-0 small"><strong>Indikator:</strong> <code>Terlambat KCKR &gt; 0 AND % KCKR ≤ 20%</code></p>
                                        <p class="mb-0 small text-muted">Deadline KCKR = tanggal ISBN + 3 bulan (berdasarkan kategori/jenis media)</p>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3 p-3 rounded" style="background:#f0fff4;border-left:4px solid #198754">
                                <div class="d-flex align-items-start gap-2">
                                    <span style="font-size:1.1rem">🟢</span>
                                    <div>
                                        <strong class="text-success">Baik</strong>
                                        <span class="badge bg-success ms-1">{{ number_format($baikV1) }} penerbit</span>
                                        <p class="mb-1 mt-1 small text-muted">Penerbit yang patuh terhadap kewajiban KCKR: tidak terlambat atau kepatuhan sudah di atas 20%.</p>
                                        <p class="mb-0 small"><strong>Indikator:</strong> <code>Terlambat KCKR = 0 ATAU % KCKR &gt; 20%</code></p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-2 rounded" style="background:#f8f9fa;border:1px dashed #dee2e6">
                                <small class="text-muted"><i class="fas fa-info-circle me-1"></i>
                                    <strong>Belum KCKR (Pra-2026):</strong> Semua judul ISBN yang belum menyerahkan KCKR sejak terbit.
                                    Total: <strong style="color:#fd7e14">{{ number_format(($total->TOTAL_JUDUL ?? 0) - ($total->TOTAL_KCKR ?? 0)) }} judul</strong>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// Daftarkan plugin datalabels global
Chart.register(ChartDataLabels);

@if(isset($distribusi) && count($distribusi) > 0)
    const labels  = ['Sangat Tidak Patuh', 'Tidak Patuh', 'Cukup Patuh', 'Patuh', 'Sangat Patuh'];
    const colors  = ['#dc3545', '#fd7e14', '#ffc107', '#0dcaf0', '#198754'];
    const distMap = {};
    @foreach($distribusi as $d)
        distMap['{{ $d->KATEGORI_PATUH }}'] = {{ $d->JUMLAH }};
    @endforeach
    const counts    = labels.map(l => distMap[l] ?? 0);
    const distTotal = counts.reduce((a,b) => a+b, 0);

    // Donut — label langsung di segmen
    new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: counts, backgroundColor: colors, borderWidth: 2 }] },
        options: {
            cutout: '55%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } },
                datalabels: {
                    color: '#fff',
                    font: { size: 11, weight: 'bold' },
                    formatter: (val, ctx) => {
                        if (val === 0) return '';
                        const pct = distTotal > 0 ? ((val / distTotal) * 100).toFixed(1) : 0;
                        return val.toLocaleString('id') + '\n' + pct + '%';
                    },
                    textAlign: 'center',
                    display: ctx => ctx.dataset.data[ctx.dataIndex] > 0,
                }
            }
        }
    });

    // Bar — angka di atas setiap batang
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
            responsive: true,
            layout: { padding: { top: 28 } },
            plugins: {
                legend: { display: false },
                datalabels: {
                    anchor: 'end',
                    align: 'top',
                    clip: false,
                    color: '#333',
                    font: { size: 12, weight: 'bold' },
                    formatter: val => val > 0 ? val.toLocaleString('id') : '',
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grace: '15%',
                }
            },
        }
    });
@endif

// Pie Chart Rekomendasi Sistem
@if(isset($total) && $total)
(function() {
    const rekEl = document.getElementById('rekChart');
    if (!rekEl) return;
    @if($hasV2 ?? false)
        const rekLabels = ['Blokir Konfirmasi Terbit', 'Blokir SS KCKR', 'Baik'];
        const rekData   = [{{ $blokirTerbit ?? 0 }}, {{ $blokirKckr ?? 0 }}, {{ $baik ?? 0 }}];
        const rekColors = ['#dc3545', '#fd7e14', '#198754'];
    @else
        const rekLabels = ['Blokir SS KCKR', 'Baik'];
        const rekData   = [{{ $total->PENERBIT_BLOKIR_KCKR ?? 0 }}, {{ $total->PENERBIT_BAIK ?? 0 }}];
        const rekColors = ['#fd7e14', '#198754'];
    @endif
    const rekTotal = rekData.reduce((a,b) => a+b, 0);
    new Chart(rekEl, {
        type: 'pie',
        data: {
            labels: rekLabels,
            datasets: [{ data: rekData, backgroundColor: rekColors, borderWidth: 2, hoverOffset: 10 }]
        },
        options: {
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 12 }, padding: 12, boxWidth: 14 }
                },
                datalabels: {
                    color: '#fff',
                    font: { size: 13, weight: 'bold' },
                    formatter: (val, ctx) => {
                        if (val === 0) return '';
                        const pct = rekTotal > 0 ? ((val / rekTotal) * 100).toFixed(1) : 0;
                        return val.toLocaleString('id') + '\n' + pct + '%';
                    },
                    textAlign: 'center',
                    display: ctx => ctx.dataset.data[ctx.dataIndex] > 0,
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const v   = ctx.parsed;
                            const pct = rekTotal > 0 ? ((v / rekTotal) * 100).toFixed(1) : 0;
                            return ` ${ctx.label}: ${v.toLocaleString('id')} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
})();
@endif

function downloadPDF() {
    const btn = document.querySelector('button[onclick="downloadPDF()"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';

    const el = document.getElementById('dashboardContent');

    const pdfHeader = document.getElementById('pdfHeader');
    pdfHeader.style.display = 'block';
    const hidden = el.querySelectorAll('.btn, form, .card.shadow-sm.mb-4');
    hidden.forEach(e => e.style.display = 'none');

    const SCALE = 2;

    html2canvas(el, {
        scale: SCALE,
        useCORS: true,
        allowTaint: true,
        logging: false,
        backgroundColor: '#ffffff',
        windowWidth: 1400,
        onclone: (doc) => {
            doc.querySelectorAll('canvas').forEach(c => {
                const original = document.getElementById(c.id);
                if (original) {
                    const img = doc.createElement('img');
                    img.src = original.toDataURL('image/png');
                    img.style.width  = original.offsetWidth  + 'px';
                    img.style.height = original.offsetHeight + 'px';
                    c.parentNode.replaceChild(img, c);
                }
            });
        }
    }).then(canvas => {
        pdfHeader.style.display = 'none';
        hidden.forEach(e => e.style.display = '');

        const { jsPDF } = window.jspdf;
        const imgData = canvas.toDataURL('image/jpeg', 0.92);

        // Buat PDF dengan ukuran persis mengikuti konten (1px = 0.264583mm @96dpi)
        const MM_PER_PX = 25.4 / 96;
        const pdfW = (canvas.width  / SCALE) * MM_PER_PX;
        const pdfH = (canvas.height / SCALE) * MM_PER_PX;

        const pdf = new jsPDF({
            orientation: pdfW > pdfH ? 'l' : 'p',
            unit: 'mm',
            format: [pdfW, pdfH]
        });
        pdf.addImage(imgData, 'JPEG', 0, 0, pdfW, pdfH, '', 'FAST');

        const now = new Date();
        const filename = `Dashboard-Kepatuhan-${now.getFullYear()}${String(now.getMonth()+1).padStart(2,'0')}${String(now.getDate()).padStart(2,'0')}.pdf`;
        pdf.save(filename);

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download PDF';
    }).catch(err => {
        pdfHeader.style.display = 'none';
        hidden.forEach(e => e.style.display = '');
        console.error(err);
        alert('Gagal: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-pdf"></i> Download PDF';
    });
}
</script>
@endsection
