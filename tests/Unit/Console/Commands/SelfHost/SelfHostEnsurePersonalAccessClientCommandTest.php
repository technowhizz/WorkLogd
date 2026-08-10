<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SelfHost;

use App\Console\Commands\SelfHost\SelfHostEnsurePersonalAccessClientCommand;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(SelfHostEnsurePersonalAccessClientCommand::class)]
class SelfHostEnsurePersonalAccessClientCommandTest extends TestCaseWithDatabase
{
    public function test_creates_a_personal_access_client_if_there_is_none(): void
    {
        // Arrange
        Client::query()->delete();

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('self-host:ensure-personal-access-client');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Created the personal access client.', Artisan::output());
        $clients = Client::query()->get();
        $this->assertCount(1, $clients);
        $client = $clients->firstOrFail();
        $this->assertContains('personal_access', $client->grant_types);
        $this->assertSame(config('auth.guards.api.provider'), $client->provider);
        $this->assertFalse($client->revoked);
    }

    public function test_does_not_create_a_second_client_if_one_already_exists(): void
    {
        // Arrange
        Client::query()->delete();
        $this->artisan('self-host:ensure-personal-access-client');
        $existing = Client::query()->firstOrFail();

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('self-host:ensure-personal-access-client');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Personal access client already exists.', Artisan::output());
        $this->assertCount(1, Client::query()->get());
        $this->assertSame($existing->getKey(), Client::query()->firstOrFail()->getKey());
    }

    public function test_uses_the_given_name_for_a_created_client(): void
    {
        // Arrange
        Client::query()->delete();

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('self-host:ensure-personal-access-client --name="Custom Name"');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('Custom Name', Client::query()->firstOrFail()->name);
    }

    public function test_does_not_fail_the_boot_if_the_database_is_not_migrated_yet(): void
    {
        // Arrange
        $this->mock(ClientRepository::class, function ($mock): void {
            $mock->shouldReceive('personalAccessClient')->andThrow(new QueryException(
                'pgsql',
                'select * from "oauth_clients"',
                [],
                new Exception('relation "oauth_clients" does not exist')
            ));
        });

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('self-host:ensure-personal-access-client');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Could not read oauth_clients', Artisan::output());
    }
}
