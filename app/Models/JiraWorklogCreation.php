<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Database\Factories\JiraWorklogCreationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One worklog created in Jira, recorded once and never revised.
 *
 * This is the ledger the free tier's weekly allowance is counted from. Nothing deletes from it
 * during normal use - that is the whole point, since counting anything a customer can remove
 * lets them remove their way back to a full allowance.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $issue_key
 * @property string $jira_worklog_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 *
 * @method static JiraWorklogCreationFactory factory()
 */
class JiraWorklogCreation extends Model
{
    /** @use HasFactory<JiraWorklogCreationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'jira_worklog_creations';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'issue_key' => 'string',
        'jira_worklog_id' => 'string',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
