import { createHash, createHmac } from 'node:crypto';
import { id } from './protocol.mjs';

const names = ['Agent', 'AskUserQuestion', 'Bash', 'Edit', 'Glob', 'Grep', 'Read', 'Write'];
const actions = ['accept', 'decline', 'cancel'];
export const digest = value => createHash('sha256').update(value).digest('hex');
export const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const fingerprint = (input, token) => {
    if (!object(input)) return null;
    const json = JSON.stringify(input);
    return Buffer.byteLength(json) <= 16_384 ? createHmac('sha256', token).update(json).digest('hex') : null;
};

export function observeAttention(state, hook, sequence, token) {
    const { hook_event_name: event, tool_name: name, tool_use_id: use } = hook;
    if (event === 'Elicitation' || event === 'ElicitationResult') {
        if (!id(hook.mcp_server_name) || !id(hook.elicitation_id)) return false;
        const key = digest(hook.mcp_server_name + '\0' + hook.elicitation_id);
        if (event === 'Elicitation') {
            if (Object.hasOwn(state.waits, key) || Object.keys(state.waits).length >= 16) return false;
            state.waits[key] = sequence;
        } else {
            if (!(state.waits[key] > 0) || !actions.includes(hook.action)) return false;
            // Retain a bounded tombstone to reject duplicate starts.
            state.waits[key] *= -1;
        }
        return true;
    }
    if (!names.includes(name)) return false;
    if (event === 'PermissionRequest') {
        const hash = fingerprint(hook.tool_input, token);
        if (hash === null) return false;
        // This hook omits tool_use_id; ambiguous matches cannot prove a wait.
        const matches = Object.values(state.tools).filter(tool => tool.name === name && tool.fingerprint === hash);
        if (matches.length !== 1 || matches[0].wait !== null) return false;
        matches[0].wait = sequence;
        return true;
    }
    if (!id(use)) return false;
    const key = digest(use);
    if (event === 'PreToolUse') {
        if (Object.hasOwn(state.tools, key) || Object.keys(state.tools).length >= 32) return false;
        state.tools[key] = { name, fingerprint: fingerprint(hook.tool_input, token), wait: name === 'AskUserQuestion' ? sequence : null };
    } else if (['PostToolUse', 'PostToolUseFailure'].includes(event) && state.tools[key]?.name === name) {
        delete state.tools[key];
    } else return false;
    return true;
}

export function attentionFields(state) {
    const tools = Object.values(state.tools);
    const waits = Object.values(state.waits).filter(start => start > 0)
        .map(start => ({ start, reason: 'external-input' }));
    for (const tool of tools) {
        if (tool.wait !== null) waits.push({ start: tool.wait, reason: tool.name === 'AskUserQuestion' ? 'question' : 'permission' });
    }
    const first = waits.sort((a, b) => a.start - b.start)[0];
    return { attention: first ? { id: String(first.start), reason: first.reason } : null, tool: tools.at(-1)?.name ?? null };
}
