<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class GratitudeAccountService
{
    public function accounts(array $filters = []): Collection
    {
        $accounts = $this->query($filters)->get();
        $levels = GratitudeLevel::whereIn('name', $accounts->pluck('level')->filter()->unique())
            ->get()
            ->keyBy('name');

        return $accounts->map(function (Gratitude $gratitude) use ($levels) {
            $level = $levels->get($gratitude->level);
            $gratitude->setAttribute('level_icon_url', $level?->level_icon_url);
            $gratitude->setAttribute('level_image_url', $level?->level_image_url);

            return $gratitude;
        });
    }

    public function query(array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);
        $now = Carbon::now();

        $query = Gratitude::query()
            ->select(
                'id',
                'gratitudeNumber',
                'level',
                'level_obtained_at',
                'totalPoints',
                'useablePoints',
                'totalExpiredPoints',
                'totalRemainingPoints',
                'totalRedeemedPoints',
                'totalCancelledPoints',
                'status',
                'is_active',
                'systemLevelUpdate',
                'last_activity_at',
                'created_at',
                'updated_at'
            )
            ->selectSub(
                EarnedPoint::selectRaw('COALESCE(SUM(points), 0)')
                    ->whereColumn('gratitudeNumber', 'gratitudes.gratitudeNumber')
                    ->activeStatus()
                    ->whereNotNull('usable_date')
                    ->where('usable_date', '>', $now),
                'pending_points'
            );

        if ($filters['status'] === 'active') {
            $query->where('is_active', true)
                ->where(function (Builder $q) {
                    $q->whereNull('status')
                        ->orWhereIn('status', ['active', '1', 1, true]);
                });
        } elseif ($filters['status'] === 'inactive') {
            $query->where(function (Builder $q) {
                $q->where('is_active', false)
                    ->orWhereIn('status', ['inactive', '0', 0, false]);
            });
        }

        if ($filters['usable_points'] === 'with') {
            $query->where('useablePoints', '>', 0);
        } elseif ($filters['usable_points'] === 'without') {
            $query->where(function (Builder $q) {
                $q->whereNull('useablePoints')->orWhere('useablePoints', '<=', 0);
            });
        }

        if ($filters['usable_min'] !== null) {
            $query->where('useablePoints', '>=', $filters['usable_min']);
        }

        if ($filters['usable_max'] !== null) {
            $query->where('useablePoints', '<=', $filters['usable_max']);
        }

        if ($filters['level']) {
            $query->where('level', $filters['level']);
        }

        if ($filters['search']) {
            $search = $filters['search'];
            $query->where('gratitudeNumber', 'like', "%{$search}%");
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id');
    }

    public function filters(array $input): array
    {
        return $this->normalizeFilters($input);
    }

    public function filterOptions(): array
    {
        return [
            'levels' => GratitudeLevel::where('status', true)
                ->orderBy('min_points')
                ->get(['id', 'name', 'min_points', 'max_points']),
        ];
    }

    public function excelResponse(array $filters = []): Response
    {
        $rows = $this->exportRows($filters);
        $html = $this->tableHtml($rows, 'Gratitude Accounts', $this->filterSummary($filters));

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('gratitude-accounts', 'xls').'"',
        ]);
    }

    public function printResponse(array $filters = []): Response
    {
        $rows = $this->exportRows($filters);
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Gratitude Accounts</title>'.$this->printStyles().'</head><body>'
            .$this->tableHtml($rows, 'Gratitude Accounts', $this->filterSummary($filters))
            .'<script>window.addEventListener("load",()=>window.print());</script></body></html>';

        return response($html);
    }

    public function pdfResponse(array $filters = []): Response
    {
        $rows = $this->exportRows($filters);
        $pdf = $this->makePdf($rows, $filters);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('gratitude-accounts', 'pdf').'"',
        ]);
    }

    private function exportRows(array $filters): Collection
    {
        return $this->accounts($filters)
            ->map(fn (Gratitude $account) => [
                'ID' => $account->id,
                'Gratitude Number' => $account->gratitudeNumber,
                'Level' => $account->level ?: '',
                'Status' => $this->accountStatus($account),
                'Total Points' => (int) $account->totalPoints,
                'Remaining Points' => (int) $account->totalRemainingPoints,
                'Usable Points' => (int) $account->useablePoints,
                'Pending Points' => (int) $account->pending_points,
                'Redeemed Points' => (int) $account->totalRedeemedPoints,
                'Cancelled Points' => (int) $account->totalCancelledPoints,
                'Expired Points' => (int) $account->totalExpiredPoints,
                'Last Activity' => $account->last_activity_at ? Carbon::parse($account->last_activity_at)->toDateString() : '',
            ]);
    }

    private function normalizeFilters(array $input): array
    {
        $status = strtolower((string) ($input['status'] ?? ''));
        $usablePoints = strtolower((string) ($input['usable_points'] ?? ''));

        return [
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : null,
            'usable_points' => in_array($usablePoints, ['with', 'without'], true) ? $usablePoints : null,
            'usable_min' => isset($input['usable_min']) && $input['usable_min'] !== '' ? max(0, (int) $input['usable_min']) : null,
            'usable_max' => isset($input['usable_max']) && $input['usable_max'] !== '' ? max(0, (int) $input['usable_max']) : null,
            'level' => isset($input['level']) && $input['level'] !== '' ? (string) $input['level'] : null,
            'search' => isset($input['search']) && trim((string) $input['search']) !== '' ? trim((string) $input['search']) : null,
        ];
    }

    private function accountStatus(Gratitude $account): string
    {
        $status = strtolower((string) ($account->status ?: ''));

        if ($account->is_active && in_array($status, ['', 'active', '1', 'true'], true)) {
            return 'active';
        }

        return 'inactive';
    }

    private function filterSummary(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $summary = [];

        if ($filters['status']) {
            $summary[] = 'Status: '.$filters['status'];
        }
        if ($filters['usable_points']) {
            $summary[] = 'Usable points: '.$filters['usable_points'];
        }
        if ($filters['usable_min'] !== null) {
            $summary[] = 'Usable min: '.$filters['usable_min'];
        }
        if ($filters['usable_max'] !== null) {
            $summary[] = 'Usable max: '.$filters['usable_max'];
        }
        if ($filters['level']) {
            $summary[] = 'Level: '.$filters['level'];
        }
        if ($filters['search']) {
            $summary[] = 'Search: '.$filters['search'];
        }

        return $summary;
    }

    private function tableHtml(Collection $rows, string $title, array $summary): string
    {
        $columns = array_keys($rows->first() ?? [
            'ID' => null,
            'Gratitude Number' => null,
            'Level' => null,
            'Status' => null,
            'Usable Points' => null,
        ]);

        $head = collect($columns)
            ->map(fn ($column) => '<th>'.e($column).'</th>')
            ->implode('');

        $body = $rows->map(function (array $row) use ($columns) {
            $cells = collect($columns)
                ->map(fn ($column) => '<td>'.nl2br(e((string) ($row[$column] ?? ''))).'</td>')
                ->implode('');

            return '<tr>'.$cells.'</tr>';
        })->implode('');

        $summaryHtml = $summary
            ? '<p class="filters">'.e(implode(' | ', $summary)).'</p>'
            : '<p class="filters">No filters applied</p>';

        return '<main><h1>'.e($title).'</h1><p class="meta">Generated '.e(Carbon::now()->toDateTimeString()).'</p>'.$summaryHtml
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></main>';
    }

    private function printStyles(): string
    {
        return '<style>
            @page { margin: 14mm; }
            body { color: #111827; font-family: Arial, sans-serif; font-size: 12px; }
            h1 { font-size: 20px; margin: 0 0 4px; }
            .meta, .filters { color: #4b5563; margin: 0 0 10px; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
            th { background: #f3f4f6; font-weight: 700; }
            tr { page-break-inside: avoid; }
        </style>';
    }

    private function makePdf(Collection $rows, array $filters): string
    {
        $pages = $this->pdfTablePages($rows, $filters);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];
        $pageIds = [];
        $nextId = 5;

        foreach ($pages as $page) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $objects[$contentId] = '<< /Length '.strlen($page)." >>\nstream\n{$page}\nendstream";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $pageIds[] = $pageId.' 0 R';
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageIds).' ] /Count '.count($pageIds).' >>';
        ksort($objects);

        return $this->buildPdfDocument($objects);
    }

    private function pdfTablePages(Collection $rows, array $filters): array
    {
        $columns = $this->pdfColumns();
        $pages = [];
        [$content, $y] = $this->pdfStartPage($filters, $columns);

        if ($rows->isEmpty()) {
            $content .= $this->pdfDrawRow(['Gratitude Number' => ['No records found.']], $columns, $y, 22);
            $pages[] = $content;

            return $pages;
        }

        foreach ($rows as $row) {
            $prepared = $this->preparePdfRow($row, $columns);
            $rowHeight = $this->pdfRowHeight($prepared);

            if ($y - $rowHeight < 26) {
                $pages[] = $content;
                [$content, $y] = $this->pdfStartPage($filters, $columns);
            }

            $content .= $this->pdfDrawRow($prepared, $columns, $y, $rowHeight);
            $y -= $rowHeight;
        }

        $pages[] = $content;

        return $pages;
    }

    private function pdfColumns(): array
    {
        return [
            ['key' => 'Gratitude Number', 'label' => 'Gratitude #', 'width' => 92],
            ['key' => 'Level', 'label' => 'Level', 'width' => 74],
            ['key' => 'Status', 'label' => 'Status', 'width' => 54],
            ['key' => 'Total Points', 'label' => 'Total', 'width' => 70],
            ['key' => 'Remaining Points', 'label' => 'Remaining', 'width' => 78],
            ['key' => 'Usable Points', 'label' => 'Usable', 'width' => 70],
            ['key' => 'Pending Points', 'label' => 'Pending', 'width' => 70],
            ['key' => 'Redeemed Points', 'label' => 'Redeemed', 'width' => 74],
            ['key' => 'Cancelled Points', 'label' => 'Cancelled', 'width' => 76],
            ['key' => 'Expired Points', 'label' => 'Expired', 'width' => 70],
            ['key' => 'Last Activity', 'label' => 'Last Activity', 'width' => 86],
        ];
    }

    private function pdfStartPage(array $filters, array $columns): array
    {
        $summary = implode(' | ', $this->filterSummary($filters)) ?: 'No filters applied';
        $content = $this->pdfTextAt('Gratitude Accounts', 24, 560, 14, 'F2');
        $content .= $this->pdfTextAt('Generated '.Carbon::now()->toDateTimeString(), 24, 542, 8);
        $content .= $this->pdfTextAt($summary, 24, 530, 8);
        $content .= $this->pdfDrawHeaderRow($columns, 510);

        return [$content, 490];
    }

    private function preparePdfRow(array $row, array $columns): array
    {
        $prepared = [];

        foreach ($columns as $column) {
            $value = $row[$column['key']] ?? '';

            if (is_int($value)) {
                $value = number_format($value);
            }

            $prepared[$column['key']] = $this->wrapPdfCell((string) $value, (float) $column['width']);
        }

        return $prepared;
    }

    private function wrapPdfCell(string $value, float $width): array
    {
        $maxChars = max(8, (int) floor(($width - 8) / 3.7));
        $lines = collect(explode("\n", $this->pdfText($value)))
            ->flatMap(fn ($line) => explode("\n", wordwrap($line, $maxChars, "\n", true)))
            ->map(fn ($line) => trim($line))
            ->values()
            ->all();

        if (count($lines) > 10) {
            $lines = array_slice($lines, 0, 10);
            $lines[9] = rtrim($lines[9], '.').'...';
        }

        return $lines ?: [''];
    }

    private function pdfRowHeight(array $prepared): float
    {
        $lineCount = max(array_map('count', $prepared) ?: [1]);

        return max(22, ($lineCount * 9) + 8);
    }

    private function pdfDrawHeaderRow(array $columns, float $topY): string
    {
        $content = '';
        $x = 24;

        foreach ($columns as $column) {
            $content .= $this->pdfCell((float) $x, $topY, (float) $column['width'], 20, true);
            $content .= $this->pdfTextAt($column['label'], $x + 4, $topY - 13, 7, 'F2');
            $x += $column['width'];
        }

        return $content;
    }

    private function pdfDrawRow(array $prepared, array $columns, float $topY, float $height): string
    {
        $content = '';
        $x = 24;

        foreach ($columns as $column) {
            $key = $column['key'];
            $content .= $this->pdfCell((float) $x, $topY, (float) $column['width'], $height);

            foreach (($prepared[$key] ?? ['']) as $index => $line) {
                $content .= $this->pdfTextAt($line, $x + 4, $topY - 12 - ($index * 9), 7);
            }

            $x += $column['width'];
        }

        return $content;
    }

    private function pdfCell(float $x, float $topY, float $width, float $height, bool $fill = false): string
    {
        $bottomY = $topY - $height;
        $rectangle = $this->pdfNumber($x).' '.$this->pdfNumber($bottomY).' '.$this->pdfNumber($width).' '.$this->pdfNumber($height).' re';

        return ($fill ? "0.93 0.95 0.97 rg\n{$rectangle} f\n0 g\n" : '')
            ."0.55 G\n0.4 w\n{$rectangle} S\n0 G\n";
    }

    private function buildPdfDocument(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(max(array_keys($objects)) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= max(array_keys($objects)); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size ".(max(array_keys($objects)) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function pdfTextAt(string $text, float $x, float $y, int $size = 8, string $font = 'F1'): string
    {
        return "BT\n/{$font} {$size} Tf\n1 0 0 1 ".$this->pdfNumber($x).' '.$this->pdfNumber($y).' Tm ('.$this->escapePdf($this->pdfText($text)).") Tj\nET\n";
    }

    private function pdfText(mixed $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', (string) $value) ?? '';
    }

    private function escapePdf(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function pdfNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function filename(string $base, string $extension): string
    {
        return $base.'-'.Carbon::now()->format('Ymd-His').'.'.$extension;
    }
}
