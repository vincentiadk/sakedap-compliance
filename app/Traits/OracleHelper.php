<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

trait OracleHelper
{
    protected function getOracleConnection()
    {
        $conn = odbc_pconnect(
            config('database.connections.odbc.dsn'),
            config('database.connections.odbc.username'),
            config('database.connections.odbc.password')
        );
        if (!$conn) {
            throw new \Exception("Connection failed: " . odbc_errormsg());
        }
        return $conn;
    }

    protected function parseDateFilter(Request $request): array
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

    protected function buildProvinceWhere(array $provinceIds): string
    {
        if (empty($provinceIds)) return '';
        $ids = implode(',', array_map('intval', $provinceIds));
        return "AND P.PROVINCE_ID IN ($ids)";
    }

    protected function fetchProvinces($conn): array
    {
        $result    = odbc_exec($conn, "SELECT ID, NAMAPROPINSI FROM PROPINSI ORDER BY NAMAPROPINSI");
        $provinces = [];
        while ($row = odbc_fetch_object($result)) {
            $provinces[] = $row;
        }
        return $provinces;
    }

    /**
     * Buat cache key yang konsisten dari parameter request.
     * province_ids di-sort, semua key+value di-lowercase, lalu di-MD5.
     */
    protected function makeCacheKey(Request $request, string $prefix, array $paramKeys): string
    {
        $params = $request->only($paramKeys);

        if (!empty($params['province_ids']) && is_array($params['province_ids'])) {
            $ids = array_map('intval', $params['province_ids']);
            sort($ids);
            $params['province_ids'] = implode(',', $ids);
        } else {
            unset($params['province_ids']);
        }

        $normalized = [];
        foreach ($params as $k => $v) {
            $normalized[strtolower($k)] = strtolower((string) $v);
        }
        ksort($normalized);

        return $prefix . ':' . md5(http_build_query($normalized));
    }

    protected function buildFilterLabel(Request $request, array $provinceIds = []): array
    {
        $dateFilter = $this->parseDateFilter($request);
        $months     = ['Januari','Februari','Maret','April','Mei','Juni',
                       'Juli','Agustus','September','Oktober','November','Desember'];

        if ($dateFilter['type'] === 'bulan') {
            $m            = (int) $request->filter_month;
            $y            = $request->filter_year ?? 2026;
            $periodeLabel = ($months[$m - 1] ?? '') . ' ' . $y;
        } elseif ($dateFilter['type'] === 'range') {
            $periodeLabel = date('d/m/Y', strtotime($dateFilter['start']))
                          . ' – '
                          . date('d/m/Y', strtotime($dateFilter['end'] . ' -1 day'));
        } else {
            $periodeLabel = 'Tahun ' . ($request->filter_year ?? 2026);
        }

        if (!empty($provinceIds)) {
            $cached  = Cache::get('compliance:provinces', Cache::get('dashboard:provinces', []));
            $nameMap = [];
            foreach ($cached as $p) {
                $p = (object) $p;
                $nameMap[(int) $p->ID] = $p->NAMAPROPINSI;
            }
            $names     = array_map(fn($id) => $nameMap[$id] ?? $id, array_map('intval', $provinceIds));
            $provLabel = implode(', ', $names);
        } else {
            $provLabel = 'Semua Provinsi';
        }

        return ['periode' => $periodeLabel, 'provinsi' => $provLabel];
    }

    protected function safeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9]+/', '_', $name);
    }

    protected function buildExportFilename(array $label, bool $withDetail = false): string
    {
        $periode = str_replace(['/', ' ', '–', '-'], ['', '_', '-', '_'], $label['periode']);
        $suffix  = $withDetail ? '_Lengkap' : '_Ringkasan';
        return 'Compliance_KCKR' . $suffix . '_' . $periode . '_' . date('d-m-Y') . '.xlsx';
    }

    protected function writeTitleRows(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $mainTitle,
        array $label,
        int $colCount
    ): int {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

        $titleStyle = [
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '0D47A1']],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ];
        $subStyle = [
            'font'      => ['size' => 10, 'color' => ['rgb' => '444444']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT],
        ];

        $sheet->mergeCells('A1:' . $lastCol . '1');
        $sheet->getCell('A1')->setValue($mainTitle);
        $sheet->getStyle('A1')->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $sheet->mergeCells('A2:' . $lastCol . '2');
        $sheet->getCell('A2')->setValue('Periode: ' . $label['periode'] . '     |     Provinsi: ' . $label['provinsi']);
        $sheet->getStyle('A2')->applyFromArray($subStyle);

        $sheet->mergeCells('A3:' . $lastCol . '3');
        $sheet->getCell('A3')->setValue('Diunduh: ' . date('d/m/Y H:i'));
        $sheet->getStyle('A3')->applyFromArray($subStyle);

        $sheet->getStyle('A1:' . $lastCol . '3')
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E3F2FD');

        $sheet->getRowDimension(4)->setRowHeight(6);

        return 5; // header mulai di baris 5
    }

    protected function sendDownloadCookie(Request $request): void
    {
        $token = $request->get('download_token', '');
        if ($token) {
            setcookie('dl_' . preg_replace('/[^a-z0-9]/i', '', $token), '1', time() + 120, '/');
        }
    }

    protected function fmtDate(?string $val): string
    {
        return $val ? date('d/m/Y', strtotime($val)) : '';
    }

    protected function streamXlsx(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet,
        string $filename,
        Request $request
    ): \Illuminate\Http\Response {
        $this->sendDownloadCookie($request);

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);
        $content  = file_get_contents($tempFile);
        unlink($tempFile);

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache',
            'Pragma'              => 'no-cache',
        ]);
    }
}
