export function maxBirthDate(today = new Date()) {
    const now = today; const year = now.getFullYear() - 16;
    const day = Math.min(now.getDate(), new Date(year, now.getMonth() + 1, 0).getDate());
    return `${year}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}
export function isOldEnough(value, today = new Date()) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '') || value < '1900-01-01') return false;
    const date = new Date(`${value}T12:00:00Z`);
    return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value && value <= maxBirthDate(today);
}
