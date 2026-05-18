<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratitude_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('min_points')->default(0);
            $table->integer('max_points')->nullable();
            $table->boolean('status')->default(true);
            $table->decimal('redemption_points_per_dollar', 8, 2)->default(35);
            $table->decimal('partner_points_per_dollar', 8, 2)->default(35);
            $table->unsignedInteger('earned_expire_days')->default(730);
            $table->unsignedInteger('bonus_expire_days')->default(730);
            $table->unsignedSmallInteger('level_interval_years')->default(2);
            $table->unsignedTinyInteger('min_journeys')->default(0);
            $table->unsignedTinyInteger('jetsetter_min_journeys')->nullable();
            $table->unsignedTinyInteger('jetsetter_min_journey_days')->nullable();
            $table->text('stay_active_rules')->nullable();
            $table->json('level_rules')->nullable();
            $table->text('terms_conditions')->nullable();
            $table->text('level_terms_conditions')->nullable();
            $table->string('level_image')->nullable();
            $table->string('level_icon')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratitude_levels');
    }
};
