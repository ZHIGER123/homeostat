<?php

namespace App\Enums;

enum TrackStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Published, self::Rejected], true),
            self::Published, self::Rejected => $next === self::Pending,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'На модерации',
            self::Published => 'Опубликован',
            self::Rejected => 'Отклонён',
        };
    }
}
