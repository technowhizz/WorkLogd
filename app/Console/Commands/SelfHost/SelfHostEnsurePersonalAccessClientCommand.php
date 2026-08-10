<?php

declare(strict_types=1);

namespace App\Console\Commands\SelfHost;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\QueryException;
use Laravel\Passport\ClientRepository;
use RuntimeException;

/**
 * Isolatable because the app, worker and scheduler containers boot together and each runs
 * this. Without the lock all three can pass the "does one exist" check before any of them has
 * created it, and the install ends up with three personal access clients.
 */
class SelfHostEnsurePersonalAccessClientCommand extends Command implements Isolatable
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'self-host:ensure-personal-access-client
                { --name= : The name to give the client, if one has to be created }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make sure a personal access client exists, so the API token screen can mint tokens';

    /**
     * Execute the console command.
     *
     * Passport 13 resolves the personal access client by querying oauth_clients for a
     * non-revoked client carrying the personal_access grant - there is no configured id or
     * secret any more, and the grant never transmits one. So the only thing a fresh install
     * is missing is the row, which this creates. Idempotent, so it can run on every boot.
     */
    public function handle(ClientRepository $clients): int
    {
        $provider = (string) config('auth.guards.api.provider');

        // QueryException has to be caught ahead of RuntimeException, which it extends by way of
        // PDOException. Otherwise a database that is not migrated yet reads as "no client
        // exists" and we go on to create one against a table that is not there.
        try {
            $client = $clients->personalAccessClient($provider);

            $this->info('Personal access client already exists.');
            $this->line('ID: '.$client->getKey());

            return self::SUCCESS;
        } catch (QueryException $exception) {
            return $this->reportDatabaseNotReady($exception);
        } catch (RuntimeException) {
            // None for this provider yet, so make one below.
        }

        $name = $this->option('name');

        try {
            $client = $clients->createPersonalAccessGrantClient(
                is_string($name) && $name !== '' ? $name : config('app.name').' Personal Access Client',
                $provider
            );
        } catch (QueryException $exception) {
            return $this->reportDatabaseNotReady($exception);
        }

        $this->info('Created the personal access client.');
        $this->line('ID: '.$client->getKey());

        return self::SUCCESS;
    }

    /**
     * Reached before the database is migrated, which is normal when AUTO_DB_MIGRATE is off.
     * Only the API token screen depends on this, so report it and let the container boot.
     */
    private function reportDatabaseNotReady(QueryException $exception): int
    {
        $this->warn('Could not read oauth_clients, so the personal access client was not checked.');
        $this->line($exception->getMessage());
        $this->line('Run `php artisan self-host:ensure-personal-access-client` once the database is migrated.');

        return self::SUCCESS;
    }
}
