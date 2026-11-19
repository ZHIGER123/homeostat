<?php

return [
    'ffprobe' => ['path' => env('FFPROBE_PATH', 'ffprobe')],
    'postmark' => ['key' => env('POSTMARK_API_KEY')],
    'resend' => ['key' => env('RESEND_API_KEY')],
    'ses' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],
    'turnstile' => ['site_key' => env('TURNSTILE_SITE_KEY'), 'secret' => env('TURNSTILE_SECRET')],
    'stripe' => ['secret' => env('STRIPE_SECRET'), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET')],
    'email_otp' => [
        'ttl' => (int) env('EMAIL_OTP_TTL', 10),
        'resend_seconds' => (int) env('EMAIL_OTP_RESEND_SECONDS', 60),
        'max_attempts' => (int) env('EMAIL_OTP_MAX_ATTEMPTS', 5),
    ],
    'oauth' => [
        'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/google/callback'), 'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scope' => 'openid email profile'],
    ],
];
