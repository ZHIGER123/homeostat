<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Track;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function index(User $user): AnonymousResourceCollection
    {
        $me = request()->user();
        Message::where('sender_id', $user->id)->where('recipient_id', $me->id)->whereNull('read_at')->update(['read_at' => now()]);
        $messages = Message::query()
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('sender_id', $me->id)->where('recipient_id', $user->id))->orWhere(fn ($q) => $q->where('sender_id', $user->id)->where('recipient_id', $me->id)))
            ->with(['sender', 'recipient', 'track.artist', 'track.genre'])
            ->oldest()
            ->paginate(100);
        return MessageResource::collection($messages);
    }

    public function store(StoreMessageRequest $request, User $user): MessageResource
    {
        $me = $request->user();
        if ($me->is($user)) {
            throw ValidationException::withMessages(['recipient_id' => 'Нельзя отправить сообщение самому себе.']);
        }
        if ($user->is_blocked) {
            throw ValidationException::withMessages(['recipient_id' => 'Этому пользователю нельзя отправить сообщение.']);
        }
        $data = $request->validated();
        if (! empty($data['track_id']) && ! Track::whereKey($data['track_id'])->published()->exists()) {
            throw ValidationException::withMessages(['track_id' => 'Отправлять можно только опубликованные треки.']);
        }
        $message = Message::create([
            'sender_id' => $me->id,
            'recipient_id' => $user->id,
            'track_id' => $data['track_id'] ?? null,
            'body' => trim((string) ($data['body'] ?? '')) ?: null,
        ]);
        return new MessageResource($message->load(['sender', 'recipient', 'track.artist', 'track.genre']));
    }
}
