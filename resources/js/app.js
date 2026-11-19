import { maxBirthDate, isOldEnough } from './age.js';
import '../css/admin-enhancements.css';
import { createLibraryViews } from './library.js';
import { createPlayer } from './player.js';
import { pricingView, billingView, choosePlan, billingAction, handleBillingQuery, syncBilling } from './billing.js';
const API_BASE = (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, '');
const TOKEN_KEY = 'homeostat_token';
const USER_KEY = 'homeostat_user';

const state = {
    token: localStorage.getItem(TOKEN_KEY),
    user: readUser(),
    genres: [],
    currentTrack: null,
    currentTrackLiked: false,
    currentArtistFollowing: false,
    list: { items: [], meta: {}, filters: { q: '', genre_id: '', sort: 'created_at', direction: 'desc', page: 1 } },
    captcha: null,
    plans: [],
    billing: null,
    pricingCycle: 'monthly',
};

const root = document.querySelector('#app-root');
const player = createPlayer({ url: apiUrl, loadTrack: async id => dataOf(await api(`/tracks/${id}`)), registerPlay, escapeHtml, notify });
function avatarMarkup(user, css = 'profile-avatar') {
    const url = user?.avatar_path?.startsWith('avatars/') ? `${apiUrl(`/users/${user.id}/avatar`)}?v=${encodeURIComponent(user.avatar_path)}` : mediaUrl(user?.avatar_path);
    return url ? `<img class="${css} avatar-image" src="${escapeHtml(url)}" alt="Аватар">` : `<span class="${css}">${escapeHtml((user?.name || 'G').slice(0, 1).toUpperCase())}</span>`;
}
function trackCoverUrl(track) {
    if (!track.cover_path) return '';
    return track.status === 'published' ? apiUrl(`/tracks/${track.id}/cover`) : mediaUrl(track.cover_path);
}
function readUser() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { return null; }
}

