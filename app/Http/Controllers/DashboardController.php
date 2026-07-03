<?php

namespace App\Http\Controllers;

use App\Traits\OracleHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    use OracleHelper;

    public function index(Request $request)
    {
        try {
            $conn        = $this->getOracleConnection();
            $dateFilter  = $this->parseDateFilter($request);
            $start_date  = $dateFilter['start'];
            $end_date    = $dateFilter['end'];
            $provinceIds = $request->province_ids ?? [];
            $isV2        = (int)($request->filter_year ?? date('Y')) >= 2026
                           || ($dateFilter['type'] === 'range' && substr($dateFilter['start'], 0, 4) >= '2026');

            $provinces = array_map(
                fn($r) => (object) $r,
                Cache::remember('dashboard:provinces', 900, fn() =>
                    array_map(fn($r) => (array) $r, $this->fetchProvinces($conn))
                )
            );

            $whereProvinsi = $this->buildProvinceWhere($provinceIds);
            $cacheKey      = $this->makeCacheKey($request, 'dashboard', [
                'filter_type', 'filter_year', 'filter_month',
                'start_date', 'end_date', 'province_ids',
            ]);

            if ($isV2) {
                // ── Logika 2026+: berbasis tanggal_terbit ──────────────────
                $cached = Cache::remember($cacheKey, 900, function() use ($conn, $start_date, $end_date, $whereProvinsi) {
                    $dlTerbit = 'PI.CREATEDATE + 28';

                    // Distribusi kepatuhan — % KCKR dari judul yang sudah terbit
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
                            SUM(JUDUL_TERBIT) as TOTAL_JUDUL,
                            SUM(SUDAH_KCKR) as TOTAL_KCKR
                        FROM (
                            SELECT
                                P.ID,
                                COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) as JUDUL_TERBIT,
                                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as SUDAH_KCKR,
                                ROUND(
                                    CASE WHEN COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) > 0
                                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END)
                                           / COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) * 100
                                        ELSE 0 END, 1
                                ) as PERSENTASE_KCKR
                            FROM PENERBIT P
                            LEFT JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                                AND PI.CREATEDATE >= TO_DATE('$start_date', 'YYYY-MM-DD')
                                AND PI.CREATEDATE <  TO_DATE('$end_date',   'YYYY-MM-DD')
                                AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                            LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                            WHERE 1=1 $whereProvinsi
                            GROUP BY P.ID
                            HAVING COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) > 0
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
                    $result     = odbc_exec($conn, $query);
                    $distribusi = [];
                    while ($row = odbc_fetch_object($result)) {
                        $distribusi[] = (array) $row;
                    }

                    // Total + status terbit
                    $queryTotal = "
                        SELECT
                            COUNT(*) as TOTAL_PENERBIT,
                            SUM(TOTAL_JUDUL) as TOTAL_JUDUL,
                            SUM(JUDUL_TERBIT) as TOTAL_TERBIT,
                            SUM(JUDUL_BELUM_TERBIT) as TOTAL_BELUM_TERBIT,
                            SUM(HUTANG_TERBIT) as TOTAL_HUTANG_TERBIT,
                            SUM(LEWAT_TEGURAN) as TOTAL_LEWAT_TEGURAN,
                            SUM(SUDAH_KCKR) as TOTAL_KCKR,
                            SUM(BELUM_KCKR) as TOTAL_BELUM_KCKR,
                            ROUND(AVG(PERSENTASE_KCKR), 1) as RATA_RATA_KEPATUHAN
                        FROM (
                            SELECT
                                P.ID,
                                COUNT(DISTINCT PI.ID) as TOTAL_JUDUL,
                                COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) as JUDUL_TERBIT,
                                COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL THEN PI.ID END) as JUDUL_BELUM_TERBIT,
                                SUM(CASE WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > $dlTerbit THEN 1 ELSE 0 END) as HUTANG_TERBIT,
                                SUM(CASE WHEN PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlTerbit + 30) THEN 1 ELSE 0 END) as LEWAT_TEGURAN,
                                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as SUDAH_KCKR,
                                SUM(CASE WHEN (PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL) AND PI.RECEIVED_DATE_KCKR IS NULL THEN 1 ELSE 0 END) as BELUM_KCKR,
                                ROUND(
                                    CASE WHEN COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) > 0
                                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END)
                                           / COUNT(DISTINCT CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL THEN PI.ID END) * 100
                                        ELSE 0 END, 1
                                ) as PERSENTASE_KCKR
                            FROM PENERBIT P
                            LEFT JOIN PENERBIT_ISBN PI ON P.ID = PI.PENERBIT_ID
                                AND PI.CREATEDATE >= TO_DATE('$start_date', 'YYYY-MM-DD')
                                AND PI.CREATEDATE <  TO_DATE('$end_date',   'YYYY-MM-DD')
                                AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                            LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                            WHERE 1=1 $whereProvinsi
                            GROUP BY P.ID
                            HAVING COUNT(DISTINCT PI.ID) > 0
                        )
                    ";
                    $resultTotal = odbc_exec($conn, $queryTotal);
                    $total       = (array) odbc_fetch_object($resultTotal);

                    return compact('distribusi', 'total');
                });

            } else {
                // ── Logika s.d 2025: berbasis createdate ───────────────────
                $cached = Cache::remember($cacheKey, 900, function() use ($conn, $start_date, $end_date, $whereProvinsi) {
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
                                AND PI.CREATEDATE <  TO_DATE('$end_date',   'YYYY-MM-DD')
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
                    $result     = odbc_exec($conn, $query);
                    $distribusi = [];
                    while ($row = odbc_fetch_object($result)) {
                        $distribusi[] = (array) $row;
                    }

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
                                AND PI.CREATEDATE <  TO_DATE('$end_date',   'YYYY-MM-DD')
                                AND (NOT UPPER(PI.KETERANGAN) LIKE '%LENGKAP%' OR UPPER(PI.KETERANGAN) IS NULL)
                            LEFT JOIN PENERBIT_TERBITAN PT ON PI.PENERBIT_TERBITAN_ID = PT.ID
                            WHERE 1=1 $whereProvinsi
                            GROUP BY P.ID
                            HAVING COUNT(DISTINCT PI.ID) > 0
                        )
                    ";
                    $resultTotal = odbc_exec($conn, $queryTotal);
                    $total       = (array) odbc_fetch_object($resultTotal);

                    return compact('distribusi', 'total');
                });
            }

            $distribusi = array_map(fn($r) => (object) $r, $cached['distribusi']);
            $total      = (object) $cached['total'];

            return view('dashboard', compact(
                'distribusi', 'total', 'start_date', 'end_date',
                'provinceIds', 'provinces', 'dateFilter', 'isV2'
            ));

        } catch (\Exception $e) {
            return view('dashboard', [
                'error' => 'Error: ' . $e->getMessage(),
                'distribusi' => [], 'total' => null,
                'provinces' => [], 'provinceIds' => [],
                'isV2' => false,
            ]);
        }
    }
}
