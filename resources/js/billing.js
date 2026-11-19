const c = () => window.__homeostat;
const readError = error => error?.message || 'Неизвестная ошибка.';
function planName(plan) { return ({ free: 'Free', artist: 'Artist', artist_pro: 'Artist Pro' })[plan] || plan || 'Free'; }
function money(cents, currency = 'USD') { return cents ? new Intl.NumberFormat('ru-RU', { style: 'currency', currency }).format(Number(cents) / 100) : 'Бесплатно'; }
function hours(seconds) { return seconds == null ? 'Без лимита' : `${(Number(seconds) / 3600).toFixed(seconds % 3600 ? 1 : 0)} ч всего`; }

function pricingView(plans = []) {
    const current = c().state.billing?.plan || c().state.user?.plan || 'free';
    return `<div class="page-intro pricing-head"><span class="eyebrow">HOMEOSTAT FOR ARTISTS</span><h1>Больше места для твоего звука.</h1><p>Загружай, развивай аудиторию и сохраняй контроль над каждым релизом. Бесплатный план остаётся доступным всем.</p><div class="pricing-switch" role="group" aria-label="Период оплаты"><button class="cycle-button ${c().state.pricingCycle === 'monthly' ? 'is-active' : ''}" data-cycle="monthly">Ежемесячно</button><button class="cycle-button ${c().state.pricingCycle === 'annual' ? 'is-active' : ''}" data-cycle="annual">Ежегодно <span>один платёж</span></button></div></div><div class="pricing-grid">${plans.map(plan => { const price = plan[c().state.pricingCycle] ?? 0; const isPro = plan.id === 'artist_pro'; const isCurrent = plan.id === current; return `<article class="price-card ${isPro ? 'is-pro' : ''} ${isCurrent ? 'is-current' : ''}">${isPro ? '<span class="popular-ribbon">САМЫЙ ПОПУЛЯРНЫЙ</span>' : ''}<div class="price-card-head"><div><span class="eyebrow">${isPro ? 'FOR PROFESSIONALS' : plan.id === 'artist' ? 'FOR CREATORS' : 'START HERE'}</span><h2>${c().escapeHtml(plan.name || planName(plan.id))}${isPro ? ' <span class="plan-badge">✦</span>' : ''}</h2><p>${c().escapeHtml(plan.label || '')}</p></div>${isCurrent ? '<span class="current-badge">Текущий</span>' : ''}</div><div class="price-line"><strong>${money(price, plan.currency)}</strong><span>${price ? (c().state.pricingCycle === 'annual' ? '/ год, один платёж' : '/ месяц') : 'навсегда'}</span></div><button class="button ${isPro ? 'button-primary' : 'button-dark'} button-wide" data-action="choose-plan" data-plan="${c().escapeHtml(plan.id)}" ${isCurrent || plan.id === 'free' ? 'disabled' : ''}>${isCurrent ? 'Тариф активен' : plan.id === 'free' ? 'Начать бесплатно' : 'Выбрать тариф'} <b>→</b></button><ul class="feature-list">${(plan.features || []).map(feature => `<li><span>✓</span>${c().escapeHtml(feature)}</li>`).join('')}</ul><div class="plan-limit"><span>Лимит загрузок</span><b>${hours(plan.upload_seconds)}</b></div></article>`; }).join('')}</div><p class="pricing-note">Оплата проходит на защищённой странице Stripe. Homeostat не хранит данные банковской карты.</p>`;
}

