import { randomUUID } from 'node:crypto';
import { createConnection } from 'node:net';
import { performance } from 'node:perf_hooks';

export const id = value => typeof value === 'string' && /^[a-zA-Z0-9_.:-]{1,128}$/.test(value);
export const uuid = value => typeof value === 'string' && /^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i.test(value);
export const token = value => typeof value === 'string' && /^[\da-f]{64}$/.test(value);
const maxFrame = 16_384;

export function request(endpoint, message, deadline) {
    const frame = JSON.stringify({ version: 1, ...message }) + '\n';
    const remaining = Math.min(250, deadline - performance.now());
    if (Buffer.byteLength(frame) > maxFrame || remaining <= 0) return Promise.resolve(null);
    return new Promise(resolve => {
        const socket = createConnection(endpoint);
        const finish = reply => {
            clearTimeout(timer);
            socket.destroy();
            resolve(reply);
        };
        const timer = setTimeout(() => finish(null), remaining);
        let buffer = Buffer.alloc(0);
        socket.once('connect', () => socket.write(frame));
        socket.once('error', () => finish(null));
        socket.once('end', () => finish(null));
        socket.on('data', chunk => {
            const end = chunk.indexOf(10);
            const part = end < 0 ? chunk : chunk.subarray(0, end);
            if (buffer.length + part.length >= maxFrame) return finish(null);
            buffer = Buffer.concat([buffer, part]);
            if (end < 0) return;
            try { finish(JSON.parse(buffer.toString('utf8'))); }
            catch { finish(null); } // Invalid reply is a local delivery refusal.
        });
    });
}

export async function send(endpoint, credential, state, kind, fields, deadline) {
    const reply = await request(endpoint, {
        kind, ...credential, eventId: randomUUID(), sessionId: state.session,
        sequence: state.sequence, sessionEpoch: state.epoch, ...fields,
    }, deadline);
    return reply?.ok === true && ['accepted', 'duplicate'].includes(reply.status);
}
