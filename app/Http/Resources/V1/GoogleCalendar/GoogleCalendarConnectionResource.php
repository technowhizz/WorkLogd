<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\GoogleCalendar;

use App\Http\Resources\V1\BaseResource;
use App\Models\GoogleCalendarConnection;
use Illuminate\Http\Request;

/**
 * @mixin GoogleCalendarConnection
 *
 * @property-read GoogleCalendarConnection|null $resource
 */
class GoogleCalendarConnectionResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * Note: a user without a connection gets the same shape with `is_connected` false,
     * so the settings card only has to deal with one response shape.
     *
     * @return array<string, string|bool|null>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Whether a Google account is currently connected */
            'is_connected' => $this->resource !== null,
            /** Email address of the connected Google account */
            'email' => $this->resource?->email,
            /** Whether Google rejected the stored credentials and the account needs to be connected again */
            'requires_reauthentication' => $this->resource->requires_reauthentication ?? false,
            /** @var string|null $connected_at When the Google account was connected (ISO 8601 format, UTC timezone, example: 2024-02-26T17:17:17Z) */
            'connected_at' => $this->formatDateTime($this->resource?->created_at),
        ];
    }
}
