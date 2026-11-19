import { createLibraryViews } from './library.js';
import { pricingView, billingView, choosePlan, billingAction, handleBillingQuery } from './billing.js';
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
// A single persistent player lets playback continue while navigating.
const player = new Audio();
let playingId = null;
player.addEventListener('playing', () => { if (playingId) registerPlay(playingId).catch(() => {}); });
player.addEventListener('error', () => notify('Не удалось воспроизвести аудио. Проверьте файл трека.', 'error'));
function avatarMarkup(user, css = 'profile-avatar') {
    const url = user?.avatar_path?.startsWith('avatars/') ? `${apiUrl(`/users/${user.id}/avatar`)}?v=${encodeURIComponent(user.avatar_path)}` : mediaUrl(user?.avatar_path);
    return url ? `<img class="${css} avatar-image" src="${escapeHtml(url)}" alt="Аватар">` : `<span class="${css}">${escapeHtml((user?.name || 'G').slice(0, 1).toUpperCase())}</span>`;
}
function trackCoverUrl(track) {
    if (!track.cover_path) return '';
    return track.status === 'published' ? apiUrl(`/tracks/${track.id}/cover`) : mediaUrl(track.cover_path);
}
async function playTrack(id) {
    const detail = document.querySelector(`[data-track-audio="${id}"]`);
    if (detail) {
        player.pause();
        if (!detail.paused) { detail.pause(); return; }
        await detail.play(); return;
    }
    document.querySelectorAll('[data-track-audio]').forEach(audio => audio.pause());
    if (String(playingId) === String(id) && !player.paused) { player.pause(); return; }
    if (String(playingId) !== String(id)) { playingId = id; player.src = apiUrl(`/tracks/${id}/stream`); }
    await player.play();
}


function readUser() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { return null; }
}

