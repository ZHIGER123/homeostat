<?php
namespace Tests\Support;
use Illuminate\Http\UploadedFile;
class TestAudio
{
    public static function wav(): UploadedFile
    {
        $data = str_repeat("\0", 16000); // one second, 8 kHz mono 16-bit PCM
        $wav = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data;
        return UploadedFile::fake()->createWithContent('tone.wav', $wav);
    }
}
