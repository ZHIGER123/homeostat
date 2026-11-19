<?php

return [
    'free' => [
        'name' => 'Free',
        'label' => 'Для первых релизов',
        'monthly' => 0,
        'annual' => 0,
        'currency' => 'EUR',
        'upload_seconds' => 3600,
        'max_pending' => 10,
        'features' => ['1 час загрузок в месяц', 'Публичный каталог', 'Базовая статистика', 'Личные плейлисты'],
    ],
    'artist' => [
        'name' => 'Artist',
        'label' => 'Больше возможностей для автора',
        'monthly' => 299,
        'annual' => 3588,
        'currency' => 'EUR',
        'price_id_monthly' => env('STRIPE_ARTIST_MONTHLY_PRICE_ID'),
        'price_id_annual' => env('STRIPE_ARTIST_ANNUAL_PRICE_ID'),
        'upload_seconds' => 10800,
        'max_pending' => 10,
        'features' => ['3 часа загрузок в месяц', 'Продвинутая статистика', 'Приоритетная модерация', 'Продвижение релизов'],
    ],
    'artist_pro' => [
        'name' => 'Artist Pro',
        'label' => 'Без ограничений для профессионалов',
        'monthly' => 699,
        'annual' => 8388,
        'currency' => 'EUR',
        'price_id_monthly' => env('STRIPE_ARTIST_PRO_MONTHLY_PRICE_ID'),
        'price_id_annual' => env('STRIPE_ARTIST_PRO_ANNUAL_PRICE_ID'),
        'upload_seconds' => null,
        'max_pending' => null,
        'features' => ['Безлимитные загрузки', 'Полная статистика аудитории', 'Приоритетная модерация', 'Замена треков без потери статистики', 'Инструменты продвижения'],
    ],
];
