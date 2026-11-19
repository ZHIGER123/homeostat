<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use App\Models\User;
use App\Services\ArtistFeatureService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ArtistFeatureController extends Controller
{
    public function __construct(private readonly ArtistFeatureService $features) {}
    public function spotlightIndex(User $user): AnonymousResourceCollection
    {
        $limit = $user->currentPlan() === 'free' ? 1 : 5;
        return TrackResource::collection($user->tracks()->published()->where('is_spotlight', true)->with(['artist','genre'])->withCount(['likes','comments'])->latest()->limit($limit)->get());
    }
    public function spotlight(Request $request, Track $track): TrackResource { $data=$request->validate(['enabled'=>['required','boolean']]); return new TrackResource($this->features->spotlight($request->user(),$track,$data['enabled'])->load(['artist','genre'])); }
    public function boost(Request $request, Track $track): TrackResource { $data=$request->validate(['enabled'=>['required','boolean']]); return new TrackResource($this->features->boost($request->user(),$track,$data['enabled'])->load(['artist','genre'])); }
    public function comments(Request $request, Track $track): TrackResource { $data=$request->validate(['enabled'=>['required','boolean']]); return new TrackResource($this->features->comments($request->user(),$track,$data['enabled'])->load(['artist','genre'])); }
}
