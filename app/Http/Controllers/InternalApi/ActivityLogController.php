<?php

namespace App\Http\Controllers\InternalApi;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request, ActivityLogService $activityLogs): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'log_name' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:255'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'causer_type' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $logs = $activityLogs->paginate($validated, (int) ($validated['per_page'] ?? 25));

        return response()->json([
            'logs' => collect($logs->items())->map(fn (Activity $activity) => $this->activityPayload($activity))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
            'filter_options' => $activityLogs->filterOptions(),
        ]);
    }

    public function destroy(int $activityLog, ActivityLogService $activityLogs): JsonResponse
    {
        $deleted = $activityLogs->deleteIds([$activityLog]);

        if ($deleted === 0) {
            return response()->json(['message' => 'Log entry not found.'], 404);
        }

        return response()->json([
            'message' => 'Log entry deleted.',
            'deleted' => $deleted,
        ]);
    }

    public function bulkDestroy(Request $request, ActivityLogService $activityLogs): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = $activityLogs->deleteIds($validated['ids']);

        return response()->json([
            'message' => "{$deleted} log entries deleted.",
            'deleted' => $deleted,
        ]);
    }

    public function prune(Request $request, ActivityLogService $activityLogs): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $days = (int) ($validated['days'] ?? 60);
        $deleted = $activityLogs->pruneOlderThan($days);

        return response()->json([
            'message' => "{$deleted} log entries older than {$days} days deleted.",
            'deleted' => $deleted,
            'days' => $days,
        ]);
    }

    private function activityPayload(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type,
            'subject_type_label' => $this->classLabel($activity->subject_type),
            'subject_id' => $activity->subject_id,
            'causer_type' => $activity->causer_type,
            'causer_type_label' => $this->classLabel($activity->causer_type),
            'causer_id' => $activity->causer_id,
            'properties' => $activity->properties?->toArray() ?? [],
            'created_at' => $activity->created_at?->toDateTimeString(),
            'updated_at' => $activity->updated_at?->toDateTimeString(),
        ];
    }

    private function classLabel(?string $class): string
    {
        if (! $class) {
            return '';
        }

        return class_basename($class);
    }
}
