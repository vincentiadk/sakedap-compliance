<?php

namespace App\Http\Controllers;

use App\Traits\OracleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Compliance 2026+: aturan baru berbasis tanggal_terbit.
 * - Deadline terbit : createdate + 28 hari kalender (≈ 20 hari kerja, tanpa libur nasional)
 * - Hutang terbit   : tanggal_terbit NULL & SYSDATE > deadline_terbit
 * - Lewat teguran   : tanggal_terbit NULL & SYSDATE > deadline_terbit + 30
 * - KCKR            : dihitung dari tanggal_terbit (bukan createdate)
 *                     jika tanggal_terbit NULL → "Belum Terbit", exclude dari kewajiban KCKR
 */
class ComplianceV2Controller extends Controller
{
    use OracleHelper;

    private const PER_PAGE = 25;

    // ─── Ekspresi SQL yang dipakai berulang ──────────────────────────────────

    /** Deadline wajib konfirmasi terbit (20 hari kerja ≈ 28 hari kalender) */
    private const EXPR_DEADLINE_TERBIT = 'PI.CREATEDATE + 28';

    /** Deadline KCKR dari tanggal_terbit (sama dengan aturan lama, ganti base date) */
    private function exprDeadlineKckr(string $base = 'PI.TANGGAL_TERBIT'): string
    {
        return "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS($base, 3)
                     ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS($base, 3)
                               ELSE ADD_MONTHS($base, 12) END END";
    }

    // ─── Helper query ────────────────────────────────────────────────────────

    private function buildDateWhere(string $start, string $end): string
    {
        return "AND PI.CREATEDATE >= TO_DATE('$start', 'YYYY-MM-DD')
                AND PI.CREATEDATE <  TO_DATE('$end',   'YYYY-MM-DD')";
    }

    private function parsePersentaseRange(string $persentase): array
    {
        return match($persentase) {
            '0-20'   => [0, 20],
            '21-40'  => [21, 40],
            '41-60'  => [41, 60],
            '61-80'  => [61, 80],
            '81-100' => [81, 100],
            default  => [0, 100],
        };
    }

