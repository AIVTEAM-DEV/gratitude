<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratitudes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('old_id')->nullable();
            $table->string('gratitudeNumber')->nullable()->unique();
            $table->integer('totalPoints')->nullable();
            $table->integer('totalEarnedPoints')->nullable();
            $table->integer('totalBonusPoints')->nullable();
            $table->integer('totalExpiredPoints')->nullable();
            $table->integer('totalCancelledPoints')->nullable();
            $table->integer('totalRedeemedPoints')->nullable();
            $table->integer('totalRemainingPoints')->nullable();
            $table->integer('useablePoints')->nullable();
            $table->integer('nonUseablePoints')->nullable();
            $table->string('level')->nullable();
            $table->json('levelHistory')->nullable();
            $table->timestamp('level_obtained_at')->nullable();
            $table->string('status')->nullable();
            $table->string('statusChange')->nullable();
            $table->string('statusChangeReason')->nullable();
            $table->boolean('systemLevelUpdate')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('importStatus')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'is_active']);
            $table->index('level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratitudes');
    }
};