function dataOf(payload) { return payload?.data ?? payload; }
function itemsOf(payload) { return Array.isArray(payload?.data) ? payload.data : []; }
function escapeHtml(value = '') { return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
function proBadge(user) { return user?.plan === 'artist_pro' ? '<span class="pro-badge">PRO</span>' : ''; }
function roleName(role) { return ({ admin: 'Администратор', artist: 'Исполнитель', listener: 'Слушатель' })[role] || role || 'Гость'; }
function planName(plan) { return ({ free: 'Free', artist: 'Artist', artist_pro: 'Artist Pro' })[plan] || plan || 'Free'; }
function money(cents) { return cents ? `€${(Number(cents) / 100).toFixed(2)}` : 'Бесплатно'; }
function hours(seconds) { return seconds == null ? 'Без лимита' : `${(Number(seconds) / 3600).toFixed(seconds % 3600 ? 1 : 0)} ч всего`; }
function statusLabel(status) { return ({ pending: 'На модерации', published: 'Опубликован', rejected: 'Отклонён' })[status] || status || '—'; }
function formatDuration(seconds) { if (!seconds) return '—'; const min = Math.floor(seconds / 60); const sec = String(seconds % 60).padStart(2, '0'); return `${min}:${sec}`; }
function readError(error) { return error?.message || 'Неизвестная ошибка.'; }

function setAuth(payload) {
    const token = payload?.token;
    const user = payload?.user?.data || payload?.user;
    if (token) { state.token = token; localStorage.setItem(TOKEN_KEY, token); }
    if (user) { state.user = user; localStorage.setItem(USER_KEY, JSON.stringify(user)); }
}

function clearAuth() {
    state.token = null; state.user = null;
    localStorage.removeItem(TOKEN_KEY); localStorage.removeItem(USER_KEY);
}

function go(path) { window.location.hash = path.startsWith('#') ? path : `#${path}`; }
function currentRoute() {
    const raw = window.location.hash.replace(/^#/, '') || (state.token ? 'tracks' : 'login');
    const [path, query = ''] = raw.split('?');
    return { path, params: new URLSearchParams(query) };
}
function isActive(name) { const route = currentRoute().path; return route === name || route.startsWith(`${name}/`) ? 'is-active' : ''; }
function apiUrl(path) { return `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`; }
function mediaUrl(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    const base = new URL(API_BASE, window.location.origin).origin;
    return `${base}/storage/${String(path).replace(/^\//, '')}`;
}

class ApiError extends Error {
    constructor(message, status, errors = {}, retryAfter = 0) { super(message); this.status = status; this.errors = errors; this.retryAfter = retryAfter; }
}

async function api(path, options = {}) {
    const { body, auth = true, method = 'GET', headers = {}, ...rest } = options;
    const isForm = body instanceof FormData;
    const requestHeaders = { Accept: 'application/json', ...headers };
    if (!isForm && body !== undefined) requestHeaders['Content-Type'] = 'application/json';
    if (auth && state.token) requestHeaders.Authorization = `Bearer ${state.token}`;
    let response;
    try {
        response = await fetch(apiUrl(path), { method, headers: requestHeaders, body: body === undefined || isForm ? body : JSON.stringify(body), ...rest });
    } catch { throw new ApiError('Сервер недоступен. Проверьте, запущен ли Laravel.', 0); }
    let payload = {};
    try { payload = await response.json(); } catch { payload = {}; }
    if (response.status === 401 && auth) { clearAuth(); go('login'); throw new ApiError('Сессия истекла. Войдите снова.', 401, payload.errors || {}); }
    if (!response.ok) {
        const seconds = Number(response.headers.get('Retry-After') || payload.retry_after || 60);
        const retryAfter = response.status === 429 ? Math.max(1, Math.min(3600, Number.isFinite(seconds) ? seconds : 60)) : 0;
        const message = response.status === 429 ? `Слишком много запросов. Повторите через ${Math.ceil(retryAfter)} с.` : payload.message || `Ошибка сервера (${response.status}).`;
        throw new ApiError(message, response.status, payload.errors || {}, retryAfter);
    }
    return payload;
}

function notify(message, type = 'info') {
    const node = document.querySelector('#toast'); if (!node) return;
    node.className = `toast toast-${type} is-visible`; node.textContent = message;
    window.clearTimeout(notify.timer); notify.timer = window.setTimeout(() => node.classList.remove('is-visible'), 3600);
}
window.__homeostat = { state, escapeHtml, api, dataOf, go, ApiError, formatDuration, notify };
const { trackCard, uploadView, profileView, playlistListView, playlistDetailView, playlistSearchView, discoverPlaylistsView, tracksView, pagination, trackDetailView, commentView, statsView, trackCollectionView, adminView } = createLibraryViews({ state, escapeHtml, trackCoverUrl, formatDuration, formMessage, itemsOf, avatarMarkup, roleName, API_BASE, apiUrl, statusLabel });

function shell(content, title = 'Обзор') {
    const user = state.user; const isAdmin = user?.role === 'admin'; const canUpload = Boolean(user);
    return `<div class="app-shell">
        <aside class="sidebar"><a class="brand" href="#tracks" aria-label="Homeostat"><span class="brand-mark"><span></span><span></span><span></span><span></span></span><span>homeostat</span></a>
            <nav class="primary-nav" aria-label="Основная навигация">
                <a class="nav-link ${isActive('tracks')}" href="#tracks"><span>◉</span> Обзор</a>
                <a class="nav-link ${isActive('feed')}" href="#feed"><span>⌁</span> Моя лента</a>
                <a class="nav-link ${isActive('favorites')}" href="#favorites"><span>♡</span> Избранное</a>
                <a class="nav-link ${isActive('playlists')}" href="#playlists"><span>▤</span> Плейлисты</a>
                <a class="nav-link ${isActive('upload')} ${canUpload ? '' : 'is-hidden'}" href="#upload"><span>＋</span> Загрузить</a>
                <a class="nav-link ${isActive('stats')} ${canUpload ? '' : 'is-hidden'}" href="#stats"><span>⌁</span> Статистика</a>
                <a class="nav-link ${isActive('pricing')}" href="#pricing"><span>✦</span> Тарифы</a>
                <a class="nav-link ${isActive('billing')} ${user ? '' : 'is-hidden'}" href="#billing"><span>◒</span> Подписка</a>
                <a class="nav-link ${isActive('admin')} ${isAdmin ? '' : 'is-hidden'}" href="#admin"><span>◆</span> Администрирование</a>
            </nav>
            <div class="sidebar-bottom"><div class="mini-note"><span class="live-dot"></span><span>API online mode</span></div><button class="nav-link nav-button" data-action="logout"><span>↪</span> Выйти</button></div>
        </aside>
        <div class="main-column"><header class="topbar"><button class="mobile-menu" data-action="toggle-menu" aria-label="Открыть меню">☰</button><div class="breadcrumbs"><span>Homeostat</span><b>/</b><strong>${escapeHtml(title)}</strong></div><div class="top-actions"><a class="user-chip" href="#profile" title="Открыть профиль">${avatarMarkup(user, 'avatar')}<span class="user-chip-copy"><b>${escapeHtml(user?.name || 'Гость')} ${proBadge(user)}</b><small>${escapeHtml(roleName(user?.role))}</small></span></a></div></header><main class="content" id="screen">${content}</main></div>
    </div><div id="player-slot"></div><div id="toast" class="toast" role="status"></div>`;
}

function formMessage(message = '') { return `<p class="form-message" data-form-message>${escapeHtml(message)}</p>`; }
function fieldError(errors, field) { return errors?.[field]?.[0] ? `<small class="field-error">${escapeHtml(errors[field][0])}</small>` : ''; }

function birthDateMarkup() {
    return `<fieldset class="birth-date-card"><legend>Дата рождения <span class="age-badge">16+</span></legend><p>Музыка для вашей сцены. Укажите дату рождения — доступ открыт с 16 лет.</p><label for="birth-date">День, месяц и год<input id="birth-date" name="birth_date" type="date" autocomplete="bday" min="1900-01-01" max="${maxBirthDate()}" aria-describedby="birth-date-help birth-date-error" required></label><small id="birth-date-help">Дата не отображается в публичном профиле. Ввод даты — заявление о возрасте, а не проверка документа.</small><span id="birth-date-error" class="inline-error" data-error="birth_date" aria-live="polite"></span></fieldset>`;
}

function captchaMarkup() {
    const captcha = state.captcha || {};
    if (captcha.provider === 'turnstile') {
        return `<div class="captcha-box"><div class="captcha-heading"><span>Проверка безопасности</span><small>Cloudflare Turnstile</small></div><div class="turnstile-slot" data-sitekey="${escapeHtml(captcha.site_key || '')}"></div><input type="hidden" name="turnstile_token" value=""><small class="inline-error" data-error="captcha_answer"></small></div>`;
    }
    return `<div class="captcha-box"><div class="captcha-heading"><span>Проверка безопасности</span><button class="captcha-refresh" data-action="refresh-captcha" type="button" aria-label="Обновить CAPTCHA">↻</button></div><div class="captcha-question"><strong>${escapeHtml(captcha.question || 'Загрузка…')}</strong><input name="captcha_answer" inputmode="numeric" autocomplete="off" placeholder="Ответ" required><input type="hidden" name="captcha_id" value="${escapeHtml(captcha.id || '')}"></div><small>Небольшая проверка защищает регистрацию от автоматических ботов.</small><span class="inline-error" data-error="captcha_answer"></span></div>`;
}

function loginView(mode = 'login', error = '') {
    const register = mode === 'register';
    const fields = register
        ? `<label>Имя<input name="name" autocomplete="name" placeholder="Как к тебе обращаться" required><span class="inline-error" data-error="name"></span></label><label>Email<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required><span class="inline-error" data-error="email"></span></label>${birthDateMarkup()}<label>Пароль<input name="password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required><span class="inline-error" data-error="password"></span></label><label>Повторите пароль<input name="password_confirmation" type="password" autocomplete="new-password" required><span class="inline-error" data-error="password_confirmation"></span></label>${captchaMarkup()}<dialog class="email-verification-dialog" data-email-dialog aria-labelledby="email-verification-title" aria-describedby="email-verification-description"><div class="email-verification-content"><button type="button" class="email-dialog-close" data-close-email-dialog aria-label="Закрыть окно">×</button><span class="email-dialog-icon" aria-hidden="true">✉</span><span class="eyebrow">ПОСЛЕДНИЙ ШАГ</span><h2 id="email-verification-title">Подтвердите email</h2><p id="email-verification-description">Мы отправили код на <strong data-verification-email></strong></p><p class="email-code-status" data-email-code-status aria-live="polite"></p><label data-email-code-field for="email-verification-code">Код из письма<input id="email-verification-code" name="code" type="text" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" aria-describedby="email-code-error"><span id="email-code-error" class="inline-error" data-error="code" aria-live="polite"></span></label><p class="form-message" data-verification-message role="alert"></p><button class="button button-primary button-wide" type="button" data-confirm-email>Подтвердить и создать аккаунт</button><button class="button button-light button-wide" type="button" data-action="request-email-code">Отправить код повторно</button><button class="email-dialog-edit" type="button" data-edit-registration>Изменить email или данные</button><small class="email-dialog-note">Письма нет? Проверьте папку «Спам». Никому не сообщайте код.</small></div></dialog>`
        : `<label>Email<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required><span class="inline-error" data-error="email"></span></label><label>Пароль<input name="password" type="password" autocomplete="current-password" placeholder="Минимум 8 символов" required><span class="inline-error" data-error="password"></span></label>`;
    return `<div class="auth-layout"><section class="auth-art"><div class="orb orb-one"></div><div class="orb orb-two"></div><span class="eyebrow">SOUND • STATE • HOME</span><h1>Найди звук,<br><em>который остаётся.</em></h1><p>Homeostat собирает музыку вокруг твоего состояния — от первой ноты до собственного плейлиста.</p><div class="wave-hero" aria-hidden="true">${Array.from({ length: 32 }, (_, i) => `<i style="--h:${20 + ((i * 17) % 76)}%"></i>`).join('')}</div><div class="auth-art-note">Создавай свою сцену. Слушай своё.</div></section><section class="auth-card-wrap"><div class="auth-card"><div class="auth-card-head"><span class="eyebrow">${register ? 'NEW ACCOUNT' : 'WELCOME BACK'}</span><h2>${register ? 'Создай свой профиль' : 'С возвращением'}</h2><p>${register ? 'Код подтверждения придёт на указанную почту.' : 'Войдите, чтобы продолжить слушать.'}</p></div><form id="auth-form" data-mode="${register ? 'register' : 'login'}" novalidate>${fields}${formMessage(error)}<button class="button button-primary button-wide" type="submit"><span>${register ? 'Зарегистрироваться' : 'Войти в Homeostat'}</span><b>→</b></button></form><div class="auth-switch">${register ? 'Уже есть аккаунт?' : 'Впервые здесь?'} <a href="#${register ? 'login' : 'register'}">${register ? 'Войти' : 'Создать профиль'}</a></div><p class="auth-legal">Homeostat — платформа 16+. Дата рождения запрашивается только при регистрации.</p></div></section></div>`;
}







function loadingView(label = 'Получаем данные…') { return `<div class="loading-state"><span class="spinner"></span>${escapeHtml(label)}</div>`; }

async function loadCaptcha() {
    state.captcha = await api('/auth/captcha', { auth: false });
}

function mountTurnstile() {
    const slot = document.querySelector('.turnstile-slot');
    if (!slot || !state.captcha || state.captcha.provider !== 'turnstile') return;
    const renderWidget = () => {
        if (window.turnstile && !slot.dataset.mounted) {
            window.turnstile.render(slot, { sitekey: slot.dataset.sitekey, callback: token => { const input = document.querySelector('[name="turnstile_token"]'); if (input) input.value = token; } });
            slot.dataset.mounted = 'true';
        }
    };
    if (!document.querySelector('script[data-turnstile]')) {
        const script = document.createElement('script'); script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js'; script.async = true; script.defer = true; script.dataset.turnstile = 'true'; script.onload = renderWidget; document.head.appendChild(script);
    } else renderWidget();
}

let renderVersion = 0;
async function render() {
    document.querySelectorAll('[data-preview-audio]').forEach(audio => { audio.pause(); audio.removeAttribute('src'); audio.load(); });
    moderationPreviewUrls?.forEach(url => URL.revokeObjectURL(url)); moderationPreviewUrls?.clear();
    previewRequests.forEach(controller => controller.abort()); previewRequests.clear();
    document.querySelectorAll('.cover-dialog').forEach(dialog => dialog.close());
    const version = ++renderVersion;
    const route = currentRoute();
    if (!state.token && !['login', 'register', 'tracks', 'pricing'].includes(route.path) && !/^tracks\/\d+$/.test(route.path) && !/^users\/\d+$/.test(route.path)) { go('login'); return; }
    if (state.token && ['login', 'register'].includes(route.path)) { go('tracks'); return; }
    if (['login', 'register'].includes(route.path)) { if (route.path === 'register') { try { await loadCaptcha(); } catch (error) { state.captcha = { provider: 'math', question: 'Обновите страницу', id: '' }; } } if (version !== renderVersion) return; root.innerHTML = loginView(route.path); bindEvents(); mountTurnstile(); return; }
    const screen = document.querySelector('#screen'); if (screen) screen.innerHTML = loadingView();
    let content = ''; let title = 'Обзор';
    try {
        if (route.path === 'tracks') { await ensureGenres(); await loadTracks(route.params); content = tracksView(); }
        else if (route.path === 'welcome') { requireAuth(); content = `<div class="page-intro"><span class="eyebrow">WELCOME TO HOMEOSTAT</span><h1>Твоя музыка начинается здесь</h1><p>Выбирайте бесплатный план или сравните платные тарифы. Платная подписка необязательна.</p></div><div class="form-panel"><h2>Станьте исполнителем</h2><p>Оформите профиль и загрузите первый трек — аккаунт исполнителя появится автоматически. Трек поступит на проверку перед публикацией.</p><div class="action-row"><a class="button button-primary" href="#upload">Загрузить трек</a><a class="button button-light" href="#profile">Оформить профиль</a><a class="button button-light" href="#pricing">Посмотреть тарифы</a><a class="button button-light" href="#tracks">Продолжить бесплатно</a></div></div>`; title = 'Добро пожаловать'; }
        else if (/^users\/\d+$/.test(route.path)) { const user = dataOf(await api(`/users/${route.path.split('/')[1]}`, {auth:false})); const spotlight = itemsOf(await api(`/users/${user.id}/spotlight`, {auth:false})); const links = Object.entries(user.social_links || {}).filter(([,url]) => typeof url === 'string' && /^https?:\/\//i.test(url)).map(([name,url]) => `<a class="button button-light" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(({website:'Сайт',youtube:'YouTube',telegram:'Telegram'})[name] || name)} ↗</a>`).join(''); content = `<div class="profile-hero">${avatarMarkup(user)}<div><span class="eyebrow">ARTIST PROFILE</span><h1>${escapeHtml(user.name)} ${proBadge(user)}</h1><p>${escapeHtml(roleName(user.role))}</p></div></div><section class="form-panel"><h2>О себе</h2><p>${escapeHtml(user.bio || 'Пользователь пока не заполнил описание.')}</p><div class="action-row">${links}</div></section><section class="library-section"><h2>Закреплённые треки</h2><div class="cards-grid">${spotlight.length ? spotlight.map(trackCard).join('') : '<p class="muted-copy">Пока нет закреплённых треков.</p>'}</div></section>`; title = 'Профиль'; }
        else if (route.path.startsWith('tracks/')) { const id = route.path.split('/')[1]; // Send the optional bearer token so the API can authorize the owner/admin
            // to view a pending track immediately after upload. Guests still get
            // the same server-side 404 for unpublished tracks.
            const result = await api(`/tracks/${id}`); state.currentTrack = dataOf(result); let comments = []; state.commentsUnavailable = false; try { comments = itemsOf(await api(`/tracks/${id}/comments`)); } catch { state.commentsUnavailable = true; } state.currentTrackLiked = await checkLiked(id); state.currentArtistFollowing = false; if (state.user && state.currentTrack.artist?.id && state.user.id !== state.currentTrack.artist.id) { try { const status = await api(`/users/${state.user.id}/following/${state.currentTrack.artist.id}`); state.currentArtistFollowing = Boolean(status.following); } catch { state.currentArtistFollowing = null; } } content = trackDetailView(state.currentTrack, comments); title = 'Трек'; }
        else if (route.path === 'feed') { requireAuth(); const result = await api(`/users/${state.user.id}/feed`); content = trackCollectionView(itemsOf(result), 'Моя лента', 'Новые публикации артистов, на которых вы подписаны.'); title = 'Моя лента'; }
        else if (route.path === 'favorites') { requireAuth(); const result = await api(`/users/${state.user.id}/likes`); content = trackCollectionView(itemsOf(result), 'Избранное', 'Треки, которые вы сохранили лайком.'); title = 'Избранное'; }
        else if (route.path === 'playlists') { requireAuth(); content = playlistListView(itemsOf(await api('/playlists'))); title = 'Плейлисты'; }
        else if (route.path === 'playlists/discover') { requireAuth(); const params = new URLSearchParams(route.params); const result = await api(`/playlists/discover?${params}`); content = discoverPlaylistsView(result, params); title = 'Плейлисты сообщества'; }
        else if (/^playlists\/\d+\/search$/.test(route.path)) { requireAuth(); const id = route.path.split('/')[1]; const playlist = dataOf(await api(`/playlists/${id}`)); if (playlist.owner?.id !== state.user.id && state.user.role !== 'admin') throw new ApiError('Недостаточно прав.', 403); const params = new URLSearchParams(route.params); const result = await api(`/tracks?${params}`); content = playlistSearchView(playlist, result, params); title = 'Поиск треков'; }
        else if (route.path.startsWith('playlists/')) { requireAuth(); const result = await api(`/playlists/${route.path.split('/')[1]}`); const liked = await api(`/playlists/${route.path.split('/')[1]}/like`); state.likedPlaylistIds = liked.liked ? [dataOf(result).id] : []; content = playlistDetailView(dataOf(result)); title = 'Плейлист'; }
        else if (route.path === 'upload') { requireAuth(); await ensureGenres(); try { state.billing = dataOf(await api('/billing/me')); } catch { state.billing = null; } content = uploadView(); title = 'Загрузить'; }
        else if (route.path === 'upload-success') { requireAuth(); content = `<div class="empty-state"><span class="empty-icon">✓</span><h1>Трек отправлен на модерацию</h1><p>После проверки он появится в каталоге. Повторная загрузка не нужна.</p><p>Ваш ID: <b>#${escapeHtml(state.user.id)}</b></p><div class="action-row"><a class="button button-primary" href="#tracks">В каталог</a><a class="button button-light" href="#upload">Загрузить ещё</a></div></div>`; title = 'Загрузка завершена'; }
        else if (route.path.startsWith('edit/')) { requireAuth(); await ensureGenres(); const result = await api(`/tracks/${route.path.split('/')[1]}`); content = uploadView(dataOf(result)); title = 'Редактировать'; }
        else if (route.path === 'admin') { requireRole('admin'); await ensureGenres(); const result = await api(`/admin/tracks?status=pending&page=${Math.max(1, Number(route.params.get('tracks_page')) || 1)}`); state.adminUsers = await api(`/admin/users?${new URLSearchParams({q:route.params.get('q') || '', role:route.params.get('role') || '', blocked:route.params.get('blocked') || '', page:route.params.get('users_page') || '1'})}`); content = adminView(itemsOf(result), result.meta || {}, route.params); title = 'Администрирование'; }
        else if (route.path === 'stats') { requireAuth(); const filters = { from: route.params.get('from') || '', to: route.params.get('to') || '' }; const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)); const result = await api(`/me/tracks/stats${query.toString() ? `?${query}` : ''}`); content = statsView(dataOf(result), filters); title = 'Статистика'; }
        else if (route.path === 'pricing') { if (!state.plans.length) state.plans = itemsOf(await api('/billing/plans', { auth: false })); if (state.token) state.billing = dataOf(await api('/billing/me')); content = pricingView(state.plans); title = 'Тарифы'; }
        else if (route.path === 'billing') { requireAuth(); const result = await api('/billing/me'); state.billing = dataOf(result); content = billingView(state.billing); title = 'Подписка'; }
        else if (route.path === 'profile') { requireAuth(); const result = await api('/me'); state.user = dataOf(result); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); const page = key => Math.max(1, Number.parseInt(route.params.get(key), 10) || 1); const [likes, following, lists, spotlight] = await Promise.all([api(`/users/${state.user.id}/likes?page=${page('tracks_page')}`), api(`/me/following?page=${page('artists_page')}`), api(`/me/liked-playlists?page=${page('playlists_page')}`), api(`/users/${state.user.id}/spotlight`, {auth:false})]); content = profileView(likes, following, lists, route.params, itemsOf(spotlight)); title = 'Профиль'; }
        else { go(state.token ? 'tracks' : 'login'); return; }
        if (version !== renderVersion) return;
        root.innerHTML = shell(content, title); bindEvents(); if (route.path === 'admin') mountModerationCovers(version);
    } catch (error) {
        if (version !== renderVersion || error.status === 401) return;
        const message = error.status === 403 ? 'Эта часть Homeostat доступна другой роли пользователя.' : readError(error);
        root.innerHTML = shell(`<div class="error-state"><span>${error.status || '!'}</span><h1>${error.status === 403 ? 'Доступ ограничен' : 'Не удалось загрузить данные'}</h1><p>${escapeHtml(message)}</p><button class="button button-primary" data-action="retry">Повторить</button></div>`, error.status === 403 ? 'Нет доступа' : 'Ошибка'); bindEvents();
    }
}

window.__homeostat.render = render;

function requireAuth() { if (!state.token || !state.user) throw new ApiError('Войдите, чтобы открыть этот раздел.', 401); }
function requireRole(...roles) { requireAuth(); if (!roles.includes(state.user.role)) throw new ApiError('Недостаточно прав.', 403); }
async function ensureGenres() { if (!state.genres.length) state.genres = itemsOf(await api('/genres', { auth: false })); }
async function loadTracks(params = new URLSearchParams()) {
    const filters = state.list.filters; ['q', 'genre_id', 'sort', 'direction'].forEach(key => { if (params.has(key)) filters[key] = params.get(key); }); filters.page = Number(params.get('page') || filters.page || 1);
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined)); query.set('per_page', '20');
    const result = await api(`/tracks?${query.toString()}`, { auth: false }); state.list.items = itemsOf(result); state.list.meta = result.meta || {};
}
async function checkLiked(trackId) {
    if (!state.token || !state.user) return false;
    try { return Boolean((await api(`/users/${state.user.id}/likes/${trackId}`)).liked); } catch { return null; }
}
function applyErrors(form, error) { Object.entries(error.errors || {}).forEach(([field, messages]) => { const node = form.querySelector(`[data-error="${field}"]`); if (node) node.textContent = messages[0] || ''; }); const message = form.querySelector('[data-email-dialog][open] [data-verification-message]') || form.querySelector('[data-form-message]'); if (message) { message.textContent = readError(error); message.classList.add('is-error'); } }
function validate(form, fields) { let valid = true; fields.forEach(field => { const input = form.elements[field]; const node = form.querySelector(`[data-error="${field}"]`); if (!input || !node) return; if (!input.value || (input.type === 'file' && !input.files.length)) { node.textContent = 'Заполните поле.'; valid = false; } else node.textContent = ''; }); return valid; }