    private function buildBaseQuery(
        string $dateWhere,
        string $provinceWhere,
        ?string $kategori,
        ?string $search,
        ?string $filterHutang,
        ?string $filterTeguran,
        ?string $filterKckr,
        ?string $persentase = null
    ): string {
        $kategoriWhere = !empty($kategori) ? "AND P.KATEGORI_ID = " . intval($kategori) : '';
        $searchWhere   = !empty($search)
            ? "AND UPPER(P.NAME) LIKE '%" . strtoupper(addslashes($search)) . "%'"
            : '';

        $dlTerbit  = self::EXPR_DEADLINE_TERBIT;
        $dlKckr    = $this->exprDeadlineKckr();

        // "Sudah terbit" = tanggal_terbit terisi ATAU received_date_kckr terisi
        // (kalau KCKR sudah diisi tanpa tanggal_terbit, berarti sudah pasti terbit)
        $sudahTerbit    = "(PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL)";
        $belumTerbit    = "(PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL)";

        $innerQuery = "
            SELECT
                P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY,
                P.KATEGORI_ID, P.PROVINCE_ID, P.CREATEDATE,
                CASE WHEN P.KATEGORI_ID = 1 THEN 'Pemerintah'
                     WHEN P.KATEGORI_ID = 2 THEN 'Swasta'
                     ELSE 'Lainnya' END as KATEGORI,

                -- Total judul (semua)
                COUNT(DISTINCT PI.ID) as TOTAL_JUDUL,

                -- Sudah terbit: tanggal_terbit terisi ATAU received_date_kckr terisi
                COUNT(DISTINCT CASE WHEN $sudahTerbit THEN PI.ID END) as JUDUL_TERBIT,
                COUNT(DISTINCT CASE WHEN $belumTerbit THEN PI.ID END) as JUDUL_BELUM_TERBIT,

                -- Hutang terbit: belum terbit (kedua NULL) & sudah lewat deadline 28 hr
                SUM(CASE WHEN $belumTerbit
                          AND SYSDATE > $dlTerbit
                         THEN 1 ELSE 0 END) as HUTANG_TERBIT,

                -- Melewati batas teguran: hutang + 30 hari kalender
                SUM(CASE WHEN $belumTerbit
                          AND SYSDATE > ($dlTerbit + 30)
                         THEN 1 ELSE 0 END) as LEWAT_TEGURAN,

                -- KCKR: received_date_kckr terisi = sudah kckr (tanpa syarat tanggal_terbit)
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL
                         THEN 1 ELSE 0 END) as SUDAH_KCKR,

                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL
                          AND PT.JENIS_MEDIA = '1'
                         THEN 1 ELSE 0 END) as SUDAH_KCKR_CETAK,

                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL
                          AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL)
                         THEN 1 ELSE 0 END) as SUDAH_KCKR_REKAM,

                -- Belum KCKR: sudah terbit tapi belum KCKR
                SUM(CASE WHEN $sudahTerbit
                          AND PI.RECEIVED_DATE_KCKR IS NULL
                         THEN 1 ELSE 0 END) as BELUM_KCKR,

                SUM(CASE WHEN $sudahTerbit
                          AND PI.RECEIVED_DATE_KCKR IS NULL
                          AND PT.JENIS_MEDIA = '1'
                         THEN 1 ELSE 0 END) as BELUM_KCKR_CETAK,

                SUM(CASE WHEN $sudahTerbit
                          AND PI.RECEIVED_DATE_KCKR IS NULL
                          AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL)
                         THEN 1 ELSE 0 END) as BELUM_KCKR_REKAM,

                -- Terlambat KCKR: hanya bisa dihitung kalau tanggal_terbit ada
                SUM(CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL AND (
                              (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $dlKckr)
                           OR (PI.RECEIVED_DATE_KCKR IS NULL    AND SYSDATE > $dlKckr)
                         ) THEN 1 ELSE 0 END) as TERLAMBAT_KCKR,

                -- Tepat waktu KCKR
                SUM(CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL
                          AND PI.RECEIVED_DATE_KCKR IS NOT NULL
                          AND PI.RECEIVED_DATE_KCKR <= $dlKckr
                         THEN 1 ELSE 0 END) as TEPAT_WAKTU_KCKR,

                -- % KCKR dari judul yang sudah terbit
                ROUND(
                    CASE WHEN COUNT(DISTINCT CASE WHEN $sudahTerbit THEN PI.ID END) > 0
                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END)
                           / COUNT(DISTINCT CASE WHEN $sudahTerbit THEN PI.ID END) * 100
                        ELSE 0 END, 1
                ) as PERSENTASE_KCKR

            FROM PENERBIT P
            LEFT JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                $dateWhere
                AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
            LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
            WHERE 1=1
                $provinceWhere
                $kategoriWhere
                $searchWhere
            GROUP BY P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY, P.KATEGORI_ID, P.PROVINCE_ID, P.CREATEDATE
            HAVING COUNT(DISTINCT PI.ID) > 0
        ";

        // Filter hutang/teguran/kckr/persentase diterapkan di outer query
        $outerWhere = '';
        if ($filterHutang === 'ya')     $outerWhere .= ' AND HUTANG_TERBIT > 0';
        if ($filterHutang === 'tidak')  $outerWhere .= ' AND HUTANG_TERBIT = 0';
        if ($filterTeguran === 'ya')    $outerWhere .= ' AND LEWAT_TEGURAN > 0';
        if ($filterTeguran === 'tidak') $outerWhere .= ' AND LEWAT_TEGURAN = 0';
        if ($filterKckr === 'sudah')    $outerWhere .= ' AND SUDAH_KCKR > 0';
        if ($filterKckr === 'belum')    $outerWhere .= ' AND BELUM_KCKR > 0';
        if (!empty($persentase)) {
            [$min, $max] = $this->parsePersentaseRange($persentase);
            $outerWhere .= " AND PERSENTASE_KCKR BETWEEN $min AND $max";
        }

        if ($outerWhere) {
            return "SELECT * FROM ($innerQuery) WHERE 1=1 $outerWhere";
        }

        return $innerQuery;
    }

    private function makeCacheKeyV2(Request $request, string $prefix): string
    {
        return $this->makeCacheKey($request, $prefix, [
            'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            'province_ids', 'kategori', 'search',
            'filter_hutang', 'filter_teguran', 'filter_kckr', 'persentase',
        ]);
    }

    // ─── Actions ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $conn      = $this->getOracleConnection();
            $provinces = array_map(
                fn($r) => (object) $r,
                Cache::remember('compliance_v2:provinces', 900, fn() =>
                    array_map(fn($r) => (array) $r, $this->fetchProvinces($conn))
                )
            );
            return view('compliance_v2.index', compact('provinces'));
        } catch (\Exception $e) {
            return view('compliance_v2.index', ['error' => 'Error: ' . $e->getMessage(), 'provinces' => []]);
        }
    }

    public function data(Request $request)
    {
        try {
            $conn        = $this->getOracleConnection();
            $page        = max(1, (int) $request->get('page', 1));
            $kategori    = $request->kategori      ?? null;
            $provinceIds = $request->province_ids  ?? [];
            $search      = trim($request->search   ?? '');
            $filterHutang  = $request->filter_hutang  ?? null;
            $filterTeguran = $request->filter_teguran ?? null;
            $filterKckr    = $request->filter_kckr    ?? null;
            $persentase    = $request->persentase     ?? null;
            $sortCol = $request->sort_col ?? 'NAME';
            $sortDir = $request->sort_dir ?? 'ASC';

            $dateFilter    = $this->parseDateFilter($request);
            $dateWhere     = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
            $provinceWhere = $this->buildProvinceWhere($provinceIds);

            $cacheKey = $this->makeCacheKeyV2($request, 'compliance_v2:data') . ':' . $page . ':' . strtolower($sortCol) . ':' . strtolower($sortDir);

            $cached = Cache::remember($cacheKey, 900, function() use (
                $conn, $dateWhere, $provinceWhere, $kategori, $search,
                $filterHutang, $filterTeguran, $filterKckr, $persentase, $page, $sortCol, $sortDir
            ) {
                $baseQuery = $this->buildBaseQuery(
                    $dateWhere, $provinceWhere, $kategori, $search,
                    $filterHutang, $filterTeguran, $filterKckr, $persentase
                );

                $allowed = ['NAME','TOTAL_JUDUL','JUDUL_TERBIT','HUTANG_TERBIT','LEWAT_TEGURAN',
                            'SUDAH_KCKR','BELUM_KCKR','PERSENTASE_KCKR'];
                $sortCol = in_array(strtoupper($sortCol), $allowed) ? strtoupper($sortCol) : 'NAME';
                $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

                $perPage = self::PER_PAGE;
                $offset  = ($page - 1) * $perPage;
                $end     = $offset + $perPage;

                $countRes = odbc_exec($conn, "SELECT COUNT(*) as TOTAL FROM ($baseQuery)");
                $total    = (int) odbc_result($countRes, 'TOTAL');

                $sql = "
                    SELECT * FROM (
                        SELECT a.*, ROWNUM as RN FROM (
                            $baseQuery ORDER BY $sortCol $sortDir
                        ) a WHERE ROWNUM <= $end
                    ) WHERE RN > $offset
                ";

                $result = odbc_exec($conn, $sql);
                $data   = [];
                while ($row = odbc_fetch_object($result)) {
                    $data[] = (array) $row;
                }

                return [
                    'data'         => $data,
                    'total'        => $total,
                    'current_page' => $page,
                    'last_page'    => max(1, (int) ceil($total / $perPage)),
                    'per_page'     => $perPage,
                ];
            });

            return response()->json([
                'data'         => array_map(fn($r) => (object) $r, $cached['data']),
                'total'        => $cached['total'],
                'current_page' => $cached['current_page'],
                'last_page'    => $cached['last_page'],
                'per_page'     => $cached['per_page'],
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function detail(Request $request, $id)
    {
        try {
            $conn       = $this->getOracleConnection();
            $penerbitId = (int) $id;
            $page       = max(1, (int) $request->get('page', 1));
            $perPage    = self::PER_PAGE;
            $dateFilter = $this->parseDateFilter($request);
            $dateWhere  = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);

            // Cache penerbit info
            $penerbit = (object) Cache::remember("compliance_v2:penerbit:$penerbitId", 900, function() use ($conn, $penerbitId) {
                $r = odbc_fetch_object(odbc_exec($conn, "
                    SELECT P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY, P.KODEPOS, P.KATEGORI_ID,
                           P.KONTAK1, P.TELP1, P.FAX1, P.EMAIL1,
                           P.KONTAK2, P.TELP2, P.FAX2, P.EMAIL2,
                           P.WEBSITE, P.NOSIUP
                    FROM PENERBIT P WHERE P.ID = $penerbitId
                "));
                return $r ? (array) $r : null;
            });
            if (!$penerbit || !isset($penerbit->ID)) abort(404, 'Penerbit tidak ditemukan');

            $filterStatus    = $request->filter_status    ?? '';
            $filterJenis     = $request->filter_jenis     ?? '';
            $filterHutang    = $request->filter_hutang    ?? '';
            $filterTeguran   = $request->filter_teguran   ?? '';
            $filterTerlambat = $request->filter_terlambat ?? '';
            $searchJudul     = trim($request->search_judul ?? '');
            $searchIsbn      = trim($request->search_isbn  ?? '');

            $dlTerbit = self::EXPR_DEADLINE_TERBIT;
            $dlKckr   = $this->exprDeadlineKckr();

            $fromJoin = "
                FROM PENERBIT P
                JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                    $dateWhere
                    AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                WHERE P.ID = $penerbitId
            ";

            $searchWhere = '';
            if ($searchJudul)   $searchWhere .= " AND UPPER(PT.TITLE) LIKE '%" . strtoupper(addslashes($searchJudul)) . "%'";
            if ($searchIsbn)    $searchWhere .= " AND UPPER(PI.ISBN_NO) LIKE '%" . strtoupper(addslashes($searchIsbn)) . "%'";
            if ($filterJenis === 'cetak') $searchWhere .= " AND PT.JENIS_MEDIA = '1'";
            if ($filterJenis === 'rekam') $searchWhere .= " AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL)";
            if ($filterStatus === 'terbit')       $searchWhere .= " AND (PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL)";
            if ($filterStatus === 'belum_terbit') $searchWhere .= " AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL";
            if ($filterStatus === 'sudah_kckr')   $searchWhere .= " AND PI.RECEIVED_DATE_KCKR IS NOT NULL";
            if ($filterStatus === 'belum_kckr')   $searchWhere .= " AND (PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL) AND PI.RECEIVED_DATE_KCKR IS NULL";
            if ($filterHutang === 'ya')      $searchWhere .= " AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dlTerbit";
            if ($filterTeguran === 'ya')     $searchWhere .= " AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlTerbit + 30)";
            if ($filterTerlambat === 'ya')   $searchWhere .= " AND PI.TANGGAL_TERBIT IS NOT NULL AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $dlKckr) OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dlKckr))";

            // Cache summary (tidak bergantung search/filter judul)
            $summaryKey = $this->makeCacheKey($request, "compliance_v2:detail:$penerbitId:summary", [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            ]);
            $summary = (object) Cache::remember($summaryKey, 900, function() use ($conn, $fromJoin, $dlTerbit) {
                $r = odbc_exec($conn, "
                    SELECT
                        COUNT(*) as TOTAL,
                        COUNT(CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 END) as SUDAH_TERBIT,
                        COUNT(CASE WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN 1 END) as BELUM_TERBIT,
                        COUNT(CASE WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dlTerbit THEN 1 END) as HUTANG_TERBIT,
                        COUNT(CASE WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlTerbit + 30) THEN 1 END) as LEWAT_TEGURAN,
                        COUNT(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 END) as SUDAH_KCKR,
                        COUNT(CASE WHEN (PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL) AND PI.RECEIVED_DATE_KCKR IS NULL THEN 1 END) as BELUM_KCKR
                    $fromJoin
                ");
                return (array) odbc_fetch_object($r);
            });

            // Cache data halaman (bergantung semua filter + page)
            $selectCols = "
                PI.ID, PI.ISBN_NO,
                PI.CREATEDATE      as TGL_DAFTAR,
                PI.TANGGAL_TERBIT,
                PI.RECEIVED_DATE_KCKR,
                PI.KETERANGAN,
                PT.TITLE, PT.KEPENG, PT.JENIS_MEDIA, PT.JILID_VOLUME,
                $dlTerbit          as DEADLINE_TERBIT,
                ($dlTerbit + 30)   as BATAS_TEGURAN,
                CASE
                    WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.TANGGAL_TERBIT IS NULL THEN 'Terbit'
                    WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > ($dlTerbit + 30) THEN 'Lewat Teguran'
                    WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > $dlTerbit        THEN 'Hutang Terbit'
                    WHEN PI.TANGGAL_TERBIT IS NULL                                THEN 'Belum Terbit'
                    ELSE 'Terbit'
                END as STATUS_TERBIT,
                CASE
                    WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah'
                    WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN 'Belum Terbit'
                    ELSE 'Belum'
                END as STATUS_KCKR,
                $dlKckr as DEADLINE_KCKR,
                CASE
                    WHEN PI.TANGGAL_TERBIT IS NULL THEN '-'
                    WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $dlKckr)
                      OR (PI.RECEIVED_DATE_KCKR IS NULL    AND SYSDATE > $dlKckr)
                    THEN 'Ya' ELSE 'Tidak'
                END as TERLAMBAT_KCKR
            ";

            $pageKey = $this->makeCacheKey($request, "compliance_v2:detail:$penerbitId:page", [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
                'filter_status', 'filter_jenis', 'filter_hutang', 'filter_teguran', 'filter_terlambat',
                'search_judul', 'search_isbn',
            ]) . ':' . $page;

            $cached = Cache::remember($pageKey, 900, function() use ($conn, $fromJoin, $searchWhere, $selectCols, $page, $perPage) {
                $countRes = odbc_exec($conn, "SELECT COUNT(*) as TOTAL $fromJoin $searchWhere");
                $total    = (int) (odbc_fetch_object($countRes)->TOTAL ?? 0);
                $lastPage = max(1, (int) ceil($total / $perPage));
                $page     = min($page, $lastPage);
                $offset   = ($page - 1) * $perPage;
                $end      = $offset + $perPage;

                $sql = "
                    SELECT * FROM (
                        SELECT a.*, ROWNUM as RN FROM (
                            SELECT $selectCols $fromJoin $searchWhere ORDER BY TGL_DAFTAR DESC
                        ) a WHERE ROWNUM <= $end
                    ) WHERE RN > $offset
                ";
                $result = odbc_exec($conn, $sql);
                $titles = [];
                while ($row = odbc_fetch_object($result)) {
                    $titles[] = (array) $row;
                }
                return compact('titles', 'total', 'lastPage', 'page');
            });

            $titles   = array_map(fn($r) => (object) $r, $cached['titles']);
            $total    = $cached['total'];
            $lastPage = $cached['lastPage'];
            $page     = $cached['page'];

            $kategoriLabel = match((int)$penerbit->KATEGORI_ID) {
                1 => 'Pemerintah', 2 => 'Swasta', default => 'Lainnya'
            };

            $filters = compact(
                'searchJudul', 'searchIsbn', 'filterStatus',
                'filterJenis', 'filterHutang', 'filterTeguran', 'filterTerlambat'
            );

            return view('compliance_v2.detail', compact(
                'penerbit', 'titles', 'dateFilter', 'kategoriLabel',
                'summary', 'total', 'page', 'perPage', 'lastPage', 'filters'
            ));

        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $conn          = $this->getOracleConnection();
        $provinceIds   = $request->province_ids  ?? [];
        $kategori      = $request->kategori      ?? null;
        $search        = trim($request->search   ?? '');
        $filterHutang  = $request->filter_hutang  ?? null;
        $filterTeguran = $request->filter_teguran ?? null;
        $filterKckr    = $request->filter_kckr    ?? null;
        $persentase    = $request->persentase     ?? null;

        $dateFilter    = $this->parseDateFilter($request);
        $dateWhere     = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
        $provinceWhere = $this->buildProvinceWhere($provinceIds);
        $baseQuery     = $this->buildBaseQuery(
            $dateWhere, $provinceWhere, $kategori, $search,
            $filterHutang, $filterTeguran, $filterKckr, $persentase
        );

        $label    = $this->buildFilterLabel($request, $provinceIds);
        $periode  = str_replace(['/', ' ', '–', '-'], ['', '_', '-', '_'], $label['periode']);
        $filename = 'Compliance2026_' . $periode . '_' . date('d-m-Y') . '.xlsx';

        $exportKey = $this->makeCacheKeyV2($request, 'compliance_v2:export');

        $rows = Cache::remember($exportKey, 900, function() use ($conn, $baseQuery) {
            $result = odbc_exec($conn, "SELECT * FROM ($baseQuery) ORDER BY NAME ASC");
            $data   = [];
            while ($row = odbc_fetch_object($result)) {
                $data[] = [
                    $row->NAME,
                    $row->KATEGORI,
                    $row->CITY,
                    $row->PROVINSI,
                    (int) $row->TOTAL_JUDUL,
                    (int) $row->JUDUL_TERBIT,
                    (int) $row->JUDUL_BELUM_TERBIT,
                    (int) $row->HUTANG_TERBIT,
                    (int) $row->LEWAT_TEGURAN,
                    (int) $row->SUDAH_KCKR,
                    (int) $row->SUDAH_KCKR_CETAK,
                    (int) $row->SUDAH_KCKR_REKAM,
                    (int) $row->BELUM_KCKR,
                    (int) $row->TERLAMBAT_KCKR,
                    (int) $row->TEPAT_WAKTU_KCKR,
                    (float) $row->PERSENTASE_KCKR,
                ];
            }
            return $data;
        });

        $headers = [
            'No','Nama Penerbit','Kategori','Kota','Provinsi',
            'Total Judul','Sudah Terbit','Belum Terbit',
            'Hutang Terbit','Lewat Teguran',
            'Sudah KCKR','Cetak','Rekam',
            'Belum KCKR','Terlambat KCKR','Tepat Waktu',
            '% KCKR',
        ];

        $i  = 1;
        $sp = $this->makeSpreadsheetV2($headers, function($add) use ($rows, &$i) {
            foreach ($rows as $r) {
                $add(array_merge([$i++], $r));
            }
        }, 'Ringkasan', 'LAPORAN KEPATUHAN PENERBIT KCKR 2026+', $label, count($headers));

        return $this->streamXlsx($sp, $filename, $request);
    }

    public function exportDetail(Request $request, $id)
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $conn       = $this->getOracleConnection();
        $penerbitId = (int) $id;
        $dateFilter = $this->parseDateFilter($request);
        $dateWhere  = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);

        $pResult      = odbc_exec($conn, "SELECT P.NAME, P.KATEGORI_ID FROM PENERBIT P WHERE P.ID = $penerbitId");
        $penerbit     = odbc_fetch_object($pResult);
        $penerbitName = $penerbit ? $penerbit->NAME : 'Penerbit';
        $filename     = $this->safeName($penerbitName) . '_' . date('d-m-Y') . '.xlsx';

        $dlTerbit = self::EXPR_DEADLINE_TERBIT;
        $dlKckr   = $this->exprDeadlineKckr();

        $exportKey = $this->makeCacheKey($request, "compliance_v2:export_detail:$penerbitId", [
            'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
        ]);

        $rows = Cache::remember($exportKey, 900, function() use ($conn, $penerbitId, $dateWhere, $dlTerbit, $dlKckr) {
            $sql = "
                SELECT
                    PI.ISBN_NO, PT.TITLE, PT.KEPENG, PT.JILID_VOLUME, PT.JENIS_MEDIA,
                    PI.CREATEDATE as TGL_DAFTAR,
                    PI.TANGGAL_TERBIT,
                    $dlTerbit as DEADLINE_TERBIT,
                    ($dlTerbit + 30) as BATAS_TEGURAN,
                    CASE
                        WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > ($dlTerbit + 30) THEN 'Lewat Teguran'
                        WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > $dlTerbit        THEN 'Hutang Terbit'
                        WHEN PI.TANGGAL_TERBIT IS NULL                                THEN 'Belum Terbit'
                        ELSE 'Terbit'
                    END as STATUS_TERBIT,
                    PI.RECEIVED_DATE_KCKR,
                    $dlKckr as DEADLINE_KCKR,
                    CASE
                        WHEN PI.TANGGAL_TERBIT IS NULL THEN 'Belum Terbit'
                        WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah'
                        ELSE 'Belum'
                    END as STATUS_KCKR,
                    CASE
                        WHEN PI.TANGGAL_TERBIT IS NULL THEN NULL
                        WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $dlKckr)
                          OR (PI.RECEIVED_DATE_KCKR IS NULL    AND SYSDATE > $dlKckr)
                        THEN 'Ya' ELSE 'Tidak'
                    END as TERLAMBAT_KCKR,
                    PI.KETERANGAN
                FROM PENERBIT P
                JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                    $dateWhere
                    AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                WHERE P.ID = $penerbitId
                ORDER BY TGL_DAFTAR DESC
            ";
            $result = odbc_exec($conn, $sql);
            $data   = [];
            while ($row = odbc_fetch_object($result)) {
                $jenis = $row->JENIS_MEDIA === '1' ? 'Karya Cetak' : 'Karya Rekam';
                $data[] = [
                    $row->ISBN_NO, $row->TITLE, $row->KEPENG, $row->JILID_VOLUME, $jenis,
                    $row->TGL_DAFTAR       ? date('d/m/Y', strtotime($row->TGL_DAFTAR))       : '',
                    $row->DEADLINE_TERBIT  ? date('d/m/Y', strtotime($row->DEADLINE_TERBIT))  : '',
                    $row->TANGGAL_TERBIT   ? date('d/m/Y', strtotime($row->TANGGAL_TERBIT))   : '',
                    $row->STATUS_TERBIT,
                    $row->BATAS_TEGURAN    ? date('d/m/Y', strtotime($row->BATAS_TEGURAN))    : '',
                    $row->DEADLINE_KCKR && $row->TANGGAL_TERBIT ? date('d/m/Y', strtotime($row->DEADLINE_KCKR)) : '',
                    $row->RECEIVED_DATE_KCKR ? date('d/m/Y', strtotime($row->RECEIVED_DATE_KCKR)) : '',
                    $row->STATUS_KCKR,
                    $row->TERLAMBAT_KCKR ?? '-',
                    $row->KETERANGAN ?? '',
                ];
            }
            return $data;
        });

        $headers = [
            'ISBN','Judul','Pengarang','Jilid','Jenis Media',
            'Tgl Daftar','Deadline Terbit','Tgl Terbit','Status Terbit',
            'Batas Teguran','Deadline KCKR','Tgl KCKR','Status KCKR',
            'Terlambat KCKR','Keterangan',
        ];

        $label = $this->buildFilterLabel($request);
        $i     = 1;
        $sp    = $this->makeSpreadsheetV2(
            $headers,
            function($add) use ($rows, &$i) {
                foreach ($rows as $r) { $add(array_merge([$i++], $r)); }
            },
            'Daftar Judul',
            'DAFTAR JUDUL - ' . strtoupper($penerbitName),
            $label,
            count($headers) + 1
        );

        return $this->streamXlsx($sp, $filename, $request);
    }

    // ─── Spreadsheet builder ─────────────────────────────────────────────────

    private function makeSpreadsheetV2(
        array $headers,
        callable $rowFetcher,
        string $sheetTitle,
        string $mainTitle,
        array $label,
        int $colCount
    ): \PhpOffice\PhpSpreadsheet\Spreadsheet {
        $coord = fn(int $col, int $row) =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

        $sp    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        $hRow = $this->writeTitleRows($sheet, $mainTitle, $label, $colCount);

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1565C0']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true],
        ];

        foreach ($headers as $idx => $h) {
            $addr = $coord($idx + 1, $hRow);
            $sheet->getCell($addr)->setValue($h);
            $sheet->getStyle($addr)->applyFromArray($hStyle);
        }
        $sheet->getRowDimension($hRow)->setRowHeight(28);

        $rowNum = $hRow + 1;
        $rowFetcher(function(array $rowData) use ($sheet, &$rowNum, $coord) {
            foreach ($rowData as $idx => $val) {
                $sheet->getCell($coord($idx + 1, $rowNum))->setValue($val ?? '');
            }
            $rowNum++;
        });

        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
            )->setAutoSize(true);
        }

        $sheet->freezePane('A' . ($hRow + 1));

        return $sp;
    }
}