function dataOf(payload) { return payload?.data ?? payload; }
function itemsOf(payload) { return Array.isArray(payload?.data) ? payload.data : []; }
function escapeHtml(value = '') { return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
function roleName(role) { return ({ admin: 'Администратор', artist: 'Исполнитель', listener: 'Слушатель' })[role] || role || 'Гость'; }
function planName(plan) { return ({ free: 'Free', artist: 'Artist', artist_pro: 'Artist Pro' })[plan] || plan || 'Free'; }
function money(cents) { return cents ? `€${(Number(cents) / 100).toFixed(2)}` : 'Бесплатно'; }
function hours(seconds) { return seconds == null ? 'Без лимита' : `${(Number(seconds) / 3600).toFixed(seconds % 3600 ? 1 : 0)} ч/мес`; }
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
    constructor(message, status, errors = {}) { super(message); this.status = status; this.errors = errors; }
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
    if (response.status === 401) { clearAuth(); go('login'); throw new ApiError('Сессия истекла. Войдите снова.', 401, payload.errors || {}); }
    if (!response.ok) throw new ApiError(payload.message || `Ошибка сервера (${response.status}).`, response.status, payload.errors || {});
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
    const user = state.user; const isAdmin = user?.role === 'admin'; const canUpload = Boolean(user && ['artist', 'admin'].includes(user.role));
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
        <div class="main-column"><header class="topbar"><button class="mobile-menu" data-action="toggle-menu" aria-label="Открыть меню">☰</button><div class="breadcrumbs"><span>Homeostat</span><b>/</b><strong>${escapeHtml(title)}</strong></div><div class="top-actions"><a class="user-chip" href="#profile" title="Открыть профиль">${avatarMarkup(user, 'avatar')}<span class="user-chip-copy"><b>${escapeHtml(user?.name || 'Гость')}</b><small>${escapeHtml(roleName(user?.role))}</small></span></a></div></header><main class="content" id="screen">${content}</main></div>
    </div><div id="toast" class="toast" role="status"></div>`;
}

function formMessage(message = '') { return `<p class="form-message" data-form-message>${escapeHtml(message)}</p>`; }
function fieldError(errors, field) { return errors?.[field]?.[0] ? `<small class="field-error">${escapeHtml(errors[field][0])}</small>` : ''; }

function socialButtons(mode) {
    const role = document.querySelector('#auth-form select[name="role"]')?.value || 'listener';
    const suffix = mode === 'register' ? `?role=${encodeURIComponent(role)}` : '';
    return `<div class="auth-divider"><span>или продолжить через</span></div><div class="social-grid"><a class="social-button social-google" data-provider="google" href="${apiUrl(`/auth/google/redirect${suffix}`)}"><b>G</b><span>Google</span></a><a class="social-button social-apple" data-provider="apple" href="${apiUrl(`/auth/apple/redirect${suffix}`)}"><b>●</b><span>Apple ID</span></a><a class="social-button social-github" data-provider="github" href="${apiUrl(`/auth/github/redirect${suffix}`)}"><b>◈</b><span>GitHub</span></a></div>`;
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
        ? `<label>Имя<input name="name" autocomplete="name" placeholder="Как к тебе обращаться" required><span class="inline-error" data-error="name"></span></label><label>Email<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required><span class="inline-error" data-error="email"></span></label><label>Роль<select name="role"><option value="listener">Слушатель</option><option value="artist">Исполнитель</option></select></label>${captchaMarkup()}<div class="email-code-actions"><button class="button button-light" type="button" data-action="request-email-code">Получить код на почту</button><span class="inline-error" data-email-code-status></span></div><label class="is-hidden" data-email-code-field>Код из письма<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6 цифр"><span class="inline-error" data-error="code"></span></label>`
        : `<label>Email<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required><span class="inline-error" data-error="email"></span></label><label>Пароль<input name="password" type="password" autocomplete="current-password" placeholder="Минимум 8 символов" required><span class="inline-error" data-error="password"></span></label>`;
    return `<div class="auth-layout"><section class="auth-art"><div class="orb orb-one"></div><div class="orb orb-two"></div><span class="eyebrow">SOUND • STATE • HOME</span><h1>Найди звук,<br><em>который остаётся.</em></h1><p>Homeostat собирает музыку вокруг твоего состояния — от первой ноты до собственного плейлиста.</p><div class="wave-hero" aria-hidden="true">${Array.from({ length: 32 }, (_, i) => `<i style="--h:${20 + ((i * 17) % 76)}%"></i>`).join('')}</div><div class="auth-art-note">Создавай свою сцену. Слушай своё.</div></section><section class="auth-card-wrap"><div class="auth-card"><div class="auth-card-head"><span class="eyebrow">${register ? 'NEW ACCOUNT' : 'WELCOME BACK'}</span><h2>${register ? 'Создай свой профиль' : 'С возвращением'}</h2><p>${register ? 'Код подтверждения придёт на указанную почту.' : 'Войдите, чтобы продолжить слушать.'}</p></div>${socialButtons(mode)}<div class="auth-divider auth-divider-small"><span>${register ? 'или создай аккаунт по email' : 'или войди по email'}</span></div><form id="auth-form" data-mode="${register ? 'register' : 'login'}" novalidate>${fields}${formMessage(error)}<button class="button button-primary button-wide" type="submit"><span>${register ? 'Подтвердить и создать' : 'Войти в Homeostat'}</span><b>→</b></button></form><div class="auth-switch">${register ? 'Уже есть аккаунт?' : 'Впервые здесь?'} <a href="#${register ? 'login' : 'register'}">${register ? 'Войти' : 'Создать профиль'}</a></div><p class="auth-legal">Продолжая, вы принимаете правила Homeostat и подтверждаете, что вам исполнилось 13 лет.</p></div></section></div>`;
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

async function completeOAuth() {
    const params = new URLSearchParams(window.location.search);
    const handle = params.get('oauth'); const oauthError = params.get('oauth_error');
    if (!handle && !oauthError) return false;
    window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
    if (oauthError) { go('login'); window.setTimeout(() => notify(oauthError, 'error'), 50); return true; }
    try { setAuth(await api('/auth/oauth/exchange', { method: 'POST', auth: false, body: { handle } })); go('tracks'); window.setTimeout(() => notify('Авторизация выполнена', 'success'), 50); } catch (error) { go('login'); window.setTimeout(() => notify(readError(error), 'error'), 50); }
    return true;
}

async function render() {
    const route = currentRoute();
    if (!state.token && !['login', 'register', 'tracks', 'pricing'].includes(route.path)) { go('login'); return; }
    if (state.token && ['login', 'register'].includes(route.path)) { go('tracks'); return; }
    if (['login', 'register'].includes(route.path)) { if (route.path === 'register') { try { await loadCaptcha(); } catch (error) { state.captcha = { provider: 'math', question: 'Обновите страницу', id: '' }; } } root.innerHTML = loginView(route.path); bindEvents(); mountTurnstile(); return; }
    const screen = document.querySelector('#screen'); if (screen) screen.innerHTML = loadingView();
    let content = ''; let title = 'Обзор';
    try {
        if (route.path === 'tracks') { await ensureGenres(); await loadTracks(route.params); content = tracksView(); }
        else if (route.path.startsWith('tracks/')) { const id = route.path.split('/')[1]; // Send the optional bearer token so the API can authorize the owner/admin
            // to view a pending track immediately after upload. Guests still get
            // the same server-side 404 for unpublished tracks.
            const result = await api(`/tracks/${id}`); state.currentTrack = dataOf(result); const comments = itemsOf(await api(`/tracks/${id}/comments`)); state.currentTrackLiked = await checkLiked(id); state.currentArtistFollowing = false; if (state.user && state.currentTrack.artist?.id && state.user.id !== state.currentTrack.artist.id) { const status = await api(`/users/${state.user.id}/following/${state.currentTrack.artist.id}`); state.currentArtistFollowing = Boolean(status.following); } content = trackDetailView(state.currentTrack, comments); title = 'Трек'; }
        else if (route.path === 'feed') { requireAuth(); const result = await api(`/users/${state.user.id}/feed`); content = trackCollectionView(itemsOf(result), 'Моя лента', 'Новые публикации артистов, на которых вы подписаны.'); title = 'Моя лента'; }
        else if (route.path === 'favorites') { requireAuth(); const result = await api(`/users/${state.user.id}/likes`); content = trackCollectionView(itemsOf(result), 'Избранное', 'Треки, которые вы сохранили лайком.'); title = 'Избранное'; }
        else if (route.path === 'playlists') { requireAuth(); content = playlistListView(itemsOf(await api('/playlists'))); title = 'Плейлисты'; }
        else if (route.path === 'playlists/discover') { requireAuth(); const params = new URLSearchParams(route.params); const result = await api(`/playlists/discover?${params}`); content = discoverPlaylistsView(result, params); title = 'Плейлисты сообщества'; }
        else if (/^playlists\/\d+\/search$/.test(route.path)) { requireAuth(); const id = route.path.split('/')[1]; const playlist = dataOf(await api(`/playlists/${id}`)); if (playlist.owner?.id !== state.user.id && state.user.role !== 'admin') throw new ApiError('Недостаточно прав.', 403); const params = new URLSearchParams(route.params); const result = await api(`/tracks?${params}`); content = playlistSearchView(playlist, result, params); title = 'Поиск треков'; }
        else if (route.path.startsWith('playlists/')) { requireAuth(); const result = await api(`/playlists/${route.path.split('/')[1]}`); const liked = await api(`/playlists/${route.path.split('/')[1]}/like`); state.likedPlaylistIds = liked.liked ? [dataOf(result).id] : []; content = playlistDetailView(dataOf(result)); title = 'Плейлист'; }
        else if (route.path === 'upload') { requireAuth(); await ensureGenres(); try { state.billing = dataOf(await api('/billing/me')); } catch { state.billing = null; } content = uploadView(); title = 'Загрузить'; }
        else if (route.path === 'upload-success') { requireAuth(); content = `<div class="empty-state"><span class="empty-icon">✓</span><h1>Трек отправлен на модерацию</h1><p>После проверки он появится в каталоге. Повторная загрузка не нужна.</p><div class="action-row"><a class="button button-primary" href="#tracks">В каталог</a><a class="button button-light" href="#upload">Загрузить ещё</a></div></div>`; title = 'Загрузка завершена'; }
        else if (route.path.startsWith('edit/')) { requireAuth(); await ensureGenres(); const result = await api(`/tracks/${route.path.split('/')[1]}`); content = uploadView(dataOf(result)); title = 'Редактировать'; }
        else if (route.path === 'admin') { requireRole('admin'); await ensureGenres(); const result = await api('/admin/tracks?status=pending'); content = adminView(itemsOf(result)); title = 'Администрирование'; }
        else if (route.path === 'stats') { requireAuth(); const filters = { from: route.params.get('from') || '', to: route.params.get('to') || '' }; const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)); const result = await api(`/me/tracks/stats${query.toString() ? `?${query}` : ''}`); content = statsView(dataOf(result), filters); title = 'Статистика'; }
        else if (route.path === 'pricing') { if (!state.plans.length) state.plans = itemsOf(await api('/billing/plans', { auth: false })); content = pricingView(state.plans); title = 'Тарифы'; }
        else if (route.path === 'billing') { requireAuth(); const result = await api('/billing/me'); state.billing = dataOf(result); content = billingView(state.billing); title = 'Подписка'; }
        else if (route.path === 'profile') { requireAuth(); const result = await api('/me'); state.user = dataOf(result); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); const page = key => Math.max(1, Number.parseInt(route.params.get(key), 10) || 1); const [likes, following, lists] = await Promise.all([api(`/users/${state.user.id}/likes?page=${page('tracks_page')}`), api(`/me/following?page=${page('artists_page')}`), api(`/me/liked-playlists?page=${page('playlists_page')}`)]); content = profileView(likes, following, lists, route.params); title = 'Профиль'; }
        else { go(state.token ? 'tracks' : 'login'); return; }
        root.innerHTML = shell(content, title); bindEvents();
    } catch (error) {
        if (error.status === 401) return;
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
    try { return Boolean((await api(`/users/${state.user.id}/likes/${trackId}`)).liked); } catch { return false; }
}
function applyErrors(form, error) { Object.entries(error.errors || {}).forEach(([field, messages]) => { const node = form.querySelector(`[data-error="${field}"]`); if (node) node.textContent = messages[0] || ''; }); const message = form.querySelector('[data-form-message]'); if (message) { message.textContent = readError(error); message.classList.add('is-error'); } }
function validate(form, fields) { let valid = true; fields.forEach(field => { const input = form.elements[field]; const node = form.querySelector(`[data-error="${field}"]`); if (!input || !node) return; if (!input.value || (input.type === 'file' && !input.files.length)) { node.textContent = 'Заполните поле.'; valid = false; } else node.textContent = ''; }); return valid; }

