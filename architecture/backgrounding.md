# Background command architecture

[Architecture map](README.md) · [Processes](processes-and-queues.md) · [Tools](tools-and-mcp.md)

Backgrounding changes ownership of an already running bash command. It does not
launch a second copy. Private foreground supervision and accepted background work
share record infrastructure but have different visibility and cleanup rules.

## Foreground command becomes background work

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Model
    participant Bash as BashTool
    participant Manager as BackgroundProcessManager
    participant Process as Owned shell process group
    participant Records as background_process and sidecars
    participant Questions as ToolQuestionStore / runtime prompt adapter
    participant Controller as ToolQuestionPoller
    participant TUI
    actor User

    Model->>Bash: bash(command, timeout)
    Bash->>Manager: Start supervised command
    Manager->>Records: Private row with PID, status, log paths
    Manager->>Process: Launch once, capture output and exit status
    loop While running in foreground
        Bash->>Manager: Check status, cancellation, timeout, output
        Manager->>Records: Read owned status and log
    end
    alt Finishes before background choice
        Process->>Records: Exit code and final output
        Manager-->>Bash: Exact foreground outcome
        Bash-->>Model: Result
    else Runs past prompt threshold
        Bash->>Questions: Offer backgrounding, default threshold 15 seconds
        Controller->>Questions: Poll pending tool question
        Controller-->>TUI: Question for owning session
        TUI-->>User: Continue in foreground or background?
        User->>TUI: Decision
        TUI->>Questions: Persist answer
        Questions-->>Bash: User decision
        alt User accepts
            Bash->>Manager: Mark existing row backgrounded_at
            Manager->>Records: Publish accepted background ownership
            Bash-->>Model: Background PID and log reference
            Note over Process,Records: Same process continues, no second launch
        else User declines
            Bash->>Manager: Keep waiting under foreground supervision
            Process->>Records: Eventual completion or stop outcome
            Bash-->>Model: Foreground result
        end
    end
```

There is no `bg_start` tool or model-set background flag. The user controls the
transition. Foreground timeout/cancellation remains separate from `bg_status`.

## Record state and visibility

```mermaid
stateDiagram-v2
    [*] --> PrivateRunning: bash launches command
    PrivateRunning --> PrivateFinished: foreground process exits
    PrivateRunning --> AcceptedRunning: user accepts backgrounding
    PrivateRunning --> PrivateFinished: foreground timeout or cancellation stops process
    PrivateFinished --> Removed: provisional scheduler cleanup
    AcceptedRunning --> AcceptedFinished: process exits; completion can be notified
    AcceptedRunning --> AcceptedFinished: bg_status stop
    AcceptedRunning --> Removed: controller lifecycle stop and cleanup
    AcceptedFinished --> Removed: controller startup or shutdown cleanup
    Removed --> [*]
    note right of PrivateRunning
        Not listed by bg_status
        Private foreground owner
    end note
    note right of AcceptedRunning
        backgrounded_at is set
        Visible to owning session
    end note
```

## Completion notification and explicit stop

```mermaid
%%{init: {"sequence": {"wrap": true, "width": 110, "actorMargin": 20, "diagramMarginX": 10, "messageMargin": 30}}}%%
sequenceDiagram
    participant Process as Accepted background process
    participant Store as Record and status sidecar
    participant Poller as BackgroundProcessCompletionPoller
    participant Runtime as Controller / runtime event delivery
    participant Model
    participant Tool as BgStatusTool
    participant Manager as BackgroundProcessManager

    Process->>Store: Exit status becomes available
    Poller->>Store: Poll accepted session processes
    Poller->>Runtime: Report newly completed background work
    Runtime-->>Model: Completion notification
    Model->>Tool: bg_status(action=log, pid)
    Tool->>Manager: Resolve accepted record for current session
    Manager->>Store: Read bounded log tail
    Store-->>Model: Log tail and artifact reference
    opt Stop a still-running accepted job
        Model->>Tool: bg_status(action=stop, pid)
        Tool->>Manager: Resolve owned accepted process group
        Manager->>Process: SIGTERM
        Manager->>Process: Check exit during configured grace
        alt Still alive after grace
            Manager->>Process: SIGKILL
        end
        Manager-->>Tool: Stop outcome
    end
```

`bg_status` cannot list, read, or stop a private foreground row. A PID alone does not
grant authority over an arbitrary host process.

## Two cleanup owners

```mermaid
flowchart TD
    Scheduler["scheduler_default<br/>every 300 seconds"] --> Provisional[BackgroundProcessProvisionalCleanupTask]
    Provisional --> Private[Finished private foreground rows]
    Private --> DeletePrivate[Remove exact row-owned sidecars and row]
    Startup[Controller startup after owner lock] --> Lifecycle[BackgroundProcessControllerSessionLifecycleListener]
    Shutdown[Controller shutdown] --> Lifecycle
    Lifecycle --> Scope[Accepted jobs for owned parent and child scopes]
    Scope --> Running{Process still running?}
    Running -->|Yes| Stop[Stop recorded owned process group]
    Running -->|No| DeleteAccepted[Remove exact sidecars and row]
    Stop --> DeleteAccepted
```

Startup cleans leftovers after abrupt controller loss. Shutdown cleans current
accepted jobs. Background work may outlive an LLM turn, but it is not a service
promised to survive session-controller shutdown. Never clean unrelated or root-owned
processes, and never signal active workers tagged with `HATFIELD_SESSION_ID`.

Sources: [background process behavior](../docs/background-processes.md),
[BashTool](../src/CodingAgent/Tool/BashTool.php),
[BackgroundProcessManager](../src/CodingAgent/Tool/BackgroundProcessManager.php),
[completion poller](../src/CodingAgent/Runtime/Controller/BackgroundProcessCompletionPoller.php),
[lifecycle listener](../src/CodingAgent/Runtime/Controller/BackgroundProcessControllerSessionLifecycleListener.php).
