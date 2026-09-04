<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Models\JiraConnection;
use App\Service\Security\CredentialEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Credentials encrypted under a key of their own.
 *
 * The behaviour that matters is the fallback: adding a key must not lock anybody out of rows
 * written before it existed, and removing one must not silently start storing plaintext.
 */
#[CoversClass(CredentialEncrypter::class)]
class CredentialEncrypterTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'base64:YS0zMi1ieXRlLXRlc3Qta2V5LWZvci13b3JrbG9nZCE=';

    private function encrypter(): CredentialEncrypter
    {
        return new CredentialEncrypter;
    }

    public function test_without_a_dedicated_key_it_falls_back_to_the_app_key(): void
    {
        // Arrange
        Config::set('security.credential_key', null);

        // Act
        $payload = $this->encrypter()->encrypt('a-secret');

        // Assert
        $this->assertFalse($this->encrypter()->hasDedicatedKey());
        // Readable by the ordinary application encrypter, which is what an existing installation
        // already has on disk.
        $this->assertSame('a-secret', Crypt::decryptString($payload));
    }

    public function test_with_a_dedicated_key_the_app_key_cannot_open_it(): void
    {
        // Arrange
        Config::set('security.credential_key', self::KEY);

        // Act
        $payload = $this->encrypter()->encrypt('a-secret');

        // Assert
        $this->assertTrue($this->encrypter()->hasDedicatedKey());
        $this->assertSame('a-secret', $this->encrypter()->decrypt($payload));
        // The point of the exercise: APP_KEY alone is no longer enough.
        $this->expectException(DecryptException::class);
        Crypt::decryptString($payload);
    }

    public function test_rows_written_before_the_key_existed_still_open(): void
    {
        // Arrange
        Config::set('security.credential_key', null);
        $legacy = $this->encrypter()->encrypt('written-under-app-key');

        // Act
        Config::set('security.credential_key', self::KEY);

        // Assert
        $this->assertSame('written-under-app-key', $this->encrypter()->decrypt($legacy));
        $this->assertFalse($this->encrypter()->isUnderDedicatedKey($legacy));
    }

    public function test_it_can_tell_which_key_a_value_is_under(): void
    {
        // Arrange
        Config::set('security.credential_key', self::KEY);

        // Act
        $current = $this->encrypter()->encrypt('current');

        // Assert
        $this->assertTrue($this->encrypter()->isUnderDedicatedKey($current));
    }

    public function test_the_model_cast_stores_ciphertext_and_reads_back_plaintext(): void
    {
        // Arrange
        Config::set('security.credential_key', self::KEY);

        // Act
        $connection = JiraConnection::factory()->create(['refresh_token' => 'the-real-token']);

        // Assert
        $stored = DB::table('jira_connections')->where('id', '=', $connection->getKey())->value('refresh_token');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('the-real-token', $stored);
        $this->assertSame('the-real-token', $connection->fresh()?->refresh_token);
    }

    public function test_the_rotation_command_moves_rows_onto_the_dedicated_key(): void
    {
        // Arrange
        Config::set('security.credential_key', null);
        $connection = JiraConnection::factory()->create(['refresh_token' => 'legacy-token']);
        $before = DB::table('jira_connections')->where('id', '=', $connection->getKey())->value('refresh_token');

        // Act
        Config::set('security.credential_key', self::KEY);
        $this->artisan('admin:credentials:rotate-key')->assertSuccessful();

        // Assert
        $after = DB::table('jira_connections')->where('id', '=', $connection->getKey())->value('refresh_token');
        $this->assertNotSame($before, $after);
        $this->assertIsString($after);
        $this->assertTrue($this->encrypter()->isUnderDedicatedKey($after));
        // Still the same secret, just held differently.
        $this->assertSame('legacy-token', $connection->fresh()?->refresh_token);
    }

    public function test_the_rotation_command_is_safe_to_run_twice(): void
    {
        // Arrange
        Config::set('security.credential_key', null);
        $connection = JiraConnection::factory()->create(['refresh_token' => 'legacy-token']);
        Config::set('security.credential_key', self::KEY);
        $this->artisan('admin:credentials:rotate-key')->assertSuccessful();
        $afterFirst = DB::table('jira_connections')->where('id', '=', $connection->getKey())->value('refresh_token');

        // Act
        $this->artisan('admin:credentials:rotate-key')->assertSuccessful();

        // Assert
        // Already on the key, so left exactly as it was rather than re-encrypted for no reason.
        $this->assertSame(
            $afterFirst,
            DB::table('jira_connections')->where('id', '=', $connection->getKey())->value('refresh_token')
        );
    }

    public function test_the_rotation_command_refuses_without_a_key_to_rotate_onto(): void
    {
        // Arrange
        Config::set('security.credential_key', null);

        // Act & Assert
        $this->artisan('admin:credentials:rotate-key')->assertFailed();
    }
}
