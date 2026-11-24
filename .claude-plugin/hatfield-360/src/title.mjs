import { performance } from 'node:perf_hooks';
import { text } from './lifecycle.mjs';
import { uuid } from './protocol.mjs';
import { diagnostic } from './state.mjs';

export async function titleFor(hook, deadline) {
    if (text(hook.session_title, 1024)) return hook.session_title;
    if (!uuid(hook.session_id)) return null;
    const remaining = Math.min(150, deadline - performance.now());
    if (remaining <= 0) return null;
    let timer;
    try {
        const lookup = async () => {
            const { getSessionInfo } = await import('@anthropic-ai/claude-agent-sdk/core');
            const info = await getSessionInfo(hook.session_id);
            return info?.sessionId === hook.session_id && text(info.customTitle, 1024) ? info.customTitle : null;
        };
        return await Promise.race([lookup(), new Promise(resolve => { timer = setTimeout(resolve, remaining, null); })]);
    } catch {
        diagnostic('hook_title_unavailable');
        return null;
    } finally { clearTimeout(timer); }
}
