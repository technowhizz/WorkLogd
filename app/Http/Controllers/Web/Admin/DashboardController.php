<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Models\Organization;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\BillingOverviewService;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $range = $request->string('range')->toString();
        if (! in_array($range, ['week', 'month', 'year'], true)) {
            $range = 'week';
        }

        return Inertia::render('Admin/Overview', [
            'server' => [
                'version' => config('app.version'),
                'build' => config('app.build'),
                'environment' => config('app.env'),
                'php' => PHP_VERSION,
            ],
            'users' => [
                'total' => User::query()->where('is_placeholder', '=', false)->count(),
                'placeholder' => User::query()->where('is_placeholder', '=', true)->count(),
                'active' => User::query()
                    ->where('is_placeholder', '=', false)
                    ->whereHas('timeEntries', function (Builder $query): void {
                        /** @var Builder<TimeEntry> $query */
                        $query->where('created_at', '>=', now()->subWeek())
                            ->orWhere('updated_at', '>=', now()->subWeek());
                    })
                    ->count(),
                // Both routes in, since the environment list grants access without the flag.
                'admins' => User::query()
                    ->where('is_admin', '=', true)
                    ->orWhereIn('email', config('auth.super_admins', []))
                    ->count(),
            ],
            'organizations' => [
                'total' => Organization::query()->count(),
                'team' => Organization::query()->where('personal_team', '=', false)->count(),
            ],
            'billing' => app(BillingOverviewService::class)->stats(),
            'range' => $range,
            'charts' => [
                'registrations' => $this->trend(
                    User::query()->where('is_placeholder', '=', false),
                    $range
                ),
                'timeEntriesCreated' => $this->trend(TimeEntry::query(), $range),
                'timeEntriesImported' => $this->trend(
                    TimeEntry::query()->where('is_imported', '=', true),
                    $range
                ),
            ],
        ]);
    }

    /**
     * A daily (or monthly, over a year) count for a chart.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int, array{date: string, value: int}>
     */
    private function trend(Builder $query, string $range, string $column = 'created_at'): array
    {
        $start = match ($range) {
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => now()->subWeek(),
        };

        $trend = Trend::query($query)
            ->dateColumn($column)
            ->between(start: $start, end: now());

        $trend = $range === 'year' ? $trend->perMonth() : $trend->perDay();

        return $trend->count()
            ->map(fn (TrendValue $value): array => [
                'date' => $value->date,
                'value' => (int) $value->aggregate,
            ])
            ->all();
    }
}
