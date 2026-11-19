<?php

return [
    'free' => [
        'name' => 'Free',
        'label' => 'Для первых релизов',
        'monthly' => 0,
        'annual' => 0,
        'currency' => env('STRIPE_CURRENCY', 'USD'),
        'upload_seconds' => 10800,
        'max_pending' => 10,
        'features' => ['До 3 часов аудио суммарно', '1 закреплённый трек', 'Общая статистика', 'Личные плейлисты'],
    ],
    'artist' => [
        'name' => 'Artist',
        'label' => 'Больше возможностей для автора',
        'monthly' => 299,
        'annual' => 3588,
        'currency' => env('STRIPE_CURRENCY', 'USD'),
        'price_id_monthly' => env('STRIPE_ARTIST_MONTHLY_PRICE_ID'),
        'price_id_annual' => env('STRIPE_ARTIST_ANNUAL_PRICE_ID'),
        'upload_seconds' => 10800,
        'max_pending' => 10,
        'features' => ['До 3 часов аудио суммарно', '2 буста в месяц', '5 закреплённых треков', 'Отключение комментариев'],
    ],
    'artist_pro' => [
        'name' => 'Artist Pro',
        'label' => 'Без ограничений для профессионалов',
        'monthly' => 699,
        'annual' => 8388,
        'currency' => env('STRIPE_CURRENCY', 'USD'),
        'price_id_monthly' => env('STRIPE_ARTIST_PRO_MONTHLY_PRICE_ID'),
        'price_id_annual' => env('STRIPE_ARTIST_PRO_ANNUAL_PRICE_ID'),
        'upload_seconds' => null,
        'max_pending' => null,
        'features' => ['Безлимит по общему времени треков', 'Безлимитные бусты', '5 закреплённых треков', 'Отключение комментариев', 'Значок PRO'],
    ],
];
