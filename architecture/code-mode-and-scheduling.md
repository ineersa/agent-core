# Code mode and scheduling

[Architecture](README.md) · [Tool usage](../docs/tools.md) · [Recurring-work usage](../docs/background-processes.md)

## Code mode

`CodeModeTool` is an opt-in ordinary parallel tool, not a new worker role or an
LLM-generated-response adapter. `CodeModeHostBridge` launches an owned PHP CLI
subprocess. For canonical invocations in the session runtime, the shared tool
consumer launches PHP and returns a deferred outcome. `run_control` manages
state transitions; it does not execute scripts or nested tools.

```mermaid
sequenceDiagram
    participant Worker as Shared tool consumer
    participant PHP as Session-owned PHP subprocess
    participant Broker as SQLite-backed session broker
    participant Owner as Controller completion poller
    participant RC as run_control
    Worker->>PHP: Launch with invocation identity and controller settlement authority
    Worker->>Worker: Mark ready and return deferred outcome; release slot
    PHP->>Broker: Publish code_mode_requests with invocation and call IDs
    Broker->>Worker: Deliver request to available shared slot
    Worker->>Worker: Claim call, run existing toolbox, retain reply
    Worker->>Broker: Publish wake-up to invocation reply queue
    Broker-->>PHP: Wake-up; PHP reads retained result by call ID
    PHP->>Owner: Atomically persist terminal.json for observation
    Owner->>RC: Publish deferred completion
```

The reply wake-up is not the result. Redelivery of a settled request can reuse
its retained result; an execution claim without a result is ambiguous and is
not replayed. These records do not restore a lost PHP stack or allow an
invocation to survive session shutdown. Owner notices use separate identities
from terminal completion.

The script budget defaults to **1,800 seconds** with no tool-specific cap, memory to
**256 MiB** (maximum **1,024**). The parent remaining tool budget can shorten it until background acceptance.
After acceptance only an explicit launch-relative timeout applies.
Nested calls receive cooperative budget metadata; a synchronously blocking
handler can overrun until it returns. Scripts yield at host-side I/O, not by
turning arbitrary PHP filesystem/process calls into async toolbox calls.

Direct PHP bypasses hooks and approvals. Code mode inherits the launcher's
sandbox, if any; it creates none. Source/PHAR builds reuse their launching PHP
CLI; fused static builds need `php` on PATH. Deferred/interactive tools
(`subagent`, `fork`, `agent_resume`, `agent_steer`, `notify_parent`, `ask_human`)
are refused by the bridge.

Direct `backgrounded: true` uses the existing deferred completion lifecycle.
Canonical launches run in separate native process groups with invocation-keyed
sidecars. The controller can observe completion, stop the group, and clean its
workspace independently of the launching consumer. `bg_status` controls Bash
jobs, not PHP scripts.

A brokered `tool('code_mode', ...)` launches another session-owned PHP process
and releases the request slot. The child record identifies the parent invocation
and call. The controller replaces the pending child entry in the parent journal
with the retained result before sending a wake-up. Calls without canonical or
brokered parent correlation still use the private socket.

## Scheduling

The optional built-in `SchedulingExtension` registers the main-only `schedule`
tool, `/schedule` picker, session-start hook, recurring source and extension-job
handler. It is disabled by default; no Composer package is needed.

```mermaid
flowchart TB
    Create["schedule create / cancel"] --> Store["SessionScheduleRepository: live definitions"]
    Start["Session-start hook"] -->|New controller identity; clear old definitions| Store
    Store --> Source["ExtensionRecurringJobRegistry"]
    Source --> Scheduler["Symfony Scheduler: scheduler_default"]
    Scheduler --> Dispatcher["Extension recurring dispatch"]
    Dispatcher --> Queue[(tool queue)]
    Queue --> Job["SchedulingExtension job handler"]
    Job -->|Check controller identity / expiry| Invoke["Public tool invocation: Bash or code_mode"]
    Invoke --> Notice["notifyOwner: result to owning session"]
```

Definitions are shared via a filesystem cache and lock, fenced by the current
controller identity. They are not a durable restoration contract: a new session
controller clears them. The first occurrence is immediately eligible; subsequent
occurrences use Symfony `RecurringMessage::every()` semantics. Optional lifetime
bounds future dispatch. Cancellation removes future occurrences, not an invocation
already running. Job execution checks that its definition still exists, belongs
to the same controller and has not expired.

Scheduled tool invocation is noninteractive: approval-required calls and human
questions are denied. A scheduled invocation cannot detach. Notifications use the
existing owner-message flow; no polling loop or extra coordinator is required.
Recurring occurrences explicitly redispatch to `tool`, reusing the extension-job
handler. Ordinary extension-agent dispatch instead uses `extension_agent`.

### Other clocks are separate

- File-completion index refresh is built-in recurring scheduler work, not a user
  `schedule` definition.
- Deferred child-batch deadlines use **durable `run_control` messages with
  `DelayStamp`**, not the in-memory recurring schedule.
- Code-mode and Bash timeouts belong to their invocation; recurring cancellation
  does not replace those execution deadlines.

Sources: [CodeModeHostBridge](../src/CodingAgent/Tool/CodeMode/CodeModeHostBridge.php),
[argument limits](../src/CodingAgent/Tool/Arguments/CodeModeArgumentsDTO.php),
[SchedulingExtension](../src/CodingAgent/Extension/Builtin/Scheduling/SchedulingExtension.php),
[schedule repository](../src/CodingAgent/Extension/Builtin/Scheduling/SessionScheduleRepository.php),
[recurring registry](../src/CodingAgent/Extension/ExtensionRecurringJobRegistry.php).
