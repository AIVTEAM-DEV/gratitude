<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLogService
{
    public function query(array $filters = []): Builder
    {
        $query = Activity::query()->latest('created_at');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search) {
                $query
                    ->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('subject_type', 'like', "%{$search}%")
                    ->orWhere('causer_type', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $query
                        ->orWhere('id', (int) $search)
                        ->orWhere('subject_id', (int) $search)
                        ->orWhere('causer_id', (int) $search);
                }
            });
        }

        foreach (['log_name', 'event', 'subject_type', 'causer_type'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));

            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        return $query;
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $perPage = min(100, max(10, $perPage));

        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    public function filterOptions(): array
    {
        return [
            'log_names' => $this->distinctValues('log_name'),
            'events' => $this->distinctValues('event'),
            'subject_types' => $this->distinctValues('subject_type'),
            'causer_types' => $this->distinctValues('causer_type'),
        ];
    }

    public function deleteIds(array $ids): int
    {
        $ids = collect($ids)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        return Activity::query()->whereKey($ids->all())->delete();
    }

    public function pruneOlderThan(int $days = 60): int
    {
        $days = max(1, $days);
        $cutoff = Carbon::now()->subDays($days);

        return Activity::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    private function distinctValues(string $column): array
    {
        return Activity::query()
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->limit(100)
            ->pluck($column)
            ->values()
            ->all();
    }
}
