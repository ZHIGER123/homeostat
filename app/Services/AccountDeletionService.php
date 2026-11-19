<?php

namespace App\Services;

use App\Jobs\DeleteAccountFiles;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountDeletionService
{
    public function __construct(private readonly BillingService $billing) {}

    public function delete(User $actor, User $target): void
    {
        $files = DB::transaction(function () use ($actor, $target) {
            // Lock administrators in a stable order before the target. This protects the last admin.
            $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            $owner = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $currentActor = User::whereKey($actor->id)->firstOrFail();
            abort_if($currentActor->is_blocked, 403, 'Пользователь заблокирован.');
            abort_unless($currentActor->id === $owner->id || $currentActor->isAdmin(), 403, 'Недостаточно прав.');
            abort_if($owner->isAdmin() && ! $admins->contains(fn ($admin) => $admin->id !== $owner->id && ! $admin->is_blocked), 409, 'Нельзя удалить последнего администратора.');

            // A remote failure aborts the DB deletion and leaves data and tokens intact.
            $this->billing->cancelForAccountDeletion($owner);
            $files = [];
            if ($owner->avatar_path) $files[] = ['public', $owner->avatar_path];
            foreach ($owner->tracks()->get(['audio_path', 'cover_path']) as $track) {
                if ($track->audio_path) $files[] = ['local', $track->audio_path];
                if ($track->cover_path) $files[] = ['public', $track->cover_path];
            }
            $owner->tokens()->delete(); // Polymorphic tokens have no FK cascade.
            DB::table('sessions')->where('user_id', $owner->id)->delete();
            DB::table('password_reset_tokens')->whereRaw('LOWER(email) = ?', [strtolower($owner->email)])->delete();
            EmailVerificationCode::whereRaw('LOWER(email) = ?', [strtolower($owner->email)])->delete();
            $owner->delete(); // FK cascades remove tracks, playlists, interactions and subscription.
            return $files;
        });

        // Files are removed only after the DB transaction commits; a DB rollback never loses audio.
        if ($files) {
            try {
                (new DeleteAccountFiles($files))->handle();
            } catch (\Throwable $exception) {
                report($exception);
                try {
                    DeleteAccountFiles::dispatch($files)->delay(now()->addMinute());
                } catch (\Throwable $queueException) {
                    report($queueException);
                    Log::critical('account.file_cleanup_failed', ['user_id' => $target->id, 'files' => $files]);
                }
            }
        }
        Log::info('account.deleted', ['user_id' => $target->id, 'actor_id' => $actor->id]);
    }
}
