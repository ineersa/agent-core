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
    participant Wrapper as Native process wrapper
    participant Broker as SQLite-backed session broker
    participant Owner as Controller process observer
    participant RC as run_control
    Worker->>PHP: Launch with session-owned invocation identity
    Worker->>Worker: Record ownership and commit admission before starting PHP
    Worker->>Worker: Return deferred outcome; release slot
    PHP->>Broker: Publish code_mode_requests with invocation and call IDs
    Broker->>Worker: Deliver request to available shared slot
    Worker->>Worker: Validate and run existing toolbox
    Worker->>Broker: Publish managed result reference to invocation reply queue
    Broker-->>PHP: Correlated reply with managed tool payload reference
    PHP->>PHP: Retain raw terminal result
    PHP-->>Wrapper: Exit after shutdown callbacks
    Wrapper->>Broker: Record native status, then publish wakeup
    Broker->>Worker: Deliver terminal wakeup
    Worker->>Worker: Verify exit and process retained result
    Worker->>RC: Dispatch deferred completion
    Owner->>Broker: Recover retained report once after exit and 60 seconds pending
```

The reply envelope carries a reference, not the result payload. Delivery may repeat; individual tools own idempotency if needed.
The worker does not persist claims or replies in application state. Owner notices use separate identities
from terminal completion.

The script budget defaults to **1,800 seconds** with no tool-specific cap, memory to
**256 MiB** (maximum **1,024**). The parent remaining tool budget can shorten it until background acceptance.
After acceptance only an explicit launch-relative timeout applies.
Nested calls observe the current script deadline through a live cancellation
token, not a snapshot in `ToolContext::timeoutSeconds()`. Detaching releases
the inherited limit even for a nested call already running; an explicit script
timeout still applies. A synchronously blocking handler can overrun until it returns. Scripts yield at host-side I/O, not by
turning arbitrary PHP filesystem/process calls into async toolbox calls.

Direct PHP bypasses hooks and approvals. Code mode inherits the launcher's
sandbox, if any; it creates none. Source/PHAR builds reuse their launching PHP
CLI; fused static builds need `php` on PATH. The launcher materializes a standalone
Composer runtime from the installed locked packages before starting external PHP.
The script process does not load the application kernel or open the state database.
Deferred/interactive tools
(`subagent`, `fork`, `agent_resume`, `agent_steer`, `notify_parent`, `ask_human`)
are refused by the bridge.

Direct `backgrounded: true` uses the existing deferred completion lifecycle.
Canonical launches reserve a row in the Doctrine background-process catalog and
run in separate native process groups with invocation-keyed PID and status
sidecars. The controller can stop the group and clean its workspace, including
when no PID was published. A reservation abandoned before native startup is
fenced and reported as an unknown launch, never replayed. PHP checks committed
admission at startup. `bg_status` controls Bash jobs, not PHP scripts.

Scripts can invoke ordinary tools but cannot recursively launch `code_mode`.
A live session broker is required. Standalone extension calls use their own run and invocation IDs, without an agent-turn batch; the shared worker returns their processed terminal result over the broker to the waiting caller. For canonical calls, only the shared worker delivers normal completion to run control. The observer supervises native processes and may resend a retained report after confirmed exit; it does not process normal success.

The worker validates and executes a nested request, publishes a managed result reference to the invocation reply queue, and then acknowledges the request. PHP waits for the matching call ID and resolves the reference using the payload store. The call ID correlates replies; it does not suppress execution on redelivery.
Nested reply bodies belong to the script workspace. PHP deletes each body after acknowledging its delivery. The process owner removes the remaining bodies after the script exits, and prevents late workers from recreating that scope. Terminal reports and standalone terminal replies use separate storage.

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
