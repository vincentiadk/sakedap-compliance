@extends('layouts.app')

@section('content')
<div class="container-fluid px-4">

    {{-- Back + Header --}}
    <div class="d-flex align-items-start gap-3 mb-3">
        <a href="{{ url()->previous() }}" class="btn btn-sm btn-outline-secondary flex-shrink-0">&larr; Kembali</a>

        <div class="flex-grow-1">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                    <h5 class="mb-1 fw-bold">{{ $penerbit->NAME }}</h5>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="badge bg-secondary">{{ $kategoriLabel }}</span>
                        @if($penerbit->NOSIUP)
                            <span class="badge bg-light text-dark border">No. SIUP: {{ $penerbit->NOSIUP }}</span>
                        @endif
                        <span class="badge bg-primary">Compliance Gabungan</span>
                    </div>
                </div>
                <button class="btn btn-sm btn-success flex-shrink-0" onclick="doExport()">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                </button>
            </div>

            <div class="row g-2" style="font-size:.82rem">
                <div class="col-md-4">
                    <div class="d-flex gap-2">
                        <i class="bi bi-geo-alt-fill text-danger mt-1 flex-shrink-0"></i>
                        <div>
                            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Alamat</div>
                            <div>{{ $penerbit->ALAMAT ?? '-' }}</div>
                            <div class="text-muted">
                                {{ $penerbit->CITY }}{{ $penerbit->KODEPOS ? ', ' . $penerbit->KODEPOS : '' }}
                                &mdash; {{ $penerbit->PROVINSI }}
                            </div>
                        </div>
                    </div>
                </div>

                @if($penerbit->KONTAK1 || $penerbit->TELP1 || $penerbit->EMAIL1)
                <div class="col-md-3">
                    <div class="d-flex gap-2">
                        <i class="bi bi-person-fill text-primary mt-1 flex-shrink-0"></i>
                        <div>
                            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Narahubung 1</div>
                            @if($penerbit->KONTAK1) <div class="fw-semibold">{{ $penerbit->KONTAK1 }}</div> @endif
                            @if($penerbit->TELP1)
                                <div><i class="bi bi-telephone-fill text-muted" style="font-size:.7rem"></i>
                                    <a href="tel:{{ $penerbit->TELP1 }}" class="text-decoration-none text-dark">{{ $penerbit->TELP1 }}</a>
                                </div>
                            @endif
                            @if($penerbit->EMAIL1)
                                <div><i class="bi bi-envelope-fill text-muted" style="font-size:.7rem"></i>
                                    <a href="mailto:{{ $penerbit->EMAIL1 }}" class="text-decoration-none">{{ $penerbit->EMAIL1 }}</a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                @endif

                @if($penerbit->WEBSITE)
                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <i class="bi bi-globe text-success mt-1 flex-shrink-0"></i>
                        <div>
                            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Website</div>
                            <a href="{{ Str::startsWith($penerbit->WEBSITE, 'http') ? $penerbit->WEBSITE : 'http://'.$penerbit->WEBSITE }}"
                               target="_blank" rel="noopener" class="text-decoration-none text-truncate d-block" style="max-width:160px">
                                {{ $penerbit->WEBSITE }}
                            </a>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
    <hr class="mt-0 mb-3">

    {{-- Summary Cards --}}
    @if($summary)
    <div class="mb-3">
        <div class="alert alert-light border py-1 px-3 mb-2 d-inline-flex align-items-center gap-2" style="font-size:.78rem">
            <i class="bi bi-info-circle text-primary"></i>
            Ringkasan keseluruhan — tidak terpengaruh filter tabel.
            Status Terbit hanya dari data 2026+. KCKR dari semua tahun.
        </div>

        <div class="d-flex align-items-stretch gap-0 flex-wrap">

            {{-- Terbit (2026+) --}}
            <div class="d-flex align-items-center gap-1 pe-3 me-1">
                <div class="text-muted fw-semibold me-2" style="font-size:.65rem;letter-spacing:.05em;text-transform:uppercase;writing-mode:vertical-rl;transform:rotate(180deg)">📄 Terbit 2026+</div>
                @foreach([
                    ['val' => $summary->TOTAL,         'label' => 'Total Judul',   'color' => '#6c757d'],
                    ['val' => $summary->SUDAH_TERBIT,  'label' => 'Sudah Terbit',  'color' => '#198754'],
                    ['val' => $summary->BELUM_TERBIT,  'label' => 'Belum Terbit',  'color' => '#adb5bd'],
                    ['val' => $summary->HUTANG_TERBIT, 'label' => 'Hutang Terbit', 'color' => '#ffc107'],
                    ['val' => $summary->LEWAT_TEGURAN, 'label' => 'Lewat Teguran', 'color' => '#dc3545'],
                ] as $card)
                <div class="text-center px-3 py-2 bg-white rounded shadow-sm border-top border-2" style="min-width:90px;border-color:{{ $card['color'] }}!important">
                    <div class="fs-5 fw-bold" style="color:{{ $card['color'] }}">{{ $card['val'] }}</div>
                    <small class="text-muted" style="font-size:.7rem">{{ $card['label'] }}</small>
                </div>
                @endforeach
            </div>

            <div class="vr mx-2 opacity-25"></div>

            {{-- KCKR (semua tahun) --}}
            <div class="d-flex align-items-center gap-1 ps-2">
                <div class="text-muted fw-semibold me-2" style="font-size:.65rem;letter-spacing:.05em;text-transform:uppercase;writing-mode:vertical-rl;transform:rotate(180deg)">✅ KCKR Semua</div>
                @foreach([
                    ['val' => $summary->SUDAH_KCKR,         'label' => 'Sudah KCKR',      'color' => '#0dcaf0'],
                    ['val' => $summary->BELUM_KCKR ?? 0,    'label' => 'Tagihan KCKR',    'color' => '#fd7e14'],
                    ['val' => $summary->TERLAMBAT_KCKR ?? 0,'label' => 'Terlambat KCKR',  'color' => '#dc3545'],
                ] as $card)
                <div class="text-center px-3 py-2 bg-white rounded shadow-sm border-top border-2" style="min-width:90px;border-color:{{ $card['color'] }}!important">
                    <div class="fs-5 fw-bold" style="color:{{ $card['color'] }}">{{ $card['val'] }}</div>
                    <small class="text-muted" style="font-size:.7rem">{{ $card['label'] }}</small>
                </div>
                @endforeach
                @php
                    $pct      = (float)($summary->PERSENTASE_KCKR ?? 0);
                    $pctColor = $pct >= 80 ? '#198754' : ($pct >= 50 ? '#ffc107' : '#dc3545');
                @endphp
                <div class="text-center px-3 py-2 bg-white rounded shadow-sm border-top border-2" style="min-width:110px;border-color:{{ $pctColor }}!important">
                    <div class="fs-5 fw-bold" style="color:{{ $pctColor }}">{{ $pct }}%</div>
                    <small class="text-muted" style="font-size:.7rem">% Kepatuhan</small>
                </div>
            </div>

        </div>
    </div>
    @endif

    {{-- Filter --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('compliance_v3.detail', $penerbit->ID) }}" class="row g-2 align-items-end">
                <input type="hidden" name="filter_type"  value="{{ $dateFilter['type'] }}">
                @if($dateFilter['type'] === 'tahun')
                    <input type="hidden" name="filter_year" value="{{ request('filter_year', date('Y')) }}">
                @elseif($dateFilter['type'] === 'bulan')
                    <input type="hidden" name="filter_year"  value="{{ request('filter_year',  date('Y')) }}">
                    <input type="hidden" name="filter_month" value="{{ request('filter_month', 1) }}">
                @else
                    <input type="hidden" name="start_date" value="{{ request('start_date') }}">
                    <input type="hidden" name="end_date"   value="{{ request('end_date') }}">
                @endif

                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Status</label>
                    <select class="form-select form-select-sm" name="filter_status">
                        <option value="">Semua</option>
                        <option value="terbit"       {{ $filters['filterStatus']==='terbit'       ? 'selected' : '' }}>Sudah Terbit (2026+)</option>
                        <option value="belum_terbit" {{ $filters['filterStatus']==='belum_terbit' ? 'selected' : '' }}>Belum Terbit (2026+)</option>
                        <option value="sudah_kckr"   {{ $filters['filterStatus']==='sudah_kckr'   ? 'selected' : '' }}>Sudah KCKR</option>
                        <option value="belum_kckr"   {{ $filters['filterStatus']==='belum_kckr'   ? 'selected' : '' }}>Belum KCKR</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Jenis Media</label>
                    <select class="form-select form-select-sm" name="filter_jenis">
                        <option value="">Semua</option>
                        <option value="cetak" {{ $filters['filterJenis']==='cetak' ? 'selected' : '' }}>Karya Cetak</option>
                        <option value="rekam" {{ $filters['filterJenis']==='rekam' ? 'selected' : '' }}>Karya Rekam</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Hutang Terbit</label>
                    <select class="form-select form-select-sm" name="filter_hutang">
                        <option value="">Semua</option>
                        <option value="ya" {{ $filters['filterHutang']==='ya' ? 'selected' : '' }}>Ada Hutang (2026+)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Lewat Teguran</label>
                    <select class="form-select form-select-sm" name="filter_teguran">
                        <option value="">Semua</option>
                        <option value="ya" {{ $filters['filterTeguran']==='ya' ? 'selected' : '' }}>Lewat Teguran (2026+)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">Terlambat KCKR</label>
                    <select class="form-select form-select-sm" name="filter_terlambat">
                        <option value="">Semua</option>
                        <option value="ya" {{ ($filters['filterTerlambat'] ?? '')==='ya' ? 'selected' : '' }}>Terlambat</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-1">Judul</label>
                    <input type="text" class="form-control form-control-sm" name="search_judul"
                           value="{{ $filters['searchJudul'] }}" placeholder="Cari judul...">
                </div>
                <div class="col-auto">
                    <label class="form-label form-label-sm mb-1">ISBN</label>
                    <input type="text" class="form-control form-control-sm" name="search_isbn"
                           value="{{ $filters['searchIsbn'] }}" placeholder="No ISBN...">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm mt-3">Filter</button>
                    <a href="{{ route('compliance_v3.detail', $penerbit->ID) }}?filter_type={{ $dateFilter['type'] }}&filter_year={{ request('filter_year', date('Y')) }}"
                       class="btn btn-outline-secondary btn-sm mt-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Tabel Judul --}}
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" style="font-size:.8rem">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Judul</th>
                            <th>Jilid</th>
                            <th>ISBN</th>
                            <th>Keterangan</th>
                            <th>Jenis</th>
                            <th>Tgl Daftar</th>
                            <th>Deadline Terbit</th>
                            <th>Tgl Terbit</th>
                            <th>Status Terbit</th>
                            <th>Batas Teguran</th>
                            <th>Deadline KCKR</th>
                            <th>Tgl KCKR</th>
                            <th>Status KCKR</th>
                            <th>Terlambat KCKR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($titles as $i => $row)
                        @php
                            $isPre26 = (bool) ($row->IS_PRE2026 ?? false);
                            $statusTerbitColor = $isPre26 ? 'secondary' : match($row->STATUS_TERBIT) {
                                'Terbit'        => 'success',
                                'Hutang Terbit' => 'warning',
                                'Lewat Teguran' => 'danger',
                                default         => 'secondary',
                            };
                            $statusKckrColor = match($row->STATUS_KCKR) {
                                'Sudah'        => 'success',
                                'Belum Terbit' => 'secondary',
                                default        => 'warning text-dark',
                            };
                            $trClass = (!$isPre26 && ($row->STATUS_TERBIT === 'Lewat Teguran' || $row->STATUS_KCKR === 'Belum'))
                                ? 'table-danger'
                                : ($row->STATUS_KCKR === 'Sudah' ? 'table-success' : ($isPre26 ? 'table-light' : ''));
                        @endphp
                        <tr class="{{ $trClass }}">
                            <td class="text-muted">{{ ($page - 1) * $perPage + $i + 1 }}</td>
                            <td>{{ $row->TITLE ?? '-' }}</td>
                            <td>{{ $row->JILID_VOLUME ?? '-' }}</td>
                            <td><code style="font-size:.75rem">{{ $row->ISBN_NO }}</code></td>
                            <td class="text-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                {{ $row->KETERANGAN ?? '-' }}
                            </td>
                            <td>
                                @if($row->JENIS_MEDIA === '1')
                                    <span class="badge bg-primary">Cetak</span>
                                @else
                                    <span class="badge" style="background:#6f42c1">Rekam</span>
                                @endif
                            </td>
                            <td>{{ $row->TGL_DAFTAR ? date('d/m/Y', strtotime($row->TGL_DAFTAR)) : '-' }}</td>
                            {{-- Deadline & Status Terbit: hanya 2026+ --}}
                            <td class="{{ $isPre26 ? 'text-muted' : '' }}">
                                {{ ($isPre26 || !$row->DEADLINE_TERBIT) ? '-' : date('d/m/Y', strtotime($row->DEADLINE_TERBIT)) }}
                            </td>
                            <td>
                                @if(!$isPre26 && $row->TANGGAL_TERBIT)
                                    <span class="text-success fw-semibold">{{ date('d/m/Y', strtotime($row->TANGGAL_TERBIT)) }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $statusTerbitColor }}">{{ $row->STATUS_TERBIT }}</span>
                                @if($isPre26)
                                    <small class="text-muted ms-1" style="font-size:.65rem">(pra-2026)</small>
                                @endif
                            </td>
                            <td class="text-muted">
                                {{ (!$isPre26 && $row->BATAS_TEGURAN) ? date('d/m/Y', strtotime($row->BATAS_TEGURAN)) : '-' }}
                            </td>
                            <td class="text-muted">
                                {{ $row->DEADLINE_KCKR ? date('d/m/Y', strtotime($row->DEADLINE_KCKR)) : '-' }}
                            </td>
                            <td>
                                @if($row->RECEIVED_DATE_KCKR)
                                    {{ date('d/m/Y', strtotime($row->RECEIVED_DATE_KCKR)) }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td><span class="badge bg-{{ $statusKckrColor }}">{{ $row->STATUS_KCKR }}</span></td>
                            <td class="text-center">
                                @if($row->TERLAMBAT_KCKR === 'Ya')
                                    <span class="badge bg-danger">Ya</span>
                                @else
                                    <span class="badge bg-success">Tidak</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="15" class="text-center py-4 text-muted">Tidak ada data sesuai filter.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($lastPage > 1)
        <div class="card-footer py-2">
            <nav>
                <ul class="pagination pagination-sm mb-0 justify-content-center">
                    @for($p = 1; $p <= $lastPage; $p++)
                        @if($p === 1 || $p === $lastPage || abs($p - $page) <= 2)
                        <li class="page-item {{ $p === $page ? 'active' : '' }}">
                            <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $p]) }}">{{ $p }}</a>
                        </li>
                        @elseif(abs($p - $page) === 3)
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                        @endif
                    @endfor
                </ul>
            </nav>
        </div>
        @endif
    </div>

</div>

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

<script>
function doExport() {
    const token  = Date.now().toString(36) + Math.random().toString(36).slice(2,6);
    const params = new URLSearchParams(window.location.search);
    params.set('download_token', token);
    const url    = '{{ route("compliance_v3.detail.export", $penerbit->ID) }}?' + params.toString();
    const modal  = new bootstrap.Modal(document.getElementById('exportModal'));
    const bar    = document.getElementById('exportProgress');
    let pct = 0;
    modal.show();
    window.location.href = url;
    const animI = setInterval(() => { if(pct < 85) { pct+=3; bar.style.width=pct+'%'; } }, 400);
    const pollI = setInterval(() => {
        if (document.cookie.includes('dl_' + token)) {
            clearInterval(animI); clearInterval(pollI);
            bar.style.width = '100%';
            document.getElementById('exportStatus').textContent = 'Selesai!';
            setTimeout(() => modal.hide(), 1000);
            document.cookie = 'dl_' + token + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        }
    }, 800);
    setTimeout(() => { clearInterval(animI); clearInterval(pollI); modal.hide(); }, 300000);
}
</script>
@endsection
