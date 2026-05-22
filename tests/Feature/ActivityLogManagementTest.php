<?php

namespace Tests\Feature;

use App\Models\Gratitude\Gratitude;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_logs_can_be_filtered_and_deleted(): void
    {
        $user = User::factory()->create();

        $matchingLog = Activity::query()->create([
            'log_name' => 'default',
            'description' => 'Updated gratitude account',
            'event' => 'updated',
            'subject_type' => Gratitude::class,
            'subject_id' => 25,
            'causer_type' => User::class,
            'causer_id' => $user->id,
            'properties' => ['attributes' => ['level' => 'Explorer']],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $hiddenLog = Activity::query()->create([
            'log_name' => 'default',
            'description' => 'Created application key',
            'event' => 'created',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'properties' => [],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($user)->getJson('/internal-api/logs?event=updated&search=gratitude');

        $response
            ->assertOk()
            ->assertJsonPath('logs.0.id', $matchingLog->id)
            ->assertJsonPath('logs.0.subject_type_label', 'Gratitude')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($user)
            ->deleteJson("/internal-api/logs/{$matchingLog->id}")
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('activity_log', ['id' => $matchingLog->id], config('activitylog.database_connection'));
        $this->assertDatabaseHas('activity_log', ['id' => $hiddenLog->id], config('activitylog.database_connection'));
    }

    public function test_activity_logs_can_be_bulk_deleted_and_pruned(): void
    {
        $user = User::factory()->create();

        $bulkLogs = collect([1, 2])->map(fn (int $number) => Activity::query()->create([
            'log_name' => 'default',
            'description' => "Bulk delete {$number}",
            'event' => 'deleted',
            'properties' => [],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]));

        $oldLog = Activity::query()->create([
            'log_name' => 'default',
            'description' => 'Old log',
            'event' => 'updated',
            'properties' => [],
            'created_at' => Carbon::now()->subDays(61),
            'updated_at' => Carbon::now()->subDays(61),
        ]);

        $recentLog = Activity::query()->create([
            'log_name' => 'default',
            'description' => 'Recent log',
            'event' => 'updated',
            'properties' => [],
            'created_at' => Carbon::now()->subDays(30),
            'updated_at' => Carbon::now()->subDays(30),
        ]);

        $this->actingAs($user)
            ->deleteJson('/internal-api/logs', ['ids' => $bulkLogs->pluck('id')->all()])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        foreach ($bulkLogs as $log) {
            $this->assertDatabaseMissing('activity_log', ['id' => $log->id], config('activitylog.database_connection'));
        }

        $this->actingAs($user)
            ->deleteJson('/internal-api/logs/prune/old', ['days' => 60])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertDatabaseMissing('activity_log', ['id' => $oldLog->id], config('activitylog.database_connection'));
        $this->assertDatabaseHas('activity_log', ['id' => $recentLog->id], config('activitylog.database_connection'));
    }
}
