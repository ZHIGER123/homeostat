// One audio element for all pages; rendering the interface never restarts playback.
export function createPlayer({ url, loadTrack, registerPlay, escapeHtml, notify }) {
    const audio = new Audio();
    let track = null, trackId = null, counted = false;
    const time = seconds => Number.isFinite(seconds) ? `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}` : '0:00';
    function refresh() {
        const bar = document.querySelector('[data-player]');
        if (bar) {
            bar.classList.toggle('is-playing', !audio.paused);
            bar.querySelector('[data-player-title]').textContent = track?.title || `Трек №${trackId}`;
            bar.querySelector('[data-player-artist]').textContent = track?.artist?.name || 'Homeostat';
            const link = bar.querySelector('[data-player-link]'); link.href = `#tracks/${trackId}`;
            bar.querySelector('[data-player-play]').textContent = audio.paused ? '▶' : '❚❚';
            bar.querySelector('[data-player-play]').setAttribute('aria-label', audio.paused ? 'Воспроизвести' : 'Пауза');
            bar.querySelector('[data-player-current]').textContent = time(audio.currentTime);
            bar.querySelector('[data-player-duration]').textContent = time(audio.duration);
            const seek = bar.querySelector('[data-player-seek]');
            if (document.activeElement !== seek) seek.value = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.currentTime / audio.duration * 1000 : 0;
            bar.querySelector('[data-player-repeat]').classList.toggle('is-active', audio.loop);
        }
        document.querySelectorAll('[data-action="play"]').forEach(button => {
            const playing = String(button.dataset.id) === String(trackId) && !audio.paused;
            button.textContent = playing ? '❚❚' : '▶'; button.setAttribute('aria-label', playing ? 'Пауза' : 'Воспроизвести');
        });
    }
    function markup() {
        if (!trackId) return '';
        const cover = track?.cover_path ? url(`/tracks/${trackId}/cover`) : '';
        return `<aside class="global-player" data-player aria-label="Музыкальный плеер"><div class="player-info"><div class="player-disc ${cover ? 'has-cover' : ''}" ${cover ? `style="background-image:url('${escapeHtml(cover)}')"` : ''}><span>H</span></div><a href="#tracks/${trackId}" data-player-link><b data-player-title></b><small data-player-artist></small></a></div><div class="player-center"><div class="player-actions"><button type="button" data-player-back aria-label="Назад на 10 секунд">−10</button><button type="button" data-player-play aria-label="Воспроизвести">▶</button><button type="button" data-player-forward aria-label="Вперёд на 10 секунд">+10</button><button type="button" data-player-repeat aria-label="Повтор трека" title="Повтор">↻</button></div><div class="player-timeline"><span data-player-current>0:00</span><input data-player-seek type="range" min="0" max="1000" value="0" aria-label="Позиция трека"><span data-player-duration>0:00</span></div></div><label class="player-volume">♪ <input data-player-volume type="range" min="0" max="1" step="0.01" value="${audio.volume}" aria-label="Громкость"></label></aside>`;
    }
    function mount() {
        const slot = document.querySelector('#player-slot'); if (!slot) return;
        slot.innerHTML = markup(); const bar = slot.querySelector('[data-player]');
        if (!bar) { refresh(); return; }
        bar.querySelector('[data-player-play]').onclick = () => audio.paused ? audio.play().catch(() => notify('Не удалось воспроизвести аудио.', 'error')) : audio.pause();
        bar.querySelector('[data-player-back]').onclick = () => { audio.currentTime = Math.max(0, audio.currentTime - 10); };
        bar.querySelector('[data-player-forward]').onclick = () => { if (Number.isFinite(audio.duration)) audio.currentTime = Math.min(audio.duration, audio.currentTime + 10); };
        bar.querySelector('[data-player-repeat]').onclick = () => { audio.loop = !audio.loop; refresh(); };
        bar.querySelector('[data-player-seek]').oninput = e => { if (Number.isFinite(audio.duration)) audio.currentTime = audio.duration * Number(e.target.value) / 1000; };
        bar.querySelector('[data-player-volume]').oninput = e => { audio.volume = Number(e.target.value); };
        refresh();
    }
    async function play(id) {
        id = String(id);
        if (id === trackId) { if (audio.paused) await audio.play(); else audio.pause(); return; }
        trackId = id; track = null; counted = false; audio.src = url(`/tracks/${id}/stream`); mount();
        // Optional metadata does not prevent streaming if the detail API is unavailable.
        loadTrack(id).then(value => { if (trackId === id) { track = value; mount(); } }).catch(() => {});
        await audio.play();
    }
    audio.addEventListener('playing', () => { if (!counted && trackId) { counted = true; registerPlay(trackId).catch(() => {}); } refresh(); });
    ['pause', 'timeupdate', 'durationchange', 'volumechange'].forEach(event => audio.addEventListener(event, refresh));
    audio.addEventListener('ended', () => { counted = false; refresh(); });
    audio.addEventListener('error', () => { if (trackId) notify('Аудиофайл не найден или недоступен.', 'error'); refresh(); });
    return { play, mount };
}
