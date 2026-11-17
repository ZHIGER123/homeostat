<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Exceptions\BusinessException;
use App\Models\Track;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $updated = DB::transaction(function () use ($track, $status, $reason) {
            $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->canTransitionTo($status) || $locked->status !== TrackStatus::Pending) {
                throw new BusinessException('Решение можно принять только по треку на модерации.');
            }
            $locked->update([
                'status' => $status,
                'reject_reason' => $status === TrackStatus::Rejected ? $reason : null,
            ]);

            return $locked->load(['artist', 'genre']);
        });

        Log::info('track.moderated', [
            'track_id' => $updated->id,
            'status' => $updated->status->value,
            'moderator_id' => request()->user()?->getAuthIdentifier(),
        ]);

        return $updated;
    }
}

