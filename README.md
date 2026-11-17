<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Homeostat

Музыкальная веб-платформа Homeostat: артисты загружают треки, слушатели ищут и прослушивают их, ставят лайки, комментируют, собирают плейлисты и подписываются на артистов. Каждый новый трек проходит модерацию администратором.

Проект выполняется в рамках курсовой работы по дисциплине «Основы backend-разработки».

## Стек

- PHP 8.4.1+ для зафиксированных зависимостей, Laravel 13
- MySQL 8
- Laravel Sanctum (токены для API)
- Blade-шаблоны для интерфейса
- Composer, Git

## Требования

- PHP 8.4.1 или выше с расширениями: pdo_mysql, mbstring, openssl, fileinfo, curl (для тестов также pdo_sqlite)
- Composer 2
- MySQL 8
- Git

## Установка

```bash
git clone <ссылка на репозиторий> homeostat
cd homeostat
composer install
cp .env.example .env
```

Создайте пустую базу данных (например, `homeostat_app`) и укажите в `.env` параметры подключения: `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

```bash
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

> Команда `--seed` заработает после появления сидеров (практическая работа 6). До этого используйте `php artisan migrate`.

Приложение будет доступно по адресу http://localhost:8000.

## Проверка

Откройте http://localhost:8000/api/health. При работающем подключении к БД вернётся JSON с кодом 200:

```json
{
  "app": "Homeostat",
  "time": "2026-11-03T12:00:00+06:00",
  "database": "ok"
}
```

Если база недоступна, маршрут вернёт код 503 и `"database": "error"`.

## Структура

| Каталог | Назначение |
|---|---|
| `app/Models` | Модели Eloquent |
| `app/Http` | Контроллеры, Form Request, API Resources |
| `app/Services` | Бизнес-логика |
| `app/Enums` | Перечисления (роли, статусы) |
| `database/migrations` | Миграции структуры БД |
| `routes/api.php` | API-маршруты |
| `resources/views` | Blade-шаблоны |

## База данных (КП5)

Структура БД создаётся миграциями, тестовые данные — сидерами, одной командой:

```sh
php artisan migrate:fresh --seed
```

Таблицы: `users` (с полями `role`, `bio`, `avatar_path`, `is_blocked`), `genres`, `tracks`, `playlists`, `comments`, `likes`,
промежуточные `playlist_track` (плейлист ↔ трек, с позицией) и `follows` (пользователь ↔ пользователь).
Перечисления: `App\Enums\UserRole` (`listener`, `artist`, `admin`) и `App\Enums\TrackStatus` (`pending`, `published`, `rejected`).

Тестовые учётные записи (пароль у всех — `password`):

| Роль | Email |
|---|---|
| Администратор | `admin@homeostat.test` |
| Исполнитель | `artist@homeostat.test` |
| Слушатель | `listener@homeostat.test` |
| Заблокированный слушатель | `blocked@homeostat.test` |

Проверка связей в `php artisan tinker`:

```php
Track::with(['artist', 'genre'])->first();
Playlist::with('tracks')->first()->tracks->pluck('pivot.position', 'title');
User::where('email', 'listener@homeostat.test')->with(['likedTracks', 'following'])->first();
```

Валидация входных данных описана в `app/Http/Requests` (сообщения на русском), формат ответов API — в `app/Http/Resources`
(пароль и `remember_token` скрыты через `$hidden` модели `User` и не входят в `UserResource`).

## REST API (КП6)

API версии 1 доступен по префиксу `/api/v1`, маршруты описаны в `routes/api.php` (имена `v1.*`):

```sh
php artisan route:list --path=api
```

Для русских сообщений валидации установите `APP_LOCALE=ru` в `.env` (это значение указано в `.env.example`). При `APP_DEBUG=false` внутренние ошибки не раскрывают клиенту стек вызовов и SQL.

Код разделён по слоям: **Form Request** (`app/Http/Requests`) → **контроллер** (`app/Http/Controllers/Api`) → **сервис** (`app/Services`, вся бизнес-логика) → **модель Eloquent** → **API Resource** (`app/Http/Resources`).

