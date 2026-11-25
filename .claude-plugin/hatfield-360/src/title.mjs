import { performance } from 'node:perf_hooks';
import { text } from './lifecycle.mjs';
import { uuid } from './protocol.mjs';
import { diagnostic } from './state.mjs';

const titleEvents = ['SessionStart', 'UserPromptSubmit', 'Stop'];
// Loading the SDK alone takes about 110 ms; a busy machine needs headroom.
const lookupBudgetMs = 1500;
const maxTitle = 200;

/** One line of at most `maxTitle` characters, or null. */
function line(value) {
    if (typeof value !== 'string') return null;
    const single = value.replace(/\s+/gu, ' ').trim();
    if (!single) return null;
    const characters = [...single];
    return characters.length > maxTitle ? `${characters.slice(0, maxTitle - 1).join('')}…` : single;
}

/**
 * Starts reading the name Claude shows for the session in `/resume`: its custom
 * name, else its generated title, else its latest prompt. Started before the
 * state lock so the SDK loads while the hook waits; null for other events.
 */
export function startTitleLookup(hook) {
    if (hook.agent_id != null || !titleEvents.includes(hook.hook_event_name)) return null;
    if (text(hook.session_title, 1024) && line(hook.session_title)) return Promise.resolve(line(hook.session_title));
    if (!uuid(hook.session_id)) return null;
    return (async () => {
        const { getSessionInfo } = await import('@anthropic-ai/claude-agent-sdk/core');
        const info = await getSessionInfo(hook.session_id);
        if (info?.sessionId !== hook.session_id) return null;
        return line(info.customTitle) ?? line(info.summary);
    })().catch(() => {
        diagnostic('hook_title_unavailable');
        return null;
    });
}

/** The started lookup's title, or null if it fails or outlasts its budget and the hook's deadline. */
export async function titleFor(lookup, started, deadline) {
    if (!lookup) return null;
    const remaining = Math.min(started + lookupBudgetMs, deadline) - performance.now();
    if (remaining <= 0) return null;
    let timer;
    const expired = new Promise(resolve => { timer = setTimeout(resolve, remaining, undefined); });
    try {
        const title = await Promise.race([lookup, expired]);
        if (title === undefined) diagnostic('hook_title_timeout');
        return title ?? null;
    } finally { clearTimeout(timer); }
}