function bindEvents() {
    player.mount();
    bindAdminUsers();
    const verificationForm = document.querySelector('#auth-form[data-mode="register"]');
    if (verificationForm) {
        const dialog = verificationForm.querySelector('[data-email-dialog]');
        verificationForm.querySelector('[data-close-email-dialog]').addEventListener('click', () => { if (verificationForm.dataset.busy !== 'true') dialog.close(); });
        dialog.addEventListener('cancel', event => { if (verificationForm.dataset.busy === 'true') event.preventDefault(); });
        verificationForm.querySelector('[data-confirm-email]').addEventListener('click', () => verificationForm.requestSubmit());
        verificationForm.elements.code.addEventListener('input', () => { verificationForm.elements.code.value = verificationForm.elements.code.value.replace(/[^0-9]/g, '').slice(0, 6); });
        verificationForm.querySelector('[data-edit-registration]').addEventListener('click', () => {
            if (verificationForm.dataset.busy === 'true') return;
            dialog.close(); delete verificationForm.dataset.codeRequested; delete verificationForm.dataset.codeEmail; delete verificationForm.dataset.resendToken;
            verificationForm.querySelectorAll('[readonly]').forEach(input => input.readOnly = false);
            verificationForm.elements.code.value = ''; clearRegistrationErrors(verificationForm);
            verificationForm.querySelector('[type=submit] span').textContent = 'Зарегистрироваться';
            verificationForm.elements.email.focus();
            refreshCaptchaInForm(verificationForm).catch(error => applyErrors(verificationForm,error));
        });
    }
    document.querySelectorAll('[data-cover-dialog]').forEach(btn => btn.addEventListener('click', () => { const img = btn.querySelector('img'); if (!img?.getAttribute('src')) return; const dialog = document.createElement('dialog'); dialog.className = 'cover-dialog'; const close = document.createElement('button'); close.className = 'button button-light'; close.textContent = 'Закрыть'; close.addEventListener('click', () => dialog.close()); const full = img.cloneNode(); full.removeAttribute('data-moderation-cover'); full.alt = 'Обложка в полном размере'; dialog.append(close, full); document.body.append(dialog); dialog.addEventListener('close', () => dialog.remove(), {once:true}); dialog.showModal(); }));
    document.querySelectorAll('[data-preview-seek]').forEach(btn => btn.addEventListener('click', () => { const audio = document.querySelector(`[data-preview-audio="${btn.dataset.id}"]`); if (audio && Number.isFinite(audio.duration)) audio.currentTime = Math.max(0, Math.min(audio.duration, audio.currentTime + Number(btn.dataset.previewSeek))); }));
    document.querySelectorAll('[data-preview-audio]').forEach(audio => { audio.addEventListener('play', () => { document.querySelectorAll('[data-preview-audio]').forEach(other => { if (other !== audio) other.pause(); }); player.pause?.(); }); });
    document.querySelectorAll('[data-action="toggle-menu"]').forEach(btn => btn.addEventListener('click', () => document.querySelector('.sidebar')?.classList.toggle('is-open')));
    document.querySelectorAll('[data-action="refresh-captcha"]').forEach(btn => btn.addEventListener('click', async () => { try { await refreshCaptchaInForm(btn.closest('form')); } catch (error) { notify(readError(error), 'error'); } }));
    document.querySelectorAll('[data-action="request-email-code"]').forEach(btn => btn.addEventListener('click', () => requestEmailCode(btn.closest('form'))));
    document.querySelectorAll('[data-action="admin-preview"]').forEach(btn => btn.addEventListener('click', () => previewModerationTrack(btn)));
    document.querySelectorAll('[data-action="logout"]').forEach(btn => btn.addEventListener('click', async () => {
        if (btn.disabled) return;
        btn.disabled = true;
        try { await api('/logout', { method: 'POST' }); clearAuth(); go('login'); notify('Вы вышли из аккаунта', 'success'); }
        catch (error) { notify(readError(error), 'error'); }
        finally { if (btn.isConnected) btn.disabled = false; }
    }));
    document.querySelectorAll('[data-action="retry"]').forEach(btn => btn.addEventListener('click', render));
    document.querySelectorAll('[data-cycle]').forEach(btn => btn.addEventListener('click', () => { state.pricingCycle = btn.dataset.cycle; render(); }));
    document.querySelectorAll('[data-action="choose-plan"]').forEach(btn => btn.addEventListener('click', () => choosePlan(btn.dataset.plan)));
    document.querySelectorAll('[data-action="billing-sync"]').forEach(btn => btn.addEventListener('click', syncBilling));
    document.querySelectorAll('[data-action="billing-portal"]').forEach(btn => btn.addEventListener('click', () => billingAction('portal')));
    document.querySelectorAll('[data-action="billing-cancel"]').forEach(btn => btn.addEventListener('click', () => billingAction('cancel')));
    document.querySelectorAll('[data-action="billing-resume"]').forEach(btn => btn.addEventListener('click', () => billingAction('resume')));
    document.querySelectorAll('[data-action="page"]').forEach(btn => btn.addEventListener('click', () => { if (!btn.disabled) go(`tracks?page=${Number(btn.dataset.page)}`); }));
    document.querySelectorAll('[data-action="artist-feature"]').forEach(btn => btn.addEventListener('click', async () => { btn.disabled = true; try { await api(`/tracks/${btn.dataset.id}/${btn.dataset.kind}`, {method:'PUT', body:{enabled:btn.dataset.enabled === 'true'}}); notify('Настройки трека сохранены', 'success'); await render(); } catch (error) { btn.disabled = false; notify(readError(error), 'error'); } }));
    document.querySelectorAll('[data-action="play"]').forEach(btn => btn.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); player.play(btn.dataset.id).catch(() => notify('Не удалось воспроизвести трек.', 'error')); }));
    document.querySelectorAll('[data-action="like"]').forEach(btn => btn.addEventListener('click', () => toggleLike(btn)));
    document.querySelectorAll('[data-action="follow"]').forEach(btn => btn.addEventListener('click', () => toggleFollow(btn)));
    document.querySelectorAll('[data-action="delete-track"]').forEach(btn => btn.addEventListener('click', () => deleteTrack(btn.dataset.id)));
    document.querySelectorAll('[data-action="delete-comment"]').forEach(btn => btn.addEventListener('click', () => deleteComment(btn.dataset.id)));
    document.querySelectorAll('[data-action="moderate"]').forEach(btn => btn.addEventListener('click', () => moderateTrack(btn.dataset.id, btn.dataset.status)));
    document.querySelectorAll('[data-action="delete-playlist"]').forEach(btn => btn.addEventListener('click', () => deletePlaylist(btn.dataset.id)));
    document.querySelectorAll('[data-action="playlist-remove"]').forEach(btn => btn.addEventListener('click', () => playlistRemove(btn.dataset.playlist, btn.dataset.track)));
    document.querySelectorAll('[data-action="playlist-move"]').forEach(btn => btn.addEventListener('click', () => playlistMove(btn.dataset.playlist, Number(btn.dataset.index), btn.dataset.direction)));
    document.querySelectorAll('[data-action="delete-genre"]').forEach(btn => btn.addEventListener('click', () => deleteGenre(btn.dataset.id)));
    document.querySelectorAll('[data-action="edit-genre"]').forEach(btn => btn.addEventListener('click', () => editGenre(btn.dataset.id, btn.dataset.name)));
    document.querySelectorAll('[data-action="unblock-user"]').forEach(btn => btn.addEventListener('click', () => blockUser(false)));
    const authForm = document.querySelector('#auth-form'); if (authForm) authForm.addEventListener('submit', submitAuth);
    const filterForm = document.querySelector('#filter-form'); if (filterForm) filterForm.addEventListener('submit', submitFilters);
    const trackForm = document.querySelector('#track-form'); if (trackForm) { trackForm.addEventListener('submit', submitTrack); trackForm.elements.audio?.addEventListener('change', () => inspectAudio(trackForm)); }
    const searchForm = document.querySelector('#playlist-search-form'); if (searchForm) searchForm.addEventListener('submit', e => { e.preventDefault(); go(`playlists/${searchForm.dataset.id}/search?${new URLSearchParams({q:searchForm.elements.q.value.trim(),page:1})}`); });
    const discoverForm = document.querySelector('#playlist-discover-form'); if (discoverForm) discoverForm.addEventListener('submit', e => { e.preventDefault(); go(`playlists/discover?${new URLSearchParams({q:discoverForm.elements.q.value.trim(),page:1})}`); });
    document.querySelectorAll('[data-action="add-search-track"]').forEach(btn => btn.addEventListener('click', async () => { try { await api(`/playlists/${btn.dataset.playlist}/tracks`, {method:'POST', body:{track_id:Number(btn.dataset.track)}}); btn.disabled = true; btn.textContent = '✓ Уже в плейлисте'; notify('Трек добавлен', 'success'); } catch (e) { notify(readError(e), 'error'); } }));
    document.querySelectorAll('[data-action="like-playlist"]').forEach(btn => btn.addEventListener('click', async () => { try { const liked = btn.dataset.liked === 'true'; await api(`/playlists/${btn.dataset.id}/like`, {method: liked ? 'DELETE' : 'PUT'}); btn.dataset.liked = String(!liked); btn.textContent = liked ? '♡ В избранное' : '♥ Убрать из избранного'; } catch (e) { notify(readError(e), 'error'); } }));
    const commentForm = document.querySelector('#comment-form'); if (commentForm) commentForm.addEventListener('submit', submitComment);
    const profileForm = document.querySelector('#profile-form'); if (profileForm) profileForm.addEventListener('submit', submitProfile);
    const playlistCreate = document.querySelector('#playlist-create-form'); if (playlistCreate) playlistCreate.addEventListener('submit', createPlaylist);
    const playlistEdit = document.querySelector('#playlist-edit-form'); if (playlistEdit) playlistEdit.addEventListener('submit', editPlaylist);
    const playlistAdd = document.querySelector('#playlist-add-form'); if (playlistAdd) playlistAdd.addEventListener('submit', addPlaylistTrack);
    const statsFilter = document.querySelector('#stats-filter'); if (statsFilter) statsFilter.addEventListener('submit', submitStatsFilter);
    const genreForm = document.querySelector('#genre-form'); if (genreForm) genreForm.addEventListener('submit', createGenre);
    const blockForm = document.querySelector('#block-form'); if (blockForm) blockForm.addEventListener('submit', (event) => { event.preventDefault(); blockUser(true); });
}