function bindEvents() {
    document.querySelectorAll('[data-action="toggle-menu"]').forEach(btn => btn.addEventListener('click', () => document.querySelector('.sidebar')?.classList.toggle('is-open')));
    document.querySelectorAll('[data-action="refresh-captcha"]').forEach(btn => btn.addEventListener('click', async () => { try { await loadCaptcha(); render(); } catch (error) { notify(readError(error), 'error'); } }));
    document.querySelectorAll('[data-action="request-email-code"]').forEach(btn => btn.addEventListener('click', requestEmailCode));
    document.querySelectorAll('[data-provider]').forEach(link => link.addEventListener('click', () => { const role = document.querySelector('#auth-form select[name="role"]')?.value || 'listener'; link.href = `${apiUrl(`/auth/${link.dataset.provider}/redirect`)}?role=${encodeURIComponent(role)}`; }));
    document.querySelectorAll('[data-action="logout"]').forEach(btn => btn.addEventListener('click', async () => { try { await api('/logout'); } catch {} clearAuth(); go('login'); notify('Вы вышли из аккаунта', 'success'); }));
    document.querySelectorAll('[data-action="retry"]').forEach(btn => btn.addEventListener('click', render));
    document.querySelectorAll('[data-cycle]').forEach(btn => btn.addEventListener('click', () => { state.pricingCycle = btn.dataset.cycle; render(); }));
    document.querySelectorAll('[data-action="choose-plan"]').forEach(btn => btn.addEventListener('click', () => choosePlan(btn.dataset.plan)));
    document.querySelectorAll('[data-action="billing-portal"]').forEach(btn => btn.addEventListener('click', () => billingAction('portal')));
    document.querySelectorAll('[data-action="billing-cancel"]').forEach(btn => btn.addEventListener('click', () => billingAction('cancel')));
    document.querySelectorAll('[data-action="billing-resume"]').forEach(btn => btn.addEventListener('click', () => billingAction('resume')));
    document.querySelectorAll('[data-action="page"]').forEach(btn => btn.addEventListener('click', () => { if (!btn.disabled) go(`tracks?page=${Number(btn.dataset.page)}`); }));
    document.querySelectorAll('[data-action="play"]').forEach(btn => btn.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); playTrack(btn.dataset.id).catch(() => notify('Не удалось воспроизвести трек.', 'error')); }));
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
    document.querySelectorAll('[data-track-audio]').forEach(audio => audio.addEventListener('playing', () => { player.pause(); registerPlay(audio.dataset.track).catch(() => {}); }));
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

