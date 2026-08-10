<?php

declare(strict_types=1);

namespace App\Service\Jira;

use App\Exceptions\Api\JiraAuthenticationFailedApiException;
use App\Exceptions\Api\JiraNotConfiguredApiException;
use App\Exceptions\Api\JiraRequestFailedApiException;
use App\Models\JiraConnection;
use Carbon\CarbonInterface;

/**
 * The whole of Jira, as far as the rest of the application is concerned: check who a token
 * belongs to, and create, update or delete a worklog.
 *
 * It exists purely so that the end to end suite can swap in FakeJiraClient and drive the entire
 * integration - connect, preview, sync, indicators - without an Atlassian account or a single
 * outbound request. JiraClient remains the only implementation used anywhere real, and is still
 * built on the Http facade so Http::preventStrayRequests() covers it in PHPUnit.
 */
interface JiraClientContract
{
    /**
     * The account the token belongs to. Used to check credentials when they are saved, and to
     * show which account is linked.
     *
     * @return array{account_id: string|null, display_name: string|null, email: string|null}
     *
     * @throws JiraNotConfiguredApiException
     * @throws JiraAuthenticationFailedApiException
     * @throws JiraRequestFailedApiException
     */
    public function myself(JiraConnection $connection): array;

    /**
     * @return string The new worklog's id in Jira
     *
     * @throws JiraNotConfiguredApiException
     * @throws JiraAuthenticationFailedApiException
     * @throws JiraRequestFailedApiException
     */
    public function createWorklog(JiraConnection $connection, string $issueKey, ?string $comment, CarbonInterface $startedAt, int $durationSeconds): string;

    /**
     * @throws JiraNotConfiguredApiException
     * @throws JiraAuthenticationFailedApiException
     * @throws JiraRequestFailedApiException
     */
    public function updateWorklog(JiraConnection $connection, string $issueKey, string $worklogId, ?string $comment, CarbonInterface $startedAt, int $durationSeconds): void;

    /**
     * @throws JiraNotConfiguredApiException
     * @throws JiraAuthenticationFailedApiException
     * @throws JiraRequestFailedApiException
     */
    public function deleteWorklog(JiraConnection $connection, string $issueKey, string $worklogId): void;
}
