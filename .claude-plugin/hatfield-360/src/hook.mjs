import { dirname } from 'node:path';
import { performance } from 'node:perf_hooks';
import { object } from './attention.mjs';
import { launch } from './launch.mjs';
import { observe, snapshot, text } from './lifecycle.mjs';
import { send } from './protocol.mjs';
import { diagnostic, privateDirectory, withState } from './state.mjs';
import { titleFor } from './title.mjs';

// Bound the whole invocation, even if optional SDK work remains in flight.
const deadline = performance.now() + 900;
const timeout = setTimeout(() => { diagnostic('hook_deadline'); process.exit(process.exitCode ?? 0); }, 900);
const notify = process.argv[2] === '--notify';
process.exitCode = notify ? 1 : 0;
process.umask(0o077);

async function input() {
    const chunks = [];
    let size = 0;
    for await (const chunk of process.stdin) {
        size += chunk.length;
        if (size > (notify ? 16_384 : 1_048_576)) return null;
        chunks.push(chunk);
    }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(Buffer.concat(chunks)));
}

async function report() {
    const endpoint = process.env.H360_STATUS_ENDPOINT;
    if (!endpoint?.startsWith('/') || endpoint.includes('\0') || Buffer.byteLength(endpoint) > 103 || !privateDirectory(dirname(endpoint))) return false;
    const context = launch(endpoint);
    if (!context) return false;
    let hook;
    const inputTimeout = setTimeout(() => process.stdin.destroy(), 250);
    try { hook = await input(); }
    catch {
        if (notify) process.exitCode = 2;
        diagnostic('hook_reporting_unavailable');
        return false;
    } finally { clearTimeout(inputTimeout); }
    if (!object(hook) || notify && (Object.keys(hook).length !== 2 || !hook.title || !hook.body || !text(hook.title, 200) || !text(hook.body, 2000))) {
        if (notify) process.exitCode = 2;
        return false;
    }
    return withState(dirname(endpoint), context, hook.hook_event_name === 'SubagentStart', deadline, async (previous, save, pendingStart) => {
        let state, kind, fields;
        if (notify) {
            if (!previous || previous.ended || !Number.isSafeInteger(previous.sequence + 1)) return false;
            state = previous;
            ++state.sequence;
            kind = 'notification';
            fields = { title: hook.title, body: hook.body };
        } else {
            const result = observe(previous, hook, context.secret);
            if (!result) return false;
            ({ state, kind } = result);
            if (result.refreshTitle) state.title = await titleFor(hook, deadline - (context.managed ? 500 : 250)) ?? state.title;
            fields = kind === 'session.snapshot' ? snapshot(state) : {};
        }
        // Reserve sequence and commit provider observations before I/O. A lost
        // acknowledgement is not permission to replay or reuse the sequence.
        save(state);
        const credential = await context.authorize(deadline);
        if (!credential) return false;
        if (fields.turn?.status === 'completed' && fields.activeSubagents === 0 && await pendingStart()) fields.activeSubagents = null;
        return send(endpoint, credential, state, kind, fields, deadline);
    });
}

try {
    if (await report() && notify) process.exitCode = 0;
} catch {
    diagnostic('hook_reporting_unavailable');
} finally {
    // No abandoned metadata call can keep the hook alive beyond the deadline.
    timeout.unref();
}