async function submitAuth(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.mode === 'register') return submitEmailRegistration(event);
    if (!validate(form, ['email', 'password'])) return;
    try {
        const result = await api('/login', { method: 'POST', auth: false, body: Object.fromEntries(new FormData(form).entries()) });
        setAuth(result); notify('Добро пожаловать', 'success'); go('tracks');
    } catch (error) { applyErrors(form, error); }
}

async function requestEmailCode(event) {
    const form = event.currentTarget.closest('form');
    const fields = ['name', 'email', 'captcha_answer'];
    if (!validate(form, fields)) return;
    try {
        await api('/auth/email/request-code', {
            method: 'POST', auth: false,
            body: Object.fromEntries(new FormData(form).entries()),
        });
        form.dataset.codeRequested = 'true';
        form.querySelector('[data-email-code-field]')?.classList.remove('is-hidden');
        const status = form.querySelector('[data-email-code-status]');
        if (status) status.textContent = 'Код отправлен. Он действует 10 минут.';
        notify('Если адрес допустим, письмо с кодом отправлено', 'success');
    } catch (error) {
        applyErrors(form, error);
        if (error.status === 422) { try { await loadCaptcha(); render(); } catch {} }
    }
}

async function submitEmailRegistration(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (form.dataset.codeRequested !== 'true') {
        notify('Сначала запросите код на почту', 'info');
        return;
    }
    if (!validate(form, ['name', 'email', 'code'])) return;
    try {
        const result = await api('/auth/email/verify-code', {
            method: 'POST', auth: false,
            body: Object.fromEntries(new FormData(form).entries()),
        });
        setAuth(result); notify('Профиль создан', 'success'); go('tracks');
    } catch (error) { applyErrors(form, error); }
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
        else result = await uploadTrackWithProgress(form);
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
async function submitProfile(event) { event.preventDefault(); const form = event.currentTarget; try { if (form.elements.avatar.files[0]) { const data = new FormData(); data.append('avatar', form.elements.avatar.files[0]); const uploaded = await api('/me/avatar', {method:'POST',body:data}); state.user = dataOf(uploaded); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); } const result = await api('/me', { method: 'PATCH', body: { name: form.elements.name.value, bio: form.elements.bio.value || null } }); state.user = dataOf(result); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); notify('Профиль обновлён', 'success'); render(); } catch (error) { applyErrors(form, error); } }
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
async function moderateTrack(id, status) { const reason = status === 'rejected' ? window.prompt('Укажите причину отклонения:') : null; if (status === 'rejected' && !reason) return; try { await api(`/admin/tracks/${id}/status`, { method: 'PATCH', body: { status, ...(reason ? { reason } : {}) } }); notify(status === 'published' ? 'Трек опубликован' : 'Трек отклонён', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }

window.addEventListener('hashchange', render);
(async function boot() { if (await completeOAuth()) return; handleBillingQuery(); render(); })();
