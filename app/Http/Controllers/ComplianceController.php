<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ComplianceController extends Controller
{
    private const PER_PAGE = 25;
    private const DSN = 'Driver={Oracle in instantclient_23_0};DBQ=(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)(HOST=localhost)(PORT=1521)))(CONNECT_DATA=(SERVER=DEDICATED)(SID=INLISSTY)));';

    private function getOracleConnection()
    {
        $conn = odbc_pconnect(self::DSN, config('database.connections.odbc.username'), config('database.connections.odbc.password'));
        if (!$conn) throw new \Exception("Connection failed: " . odbc_errormsg());
        return $conn;
    }

    private function parseDateFilter(Request $request): array
    {
        $type = $request->filter_type ?? 'tahun';

        if ($type === 'bulan') {
            $year  = $request->filter_year  ?? 2026;
            $month = $request->filter_month ?? 1;
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-d', strtotime("+1 month", strtotime($start)));
        } elseif ($type === 'range') {
            $start = $request->start_date ?? '2026-01-01';
            $end   = $request->end_date   ?? '2026-12-31';
            // end_date inclusive → add 1 day
            $end = date('Y-m-d', strtotime($end . ' +1 day'));
        } else {
            // tahun (default)
            $year  = $request->filter_year ?? 2026;
            $start = "{$year}-01-01";
            $end   = ($year + 1) . "-01-01";
        }

        return compact('type', 'start', 'end');
    }

    private function buildProvinceWhere(array $provinceIds): string
    {
        if (empty($provinceIds)) return '';
        $ids = implode(',', array_map('intval', $provinceIds));
        return "AND P.PROVINCE_ID IN ($ids)";
    }

    private function buildDateWhere(string $start, string $end): string
    {
        return "AND PI.CREATEDATE >= TO_DATE('$start', 'YYYY-MM-DD')
                AND PI.CREATEDATE <  TO_DATE('$end',   'YYYY-MM-DD')";
    }

    private function buildBaseQuery(string $dateWhere, string $provinceWhere, ?string $kategori, ?string $persentase, ?string $search = null): string
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

        if (!empty($persentase)) {
            [$min, $max] = $this->parsePersentaseRange($persentase);
            $query = "SELECT * FROM ($query) WHERE PERSENTASE_KCKR BETWEEN $min AND $max";
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
                         THEN 1 ELSE 0 END) as TOTAL_TERLAMBAT
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
                SUM(JUMLAHTERLAMBATKCKR) as TOTAL_TERLAMBAT
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

    private function fetchProvinces($conn): array
    {
        $result = odbc_exec($conn, "SELECT ID, NAMAPROPINSI FROM PROPINSI ORDER BY NAMAPROPINSI");
        $provinces = [];
        while ($row = odbc_fetch_object($result)) {
            $provinces[] = $row;
        }
        return $provinces;
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
            $provinces = $this->fetchProvinces($conn);
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
            $kategori    = $request->kategori    ?? null;
            $persentase  = $request->persentase  ?? null;
            $provinceIds = $request->province_ids ?? [];
            $search      = trim($request->search ?? '');

            $dateFilter    = $this->parseDateFilter($request);
            $dateWhere     = $this->buildDateWhere($dateFilter['start'], $dateFilter['end']);
            $provinceWhere = $this->buildProvinceWhere($provinceIds);

            $sortCol = $request->sort_col ?? 'CREATEDATE';
            $sortDir = $request->sort_dir ?? 'DESC';

            // Total keseluruhan: tanpa filter apapun (semua data)
            $summary   = $this->fetchSummary($conn, '', '', null, null);
            // Subtotal: semua filter aktif
            $baseQuery = $this->buildBaseQuery($dateWhere, $provinceWhere, $kategori, $persentase, $search);
            $subtotal  = $this->fetchSubtotal($conn, $baseQuery);
            $paginated = $this->fetchPaginated($conn, $baseQuery, $page, $sortCol, $sortDir);

            return response()->json([
                'summary'      => $summary,
                'subtotal'     => $subtotal,
                'data'         => $paginated['data'],
                'total'        => $paginated['total'],
                'current_page' => $paginated['current_page'],
                'last_page'    => $paginated['last_page'],
                'per_page'     => $paginated['per_page'],
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

            $pResult  = odbc_exec($conn, "SELECT P.ID, P.NAME, P.ALAMAT, P.PROVINSI, P.CITY, P.KATEGORI_ID FROM PENERBIT P WHERE P.ID = $penerbitId");
            $penerbit = odbc_fetch_object($pResult);
            if (!$penerbit) abort(404, 'Penerbit tidak ditemukan');

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

            // Summary: tanpa search filter (total penerbit di periode ini)
            $summaryResult = odbc_exec($conn, "
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
            $summary = odbc_fetch_object($summaryResult);

            // Count with search filter (untuk pagination)
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
                $titles[] = $row;
            }

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

    private function fmtDate(?string $val): string
    {
        return $val ? date('d/m/Y', strtotime($val)) : '';
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

    private function xlCell(string $val): string
    {
        return '<td>' . htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</td>';
    }

    private function xlRow(array $cells): string
    {
        return '<tr>' . implode('', array_map(fn($v) => $this->xlCell((string)($v ?? '')), $cells)) . '</tr>' . "\n";
    }

    private function xlHeader(array $cells): string
    {
        return '<tr>' . implode('', array_map(fn($v) => '<th style="background:#1976D2;color:#fff;font-weight:bold;border:1px solid #ccc">' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</th>', $cells)) . '</tr>' . "\n";
    }

    private function sendDownloadCookie(Request $request): void
    {
        $token = $request->get('download_token', '');
        if ($token) {
            setcookie('dl_' . preg_replace('/[^a-z0-9]/i', '', $token), '1', time() + 120, '/');
        }
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

        $filename = 'compliance_' . date('Ymd_His') . ($withDetail ? '_lengkap' : '') . '.xls';

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
                return response('<html><body><table><tr><td>Tidak ada data</td></tr></table></body></html>', 200, [
                    'Content-Type'        => 'application/vnd.ms-excel',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
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

        $html  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $html .= '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $html .= '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        $html .= '<x:Name>Data</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        $html .= '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
        $html .= '<table border="1" style="border-collapse:collapse;font-size:11pt;font-family:Arial">' . "\n";

        $result = odbc_exec($conn, $sql);

        if (!$withDetail) {
            $html .= $this->xlHeader(['No','Nama Penerbit','Kategori','Kota','Provinsi',
                'Jml Judul','Sudah KCKR','Sudah Cetak','Sudah Rekam',
                'Belum KCKR','Belum Cetak','Belum Rekam',
                'Terlambat','Tepat Waktu','% KCKR']);
            $i = 1;
            while ($row = odbc_fetch_object($result)) {
                $html .= $this->xlRow([
                    $i++, $row->NAME, $row->KATEGORI, $row->CITY, $row->PROVINSI,
                    $row->JUMLAHJUDUL, $row->JUMLAHSUDAHKCKR, $row->SUDAHKCKR_CETAK, $row->SUDAHKCKR_REKAM,
                    $row->JUMLAHBELUMKCKR, $row->BELUMKCKR_CETAK, $row->BELUMKCKR_REKAM,
                    $row->JUMLAHTERLAMBATKCKR, $row->JUMLAHTEPATWAKTUKCKR, $row->PERSENTASE_KCKR,
                ]);
            }
        } else {
            $html .= $this->xlHeader(['No','Nama Penerbit','Kategori','Kota','Provinsi',
                'Judul','Pengarang','Jilid','ISBN','Jenis Media',
                'Tgl Daftar','Deadline KCKR','Tgl KCKR','Status KCKR','Terlambat','Keterangan']);
            $i = 1;
            while ($row = odbc_fetch_object($result)) {
                $kat = match((int)$row->KATEGORI_ID) { 1=>'Pemerintah', 2=>'Swasta', default=>'Lainnya' };
                $html .= $this->xlRow([
                    $i++, $row->NAME, $kat, $row->CITY, $row->PROVINSI,
                    $row->TITLE, $row->KEPENG, $row->JILID_VOLUME, $row->ISBN_NO,
                    $row->JENIS_MEDIA === '1' ? 'Karya Cetak' : 'Karya Rekam',
                    $this->fmtDate($row->TGL_DAFTAR),
                    $this->fmtDate($row->DEADLINE_KCKR),
                    $this->fmtDate($row->RECEIVED_DATE_KCKR),
                    $row->STATUS_KCKR, $row->IS_TERLAMBAT, $row->KETERANGAN,
                ]);
            }
        }

        $html .= '</table></body></html>';

        $this->sendDownloadCookie($request);

        return response($html, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ]);
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

        $pResult  = odbc_exec($conn, "SELECT P.NAME FROM PENERBIT P WHERE P.ID = $penerbitId");
        $penerbit = odbc_fetch_object($pResult);
        $safeName = preg_replace('/[^a-zA-Z0-9]+/', '_', $penerbit->NAME ?? "penerbit_$penerbitId");
        $filename = 'judul_' . $safeName . '_' . date('Ymd') . '.xls';

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

        $html  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $html .= '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $html .= '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        $html .= '<x:Name>Judul</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        $html .= '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
        $html .= '<table border="1" style="border-collapse:collapse;font-size:11pt;font-family:Arial">' . "\n";
        $html .= $this->xlHeader(['No','Judul','Pengarang','Jilid','ISBN','Jenis Media',
            'Tgl Daftar','Deadline KCKR','Tgl KCKR','Status KCKR','Terlambat','Keterangan']);

        $result = odbc_exec($conn, $sql);
        $i = 1;
        while ($row = odbc_fetch_object($result)) {
            $html .= $this->xlRow([
                $i++, $row->TITLE, $row->KEPENG, $row->JILID_VOLUME, $row->ISBN_NO,
                $row->JENIS_MEDIA === '1' ? 'Karya Cetak' : 'Karya Rekam',
                $this->fmtDate($row->TGL_DAFTAR),
                $this->fmtDate($row->DEADLINE_KCKR),
                $this->fmtDate($row->RECEIVED_DATE_KCKR),
                $row->STATUS_KCKR, $row->IS_TERLAMBAT, $row->KETERANGAN,
            ]);
        }

        $html .= '</table></body></html>';

        $this->sendDownloadCookie($request);

        return response($html, 200, [
            'Content-Type'        => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ]);
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
