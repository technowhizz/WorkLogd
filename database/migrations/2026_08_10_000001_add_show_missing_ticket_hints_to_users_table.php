<?php

declare(strict_types=1);

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
        Schema::table('users', function (Blueprint $table): void {
            // Whether to mark work entries that carry no Jira issue key. This used to live in
            // localStorage, which meant signing in on a second device silently turned the dots
            // back off. Default false keeps the previous behaviour for everyone who never
            // switched it on, and it backfills every existing row here.
            $table->boolean('show_missing_ticket_hints')->default(false)->after('no_project_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('show_missing_ticket_hints');
        });
    }
};
