import { createHmac } from 'node:crypto';
import { object } from './attention.mjs';
import { id } from './protocol.mjs';

const maxJobs = 16;
const minute = 60_000;
// Claude fires one-shot jobs up to 90 s early; a matching prompt before that is typed by hand.
const early = 90_000;
const stale = 24 * 60 * minute;
const wakeupId = 'wakeup';
const fields = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

const label = value => typeof value === 'string' && value.trim() !== '' && [...value].length <= 64;
const instant = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 8.64e15;
const promptHash = (prompt, secret) => createHmac('sha256', secret).update(prompt).digest('hex');

function parseField(text, [min, max]) {
    const values = new Set();
    for (const part of text.split(',')) {
        const match = /^(\*|(\d+)(?:-(\d+))?)(?:\/(\d+))?$/.exec(part);
        if (!match) return null;
        const step = match[4] === undefined ? 1 : Number(match[4]);
        let from = min, to = max;
        if (match[1] !== '*') {
            from = Number(match[2]);
            to = match[3] === undefined ? (match[4] === undefined ? from : max) : Number(match[3]);
        }
        if (step < 1 || from < min || to > max || from > to) return null;
        for (let value = from; value <= to; value += step) values.add(value);
    }
    return values;
}

function parseCron(expression) {
    const parts = typeof expression === 'string' ? expression.trim().split(/\s+/) : [];
    if (parts.length !== 5) return null;
    const sets = parts.map((part, index) => parseField(part, fields[index]));
    if (sets.includes(null)) return null;
    if (sets[4].has(7)) sets[4].add(0);
    return { sets, anyDay: parts[2] === '*', anyWeekday: parts[4] === '*' };
}

/** The first local-time match strictly after `after`, or null within a year. */
export function nextCronMatch(expression, after) {
    const cron = parseCron(expression);
    if (!cron || !instant(after)) return null;
    const [minutes, hours, days, months, weekdays] = cron.sets;
    const at = new Date(after);
    at.setSeconds(0, 0);
    const limit = after + 366 * 24 * 60 * minute;
    for (at.setMinutes(at.getMinutes() + 1); at.getTime() <= limit;) {
        if (!months.has(at.getMonth() + 1)) {
            at.setMonth(at.getMonth() + 1, 1);
            at.setHours(0, 0);
            continue;
        }
        const day = days.has(at.getDate()), weekday = weekdays.has(at.getDay());
        // Standard cron: when both day fields are restricted, either one matches.
        if (!(cron.anyDay ? weekday : cron.anyWeekday ? day : day || weekday)) {
            at.setDate(at.getDate() + 1);
            at.setHours(0, 0);
            continue;
        }
        if (!hours.has(at.getHours())) {
            at.setHours(at.getHours() + 1, 0);
            continue;
        }
        if (minutes.has(at.getMinutes())) return at.getTime();
        at.setMinutes(at.getMinutes() + 1);
    }
    return null;
}

// Claude's jitter shifts a job's runs by a fixed amount; learn it from the first run.
function jitter(cron, nextFireAt) {
    let match = null;
    for (let at = nextCronMatch(cron, nextFireAt - 16 * minute); at !== null && at <= nextFireAt + early; at = nextCronMatch(cron, at)) match = at;
    return match === null ? 0 : nextFireAt - match;
}

function job(entry, secret, now, previous) {
    if (!object(entry) || !id(entry.id) || typeof entry.prompt !== 'string') return null;
    const name = label(entry.humanSchedule) ? entry.humanSchedule : label(entry.cron) ? entry.cron : 'Scheduled prompt';
    const next = instant(entry.nextFireAt) ? Math.round(entry.nextFireAt) : null;
    return {
        label: name,
        recurring: entry.recurring === true,
        cron: typeof entry.cron === 'string' && entry.cron.length <= 256 ? entry.cron : null,
        prompt: promptHash(entry.prompt, secret),
        next,
        offset: previous?.offset ?? (next === null || typeof entry.cron !== 'string' ? 0 : jitter(entry.cron, next)),
        expires: previous?.expires ?? (entry.recurring === true && instant(entry.maxAgeMs) ? now + entry.maxAgeMs : null),
    };
}

export function prune(state, now) {
    if (!state.schedules) return;
    for (const [key, entry] of Object.entries(state.schedules)) {
        if (entry.expires !== null && entry.expires <= now || !entry.recurring && entry.next !== null && entry.next < now - stale) delete state.schedules[key];
    }
}

