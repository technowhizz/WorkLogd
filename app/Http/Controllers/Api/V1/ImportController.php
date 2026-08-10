<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\V1\Import\ImportRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Service\Import\Importers\ImporterContract;
use App\Service\Import\Importers\ImporterProvider;
use App\Service\Import\Importers\ImportException;
use App\Service\Import\ImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    /**
     * Get information about available importers
     *
     * @operationId getImporters
     *
     * @throws AuthorizationException
     *
     * @response array{data: array<array{ key: string, name: string, description: string, supports_member_assignment: bool }>}
     */
    public function index(Organization $organization, ImporterProvider $importerProvider): JsonResponse
    {
        $this->checkPermission($organization, 'import');

        $importers = $importerProvider->getImporters();

        /** @var array<array{ key: string, name: string, description: string, supports_member_assignment: bool }> $importersResponse */
        $importersResponse = [];

        foreach ($importers as $key => $importerClass) {
            /** @var ImporterContract $importer */
            $importer = new $importerClass;
            $importersResponse[] = [
                'key' => $key,
                'name' => $importer->getName(),
                'description' => $importer->getDescription(),
                'supports_member_assignment' => $importer->supportsTargetMember(),
            ];
        }

        return new JsonResponse([
            'data' => $importersResponse,
        ], 200);
    }

    /**
     * Import data into the organization
     *
     * @throws AuthorizationException
     *
     * @operationId importData
     */
    public function import(Organization $organization, ImportRequest $request, ImportService $importService): JsonResponse
    {
        $this->checkPermission($organization, 'import');

        try {
            $importData = base64_decode($request->input('data'), true);
            if ($importData === false) {
                /*
                 * Logged because every other way this endpoint can answer 400 is reported, and a
                 * silent one is indistinguishable from the request never arriving - which is
                 * exactly the wrong guess to be left with. The payload itself is deliberately not
                 * logged: it is the customer's data. Its length is, since a truncated body on the
                 * way in is the likeliest way to get here with an otherwise valid file.
                 */
                $data = $request->input('data');
                Log::warning('Import rejected: data is not valid base64', [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $this->user()->getKey(),
                    'importer_type' => $request->input('type'),
                    'payload_length' => is_string($data) ? strlen($data) : null,
                ]);

                return new JsonResponse([
                    'message' => 'Invalid base64 encoded data',
                ], 400);
            }

            $memberId = $request->getMemberId();
            $targetMember = $memberId === null ? null : Member::query()->findOrFail($memberId);

            $timezone = $this->user()->timezone;
            $report = $importService->import(
                $organization,
                $request->input('type'),
                $importData,
                $timezone,
                $targetMember
            );

            return new JsonResponse([
                /** @var array{
                 *   clients: array{
                 *     created: int,
                 *   },
                 *   projects: array{
                 *     created: int,
                 *   },
                 *   tasks: array{
                 *     created: int,
                 *   },
                 *   time_entries: array{
                 *     created: int,
                 *   },
                 *   tags: array{
                 *     created: int,
                 *   },
                 *   users: array{
                 *     created: int,
                 *   }
                 * } $report Import report */
                'report' => $report->toArray(),
            ], 200);
        } catch (ImportException $exception) {
            report($exception);

            return new JsonResponse([
                'message' => $exception->getMessage(),
            ], 400);
        }
    }
}
