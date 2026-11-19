<?php

namespace App\Services;

use App\Enums\TrackStatus;
use App\Models\Comment;
use App\Models\Track;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Комментарии к трекам (ФТ-9, ФТ-14).
 */
class CommentService
{
    public function forTrack(Track $track, int $perPage = 20): LengthAwarePaginator
    {
        return $track->comments()->with('author')->latest()->paginate($perPage);
    }

    /** @param array<string, mixed> $data  user_id, body */
    public function create(Track $track, array $data): Comment
    {
        $author = User::findOrFail($data['user_id']);

        if ($author->is_blocked) {
            throw new AccessDeniedHttpException('Пользователь заблокирован.');
        }
        if ($track->status !== TrackStatus::Published) {
            throw new ConflictHttpException('Комментировать можно только опубликованные треки.');
        }

        return DB::transaction(function () use ($track, $author, $data) {
            $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
            if (! $locked->comments_enabled) throw new ConflictHttpException('Автор отключил комментарии к этому треку.');
            if ($locked->status !== TrackStatus::Published) throw new ConflictHttpException('Комментировать можно только опубликованные треки.');
            return $locked->comments()->create(['user_id' => $author->id, 'body' => $data['body']])->load('author');
        });
    }

    public function delete(Comment $comment): void
    {
        $comment->delete();
    }
}
