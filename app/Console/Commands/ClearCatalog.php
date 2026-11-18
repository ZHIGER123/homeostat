<?php

namespace App\Console\Commands;

use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClearCatalog extends Command
{
    protected $signature = 'homeostat:clear-catalog {--all : Explicitly delete every track and its user-generated relations}';
    protected $description = 'Safely clear the local Homeostat catalog after an explicit confirmation flag';

    public function handle(): int
    {
        if (! $this->option('all')) {
            $this->error('Команда ничего не сделала. Для явной очистки добавьте --all.');
            return self::INVALID;
        }

        $count = Track::count();
        if (! $this->confirm("Будут удалены {$count} треков, их лайки, комментарии и связи с плейлистами. Продолжить?", false)) {
            $this->info('Очистка отменена.');
            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            Track::query()->select(['id', 'audio_path', 'cover_path'])->chunkById(100, function ($tracks): void {
                foreach ($tracks as $track) {
                    Storage::disk('local')->delete($track->audio_path);
                    if ($track->cover_path) Storage::disk('public')->delete($track->cover_path);
                }
            });
            DB::table('playlist_track')->delete();
            DB::table('comments')->delete();
            DB::table('likes')->delete();
            DB::table('tracks')->delete();
        });

        $this->info('Каталог очищен. Теперь добавляйте свои треки через Homeostat.');
        return self::SUCCESS;
    }
}
