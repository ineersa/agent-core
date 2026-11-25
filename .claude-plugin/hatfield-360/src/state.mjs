import { randomUUID } from 'node:crypto';
import { constants, existsSync, lstatSync, openSync, closeSync, readSync, fstatSync, writeFileSync, renameSync, unlinkSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { performance } from 'node:perf_hooks';
import { object } from './attention.mjs';
import { id, uuid } from './protocol.mjs';
import { text } from './lifecycle.mjs';
import { validSchedules } from './schedule.mjs';

const maxState = 180_000;
export const diagnostic = event_type => process.stderr.write(JSON.stringify({ component: 'hatfield-360', event_type }) + '\n');

// Fifty 100 ms intervals give the state lock a five-second contention window.
// Pending-start locks are probes, not a second wait budget.
const stateRetries = { retries: 50, factor: 1, minTimeout: 100, maxTimeout: 100 };
async function lock(acquire, path, retries = 0) {
    try { return await acquire(path, { realpath: false, retries }); }
    catch (error) {
        if (error.code !== 'ELOCKED') throw error;
        return null;
    }
}

const sequence = n => Number.isSafeInteger(n) && n > 0;
const count = n => n === null || Number.isInteger(n) && n >= 0 && n <= 1024;
const hash = n => typeof n === 'string' && /^[\da-f]{64}$/.test(n);
const entries = (map, max, validate) => object(map) && Object.keys(map).length <= max && Object.entries(map).every(([key, value]) => hash(key) && validate(value));

function read(path) {
    const fd = openSync(path, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
    try {
        const stat = fstatSync(fd);
        if (!stat.isFile() || stat.size > maxState) throw new Error('invalid state');
        const bytes = Buffer.alloc(maxState + 1);
        const length = readSync(fd, bytes, 0, bytes.length);
        const s = JSON.parse(bytes.toString('utf8', 0, length));
        // State written before schedule tracking existed.
        if (object(s) && !Object.hasOwn(s, 'schedules')) Object.assign(s, { schedules: {}, wakeup: null });
        if (!object(s) || !id(s.session) || !sequence(s.sequence) || !sequence(s.epoch) || s.epoch > s.sequence ||
            typeof s.ended !== 'boolean' || typeof s.agentsUncertain !== 'boolean' || !count(s.scripts) ||
            !Array.isArray(s.boundaries) || s.boundaries.length > 128 || !s.boundaries.every(uuid) ||
            !(s.switch === null || s.ended && s.boundaries.length && ['resume', 'clear'].includes(s.switch)) ||
            !(s.turn === null || object(s.turn) && uuid(s.turn.id) && ['working', 'completed', 'failed'].includes(s.turn.status) && [undefined, 'schedule'].includes(s.turn.trigger)) ||
            !validSchedules(s) ||
            !(s.title === null || text(s.title, 1024)) ||
            !entries(s.waits, 16, n => sequence(Math.abs(n)) && typeof n === 'number') ||
            !entries(s.tools, 32, t => object(t) && id(t.name) && (t.wait === null || sequence(t.wait)) && (t.fingerprint === null || hash(t.fingerprint))) ||
            !(s.agents === null || entries(s.agents, 1024, n => n === true || n === 'delivering'))) throw new Error('invalid state');
        return s;
    } finally { closeSync(fd); }
}

function save(path, state) {
    const json = JSON.stringify(state);
    if (Buffer.byteLength(json) > maxState) throw new Error('state too large');
    const temporary = `${path}.${randomUUID()}.tmp`;
    writeFileSync(temporary, json, { mode: 0o600, flag: 'wx' });
    try { renameSync(temporary, path); }
    catch (error) { unlinkSync(temporary); throw error; }
}

export function privateDirectory(directory) {
    const stat = lstatSync(directory);
    return stat.isDirectory() && stat.uid === process.geteuid() && (stat.mode & 0o7777) === 0o700;
}

export async function withState(directory, launch, start, deadline, callback) {
    const { default: { lock: acquire } } = await import('proper-lockfile');
    const waitDeadline = Math.min(deadline, performance.now() + 5000);
    const path = join(directory, `node-${launch.name}.json`);
    const pending = `${path}.pending`;
    const marker = `${path}.uncertain`;
    const mark = () => closeSync(openSync(marker, constants.O_CREAT | constants.O_WRONLY | constants.O_NOFOLLOW, 0o600));
    // A waiting child start must inhibit a concurrent completed/zero snapshot.
    // If acquiring its pending lock fails, leave sticky positive uncertainty.
    const trackStart = start && existsSync(path);
    const releasePending = trackStart ? await lock(acquire, pending) : null;
    try {
        if (trackStart && !releasePending) mark();
        const release = performance.now() < deadline ? await lock(acquire, join(directory, `node-${launch.name}-state`), stateRetries) : null;
        if (!release) {
            if (trackStart) mark();
            diagnostic('hook_lock_unavailable');
            return false;
        }
        try {
            if (performance.now() >= deadline) {
                if (trackStart) mark();
                diagnostic('hook_deadline');
                return false;
            }
            const previous = existsSync(path) ? read(path) : null;
            const persist = async state => {
                if (!previous && launch.managed) {
                    // Publish the first state under the admission lock, so the
                    // cap includes every admitted instance before any socket I/O.
                    const remaining = Math.max(0, Math.min(waitDeadline, deadline) - performance.now());
                    const admission = await lock(acquire, join(directory, 'node-claude-managed-admission'), {
                        ...stateRetries, retries: Math.floor(remaining / stateRetries.minTimeout),
                    });
                    if (!admission) {
                        diagnostic('hook_lock_unavailable');
                        return false;
                    }
                    try {
                        if (performance.now() >= deadline) {
                            diagnostic('hook_deadline');
                            return false;
                        }
                        if (readdirSync(directory).filter(name => /^node-claude-managed-.*\.json$/.test(name)).length >= 64) return false;
                        save(path, state);
                    } finally { await admission(); }
                } else save(path, state);
                return true;
            };
            const pendingStart = async () => {
                const exclusive = await lock(acquire, pending);
                await exclusive?.();
                return !exclusive || existsSync(marker);
            };
            return await callback(previous, persist, pendingStart);
        } finally { await release(); }
    } finally { await releasePending?.(); }
}
