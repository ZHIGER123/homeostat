<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DeleteAccountFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public array $files) {}

    public function handle(): void
    {
        foreach ($this->files as [$disk, $path]) {
            // Only application-owned relative paths may enter this cleanup job.
            $prefixes = $disk === 'local' ? ['tracks/'] : ['covers/', 'avatars/'];
            if (! is_string($path) || str_contains($path, '..') || str_contains($path, '\\')
                || ! collect($prefixes)->contains(fn ($prefix) => str_starts_with($path, $prefix))) continue;
            if (! Storage::disk($disk)->delete($path)) {
                throw new \RuntimeException('Не удалось удалить файл аккаунта.');
            }
        }
    }
}
