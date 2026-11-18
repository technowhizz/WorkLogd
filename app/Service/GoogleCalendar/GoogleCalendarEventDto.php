<?php

declare(strict_types=1);

namespace App\Service\GoogleCalendar;

use Carbon\CarbonImmutable;

class GoogleCalendarEventDto
{
    public string $id;

    public string $title;

    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public bool $isAllDay;

    public ?string $htmlLink;

    public function __construct(string $id, string $title, CarbonImmutable $start, CarbonImmutable $end, bool $isAllDay, ?string $htmlLink)
    {
        $this->id = $id;
        $this->title = $title;
        $this->start = $start;
        $this->end = $end;
        $this->isAllDay = $isAllDay;
        $this->htmlLink = $htmlLink;
    }

    /**
     * Plain values for the cache. The cache store refuses to unserialize objects
     * (cache.serializable_classes is false), so the DTO and its Carbon dates cannot go in as is.
     *
     * @return array{id: string, title: string, start: string, end: string, is_all_day: bool, html_link: string|null}
     */
    public function toCacheArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->start->format('Y-m-d\TH:i:s.uP'),
            'end' => $this->end->format('Y-m-d\TH:i:s.uP'),
            'is_all_day' => $this->isAllDay,
            'html_link' => $this->htmlLink,
        ];
    }

    /**
     * @param  array{id: string, title: string, start: string, end: string, is_all_day: bool, html_link: string|null}  $data
     */
    public static function fromCacheArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['title'],
            CarbonImmutable::parse($data['start']),
            CarbonImmutable::parse($data['end']),
            $data['is_all_day'],
            $data['html_link'],
        );
    }
}
