<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\GoogleCalendar;

use App\Http\Resources\V1\BaseResource;
use App\Service\GoogleCalendar\GoogleCalendarEventDto;
use Illuminate\Http\Request;

/**
 * @property-read GoogleCalendarEventDto $resource
 */
class GoogleCalendarEventResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, string|bool|null>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID of the event in Google Calendar */
            'id' => $this->resource->id,
            /** Title of the event */
            'title' => $this->resource->title,
            /** Start of the event (ISO 8601 format, UTC timezone, example: 2024-02-26T17:17:17Z) */
            'start' => $this->resource->start->toIso8601ZuluString(),
            /** End of the event (ISO 8601 format, UTC timezone, example: 2024-02-26T17:17:17Z) */
            'end' => $this->resource->end->toIso8601ZuluString(),
            /** Whether the event covers whole days instead of a time range */
            'is_all_day' => $this->resource->isAllDay,
            /** Link to the event in Google Calendar */
            'html_link' => $this->resource->htmlLink,
        ];
    }
}
