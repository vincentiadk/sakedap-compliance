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

            $cutoff = '2026-01-01';
            $hasV1  = $start_date < $cutoff;
            $hasV2  = $end_date   > $cutoff;
            $isV2   = !$hasV1 && $hasV2;   // pure 2026+
            $isMixed = $hasV1 && $hasV2;   // range melintasi 2026

            $provinces = array_map(
                fn($r) => (object) $r,
                Cache::remember('dashboard:provinces', 3600, fn() =>
                    array_map(fn($r) => (array) $r, $this->fetchProvinces($conn))
                )
            );

            $whereProvinsi = $this->buildProvinceWhere($provinceIds);
            $cacheKey      = $this->makeCacheKey($request, 'dashboard', [
                'filter_type', 'filter_year', 'filter_month',
                'start_date', 'end_date', 'province_ids',
            ]);

            if ($isMixed) {
                // ── Logika hybrid: range mencakup pre-2026 DAN 2026+ ──────
                $cached = Cache::remember($cacheKey, 3600, function() use ($conn, $start_date, $end_date, $cutoff, $whereProvinsi) {
                    $dlTerbit  = 'PI.CREATEDATE + 28';
                    $dlKckrV1  = "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                       ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                 ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END";
                    $dlKckrV2  = "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3)
                                       ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3)
                                                 ELSE ADD_MONTHS(PI.TANGGAL_TERBIT, 12) END END";

                    // Distribusi berdasarkan % KCKR (V1-style: sudah / total)
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
                            SUM(TOTAL_JUDUL) as TOTAL_JUDUL,
                            SUM(SUDAH_KCKR) as TOTAL_KCKR
                        FROM (
                            SELECT
                                P.ID,
                                COUNT(DISTINCT PI.ID) as TOTAL_JUDUL,
                                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as SUDAH_KCKR,
                                ROUND(
                                    CASE WHEN COUNT(DISTINCT PI.ID) > 0
                                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END)
                                           / COUNT(DISTINCT PI.ID) * 100
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

                    // Total: V1 metrics (semua) + V2 metrics (hanya 2026+)
                    $queryTotal = "
                        SELECT
                            COUNT(*) as TOTAL_PENERBIT,
                            SUM(TOTAL_JUDUL) as TOTAL_JUDUL,
                            SUM(SUDAH_KCKR) as TOTAL_KCKR,
                            SUM(BELUM_KCKR) as TOTAL_BELUM_KCKR,
                            ROUND(AVG(PERSENTASE_KCKR), 1) as RATA_RATA_KEPATUHAN,
                            -- V2 terbit metrics (hanya records 2026+)
                            SUM(JUDUL_TERBIT) as TOTAL_TERBIT,
                            SUM(JUDUL_BELUM_TERBIT) as TOTAL_BELUM_TERBIT,
                            SUM(HUTANG_TERBIT) as TOTAL_HUTANG_TERBIT,
                            SUM(LEWAT_TEGURAN) as TOTAL_LEWAT_TEGURAN,
                            -- Rekomendasi: V2 logic jika ada data 2026+, V1 jika tidak
                            COUNT(CASE WHEN LEWAT_TEGURAN > 0 THEN 1 END) as PENERBIT_LEWAT_TEGURAN,
                            COUNT(CASE WHEN LEWAT_TEGURAN = 0 AND TERLAMBAT_KCKR > 0 AND PERSENTASE_KCKR <= 20 THEN 1 END) as PENERBIT_BLOKIR_KCKR,
                            COUNT(CASE WHEN LEWAT_TEGURAN = 0 AND (TERLAMBAT_KCKR = 0 OR PERSENTASE_KCKR > 20) THEN 1 END) as PENERBIT_BAIK
                        FROM (
                            SELECT
                                P.ID,
                                COUNT(DISTINCT PI.ID) as TOTAL_JUDUL,
                                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as SUDAH_KCKR,
                                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NULL THEN 1 ELSE 0 END) as BELUM_KCKR,
                                -- Terlambat KCKR: pre-2026 pakai dlKckrV1, 2026+ pakai dlKckrV2
                                SUM(CASE
                                    WHEN PI.CREATEDATE < TO_DATE('$cutoff', 'YYYY-MM-DD') AND (
                                        (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1))
                                     OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV1))
                                    ) THEN 1
                                    WHEN PI.CREATEDATE >= TO_DATE('$cutoff', 'YYYY-MM-DD') AND PI.TANGGAL_TERBIT IS NOT NULL AND (
                                        (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV2))
                                     OR (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV2))
                                    ) THEN 1
                                    ELSE 0 END) as TERLAMBAT_KCKR,
                                -- V2 terbit: hanya untuk records 2026+
                                COUNT(DISTINCT CASE WHEN PI.CREATEDATE >= TO_DATE('$cutoff', 'YYYY-MM-DD')
                                    AND (PI.TANGGAL_TERBIT IS NOT NULL OR PI.RECEIVED_DATE_KCKR IS NOT NULL)
                                    THEN PI.ID END) as JUDUL_TERBIT,
                                COUNT(DISTINCT CASE WHEN PI.CREATEDATE >= TO_DATE('$cutoff', 'YYYY-MM-DD')
                                    AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL
                                    THEN PI.ID END) as JUDUL_BELUM_TERBIT,
                                SUM(CASE WHEN PI.CREATEDATE >= TO_DATE('$cutoff', 'YYYY-MM-DD')
                                    AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL
                                    AND SYSDATE > $dlTerbit THEN 1 ELSE 0 END) as HUTANG_TERBIT,
                                SUM(CASE WHEN PI.CREATEDATE >= TO_DATE('$cutoff', 'YYYY-MM-DD')
                                    AND PI.TANGGAL_TERBIT IS NULL AND PI.RECEIVED_DATE_KCKR IS NULL
                                    AND SYSDATE > ($dlTerbit + 30) THEN 1 ELSE 0 END) as LEWAT_TEGURAN,
                                ROUND(
                                    CASE WHEN COUNT(DISTINCT PI.ID) > 0
                                        THEN SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END)
                                           / COUNT(DISTINCT PI.ID) * 100
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

            } elseif ($isV2) {
                // ── Logika 2026+: berbasis tanggal_terbit ──────────────────
                $cached = Cache::remember($cacheKey, 3600, function() use ($conn, $start_date, $end_date, $whereProvinsi) {
                    $dlTerbit = 'PI.CREATEDATE + 28';

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

                    $queryTotal = "
                        SELECT
                            COUNT(*) as TOTAL_PENERBIT,
                            SUM(TOTAL_JUDUL) as TOTAL_JUDUL,
                            SUM(JUDUL_TERBIT) as TOTAL_TERBIT,
                            SUM(JUDUL_BELUM_TERBIT) as TOTAL_BELUM_TERBIT,
                            SUM(HUTANG_TERBIT) as TOTAL_HUTANG_TERBIT,
                            SUM(LEWAT_TEGURAN) as TOTAL_LEWAT_TEGURAN,
                            COUNT(CASE WHEN LEWAT_TEGURAN > 0 THEN 1 END) as PENERBIT_LEWAT_TEGURAN,
                            COUNT(CASE WHEN LEWAT_TEGURAN = 0 AND TERLAMBAT_KCKR > 0 AND PERSENTASE_KCKR <= 20 THEN 1 END) as PENERBIT_BLOKIR_KCKR,
                            COUNT(CASE WHEN LEWAT_TEGURAN = 0 AND (TERLAMBAT_KCKR = 0 OR PERSENTASE_KCKR > 20) THEN 1 END) as PENERBIT_BAIK,
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
                                SUM(CASE WHEN PI.TANGGAL_TERBIT IS NOT NULL AND (
                                              (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3) ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3) ELSE ADD_MONTHS(PI.TANGGAL_TERBIT, 12) END END)
                                           OR (PI.RECEIVED_DATE_KCKR IS NULL    AND SYSDATE        > CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3) ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.TANGGAL_TERBIT, 3) ELSE ADD_MONTHS(PI.TANGGAL_TERBIT, 12) END END)
                                         ) THEN 1 ELSE 0 END) as TERLAMBAT_KCKR,
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
                $cached = Cache::remember($cacheKey, 3600, function() use ($conn, $start_date, $end_date, $whereProvinsi) {
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

                    $dlKckrV1 = "CASE WHEN P.KATEGORI_ID = 1 THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                      ELSE CASE WHEN PT.JENIS_MEDIA = '1' THEN ADD_MONTHS(PI.CREATEDATE, 3)
                                                ELSE ADD_MONTHS(PI.CREATEDATE, 12) END END";
                    $queryTotal = "
                        SELECT
                            COUNT(*) as TOTAL_PENERBIT,
                            SUM(JUMLAHJUDUL) as TOTAL_JUDUL,
                            SUM(JUMLAHSUDAHKCKR) as TOTAL_KCKR,
                            ROUND(AVG(PERSENTASE_KCKR), 1) as RATA_RATA_KEPATUHAN,
                            COUNT(CASE WHEN JUMLAHTERLAMBATKCKR > 0 AND PERSENTASE_KCKR <= 20 THEN 1 END) as PENERBIT_BLOKIR_KCKR,
                            COUNT(CASE WHEN NOT (JUMLAHTERLAMBATKCKR > 0 AND PERSENTASE_KCKR <= 20) THEN 1 END) as PENERBIT_BAIK
                        FROM (
                            SELECT
                                P.ID,
                                COUNT(DISTINCT PI.ID) as JUMLAHJUDUL,
                                SUM(CASE WHEN PI.RECEIVED_DATE_KCKR IS NOT NULL THEN 1 ELSE 0 END) as JUMLAHSUDAHKCKR,
                                SUM(CASE WHEN (PI.RECEIVED_DATE_KCKR IS NOT NULL AND PI.RECEIVED_DATE_KCKR > ($dlKckrV1))
                                          OR  (PI.RECEIVED_DATE_KCKR IS NULL AND SYSDATE > ($dlKckrV1))
                                         THEN 1 ELSE 0 END) as JUMLAHTERLAMBATKCKR,
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
                'provinceIds', 'provinces', 'dateFilter', 'isV2', 'hasV2', 'isMixed'
            ));

        } catch (\Exception $e) {
            return view('dashboard', [
                'error' => 'Error: ' . $e->getMessage(),
                'distribusi' => [], 'total' => null,
                'provinces' => [], 'provinceIds' => [],
                'isV2' => false, 'hasV2' => false, 'isMixed' => false,
            ]);
        }
    }
}
