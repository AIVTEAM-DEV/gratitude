<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratitude_terms_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('content');
            $table->boolean('status')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source_key')->nullable()->unique();
            $table->timestamps();
        });

        if (Schema::hasColumn('gratitude_levels', 'terms_conditions')) {
            DB::table('gratitude_levels')
                ->whereNotNull('terms_conditions')
                ->pluck('terms_conditions')
                ->map(fn ($content) => trim((string) $content))
                ->filter()
                ->unique()
                ->values()
                ->each(function (string $content, int $index) {
                    DB::table('gratitude_terms_conditions')->insert([
                        'content' => $content,
                        'status' => true,
                        'sort_order' => $index + 1,
                        'source_key' => 'legacy-program-terms-'.($index + 1),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
        }

        $legacyColumns = collect([
            'terms_conditions',
            'level_terms_conditions',
        ])->filter(fn (string $column) => Schema::hasColumn('gratitude_levels', $column));

        if ($legacyColumns->isNotEmpty()) {
            Schema::table('gratitude_levels', function (Blueprint $table) use ($legacyColumns) {
                $table->dropColumn($legacyColumns->all());
            });
        }
    }

    public function down(): void
    {
        Schema::table('gratitude_levels', function (Blueprint $table) {
            $table->text('terms_conditions')->nullable();
            $table->text('level_terms_conditions')->nullable();
        });

        Schema::dropIfExists('gratitude_terms_conditions');
    }
};