function updateLoginButton(form) {
    window.clearTimeout(form.loginTimer);
    if (!form.isConnected) return;
    const remaining = Math.max(0, Math.ceil((Number(form.dataset.retryAt || 0) - Date.now()) / 1000));
    const button = form.querySelector('[type="submit"]');
    button.disabled = form.dataset.busy === 'true' || remaining > 0;
    button.querySelector('span').textContent = remaining ? `Повторить через ${remaining} с` : form.dataset.busy === 'true' ? 'Входим…' : 'Войти в Homeostat';
    if (remaining) form.loginTimer = window.setTimeout(() => updateLoginButton(form), 250);
}

async function submitAuth(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.mode === 'register') return submitEmailRegistration(event);
    if (form.dataset.busy === 'true' || Date.now() < Number(form.dataset.retryAt || 0)) return;
    if (!validate(form, ['email', 'password'])) return;
    form.querySelectorAll('[data-form-message], [data-error]').forEach(node => { node.textContent = ''; });
    form.dataset.busy = 'true';
    updateLoginButton(form);
    try {
        const body = Object.fromEntries(new FormData(form).entries());
        body.email = body.email.trim().toLowerCase();
        const result = await api('/login', { method: 'POST', auth: false, body });
        setAuth(result); notify('Добро пожаловать', 'success'); go('tracks');
    } catch (error) {
        if (error.status === 429) form.dataset.retryAt = String(Date.now() + (error.retryAfter || 60) * 1000);
        applyErrors(form, error);
    } finally {
        form.dataset.busy = 'false';
        updateLoginButton(form);
    }
}

