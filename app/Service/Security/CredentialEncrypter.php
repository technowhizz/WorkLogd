<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Console\Commands\Admin\RotateCredentialKeyCommand;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Encrypts third-party credentials under a key of their own.
 *
 * Everything else the application encrypts uses APP_KEY, which lives in the same environment as
 * the database credentials - so anything that reaches both reaches every stored Jira and Google
 * token as well. Giving credentials a separate key means it can be held somewhere the application
 * environment is not: a secrets manager, a KMS, an injected file. A leaked `.env` then costs you
 * sessions and signed URLs rather than every customer's access to their issue tracker.
 *
 * Falls back to APP_KEY when no dedicated key is set, so an existing installation keeps working
 * and upgrades on its own schedule. {@see RotateCredentialKeyCommand}
 * moves existing rows onto a new key.
 */
class CredentialEncrypter
{
    private ?Encrypter $encrypter = null;

    /**
     * Whether a key separate from APP_KEY is in use.
     */
    public function hasDedicatedKey(): bool
    {
        return $this->configuredKey() !== null;
    }

    public function encrypt(string $value): string
    {
        return $this->encrypterFor($this->configuredKey())->encryptString($value);
    }

    /**
     * Decrypt a stored credential.
     *
     * Tries the dedicated key first and falls back to APP_KEY, so rows written before the key was
     * introduced keep opening while they wait to be rotated. Without the fallback, adding a key
     * would silently break every existing connection.
     */
    public function decrypt(string $payload): string
    {
        $key = $this->configuredKey();

        if ($key !== null) {
            try {
                return $this->encrypterFor($key)->decryptString($payload);
            } catch (DecryptException) {
                // Written before the dedicated key existed. Fall through.
            }
        }

        return Crypt::decryptString($payload);
    }

    /**
     * Whether a stored value is already readable under the dedicated key.
     *
     * Used by the rotation command to tell "needs moving" from "already moved", so it can be run
     * twice without doing damage the second time.
     */
    public function isUnderDedicatedKey(string $payload): bool
    {
        $key = $this->configuredKey();

        if ($key === null) {
            return false;
        }

        try {
            $this->encrypterFor($key)->decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    private function encrypterFor(?string $key): Encrypter
    {
        if ($key === null) {
            /** @var Encrypter $default */
            $default = Crypt::getFacadeRoot();

            return $default;
        }

        return $this->encrypter ??= new Encrypter($this->parse($key), $this->cipher());
    }

    private function configuredKey(): ?string
    {
        $key = config('security.credential_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function cipher(): string
    {
        /** @var string $cipher */
        $cipher = config('security.credential_cipher', 'aes-256-cbc');

        return $cipher;
    }

    /**
     * The same base64: convention Laravel uses for APP_KEY, so a key can be generated the same way.
     */
    private function parse(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(mb_substr($key, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('CREDENTIAL_ENCRYPTION_KEY is not valid base64.');
            }

            return $decoded;
        }

        return $key;
    }
}
