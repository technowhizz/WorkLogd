<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jira_worklogs', function (Blueprint $table): void {
            // Which time entries formed this worklog, as a jsonb array of their UUIDs. This is
            // what lets a worklog keep its identity when a description, date or timezone edit
            // changes the group_hash: the entries are still the same entries. Nullable because
            // rows from before this column existed are stamped on their next sync. A stale id in
            // the array is inert - membership is only ever read as "do the *current* groups
            // overlap it" - so no cleanup runs when a time entry is deleted.
            $table->jsonb('time_entry_ids')->nullable();
        });

        // GIN with the default jsonb_ops opclass: the lookup uses the ?| operator, which
        // jsonb_path_ops does not support.
        DB::statement('CREATE INDEX jira_worklogs_time_entry_ids_index ON jira_worklogs USING GIN (time_entry_ids)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS jira_worklogs_time_entry_ids_index');
        Schema::table('jira_worklogs', function (Blueprint $table): void {
            $table->dropColumn('time_entry_ids');
        });
    }
};
