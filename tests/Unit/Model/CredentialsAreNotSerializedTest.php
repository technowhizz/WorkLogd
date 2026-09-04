<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Models\GoogleCalendarConnection;
use App\Models\JiraConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Stored third-party credentials must not leave the process by accident.
 *
 * The API resources already omit them deliberately. This covers everything that is not
 * deliberate - a model in a log line, an exception context, a debug response.
 */
#[CoversClass(JiraConnection::class)]
#[CoversClass(GoogleCalendarConnection::class)]
class CredentialsAreNotSerializedTest extends ModelTestAbstract
{
    public function test_jira_oauth_tokens_are_never_in_the_array_form(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create([
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
        ]);

        // Act
        $array = $connection->toArray();
        $json = $connection->toJson();

        // Assert
        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertStringNotContainsString('secret-access-token', $json);
        $this->assertStringNotContainsString('secret-refresh-token', $json);
    }

    public function test_google_calendar_tokens_are_never_in_the_array_form(): void
    {
        // Arrange
        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'secret-access-token',
            'refresh_token' => 'secret-refresh-token',
        ]);

        // Act
        $array = $connection->toArray();
        $json = $connection->toJson();

        // Assert
        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertStringNotContainsString('secret-access-token', $json);
        $this->assertStringNotContainsString('secret-refresh-token', $json);
    }

    public function test_the_tokens_are_not_readable_as_plaintext_in_the_database(): void
    {
        // Arrange
        $connection = JiraConnection::factory()->create(['refresh_token' => 'super-secret-token']);

        // Act
        $stored = DB::table('jira_connections')
            ->where('id', '=', $connection->getKey())
            ->value('refresh_token');

        // Assert
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('super-secret-token', $stored);
    }
}