function clearRegistrationErrors(form) {
    form.querySelectorAll('[data-error], [data-form-message], [data-verification-message]').forEach(node => { node.textContent = ''; node.classList.remove('is-error'); });
}
function openEmailVerification(form) {
    const dialog = form.querySelector('[data-email-dialog]');
    if (!dialog.open) dialog.showModal();
    form.elements.code.focus();
}
function setRegistrationBusy(form, busy) {
    form.dataset.busy = String(busy);
    form.querySelectorAll('button').forEach(button => button.disabled = busy);
    if (!busy) updateResendButton(form);
}
function updateResendButton(form) {
    const button = form.querySelector('[data-action="request-email-code"]');
    if (!button?.isConnected || form.dataset.codeRequested !== 'true') return;
    const remaining = Math.max(0, Math.ceil((Number(form.dataset.resendAt || 0) - Date.now()) / 1000));
    button.disabled = remaining > 0 || form.dataset.busy === 'true';
    button.textContent = remaining ? `Отправить повторно через ${remaining} с` : 'Отправить код повторно';
    window.clearTimeout(form.resendTimer);
    if (remaining) form.resendTimer = window.setTimeout(() => updateResendButton(form), 1000);
}
async function requestEmailCode(form) {
    if (form.dataset.busy === 'true') return;
    if (Date.now() < Number(form.dataset.resendAt || 0)) {
        applyErrors(form, new ApiError('Перед повторной отправкой подождите минуту.', 429)); return;
    }
    clearRegistrationErrors(form);
    const hasResendToken = form.dataset.codeRequested === 'true' && Boolean(form.dataset.resendToken);
    const fields = ['name', 'email', 'birth_date', 'password', 'password_confirmation', ...(hasResendToken ? [] : state.captcha?.provider === 'turnstile' ? ['turnstile_token'] : ['captcha_answer'])];
    if (!validate(form, fields)) return;
    if (!form.elements.email.validity.valid) { applyErrors(form, new ApiError('Укажите корректный email.', 422, {email:['Проверьте адрес электронной почты.']})); return; }
    if (!form.elements.birth_date.validity.valid || !isOldEnough(form.elements.birth_date.value)) { applyErrors(form, new ApiError('Homeostat доступен с 16 лет.', 422, {birth_date:['Укажите корректную дату рождения. Доступ открыт с 16 лет.']})); return; }
    if (form.elements.password.value.length < 8 || form.elements.password.value !== form.elements.password_confirmation.value) { applyErrors(form, new ApiError('Пароли должны совпадать и содержать минимум 8 символов.', 422, {password_confirmation:['Проверьте повтор пароля.']})); return; }
    const button = form.querySelector('[type=submit]');
    const confirm = form.querySelector('[data-confirm-email]');
    const wasRequested = form.dataset.codeRequested === 'true';
    setRegistrationBusy(form, true); button.querySelector('span').textContent = 'Отправляем код…';
    try {
        const result = await api('/auth/email/request-code', {
            method: 'POST', auth: false,
            body: {...Object.fromEntries(new FormData(form).entries()), ...(hasResendToken ? {resend_token: form.dataset.resendToken} : {})},
        });
        if (!form.isConnected) return;
        form.dataset.codeRequested = 'true';
        form.dataset.resendToken = result.resend_token || '';
        form.dataset.codeEmail = form.elements.email.value.trim();
        form.dataset.resendAt = String(Date.now() + Number(result.resend_after ?? 60) * 1000);
        form.querySelectorAll('[name=name], [name=email], [name=birth_date], [name=password], [name=password_confirmation]').forEach(input => input.readOnly = true);
        form.elements.code.value = '';
        form.querySelector('[data-verification-email]').textContent = form.dataset.codeEmail;
        form.querySelector('[data-email-code-status]').textContent = `Код действует ${Math.ceil(Number(result.expires_in ?? 600) / 60)} мин. Введите 6 цифр из письма.`;
        setRegistrationBusy(form, false); openEmailVerification(form);
        // Resends use the encrypted, email-bound token rather than a second CAPTCHA.
    } catch (error) {
        if (!form.isConnected) return;
        if (error.status === 422) {
            // An expired resend ticket requires a fresh CAPTCHA on the original form.
            if (wasRequested) {
                form.querySelector('[data-email-dialog]').close();
                delete form.dataset.codeRequested; delete form.dataset.resendToken;
                form.querySelectorAll('[readonly]').forEach(input => input.readOnly = false);
            }
            try { await refreshCaptchaInForm(form); } catch {}
        }
        applyErrors(form, error);
    } finally {
        if (form.isConnected) {
            setRegistrationBusy(form, false);
            button.querySelector('span').textContent = form.dataset.codeRequested === 'true' ? 'Ввести код подтверждения' : 'Зарегистрироваться';
            confirm.textContent = 'Подтвердить и создать аккаунт';
        }
    }
}

