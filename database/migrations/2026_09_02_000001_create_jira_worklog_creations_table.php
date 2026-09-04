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
        /*
         * An append-only record of every worklog this instance has created in Jira.
         *
         * The free tier's weekly allowance used to be counted from the live jira_worklogs rows,
         * which is wrong in a way that is easy to exploit: removing a worklog deletes its row, so
         * syncing five and then deleting them handed the allowance straight back. Five, delete,
         * five, delete - unlimited.
         *
         * This table is never written to by a delete. A row here means "a worklog was created in
         * Jira at this moment", which stays true no matter what happens to the worklog afterwards.
         */
        Schema::create('jira_worklog_creations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->uuid('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            // Kept for support rather than for counting: when somebody disputes their allowance,
            // this says which worklogs it was spent on.
            $table->string('issue_key');
            $table->string('jira_worklog_id');
            $table->timestamps();

            // The only query this table serves: how many for this organization since a date.
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_worklog_creations');
    }
};
