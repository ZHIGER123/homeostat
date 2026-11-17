<?php

use App\Http\Controllers\Api\Admin\TrackModerationController;
use App\Http\Controllers\Api\Admin\UserBlockController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\GenreController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\PlaylistTrackController;
use App\Http\Controllers\Api\TrackCommentController;
use App\Http\Controllers\Api\TrackController;
use App\Http\Controllers\Api\TrackStatsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserFollowController;
use App\Http\Controllers\Api\UserLikeController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Служебный маршрут (КП4)
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    try {
        DB::select('select 1');
        $database = 'ok';
        $status = 200;
    } catch (Throwable $e) {
        $database = 'error';
        $status = 503;
    }

    return response()->json([
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
        'database' => $database,
    ], $status);
});

/*
|--------------------------------------------------------------------------
| REST API, версия 1:  /api/v1/...   (имена маршрутов: v1.*)
|--------------------------------------------------------------------------
| Группы разделены по уровню доступа; используются Sanctum, роли и политики.
*/
Route::prefix('v1')->name('v1.')->group(function () {

    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('auth.login');
    Route::middleware(['auth:sanctum', 'not_blocked'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::get('me/tracks/stats', [TrackStatsController::class, 'index'])->middleware('role:artist,admin')->name('me.tracks.stats');
        Route::match(['put', 'patch'], 'me', [AuthController::class, 'updateProfile'])->name('me.update');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });

    // ---- Гость: каталог, карточка трека, комментарии, профиль, прослушивание ----
    Route::middleware([])->group(function () {
        Route::apiResource('tracks', TrackController::class)->only(['index', 'show']);
        Route::get('tracks/{track}/stream', [TrackController::class, 'stream'])->name('tracks.stream');
        Route::post('tracks/{track}/play', [TrackController::class, 'play'])->name('tracks.play');
        Route::get('tracks/{track}/comments', [TrackCommentController::class, 'index'])->name('tracks.comments.index');

        Route::apiResource('genres', GenreController::class)->only(['index', 'show']);
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    });

    // ---- Авторизованный пользователь ----
    Route::middleware(['auth:sanctum', 'not_blocked'])->group(function () {
        // Треки артиста
        Route::apiResource('tracks', TrackController::class)->only(['store', 'update', 'destroy'])->middleware('role:artist,admin');

        // Комментарии
        Route::post('tracks/{track}/comments', [TrackCommentController::class, 'store'])->name('tracks.comments.store');
        Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

        // Плейлисты и их состав (вложенный ресурс)
        Route::apiResource('playlists', PlaylistController::class);
        Route::scopeBindings()->prefix('playlists/{playlist}/tracks')->name('playlists.tracks.')->group(function () {
            Route::post('/', [PlaylistTrackController::class, 'store'])->name('store');
            Route::put('/', [PlaylistTrackController::class, 'reorder'])->name('reorder');
            Route::delete('{track}', [PlaylistTrackController::class, 'destroy'])->name('destroy');
        });

        // Лайки, подписки, лента (в ПР8 {user} заменится на /me)
        Route::get('users/{user}/likes', [UserLikeController::class, 'index'])->name('users.likes.index');
        Route::put('users/{user}/likes/{track}', [UserLikeController::class, 'store'])->name('users.likes.store');
        Route::delete('users/{user}/likes/{track}', [UserLikeController::class, 'destroy'])->name('users.likes.destroy');

        Route::put('users/{user}/following/{artist}', [UserFollowController::class, 'store'])->name('users.following.store');
        Route::delete('users/{user}/following/{artist}', [UserFollowController::class, 'destroy'])->name('users.following.destroy');
        Route::get('users/{user}/feed', [UserFollowController::class, 'feed'])->name('users.feed');
    });

    // ---- Администратор (ПР8: auth:sanctum + роль admin) ----
    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'not_blocked', 'role:admin'])->group(function () {
        Route::get('tracks', [TrackModerationController::class, 'index'])->name('tracks.index');
        Route::patch('tracks/{track}/status', [TrackModerationController::class, 'updateStatus'])->name('tracks.status');

        Route::put('users/{user}/block', [UserBlockController::class, 'store'])->name('users.block.store');
        Route::delete('users/{user}/block', [UserBlockController::class, 'destroy'])->name('users.block.destroy');
    });

    // ---- Администратор: справочник жанров ----
    Route::middleware(['auth:sanctum', 'not_blocked', 'role:admin'])->group(function () {
        Route::apiResource('genres', GenreController::class)->only(['store', 'update', 'destroy']);
    });
});