async function refreshCaptchaInForm(form) {
    await loadCaptcha();
    const box = form.querySelector('.captcha-box');
    if (box) box.outerHTML = captchaMarkup();
    form.querySelector('[data-action="refresh-captcha"]')?.addEventListener('click', () => refreshCaptchaInForm(form).catch(error => applyErrors(form, error)));
    mountTurnstile();
}

async function submitEmailRegistration(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.busy === 'true') return;
    if (form.dataset.codeRequested !== 'true') return requestEmailCode(form);
    if (!form.querySelector('[data-email-dialog]').open) { openEmailVerification(form); return; }
    clearRegistrationErrors(form);
    if (!/^\d{6}$/.test(form.elements.code.value)) { applyErrors(form, new ApiError('Введите код из шести цифр.', 422, {code:['Введите 6 цифр из письма.']})); return; }
    const confirm = form.querySelector('[data-confirm-email]');
    setRegistrationBusy(form, true); confirm.textContent = 'Проверяем код…';
    try {
        const result = await api('/auth/email/verify-code', {
            method: 'POST', auth: false,
            body: {email: form.dataset.codeEmail, code: form.elements.code.value},
        });
        form.querySelector('[data-email-dialog]').close();
        setAuth(result); go(result.new_account ? 'welcome' : 'tracks');
    } catch (error) { if (form.isConnected) applyErrors(form, error); }
    finally { if (form.isConnected) { setRegistrationBusy(form, false); confirm.textContent = 'Подтвердить и создать аккаунт'; } }
}
async function submitFilters(event) { event.preventDefault(); const data = new FormData(event.currentTarget); state.list.filters = { ...state.list.filters, q: data.get('q') || '', genre_id: data.get('genre_id') || '', sort: data.get('sort') || 'created_at', page: 1 }; go(`tracks?${new URLSearchParams(state.list.filters)}`); }
async function submitTrack(event) {
    event.preventDefault();
    const form = event.currentTarget; const editId = form.dataset.editId;
    const fields = ['title', 'genre_id']; if (!editId) fields.push('audio');
    if (!validate(form, fields)) return;
    if (!editId && form.dataset.durationState === 'loading') { applyErrors(form, new ApiError('Дождитесь определения длительности.', 422)); return; }
    if (!editId && form.dataset.durationState === 'invalid') { applyErrors(form, new ApiError('Длительность превышает допустимый или тарифный лимит.', 422)); return; }
    const submit = form.querySelector('[type=submit]'); submit.disabled = true;
    try {
        let result;
        if (editId) result = await api(`/tracks/${editId}`, { method: 'PATCH', body: { title: form.elements.title.value, genre_id: form.elements.genre_id.value, description: form.elements.description.value || null } });
        else { result = await uploadTrackWithProgress(form); try { const updated = await api('/me'); state.user = dataOf(updated); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); } catch { /* Upload already succeeded; never prompt a duplicate upload. */ } }
        const track = dataOf(result); if (editId) { notify('Изменения сохранены', 'success'); go(`tracks/${track.id}`); } else go('upload-success');
    } catch (error) { applyErrors(form, error); } finally { submit.disabled = false; }
}

