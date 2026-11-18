<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CaptchaService;
use Illuminate\Http\JsonResponse;

class CaptchaController extends Controller
{
    public function __construct(private readonly CaptchaService $captcha)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->captcha->challenge());
    }
}