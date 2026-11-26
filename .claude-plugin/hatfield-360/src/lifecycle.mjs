import { attentionFields, digest, observeAttention } from './attention.mjs';
import { id, uuid } from './protocol.mjs';
import { observeScheduleTool, prune, scheduleFields, scheduledRun, settleWakeup } from './schedule.mjs';

const switches = ['clear', 'resume'];
const boundaries = ['startup', ...switches];
const childEvents = ['SubagentStart', 'SubagentStop'];
export const text = (value, max) => typeof value === 'string' && [...value].length <= max;
const maxResponse = 1000;

// Keeps the end of the final message, where the answer usually is.
function response(value) {
    if (typeof value !== 'string' || !value.trim() || /\p{Surrogate}/u.test(value)) return null;
    const scalars = [...value];
    return scalars.length <= maxResponse ? value : '…' + scalars.slice(1 - maxResponse).join('');
}

function registry(tasks) {
    if (!Array.isArray(tasks) || tasks.length > 1024) return null;
    const shells = new Set();
    let subagents = false;
    for (const task of tasks) {
        if (!id(task?.id) || typeof task.type !== 'string' || !task.type || typeof task.status !== 'string' || !task.status) return null;
        if (task.type === 'shell' && task.status === 'running') shells.add(task.id);
        // The registry contains in-flight tasks; its IDs are not agent IDs.
        subagents ||= task.type === 'subagent' || task.type === 'teammate';
    }
    return { scripts: shells.size, subagents };
}

function observeChild(state, hook) {
    if (!id(hook.agent_id) || state.agents === null) return false;
    const key = digest(hook.agent_id);
    if (hook.hook_event_name === 'SubagentStart') {
        if (state.agents[key] === true) return false;
        // A child whose stop another hook blocked starts again.
        if (state.agents[key] === 'delivering') state.agents[key] = true;
        else if (Object.keys(state.agents).length >= 1024) state.agents = null;
        else state.agents[key] = true;
    } else {
        if (state.agents[key] !== true) return false;
        if (state.turn && state.turn.status !== 'working') {
            // After the main response stopped, Claude wakes with a new prompt to handle
            // this child's result: the work continues until that response settles.
            state.agents[key] = 'delivering';
        } else {
            delete state.agents[key];
            // Child samples may add uncertainty, but never clear it; processing
            // order does not establish the order in which registries were sampled.
            state.agentsUncertain ||= registry(hook.background_tasks)?.subagents === true;
        }
    }
    state.scripts = null;
    return true;
}

function select(previous, hook, sequence) {
    const { source, session_id: session, prompt_id: prompt } = hook;
    if (!boundaries.includes(source)) return null;
    if (previous && (!switches.includes(source) || source !== previous.switch ||
        !((source === 'clear' && prompt == null) || (uuid(prompt) && prompt.toLowerCase() === previous.boundaries.at(-1))))) return null;
    return { session, epoch: sequence, boundaries: previous?.boundaries ?? [], ended: false, switch: null,
        title: null, turn: null, waits: {}, tools: {}, scripts: null, agents: {}, agentsUncertain: source === 'resume',
        // Jobs from before a clear or resume reappear after the next CronList.
        schedules: {}, wakeup: null };
}

export function observe(previous, hook, secret) {
    const { session_id: session, hook_event_name: event, prompt_id: prompt } = hook;
    const sequence = (previous?.sequence ?? 0) + 1;
    const now = Date.now();
    if (!id(session) || !Number.isSafeInteger(sequence)) return null;
    let state = previous;
    let kind = 'session.snapshot';
    let refreshTitle = false;
    if (event === 'SessionStart' && hook.agent_id == null) {
        state = select(previous, hook, sequence);
        if (!state) return null;
        refreshTitle = true;
    } else if (state?.session !== session || state.ended) return null;
    else if (childEvents.includes(event)) {
        if (!observeChild(state, hook)) return null;
    } else if (hook.agent_id != null) return null;
    else if (event === 'SessionEnd') {
        if (switches.includes(hook.reason)) {
            if (!uuid(prompt) || state.boundaries.includes(prompt.toLowerCase()) || state.boundaries.length >= 128) return null;
            state.switch = hook.reason;
            state.boundaries.push(prompt.toLowerCase());
        } else if (!['logout', 'prompt_input_exit', 'other'].includes(hook.reason)) return null;
        state.ended = true;
        kind = 'session.ended';
    } else if (!uuid(prompt)) return null;
    else if (event === 'UserPromptSubmit') {
        if (prompt === state.turn?.id) return null;
        state.turn = { id: prompt, status: 'working', ...(scheduledRun(state, hook, secret, now) ? { trigger: 'schedule' } : {}) };
        state.scripts = null;
        // The new response receives the results the stopped children delivered.
        if (state.agents) for (const [key, value] of Object.entries(state.agents)) if (value === 'delivering') delete state.agents[key];
        refreshTitle = true;
    } else if (state.turn?.id !== prompt || state.turn.status !== 'working') return null;
    else if (event === 'Stop' && hook.stop_hook_active === false) {
        // Stop can be blocked by a different hook; it is not an interrupt signal.
        const sample = registry(hook.background_tasks);
        state.scripts = sample?.scripts ?? null;
        state.agentsUncertain = sample?.subagents ?? true;
        state.turn.status = 'completed';
        // Older Claude Code versions do not report the final message.
        const final = response(hook.last_assistant_message);
        if (final) state.turn.response = final;
        settleWakeup(state);
        refreshTitle = true;
    } else if (event === 'StopFailure' && typeof hook.error === 'string') {
        state.turn.status = 'failed';
        settleWakeup(state);
    } else if (!observeScheduleTool(state, hook, secret, now) && !observeAttention(state, hook, sequence, secret)) return null;
    if (state.ended || event === 'UserPromptSubmit' || state.turn?.status === 'failed' || refreshTitle && event === 'Stop') {
        state.waits = {};
        state.tools = {};
    }
    prune(state, now);
    state.sequence = sequence;
    return { state, kind, refreshTitle };
}

export function snapshot(state) {
    const count = state.agents === null ? null : Object.keys(state.agents).length;
    return { provider: 'claude', title: state.title, turn: state.turn, ...attentionFields(state),
        activeSubagents: count === 0 && state.agentsUncertain ? null : count, backgroundScripts: state.scripts, ...scheduleFields(state) };
}
