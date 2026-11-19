<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class AudioDurationService
{
    /** Inspect the actual uploaded file; never trust duration supplied by the client. */
    public function seconds(UploadedFile $file): int
    {
        $process = new Process([config('services.ffprobe.path', 'ffprobe'), '-v', 'error',
            '-protocol_whitelist', 'file,pipe', '-select_streams', 'a:0', '-show_entries', 'stream=index:format=duration', '-of', 'json', $file->getRealPath()]);
        $process->setTimeout(15);
        try { $process->run(); } catch (ProcessTimedOutException $e) {
            throw ValidationException::withMessages(['audio' => 'Не удалось определить длительность файла за отведённое время.']);
        } catch (\Symfony\Component\Process\Exception\ProcessStartFailedException $e) {
            throw new ServiceUnavailableHttpException(null, 'Анализатор аудио недоступен. Установите ffprobe на сервере.');
        }
        if ($process->getExitCode() === 127) throw new ServiceUnavailableHttpException(null, 'Анализатор аудио недоступен. Установите ffprobe на сервере.');
        $metadata = json_decode($process->getOutput(), true);
        $value = $metadata['format']['duration'] ?? null;
        if (! $process->isSuccessful() || empty($metadata['streams']) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
            throw ValidationException::withMessages(['audio' => 'Не удалось определить длительность аудиофайла. Проверьте формат и файл.']);
        }
        $seconds = (int) ceil((float) $value);
        if ($seconds > 36000) throw ValidationException::withMessages(['audio' => 'Длительность трека не должна превышать 10 часов.']);
        return $seconds;
    }
}