function billingView(data = {}) {
    const usage = data.usage || {}; const percent = Number(usage.percent || 0); const subscription = data.status && data.status !== 'active' ? data.status : ''; const plan = data.plan || 'free';
    return `<div class="page-intro"><span class="eyebrow">YOUR PLAN</span><h1>Подписка и лимиты</h1><p>Управляй тарифом, следи за загрузками и развивай свою сцену без сюрпризов.</p></div><section class="billing-layout"><article class="billing-card billing-summary"><div class="billing-card-top"><div><span class="eyebrow">CURRENT PLAN</span><h2>${c().escapeHtml(planName(plan))}</h2><p>${subscription ? c().escapeHtml(subscription) : plan === 'free' ? 'Доступен базовый набор возможностей.' : 'Тариф активен и готов к работе.'}</p></div><span class="plan-orb">${plan === 'artist_pro' ? '✦' : 'H'}</span></div>${data.current_period_end ? `<p class="billing-date">Следующее списание / окончание периода: <b>${c().escapeHtml(new Date(data.current_period_end).toLocaleDateString('ru-RU'))}</b></p>` : ''}<div class="billing-actions"><button class="button button-light" data-action="billing-sync">Проверить оплату в Stripe</button>${plan !== 'free' ? `<button class="button button-light" data-action="billing-portal">Управление оплатой</button>${data.cancel_at_period_end ? `<button class="button button-primary" data-action="billing-resume">Возобновить</button>` : `<button class="button button-ghost" data-action="billing-cancel">Отменить в конце периода</button>`}` : `<a class="button button-primary" href="#pricing">Посмотреть тарифы</a>`}</div></article><article class="billing-card usage-card"><div class="billing-card-top"><div><span class="eyebrow">UPLOAD USAGE</span><h2>${usage.unlimited ? 'Безлимитные загрузки' : `${c().formatDuration(usage.used_seconds || 0)} из ${c().formatDuration(usage.limit_seconds || 0)}`}</h2><p>Длительность всех ваших треков</p></div><span class="usage-percent">${usage.unlimited ? '∞' : `${percent}%`}</span></div>${usage.unlimited ? '<div class="unlimited-line"><span>✦</span> Можно выпускать без ограничений</div>' : `<div class="usage-track"><i style="width:${Math.min(100, percent)}%"></i></div><div class="usage-foot"><span>${percent >= 80 ? 'Лимит почти исчерпан' : 'Место освободится после удаления треков'}</span><a href="#pricing">Увеличить лимит →</a></div>`}</article></section>`;
}

async function choosePlan(plan) {
    if (plan === 'free') { c().go(c().state.token ? 'tracks' : 'register'); return; }
    if (!c().state.token) { c().go('register'); window.setTimeout(() => c().notify('Создайте аккаунт, чтобы выбрать тариф', 'info'), 50); return; }
    try { const result = await c().api('/billing/checkout', { method: 'POST', body: { plan, cycle: c().state.pricingCycle } }); const checkout = c().dataOf(result)?.checkout_url; if (checkout) window.location.href = checkout; else throw new (c().ApiError)('Stripe не вернул ссылку на оплату.', 502); } catch (error) { c().notify(readError(error), 'error'); }
}
async function billingAction(action) {
    if (action === 'cancel' && !window.confirm('Отменить подписку в конце текущего оплаченного периода?')) return;
    try { const result = await c().api(`/billing/${action}`, { method: 'POST' }); if (action === 'portal') { const url = c().dataOf(result)?.url; if (url) window.location.href = url; return; } c().notify(action === 'cancel' ? 'Подписка будет отменена в конце периода' : 'Подписка возобновлена', 'success'); c().render(); } catch (error) { c().notify(readError(error), 'error'); }
}
async function refreshBilling(sessionId = null) {
    const body = sessionId ? { session_id: sessionId } : {};
    const result = await c().api('/billing/sync', { method: 'POST', body });
    c().state.billing = c().dataOf(result);
    const profile = await c().api('/me');
    c().state.user = c().dataOf(profile);
    localStorage.setItem('homeostat_user', JSON.stringify(c().state.user));
}

async function syncBilling() {
    const button = document.querySelector('[data-action="billing-sync"]');
    if (button) button.disabled = true;
    try { await refreshBilling(); c().notify('Подписка проверена и обновлена', 'success'); await c().render(); }
    catch (error) { c().notify(readError(error), 'error'); }
    finally { if (button) button.disabled = false; }
}

async function handleBillingQuery() {
    const params = new URLSearchParams(window.location.search); const status = params.get('billing'); if (!status) return;
    const clear = () => { params.delete('billing'); params.delete('session_id'); const query = params.toString(); window.history.replaceState({}, document.title, window.location.pathname + (query ? `?${query}` : '') + window.location.hash); };
    if (status === 'cancelled') { clear(); c().go('pricing'); window.setTimeout(() => c().notify('Оплата отменена.', 'info'), 100); return; }
    if (status !== 'success') return;
    if (!c().state.token) { c().go('login'); window.setTimeout(() => c().notify('Войдите в тот же аккаунт и откройте раздел «Подписка».', 'info'), 100); return; }
    try {
        const sessionId = params.get('session_id');
        if (!sessionId) throw new Error('Не указан номер сессии Stripe. Откройте раздел «Подписка» и нажмите «Проверить оплату».');
        await refreshBilling(sessionId); clear(); c().go('billing');
        window.setTimeout(() => c().notify('Оплата подтверждена, подписка обновлена.', 'success'), 100);
    } catch (error) {
        c().go('billing');
        window.setTimeout(() => c().notify(`${readError(error)} Повторную оплату не оформляйте.`, 'error'), 100);
    }
}



export { pricingView, billingView, choosePlan, billingAction, handleBillingQuery, syncBilling };
