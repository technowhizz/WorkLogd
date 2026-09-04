<?php

declare(strict_types=1);

namespace App\Console\Commands\Admin;

use App\Models\GoogleCalendarConnection;
use App\Models\JiraConnection;
use App\Service\Security\CredentialEncrypter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class RotateCredentialKeyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'admin:credentials:rotate-key
                { --dry-run : Report what would move without writing anything }';

    /**
     * @var string
     */
    protected $description = 'Re-encrypt stored third-party credentials under CREDENTIAL_ENCRYPTION_KEY';

    /**
     * Move every stored credential onto the dedicated key.
     *
     * Safe to run twice: a row already readable under the dedicated key is left alone, so an
     * interrupted run is resumed simply by running it again. Reading goes through
     * CredentialEncrypter, which falls back to APP_KEY, and writing always uses the dedicated
     * key - so the rotation is just "read it, save it".
     */
    public function handle(): int
    {
        $encrypter = app(CredentialEncrypter::class);

        if (! $encrypter->hasDedicatedKey()) {
            $this->error('CREDENTIAL_ENCRYPTION_KEY is not set, so there is nothing to rotate onto.');
            $this->line('Generate one with: php -r "echo \'base64:\'.base64_encode(random_bytes(32)).PHP_EOL;"');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->targets() as [$modelClass, $table, $columns]) {
            foreach ($modelClass::query()->cursor() as $record) {
                foreach ($columns as $column) {
                    /** @var string|null $stored */
                    $stored = DB::table($table)->where('id', '=', $record->getKey())->value($column);

                    if (! is_string($stored) || $stored === '') {
                        continue;
                    }

                    if ($encrypter->isUnderDedicatedKey($stored)) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $moved++;

                        continue;
                    }

                    try {
                        // The cast decrypts on read (falling back to APP_KEY) and encrypts on
                        // write (always the dedicated key), so this is the whole rotation.
                        $plaintext = $record->getAttribute($column);
                        $record->setAttribute($column, $plaintext);
                        $record->save();
                        $moved++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error(sprintf(
                            '%s %s.%s could not be re-encrypted: %s',
                            $table,
                            (string) $record->getKey(),
                            $column,
                            $exception->getMessage(),
                        ));
                    }
                }
            }
        }

        $this->info(sprintf(
            '%s %d credential%s, left %d already on the key alone.',
            $dryRun ? 'Would move' : 'Moved',
            $moved,
            $moved === 1 ? '' : 's',
            $skipped,
        ));

        if ($failed > 0) {
            $this->warn($failed.' could not be read and were left as they are. They will need reconnecting by hand.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{0: class-string<Model>, 1: string, 2: array<int, string>}>
     */
    private function targets(): array
    {
        return [
            [JiraConnection::class, 'jira_connections', ['access_token', 'refresh_token']],
            [GoogleCalendarConnection::class, 'google_calendar_connections', ['access_token', 'refresh_token']],
        ];
    }
}
