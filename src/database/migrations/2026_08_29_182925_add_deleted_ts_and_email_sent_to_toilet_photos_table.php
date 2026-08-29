<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('toilet_photos', function (Blueprint $table) {
            $table->dateTime('deleted_ts')->nullable()->after('inserted');
            $table->tinyInteger('email_sent')->default(0)->after('deleted_ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('toilet_photos', function (Blueprint $table) {
            $table->dropColumn(['deleted_ts', 'email_sent']);
        });
    }
};
