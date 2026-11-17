const API_BASE = (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, '');
const TOKEN_KEY = 'homeostat_token';
const USER_KEY = 'homeostat_user';

const state = {
    token: localStorage.getItem(TOKEN_KEY),
    user: readUser(),
    genres: [],
    currentTrack: null,
    list: { items: [], meta: {}, filters: { q: '', genre_id: '', sort: 'created_at', direction: 'desc', page: 1 } },
};

const root = document.querySelector('#app-root');

function readUser() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { return null; }
}

function escapeHtml(value = '') {
    return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
}

function roleName(role) {
    return ({ admin: 'Администратор', artist: 'Исполнитель', listener: 'Слушатель' })[role] || role || 'Гость';
}

function setAuth(payload) {
    const token = payload?.token;
    const user = payload?.user?.data || payload?.user;
    if (token) { state.token = token; localStorage.setItem(TOKEN_KEY, token); }
    if (user) { state.user = user; localStorage.setItem(USER_KEY, JSON.stringify(user)); }
}

function clearAuth() {
    state.token = null;
    state.user = null;
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}

function go(path) {
    window.location.hash = path.startsWith('#') ? path : `#${path}`;
}

function currentRoute() {
    const raw = window.location.hash.replace(/^#/, '') || (state.token ? 'tracks' : 'login');
    const [path, query = ''] = raw.split('?');
    return { path, params: new URLSearchParams(query) };
}

function mediaUrl(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    const base = new URL(API_BASE, window.location.origin).origin;
    return `${base}/storage/${String(path).replace(/^\//, '')}`;
}

function apiUrl(path) {
    return `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
}

class ApiError extends Error {
    constructor(message, status, errors = {}) {
        super(message); this.status = status; this.errors = errors;
    }
}

async function api(path, options = {}) {
    const { body, auth = true, method = 'GET', headers = {}, ...rest } = options;
    const isForm = body instanceof FormData;
    const requestHeaders = { Accept: 'application/json', ...headers };
    if (!isForm && body !== undefined) requestHeaders['Content-Type'] = 'application/json';
    if (auth && state.token) requestHeaders.Authorization = `Bearer ${state.token}`;

    let response;
    try {
        response = await fetch(apiUrl(path), {
            method,
            headers: requestHeaders,
            body: body === undefined || isForm ? body : JSON.stringify(body),
            ...rest,
        });
    } catch {
        throw new ApiError('Сервер недоступен. Проверьте, запущен ли Laravel.', 0);
    }

    let data = null;
    try { data = await response.json(); } catch { data = {}; }
    if (response.status === 401) {
        clearAuth();
        go('login');
        throw new ApiError('Сессия истекла. Войдите снова.', 401, data.errors || {});
    }
    if (!response.ok) {
        throw new ApiError(data.message || `Ошибка сервера (${response.status}).`, response.status, data.errors || {});
    }
    return data;
}

function notify(message, type = 'info') {
    const node = document.querySelector('#toast');
    if (!node) return;
    node.className = `toast toast-${type} is-visible`;
    node.textContent = message;
    window.clearTimeout(notify.timer);
    notify.timer = window.setTimeout(() => node.classList.remove('is-visible'), 3600);
}

function shell(content, title = 'Обзор') {
    const user = state.user;
    const isAdmin = user?.role === 'admin';
    const isArtist = user?.role === 'artist' || isAdmin;
    return `
        <div class="app-shell">
            <aside class="sidebar">
                <a class="brand" href="#tracks" aria-label="Homeostat">
                    <span class="brand-mark"><span></span><span></span><span></span><span></span></span>
                    <span>homeostat</span>
                </a>
                <nav class="primary-nav" aria-label="Основная навигация">
                    <a class="nav-link ${isActive('tracks')}" href="#tracks"><span>◉</span> Обзор</a>
                    <a class="nav-link ${isActive('upload')} ${isArtist ? '' : 'is-hidden'}" href="#upload"><span>＋</span> Загрузить</a>
                    <a class="nav-link ${isActive('stats')} ${isArtist ? '' : 'is-hidden'}" href="#stats"><span>⌁</span> Статистика</a>
                    <a class="nav-link ${isActive('admin')} ${isAdmin ? '' : 'is-hidden'}" href="#admin"><span>◆</span> Модерация</a>
                </nav>
                <div class="sidebar-bottom">
                    <div class="mini-note"><span class="live-dot"></span><span>API online mode</span></div>
                    <button class="nav-link nav-button" data-action="logout"><span>↪</span> Выйти</button>
                </div>
            </aside>
            <div class="main-column">
                <header class="topbar">
                    <button class="mobile-menu" data-action="toggle-menu" aria-label="Открыть меню">☰</button>
                    <div class="breadcrumbs"><span>Homeostat</span><b>/</b><strong>${escapeHtml(title)}</strong></div>
                    <div class="top-actions">
                        <a class="user-chip" href="#profile" title="Открыть профиль">
                            <span class="avatar">${escapeHtml((user?.name || 'G').slice(0, 1).toUpperCase())}</span>
                            <span class="user-chip-copy"><b>${escapeHtml(user?.name || 'Гость')}</b><small>${escapeHtml(roleName(user?.role))}</small></span>
                        </a>
                    </div>
                </header>
                <main class="content" id="screen">${content}</main>
            </div>
        </div>
        <div id="toast" class="toast" role="status"></div>
    `;
}

function isActive(name) {
    const route = currentRoute().path;
    return route === name || route.startsWith(`${name}/`) ? 'is-active' : '';
}

function formMessage(message = '') { return `<p class="form-message" data-form-message>${escapeHtml(message)}</p>`; }
function fieldError(errors, field) { return errors?.[field]?.[0] ? `<small class="field-error">${escapeHtml(errors[field][0])}</small>` : ''; }
function statusLabel(status) { return ({ pending: 'На модерации', published: 'Опубликован', rejected: 'Отклонён' })[status] || status || '—'; }

function loginView(mode = 'login', error = '') {
    const register = mode === 'register';
    return `
        <div class="auth-layout">
            <section class="auth-art">
                <div class="orb orb-one"></div><div class="orb orb-two"></div>
                <span class="eyebrow">SOUND • STATE • HOME</span>
                <h1>Найди звук,<br><em>который остаётся.</em></h1>
                <p>Homeostat собирает музыку вокруг твоего состояния — от первой ноты до собственного плейлиста.</p>
                <div class="wave-hero" aria-hidden="true">${Array.from({ length: 32 }, (_, i) => `<i style="--h:${20 + ((i * 17) % 76)}%"></i>`).join('')}</div>
            </section>
            <section class="auth-card-wrap">
                <div class="auth-card">
                    <div class="auth-card-head"><span class="eyebrow">${register ? 'NEW ACCOUNT' : 'WELCOME BACK'}</span><h2>${register ? 'Создай свой профиль' : 'С возвращением'}</h2><p>${register ? 'Регистрация занимает меньше минуты.' : 'Войдите, чтобы продолжить слушать.'}</p></div>
                    <form id="auth-form" data-mode="${register ? 'register' : 'login'}" novalidate>
                        ${register ? `<label>Имя<input name="name" autocomplete="name" placeholder="Как к тебе обращаться" required><span class="inline-error" data-error="name"></span></label>` : ''}
                        <label>Email<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required><span class="inline-error" data-error="email"></span></label>
                        <label>Пароль<input name="password" type="password" autocomplete="${register ? 'new-password' : 'current-password'}" placeholder="Минимум 8 символов" required><span class="inline-error" data-error="password"></span></label>
                        ${register ? `<label>Роль<select name="role"><option value="listener">Слушатель</option><option value="artist">Исполнитель</option></select></label>` : ''}
                        ${register ? `<label>Повторите пароль<input name="password_confirmation" type="password" autocomplete="new-password" placeholder="Ещё раз" required><span class="inline-error" data-error="password_confirmation"></span></label>` : ''}
                        ${formMessage(error)}
                        <button class="button button-primary button-wide" type="submit"><span>${register ? 'Создать профиль' : 'Войти в Homeostat'}</span><b>→</b></button>
                    </form>
                    <div class="auth-switch">${register ? 'Уже есть аккаунт?' : 'Впервые здесь?'} <a href="#${register ? 'login' : 'register'}">${register ? 'Войти' : 'Создать профиль'}</a></div>
                </div>
            </section>
        </div>
    `;
}

function tracksView() {
    const filters = state.list.filters;
    const items = state.list.items;
    const meta = state.list.meta || {};
    const cards = items.length ? items.map(trackCard).join('') : `<div class="empty-state"><span class="empty-icon">⌁</span><h3>Пока тихо</h3><p>Измените фильтр или загрузите первый трек.</p></div>`;
    return `
        <section class="hero-banner">
            <div><span class="eyebrow">YOUR DAILY FREQUENCY</span><h1>Музыка, которая<br><em>на твоей волне.</em></h1><p>Открывай новые треки, сохраняй состояние и слушай без лишнего шума.</p></div>
            <div class="hero-disc" aria-hidden="true"><div class="disc-inner"></div><span>H</span></div>
        </section>
        <section class="section-head"><div><span class="eyebrow">DISCOVER</span><h2>Каталог треков</h2></div><span class="result-count">${meta.total ? `${meta.total} треков` : 'Загрузка…'}</span></section>
        <form id="filter-form" class="filter-bar">
            <label class="search-field"><span>⌕</span><input name="q" value="${escapeHtml(filters.q)}" placeholder="Поиск по названию или артисту"></label>
            <label><span class="sr-only">Жанр</span><select name="genre_id"><option value="">Все жанры</option>${state.genres.map(g => `<option value="${g.id}" ${String(filters.genre_id) === String(g.id) ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}</select></label>
            <label><span class="sr-only">Сортировка</span><select name="sort"><option value="created_at" ${filters.sort === 'created_at' ? 'selected' : ''}>Новейшие</option><option value="plays_count" ${filters.sort === 'plays_count' ? 'selected' : ''}>По прослушиваниям</option><option value="likes_count" ${filters.sort === 'likes_count' ? 'selected' : ''}>По лайкам</option></select></label>
            <button class="button button-dark" type="submit">Найти</button>
        </form>
        <div class="cards-grid">${cards}</div>
        ${pagination(meta)}
    `;
}

function trackCard(track) {
    const artist = track.artist?.name || 'Неизвестный артист';
    const cover = mediaUrl(track.cover_path);
    return `<article class="track-card" data-track-id="${track.id}">
        <a class="cover-art ${cover ? 'has-image' : ''}" ${cover ? `style="background-image:url('${escapeHtml(cover)}')"` : ''} href="#tracks/${track.id}" aria-label="Открыть ${escapeHtml(track.title)}"><span>${cover ? '' : 'H'}</span><button class="play-button" data-action="play" data-id="${track.id}" type="button" aria-label="Слушать">▶</button></a>
        <div class="track-card-copy"><a href="#tracks/${track.id}" class="track-title">${escapeHtml(track.title)}</a><p>${escapeHtml(artist)}</p><div class="track-meta"><span>${escapeHtml(track.genre?.name || 'Без жанра')}</span><span>${formatDuration(track.duration)}</span></div></div>
    </article>`;
}

function formatDuration(seconds) { if (!seconds) return '—'; const min = Math.floor(seconds / 60); const sec = String(seconds % 60).padStart(2, '0'); return `${min}:${sec}`; }
function pagination(meta) {
    if (!meta.last_page || meta.last_page <= 1) return '';
    const page = meta.current_page || 1;
    return `<div class="pagination"><button class="button button-ghost" data-action="page" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>←</button><span>Страница <b>${page}</b> из ${meta.last_page}</span><button class="button button-ghost" data-action="page" data-page="${page + 1}" ${page >= meta.last_page ? 'disabled' : ''}>→</button></div>`;
}

function trackDetailView(track) {
    const cover = mediaUrl(track.cover_path);
    const canEdit = state.user && (state.user.role === 'admin' || state.user.id === track.artist?.id);
    return `<div class="back-row"><a href="#tracks">← Вернуться к каталогу</a></div>
        <section class="detail-panel"><div class="detail-cover ${cover ? 'has-image' : ''}" ${cover ? `style="background-image:url('${escapeHtml(cover)}')"` : ''}><span>${cover ? '' : 'H'}</span><button class="play-button play-large" data-action="play" data-id="${track.id}" type="button">▶</button></div>
        <div class="detail-copy"><span class="eyebrow">TRACK / ${escapeHtml(statusLabel(track.status))}</span><h1>${escapeHtml(track.title)}</h1><p class="detail-artist">${escapeHtml(track.artist?.name || 'Неизвестный артист')}</p><p>${escapeHtml(track.description || 'У этого трека пока нет описания.')}</p><div class="detail-stats"><span><b>${track.plays_count || 0}</b> прослушиваний</span><span><b>${track.likes_count || 0}</b> лайков</span><span><b>${formatDuration(track.duration)}</b> длительность</span></div><audio class="audio-player" controls preload="none" src="${escapeHtml(`${API_BASE}/tracks/${track.id}/stream`)}"></audio><div class="action-row">${canEdit ? `<a class="button button-light" href="#edit/${track.id}">Редактировать</a><button class="button button-danger" data-action="delete-track" data-id="${track.id}">Удалить</button>` : ''}</div></div></section>`;
}

function uploadView(track = null) {
    const edit = Boolean(track);
    return `<div class="page-intro"><span class="eyebrow">${edit ? 'EDIT TRACK' : 'ARTIST STUDIO'}</span><h1>${edit ? 'Настрой звучание' : 'Добавь свой трек'}</h1><p>${edit ? 'Обнови описание — после изменений трек снова пройдёт модерацию.' : 'Загрузи аудио, выбери жанр и отправь работу на модерацию.'}</p></div>
        <form id="track-form" class="form-panel" data-edit-id="${edit ? track.id : ''}" novalidate><div class="form-grid">
            <label>Название трека<input name="title" value="${escapeHtml(track?.title || '')}" placeholder="Например, Night Drive" required><span class="inline-error" data-error="title"></span></label>
            <label>Жанр<select name="genre_id" required><option value="">Выберите жанр</option>${state.genres.map(g => `<option value="${g.id}" ${String(track?.genre?.id) === String(g.id) ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}</select><span class="inline-error" data-error="genre_id"></span></label>
            <label>Длительность, секунд<input name="duration" type="number" min="1" value="${escapeHtml(track?.duration || '')}" placeholder="240" required><span class="inline-error" data-error="duration"></span></label>
            <label class="wide-field">Описание<textarea name="description" rows="4" placeholder="О чём этот трек?">${escapeHtml(track?.description || '')}</textarea><span class="inline-error" data-error="description"></span></label>
            ${edit ? '' : `<label class="wide-field file-field">Аудиофайл<input name="audio" type="file" accept="audio/mpeg,audio/wav,audio/ogg,.mp3,.wav,.ogg" required><span class="file-hint">MP3, WAV или OGG · до 50 МБ</span><span class="inline-error" data-error="audio"></span></label><label class="wide-field file-field">Обложка<input name="cover" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png"><span class="file-hint">JPG или PNG · до 5 МБ</span><span class="inline-error" data-error="cover"></span></label>`}
        </div>${formMessage()}<div class="action-row"><a class="button button-light" href="${edit ? `#tracks/${track.id}` : '#tracks'}">Отмена</a><button class="button button-primary" type="submit">${edit ? 'Сохранить изменения' : 'Отправить на модерацию'} <b>→</b></button></div></form>`;
}

function adminView(items = []) {
    const body = items.length ? items.map(track => `<div class="moderation-row"><div class="moderation-cover">${track.cover_path ? '' : 'H'}</div><div class="moderation-main"><b>${escapeHtml(track.title)}</b><span>${escapeHtml(track.artist?.name || '—')} · ${escapeHtml(track.genre?.name || '—')}</span></div><span class="status status-pending">${escapeHtml(statusLabel(track.status))}</span><div class="action-row"><button class="button button-success" data-action="moderate" data-id="${track.id}" data-status="published">Одобрить</button><button class="button button-danger" data-action="moderate" data-id="${track.id}" data-status="rejected">Отклонить</button></div></div>`).join('') : `<div class="empty-state"><span class="empty-icon">✓</span><h3>Очередь пуста</h3><p>Новых треков на модерации нет.</p></div>`;
    return `<div class="page-intro"><span class="eyebrow">ADMIN SPACE</span><h1>Модерация</h1><p>Проверь новые релизы перед публикацией в каталоге.</p></div><section class="moderation-panel">${body}</section>`;
}

function statsView(data = {}) {
    const byStatus = data.by_status || {};
    return `<div class="page-intro"><span class="eyebrow">ARTIST INSIGHTS</span><h1>Статистика</h1><p>Сводка по твоим трекам на основе данных API.</p></div><div class="stats-grid"><div class="stat-card"><span>Треков</span><b>${data.tracks || 0}</b><small>в выбранном периоде</small></div><div class="stat-card accent"><span>Прослушиваний</span><b>${data.plays || 0}</b><small>всего засчитано</small></div><div class="stat-card"><span>Лайков</span><b>${data.likes || 0}</b><small>на опубликованных треках</small></div><div class="stat-card"><span>Комментариев</span><b>${data.comments || 0}</b><small>всего</small></div></div><section class="insight-panel"><div><span class="eyebrow">STATUS MIX</span><h2>Состояние релизов</h2></div><div class="status-bars">${[['pending','На модерации'],['published','Опубликованы'],['rejected','Отклонены']].map(([key,label]) => `<div class="status-bar"><span>${label}</span><b>${byStatus[key] || 0}</b><i style="--value:${Math.min(100, Number(byStatus[key] || 0) * 12)}%"></i></div>`).join('')}</div></section>`;
}

function profileView() {
    const user = state.user || {};
    return `<div class="profile-hero"><span class="profile-avatar">${escapeHtml((user.name || 'G').slice(0, 1).toUpperCase())}</span><div><span class="eyebrow">YOUR PROFILE</span><h1>${escapeHtml(user.name || 'Пользователь')}</h1><p>${escapeHtml(roleName(user.role))} · ${escapeHtml(user.email || 'email скрыт')}</p></div></div><section class="profile-panel"><h2>О тебе</h2><p>${escapeHtml(user.bio || 'Добавь описание профиля через API, чтобы рассказать о своём звучании.')}</p><div class="profile-meta"><span>ID профиля <b>#${user.id || '—'}</b></span><span>Роль <b>${escapeHtml(roleName(user.role))}</b></span></div></section>`;
}

function loadingView(label = 'Получаем данные…') { return `<div class="loading-state"><span class="spinner"></span>${escapeHtml(label)}</div>`; }

async function render() {
    const route = currentRoute();
    if (!state.token && !['login', 'register'].includes(route.path)) { go('login'); return; }
    if (state.token && ['login', 'register'].includes(route.path)) { go('tracks'); return; }
    if (['login', 'register'].includes(route.path)) { root.innerHTML = loginView(route.path); bindEvents(); return; }

    const screen = document.querySelector('#screen');
    if (screen) screen.innerHTML = loadingView();
    let content = '', title = 'Обзор';
    try {
        if (route.path === 'tracks') { await ensureGenres(); await loadTracks(route.params); content = tracksView(); }
        else if (route.path.startsWith('tracks/')) { const id = route.path.split('/')[1]; const result = await api(`/tracks/${id}`, { auth: false }); state.currentTrack = result.data || result; content = trackDetailView(state.currentTrack); title = 'Трек'; }
        else if (route.path === 'upload') { await ensureGenres(); content = uploadView(); title = 'Загрузить'; }
        else if (route.path.startsWith('edit/')) { await ensureGenres(); const id = route.path.split('/')[1]; const result = await api(`/tracks/${id}`); content = uploadView(result.data || result); title = 'Редактировать'; }
        else if (route.path === 'admin') { requireRole('admin'); const result = await api('/admin/tracks?status=pending'); content = adminView(result.data || []); title = 'Модерация'; }
        else if (route.path === 'stats') { requireRole('artist', 'admin'); const result = await api('/me/tracks/stats'); content = statsView(result.data || {}); title = 'Статистика'; }
        else if (route.path === 'profile') { if (!state.user) { const result = await api('/me'); state.user = result.data || result; } content = profileView(); title = 'Профиль'; }
        else { go('tracks'); return; }
        root.innerHTML = shell(content, title);
        bindEvents();
    } catch (error) {
        if (error.status === 401) return;
        if (error.status === 403) { root.innerHTML = shell(`<div class="error-state"><span>403</span><h1>Доступ ограничен</h1><p>Эта часть Homeostat доступна другой роли пользователя.</p><a class="button button-primary" href="#tracks">Вернуться в каталог</a></div>`, 'Нет доступа'); bindEvents(); return; }
        root.innerHTML = shell(`<div class="error-state"><span>${error.status || '!'}</span><h1>Не удалось загрузить данные</h1><p>${escapeHtml(error.message)}</p><button class="button button-primary" data-action="retry">Повторить</button></div>`, 'Ошибка'); bindEvents();
    }
}

function requireRole(...roles) { if (!state.user || !roles.includes(state.user.role)) throw new ApiError('Недостаточно прав.', 403); }

async function ensureGenres() { if (!state.genres.length) { const result = await api('/genres', { auth: false }); state.genres = result.data || []; } }
async function loadTracks(params = new URLSearchParams()) {
    const filters = state.list.filters;
    ['q', 'genre_id', 'sort', 'direction'].forEach(key => { if (params.has(key)) filters[key] = params.get(key); });
    filters.page = Number(params.get('page') || filters.page || 1);
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined));
    query.set('per_page', '8');
    const result = await api(`/tracks?${query.toString()}`, { auth: false });
    state.list.items = result.data || [];
    state.list.meta = result.meta || {};
}

function applyErrors(form, error) {
    Object.entries(error.errors || {}).forEach(([field, messages]) => { const node = form.querySelector(`[data-error="${field}"]`); if (node) node.textContent = messages[0] || ''; });
    const message = form.querySelector('[data-form-message]'); if (message) { message.textContent = error.message; message.classList.add('is-error'); }
}

function validate(form, fields) {
    let valid = true;
    fields.forEach(field => { const input = form.elements[field]; const node = form.querySelector(`[data-error="${field}"]`); if (!input || !node) return; if (!input.value || (input.type === 'file' && !input.files.length)) { node.textContent = 'Заполните поле.'; valid = false; } else node.textContent = ''; });
    return valid;
}

function bindEvents() {
    document.querySelectorAll('[data-action="toggle-menu"]').forEach(btn => btn.addEventListener('click', () => document.querySelector('.sidebar')?.classList.toggle('is-open')));
    document.querySelectorAll('[data-action="logout"]').forEach(btn => btn.addEventListener('click', async () => { try { await api('/logout'); } catch {} clearAuth(); go('login'); notify('Вы вышли из аккаунта', 'success'); }));
    document.querySelectorAll('[data-action="retry"]').forEach(btn => btn.addEventListener('click', render));
    document.querySelectorAll('[data-action="page"]').forEach(btn => btn.addEventListener('click', () => { if (!btn.disabled) { const next = Number(btn.dataset.page); state.list.filters.page = next; go(`tracks?page=${next}`); } }));
    document.querySelectorAll('[data-action="play"]').forEach(btn => btn.addEventListener('click', async (event) => { event.preventDefault(); event.stopPropagation(); try { await api(`/tracks/${btn.dataset.id}/play`, { auth: Boolean(state.token), method: 'POST' }); notify('Прослушивание засчитано', 'success'); } catch (error) { notify(error.message, 'error'); } }));
    document.querySelectorAll('[data-action="delete-track"]').forEach(btn => btn.addEventListener('click', () => deleteTrack(btn.dataset.id)));
    document.querySelectorAll('[data-action="moderate"]').forEach(btn => btn.addEventListener('click', () => moderateTrack(btn.dataset.id, btn.dataset.status)));

    const authForm = document.querySelector('#auth-form'); if (authForm) authForm.addEventListener('submit', submitAuth);
    const filterForm = document.querySelector('#filter-form'); if (filterForm) filterForm.addEventListener('submit', (event) => { event.preventDefault(); const data = new FormData(filterForm); state.list.filters = { ...state.list.filters, q: data.get('q') || '', genre_id: data.get('genre_id') || '', sort: data.get('sort') || 'created_at', page: 1 }; go(`tracks?${new URLSearchParams(state.list.filters).toString()}`); });
    const trackForm = document.querySelector('#track-form'); if (trackForm) trackForm.addEventListener('submit', submitTrack);
}

async function submitAuth(event) {
    event.preventDefault(); const form = event.currentTarget; const mode = form.dataset.mode; if (!validate(form, mode === 'register' ? ['name','email','password','password_confirmation'] : ['email','password'])) return;
    const body = Object.fromEntries(new FormData(form).entries());
    try { const result = await api(mode === 'register' ? '/register' : '/login', { method: 'POST', auth: false, body }); setAuth(result); notify(mode === 'register' ? 'Профиль создан' : 'Добро пожаловать', 'success'); go('tracks'); } catch (error) { applyErrors(form, error); }
}

async function submitTrack(event) {
    event.preventDefault(); const form = event.currentTarget; const editId = form.dataset.editId; const fields = ['title','genre_id','duration']; if (!editId) fields.push('audio'); if (!validate(form, fields)) return;
    try {
        let result;
        if (editId) { result = await api(`/tracks/${editId}`, { method: 'PATCH', body: { title: form.elements.title.value, genre_id: form.elements.genre_id.value, duration: form.elements.duration.value, description: form.elements.description.value || null } }); }
        else { const data = new FormData(form); result = await api('/tracks', { method: 'POST', body: data }); }
        const track = result.data || result; notify(editId ? 'Изменения сохранены' : 'Трек отправлен на модерацию', 'success'); go(`tracks/${track.id}`);
    } catch (error) { applyErrors(form, error); }
}

async function deleteTrack(id) { if (!window.confirm('Удалить этот трек и связанные записи?')) return; try { await api(`/tracks/${id}`, { method: 'DELETE' }); notify('Трек удалён', 'success'); go('tracks'); } catch (error) { notify(error.message, 'error'); } }
async function moderateTrack(id, status) { const reason = status === 'rejected' ? window.prompt('Укажите причину отклонения:') : null; if (status === 'rejected' && !reason) return; try { await api(`/admin/tracks/${id}/status`, { method: 'PATCH', body: { status, ...(reason ? { reason } : {}) } }); notify(status === 'published' ? 'Трек опубликован' : 'Трек отклонён', 'success'); render(); } catch (error) { notify(error.message, 'error'); } }

window.addEventListener('hashchange', render);
render();