function inspectAudio(form) {
    const file = form.elements.audio.files[0]; const display = form.querySelector('[data-duration-display]');
    if (!file) { form.dataset.durationState = ''; display.textContent = 'Выберите аудиофайл'; return; }
    form.dataset.durationState = 'loading'; display.textContent = 'Определяем длительность…';
    const objectUrl = URL.createObjectURL(file); const probe = new Audio();
    const cleanup = () => { probe.removeAttribute('src'); probe.load(); URL.revokeObjectURL(objectUrl); };
    probe.onloadedmetadata = () => {
        if (form.elements.audio.files[0] !== file) { cleanup(); return; }
        const duration = Math.ceil(probe.duration); const usage = state.billing?.usage;
        const exceedsTrack = !Number.isFinite(duration) || duration <= 0 || duration > 36000;
        const exceedsPlan = usage && usage.limit_seconds != null && Number(usage.used_seconds) + duration > Number(usage.limit_seconds);
        form.dataset.durationState = exceedsTrack || exceedsPlan ? 'invalid' : 'ready';
        display.textContent = exceedsTrack ? 'Трек должен длиться не более 10 часов.' : exceedsPlan ? `Длительность ${formatDuration(duration)} превышает оставшийся лимит тарифа.` : `${formatDuration(duration)} · точную длительность проверит сервер`;
        cleanup();
    };
    probe.onerror = () => { if (form.elements.audio.files[0] === file) { form.dataset.durationState = 'unknown'; display.textContent = 'Браузер не определил длительность; её проверит сервер.'; } cleanup(); };
    probe.src = objectUrl;
}
function uploadTrackWithProgress(form) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const progress = form.querySelector('[data-upload-progress]');
        const bar = form.querySelector('[data-upload-bar]');
        const percent = form.querySelector('[data-upload-percent]');
        progress?.classList.remove('is-hidden');
        xhr.open('POST', apiUrl('/tracks'));
        xhr.setRequestHeader('Accept', 'application/json');
        if (state.token) xhr.setRequestHeader('Authorization', `Bearer ${state.token}`);
        xhr.upload.addEventListener('progress', event => {
            if (!event.lengthComputable) return;
            const value = Math.round(event.loaded / event.total * 100);
            if (bar) bar.value = value;
            if (percent) percent.textContent = `${value}%`;
        });
        xhr.onload = () => {
            let payload = {}; try { payload = JSON.parse(xhr.responseText || '{}'); } catch {}
            if (xhr.status >= 200 && xhr.status < 300) return resolve(payload);
            reject(new ApiError(payload.message || `Ошибка сервера (${xhr.status}).`, xhr.status, payload.errors || {}));
        };
        xhr.onerror = () => reject(new ApiError('Не удалось загрузить файл.', 0));
        xhr.send(new FormData(form));
    });
}
async function registerPlay(id) { const result = await api(`/tracks/${id}/play`, { method: 'POST', auth: Boolean(state.token) }); document.querySelectorAll(`[data-play-count="${id}"]`).forEach(node => { node.textContent = String(result.plays_count ?? node.textContent); }); if (result.counted === false) notify('Это прослушивание уже учитывалось в последние 30 секунд', 'info'); return result; }
async function toggleLike(button) { try { const id = button.dataset.id; const liked = button.dataset.liked === 'true'; if (liked) await api(`/users/${state.user.id}/likes/${id}`, { method: 'DELETE' }); else await api(`/users/${state.user.id}/likes/${id}`, { method: 'PUT' }); button.dataset.liked = String(!liked); button.className = `button ${!liked ? 'button-success' : 'button-light'}`; button.textContent = !liked ? '♥ В избранном' : '♡ Добавить в избранное'; notify(!liked ? 'Трек добавлен в избранное' : 'Лайк снят', 'success'); } catch (error) { notify(readError(error), 'error'); } }
async function toggleFollow(button) { const artistId = button.dataset.id; if (!artistId || !state.user) return; try { const following = button.dataset.following === 'true'; if (following) await api(`/users/${state.user.id}/following/${artistId}`, { method: 'DELETE' }); else await api(`/users/${state.user.id}/following/${artistId}`, { method: 'PUT' }); button.dataset.following = String(!following); button.textContent = !following ? '✓ Вы подписаны' : '＋ Подписаться'; notify(!following ? 'Подписка оформлена' : 'Подписка отменена', 'success'); } catch (error) { notify(readError(error), 'error'); } }
async function submitComment(event) { event.preventDefault(); const form = event.currentTarget; try { await api(`/tracks/${state.currentTrack.id}/comments`, { method: 'POST', body: { body: form.elements.body.value } }); notify('Комментарий опубликован', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function deleteComment(id) { if (!window.confirm('Удалить комментарий?')) return; try { await api(`/comments/${id}`, { method: 'DELETE' }); notify('Комментарий удалён', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }
async function submitProfile(event) { event.preventDefault(); const form = event.currentTarget; try { if (form.elements.avatar.files[0]) { const data = new FormData(); data.append('avatar', form.elements.avatar.files[0]); const uploaded = await api('/me/avatar', {method:'POST',body:data}); state.user = dataOf(uploaded); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); } const result = await api('/me', { method: 'PATCH', body: { name: form.elements.name.value, bio: form.elements.bio.value || null, social_links: { website: form.elements.website.value || null, youtube: form.elements.youtube.value || null, telegram: form.elements.telegram.value || null } } }); state.user = dataOf(result); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); notify('Профиль обновлён', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function createPlaylist(event) { event.preventDefault(); const form = event.currentTarget; try { const result = await api('/playlists', { method: 'POST', body: { title: form.elements.title.value } }); notify('Плейлист создан', 'success'); go(`playlists/${dataOf(result).id}`); } catch (error) { applyErrors(form, error); } }
async function editPlaylist(event) { event.preventDefault(); const form = event.currentTarget; try { await api(`/playlists/${form.dataset.id}`, { method: 'PATCH', body: { title: form.elements.title.value, description: form.elements.description.value || null, is_public: form.elements.is_public.checked } }); notify('Плейлист обновлён', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function deletePlaylist(id) { if (!window.confirm('Удалить плейлист?')) return; try { await api(`/playlists/${id}`, { method: 'DELETE' }); notify('Плейлист удалён', 'success'); go('playlists'); } catch (error) { notify(readError(error), 'error'); } }
async function addPlaylistTrack(event) { event.preventDefault(); const form = event.currentTarget; if (!form.elements.track_id.value) return; try { await api(`/playlists/${form.dataset.id}/tracks`, { method: 'POST', body: { track_id: form.elements.track_id.value } }); notify('Трек добавлен', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function playlistRemove(playlistId, trackId) { try { await api(`/playlists/${playlistId}/tracks/${trackId}`, { method: 'DELETE' }); notify('Трек убран из плейлиста', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }
async function playlistMove(playlistId, index, direction) { try { const playlist = dataOf(await api(`/playlists/${playlistId}`)); const ids = (playlist.tracks || []).map(track => track.id); const target = direction === 'up' ? index - 1 : index + 1; [ids[index], ids[target]] = [ids[target], ids[index]]; await api(`/playlists/${playlistId}/tracks`, { method: 'PUT', body: { track_ids: ids } }); render(); } catch (error) { notify(readError(error), 'error'); } }
async function submitStatsFilter(event) { event.preventDefault(); const data = new FormData(event.currentTarget); const params = new URLSearchParams(); if (data.get('from')) params.set('from', data.get('from')); if (data.get('to')) params.set('to', data.get('to')); go(`stats${params.toString() ? `?${params}` : ''}`); }
async function createGenre(event) { event.preventDefault(); const form = event.currentTarget; try { await api('/genres', { method: 'POST', body: { name: form.elements.name.value, slug: form.elements.slug.value || undefined } }); state.genres = []; notify('Жанр создан', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function editGenre(id, currentName) { const name = window.prompt('Новое название жанра:', currentName); if (!name || name === currentName) return; try { await api(`/genres/${id}`, { method: 'PATCH', body: { name } }); state.genres = []; notify('Жанр обновлён', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }
async function deleteGenre(id) { if (!window.confirm('Удалить жанр? Жанр с треками сервер отклонит.')) return; try { await api(`/genres/${id}`, { method: 'DELETE' }); state.genres = []; notify('Жанр удалён', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }
async function blockUser(block) { const form = document.querySelector('#block-form'); const id = form?.elements.user_id?.value; if (!id) return; try { await api(`/admin/users/${id}/block`, { method: block ? 'PUT' : 'DELETE' }); notify(block ? 'Пользователь заблокирован' : 'Пользователь разблокирован', 'success'); } catch (error) { notify(readError(error), 'error'); } }
async function deleteTrack(id) { if (!window.confirm('Удалить этот трек и связанные записи?')) return; try { await api(`/tracks/${id}`, { method: 'DELETE' }); notify('Трек удалён', 'success'); go('tracks'); } catch (error) { notify(readError(error), 'error'); } }
const moderationPreviewUrls = new Map();
const previewRequests = new Set();
async function previewModerationTrack(btn) {
    const id = btn.dataset.id; const audio = document.querySelector(`[data-preview-audio="${id}"]`); if (!audio) return;
    document.querySelectorAll('[data-preview-audio]').forEach(other => { if (other !== audio) other.pause(); });
    try { player.pause?.(); } catch {}
    if (audio.src && !audio.paused) { audio.pause(); btn.textContent = '▶ Прослушать'; return; }
    if (!audio.src) {
        const controller = new AbortController(); previewRequests.add(controller);
        btn.disabled = true; btn.textContent = 'Загрузка…';
        try {
            const response = await fetch(apiUrl(`/tracks/${id}/stream`), { signal:controller.signal, headers: { Authorization: `Bearer ${state.token}`, Accept: 'audio/*' } });
            if (!response.ok) throw new ApiError(response.status === 404 ? 'Аудиофайл трека не найден.' : `Ошибка сервера (${response.status}).`, response.status);
            const blob = await response.blob();
            if (controller.signal.aborted || !audio.isConnected) return;
            const url = URL.createObjectURL(blob); moderationPreviewUrls.set(id, url); audio.src = url;
            audio.onended = () => { btn.textContent = '▶ Прослушать'; };
            audio.onpause = () => { btn.textContent = '▶ Прослушать'; };
            audio.onplay = () => { btn.textContent = '❚❚ Пауза'; };
        } catch (error) { if (error.name !== 'AbortError') notify(readError(error), 'error'); btn.textContent = '▶ Прослушать'; return; }
        finally { previewRequests.delete(controller); btn.disabled = false; }
    }
    if (!audio.isConnected) return;
    audio.classList.remove('is-hidden');
    try { await audio.play(); } catch { notify('Браузер не смог воспроизвести этот файл.', 'error'); }
}

async function moderateTrack(id, status) { const reason = status === 'rejected' ? window.prompt('Укажите причину отклонения:') : null; if (status === 'rejected' && !reason) return; try { await api(`/admin/tracks/${id}/status`, { method: 'PATCH', body: { status, ...(reason ? { reason } : {}) } }); notify(status === 'published' ? 'Трек опубликован' : 'Трек отклонён', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }

window.addEventListener('hashchange', render);
(async function boot() { await handleBillingQuery(); render(); })();

async function mountModerationCovers(version) {
    await Promise.all([...document.querySelectorAll('[data-moderation-cover]')].map(async img => {
        try {
            const response = await fetch(apiUrl(`/tracks/${img.dataset.moderationCover}/cover`), {headers: {Authorization: `Bearer ${state.token}`}});
            if (!response.ok) throw new Error('cover');
            const blob = await response.blob();
            if (version !== renderVersion || !img.isConnected) return;
            const url = URL.createObjectURL(blob); moderationPreviewUrls.set(`cover-${img.dataset.moderationCover}`, url); img.src = url;
        } catch { if (img.isConnected) { const label = document.createElement('span'); label.textContent = 'Обложка недоступна'; img.replaceWith(label); } }
    }));
}
function bindAdminUsers() {
    const filter = document.querySelector('#admin-users-filter');
    filter?.addEventListener('submit', event => { event.preventDefault(); go(`admin?${new URLSearchParams(new FormData(filter))}`); });
    document.querySelectorAll('[data-user-block]').forEach(btn => btn.addEventListener('click', async () => {
        if (!confirm('Изменить блокировку пользователя #' + btn.dataset.id + '?')) return;
        btn.disabled = true;
        try { await api(`/admin/users/${btn.dataset.id}/block`, {method:btn.dataset.userBlock === 'true' ? 'PUT' : 'DELETE'}); await render(); } catch (e) { btn.disabled = false; notify(readError(e), 'error'); }
    }));
    document.querySelectorAll('[data-admin-user-form]').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault(); const btn = form.querySelector('[type=submit]'); btn.disabled = true;
        try { await api(`/admin/users/${form.dataset.adminUserForm}`, {method:'PATCH', body:Object.fromEntries(new FormData(form))}); notify('Пользователь обновлён', 'success'); await render(); } catch (e) { applyErrors(form,e); btn.disabled = false; }
    }));
}
