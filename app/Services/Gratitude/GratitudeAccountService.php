<?php

namespace App\Services\Gratitude;

use App\Models\Gratitude\BonusPoint;
use App\Models\Gratitude\EarnedPoint;
use App\Models\Gratitude\Gratitude;
use App\Models\Gratitude\GratitudeLevel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class GratitudeAccountService
{
    private const EXPORT_TITLE = 'Gratitude - Art In Voyage';

    private const ABOUT_TO_EXPIRE_DAYS = 30;

    public function accounts(array $filters = []): Collection
    {
        $accounts = $this->query($filters)->get();
        $levels = GratitudeLevel::whereIn('name', $accounts->pluck('level')->filter()->unique())
            ->get()
            ->keyBy('name');

        return $accounts->map(function (Gratitude $gratitude) use ($levels) {
            $level = $levels->get($gratitude->level);
            $expiringSoonPoints = (int) ($gratitude->earned_expiring_soon_points ?? 0)
                + (int) ($gratitude->bonus_expiring_soon_points ?? 0);

            $gratitude->setAttribute('level_icon_url', $level?->level_icon_url);
            $gratitude->setAttribute('level_image_url', $level?->level_image_url);
            $gratitude->setAttribute('redemption_points_per_dollar', (float) ($level?->redemption_points_per_dollar ?: 35));
            $gratitude->setAttribute('total_balance', (int) ($gratitude->totalRemainingPoints ?? 0));
            $gratitude->setAttribute('expiring_soon_points', $expiringSoonPoints);

            return $gratitude;
        });
    }

    public function query(array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);
        $now = Carbon::now();
        $expiringSoonUntil = $now->copy()->addDays(self::ABOUT_TO_EXPIRE_DAYS)->endOfDay();

        $query = Gratitude::query()
            ->select(
                'id',
                'gratitudeNumber',
                'guests_data',
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
            )
            ->selectSub(
                $this->pointSumExpiringBetweenSubquery(EarnedPoint::class, $now, $expiringSoonUntil),
                'earned_expiring_soon_points'
            )
            ->selectSub(
                $this->pointSumExpiringBetweenSubquery(BonusPoint::class, $now, $expiringSoonUntil),
                'bonus_expiring_soon_points'
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

        $this->applyExpirationFilters($query, $filters, $now);

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
            'about_to_expire_days' => self::ABOUT_TO_EXPIRE_DAYS,
        ];
    }

    public function excelResponse(array $filters = []): Response
    {
        $groups = $this->exportGroups($filters);
        $html = $this->tableHtml($groups, self::EXPORT_TITLE);

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('gratitude-accounts', 'xls').'"',
        ]);
    }

    public function printResponse(array $filters = []): Response
    {
        $groups = $this->exportGroups($filters);
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Gratitude Accounts</title>'.$this->printStyles().'</head><body>'
            .$this->tableHtml($groups, self::EXPORT_TITLE)
            .'<script>window.addEventListener("load",()=>window.print());</script></body></html>';

        return response($html);
    }

    public function pdfResponse(array $filters = []): Response
    {
        $groups = $this->exportGroups($filters);
        $pdf = $this->makePdf($groups, $filters);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('gratitude-accounts', 'pdf').'"',
        ]);
    }

    private function exportGroups(array $filters): Collection
    {
        $accounts = $this->accounts($filters);

        return $accounts
            ->map(function (Gratitude $account) {
                $totalBalance = (int) ($account->total_balance ?? $account->totalRemainingPoints ?? 0);
                $useablePoints = (int) ($account->useablePoints ?? 0);
                $pointsPerDollar = max(1, (float) ($account->getAttribute('redemption_points_per_dollar') ?: 35));

                $guests = collect(Gratitude::normalizeGuestsData($account->guests_data ?? []))
                    ->filter(fn ($guest) => is_array($guest))
                    ->map(fn (array $guest) => [
                        'Ownership' => $this->guestOwnership($guest),
                        'Guests First Name' => $this->guestFirstName($guest),
                        'Guests Last Name' => $this->guestLastName($guest),
                        'Guests Preferred Name' => $this->guestPreferredName($guest),
                        'Guests Date of Birth' => $this->guestDateOfBirth($guest),
                        'Guests Email' => $this->guestEmail($guest),
                    ])
                    ->values();

                if ($guests->isEmpty()) {
                    $guests = collect([[
                        'Guests First Name' => 'No guests found',
                        'Guests Last Name' => '',
                        'Guests Preferred Name' => '',
                        'Guests Date of Birth' => '',
                        'Guests Email' => '',
                    ]]);
                }

                return [
                    'account' => [
                        'Gratitude Number' => $account->gratitudeNumber,
                        'Level' => $account->level ?: '',
                        'Total Balance' => $totalBalance,
                        'Useable Points' => $useablePoints,
                        '$ Value' => round($useablePoints / $pointsPerDollar, 2),
                        'Status' => ucfirst($this->accountStatus($account)),
                    ],
                    'guests' => $guests,
                ];
            });
    }

    private function normalizeFilters(array $input): array
    {
        $status = strtolower((string) ($input['status'] ?? ''));
        $usablePoints = strtolower((string) ($input['usable_points'] ?? ''));
        $expiryStatus = str_replace('-', '_', strtolower((string) ($input['expiry_status'] ?? $input['expiration_status'] ?? '')));
        $expiresFrom = $this->normalizeDateFilter($input['expires_from'] ?? $input['date_from'] ?? null);
        $expiresTo = $this->normalizeDateFilter($input['expires_to'] ?? $input['date_to'] ?? null);

        if ($expiresFrom !== null && $expiresTo !== null && $expiresFrom > $expiresTo) {
            [$expiresFrom, $expiresTo] = [$expiresTo, $expiresFrom];
        }

        return [
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : null,
            'usable_points' => in_array($usablePoints, ['with', 'without'], true) ? $usablePoints : null,
            'usable_min' => isset($input['usable_min']) && $input['usable_min'] !== '' ? max(0, (int) $input['usable_min']) : null,
            'usable_max' => isset($input['usable_max']) && $input['usable_max'] !== '' ? max(0, (int) $input['usable_max']) : null,
            'level' => isset($input['level']) && $input['level'] !== '' ? (string) $input['level'] : null,
            'search' => isset($input['search']) && trim((string) $input['search']) !== '' ? trim((string) $input['search']) : null,
            'expiry_status' => in_array($expiryStatus, ['about_to_expire', 'about_to_expired'], true) ? 'about_to_expire' : null,
            'expires_from' => $expiresFrom,
            'expires_to' => $expiresTo,
        ];
    }

    private function normalizeDateFilter(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function pointSumExpiringBetweenSubquery(string $modelClass, Carbon $from, Carbon $to): Builder
    {
        return $modelClass::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) > 0 THEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) ELSE 0 END), 0)')
            ->whereColumn('gratitudeNumber', 'gratitudes.gratitudeNumber')
            ->whereNull('cancel_id')
            ->activeStatus()
            ->withRemainingPoints()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', $from)
            ->where('expires_at', '<=', $to);
    }

    private function applyExpirationFilters(Builder $query, array $filters, Carbon $now): void
    {
        $expiresFrom = $filters['expires_from'] ? Carbon::parse($filters['expires_from'])->startOfDay() : null;
        $expiresTo = $filters['expires_to'] ? Carbon::parse($filters['expires_to'])->endOfDay() : null;

        if ($filters['expiry_status'] === 'about_to_expire') {
            $windowStart = $expiresFrom && $expiresFrom->gt($now) ? $expiresFrom : $now->copy();
            $windowEnd = $expiresTo ?: $now->copy()->addDays(self::ABOUT_TO_EXPIRE_DAYS)->endOfDay();

            $this->whereHasPointExpiringBetween($query, $windowStart, $windowEnd);

            return;
        }

        if ($expiresFrom || $expiresTo) {
            $this->whereHasPointExpiringBetween($query, $expiresFrom, $expiresTo);
        }
    }

    private function whereHasPointExpiringBetween(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        $query->where(function (Builder $q) use ($from, $to) {
            $q->whereExists(fn ($subquery) => $this->pointExpiryExistsSubquery($subquery, 'earned_points', $from, $to))
                ->orWhereExists(fn ($subquery) => $this->pointExpiryExistsSubquery($subquery, 'bonus_points', $from, $to));
        });
    }

    private function pointExpiryExistsSubquery($query, string $table, ?Carbon $from, ?Carbon $to): void
    {
        $query->selectRaw('1')
            ->from($table)
            ->whereColumn($table.'.gratitudeNumber', 'gratitudes.gratitudeNumber')
            ->whereNull($table.'.cancel_id')
            ->whereIn($table.'.status', [true, 1, '1'])
            ->whereNotNull($table.'.expires_at')
            ->whereRaw($this->remainingPointsPositiveExpression($table));

        if ($from) {
            $query->where($table.'.expires_at', '>=', $from);
        }

        if ($to) {
            $query->where($table.'.expires_at', '<=', $to);
        }
    }

    private function remainingPointsPositiveExpression(string $table): string
    {
        return 'COALESCE('.$table.'.points, 0) > COALESCE('.$table.'.redeemed_points, 0) + COALESCE('.$table.'.cancelled_points, 0)';
    }

    private function accountStatus(Gratitude $account): string
    {
        $status = strtolower((string) ($account->status ?: ''));

        if ($account->is_active && in_array($status, ['', 'active', '1', 'true'], true)) {
            return 'active';
        }

        return 'inactive';
    }

    private function guestFirstName(array $guest): string
    {
        $firstName = $this->firstGuestValue($guest, ['first_name', 'firstName', 'firstname', 'given_name', 'givenName']);

        if ($firstName !== null) {
            return (string) $firstName;
        }

        return $this->splitGuestName($guest)[0] ?? '';
    }

    private function guestOwnership(array $guest): string
    {
        $ownership = $this->firstGuestValue($guest, [
            'ownership',
            'onwership',
            'gratitude_ownership',
            'gratitudeOwnership',
            'role',
            'type',
            'guest_type',
            'guestType',
        ]);

        return $ownership !== null ? ucfirst((string) $ownership) : '';
    }

    private function guestLastName(array $guest): string
    {
        $lastName = $this->firstGuestValue($guest, ['last_name', 'lastName', 'lastname', 'surname', 'family_name', 'familyName']);

        if ($lastName !== null) {
            return (string) $lastName;
        }

        return $this->splitGuestName($guest)[1] ?? '';
    }

    private function guestPreferredName(array $guest): string
    {
        $preferredName = $this->firstGuestValue($guest, ['preferred_name', 'preferredName', 'nickname', 'known_as', 'knownAs']);

        return $preferredName !== null ? (string) $preferredName : '';
    }

    private function guestDateOfBirth(array $guest): string
    {
        $dateOfBirth = $this->firstGuestValue($guest, [
            'birthday',
            'birtday',
            'date_of_birth',
            'dateOfBirth',
            'birth_date',
            'birthDate',
            'dob',
        ]);

        if ($dateOfBirth === null) {
            return '';
        }

        try {
            return Carbon::parse($dateOfBirth)->format('F d, Y');
        } catch (\Throwable) {
            return (string) $dateOfBirth;
        }
    }

    private function guestEmail(array $guest): string
    {
        $email = $this->firstGuestValue($guest, [
            'email',
            'email_address',
            'emailAddress',
            'guest_email',
            'guestEmail',
            'contact_email',
            'contactEmail',
            'user.email',
            'profile.email',
            'contact.email',
        ]);

        return $email !== null ? (string) $email : '';
    }

    private function splitGuestName(array $guest): array
    {
        $name = $this->firstGuestValue($guest, [
            'name',
            'full_name',
            'fullName',
            'guest_name',
            'guestName',
            'display_name',
            'displayName',
        ]);

        if ($name === null) {
            return [];
        }

        $parts = preg_split('/\s+/', trim((string) $name), 2);

        return $parts ?: [];
    }

    private function firstGuestValue(array $guest, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($guest, $key);

            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
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
        if ($filters['expiry_status'] === 'about_to_expire') {
            $summary[] = 'Expiration: about to expire';
        }
        if ($filters['expires_from'] || $filters['expires_to']) {
            $summary[] = 'Expires: '.($filters['expires_from'] ?? 'any').' to '.($filters['expires_to'] ?? 'any');
        }

        return $summary;
    }

    private function exportColumns(): array
    {
        return [
            'Gratitude Number',
            'Level',
            'Ownership',
            'Guests First Name',
            'Guests Last Name',
            'Guests Preferred Name',
            'Guests Date of Birth',
            'Guests Email',
            'Total Balance',
            'Useable Points',
            '$ Value',
            'Status',
        ];
    }

    private function tableHtml(Collection $groups, string $title): string
    {
        $columns = $this->exportColumns();

        $head = collect($columns)
            ->map(fn ($column) => '<th>'.e($column).'</th>')
            ->implode('');

        $body = $groups->isEmpty()
            ? '<tr><td colspan="'.count($columns).'">No records found.</td></tr>'
            : $groups->map(function (array $group) use ($columns) {
                $guests = collect($group['guests'] ?? [])->values();
                $rowspan = max(1, $guests->count());

                return $guests->map(function (array $guest, int $index) use ($columns, $group, $rowspan) {
                    $cells = '';

                    if ($index === 0) {
                        $cells .= '<td rowspan="'.$rowspan.'" style="vertical-align: middle; text-align: center;">'.nl2br(e((string) data_get($group, 'account.Gratitude Number', ''))).'</td>';
                    }

                    foreach (array_slice($columns, 1) as $column) {
                        $value = $guest[$column] ?? data_get($group, 'account.'.$column, '');

                        $cells .= '<td>'.nl2br(e($this->formatExportValue($value, $column))).'</td>';
                    }

                    return '<tr>'.$cells.'</tr>';
                })->implode('');
            })->implode('');

        return '<main><table><thead><tr><th colspan="'.count($columns).'" style="text-align: center;">'.e($title).'</th></tr><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></main>';
    }

    private function printStyles(): string
    {
        return '<style>
            @page { margin: 14mm; }
            body { color: #111827; font-family: Arial, sans-serif; font-size: 12px; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
            th { background: #f3f4f6; font-weight: 700; }
            tr { page-break-inside: avoid; }
        </style>';
    }

    private function formatExportValue(mixed $value, string $column): string
    {
        if (in_array($column, ['Total Balance', 'Useable Points', '$ Value'], true)) {
            return number_format((float) $value, 2, ',', ' ');
        }

        return (string) $value;
    }

    private function makePdf(Collection $groups, array $filters): string
    {
        $pages = $this->pdfTablePages($groups, $filters);
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

    private function pdfTablePages(Collection $groups, array $filters): array
    {
        $columns = $this->pdfColumns();
        $pages = [];
        [$content, $y] = $this->pdfStartPage($filters, $columns);

        if ($groups->isEmpty()) {
            $content .= $this->pdfDrawRow(['Gratitude Number' => ['No records found.']], $columns, $y, 22);
            $pages[] = $content;

            return $pages;
        }

        foreach ($groups as $group) {
            $accountCell = $this->wrapPdfCell(
                (string) data_get($group, 'account.Gratitude Number', ''),
                (float) $columns[0]['width']
            );
            $accountCellHeight = $this->pdfRowHeight(['Gratitude Number' => $accountCell]);
            $rows = collect(data_get($group, 'guests', []))
                ->map(fn (array $guest) => $this->preparePdfGuestRow(data_get($group, 'account', []), $guest, $columns))
                ->values()
                ->all();
            $rowHeights = array_map(fn (array $row) => $this->pdfRowHeight($row), $rows);
            $offset = 0;

            while ($offset < count($rows)) {
                if ($y - 22 < 26) {
                    $pages[] = $content;
                    [$content, $y] = $this->pdfStartPage($filters, $columns);
                }

                $availableHeight = $y - 26;
                $nextRequiredHeight = max($rowHeights[$offset] ?? 22, $accountCellHeight);

                if ($nextRequiredHeight > $availableHeight && $y < 490) {
                    $pages[] = $content;
                    [$content, $y] = $this->pdfStartPage($filters, $columns);

                    continue;
                }

                $chunkRows = [];
                $chunkHeights = [];
                $chunkHeight = 0;

                for ($index = $offset; $index < count($rows); $index++) {
                    $rowHeight = $rowHeights[$index];

                    if ($chunkRows !== [] && $chunkHeight + $rowHeight > $availableHeight) {
                        break;
                    }

                    $chunkRows[] = $rows[$index];
                    $chunkHeights[] = $rowHeight;
                    $chunkHeight += $rowHeight;

                    if ($chunkHeight >= $availableHeight) {
                        break;
                    }
                }

                if ($chunkRows === []) {
                    $pages[] = $content;
                    [$content, $y] = $this->pdfStartPage($filters, $columns);

                    continue;
                }

                if ($chunkHeight < $accountCellHeight) {
                    $chunkHeights[0] += $accountCellHeight - $chunkHeight;
                    $chunkHeight = $accountCellHeight;
                }

                $chunkAccountCell = $offset === 0
                    ? $accountCell
                    : $this->wrapPdfCell(
                        (string) data_get($group, 'account.Gratitude Number', '').' (cont.)',
                        (float) $columns[0]['width']
                    );

                $content .= $this->pdfDrawGroupedRows($chunkAccountCell, $chunkRows, $columns, $y, $chunkHeights);
                $y -= $chunkHeight;
                $offset += count($chunkRows);

                if ($offset < count($rows)) {
                    $pages[] = $content;
                    [$content, $y] = $this->pdfStartPage($filters, $columns);
                }
            }
        }

        $pages[] = $content;

        return $pages;
    }

    private function pdfColumns(): array
    {
        return [
            ['key' => 'Gratitude Number', 'label' => 'Gratitude Number', 'width' => 68],
            ['key' => 'Level', 'label' => 'Level', 'width' => 48],
            ['key' => 'Ownership', 'label' => 'Ownership', 'width' => 58],
            ['key' => 'Guests First Name', 'label' => 'Guests First Name', 'width' => 68],
            ['key' => 'Guests Last Name', 'label' => 'Guests Last Name', 'width' => 68],
            ['key' => 'Guests Preferred Name', 'label' => 'Guests Preferred Name', 'width' => 76],
            ['key' => 'Guests Date of Birth', 'label' => 'Guests Date of Birth', 'width' => 72],
            ['key' => 'Guests Email', 'label' => 'Guests Email', 'width' => 110],
            ['key' => 'Total Balance', 'label' => 'Total Balance', 'width' => 60],
            ['key' => 'Useable Points', 'label' => 'Useable Points', 'width' => 60],
            ['key' => '$ Value', 'label' => '$ Value', 'width' => 46],
            ['key' => 'Status', 'label' => 'Status', 'width' => 40],
        ];
    }

    private function pdfStartPage(array $filters, array $columns): array
    {
        $tableWidth = array_sum(array_column($columns, 'width'));
        $content = $this->pdfCell(24, 570, (float) $tableWidth, 20);
        $content .= $this->pdfCenteredTextAt(self::EXPORT_TITLE, 24, 557, (float) $tableWidth, 8, 'F2');
        $content .= $this->pdfDrawHeaderRow($columns, 550);

        return [$content, 530];
    }

    private function preparePdfGuestRow(array $account, array $guest, array $columns): array
    {
        $prepared = [];

        foreach (array_slice($columns, 1) as $column) {
            $key = $column['key'];
            $value = $guest[$key] ?? $account[$key] ?? '';

            $prepared[$key] = $this->wrapPdfCell($this->formatExportValue($value, $key), (float) $column['width']);
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
        $height = 20;

        foreach ($columns as $column) {
            $content .= $this->pdfCell((float) $x, $topY, (float) $column['width'], $height, true);
            $content .= $this->pdfTextAt($column['label'], $x + 3, $topY - 12, 5, 'F2');
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

    private function pdfDrawGroupedRows(array $accountCell, array $rows, array $columns, float $topY, array $rowHeights): string
    {
        $content = '';
        $x = 24;
        $totalHeight = array_sum($rowHeights);
        $firstColumn = $columns[0];

        $content .= $this->pdfCell((float) $x, $topY, (float) $firstColumn['width'], (float) $totalHeight);

        foreach ($accountCell as $index => $line) {
            $content .= $this->pdfTextAt($line, $x + 4, $topY - 12 - ($index * 9), 7);
        }

        $rowTopY = $topY;

        foreach ($rows as $rowIndex => $row) {
            $x = 24 + $firstColumn['width'];
            $height = (float) $rowHeights[$rowIndex];

            foreach (array_slice($columns, 1) as $column) {
                $key = $column['key'];
                $content .= $this->pdfCell((float) $x, $rowTopY, (float) $column['width'], $height);

                foreach (($row[$key] ?? ['']) as $lineIndex => $line) {
                    $content .= $this->pdfTextAt($line, $x + 4, $rowTopY - 12 - ($lineIndex * 9), 7);
                }

                $x += $column['width'];
            }

            $rowTopY -= $height;
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

    private function pdfCenteredTextAt(string $text, float $x, float $y, float $width, int $size = 8, string $font = 'F1'): string
    {
        $textWidth = strlen($this->pdfText($text)) * $size * 0.5;
        $textX = $x + max(0, ($width - $textWidth) / 2);

        return $this->pdfTextAt($text, $textX, $y, $size, $font);
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
