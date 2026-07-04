<?php

namespace App\Http\Controllers;

use App\Traits\OracleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Compliance V3 – Gabungan: pra-2026 + 2026+
 *
 * Pra-2026 : KCKR dari createdate, tanpa pelacakan konfirmasi terbit.
 * 2026+    : KCKR dari tanggal_terbit, pelacakan konfirmasi terbit aktif.
 * Kolom terbit (Sudah Terbit, Belum, Hutang, Lewat Teguran) hanya terisi
 * untuk records 2026+. Penerbit murni pra-2026 akan tampil "-" di kolom tsb.
 */
class ComplianceV3Controller extends Controller
{
    use OracleHelper;

    private const PER_PAGE = 25;
    private const CUTOFF   = '2026-01-01';

    private const EXPR_DEADLINE_TERBIT = 'PI.CREATEDATE + 28';

    private function exprDeadlineKckrV1(): string
    {
        return "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                     ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                               ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END";
    }

    private function exprDeadlineKckrV2(): string
    {
        return "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3)
                     ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3)
                               ELSE ADD_MONTHS(PI.TANGGAL_TERBIT, 12) END END";
    }

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
        ?string $persentase = null,
        ?string $filterRekomendasi = null
    ): string {
        $cutoff = self::CUTOFF;

        $kategoriWhere = !empty($kategori) ? "AND P.KATEGORI_ID = " . intval($kategori) : '';
        $searchWhere   = !empty($search)
            ? "AND UPPER(P.NAME) LIKE '%" . strtoupper(addslashes($search)) . "%'"
            : '';

        $dlTerbit = self::EXPR_DEADLINE_TERBIT;
        $dlKckrV1 = $this->exprDeadlineKckrV1();
        $dlKckrV2 = $this->exprDeadlineKckrV2();

        // Terbit hanya berlaku untuk records 2026+
        $is2026   = "PI.CREATEDATE >= TO_DATE('$cutoff','YYYY-MM-DD')";
        $isPre26  = "PI.CREATEDATE <  TO_DATE('$cutoff','YYYY-MM-DD')";

        $sudahTerbit = "(PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL)";
        $belumTerbit = "(PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL)";

        $innerQuery = "
            SELECT
                P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY,
                P.KATEGORI_ID, P.PROVINCE_ID, P.CREATEDATE,
                CASE WHEN P.KATEGORI_ID = 1 THEN 'Pemerintah'
                     WHEN P.KATEGORI_ID = 2 THEN 'Swasta'
                     ELSE 'Lainnya' END as KATEGORI,

                -- Total judul dalam range (semua tahun)
                COUNT(DISTINCT PI.ID)                                                    as TOTAL_JUDUL,

                -- Berapa yang 2026+ (agar UI tahu apakah kolom terbit relevan)
                COUNT(DISTINCT CASE WHEN $is2026 THEN PI.ID END)                        as JUDUL_2026_PLUS,

                -- Status Terbit: hanya dari records 2026+
                COUNT(DISTINCT CASE WHEN $is2026 AND $sudahTerbit THEN PI.ID END)       as JUDUL_TERBIT,
                COUNT(DISTINCT CASE WHEN $is2026 AND $belumTerbit THEN PI.ID END)       as JUDUL_BELUM_TERBIT,
                SUM(CASE WHEN $is2026 AND $belumTerbit AND SYSDATE > $dlTerbit
                         THEN 1 ELSE 0 END)                                              as HUTANG_TERBIT,
                SUM(CASE WHEN $is2026 AND $belumTerbit AND SYSDATE > ($dlTerbit + 30)
                         THEN 1 ELSE 0 END)                                              as LEWAT_TEGURAN,

                -- KCKR: dari semua records (pra-2026 dan 2026+)
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL
                         THEN 1 ELSE 0 END)                                              as SUDAH_KCKR,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND PT.JENIS_MEDIA = '1'
                         THEN 1 ELSE 0 END)                                              as SUDAH_KCKR_CETAK,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL
                          AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL)
                         THEN 1 ELSE 0 END)                                              as SUDAH_KCKR_REKAM,

                -- Belum KCKR:
                --   pra-2026 : semua yg belum setor KCKR (tanpa syarat terbit)
                --   2026+    : sudah konfirmasi terbit tapi belum KCKR
                SUM(CASE
                    WHEN $isPre26 AND PI.RECEIVED_DATE_KCKR IS NULL THEN 1
                    WHEN $is2026  AND PI.TANGGAL_TERBIT IS NOT NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN 1
                    ELSE 0 END)                                                          as BELUM_KCKR,
                SUM(CASE
                    WHEN $isPre26 AND PI.RECEIVED_DATE_KCKR IS NULL AND PT.JENIS_MEDIA = '1' THEN 1
                    WHEN $is2026  AND PI.TANGGAL_TERBIT IS NOT NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND PT.JENIS_MEDIA = '1' THEN 1
                    ELSE 0 END)                                                          as BELUM_KCKR_CETAK,
                SUM(CASE
                    WHEN $isPre26 AND PI.RECEIVED_DATE_KCKR IS NULL AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) THEN 1
                    WHEN $is2026  AND PI.TANGGAL_TERBIT IS NOT NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) THEN 1
                    ELSE 0 END)                                                          as BELUM_KCKR_REKAM,

                -- Terlambat KCKR: formula hybrid
                SUM(CASE
                    -- pra-2026: deadline dari createdate
                    WHEN $isPre26 AND (
                        (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1))
                     OR (PI.RECEIVED_DATE_KCKR IS NULL    AND SYSDATE > ($dlKckrV1))
                    ) THEN 1
                    -- 2026+: deadline dari tanggal_terbit (hanya jika terbit sudah diisi)
                    WHEN $is2026 AND PI.TANGGAL_TERBIT IS NOT NULL AND (
                        (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV2))
                     OR (PI.RECEIVED_DATE_KCKR IS NULL    AND SYSDATE > ($dlKckrV2))
                    ) THEN 1
                    ELSE 0
                END)                                                                     as TERLAMBAT_KCKR,

                -- % KCKR = sudah / total judul (V1-style, konsisten untuk semua tahun)
                ROUND(
                    CASE WHEN COUNT(DISTINCT PI.ID) > 0
                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END)
                           / COUNT(DISTINCT PI.ID) * 100
                        ELSE 0 END, 1
                )                                                                        as PERSENTASE_KCKR

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

        $outerWhere = '';
        if ($filterHutang === 'ya')     $outerWhere .= ' AND HUTANG_TERBIT > 0';
        if ($filterHutang === 'tidak')  $outerWhere .= ' AND HUTANG_TERBIT = 0';
        if ($filterTeguran === 'ya')    $outerWhere .= ' AND LEWAT_TEGURAN > 0';
        if ($filterTeguran === 'tidak') $outerWhere .= ' AND LEWAT_TEGURAN = 0';
        if ($filterKckr === 'sudah')    $outerWhere .= ' AND SUDAH_KCKR > 0';
        if ($filterKckr === 'belum')    $outerWhere .= ' AND BELUM_KCKR > 0';

        if ($filterRekomendasi === 'blokir_terbit') $outerWhere .= ' AND LEWAT_TEGURAN > 0';
        if ($filterRekomendasi === 'blokir_kckr')   $outerWhere .= ' AND LEWAT_TEGURAN = 0 AND TERLAMBAT_KCKR > 0 AND PERSENTASE_KCKR <= 20';
        if ($filterRekomendasi === 'baik')           $outerWhere .= ' AND LEWAT_TEGURAN = 0 AND (TERLAMBAT_KCKR = 0 OR PERSENTASE_KCKR > 20)';

        if (!empty($persentase)) {
            [$min, $max] = $this->parsePersentaseRange($persentase);
            $outerWhere .= " AND PERSENTASE_KCKR BETWEEN $min AND $max";
        }

        return $outerWhere
            ? "SELECT * FROM ($innerQuery) WHERE 1=1 $outerWhere"
            : $innerQuery;
    }

    private function makeCacheKeyV3(Request $request, string $prefix): string
    {
        return $this->makeCacheKey($request, $prefix, [
            'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            'province_ids', 'kategori', 'search',
            'filter_hutang', 'filter_teguran', 'filter_kckr', 'persentase', 'filter_rekomendasi',
        ]);
    }

    // ─── Actions ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        try {
            $conn      = $this->getOracleConnection();
            $provinces = array_map(
                fn($r) => (object) $r,
                Cache::remember('compliance_v3:provinces', 3600, fn() =>
                    array_map(fn($r) => (array) $r, $this->fetchProvinces($conn))
                )
            );
            return view('compliance_v3.index', compact('provinces'));
        } catch (\Exception $e) {
            return view('compliance_v3.index', ['error' => 'Error: ' . $e->getMessage(), 'provinces' => []]);
        }
    }

    public function data(Request $request)
    {
        try {
            $conn              = $this->getOracleConnection();
            $page              = max(1, (int) $request->get('page', 1));
            $kategori          = $request->kategori           ?? null;
            $provinceIds       = $request->province_ids       ?? [];
            $search            = trim($request->search        ?? '');
            $filterHutang      = $request->filter_hutang      ?? null;
            $filterTeguran     = $request->filter_teguran     ?? null;
            $filterKckr        = $request->filter_kckr        ?? null;
            $persentase        = $request->persentase         ?? null;
            $filterRekomendasi = $request->filter_rekomendasi ?? null;
            $sortCol           = $request->sort_col           ?? 'NAME';
            $sortDir           = $request->sort_dir           ?? 'ASC';

            $dateFilter    = $this->parseDateFilter($request);
            $dateWhere     = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
            $provinceWhere = $this->buildProvinceWhere($provinceIds);

            $cacheKey = $this->makeCacheKeyV3($request, 'compliance_v3:data')
                . ':' . $page . ':' . strtolower($sortCol) . ':' . strtolower($sortDir);

            $cached = Cache::remember($cacheKey, 3600, function() use (
                $conn, $dateWhere, $provinceWhere, $kategori, $search,
                $filterHutang, $filterTeguran, $filterKckr, $persentase, $filterRekomendasi,
                $page, $sortCol, $sortDir
            ) {
                $baseQuery = $this->buildBaseQuery(
                    $dateWhere, $provinceWhere, $kategori, $search,
                    $filterHutang, $filterTeguran, $filterKckr, $persentase, $filterRekomendasi
                );

                $allowed = ['NAME','TOTAL_JUDUL','JUDUL_TERBIT','HUTANG_TERBIT',
                            'LEWAT_TEGURAN','SUDAH_KCKR','BELUM_KCKR','PERSENTASE_KCKR','TERLAMBAT_KCKR'];
                $sortCol = in_array(strtoupper($sortCol), $allowed) ? strtoupper($sortCol) : 'NAME';
                $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

                $perPage = self::PER_PAGE;
                $offset  = ($page - 1) * $perPage;
                $end     = $offset + $perPage;

                $countRes = odbc_exec($conn, "SELECT COUNT(*) as TOTAL FROM ($baseQuery)");
                $total    = (int) odbc_result($countRes, 'TOTAL');

                $aggRes = odbc_exec($conn, "
                    SELECT
                        SUM(TOTAL_JUDUL)        as SUM_JUDUL,
                        SUM(JUDUL_TERBIT)       as SUM_TERBIT,
                        SUM(JUDUL_BELUM_TERBIT) as SUM_BELUM_TERBIT,
                        SUM(HUTANG_TERBIT)      as SUM_HUTANG,
                        SUM(LEWAT_TEGURAN)      as SUM_TEGURAN,
                        SUM(SUDAH_KCKR)         as SUM_SUDAH_KCKR,
                        SUM(SUDAH_KCKR_CETAK)   as SUM_SUDAH_KCKR_CETAK,
                        SUM(SUDAH_KCKR_REKAM)   as SUM_SUDAH_KCKR_REKAM,
                        SUM(BELUM_KCKR)         as SUM_BELUM_KCKR,
                        SUM(BELUM_KCKR_CETAK)   as SUM_BELUM_KCKR_CETAK,
                        SUM(BELUM_KCKR_REKAM)   as SUM_BELUM_KCKR_REKAM,
                        ROUND(AVG(PERSENTASE_KCKR), 1) as AVG_PCT
                    FROM ($baseQuery)
                ");
                $agg = (array) odbc_fetch_object($aggRes);

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
                    'agg'          => $agg,
                ];
            });

            return response()->json([
                'data'         => array_map(fn($r) => (object) $r, $cached['data']),
                'total'        => $cached['total'],
                'current_page' => $cached['current_page'],
                'last_page'    => $cached['last_page'],
                'per_page'     => $cached['per_page'],
                'agg'          => $cached['agg'],
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
            $cutoff     = self::CUTOFF;

            $penerbit = (object) Cache::remember("compliance_v3:penerbit:$penerbitId", 3600, function() use ($conn, $penerbitId) {
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
            $dlKckrV1 = $this->exprDeadlineKckrV1();
            $dlKckrV2 = $this->exprDeadlineKckrV2();

            $is2026  = "PI.CREATEDATE >= TO_DATE('$cutoff','YYYY-MM-DD')";
            $isPre26 = "PI.CREATEDATE <  TO_DATE('$cutoff','YYYY-MM-DD')";

            $fromJoin = "
                FROM PENERBIT P
                JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                    $dateWhere
                    AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                WHERE P.ID = $penerbitId
            ";

            $searchWhere = '';
            if ($searchJudul) $searchWhere .= " AND UPPER(PT.TITLE) LIKE '%" . strtoupper(addslashes($searchJudul)) . "%'";
            if ($searchIsbn)  $searchWhere .= " AND UPPER(PI.ISBN_NO) LIKE '%" . strtoupper(addslashes($searchIsbn)) . "%'";
            if ($filterJenis === 'cetak') $searchWhere .= " AND PT.JENIS_MEDIA = '1'";
            if ($filterJenis === 'rekam') $searchWhere .= " AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL)";

            // filter status terbit: hanya 2026+ records punya status terbit
            if ($filterStatus === 'terbit')       $searchWhere .= " AND $is2026 AND (PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL)";
            if ($filterStatus === 'belum_terbit') $searchWhere .= " AND $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL";
            if ($filterStatus === 'sudah_kckr')   $searchWhere .= " AND PI.RECEIVED_DATE_KCKR IS NOT NULL";
            if ($filterStatus === 'belum_kckr')   $searchWhere .= " AND PI.RECEIVED_DATE_KCKR IS NULL";
            if ($filterHutang  === 'ya') $searchWhere .= " AND $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dlTerbit";
            if ($filterTeguran === 'ya') $searchWhere .= " AND $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlTerbit + 30)";
            if ($filterTerlambat === 'ya') {
                $searchWhere .= " AND (
                    ($isPre26 AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1)) OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV1))))
                    OR ($is2026 AND PI.TANGGAL_TERBIT IS NOT NULL AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV2)) OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV2))))
                )";
            }

            // Summary: terbit metrics hanya 2026+, KCKR semua records
            $summaryKey = $this->makeCacheKey($request, "compliance_v3:detail:$penerbitId:summary", [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            ]);
            $summary = (object) Cache::remember($summaryKey, 3600, function() use ($conn, $fromJoin, $dlTerbit, $dlKckrV1, $dlKckrV2, $is2026, $isPre26) {
                $sudahTerbit = "(PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL)";
                $r = odbc_exec($conn, "
                    SELECT
                        COUNT(*) as TOTAL,
                        COUNT(CASE WHEN $is2026 AND $sudahTerbit THEN 1 END) as SUDAH_TERBIT,
                        COUNT(CASE WHEN $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN 1 END) as BELUM_TERBIT,
                        COUNT(CASE WHEN $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dlTerbit THEN 1 END) as HUTANG_TERBIT,
                        COUNT(CASE WHEN $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlTerbit + 30) THEN 1 END) as LEWAT_TEGURAN,
                        COUNT(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 END) as SUDAH_KCKR,
                        COUNT(CASE WHEN ($isPre26 AND PI.RECEIVED_DATE_KCKR IS NULL)
                                     OR ($is2026 AND PI.TANGGAL_TERBIT IS NOT NULL AND PI.RECEIVED_DATE_KCKR IS NULL)
                                   THEN 1 END) as BELUM_KCKR,
                        COUNT(CASE WHEN
                            ($isPre26 AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1)) OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV1))))
                            OR ($is2026 AND PI.TANGGAL_TERBIT IS NOT NULL AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV2)) OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV2))))
                        THEN 1 END) as TERLAMBAT_KCKR,
                        ROUND(
                            CASE WHEN COUNT(*) > 0
                                THEN COUNT(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 END) / COUNT(*) * 100
                                ELSE 0 END, 1
                        ) as PERSENTASE_KCKR
                    $fromJoin
                ");
                return (array) odbc_fetch_object($r);
            });

            $selectCols = "
                PI.ID, PI.ISBN_NO,
                PI.CREATEDATE      as TGL_DAFTAR,
                PI.TANGGAL_TERBIT,
                PI.RECEIVED_DATE_KCKR,
                PI.KETERANGAN,
                PT.TITLE, PT.KEPENG, PT.JENIS_MEDIA, PT.JILID_VOLUME,
                CASE WHEN $isPre26 THEN NULL ELSE $dlTerbit END   as DEADLINE_TERBIT,
                CASE WHEN $isPre26 THEN NULL ELSE ($dlTerbit + 30) END as BATAS_TEGURAN,
                -- IS_PRE2026: 1 = pra-2026, 0 = 2026+
                CASE WHEN $isPre26 THEN 1 ELSE 0 END               as IS_PRE2026,
                -- Status Terbit: N/A untuk pra-2026
                CASE
                    WHEN $isPre26                                                          THEN 'N/A'
                    WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.TANGGAL_TERBIT IS NULL  THEN 'Terbit'
                    WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > ($dlTerbit + 30)         THEN 'Lewat Teguran'
                    WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > $dlTerbit                THEN 'Hutang Terbit'
                    WHEN PI.TANGGAL_TERBIT IS NULL                                        THEN 'Belum Terbit'
                    ELSE 'Terbit'
                END as STATUS_TERBIT,
                CASE
                    WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah'
                    WHEN $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN 'Belum Terbit'
                    ELSE 'Belum'
                END as STATUS_KCKR,
                -- Deadline KCKR: hybrid
                CASE
                    WHEN $isPre26 THEN ($dlKckrV1)
                    WHEN PI.TANGGAL_TERBIT IS NOT NULL THEN ($dlKckrV2)
                    ELSE NULL
                END as DEADLINE_KCKR,
                -- Terlambat KCKR: hybrid
                CASE
                    WHEN $isPre26 AND (
                        (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1))
                     OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV1))
                    ) THEN 'Ya'
                    WHEN $is2026 AND PI.TANGGAL_TERBIT IS NOT NULL AND (
                        (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV2))
                     OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV2))
                    ) THEN 'Ya'
                    ELSE 'Tidak'
                END as TERLAMBAT_KCKR
            ";

            $pageKey = $this->makeCacheKey($request, "compliance_v3:detail:$penerbitId:page", [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
                'filter_status', 'filter_jenis', 'filter_hutang', 'filter_teguran', 'filter_terlambat',
                'search_judul', 'search_isbn',
            ]) . ':' . $page;

            $cached = Cache::remember($pageKey, 3600, function() use ($conn, $fromJoin, $searchWhere, $selectCols, $page, $perPage) {
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

            return view('compliance_v3.detail', compact(
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
        $filename = 'ComplianceV3_' . $periode . '_' . date('d-m-Y') . '.xlsx';

        $exportKey = $this->makeCacheKeyV3($request, 'compliance_v3:export');

        $rows = Cache::remember($exportKey, 3600, function() use ($conn, $baseQuery) {
            $result = odbc_exec($conn, "SELECT * FROM ($baseQuery) ORDER BY NAME ASC");
            $data   = [];
            while ($row = odbc_fetch_object($result)) {
                $lewat      = (int) $row->LEWAT_TEGURAN;
                $terlambat  = (int) $row->TERLAMBAT_KCKR;
                $pct        = (float) $row->PERSENTASE_KCKR;
                $jml2026    = (int) $row->JUDUL_2026_PLUS;

                $rekomendasi = $lewat > 0
                    ? 'Blokir Konfirmasi Terbit'
                    : ($terlambat > 0 && $pct <= 20 ? 'Blokir SS KCKR' : 'Baik');

                $data[] = [
                    $row->NAME,
                    $row->KATEGORI,
                    $row->CITY,
                    $row->PROVINSI,
                    (int) $row->TOTAL_JUDUL,
                    // Kolom terbit: kosong jika tidak ada data 2026+
                    $jml2026 > 0 ? (int) $row->JUDUL_TERBIT       : '-',
                    $jml2026 > 0 ? (int) $row->JUDUL_BELUM_TERBIT : '-',
                    $jml2026 > 0 ? (int) $row->HUTANG_TERBIT      : '-',
                    $jml2026 > 0 ? $lewat                          : '-',
                    (int) $row->SUDAH_KCKR,
                    (int) $row->SUDAH_KCKR_CETAK,
                    (int) $row->SUDAH_KCKR_REKAM,
                    (int) $row->BELUM_KCKR,
                    (int) $row->BELUM_KCKR_CETAK,
                    (int) $row->BELUM_KCKR_REKAM,
                    $terlambat,
                    $pct,
                    $rekomendasi,
                ];
            }
            return $data;
        });

        $i  = 1;
        $sp = $this->makeSpreadsheetV3(function($add) use ($rows, &$i) {
            foreach ($rows as $r) {
                $add(array_merge([$i++], $r));
            }
        }, 'Ringkasan', 'LAPORAN KEPATUHAN PENERBIT KCKR — GABUNGAN', $label, 19);

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
        $cutoff     = self::CUTOFF;

        $pResult      = odbc_exec($conn, "SELECT P.NAME, P.KATEGORI_ID FROM PENERBIT P WHERE P.ID = $penerbitId");
        $penerbit     = odbc_fetch_object($pResult);
        $penerbitName = $penerbit ? $penerbit->NAME : 'Penerbit';
        $filename     = $this->safeName($penerbitName) . '_V3_' . date('d-m-Y') . '.xlsx';

        $dlTerbit = self::EXPR_DEADLINE_TERBIT;
        $dlKckrV1 = $this->exprDeadlineKckrV1();
        $dlKckrV2 = $this->exprDeadlineKckrV2();

        $is2026  = "PI.CREATEDATE >= TO_DATE('$cutoff','YYYY-MM-DD')";
        $isPre26 = "PI.CREATEDATE <  TO_DATE('$cutoff','YYYY-MM-DD')";

        $exportKey = $this->makeCacheKey($request, "compliance_v3:export_detail:$penerbitId", [
            'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
        ]);

        $rows = Cache::remember($exportKey, 3600, function() use ($conn, $penerbitId, $dateWhere, $dlTerbit, $dlKckrV1, $dlKckrV2, $is2026, $isPre26) {
            $sql = "
                SELECT
                    PI.ISBN_NO, PT.TITLE, PT.KEPENG, PT.JILID_VOLUME, PT.JENIS_MEDIA,
                    PI.CREATEDATE as TGL_DAFTAR,
                    PI.TANGGAL_TERBIT,
                    CASE WHEN $isPre26 THEN NULL ELSE $dlTerbit END as DEADLINE_TERBIT,
                    CASE WHEN $isPre26 THEN NULL ELSE ($dlTerbit + 30) END as BATAS_TEGURAN,
                    CASE
                        WHEN $isPre26 THEN 'N/A (Pra-2026)'
                        WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > ($dlTerbit + 30) THEN 'Lewat Teguran'
                        WHEN PI.TANGGAL_TERBIT IS NULL AND SYSDATE > $dlTerbit        THEN 'Hutang Terbit'
                        WHEN PI.TANGGAL_TERBIT IS NULL                                THEN 'Belum Terbit'
                        ELSE 'Terbit'
                    END as STATUS_TERBIT,
                    PI.RECEIVED_DATE_KCKR,
                    CASE
                        WHEN $isPre26 THEN ($dlKckrV1)
                        WHEN PI.TANGGAL_TERBIT IS NOT NULL THEN ($dlKckrV2)
                        ELSE NULL
                    END as DEADLINE_KCKR,
                    CASE
                        WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah'
                        WHEN $is2026 AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN 'Belum Terbit'
                        ELSE 'Belum'
                    END as STATUS_KCKR,
                    CASE
                        WHEN $isPre26 AND (
                            (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1))
                         OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV1))
                        ) THEN 'Ya'
                        WHEN $is2026 AND PI.TANGGAL_TERBIT IS NOT NULL AND (
                            (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV2))
                         OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV2))
                        ) THEN 'Ya'
                        ELSE 'Tidak'
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
                    $row->DEADLINE_TERBIT  ? date('d/m/Y', strtotime($row->DEADLINE_TERBIT))  : '-',
                    $row->TANGGAL_TERBIT   ? date('d/m/Y', strtotime($row->TANGGAL_TERBIT))   : '',
                    $row->STATUS_TERBIT,
                    $row->BATAS_TEGURAN    ? date('d/m/Y', strtotime($row->BATAS_TEGURAN))    : '-',
                    $row->DEADLINE_KCKR    ? date('d/m/Y', strtotime($row->DEADLINE_KCKR))    : '',
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
        $coord = fn(int $c, int $r) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
        $sp    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Daftar Judul');

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1565C0']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
        ];

        $allHeaders = array_merge(['No'], $headers);
        $sheet->mergeCells($coord(1, 1) . ':' . $coord(count($allHeaders), 1));
        $sheet->getCell($coord(1, 1))->setValue('DAFTAR JUDUL — ' . strtoupper($penerbitName));
        $sheet->getStyle($coord(1, 1) . ':' . $coord(count($allHeaders), 1))->applyFromArray(array_merge($hStyle, [
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D47A1']],
        ]));
        $sheet->getRowDimension(1)->setRowHeight(20);

        foreach ($allHeaders as $idx => $h) {
            $addr = $coord($idx + 1, 2);
            $sheet->getCell($addr)->setValue($h);
            $sheet->getStyle($addr)->applyFromArray($hStyle);
        }
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getStyle('B:B')->getNumberFormat()
              ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        $rn = 3;
        foreach ($rows as $r) {
            $sheet->getCell($coord(1, $rn))->setValue($i++);
            foreach ($r as $ci => $v) {
                $cell = $sheet->getCell($coord($ci + 2, $rn));
                if ($ci === 0) {
                    $cell->setValueExplicit($v ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } else {
                    $cell->setValue($v ?? '');
                }
            }
            $rn++;
        }
        foreach (range(1, count($allHeaders)) as $c) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $sheet->freezePane('A3');

        return $this->streamXlsx($sp, $filename, $request);
    }

    // ─── Spreadsheet builder ─────────────────────────────────────────────────

    private function makeSpreadsheetV3(
        callable $rowFetcher,
        string $sheetTitle,
        string $mainTitle,
        array $label,
        int $colCount
    ): \PhpOffice\PhpSpreadsheet\Spreadsheet {
        $Fill   = \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID;
        $AlignH = \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
        $AlignV = \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER;
        $coord  = fn(int $col, int $row) =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

        $sp    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        $r1 = $this->writeTitleRows($sheet, $mainTitle, $label, $colCount);
        $r2 = $r1 + 1;

        $baseStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => $Fill, 'startColor' => ['rgb' => '1565C0']],
            'alignment' => ['horizontal' => $AlignH, 'vertical' => $AlignV, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                             'color'       => ['rgb' => 'AAAAAA']]],
        ];
        $groupStyle = $baseStyle;
        $groupStyle['fill']['startColor']['rgb'] = '0D47A1';

        // Kolom span tunggal (rowspan 2)
        $singleCols = [
            1 => '#',  2 => 'Nama Penerbit', 3 => 'Kategori',
            4 => 'Kota', 5 => 'Provinsi',    6 => 'Total Judul',
            18 => '% KCKR', 19 => 'Rekomendasi',
        ];
        foreach ($singleCols as $col => $label_) {
            $sheet->getCell($coord($col, $r1))->setValue($label_);
            $sheet->mergeCells($coord($col, $r1) . ':' . $coord($col, $r2));
            $sheet->getStyle($coord($col, $r1) . ':' . $coord($col, $r2))->applyFromArray($baseStyle);
        }

        $groups = [
            [7,  8,  'Status Terbit (2026+)'],
            [9,  10, 'Keterlambatan Terbit (2026+)'],
            [11, 13, 'Sudah KCKR'],
            [14, 17, 'Belum KCKR'],
        ];
        foreach ($groups as [$from, $to, $title]) {
            $sheet->getCell($coord($from, $r1))->setValue($title);
            $sheet->mergeCells($coord($from, $r1) . ':' . $coord($to, $r1));
            $sheet->getStyle($coord($from, $r1) . ':' . $coord($to, $r1))->applyFromArray($groupStyle);
        }

        $subHeaders = [
            7  => 'Terbit',       8  => 'Belum',
            9  => 'Hutang',       10 => 'Lewat Teguran',
            11 => 'Total',        12 => 'Cetak',        13 => 'Rekam',
            14 => 'Total',        15 => 'Cetak',        16 => 'Rekam',        17 => 'Terlambat',
        ];
        foreach ($subHeaders as $col => $label_) {
            $sheet->getCell($coord($col, $r2))->setValue($label_);
            $sheet->getStyle($coord($col, $r2))->applyFromArray($baseStyle);
        }

        $sheet->getRowDimension($r1)->setRowHeight(22);
        $sheet->getRowDimension($r2)->setRowHeight(20);

        $colBg = [
            7  => 'E8F5E9', 8  => 'E8F5E9',
            9  => 'FFFDE7', 10 => 'FFFDE7',
            11 => 'C8E6C9', 12 => 'C8E6C9', 13 => 'C8E6C9',
            14 => 'FFF3E0', 15 => 'FFF3E0', 16 => 'FFF3E0', 17 => 'FFCCBC',
        ];

        $rowNum = $r2 + 1;
        $rowFetcher(function(array $rowData) use ($sheet, &$rowNum, $coord, $colBg, $Fill) {
            $isEven = ($rowNum % 2 === 0);
            foreach ($rowData as $idx => $val) {
                $col  = $idx + 1;
                $cell = $sheet->getCell($coord($col, $rowNum));
                $cell->setValue($val ?? '');
                $styleArr = [
                    'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
                    'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                ];
                if (isset($colBg[$col])) {
                    $styleArr['fill'] = ['fillType' => $Fill, 'startColor' => ['rgb' => $colBg[$col]]];
                } elseif ($isEven) {
                    $styleArr['fill'] = ['fillType' => $Fill, 'startColor' => ['rgb' => 'F9F9F9']];
                }
                $sheet->getStyle($coord($col, $rowNum))->applyFromArray($styleArr);
            }
            // Merahkan kolom Terlambat KCKR (col 17) kalau > 0
            if (!empty($rowData[16]) && $rowData[16] > 0) {
                $sheet->getStyle($coord(17, $rowNum))->getFont()->getColor()->setRGB('C62828');
                $sheet->getStyle($coord(17, $rowNum))->getFont()->setBold(true);
            }
            $rowNum++;
        });

        foreach (range(1, $colCount) as $c) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
            )->setAutoSize(true);
        }
        $sheet->freezePane('A' . ($r2 + 1));

        return $sp;
    }
}
