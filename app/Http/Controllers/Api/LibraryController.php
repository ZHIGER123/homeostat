<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\UserResource;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LibraryController extends Controller
{
    public function following(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($request->user()->following()->paginate(20));
    }
    public function playlists(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:150']]);
        return PlaylistResource::collection(Playlist::query()->where('is_public', true)->with('owner')->withCount(['tracks' => fn ($query) => $query->published()])
            ->when($q['q'] ?? null, fn ($query, $term) => $query->where('title', 'like', '%'.$term.'%'))
            ->latest()->paginate(20)->withQueryString());
    }
    public function likedPlaylists(Request $request): AnonymousResourceCollection
    {
        return PlaylistResource::collection($request->user()->likedPlaylists()->where('is_public', true)->with('owner')->withCount(['tracks' => fn ($query) => $query->published()])->paginate(20));
    }
    public function likeStatus(Request $request, Playlist $playlist): \Illuminate\Http\JsonResponse
    {
        abort_unless($playlist->is_public || $playlist->user_id === $request->user()->id || $request->user()->isAdmin(), 404);
        return response()->json(['liked' => $request->user()->likedPlaylists()->whereKey($playlist->id)->exists()]);
    }
    public function likePlaylist(Request $request, Playlist $playlist): Response
    {
        abort_unless($playlist->is_public, 404);
        $request->user()->likedPlaylists()->syncWithoutDetaching([$playlist->id]);
        return response()->noContent();
    }
    public function unlikePlaylist(Request $request, Playlist $playlist): Response
    {
        $request->user()->likedPlaylists()->detach($playlist->id);
        return response()->noContent();
    }
}
