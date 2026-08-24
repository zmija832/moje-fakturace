<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('central')->hasColumn('businesses', 'theme_color')) {
            Schema::connection('central')->table('businesses', function (Blueprint $table): void {
                $table->string('theme_color', 16)->default('blue')->after('visual_identifier');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('central')->hasColumn('businesses', 'theme_color')) {
            Schema::connection('central')->table('businesses', fn (Blueprint $table) => $table->dropColumn('theme_color'));
        }
    }
};
