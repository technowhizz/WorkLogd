<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedCredential;
use App\Models\Concerns\HasUuids;
use Database\Factories\JiraConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $organization_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string|null $cloud_id
 * @property bool $requires_reauthentication
 * @property Carbon|null $sync_from_date
 * @property Carbon|null $last_verified_at
 * @property-read User $user
 * @property-read Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static JiraConnectionFactory factory()
 */
class JiraConnection extends Model
{
    /** @use HasFactory<JiraConnectionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Never serialized, whatever asks.
     *
     * The API resource already omits them, but that is one deliberate decision in one place -
     * this covers the accidents: a model dumped into a log line, an exception context, a debug
     * response, a future endpoint that returns the model directly.
     *
     * Note the model is also deliberately not auditable, since audit rows would hold copies.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'access_token' => EncryptedCredential::class,
        'refresh_token' => EncryptedCredential::class,
        'token_expires_at' => 'datetime',
        'cloud_id' => 'string',
        'requires_reauthentication' => 'bool',
        'sync_from_date' => 'date',
        'last_verified_at' => 'datetime',
    ];

    /**
     * Whether the access token is past, or nearly past, its life.
     *
     * Refreshed a minute early on purpose: a token that passes this check and then expires while
     * the request is in flight fails for no good reason, and a Jira sync can be dozens of calls.
     */
    public function needsRefresh(): bool
    {
        return $this->token_expires_at === null
            || $this->token_expires_at->subMinute()->isPast();
    }

    public function isConnected(): bool
    {
        return $this->refresh_token !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
