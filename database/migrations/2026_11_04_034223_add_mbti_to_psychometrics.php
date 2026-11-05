<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('psychometric_tests', function (Blueprint $table): void {
            $table->string('type', 10)->default('iq');
        });
        Schema::table('psychometric_attempts', function (Blueprint $table): void {
            $table->json('result')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('psychometric_tests')->where('type', 'mbti')->exists()) {
            throw new RuntimeException('Hapus paket MBTI beserta penugasannya sebelum membatalkan migrasi ini.');
        }
        Schema::table('psychometric_attempts', function (Blueprint $table): void {
            $table->dropColumn('result');
        });
        Schema::table('psychometric_tests', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