Полный CRUD реализован для треков, жанров и плейлистов; дополнительно — модерация, прослушивание, комментарии, лайки, подписки и лента, блокировка пользователей.
Таблица эндпоинтов и покрытие требований ПР1 — в [docs/api-endpoints.md](docs/api-endpoints.md).

Запросы для Postman (коллекция и окружение с `{{base_url}}`) — в `docs/postman/`. Перед запуском: `php artisan migrate:fresh --seed && php artisan serve`.

Тесты (Feature-тесты API, 45 шт.):

```sh
php artisan test
```

## Аутентификация (КП8)

`POST /api/v1/auth/register` и `POST /api/v1/auth/login` возвращают токен Sanctum. Передавайте его как `Authorization: Bearer <token>`; `POST /api/v1/auth/logout` отзывает текущий токен, а `GET /api/v1/me` возвращает профиль. Срок действия задаётся `SANCTUM_TOKEN_EXPIRATION` в минутах (по умолчанию 10080). Для управления жанрами и модерации необходим администратор; треки изменяет владелец-артист или администратор.

В Postman запросы входа под каждой ролью сохраняют токен в переменную коллекции `token`. Переключайте пользователя перед проверкой соответствующих действий. Загрузка трека использует `multipart/form-data`: обязательные `title`, `genre_id`, `duration`, `audio` (mp3/wav/ogg, до 50 МБ), необязательные `description` и `cover` (jpg/png, до 5 МБ). Подберите лимиты `upload_max_filesize` и `post_max_size` в PHP и лимит веб-сервера, затем настройте `php artisan storage:link` для обложек.

## Документация

Проектная документация (ТЗ, ER-диаграмма, физическая модель БД) хранится в каталоге `docs/`.

## Бизнес-правила (КП9)

Описание транзакций, модерации, фильтрации и ограничений статистики: [docs/kp9-business-rules.md](docs/kp9-business-rules.md). Для проверки отката и файлов запускайте `php artisan test --filter=TrackWorkflowTest`.


## КП10: тестирование, логирование и сдача

- Матрица из 16 сценариев с ожидаемыми и фактическими результатами: [docs/kp10-test-cases.md](docs/kp10-test-cases.md).
- Пошаговый чек-лист ручной проверки коллекции Postman: [docs/kp10-postman-checklist.md](docs/kp10-postman-checklist.md).
- API-запросы журналируются middleware `LogApiActivity` без тела запроса, токенов, паролей и файлов. Бизнес-события создания и модерации трека записываются с идентификаторами и статусом.
- Автоматические Feature-тесты используют отдельную SQLite-конфигурацию PHPUnit, `RefreshDatabase` и фабрики. Перед сдачей необходимо выполнить `php artisan test`, `php artisan route:list --path=api` и ручной чек-лист Postman.
- В production следует использовать `APP_DEBUG=false`, не помещать `.env` и реальные токены в Git и ограничить доступ к логам.


## КП11–КП12: клиентская часть

Frontend реализован внутри Laravel на Blade + Vite + vanilla JavaScript: `resources/views/welcome.blade.php`, `resources/js/app.js`, `resources/css/app.css`. Он не использует Blade-данные как заглушки: каталог, авторизация, профиль, загрузка и модерация работают через API `/api/v1`.

### Запуск

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
npm install
npm run dev
php artisan serve --host=127.0.0.1 --port=8000
```

Откройте `http://127.0.0.1:8000`. Для внешнего Vite-клиента API-адрес задаётся через `VITE_API_URL`, а разрешённый origin — через `CLIENT_URL`. В Bearer-режиме `supports_credentials=false`; wildcard `*` не используется.

Реализованы: регистрация и вход, localStorage-токен, `GET /me`, logout, обработка 401/403/409/422/500 и сетевых ошибок, каталог с поиском/жанром/сортировкой/пагинацией, карточка трека, аудиоплеер, загрузка FormData, редактирование, удаление, статистика артиста и админская модерация.
