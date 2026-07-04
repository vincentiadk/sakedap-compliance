<?php

namespace App\Http\Controllers;

use App\Traits\OracleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ComplianceController extends Controller
{
    use OracleHelper;

    private const PER_PAGE = 25;

    private function buildDateWhere(string $start, string $end): string
    {
        return "AND PI.CREATEDATE >= TO_DATE('$start', 'YYYY-MM-DD')
                AND PI.CREATEDATE <  TO_DATE('$end',   'YYYY-MM-DD')";
    }

    private function buildBaseQuery(string $dateWhere, string $provinceWhere, ?string $kategori, ?string $persentase, ?string $search = null, ?string $filterRekomendasi = null): string
    {
        $kategoriWhere = !empty($kategori) ? "AND P.KATEGORI_ID = " . intval($kategori) : '';
        $searchWhere   = !empty($search)   ? "AND UPPER(P.NAME) LIKE '%" . strtoupper(addslashes($search)) . "%'" : '';

        $query = "
            SELECT
                P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY, P.KATEGORI_ID, P.PROVINCE_ID, P.CREATEDATE,
                CASE WHEN P.KATEGORI_ID = 1 THEN 'Pemerintah'
                     WHEN P.KATEGORI_ID = 2 THEN 'Swasta'
                     ELSE 'Lainnya' END as KATEGORI,
                COUNT(DISTINCT PI.ID) as JUMLAHJUDUL,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as JUMLAHSUDAHKCKR,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND PT.JENIS_MEDIA = '1' THEN 1 ELSE 0 END) as SUDAHKCKR_CETAK,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) THEN 1 ELSE 0 END) as SUDAHKCKR_REKAM,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL THEN 1 ELSE 0 END) as JUMLAHBELUMKCKR,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL AND PT.JENIS_MEDIA = '1' THEN 1 ELSE 0 END) as BELUMKCKR_CETAK,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) THEN 1 ELSE 0 END) as BELUMKCKR_REKAM,
                SUM(CASE WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL
                                AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                 ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                           ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                          OR (PI.RECEIVED_DATE_KCKR IS NULL
                              AND SYSDATE > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                 ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                           ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                         THEN 1 ELSE 0 END) as JUMLAHTERLAMBATKCKR,
                SUM(CASE WHEN PT.JENIS_MEDIA = '1' AND (
                              (PI.RECEIVED_DATE_KCKR IS NOT NULL
                               AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                ELSE ADD_MONTHS(PI.CREATEDATE, 3) END)
                           OR (PI.RECEIVED_DATE_KCKR IS NULL
                               AND SYSDATE > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                  ELSE ADD_MONTHS(PI.CREATEDATE, 3) END)
                         ) THEN 1 ELSE 0 END) as TERLAMBATKCKR_CETAK,
                SUM(CASE WHEN (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) AND (
                              (PI.RECEIVED_DATE_KCKR IS NOT NULL
                               AND PI.RECEIVED_DATE_KCKR > ADD_MONTHS(PI.CREATEDATE, 12))
                           OR (PI.RECEIVED_DATE_KCKR IS NULL
                               AND SYSDATE > ADD_MONTHS(PI.CREATEDATE, 12))
                         ) THEN 1 ELSE 0 END) as TERLAMBATKCKR_REKAM,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL
                          AND PI.RECEIVED_DATE_KCKR <= CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                            ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                      ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END
                         THEN 1 ELSE 0 END) as JUMLAHTEPATWAKTUKCKR,
                ROUND(
                    CASE WHEN COUNT(DISTINCT PI.ID) > 0
                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) / COUNT(DISTINCT PI.ID) * 100
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

        $outerWhere = '';
        if (!empty($persentase)) {
            [$min, $max] = $this->parsePersentaseRange($persentase);
            $outerWhere .= " AND PERSENTASE_KCKR BETWEEN $min AND $max";
        }
        if ($filterRekomendasi === 'blokir_kckr') $outerWhere .= ' AND JUMLAHTERLAMBATKCKR > 0 AND PERSENTASE_KCKR <= 20';
        if ($filterRekomendasi === 'baik')         $outerWhere .= ' AND NOT (JUMLAHTERLAMBATKCKR > 0 AND PERSENTASE_KCKR <= 20)';

        if ($outerWhere) {
            $query = "SELECT * FROM ($query) WHERE 1=1 $outerWhere";
        }

        return $query;
    }

    private function fetchSummary($conn, string $dateWhere, string $provinceWhere, ?string $kategori, ?string $search = null): object
    {
        $kategoriWhere = !empty($kategori) ? "AND P.KATEGORI_ID = " . intval($kategori) : '';
        $searchWhere   = !empty($search)   ? "AND UPPER(P.NAME) LIKE '%" . strtoupper(addslashes($search)) . "%'" : '';
        $keteranganWhere = "AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)";
        $joinCondition = !empty($dateWhere) ? "$dateWhere $keteranganWhere" : $keteranganWhere;

        $sql = "
            SELECT
                COUNT(DISTINCT P.ID) as TOTAL_PENERBIT,
                COUNT(PI.ID) as TOTAL_ISBN,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as TOTAL_SUDAH_KCKR,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND PT.JENIS_MEDIA = '1' THEN 1 ELSE 0 END) as TOTAL_SUDAH_CETAK,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) THEN 1 ELSE 0 END) as TOTAL_SUDAH_REKAM,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL THEN 1 ELSE 0 END) as TOTAL_BELUM_KCKR,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL AND PT.JENIS_MEDIA = '1' THEN 1 ELSE 0 END) as TOTAL_BELUM_CETAK,
                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) THEN 1 ELSE 0 END) as TOTAL_BELUM_REKAM,
                SUM(CASE WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL
                                AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                 ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                           ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                          OR (PI.RECEIVED_DATE_KCKR IS NULL
                              AND SYSDATE > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                 ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                           ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                         THEN 1 ELSE 0 END) as TOTAL_TERLAMBAT,
                SUM(CASE WHEN PT.JENIS_MEDIA = '1' AND (
                              (PI.RECEIVED_DATE_KCKR IS NOT NULL
                               AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                ELSE ADD_MONTHS(PI.CREATEDATE, 3) END)
                           OR (PI.RECEIVED_DATE_KCKR IS NULL
                               AND SYSDATE > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                  ELSE ADD_MONTHS(PI.CREATEDATE, 3) END)
                         ) THEN 1 ELSE 0 END) as TOTAL_TERLAMBAT_CETAK,
                SUM(CASE WHEN (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL) AND (
                              (PI.RECEIVED_DATE_KCKR IS NOT NULL
                               AND PI.RECEIVED_DATE_KCKR > ADD_MONTHS(PI.CREATEDATE, 12))
                           OR (PI.RECEIVED_DATE_KCKR IS NULL
                               AND SYSDATE > ADD_MONTHS(PI.CREATEDATE, 12))
                         ) THEN 1 ELSE 0 END) as TOTAL_TERLAMBAT_REKAM
            FROM PENERBIT P
            LEFT JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                $joinCondition
            LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
            WHERE 1=1
                $provinceWhere
                $kategoriWhere
                $searchWhere
        ";

        $result = odbc_exec($conn, $sql);
        return odbc_fetch_object($result);
    }

    private function fetchSubtotal($conn, string $baseQuery): object
    {
        $sql = "
            SELECT
                COUNT(*) as TOTAL_PENERBIT,
                SUM(JUMLAHJUDUL) as TOTAL_ISBN,
                SUM(JUMLAHSUDAHKCKR) as TOTAL_SUDAH_KCKR,
                SUM(SUDAHKCKR_CETAK) as TOTAL_SUDAH_CETAK,
                SUM(SUDAHKCKR_REKAM) as TOTAL_SUDAH_REKAM,
                SUM(JUMLAHBELUMKCKR) as TOTAL_BELUM_KCKR,
                SUM(BELUMKCKR_CETAK) as TOTAL_BELUM_CETAK,
                SUM(BELUMKCKR_REKAM) as TOTAL_BELUM_REKAM,
                SUM(JUMLAHTERLAMBATKCKR) as TOTAL_TERLAMBAT,
                SUM(TERLAMBATKCKR_CETAK) as TOTAL_TERLAMBAT_CETAK,
                SUM(TERLAMBATKCKR_REKAM) as TOTAL_TERLAMBAT_REKAM
            FROM ($baseQuery)
        ";
        $result = odbc_exec($conn, $sql);
        return odbc_fetch_object($result);
    }

    private function fetchPaginated($conn, string $baseQuery, int $page, string $sortCol = 'CREATEDATE', string $sortDir = 'DESC'): array
    {
        $allowed  = ['JUMLAHJUDUL', 'JUMLAHSUDAHKCKR', 'JUMLAHBELUMKCKR', 'JUMLAHTERLAMBATKCKR', 'PERSENTASE_KCKR', 'CREATEDATE', 'NAME'];
        $sortCol  = in_array(strtoupper($sortCol), $allowed) ? strtoupper($sortCol) : 'CREATEDATE';
        $sortDir  = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $perPage = self::PER_PAGE;
        $offset  = ($page - 1) * $perPage;
        $end     = $offset + $perPage;

        $countResult = odbc_exec($conn, "SELECT COUNT(*) as TOTAL FROM ($baseQuery)");
        $total = (int) odbc_result($countResult, 'TOTAL');

        $sql = "
            SELECT * FROM (
                SELECT a.*, ROWNUM as RN FROM (
                    $baseQuery ORDER BY $sortCol $sortDir
                ) a WHERE ROWNUM <= $end
            ) WHERE RN > $offset
        ";

        $result = odbc_exec($conn, $sql);
        $data = [];
        while ($row = odbc_fetch_object($result)) {
            $data[] = $row;
        }

        return compact('data', 'total', 'perPage', 'page') + [
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
        ];
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

    public function index(Request $request)
    {
        try {
            $conn      = $this->getOracleConnection();
            $provinces = array_map(
                fn($r) => (object) $r,
                Cache::remember('compliance:provinces', 3600, fn() =>
                    array_map(fn($r) => (array) $r, $this->fetchProvinces($conn))
                )
            );
            return view('compliance.index', ['provinces' => $provinces]);
        } catch (\Exception $e) {
            return view('compliance.index', ['error' => 'Error: ' . $e->getMessage(), 'provinces' => []]);
        }
    }

    public function data(Request $request)
    {
        try {
            $conn        = $this->getOracleConnection();
            $page        = (int) $request->get('page', 1);
            $kategori           = $request->kategori           ?? null;
            $persentase         = $request->persentase         ?? null;
            $filterRekomendasi  = $request->filter_rekomendasi ?? null;
            $provinceIds        = $request->province_ids       ?? [];
            $search             = trim($request->search        ?? '');

            $dateFilter    = $this->parseDateFilter($request);
            $dateWhere     = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
            $provinceWhere = $this->buildProvinceWhere($provinceIds);

            $sortCol = $request->sort_col ?? 'CREATEDATE';
            $sortDir = $request->sort_dir ?? 'DESC';

            // Total keseluruhan tidak bergantung filter — cache terpisah
            $summary = (object) Cache::remember('compliance:summary_total', 3600, function() use ($conn) {
                return (array) $this->fetchSummary($conn, '', '', null, null);
            });

            // Subtotal + paginated — cache per kombinasi filter+halaman+sort
            $dataKey = $this->makeCacheKey($request, 'compliance:data', [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
                'province_ids', 'kategori', 'persentase', 'filter_rekomendasi', 'search', 'page', 'sort_col', 'sort_dir',
            ]);

            $cached = Cache::remember($dataKey, 3600, function() use (
                $conn, $dateWhere, $provinceWhere, $kategori, $persentase, $filterRekomendasi, $search, $page, $sortCol, $sortDir
            ) {
                $baseQuery = $this->buildBaseQuery($dateWhere, $provinceWhere, $kategori, $persentase, $search, $filterRekomendasi);
                $subtotal  = (array) $this->fetchSubtotal($conn, $baseQuery);
                $paginated = $this->fetchPaginated($conn, $baseQuery, $page, $sortCol, $sortDir);

                return [
                    'subtotal'     => $subtotal,
                    'data'         => array_map(fn($r) => (array) $r, $paginated['data']),
                    'total'        => $paginated['total'],
                    'current_page' => $paginated['current_page'],
                    'last_page'    => $paginated['last_page'],
                    'per_page'     => $paginated['per_page'],
                ];
            });

            return response()->json([
                'summary'      => $summary,
                'subtotal'     => (object) $cached['subtotal'],
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

    private const DETAIL_PER_PAGE = 25;

    public function detail(Request $request, $id)
    {
        try {
            $conn       = $this->getOracleConnection();
            $penerbitId = (int) $id;
            $page       = max(1, (int) $request->get('page', 1));
            $perPage    = self::DETAIL_PER_PAGE;
            $dateFilter = $this->parseDateFilter($request);
            $dateWhere  = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);

            // Search filters
            $searchJudul     = trim($request->search_judul     ?? '');
            $searchIsbn      = trim($request->search_isbn      ?? '');
            $searchPengarang = trim($request->search_pengarang ?? '');
            $searchJilid     = trim($request->search_jilid     ?? '');
            $filterJenis     = $request->filter_jenis     ?? '';
            $filterStatus    = $request->filter_status    ?? '';
            $filterTerlambat = $request->filter_terlambat ?? '';
            $tglDaftarStart  = $request->tgl_daftar_start ?? '';
            $tglDaftarEnd    = $request->tgl_daftar_end   ?? '';
            $tglKckrStart    = $request->tgl_kckr_start   ?? '';
            $tglKckrEnd      = $request->tgl_kckr_end     ?? '';

            $searchWhere = $this->buildDetailSearchWhere($request);

            // Cache penerbit info (jarang berubah)
            $penerbit = (object) Cache::remember("compliance:penerbit:$penerbitId", 3600, function() use ($conn, $penerbitId) {
                $r = odbc_fetch_object(odbc_exec($conn, "SELECT P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY, P.KATEGORI_ID FROM PENERBIT P WHERE P.ID = $penerbitId"));
                return $r ? (array) $r : null;
            });
            if (!$penerbit || !isset($penerbit->ID)) abort(404, 'Penerbit tidak ditemukan');

            $selectCols = "
                PI.ID, PI.ISBN_NO,
                PI.CREATEDATE as TGL_DAFTAR,
                PI.RECEIVED_DATE_KCKR,
                PI.KETERANGAN,
                PT.TITLE, PT.KEPENG, PT.JENIS_MEDIA,
                PT.TAHUN_TERBIT, PT.TEMPAT_TERBIT, PT.JILID_VOLUME,
                CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah' ELSE 'Belum' END as STATUS_KCKR,
                CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                     ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                               ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END as DEADLINE_KCKR,
                CASE
                    WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL
                          AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                           ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                     ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                      OR (PI.RECEIVED_DATE_KCKR IS NULL
                          AND SYSDATE > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                             ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                       ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                    THEN 'Ya' ELSE 'Tidak' END as IS_TERLAMBAT
            ";

            $fromJoin = "
                FROM PENERBIT P
                JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                    $dateWhere
                    AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                WHERE P.ID = $penerbitId
            ";

            // Cache key: id + filter tanggal (summary tidak bergantung search)
            $summaryKey = $this->makeCacheKey($request, "compliance:detail:$penerbitId:summary", [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            ]);
            $summary = (object) Cache::remember($summaryKey, 3600, function() use ($conn, $fromJoin) {
                $r = odbc_exec($conn, "
                    SELECT
                        COUNT(*) as TOTAL,
                        SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as SUDAH,
                        SUM(CASE WHEN PT.JENIS_MEDIA = '1' THEN 1 ELSE 0 END) as CETAK,
                        SUM(CASE WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL
                                        AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                         ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                                   ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                                  OR (PI.RECEIVED_DATE_KCKR IS NULL
                                      AND SYSDATE > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                         ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                                   ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END)
                                 THEN 1 ELSE 0 END) as TERLAMBAT
                    $fromJoin
                ");
                return (array) odbc_fetch_object($r);
            });

            // Cache key: id + filter + search + page
            $pageKey = $this->makeCacheKey($request, "compliance:detail:$penerbitId:page", [
                'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
                'filter_jenis', 'filter_status', 'filter_terlambat',
                'search_judul', 'search_isbn', 'search_pengarang', 'search_jilid',
                'tgl_daftar_start', 'tgl_daftar_end', 'tgl_kckr_start', 'tgl_kckr_end',
            ]) . ':' . $page;

            $cached = Cache::remember($pageKey, 3600, function() use ($conn, $fromJoin, $searchWhere, $selectCols, $page, $perPage) {
                $countResult = odbc_exec($conn, "SELECT COUNT(*) as TOTAL $fromJoin $searchWhere");
                $total       = (int) (odbc_fetch_object($countResult)->TOTAL ?? 0);
                $lastPage    = max(1, (int) ceil($total / $perPage));
                $page        = min($page, $lastPage);
                $offset      = ($page - 1) * $perPage;
                $end         = $offset + $perPage;

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
                'searchJudul', 'searchIsbn', 'searchPengarang', 'searchJilid',
                'filterJenis', 'filterStatus', 'filterTerlambat',
                'tglDaftarStart', 'tglDaftarEnd', 'tglKckrStart', 'tglKckrEnd'
            );

            return view('compliance.detail', compact(
                'penerbit', 'titles', 'dateFilter', 'kategoriLabel',
                'summary', 'total', 'page', 'perPage', 'lastPage', 'filters'
            ));

        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    private function csvRow($out, array $row): void
    {
        fputcsv($out, array_map(fn($v) => $v === null ? '' : $v, $row));
    }

    private function buildDetailSearchWhere(Request $request): string
    {
        $w = '';
        $searchJudul     = trim($request->search_judul     ?? '');
        $searchIsbn      = trim($request->search_isbn      ?? '');
        $searchPengarang = trim($request->search_pengarang ?? '');
        $searchJilid     = trim($request->search_jilid     ?? '');
        $filterJenis     = $request->filter_jenis     ?? '';
        $filterStatus    = $request->filter_status    ?? '';
        $filterTerlambat = $request->filter_terlambat ?? '';
        $tglDaftarStart  = $request->tgl_daftar_start ?? '';
        $tglDaftarEnd    = $request->tgl_daftar_end   ?? '';
        $tglKckrStart    = $request->tgl_kckr_start   ?? '';
        $tglKckrEnd      = $request->tgl_kckr_end     ?? '';

        if ($searchJudul)     $w .= " AND UPPER(PT.TITLE) LIKE '%" . strtoupper(addslashes($searchJudul)) . "%'";
        if ($searchIsbn)      $w .= " AND UPPER(PI.ISBN_NO) LIKE '%" . strtoupper(addslashes($searchIsbn)) . "%'";
        if ($searchPengarang) $w .= " AND UPPER(PT.KEPENG) LIKE '%" . strtoupper(addslashes($searchPengarang)) . "%'";
        if ($searchJilid)     $w .= " AND UPPER(PT.JILID_VOLUME) LIKE '%" . strtoupper(addslashes($searchJilid)) . "%'";
        if ($filterJenis === 'cetak') $w .= " AND PT.JENIS_MEDIA = '1'";
        elseif ($filterJenis === 'rekam') $w .= " AND (PT.JENIS_MEDIA != '1' OR PT.JENIS_MEDIA IS NULL)";
        if ($filterStatus === 'sudah') $w .= " AND PI.RECEIVED_DATE_KCKR IS NOT NULL";
        elseif ($filterStatus === 'belum') $w .= " AND PI.RECEIVED_DATE_KCKR IS NULL";

        $dl = "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                    ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                              ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END";
        if ($filterTerlambat === 'ya')
            $w .= " AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $dl)
                      OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dl))";
        elseif ($filterTerlambat === 'tidak')
            $w .= " AND ((PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR <= $dl)
                      OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE <= $dl))";

        if ($tglDaftarStart) $w .= " AND PI.CREATEDATE >= TO_DATE('$tglDaftarStart', 'YYYY-MM-DD')";
        if ($tglDaftarEnd)   $w .= " AND PI.CREATEDATE < TO_DATE('$tglDaftarEnd', 'YYYY-MM-DD') + 1";
        if ($tglKckrStart)   $w .= " AND PI.RECEIVED_DATE_KCKR >= TO_DATE('$tglKckrStart', 'YYYY-MM-DD')";
        if ($tglKckrEnd)     $w .= " AND PI.RECEIVED_DATE_KCKR < TO_DATE('$tglKckrEnd', 'YYYY-MM-DD') + 1";

        return $w;
    }

    private function buildIdInWhere(array $ids): string
    {
        if (empty($ids)) return '1=0';
        $chunks = array_chunk($ids, 1000);
        $parts  = array_map(fn($c) => 'P.ID IN (' . implode(',', $c) . ')', $chunks);
        return '(' . implode(' OR ', $parts) . ')';
    }

    private function makeRingkasanSpreadsheet(callable $rowFetcher, array $label = []): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $coord = fn(int $col, int $row) =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ringkasan');

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1976D2']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                            'wrapText'   => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
        ];
        $subStyle = array_merge($headerStyle, [
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1565C0']],
        ]);

        // Title rows (jika label tersedia)
        $hStart = 1;
        if (!empty($label)) {
            $hStart = $this->writeTitleRows($sheet, 'LAPORAN KEPATUHAN PENERBIT KCKR', $label, 15);
        }
        $h1 = $hStart;
        $h2 = $hStart + 1;

        // Baris header 1: kolom-kolom dengan merge
        $row1 = [
            1  => 'No',
            2  => 'Nama Penerbit',
            3  => 'Kategori',
            4  => 'Kota',
            5  => 'Provinsi',
            6  => 'Jml Judul',
            7  => 'Sudah KCKR',
            10 => 'Belum KCKR',
            13 => 'Terlambat',
            14 => 'Tepat Waktu',
            15 => '% KCKR',
        ];

        foreach ($row1 as $col => $lbl) {
            $sheet->getCell($coord($col, $h1))->setValue($lbl);
            $sheet->getStyle($coord($col, $h1))->applyFromArray($headerStyle);
        }

        // Merge kolom yang span 2 baris
        foreach ([1,2,3,4,5,6,13,14,15] as $col) {
            $sheet->mergeCells($coord($col, $h1) . ':' . $coord($col, $h2));
        }
        $sheet->mergeCells($coord(7, $h1) . ':' . $coord(9, $h1));
        $sheet->mergeCells($coord(10, $h1) . ':' . $coord(12, $h1));

        // Baris header 2: sub-header Sudah & Belum
        $row2 = [7 => 'Total', 8 => 'Cetak', 9 => 'Rekam', 10 => 'Total', 11 => 'Cetak', 12 => 'Rekam'];
        foreach ($row2 as $col => $lbl) {
            $sheet->getCell($coord($col, $h2))->setValue($lbl);
            $sheet->getStyle($coord($col, $h2))->applyFromArray($subStyle);
        }

        // Data mulai baris setelah 2 baris header
        $rowNum = $h2 + 1;
        $rowFetcher(function(array $rowData) use ($sheet, &$rowNum, $coord) {
            foreach ($rowData as $idx => $val) {
                $sheet->getCell($coord($idx + 1, $rowNum))->setValue($val ?? '');
            }
            $rowNum++;
        });

        $sheet->getRowDimension($h1)->setRowHeight(28);
        $sheet->getRowDimension($h2)->setRowHeight(20);

        foreach (range(1, 15) as $c) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
            )->setAutoSize(true);
        }

        $sheet->freezePane('A' . ($h2 + 1));

        return $spreadsheet;
    }

    private function addDetailSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $sp, $conn, string $sql): void
    {
        $coord = fn(int $col, int $row) =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

        $sheet = $sp->createSheet();
        $sheet->setTitle('Daftar Judul');

        $headers = ['No','Nama Penerbit','Kategori','Kota','Provinsi',
            'Judul','Pengarang','Jilid','ISBN','Jenis Media',
            'Tgl Daftar','Deadline KCKR','Tgl KCKR','Status KCKR','Terlambat','Keterangan'];

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'AAAAAA']]],
        ];

        foreach ($headers as $idx => $h) {
            $addr = $coord($idx + 1, 1);
            $sheet->getCell($addr)->setValue($h);
            $sheet->getStyle($addr)->applyFromArray($headerStyle);
        }

        $result = odbc_exec($conn, $sql);
        $rowNum = 2;
        $i = 1;
        while ($row = odbc_fetch_object($result)) {
            $kat = match((int)$row->KATEGORI_ID) { 1=>'Pemerintah', 2=>'Swasta', default=>'Lainnya' };
            $data = [
                $i++, $row->NAME, $kat, $row->CITY, $row->PROVINSI,
                $row->TITLE, $row->KEPENG, $row->JILID_VOLUME, $row->ISBN_NO,
                $row->JENIS_MEDIA === '1' ? 'Karya Cetak' : 'Karya Rekam',
                $this->fmtDate($row->TGL_DAFTAR),
                $this->fmtDate($row->DEADLINE_KCKR),
                $this->fmtDate($row->RECEIVED_DATE_KCKR),
                $row->STATUS_KCKR, $row->IS_TERLAMBAT, $row->KETERANGAN,
            ];
            foreach ($data as $idx => $val) {
                $sheet->getCell($coord($idx + 1, $rowNum))->setValue($val ?? '');
            }
            $rowNum++;
        }

        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
            )->setAutoSize(true);
        }

        $sheet->getRowDimension(1)->setRowHeight(20);
        $sheet->freezePane('A2');
    }

    private function makeSpreadsheet(array $headers, callable $rowFetcher, string $sheetTitle = 'Data', string $mainTitle = '', array $label = []): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $coord = fn(int $col, int $row) =>
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

        $hRow = 1;
        if (!empty($label) && $mainTitle) {
            $hRow = $this->writeTitleRows($sheet, $mainTitle, $label, count($headers));
        }

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1976D2']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];

        foreach ($headers as $idx => $h) {
            $addr = $coord($idx + 1, $hRow);
            $sheet->getCell($addr)->setValue($h);
            $sheet->getStyle($addr)->applyFromArray($headerStyle);
        }

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

        return $spreadsheet;
    }

    public function export(Request $request)
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $conn        = $this->getOracleConnection();
        $withDetail  = (bool) $request->get('with_detail', 0);
        $kategori    = $request->kategori    ?? null;
        $persentase  = $request->persentase  ?? null;
        $provinceIds = $request->province_ids ?? [];
        $search      = trim($request->search ?? '');

        $dateFilter    = $this->parseDateFilter($request);
        $dateWhere     = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
        $provinceWhere = $this->buildProvinceWhere($provinceIds);
        $baseQuery     = $this->buildBaseQuery($dateWhere, $provinceWhere, $kategori, $persentase, $search);

        $label    = $this->buildFilterLabel($request, $provinceIds);
        $filename = $this->buildExportFilename($label, $withDetail);

        $deadlineExpr = "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                              ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                        ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END";

        if ($withDetail) {
            $idResult = odbc_exec($conn, "SELECT ID FROM ($baseQuery)");
            $ids = [];
            while ($row = odbc_fetch_object($idResult)) {
                $ids[] = (int) $row->ID;
            }

            if (empty($ids)) {
                $this->sendDownloadCookie($request);
                $sp = $this->makeSpreadsheet(['Pesan'], fn($add) => $add(['Tidak ada data yang cocok dengan filter']));
                return $this->streamXlsx($sp, $filename, $request);
            }

            $idWhere = $this->buildIdInWhere($ids);
            $sql = "
                SELECT P.ID, P.NAME, P.CITY, P.PROVINSI, P.KATEGORI_ID,
                    PI.ISBN_NO, PI.CREATEDATE as TGL_DAFTAR, PI.RECEIVED_DATE_KCKR, PI.KETERANGAN,
                    PT.TITLE, PT.KEPENG, PT.JENIS_MEDIA, PT.JILID_VOLUME,
                    CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah' ELSE 'Belum' END as STATUS_KCKR,
                    $deadlineExpr as DEADLINE_KCKR,
                    CASE WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $deadlineExpr)
                              OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $deadlineExpr)
                         THEN 'Ya' ELSE 'Tidak' END as IS_TERLAMBAT
                FROM PENERBIT P
                JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                    $dateWhere
                    AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                WHERE $idWhere
                ORDER BY P.NAME, PI.CREATEDATE
            ";
        } else {
            $sql = "SELECT * FROM ($baseQuery) ORDER BY NAME ASC";
        }

        // Cache key berdasarkan filter saja (tanpa download_token, with_detail, dsb.)
        $exportKey = $this->makeCacheKey($request, 'compliance:export', [
            'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            'province_ids', 'kategori', 'persentase', 'search',
        ]);

        // Ringkasan rows — selalu dibutuhkan
        $ringkasanRows = Cache::remember($exportKey . ':ringkasan', 3600, function() use ($conn, $baseQuery) {
            $result = odbc_exec($conn, "SELECT * FROM ($baseQuery) ORDER BY NAME ASC");
            $rows = [];
            while ($row = odbc_fetch_object($result)) {
                $rows[] = [
                    $row->NAME, $row->KATEGORI, $row->CITY, $row->PROVINSI,
                    (int)$row->JUMLAHJUDUL,
                    (int)$row->JUMLAHSUDAHKCKR, (int)$row->SUDAHKCKR_CETAK, (int)$row->SUDAHKCKR_REKAM,
                    (int)$row->JUMLAHBELUMKCKR, (int)$row->BELUMKCKR_CETAK, (int)$row->BELUMKCKR_REKAM,
                    (int)$row->JUMLAHTERLAMBATKCKR, (int)$row->JUMLAHTEPATWAKTUKCKR, (float)$row->PERSENTASE_KCKR,
                ];
            }
            return $rows;
        });

        $i = 1;
        $sp = $this->makeRingkasanSpreadsheet(function($add) use ($ringkasanRows, &$i) {
            foreach ($ringkasanRows as $r) {
                $add(array_merge([$i++], $r));
            }
        }, $label);

        if ($withDetail) {
            if (empty($ids)) {
                return $this->streamXlsx($sp, $filename, $request);
            }

            $detailRows = Cache::remember($exportKey . ':detail', 3600, function() use ($conn, $sql) {
                $result = odbc_exec($conn, $sql);
                $rows = [];
                while ($row = odbc_fetch_object($result)) {
                    $kat = match((int)$row->KATEGORI_ID) { 1=>'Pemerintah', 2=>'Swasta', default=>'Lainnya' };
                    $rows[] = [
                        $row->NAME, $kat, $row->CITY, $row->PROVINSI,
                        $row->TITLE, $row->KEPENG, $row->JILID_VOLUME, $row->ISBN_NO,
                        $row->JENIS_MEDIA === '1' ? 'Karya Cetak' : 'Karya Rekam',
                        $this->fmtDate($row->TGL_DAFTAR),
                        $this->fmtDate($row->DEADLINE_KCKR),
                        $this->fmtDate($row->RECEIVED_DATE_KCKR),
                        $row->STATUS_KCKR, $row->IS_TERLAMBAT, $row->KETERANGAN,
                    ];
                }
                return $rows;
            });

            // Tambah Sheet 2: Daftar Judul dari cached rows
            $coord   = fn(int $col, int $row) =>
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $sheet2  = $sp->createSheet();
            $sheet2->setTitle('Daftar Judul');
            $colsDet = ['No','Nama Penerbit','Kategori','Kota','Provinsi',
                'Judul','Pengarang','Jilid','ISBN','Jenis Media',
                'Tgl Daftar','Deadline KCKR','Tgl KCKR','Status KCKR','Terlambat','Keterangan'];
            $hRow2   = $this->writeTitleRows($sheet2, 'DAFTAR JUDUL COMPLIANCE KCKR', $label, count($colsDet));
            $hStyle  = [
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
                'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
            ];
            foreach ($colsDet as $idx => $h) {
                $addr = $coord($idx + 1, $hRow2);
                $sheet2->getCell($addr)->setValue($h);
                $sheet2->getStyle($addr)->applyFromArray($hStyle);
            }
            $rowNum = $hRow2 + 1; $j = 1;
            foreach ($detailRows as $r) {
                foreach (array_merge([$j++], $r) as $idx => $val) {
                    $sheet2->getCell($coord($idx + 1, $rowNum))->setValue($val ?? '');
                }
                $rowNum++;
            }
            foreach (range(1, count($colsDet)) as $c) {
                $sheet2->getColumnDimension(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
                )->setAutoSize(true);
            }
            $sheet2->freezePane('A' . ($hRow2 + 1));
        }

        return $this->streamXlsx($sp, $filename, $request);
    }

    public function exportDetail(Request $request, $id)
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $conn        = $this->getOracleConnection();
        $penerbitId  = (int) $id;
        $dateFilter  = $this->parseDateFilter($request);
        $dateWhere   = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
        $searchWhere = $this->buildDetailSearchWhere($request);

        $pResult     = odbc_exec($conn, "SELECT P.NAME FROM PENERBIT P WHERE P.ID = $penerbitId");
        $penerbit    = odbc_fetch_object($pResult);
        $penerbitName = $penerbit->NAME ?? "penerbit_$penerbitId";
        $filename    = $this->safeName($penerbitName) . '_' . date('d-m-Y') . '.xlsx';
        $label       = $this->buildFilterLabel($request);

        $deadlineExpr = "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                              ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                        ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END";

        $sql = "
            SELECT PI.ISBN_NO, PI.CREATEDATE as TGL_DAFTAR, PI.RECEIVED_DATE_KCKR, PI.KETERANGAN,
                PT.TITLE, PT.KEPENG, PT.JENIS_MEDIA, PT.JILID_VOLUME,
                CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 'Sudah' ELSE 'Belum' END as STATUS_KCKR,
                $deadlineExpr as DEADLINE_KCKR,
                CASE WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > $deadlineExpr)
                          OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $deadlineExpr)
                     THEN 'Ya' ELSE 'Tidak' END as IS_TERLAMBAT
            FROM PENERBIT P
            JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                $dateWhere
                AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
            LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
            WHERE P.ID = $penerbitId
                $searchWhere
            ORDER BY TGL_DAFTAR DESC
        ";

        $detailKey = $this->makeCacheKey($request, 'compliance:export_detail:' . $penerbitId, [
            'filter_type', 'filter_year', 'filter_month', 'start_date', 'end_date',
            'search_judul', 'search_isbn', 'search_pengarang', 'search_jilid',
            'filter_jenis', 'filter_status', 'filter_terlambat',
            'tgl_daftar_start', 'tgl_daftar_end', 'tgl_kckr_start', 'tgl_kckr_end',
        ]);

        $rows = Cache::remember($detailKey, 3600, function() use ($conn, $sql) {
            $result = odbc_exec($conn, $sql);
            $rows = [];
            while ($row = odbc_fetch_object($result)) {
                $rows[] = [
                    $row->TITLE, $row->KEPENG, $row->JILID_VOLUME, $row->ISBN_NO,
                    $row->JENIS_MEDIA === '1' ? 'Karya Cetak' : 'Karya Rekam',
                    $this->fmtDate($row->TGL_DAFTAR),
                    $this->fmtDate($row->DEADLINE_KCKR),
                    $this->fmtDate($row->RECEIVED_DATE_KCKR),
                    $row->STATUS_KCKR, $row->IS_TERLAMBAT, $row->KETERANGAN,
                ];
            }
            return $rows;
        });

        $headers = ['No','Judul','Pengarang','Jilid','ISBN','Jenis Media',
            'Tgl Daftar','Deadline KCKR','Tgl KCKR','Status KCKR','Terlambat','Keterangan'];
        $i = 1;
        $sp = $this->makeSpreadsheet($headers, function($add) use ($rows, &$i) {
            foreach ($rows as $r) {
                $add(array_merge([$i++], $r));
            }
        }, 'Judul', 'DAFTAR JUDUL - ' . strtoupper($penerbitName), $label);

        return $this->streamXlsx($sp, $filename, $request);
    }

    public function testConnection()
    {
        try {
            $conn   = $this->getOracleConnection();
            $result = odbc_exec($conn, "SELECT 1 FROM DUAL");
            $row    = odbc_fetch_object($result);
            return response()->json(['status' => 'success', 'message' => '✅ Connection OK!', 'data' => $row]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => '❌ ' . $e->getMessage()], 500);
        }
    }
}
