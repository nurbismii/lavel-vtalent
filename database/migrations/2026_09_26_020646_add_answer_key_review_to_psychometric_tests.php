<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psychometric_tests', function (Blueprint $table): void {
            $table->json('answer_key_review')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('psychometric_tests', function (Blueprint $table): void {
            $table->dropColumn('answer_key_review');
        });
    }
};
