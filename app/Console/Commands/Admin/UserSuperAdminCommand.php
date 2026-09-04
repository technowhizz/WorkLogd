<?php

declare(strict_types=1);

namespace App\Console\Commands\Admin;

use App\Models\User;
use Illuminate\Console\Command;

class UserSuperAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:user:super-admin
                { email : The email of the user to grant or revoke admin access for }
                { --revoke : Take the access away instead of granting it }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant or revoke access to the admin panel for a user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $revoke = (bool) $this->option('revoke');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)
            ->where('is_placeholder', '=', false)
            ->first();

        if ($user === null) {
            $this->error('User with email "'.$email.'" not found.');

            return self::FAILURE;
        }

        $user->is_admin = ! $revoke;
        $user->save();

        if ($revoke) {
            $this->info('User with email "'.$email.'" can no longer access the admin panel.');

            if ($user->isSuperAdminByConfiguration()) {
                $this->warn('The SUPER_ADMINS environment variable still names this user, so the access remains. Remove it there too.');
            }

            return self::SUCCESS;
        }

        $this->info('User with email "'.$email.'" can now access the admin panel.');

        if (! $user->hasVerifiedEmail()) {
            $this->warn('Their email address is not verified yet, and the panel stays closed until it is. Verify it with "admin:user:verify '.$email.'".');
        }

        return self::SUCCESS;
    }
}
