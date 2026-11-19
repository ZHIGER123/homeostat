// One audio element for all pages; rendering or collapsing the UI never restarts playback.
export function createPlayer({ url, loadTrack, registerPlay, escapeHtml, notify }) {
    const audio = new Audio();
    audio.preload = 'metadata';
    let track = null, trackId = null, counted = false, seeking = false;
    let minimized = localStorage.getItem('homeostat_player_minimized') === 'true';
    const time = seconds => Number.isFinite(seconds) ? `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}` : '0:00';
    function refresh() {
        const bar = document.querySelector('[data-player]');
        if (bar) {
            bar.classList.toggle('is-playing', !audio.paused && !audio.ended);
            const title = bar.querySelector('[data-player-title]'); if (title) title.textContent = track?.title || `Трек №${trackId}`;
            const artist = bar.querySelector('[data-player-artist]'); if (artist) artist.textContent = track?.artist?.name || 'Homeostat';
            const link = bar.querySelector('[data-player-link]'); if (link) link.href = `#tracks/${trackId}`;
            const play = bar.querySelector('[data-player-play]'); if (play) { play.textContent = audio.paused ? '▶' : '❚❚'; play.setAttribute('aria-label', audio.paused ? 'Воспроизвести' : 'Пауза'); }
            const current = bar.querySelector('[data-player-current]'); if (current && !seeking) current.textContent = time(audio.currentTime);
            const duration = bar.querySelector('[data-player-duration]'); if (duration) duration.textContent = time(audio.duration);
            const seek = bar.querySelector('[data-player-seek]');
            if (seek && !seeking) { seek.disabled = !Number.isFinite(audio.duration) || audio.duration <= 0; seek.value = !seek.disabled ? audio.currentTime / audio.duration * 1000 : 0; }
            bar.querySelector('[data-player-repeat]')?.classList.toggle('is-active', audio.loop);
        }
        document.querySelectorAll('[data-action="play"]').forEach(button => {
            const playing = String(button.dataset.id) === String(trackId) && !audio.paused;
            button.textContent = playing ? '❚❚' : '▶'; button.setAttribute('aria-label', playing ? 'Пауза' : 'Воспроизвести');
        });
    }
    function markup() {
        if (!trackId) return '';
        const cover = track?.cover_path ? url(`/tracks/${trackId}/cover`) : '';
        const disc = `<div class="player-disc ${cover ? 'has-cover' : ''}" ${cover ? `style="background-image:url('${escapeHtml(cover)}')"` : ''}><span>H</span></div>`;
        if (minimized) return `<aside class="global-player is-minimized" data-player aria-label="Свернутый музыкальный плеер"><button class="mini-player-expand" type="button" data-player-expand aria-label="Развернуть плеер" title="Развернуть плеер">${disc}<span class="mini-player-label"><b data-player-title></b><small data-player-artist></small></span></button><button class="mini-player-play" type="button" data-player-play aria-label="Воспроизвести">▶</button></aside>`;
        return `<aside class="global-player" data-player aria-label="Музыкальный плеер"><div class="player-info">${disc}<a href="#tracks/${trackId}" data-player-link><b data-player-title></b><small data-player-artist></small></a></div><div class="player-center"><div class="player-actions"><button type="button" data-player-back aria-label="Назад на 10 секунд">−10</button><button type="button" data-player-play aria-label="Воспроизвести">▶</button><button type="button" data-player-forward aria-label="Вперёд на 10 секунд">+10</button><button type="button" data-player-repeat aria-label="Повтор трека" title="Повтор">↻</button></div><div class="player-timeline"><span data-player-current>0:00</span><input data-player-seek type="range" min="0" max="1000" value="0" aria-label="Позиция трека"><span data-player-duration>0:00</span></div></div><div class="player-end"><label class="player-volume">♪ <input data-player-volume type="range" min="0" max="1" step="0.01" value="${audio.volume}" aria-label="Громкость"></label><button class="player-collapse" type="button" data-player-collapse aria-label="Свернуть плеер" title="Свернуть плеер">⌄</button></div></aside>`;
    }
    function mount() {
        const slot = document.querySelector('#player-slot'); if (!slot) return;
        slot.innerHTML = markup(); const bar = slot.querySelector('[data-player]');
        if (!bar) { refresh(); return; }
        bar.querySelector('[data-player-play]').onclick = () => audio.paused ? audio.play().catch(() => notify('Не удалось воспроизвести аудио.', 'error')) : audio.pause();
        const collapse = bar.querySelector('[data-player-collapse]'); if (collapse) collapse.onclick = () => { minimized = true; localStorage.setItem('homeostat_player_minimized', 'true'); mount(); };
        const expand = bar.querySelector('[data-player-expand]'); if (expand) expand.onclick = () => { minimized = false; localStorage.setItem('homeostat_player_minimized', 'false'); mount(); };
        const back = bar.querySelector('[data-player-back]'); if (back) back.onclick = () => { audio.currentTime = Math.max(0, audio.currentTime - 10); };
        const forward = bar.querySelector('[data-player-forward]'); if (forward) forward.onclick = () => { if (Number.isFinite(audio.duration)) audio.currentTime = Math.min(Math.max(0, audio.duration - 0.01), audio.currentTime + 10); };
        const repeat = bar.querySelector('[data-player-repeat]'); if (repeat) repeat.onclick = () => { audio.loop = !audio.loop; refresh(); };
        const seek = bar.querySelector('[data-player-seek]');
        if (seek) {
            // Preview while dragging; perform only one HTTP seek when the user releases.
            seek.oninput = e => { seeking = true; if (Number.isFinite(audio.duration)) bar.querySelector('[data-player-current]').textContent = time(audio.duration * Number(e.target.value) / 1000); };
            seek.onchange = e => { if (Number.isFinite(audio.duration) && audio.duration > 0) audio.currentTime = Math.min(Math.max(0, audio.duration - 0.01), audio.duration * Number(e.target.value) / 1000); seeking = false; refresh(); };
            seek.onblur = () => { seeking = false; refresh(); };
        }
        const volume = bar.querySelector('[data-player-volume]'); if (volume) volume.oninput = e => { audio.volume = Number(e.target.value); };
        refresh();
    }
    async function play(id) {
        id = String(id);
        if (id === trackId) { if (audio.paused) await audio.play(); else audio.pause(); return; }
        trackId = id; track = null; counted = false; seeking = false; audio.src = url(`/tracks/${id}/stream`); mount();
        loadTrack(id).then(value => { if (trackId === id) { track = value; mount(); } }).catch(() => {});
        await audio.play();
    }
    audio.addEventListener('playing', () => { if (!counted && trackId) { counted = true; registerPlay(trackId).catch(() => {}); } refresh(); });
    ['pause', 'timeupdate', 'durationchange', 'volumechange', 'seeked'].forEach(event => audio.addEventListener(event, refresh));
    audio.addEventListener('ended', () => { counted = false; refresh(); });
    audio.addEventListener('error', () => { if (trackId) notify('Не удалось загрузить аудио. Проверьте файл и подключение.', 'error'); refresh(); });
    return { play, mount };
}
