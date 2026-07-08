<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gratitude_levels', function (Blueprint $table) {
            $table->decimal('gift_card_points_per_dollar', 8, 2)->default(35)->after('partner_points_per_dollar');
            $table->decimal('airline_miles_points_per_dollar', 8, 2)->default(35)->after('gift_card_points_per_dollar');
        });
    }

    public function down(): void
    {
        Schema::table('gratitude_levels', function (Blueprint $table) {
            $table->dropColumn([
                'gift_card_points_per_dollar',
                'airline_miles_points_per_dollar',
            ]);
        });
    }
};