/** Tracks Claude's session cron jobs and self-paced wakeups from their tool results. */
export function observeScheduleTool(state, hook, secret, now) {
    const { tool_name: name, tool_input: input, tool_response: response } = hook;
    if (hook.hook_event_name !== 'PostToolUse' || !object(response)) return false;
    if (name === 'CronCreate') {
        if (!object(input)) return false;
        const created = job({ ...input, ...response }, secret, now, null);
        if (!created || response.id === wakeupId) return false;
        state.schedules ??= {};
        if (!Object.hasOwn(state.schedules, response.id) && Object.keys(state.schedules).length >= maxJobs) return false;
        state.schedules[response.id] = created;
        return true;
    }
    if (name === 'CronDelete') {
        const key = response.id ?? input?.id;
        if (!id(key) || !state.schedules || !Object.hasOwn(state.schedules, key)) return false;
        delete state.schedules[key];
        return true;
    }
    if (name === 'CronList') {
        if (!Array.isArray(response.jobs) || response.jobs.length > maxJobs) return false;
        const listed = {};
        for (const entry of response.jobs) {
            const known = state.schedules?.[entry?.id];
            const parsed = job(entry, secret, now, known);
            if (!parsed || entry.id === wakeupId || Object.hasOwn(listed, entry.id)) return false;
            listed[entry.id] = parsed;
        }
        state.schedules = listed;
        return true;
    }
    if (name === 'ScheduleWakeup') {
        if (!object(input) || typeof input.prompt !== 'string' || !instant(response.scheduledFor)) return false;
        state.wakeup = { prompt: promptHash(input.prompt, secret), at: Math.round(response.scheduledFor), turn: null };
        return true;
    }
    return false;
}

/** Whether a submitted prompt is a due run of a tracked job or wakeup, consuming that run. */
export function scheduledRun(state, hook, secret, now) {
    if (typeof hook.prompt !== 'string') return false;
    const hash = promptHash(hook.prompt, secret);
    const due = at => at === null || now >= at - early;
    const wakeup = state.wakeup;
    if (wakeup && wakeup.at !== null && wakeup.prompt === hash && due(wakeup.at)) {
        // The loop continues only if this run schedules another wakeup.
        state.wakeup = { ...wakeup, at: null, turn: hook.prompt_id };
        return true;
    }
    const match = Object.entries(state.schedules ?? {}).find(([, entry]) => entry.prompt === hash && due(entry.next));
    if (!match) return false;
    const [key, entry] = match;
    if (!entry.recurring) delete state.schedules[key];
    else {
        const after = Math.max(now, entry.next ?? 0) - entry.offset;
        const next = entry.cron === null ? null : nextCronMatch(entry.cron, after);
        entry.next = next === null ? null : next + entry.offset;
    }
    return true;
}

/** A finished wakeup run that scheduled no further wakeup ends the loop. */
export function settleWakeup(state) {
    if (state.wakeup?.turn != null && state.wakeup.turn === state.turn?.id) state.wakeup = null;
}

export function scheduleFields(state) {
    const list = Object.entries(state.schedules ?? {}).map(([key, entry]) => ({ id: key, label: entry.label, recurring: entry.recurring, nextRunAt: entry.next }));
    if (state.wakeup) list.push({ id: wakeupId, label: 'Self-paced loop', recurring: true, nextRunAt: state.wakeup.at });
    // Older Hatfield 360 builds reject the field, so only sessions that use schedules send it.
    return list.length ? { schedules: list.slice(0, maxJobs) } : {};
}

export function validSchedules(state) {
    const entry = e => object(e) && label(e.label) && typeof e.recurring === 'boolean' && (e.cron === null || typeof e.cron === 'string' && e.cron.length <= 256) &&
        /^[\da-f]{64}$/.test(e.prompt) && (e.next === null || instant(e.next)) && Number.isFinite(e.offset) && (e.expires === null || instant(e.expires));
    const schedules = state.schedules === null || object(state.schedules) && Object.keys(state.schedules).length <= maxJobs &&
        Object.entries(state.schedules).every(([key, value]) => id(key) && key !== wakeupId && entry(value));
    const wakeup = state.wakeup === null || object(state.wakeup) && /^[\da-f]{64}$/.test(state.wakeup.prompt) &&
        (state.wakeup.at === null || instant(state.wakeup.at)) && (state.wakeup.turn === null || typeof state.wakeup.turn === 'string');
    return schedules && wakeup;
}
