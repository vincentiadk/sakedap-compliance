<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const DSN = 'Driver={Oracle in instantclient_23_0};DBQ=(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)(HOST=localhost)(PORT=1521)))(CONNECT_DATA=(SERVER=DEDICATED)(SID=INLISSTY)));';

    private function getOracleConnection()
    {
        $conn = odbc_pconnect(self::DSN, config('database.connections.odbc.username'), config('database.connections.odbc.password'));

        if (!$conn) {
            throw new \Exception("Connection failed: " . odbc_errormsg());
        }

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
            $end   = date('Y-m-d', strtotime($end . ' +1 day'));
        } else {
            $year  = $request->filter_year ?? 2026;
            $start = "{$year}-01-01";
            $end   = ($year + 1) . "-01-01";
        }

        return compact('type', 'start', 'end');
    }

    private function fetchProvinces($conn): array
    {
        $result    = odbc_exec($conn, "SELECT ID, NAMAPROPINSI FROM PROPINSI ORDER BY NAMAPROPINSI");
        $provinces = [];
        while ($row = odbc_fetch_object($result)) {
            $provinces[] = $row;
        }
        return $provinces;
    }

    private function buildProvinceWhere(array $provinceIds): string
    {
        if (empty($provinceIds)) return '';
        $ids = implode(',', array_map('intval', $provinceIds));
        return "AND P.PROVINCE_ID IN ($ids)";
    }

    public function index(Request $request)
    {
        try {
            $conn        = $this->getOracleConnection();
            $dateFilter  = $this->parseDateFilter($request);
            $start_date  = $dateFilter['start'];
            $end_date    = $dateFilter['end'];
            $provinceIds = $request->province_ids ?? [];
            $provinces   = $this->fetchProvinces($conn);

            $whereProvinsi = $this->buildProvinceWhere($provinceIds);

            // Query distribusi kepatuhan
            $query = "
                SELECT
                    CASE
                        WHEN PERSENTASE_KCKR BETWEEN 0  AND 20  THEN 'Sangat Tidak Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 21 AND 40  THEN 'Tidak Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 41 AND 60  THEN 'Cukup Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 61 AND 80  THEN 'Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 81 AND 100 THEN 'Sangat Patuh'
                    END as KATEGORI_PATUH,
                    COUNT(*) as JUMLAH,
                    ROUND(AVG(PERSENTASE_KCKR), 1) as RATA_RATA_PCT,
                    SUM(JUMLAHJUDUL) as TOTAL_JUDUL,
                    SUM(JUMLAHSUDAHKCKR) as TOTAL_KCKR
                FROM (
                    SELECT
                        P.ID,
                        COUNT(DISTINCT PI.ID) as JUMLAHJUDUL,
                        SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as JUMLAHSUDAHKCKR,
                        ROUND(
                            CASE WHEN COUNT(DISTINCT PI.ID) > 0
                                THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) / COUNT(DISTINCT PI.ID) * 100
                                ELSE 0 END, 1
                        ) as PERSENTASE_KCKR
                    FROM PENERBIT P
                    LEFT JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                        AND PI.CREATEDATE >= TO_DATE('$start_date', 'YYYY-MM-DD')
                        AND PI.CREATEDATE < TO_DATE('$end_date', 'YYYY-MM-DD')
                        AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                    LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                    WHERE 1=1 $whereProvinsi
                    GROUP BY P.ID
                    HAVING COUNT(DISTINCT PI.ID) > 0
                )
                GROUP BY
                    CASE
                        WHEN PERSENTASE_KCKR BETWEEN 0  AND 20  THEN 'Sangat Tidak Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 21 AND 40  THEN 'Tidak Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 41 AND 60  THEN 'Cukup Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 61 AND 80  THEN 'Patuh'
                        WHEN PERSENTASE_KCKR BETWEEN 81 AND 100 THEN 'Sangat Patuh'
                    END
                ORDER BY MIN(PERSENTASE_KCKR)
            ";

            $result = odbc_exec($conn, $query);
            $distribusi = [];
            while ($row = odbc_fetch_object($result)) {
                $distribusi[] = $row;
            }

            // Query total keseluruhan
            $queryTotal = "
                SELECT
                    COUNT(*) as TOTAL_PENERBIT,
                    SUM(JUMLAHJUDUL) as TOTAL_JUDUL,
                    SUM(JUMLAHSUDAHKCKR) as TOTAL_KCKR,
                    ROUND(AVG(PERSENTASE_KCKR), 1) as RATA_RATA_KEPATUHAN
                FROM (
                    SELECT
                        P.ID,
                        COUNT(DISTINCT PI.ID) as JUMLAHJUDUL,
                        SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as JUMLAHSUDAHKCKR,
                        ROUND(
                            CASE WHEN COUNT(DISTINCT PI.ID) > 0
                                THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) / COUNT(DISTINCT PI.ID) * 100
                                ELSE 0 END, 1
                        ) as PERSENTASE_KCKR
                    FROM PENERBIT P
                    LEFT JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                        AND PI.CREATEDATE >= TO_DATE('$start_date', 'YYYY-MM-DD')
                        AND PI.CREATEDATE < TO_DATE('$end_date', 'YYYY-MM-DD')
                        AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                    LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                    WHERE 1=1 $whereProvinsi
                    GROUP BY P.ID
                    HAVING COUNT(DISTINCT PI.ID) > 0
                )
            ";

            $resultTotal = odbc_exec($conn, $queryTotal);
            $total       = odbc_fetch_object($resultTotal);

            return view('dashboard', compact('distribusi', 'total', 'start_date', 'end_date', 'provinceIds', 'provinces', 'dateFilter'));

        } catch (\Exception $e) {
            return view('dashboard', ['error' => 'Error: ' . $e->getMessage(), 'distribusi' => [], 'total' => null, 'provinces' => [], 'provinceIds' => []]);
        }
    }
}
