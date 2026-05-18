<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $remainingPointsExpression = 'CASE WHEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) > 0 THEN COALESCE(points, 0) - COALESCE(redeemed_points, 0) - COALESCE(cancelled_points, 0) ELSE 0 END';

        Schema::create('bonus_points', function (Blueprint $table) use ($remainingPointsExpression) {
            $table->id();
            $table->bigInteger('old_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->bigInteger('journey_id')->nullable();
            $table->date('date')->nullable();
            $table->string('category')->nullable();
            $table->string('type')->nullable();
            $table->string('gratitudeNumber');
            $table->integer('points')->default(0);
            $table->json('points_breakdown')->nullable();
            $table->integer('redeemed_points')->default(0);
            $table->integer('cancelled_points')->default(0);
            $table->integer('remaining_points')->virtualAs($remainingPointsExpression);
            $table->text('redemption_history')->nullable();
            $table->string('amount')->nullable();
            $table->string('description')->nullable();
            $table->bigInteger('cancel_id')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->timestamp('usable_date')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('expires_at_manual')->default(false);

            $table->index(['gratitudeNumber', 'status']);
            $table->index(['gratitudeNumber', 'usable_date']);
            $table->index(['gratitudeNumber', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_points');
    }
};
