<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Models\Track;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Модерация треков администратором: очередь и решение (ФТ-13).
 */
class TrackModerationService
{
    /** Очередь треков по статусу (по умолчанию — на модерации), старые первыми. */
    public function queue(?TrackStatus $status, int $perPage = 20): LengthAwarePaginator
    {
        return Track::query()
            ->where('status', $status ?? TrackStatus::Pending)
            ->with(['artist', 'genre'])
            ->oldest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Переход допустим только из pending; при rejected причина обязательна
     * (проверяется в ModerateTrackRequest).
     */
    public function decide(Track $track, TrackStatus $status, ?string $reason): Track
    {
        if ($track->status !== TrackStatus::Pending) {
            throw new ConflictHttpException('Решение можно принять только по треку на модерации.');
        }

        $track->update([
            'status' => $status,
            'reject_reason' => $status === TrackStatus::Rejected ? $reason : null,
        ]);

        return $track->load(['artist', 'genre']);
    }
}
