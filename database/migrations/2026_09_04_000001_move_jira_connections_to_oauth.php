<?php

declare(strict_types=1);

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
        Schema::table('jira_connections', function (Blueprint $table): void {
            // OAuth 2.0 (3LO). The access token lasts about an hour; the refresh token rotates on
            // every use, so the stored one is replaced each time rather than reused.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            // OAuth calls go to api.atlassian.com/ex/jira/{cloudId}, not to the site host, so the
            // id of the site the user authorised is resolved once and kept.
            $table->string('cloud_id')->nullable();
        });

        /*
         * Existing API token connections cannot be upgraded in place - an API token cannot be
         * exchanged for OAuth tokens - so they are removed and their owners reconnect. This is
         * also what lets the app declare that it stores no Atlassian personal data: the identity
         * columns below are the only place it ever did, and they are going with them.
         */
        DB::table('jira_connections')->delete();

        Schema::table('jira_connections', function (Blueprint $table): void {
            // Atlassian sourced identity. Not stored any more - the settings screen fetches it
            // from /me when it renders and caches it for well under a day, which keeps the app
            // outside the Personal Data Reporting API's scope.
            $table->dropColumn(['email', 'account_id', 'display_name', 'api_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_connections', function (Blueprint $table): void {
            $table->string('email')->default('');
            $table->string('account_id')->nullable();
            $table->string('display_name')->nullable();
            $table->text('api_token')->default('');
        });

        Schema::table('jira_connections', function (Blueprint $table): void {
            $table->dropColumn(['access_token', 'refresh_token', 'token_expires_at', 'cloud_id']);
        });
    }
};
