<?php

declare(strict_types=1);

namespace App\Casts;

use App\Service\Security\CredentialEncrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A drop-in replacement for the `encrypted` cast that uses the credential key.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedCredential implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(CredentialEncrypter::class)->decrypt((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(CredentialEncrypter::class)->encrypt((string) $value);
    }
}
