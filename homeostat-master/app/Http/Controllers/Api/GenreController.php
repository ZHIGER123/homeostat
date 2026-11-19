<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGenreRequest;
use App\Http\Requests\UpdateGenreRequest;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use App\Services\GenreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GenreController extends Controller
{
    public function __construct(private readonly GenreService $genres) {}

    /** GET /api/v1/genres — справочник жанров. */
    public function index(): AnonymousResourceCollection
    {
        return GenreResource::collection($this->genres->all());
    }

    /** POST /api/v1/genres — создание жанра (администратор). */
    public function store(StoreGenreRequest $request): JsonResponse
    {
        $genre = $this->genres->create($request->validated());

        return (new GenreResource($genre))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** GET /api/v1/genres/{genre} */
    public function show(Genre $genre): GenreResource
    {
        return new GenreResource($this->genres->find($genre));
    }

    /** PUT|PATCH /api/v1/genres/{genre} */
    public function update(UpdateGenreRequest $request, Genre $genre): GenreResource
    {
        return new GenreResource($this->genres->update($genre, $request->validated()));
    }

    /** DELETE /api/v1/genres/{genre} — 409, если у жанра есть треки. */
    public function destroy(Genre $genre): Response
    {
        $this->genres->delete($genre);

        return response()->noContent();
    }
}
