import { createHash, createHmac } from 'node:crypto';
import { accessSync, constants, readFileSync, readlinkSync, statSync } from 'node:fs';
import { delimiter, resolve } from 'node:path';
import { request, token, uuid } from './protocol.mjs';

function identity(path) {
    try {
        const { dev, ino } = statSync(path, { bigint: true });
        return [String(dev), String(ino)];
    } catch { return null; } // Unreadable executable/namespace is not positive identity evidence.
}

function processRow(pid) {
    const base = `/proc/${pid}`;
    const stat = readFileSync(`${base}/stat`, 'utf8');
    if (stat.length > 8192 || !stat.startsWith(`${pid} (`)) return null;
    // The parenthesized comm field may itself contain spaces and parentheses.
    const end = stat.lastIndexOf(')');
    if (end < 0) return null;
    const fields = stat.slice(end + 2).split(' ');
    const [state, parent, , , tty] = fields;
    const ticks = fields[19];
    if (!/^(0|[1-9]\d{0,9})$/.test(parent ?? '') || !/^-?\d+$/.test(tty ?? '') ||
        !/^[1-9]\d{0,19}$/.test(ticks ?? '') || ['Z', 'X'].includes(state)) return null;
    return { parent: Number(parent), tty, ticks, ns: identity(`${base}/ns/pid`)?.[1], exe: identity(`${base}/exe`)?.join(':') };
}

function owner() {
    if (process.platform !== 'linux' || readlinkSync('/proc/self') !== String(process.pid)) return null;
    const executable = (process.env.PATH ?? '').split(delimiter).map(path => resolve(path, 'claude')).find(path => {
        try { accessSync(path, constants.X_OK); return statSync(path).isFile(); }
        catch { return false; } // Keep searching for an executable on PATH.
    });
    const exe = executable && identity(executable)?.join(':');
    if (!exe) return null;
    const chain = new Map();
    let match = null;
    let pid = process.pid;
    for (; pid > 0 && chain.size < 64;) {
        const row = processRow(pid);
        if (!row || chain.has(pid)) return null;
        chain.set(pid, row);
        if (row.exe === exe) {
            if (match || row.tty === '0' || !/^[1-9]\d{0,19}$/.test(row.ns ?? '')) return null;
            match = { pid, pidNamespace: row.ns, startTicks: row.ticks };
        }
        pid = row.parent;
    }
    // Reject ambiguity, incomplete ancestry and process replacement.
    if (pid !== 0 || !match || match.pid === process.pid || match.pidNamespace !== chain.get(process.pid)?.ns ||
        identity(executable)?.join(':') !== exe || [...chain].some(([pid, row]) => !same(processRow(pid), row))) return null;
    return match;
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
function instanceId(processOwner, secret) {
    const key = createHmac('sha256', secret).update(Object.values(processOwner).join(':')).digest('hex');
    const bytes = createHash('sha1').update(Buffer.from('f7ddf8d276b94e459ab0c13ce270fb4f', 'hex')).update(key).digest();
    bytes[6] = (bytes[6] & 15) | 80;
    bytes[8] = (bytes[8] & 63) | 128;
    return [bytes.subarray(0, 4), bytes.subarray(4, 6), bytes.subarray(6, 8), bytes.subarray(8, 10), bytes.subarray(10, 16)]
        .map(part => part.toString('hex')).join('-');
}

export function launch(endpoint) {
    const { H360_STATUS_LAUNCH_ID: launchId, H360_STATUS_TOKEN: statusToken, H360_STATUS_BOOTSTRAP_TOKEN: secret } = process.env;
    if (uuid(launchId) && launchId === launchId.toLowerCase() && token(statusToken)) {
        const name = `claude-status-${launchId}`;
        return { name, lock: name, secret: statusToken, managed: false, authorize: async () => ({ launchId, token: statusToken }) };
    }
    if (launchId !== undefined || statusToken !== undefined || !token(secret)) return null;
    const identity = owner();
    if (!identity) return null;
    const instance = instanceId(identity, secret);
    return {
        name: `claude-managed-${instance}`, lock: 'claude-managed', secret, managed: true,
        async authorize(deadline) {
            if (!same(identity, owner())) return null;
            const registration = { kind: 'launch.register', bootstrapToken: secret, instanceId: instance, provider: 'claude', owner: identity };
            let reply = await request(endpoint, registration, deadline);
            if (!reply || ['unavailable', 'limit'].includes(reply.error)) reply = await request(endpoint, registration, deadline);
            return reply?.ok === true && uuid(reply.launchId) && token(reply.token) ? { launchId: reply.launchId, token: reply.token } : null;
        },
    };
}
