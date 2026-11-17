const API_BASE = (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, '');
const TOKEN_KEY = 'homeostat_token';
const USER_KEY = 'homeostat_user';

const state = {
    token: localStorage.getItem(TOKEN_KEY),
    user: readUser(),
    genres: [],
    currentTrack: null,
    currentTrackLiked: false,
    list: { items: [], meta: {}, filters: { q: '', genre_id: '', sort: 'created_at', direction: 'desc', page: 1 } },
};

const root = document.querySelector('#app-root');

function readUser() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { return null; }
}

function dataOf(payload) { return payload?.data ?? payload; }
function itemsOf(payload) { return Array.isArray(payload?.data) ? payload.data : []; }
function escapeHtml(value = '') { return String(value).replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
function roleName(role) { return ({ admin: 'Администратор', artist: 'Исполнитель', listener: 'Слушатель' })[role] || role || 'Гость'; }
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

function shell(content, title = 'Обзор') {
    const user = state.user; const isAdmin = user?.role === 'admin'; const isArtist = user?.role === 'artist' || isAdmin;
    return `<div class="app-shell">
        <aside class="sidebar"><a class="brand" href="#tracks" aria-label="Homeostat"><span class="brand-mark"><span></span><span></span><span></span><span></span></span><span>homeostat</span></a>
            <nav class="primary-nav" aria-label="Основная навигация">
                <a class="nav-link ${isActive('tracks')}" href="#tracks"><span>◉</span> Обзор</a>
                <a class="nav-link ${isActive('feed')}" href="#feed"><span>⌁</span> Моя лента</a>
                <a class="nav-link ${isActive('favorites')}" href="#favorites"><span>♡</span> Избранное</a>
                <a class="nav-link ${isActive('playlists')}" href="#playlists"><span>▤</span> Плейлисты</a>
                <a class="nav-link ${isActive('upload')} ${isArtist ? '' : 'is-hidden'}" href="#upload"><span>＋</span> Загрузить</a>
                <a class="nav-link ${isActive('stats')} ${isArtist ? '' : 'is-hidden'}" href="#stats"><span>⌁</span> Статистика</a>
                <a class="nav-link ${isActive('admin')} ${isAdmin ? '' : 'is-hidden'}" href="#admin"><span>◆</span> Администрирование</a>
            </nav>
            <div class="sidebar-bottom"><div class="mini-note"><span class="live-dot"></span><span>API online mode</span></div><button class="nav-link nav-button" data-action="logout"><span>↪</span> Выйти</button></div>
        </aside>
        <div class="main-column"><header class="topbar"><button class="mobile-menu" data-action="toggle-menu" aria-label="Открыть меню">☰</button><div class="breadcrumbs"><span>Homeostat</span><b>/</b><strong>${escapeHtml(title)}</strong></div><div class="top-actions"><a class="user-chip" href="#profile" title="Открыть профиль"><span class="avatar">${escapeHtml((user?.name || 'G').slice(0, 1).toUpperCase())}</span><span class="user-chip-copy"><b>${escapeHtml(user?.name || 'Гость')}</b><small>${escapeHtml(roleName(user?.role))}</small></span></a></div></header><main class="content" id="screen">${content}</main></div>
    </div><div id="toast" class="toast" role="status"></div>`;
}

function formMessage(message = '') { return `<p class="form-message" data-form-message>${escapeHtml(message)}</p>`; }
function fieldError(errors, field) { return errors?.[field]?.[0] ? `<small class="field-error">${escapeHtml(errors[field][0])}</small>` : ''; }

function loginView(mode = 'login', error = '') {
    const register = mode === 'register';
    return `<div class="auth-layout"><section class="auth-art"><div class="orb orb-one"></div><div class="orb orb-two"></div><span class="eyebrow">SOUND • STATE • HOME</span><h1>Найди звук,<br><em>который остаётся.</em></h1><p>Homeostat собирает музыку вокруг твоего состояния — от первой ноты до собственного плейлиста.</p><div class="wave-hero" aria-hidden="true">${Array.from({ length: 32 }, (_, i) => `<i style="--h:${20 + ((i * 17) % 76)}%"></i>`).join('')}</div></section><section class="auth-card-wrap"><div class="auth-card"><div class="auth-card-head"><span class="eyebrow">${register ? 'NEW ACCOUNT' : 'WELCOME BACK'}</span><h2>${register ? 'Создай свой профиль' : 'С возвращением'}</h2><p>${register ? 'Регистрация занимает меньше минуты.' : 'Войдите, чтобы продолжить слушать.'}</p></div><form id="auth-form" data-mode="${register ? 'register' : 'login'}" novalidate>
        ${register ? `<label>Имя<input name="name" autocomplete="name" placeholder="Как к тебе обращаться" required><span class="inline-error" data-error="name"></span></label>` : ''}<label>Email<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required><span class="inline-error" data-error="email"></span></label><label>Пароль<input name="password" type="password" autocomplete="${register ? 'new-password' : 'current-password'}" placeholder="Минимум 8 символов" required><span class="inline-error" data-error="password"></span></label>
        ${register ? `<label>Роль<select name="role"><option value="listener">Слушатель</option><option value="artist">Исполнитель</option></select></label><label>Повторите пароль<input name="password_confirmation" type="password" autocomplete="new-password" placeholder="Ещё раз" required><span class="inline-error" data-error="password_confirmation"></span></label>` : ''}${formMessage(error)}<button class="button button-primary button-wide" type="submit"><span>${register ? 'Создать профиль' : 'Войти в Homeostat'}</span><b>→</b></button></form><div class="auth-switch">${register ? 'Уже есть аккаунт?' : 'Впервые здесь?'} <a href="#${register ? 'login' : 'register'}">${register ? 'Войти' : 'Создать профиль'}</a></div></div></section></div>`;
}

function tracksView() {
    const filters = state.list.filters; const meta = state.list.meta || {};
    const cards = state.list.items.length ? state.list.items.map(trackCard).join('') : `<div class="empty-state"><span class="empty-icon">⌁</span><h3>Пока тихо</h3><p>Измените фильтр или загрузите первый трек.</p></div>`;
    return `<section class="hero-banner"><div><span class="eyebrow">YOUR DAILY FREQUENCY</span><h1>Музыка, которая<br><em>на твоей волне.</em></h1><p>Открывай новые треки, сохраняй состояние и слушай без лишнего шума.</p></div><div class="hero-disc" aria-hidden="true"><div class="disc-inner"></div><span>H</span></div></section><section class="section-head"><div><span class="eyebrow">DISCOVER</span><h2>Каталог треков</h2></div><span class="result-count">${meta.total !== undefined ? `${meta.total} треков` : 'Загрузка…'}</span></section><form id="filter-form" class="filter-bar"><label class="search-field"><span>⌕</span><input name="q" value="${escapeHtml(filters.q)}" placeholder="Поиск по названию или артисту"></label><label><span class="sr-only">Жанр</span><select name="genre_id"><option value="">Все жанры</option>${state.genres.map(g => `<option value="${g.id}" ${String(filters.genre_id) === String(g.id) ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}</select></label><label><span class="sr-only">Сортировка</span><select name="sort"><option value="created_at" ${filters.sort === 'created_at' ? 'selected' : ''}>Новейшие</option><option value="plays_count" ${filters.sort === 'plays_count' ? 'selected' : ''}>По прослушиваниям</option><option value="likes_count" ${filters.sort === 'likes_count' ? 'selected' : ''}>По лайкам</option></select></label><button class="button button-dark" type="submit">Найти</button></form><div class="cards-grid">${cards}</div>${pagination(meta)}`;
}

function trackCard(track) {
    const cover = mediaUrl(track.cover_path); const artist = track.artist?.name || 'Неизвестный артист';
    return `<article class="track-card" data-track-id="${track.id}"><div class="cover-art ${cover ? 'has-image' : ''}" ${cover ? `style="background-image:url('${escapeHtml(cover)}')"` : ''}><a class="cover-link" href="#tracks/${track.id}" aria-label="Открыть ${escapeHtml(track.title)}"><span>${cover ? '' : 'H'}</span></a><button class="play-button" data-action="play" data-id="${track.id}" type="button" aria-label="Слушать">▶</button></div><div class="track-card-copy"><a href="#tracks/${track.id}" class="track-title">${escapeHtml(track.title)}</a><p>${escapeHtml(artist)}</p><div class="track-meta"><span>${escapeHtml(track.genre?.name || 'Без жанра')}</span><span>${formatDuration(track.duration)}</span></div></div></article>`;
}

function pagination(meta) {
    if (!meta.last_page || meta.last_page <= 1) return '';
    const page = meta.current_page || 1;
    return `<div class="pagination"><button class="button button-ghost" data-action="page" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>←</button><span>Страница <b>${page}</b> из ${meta.last_page}</span><button class="button button-ghost" data-action="page" data-page="${page + 1}" ${page >= meta.last_page ? 'disabled' : ''}>→</button></div>`;
}

function trackDetailView(track, comments = []) {
    const cover = mediaUrl(track.cover_path); const canEdit = state.user && (state.user.role === 'admin' || state.user.id === track.artist?.id); const liked = state.currentTrackLiked;
    const commentsHtml = comments.length ? comments.map(commentView).join('') : `<div class="empty-inline">Комментариев пока нет. Будьте первым.</div>`;
    return `<div class="back-row"><a href="#tracks">← Вернуться к каталогу</a></div><section class="detail-panel"><div class="detail-cover ${cover ? 'has-image' : ''}" ${cover ? `style="background-image:url('${escapeHtml(cover)}')"` : ''}><span>${cover ? '' : 'H'}</span><button class="play-button play-large" data-action="play" data-id="${track.id}" type="button">▶</button></div><div class="detail-copy"><span class="eyebrow">TRACK / ${escapeHtml(track.status === 'rejected' ? `${statusLabel(track.status)}: ${track.reject_reason || ''}` : statusLabel(track.status))}</span><h1>${escapeHtml(track.title)}</h1><p class="detail-artist">${escapeHtml(track.artist?.name || 'Неизвестный артист')}</p><p>${escapeHtml(track.description || 'У этого трека пока нет описания.')}</p><div class="detail-stats"><span><b>${track.plays_count || 0}</b> прослушиваний</span><span><b>${track.likes_count || 0}</b> лайков</span><span><b>${track.comments_count || comments.length || 0}</b> комментариев</span><span><b>${formatDuration(track.duration)}</b> длительность</span></div><audio class="audio-player" controls preload="none" data-track-audio="${track.id}" src="${escapeHtml(`${API_BASE}/tracks/${track.id}/stream`)}"></audio><div class="action-row"><button class="button ${liked ? 'button-success' : 'button-light'}" data-action="like" data-id="${track.id}" data-liked="${liked}">${liked ? '♥ В избранном' : '♡ Добавить в избранное'}</button><button class="button button-light" data-action="follow" data-id="${track.artist?.id || ''}" data-following="false">＋ Подписаться</button>${canEdit ? `<a class="button button-light" href="#edit/${track.id}">Редактировать</a><button class="button button-danger" data-action="delete-track" data-id="${track.id}">Удалить</button>` : ''}</div></div></section><section class="comments-panel"><div class="section-head compact"><div><span class="eyebrow">COMMUNITY</span><h2>Комментарии</h2></div></div>${state.token ? `<form id="comment-form" class="inline-form"><textarea name="body" rows="2" minlength="2" maxlength="1000" placeholder="Оставьте добрый комментарий…" required></textarea><button class="button button-primary" type="submit">Опубликовать</button></form>` : '<p class="muted-copy">Войдите, чтобы оставить комментарий.</p>'}<div class="comment-list">${commentsHtml}</div></section>`;
}

function commentView(comment) {
    const author = comment.author || comment.user || {}; const owner = state.user && (state.user.role === 'admin' || state.user.id === (comment.user_id || author.id));
    return `<article class="comment-item"><div class="avatar avatar-small">${escapeHtml((author.name || 'U').slice(0, 1).toUpperCase())}</div><div class="comment-body"><div class="comment-head"><b>${escapeHtml(author.name || 'Пользователь')}</b><small>${escapeHtml(comment.created_at || '')}</small></div><p>${escapeHtml(comment.body)}</p></div>${owner ? `<button class="icon-button" title="Удалить комментарий" data-action="delete-comment" data-id="${comment.id}">×</button>` : ''}</article>`;
}

function uploadView(track = null) {
    const edit = Boolean(track);
    return `<div class="page-intro"><span class="eyebrow">${edit ? 'EDIT TRACK' : 'ARTIST STUDIO'}</span><h1>${edit ? 'Настрой звучание' : 'Добавь свой трек'}</h1><p>${edit ? 'Обнови данные — после изменений трек снова пройдёт модерацию.' : 'Артист может загрузить свой аудиофайл, выбрать жанр и отправить релиз на модерацию.'}</p></div><form id="track-form" class="form-panel" data-edit-id="${edit ? track.id : ''}" novalidate><div class="form-grid"><label>Название трека<input name="title" value="${escapeHtml(track?.title || '')}" placeholder="Например, Night Drive" required><span class="inline-error" data-error="title"></span></label><label>Жанр<select name="genre_id" required><option value="">Выберите жанр</option>${state.genres.map(g => `<option value="${g.id}" ${String(track?.genre?.id) === String(g.id) ? 'selected' : ''}>${escapeHtml(g.name)}</option>`).join('')}</select><span class="inline-error" data-error="genre_id"></span></label><label>Длительность, секунд<input name="duration" type="number" min="1" value="${escapeHtml(track?.duration || '')}" placeholder="240" required><span class="inline-error" data-error="duration"></span></label><label class="wide-field">Описание<textarea name="description" rows="4" placeholder="О чём этот трек?">${escapeHtml(track?.description || '')}</textarea><span class="inline-error" data-error="description"></span></label>${edit ? '' : `<label class="wide-field file-field">Аудиофайл<input name="audio" type="file" accept="audio/mpeg,audio/wav,audio/ogg,.mp3,.wav,.ogg" required><span class="file-hint">MP3, WAV или OGG · до 50 МБ</span><span class="inline-error" data-error="audio"></span></label><label class="wide-field file-field">Обложка<input name="cover" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png"><span class="file-hint">JPG или PNG · до 5 МБ</span><span class="inline-error" data-error="cover"></span></label>`}</div>${formMessage()}<div class="action-row"><a class="button button-light" href="${edit ? `#tracks/${track.id}` : '#tracks'}">Отмена</a><button class="button button-primary" type="submit">${edit ? 'Сохранить изменения' : 'Отправить на модерацию'} <b>→</b></button></div></form>`;
}

function statsView(data = {}, filters = {}) {
    const byStatus = data.by_status || {};
    return `<div class="page-intro"><span class="eyebrow">ARTIST INSIGHTS</span><h1>Статистика</h1><p>Сводка по твоим трекам на основе данных API.</p></div><form id="stats-filter" class="filter-bar compact-filter"><label>С даты<input type="date" name="from" value="${escapeHtml(filters.from || '')}"></label><label>По дату<input type="date" name="to" value="${escapeHtml(filters.to || '')}"></label><button class="button button-dark" type="submit">Обновить</button></form><div class="stats-grid"><div class="stat-card"><span>Треков</span><b>${data.tracks || 0}</b><small>за выбранный период</small></div><div class="stat-card accent"><span>Прослушиваний</span><b>${data.plays || 0}</b><small>всего засчитано</small></div><div class="stat-card"><span>Лайков</span><b>${data.likes || 0}</b><small>на опубликованных треках</small></div><div class="stat-card"><span>Комментариев</span><b>${data.comments || 0}</b><small>всего</small></div></div><section class="insight-panel"><div><span class="eyebrow">STATUS MIX</span><h2>Состояние релизов</h2></div><div class="status-bars">${[['pending','На модерации'],['published','Опубликованы'],['rejected','Отклонены']].map(([key,label]) => `<div class="status-bar"><span>${label}</span><b>${byStatus[key] || 0}</b><i style="--value:${Math.min(100, Number(byStatus[key] || 0) * 12)}%"></i></div>`).join('')}</div></section>`;
}

function profileView() {
    const user = state.user || {};
    return `<div class="profile-hero"><span class="profile-avatar">${escapeHtml((user.name || 'G').slice(0, 1).toUpperCase())}</span><div><span class="eyebrow">YOUR PROFILE</span><h1>${escapeHtml(user.name || 'Пользователь')}</h1><p>${escapeHtml(roleName(user.role))} · ${escapeHtml(user.email || 'email скрыт')}</p></div></div><form id="profile-form" class="form-panel profile-form"><div class="form-grid"><label>Имя<input name="name" value="${escapeHtml(user.name || '')}" required><span class="inline-error" data-error="name"></span></label><label>Аватар<input name="avatar_path" value="${escapeHtml(user.avatar_path || '')}" placeholder="Путь к аватару или URL"></label><label class="wide-field">О себе<textarea name="bio" rows="4" maxlength="1000" placeholder="Расскажите о себе">${escapeHtml(user.bio || '')}</textarea></label></div>${formMessage()}<div class="action-row"><button class="button button-primary" type="submit">Сохранить профиль</button></div></form>`;
}

function playlistListView(items = []) {
    const body = items.length ? items.map(p => `<a class="playlist-card" href="#playlists/${p.id}"><span class="playlist-icon">▤</span><span><b>${escapeHtml(p.title)}</b><small>${p.tracks_count || p.tracks?.length || 0} треков</small></span><span>→</span></a>`).join('') : `<div class="empty-state"><span class="empty-icon">▤</span><h3>Плейлистов пока нет</h3><p>Создайте подборку любимых треков.</p></div>`;
    return `<div class="page-intro"><span class="eyebrow">YOUR COLLECTION</span><h1>Плейлисты</h1><p>Собирайте музыку в собственные подборки и меняйте порядок треков.</p></div><section class="form-panel compact-panel"><form id="playlist-create-form" class="inline-form"><input name="title" placeholder="Название нового плейлиста" required maxlength="255"><button class="button button-primary" type="submit">Создать</button></form></section><section class="playlist-list">${body}</section>`;
}

function playlistDetailView(playlist) {
    const tracks = playlist.tracks || []; const options = state.list.items.filter(track => !tracks.some(item => item.id === track.id)).map(track => `<option value="${track.id}">${escapeHtml(track.title)} — ${escapeHtml(track.artist?.name || '')}</option>`).join('');
    const rows = tracks.length ? tracks.map((track, index) => `<div class="playlist-track"><span class="track-position">${index + 1}</span><span class="playlist-track-copy"><b>${escapeHtml(track.title)}</b><small>${escapeHtml(track.artist?.name || '')}</small></span><button class="icon-button" data-action="playlist-move" data-playlist="${playlist.id}" data-index="${index}" data-direction="up" ${index === 0 ? 'disabled' : ''}>↑</button><button class="icon-button" data-action="playlist-move" data-playlist="${playlist.id}" data-index="${index}" data-direction="down" ${index === tracks.length - 1 ? 'disabled' : ''}>↓</button><button class="icon-button danger-icon" data-action="playlist-remove" data-playlist="${playlist.id}" data-track="${track.id}">×</button></div>`).join('') : `<div class="empty-inline">В плейлисте пока нет треков.</div>`;
    return `<div class="back-row"><a href="#playlists">← Все плейлисты</a></div><div class="page-intro"><span class="eyebrow">PLAYLIST</span><h1>${escapeHtml(playlist.title)}</h1><p>${escapeHtml(playlist.description || 'Личная подборка Homeostat.')}</p></div><form id="playlist-edit-form" class="form-panel compact-panel" data-id="${playlist.id}"><div class="inline-form"><input name="title" value="${escapeHtml(playlist.title)}" maxlength="255" required><input name="description" value="${escapeHtml(playlist.description || '')}" maxlength="2000" placeholder="Описание"><button class="button button-light" type="submit">Переименовать</button><button class="button button-danger" type="button" data-action="delete-playlist" data-id="${playlist.id}">Удалить</button></div>${formMessage()}</form><section class="form-panel compact-panel"><form id="playlist-add-form" class="inline-form" data-id="${playlist.id}"><select name="track_id" required><option value="">Добавить опубликованный трек</option>${options}</select><button class="button button-primary" type="submit">Добавить</button></form></section><section class="playlist-tracks">${rows}</section>`;
}

function trackCollectionView(items, title, subtitle) { return `<div class="page-intro"><span class="eyebrow">${escapeHtml(title.toUpperCase())}</span><h1>${escapeHtml(title)}</h1><p>${escapeHtml(subtitle)}</p></div><div class="cards-grid">${items.length ? items.map(trackCard).join('') : `<div class="empty-state"><span class="empty-icon">⌁</span><h3>Здесь пока пусто</h3><p>Когда появятся треки, они будут отображаться здесь.</p></div>`}</div>`; }

function adminView(items = []) {
    const moderation = items.length ? items.map(track => `<div class="moderation-row"><div class="moderation-cover">${track.cover_path ? '' : 'H'}</div><div class="moderation-main"><b>${escapeHtml(track.title)}</b><span>${escapeHtml(track.artist?.name || '—')} · ${escapeHtml(track.genre?.name || '—')}</span></div><span class="status status-pending">${escapeHtml(statusLabel(track.status))}</span><div class="action-row"><button class="button button-success" data-action="moderate" data-id="${track.id}" data-status="published">Одобрить</button><button class="button button-danger" data-action="moderate" data-id="${track.id}" data-status="rejected">Отклонить</button></div></div>`).join('') : `<div class="empty-state"><span class="empty-icon">✓</span><h3>Очередь пуста</h3><p>Новых треков на модерации нет.</p></div>`;
    const genres = state.genres.map(g => `<div class="admin-list-row"><span><b>${escapeHtml(g.name)}</b><small>${escapeHtml(g.slug || '')}</small></span><button class="icon-button" data-action="edit-genre" data-id="${g.id}" data-name="${escapeHtml(g.name)}">✎</button><button class="icon-button danger-icon" data-action="delete-genre" data-id="${g.id}">×</button></div>`).join('');
    return `<div class="page-intro"><span class="eyebrow">ADMIN SPACE</span><h1>Администрирование</h1><p>Модерация релизов, справочник жанров и блокировка пользователей.</p></div><section class="moderation-panel"><div class="section-head compact"><div><span class="eyebrow">RELEASES</span><h2>Очередь модерации</h2></div></div>${moderation}</section><div class="admin-grid"><section class="form-panel"><span class="eyebrow">GENRES</span><h2>Жанры</h2><form id="genre-form" class="inline-form"><input name="name" placeholder="Новый жанр" required><input name="slug" placeholder="slug (необязательно)"><button class="button button-primary" type="submit">Добавить</button></form><div class="admin-list">${genres || '<div class="empty-inline">Жанров нет.</div>'}</div></section><section class="form-panel"><span class="eyebrow">SAFETY</span><h2>Пользователь</h2><p class="muted-copy">Для блокировки нужен ID пользователя из профиля или API.</p><form id="block-form" class="inline-form"><input name="user_id" type="number" min="1" placeholder="ID пользователя" required><button class="button button-danger" type="submit">Заблокировать</button><button class="button button-light" type="button" data-action="unblock-user">Разблокировать</button></form></section></div>`;
}

function loadingView(label = 'Получаем данные…') { return `<div class="loading-state"><span class="spinner"></span>${escapeHtml(label)}</div>`; }

async function render() {
    const route = currentRoute();
    if (!state.token && !['login', 'register', 'tracks'].includes(route.path)) { go('login'); return; }
    if (state.token && ['login', 'register'].includes(route.path)) { go('tracks'); return; }
    if (['login', 'register'].includes(route.path)) { root.innerHTML = loginView(route.path); bindEvents(); return; }
    const screen = document.querySelector('#screen'); if (screen) screen.innerHTML = loadingView();
    let content = ''; let title = 'Обзор';
    try {
        if (route.path === 'tracks') { await ensureGenres(); await loadTracks(route.params); content = tracksView(); }
        else if (route.path.startsWith('tracks/')) { const id = route.path.split('/')[1]; const result = await api(`/tracks/${id}`, { auth: false }); state.currentTrack = dataOf(result); const comments = itemsOf(await api(`/tracks/${id}/comments`, { auth: false })); state.currentTrackLiked = await checkLiked(id); content = trackDetailView(state.currentTrack, comments); title = 'Трек'; }
        else if (route.path === 'feed') { requireAuth(); const result = await api(`/users/${state.user.id}/feed`); content = trackCollectionView(itemsOf(result), 'Моя лента', 'Новые публикации артистов, на которых вы подписаны.'); title = 'Моя лента'; }
        else if (route.path === 'favorites') { requireAuth(); const result = await api(`/users/${state.user.id}/likes`); content = trackCollectionView(itemsOf(result), 'Избранное', 'Треки, которые вы сохранили лайком.'); title = 'Избранное'; }
        else if (route.path === 'playlists') { requireAuth(); content = playlistListView(itemsOf(await api('/playlists'))); title = 'Плейлисты'; }
        else if (route.path.startsWith('playlists/')) { requireAuth(); await loadTracks(new URLSearchParams()); const result = await api(`/playlists/${route.path.split('/')[1]}`); content = playlistDetailView(dataOf(result)); title = 'Плейлист'; }
        else if (route.path === 'upload') { requireRole('artist', 'admin'); await ensureGenres(); content = uploadView(); title = 'Загрузить'; }
        else if (route.path.startsWith('edit/')) { requireRole('artist', 'admin'); await ensureGenres(); const result = await api(`/tracks/${route.path.split('/')[1]}`); content = uploadView(dataOf(result)); title = 'Редактировать'; }
        else if (route.path === 'admin') { requireRole('admin'); await ensureGenres(); const result = await api('/admin/tracks?status=pending'); content = adminView(itemsOf(result)); title = 'Администрирование'; }
        else if (route.path === 'stats') { requireRole('artist', 'admin'); const filters = { from: route.params.get('from') || '', to: route.params.get('to') || '' }; const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)); const result = await api(`/me/tracks/stats${query.toString() ? `?${query}` : ''}`); content = statsView(dataOf(result), filters); title = 'Статистика'; }
        else if (route.path === 'profile') { requireAuth(); const result = await api('/me'); state.user = dataOf(result); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); content = profileView(); title = 'Профиль'; }
        else { go(state.token ? 'tracks' : 'login'); return; }
        root.innerHTML = shell(content, title); bindEvents();
    } catch (error) {
        if (error.status === 401) return;
        const message = error.status === 403 ? 'Эта часть Homeostat доступна другой роли пользователя.' : readError(error);
        root.innerHTML = shell(`<div class="error-state"><span>${error.status || '!'}</span><h1>${error.status === 403 ? 'Доступ ограничен' : 'Не удалось загрузить данные'}</h1><p>${escapeHtml(message)}</p><button class="button button-primary" data-action="retry">Повторить</button></div>`, error.status === 403 ? 'Нет доступа' : 'Ошибка'); bindEvents();
    }
}

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
    try { return itemsOf(await api(`/users/${state.user.id}/likes`)).some(track => String(track.id) === String(trackId)); } catch { return false; }
}
function applyErrors(form, error) { Object.entries(error.errors || {}).forEach(([field, messages]) => { const node = form.querySelector(`[data-error="${field}"]`); if (node) node.textContent = messages[0] || ''; }); const message = form.querySelector('[data-form-message]'); if (message) { message.textContent = readError(error); message.classList.add('is-error'); } }
function validate(form, fields) { let valid = true; fields.forEach(field => { const input = form.elements[field]; const node = form.querySelector(`[data-error="${field}"]`); if (!input || !node) return; if (!input.value || (input.type === 'file' && !input.files.length)) { node.textContent = 'Заполните поле.'; valid = false; } else node.textContent = ''; }); return valid; }

function bindEvents() {
    document.querySelectorAll('[data-action="toggle-menu"]').forEach(btn => btn.addEventListener('click', () => document.querySelector('.sidebar')?.classList.toggle('is-open')));
    document.querySelectorAll('[data-action="logout"]').forEach(btn => btn.addEventListener('click', async () => { try { await api('/logout'); } catch {} clearAuth(); go('login'); notify('Вы вышли из аккаунта', 'success'); }));
    document.querySelectorAll('[data-action="retry"]').forEach(btn => btn.addEventListener('click', render));
    document.querySelectorAll('[data-action="page"]').forEach(btn => btn.addEventListener('click', () => { if (!btn.disabled) go(`tracks?page=${Number(btn.dataset.page)}`); }));
    document.querySelectorAll('[data-action="play"]').forEach(btn => btn.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); registerPlay(btn.dataset.id).then(() => notify('Прослушивание засчитано', 'success')).catch(error => notify(readError(error), 'error')); }));
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
    document.querySelectorAll('[data-track-audio]').forEach(audio => audio.addEventListener('play', () => { if (!audio.dataset.counted) { audio.dataset.counted = 'true'; registerPlay(audio.dataset.track).catch(() => {}); } }));
    const authForm = document.querySelector('#auth-form'); if (authForm) authForm.addEventListener('submit', submitAuth);
    const filterForm = document.querySelector('#filter-form'); if (filterForm) filterForm.addEventListener('submit', submitFilters);
    const trackForm = document.querySelector('#track-form'); if (trackForm) trackForm.addEventListener('submit', submitTrack);
    const commentForm = document.querySelector('#comment-form'); if (commentForm) commentForm.addEventListener('submit', submitComment);
    const profileForm = document.querySelector('#profile-form'); if (profileForm) profileForm.addEventListener('submit', submitProfile);
    const playlistCreate = document.querySelector('#playlist-create-form'); if (playlistCreate) playlistCreate.addEventListener('submit', createPlaylist);
    const playlistEdit = document.querySelector('#playlist-edit-form'); if (playlistEdit) playlistEdit.addEventListener('submit', editPlaylist);
    const playlistAdd = document.querySelector('#playlist-add-form'); if (playlistAdd) playlistAdd.addEventListener('submit', addPlaylistTrack);
    const statsFilter = document.querySelector('#stats-filter'); if (statsFilter) statsFilter.addEventListener('submit', submitStatsFilter);
    const genreForm = document.querySelector('#genre-form'); if (genreForm) genreForm.addEventListener('submit', createGenre);
    const blockForm = document.querySelector('#block-form'); if (blockForm) blockForm.addEventListener('submit', (event) => { event.preventDefault(); blockUser(true); });
}

async function submitAuth(event) { event.preventDefault(); const form = event.currentTarget; const mode = form.dataset.mode; if (!validate(form, mode === 'register' ? ['name', 'email', 'password', 'password_confirmation'] : ['email', 'password'])) return; try { const result = await api(mode === 'register' ? '/register' : '/login', { method: 'POST', auth: false, body: Object.fromEntries(new FormData(form).entries()) }); setAuth(result); notify(mode === 'register' ? 'Профиль создан' : 'Добро пожаловать', 'success'); go('tracks'); } catch (error) { applyErrors(form, error); } }
async function submitFilters(event) { event.preventDefault(); const data = new FormData(event.currentTarget); state.list.filters = { ...state.list.filters, q: data.get('q') || '', genre_id: data.get('genre_id') || '', sort: data.get('sort') || 'created_at', page: 1 }; go(`tracks?${new URLSearchParams(state.list.filters)}`); }
async function submitTrack(event) { event.preventDefault(); const form = event.currentTarget; const editId = form.dataset.editId; const fields = ['title', 'genre_id', 'duration']; if (!editId) fields.push('audio'); if (!validate(form, fields)) return; try { let result; if (editId) result = await api(`/tracks/${editId}`, { method: 'PATCH', body: { title: form.elements.title.value, genre_id: form.elements.genre_id.value, duration: form.elements.duration.value, description: form.elements.description.value || null } }); else result = await api('/tracks', { method: 'POST', body: new FormData(form) }); const track = dataOf(result); notify(editId ? 'Изменения сохранены и отправлены на модерацию' : 'Трек отправлен на модерацию', 'success'); go(`tracks/${track.id}`); } catch (error) { applyErrors(form, error); } }
async function registerPlay(id) { const result = await api(`/tracks/${id}/play`, { method: 'POST', auth: Boolean(state.token) }); if (result.counted === false) notify('Это прослушивание уже учитывалось в последние 30 секунд', 'info'); return result; }
async function toggleLike(button) { try { const id = button.dataset.id; const liked = button.dataset.liked === 'true'; if (liked) await api(`/users/${state.user.id}/likes/${id}`, { method: 'DELETE' }); else await api(`/users/${state.user.id}/likes/${id}`, { method: 'PUT' }); button.dataset.liked = String(!liked); button.className = `button ${!liked ? 'button-success' : 'button-light'}`; button.textContent = !liked ? '♥ В избранном' : '♡ Добавить в избранное'; notify(!liked ? 'Трек добавлен в избранное' : 'Лайк снят', 'success'); } catch (error) { notify(readError(error), 'error'); } }
async function toggleFollow(button) { const artistId = button.dataset.id; if (!artistId || !state.user) return; try { const following = button.dataset.following === 'true'; if (following) await api(`/users/${state.user.id}/following/${artistId}`, { method: 'DELETE' }); else await api(`/users/${state.user.id}/following/${artistId}`, { method: 'PUT' }); button.dataset.following = String(!following); button.textContent = !following ? '✓ Вы подписаны' : '＋ Подписаться'; notify(!following ? 'Подписка оформлена' : 'Подписка отменена', 'success'); } catch (error) { notify(readError(error), 'error'); } }
async function submitComment(event) { event.preventDefault(); const form = event.currentTarget; try { await api(`/tracks/${state.currentTrack.id}/comments`, { method: 'POST', body: { body: form.elements.body.value } }); notify('Комментарий опубликован', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function deleteComment(id) { if (!window.confirm('Удалить комментарий?')) return; try { await api(`/comments/${id}`, { method: 'DELETE' }); notify('Комментарий удалён', 'success'); render(); } catch (error) { notify(readError(error), 'error'); } }
async function submitProfile(event) { event.preventDefault(); const form = event.currentTarget; try { const result = await api('/me', { method: 'PATCH', body: { name: form.elements.name.value, bio: form.elements.bio.value || null, avatar_path: form.elements.avatar_path.value || null } }); state.user = dataOf(result); localStorage.setItem(USER_KEY, JSON.stringify(state.user)); notify('Профиль обновлён', 'success'); render(); } catch (error) { applyErrors(form, error); } }
async function createPlaylist(event) { event.preventDefault(); const form = event.currentTarget; try { const result = await api('/playlists', { method: 'POST', body: { title: form.elements.title.value } }); notify('Плейлист создан', 'success'); go(`playlists/${dataOf(result).id}`); } catch (error) { applyErrors(form, error); } }
async function editPlaylist(event) { event.preventDefault(); const form = event.currentTarget; try { await api(`/playlists/${form.dataset.id}`, { method: 'PATCH', body: { title: form.elements.title.value, description: form.elements.description.value || null } }); notify('Плейлист обновлён', 'success'); render(); } catch (error) { applyErrors(form, error); } }
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
render();
