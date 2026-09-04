<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credential encryption key
    |--------------------------------------------------------------------------
    |
    | The key third-party credentials - Jira and Google tokens - are encrypted under. Separate
    | from APP_KEY on purpose: APP_KEY sits in the same environment as the database credentials,
    | so anything that reaches both reaches every stored token. Point this at a secrets manager
    | or an injected file and a leaked `.env` no longer opens your customers' issue trackers.
    |
    | Leave it empty and credentials fall back to APP_KEY, which is what an existing installation
    | already does. Set it, then run `admin:credentials:rotate-key` to move the existing rows.
    |
    | Generate one the same way as APP_KEY:
    |   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
    |
    */

    'credential_key' => env('CREDENTIAL_ENCRYPTION_KEY'),

    'credential_cipher' => env('CREDENTIAL_ENCRYPTION_CIPHER', 'aes-256-cbc'),

];
