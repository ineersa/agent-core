import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { constants, existsSync, lstatSync, openSync, closeSync, readSync, fstatSync, writeFileSync, renameSync, unlinkSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { performance } from 'node:perf_hooks';
import { object } from './attention.mjs';
import { id, uuid } from './protocol.mjs';
import { text } from './lifecycle.mjs';

const maxState = 180_000;
export const diagnostic = event_type => process.stderr.write(JSON.stringify({ component: 'hatfield-360', event_type }) + '\n');

// Node does not expose flock(2). util-linux flock operates on the inherited
// open file description; the Node process retains it until close or exit.
async function lock(path, mode, milliseconds) {
    const fd = openSync(path, constants.O_CREAT | constants.O_RDWR | constants.O_NOFOLLOW, 0o600);
    const acquired = await new Promise(resolve => {
        const wait = milliseconds > 0 ? ['-w', (milliseconds / 1000).toFixed(3)] : ['-n'];
        const child = spawn('flock', [mode, ...wait, '3'], { stdio: ['ignore', 'ignore', 'ignore', fd] });
        child.once('error', () => resolve(false));
        child.once('close', code => resolve(code === 0));
    });
    if (acquired) return () => closeSync(fd);
    closeSync(fd);
    return null;
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
        if (!object(s) || !id(s.session) || !sequence(s.sequence) || !sequence(s.epoch) || s.epoch > s.sequence ||
            typeof s.ended !== 'boolean' || typeof s.agentsUncertain !== 'boolean' || !count(s.scripts) ||
            !Array.isArray(s.boundaries) || s.boundaries.length > 128 || !s.boundaries.every(uuid) ||
            !(s.switch === null || s.ended && s.boundaries.length && ['resume', 'clear'].includes(s.switch)) ||
            !(s.turn === null || object(s.turn) && uuid(s.turn.id) && ['working', 'completed', 'failed'].includes(s.turn.status)) ||
            !(s.title === null || text(s.title, 1024)) ||
            !entries(s.waits, 16, n => sequence(Math.abs(n)) && typeof n === 'number') ||
            !entries(s.tools, 32, t => object(t) && id(t.name) && (t.wait === null || sequence(t.wait)) && (t.fingerprint === null || hash(t.fingerprint))) ||
            !(s.agents === null || entries(s.agents, 1024, n => n === true))) throw new Error('invalid state');
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
    const path = join(directory, `node-${launch.name}.json`);
    const pending = `${path}.pending`;
    const marker = `${path}.uncertain`;
    const mark = () => closeSync(openSync(marker, constants.O_CREAT | constants.O_WRONLY | constants.O_NOFOLLOW, 0o600));
    // A waiting child start must inhibit a concurrent completed/zero snapshot.
    // If acquiring its pending lock fails, leave sticky positive uncertainty.
    const trackStart = start && existsSync(path);
    const releasePending = trackStart ? await lock(pending, '-s', 0) : null;
    try {
        if (trackStart && !releasePending) mark();
        const budget = Math.min(launch.managed ? 350 : 650, deadline - performance.now());
        const release = budget > 0 ? await lock(join(directory, `node-${launch.lock}.lock`), '-x', budget) : null;
        if (!release) {
            if (trackStart) mark();
            diagnostic('hook_lock_unavailable');
            return false;
        }
        try {
            const previous = existsSync(path) ? read(path) : null;
            if (!previous && launch.managed && readdirSync(directory).filter(name => /^node-claude-managed-.*\.json$/.test(name)).length >= 64) return false;
            const pendingStart = async () => {
                const exclusive = await lock(pending, '-x', 0);
                exclusive?.();
                return !exclusive || existsSync(marker);
            };
            return await callback(previous, state => save(path, state), pendingStart);
        } finally { release(); }
    } finally { releasePending?.(); }
}
