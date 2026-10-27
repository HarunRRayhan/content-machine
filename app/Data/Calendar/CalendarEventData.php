<?php

namespace App\Data\Calendar;

/**
 * One scheduled or published occurrence on the content calendar.
 */
final readonly class CalendarEventData
{
    public function __construct(
        public string $kind,
        public int $id,
        public string $humanId,
        public string $title,
        public string $state,
        public string $at,
        public string $date,
        public string $category = '',
    ) {}

    /**
     * @return array{
     *     kind: string,
     *     id: int,
     *     human_id: string,
     *     title: string,
     *     state: string,
     *     at: string,
     *     date: string,
     *     category: string,
     *     href: string
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'human_id' => $this->humanId,
            'title' => $this->title,
            'state' => $this->state,
            'at' => $this->at,
            'date' => $this->date,
            'category' => $this->category,
            'href' => $this->kind === 'video' ? '/videos/'.$this->humanId : '/posts/'.$this->humanId,
        ];
    }
}
