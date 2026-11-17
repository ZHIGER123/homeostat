<?php

return [
    'postmark' => ['key' => env('POSTMARK_API_KEY')],
    'resend' => ['key' => env('RESEND_API_KEY')],
    'ses' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],
    'turnstile' => ['site_key' => env('TURNSTILE_SITE_KEY'), 'secret' => env('TURNSTILE_SECRET')],
    'stripe' => ['secret' => env('STRIPE_SECRET'), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET')],
    'oauth' => [
        'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/google/callback'), 'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token', 'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scope' => 'openid email profile'],
        'apple' => ['client_id' => env('APPLE_CLIENT_ID'), 'client_secret' => env('APPLE_CLIENT_SECRET'), 'redirect' => env('APPLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/apple/callback'), 'authorize' => 'https://appleid.apple.com/auth/authorize', 'token' => 'https://appleid.apple.com/auth/token', 'scope' => 'name email'],
        'github' => ['client_id' => env('GITHUB_CLIENT_ID'), 'client_secret' => env('GITHUB_CLIENT_SECRET'), 'redirect' => env('GITHUB_REDIRECT_URI', env('APP_URL').'/api/v1/auth/github/callback'), 'authorize' => 'https://github.com/login/oauth/authorize', 'token' => 'https://github.com/login/oauth/access_token', 'userinfo' => 'https://api.github.com/user', 'emails' => 'https://api.github.com/user/emails', 'scope' => 'read:user user:email'],
    ],
];
