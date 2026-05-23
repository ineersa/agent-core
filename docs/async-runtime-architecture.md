# Async Runtime Architecture

Visual overview of the multi-process async agent runtime with ASCII diagrams.
Focus on topology, message flow, event delivery, and process supervision.

---

## 1. Process Topology

```
┌──────────────────────────────────────────────────────────────────┐
│                     TUI PROCESS (InteractiveMode)                 │
│  ┌──────────────┐  ┌──────────────────┐  ┌───────────────────┐   │
│  │ SubmitListener│  │RuntimeEventPoller│  │ CtrlCInputInterceptor│  │
│  └──────┬───────┘  └────────┬─────────┘  └───────────────────┘   │
│         │                   │                                     │
│         └───────┬───────────┘                                     │
│                 ▼                                                 │
│     JsonlProcessAgentSessionClient (AgentSessionClient)           │
│     ┌───────────────────────────────────────────────────────┐    │
│     │  proc_open() pipes: [0] stdin → [1] stdout ←          │    │
│     │  writeCommand()       readEvents()                    │    │
│     │  auto-restart: ensureProcessRunning()                 │    │
│     └───────────────────────┬───────────────────────────────┘    │
└─────────────────────────────┼────────────────────────────────────┘
                              │ JSONL over stdin/stdout pipes
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│              CONTROLLER PROCESS (HeadlessController)              │
│              bin/console agent --controller                       │
│                                                                   │
│  Revolt EventLoop::run()                                         │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │  onReadable(STDIN)  repeat(10ms)   repeat(50ms)          │     │
│  │  ┌──────────────┐  ┌───────────┐  ┌─────────────────┐   │     │
│  │  │handleCommand │  │pollLlm    │  │event drain      │   │     │
│  │  │Line()        │  │Stdout()   │  │(events.jsonl)   │   │     │
│  │  └──────┬───────┘  └─────┬─────┘  └────────┬────────┘   │     │
│  │         ▼                ▼                 ▼              │     │
│  │  EventDispatcher   emit()→TUI       emit()→TUI           │     │
│  │  ┌──────┼──────────────────────────────────────────┐     │     │
│  │  │ StartRun | UserMsg  | Cancel  | Resume  handlers│     │     │
│  │  │ Handlers call InProcessAgentSessionClient (sync) │     │     │
│  │  └──────────────────────────────────────────────────┘     │     │
│  └─────────────────────────────────────────────────────────┘     │
│                                                                   │
│  ConsumerSupervisor (Symfony Process)                             │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │  spawn($argv[0] messenger:consume <transport>)            │    │
│  │  supervise() every 5s — isRunning() check                │    │
│  │  shutdown() — stop(5, SIGTERM) → SIGKILL if needed       │    │
│  └───────────────┬──────────────────────────────────────────┘    │
└──────────────────┼───────────────────────────────────────────────┘
                   │ Symfony Process (child processes)
                   ▼
┌──────────────────────────────────────────────────────────────────┐
│                    MESSENGER CONSUMER PROCESSES                    │
│                                                                   │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │ messenger:consume│  │ messenger:consume│  │ messenger:consume│  │
│  │ run_control      │  │ llm              │  │ tool             │  │
│  │                  │  │                  │  │                  │  │
│  │ RunOrchestrator  │  │ ExecuteLlmStep   │  │ ExecuteToolCall  │  │
│  │ ├─ onStartRun    │  │ Worker           │  │ Worker           │  │
│  │ ├─ onApplyCmd    │  │                  │  │                  │  │
│  │ ├─ onAdvanceRun  │  │ → LLM HTTP call  │  │ → shell/browser  │  │
│  │ ├─ onLlmStepRes  │  │ → stdout pipe    │  │ → stdout pipe    │  │
│  │ └─ onToolCallRes │  │   JSONL deltas   │  │   JSONL deltas   │  │
│  └────────┬─────────┘  └────────┬─────────┘  └────────┬─────────┘
│           │                     │                     │
│           ▼                     ▼                     ▼
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │             Doctrine SQLite (.hatfield/messenger.sqlite)     │ │
│  │  3 queues: run_control | llm | tool                          │ │
│  │  PhpSerializer (run_control) | Symfony Serializer (llm,tool) │ │
│  └─────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

**Key boundaries:**
- **TUI → Controller**: JSONL over stdin/stdout pipe (proc_open)
- **Controller → Consumers**: Symfony Process spawn
- **Consumers ↔ each other**: Doctrine SQLite messenger queues
- **LLM Consumer → Controller**: STDOUT pipe from child process
- **RunCommit → Controller**: events.jsonl (file-based, shared between processes)

---

## 2. Message Flow Diagrams

### 2a. Start New Run

```
TUI                         Controller              run_control        LLM Consumer
│                              │                       │                  │
│ start_run(id, prompt) ──────►│                       │                  │
│                              │ command.ack ─────────►│                  │
│                              │                       │                  │
│                              │ EventDispatcher       │                  │
│                              │  → StartRunHandler    │                  │
│                              │    → client.start()   │                  │
│                              │      → runner.start() │                  │
│                              │        → commandBus   │                  │
│                              │          .dispatch() ─┼──► StartRun     │
│                              │ run.started(seq:0) ──►│                  │
│                              │                       │                  │
│                              │                       │ RunOrchestrator  │
│                              │                       │  onStartRun()    │
│                              │                       │  → RunCommit     │
│                              │                       │    → events.jsonl│
│                              │                       │  → AdvanceRun ──┼──► ExecuteLlmStep
│                              │                       │                  │
│                              │    ◄── 50ms drain ────│                  │
│ run.started(seq:1) ◄────────│                       │                  │
│ turn.started       ◄────────│                       │                  │
│                              │                       │                  │
│                              │    ◄── 10ms poll ──────────── STDOUT ◄──┤
│ thinking/text      ◄────────│   JSONL deltas ◄────────────────────────┤
│ deltas in realtime ◄────────│                       │                  │
│                              │                       │                  │
│                              │    ◄── 50ms drain ────│                  │
│ assistant.completed◄────────│                       │                  │
│ run.completed      ◄────────│                       │                  │
```

**Key timing:**
- command.ack: `< 20ms` (immediate ACK before dispatch)
- run.started (seq:0): immediate synthetic event
- Streaming deltas: `~10ms` latency (poll interval)
- Canonical events: `~50ms` latency (event drain interval)

### 2b. Streaming Deltas (thinking, text, tool args)

```
LLM CONSUMER PROCESS                    CONTROLLER PROCESS
┌──────────────────────┐               ┌──────────────────────────┐
│                      │               │                          │
│ Symfony AI           │               │ pollLlmStdout()          │
│  CompletionsClient   │               │  every 10ms              │
│  stream()            │               │                          │
│    │                 │               │  ┌────────────────────┐  │
│    │ ThinkingDelta   │               │  │ partial-line buffer│  │
│    ▼                 │               │  │ (accumulate JSONL  │  │
│ AssistantThinking    │               │  │  across polls)     │  │
│ StreamSubscriber     │               │  └────────┬───────────┘  │
│  → RuntimeEvent      │               │           │              │
│    .thinking_started │               │           ▼              │
│    .thinking_delta   │               │  Process::getIncremental │
│                      │               │  Output() from LLM child │
│  StdoutRuntimeEvent  │               │           │              │
│  Sink::emit()        │  STDOUT pipe   │           ▼              │
│  → fwrite(STDOUT)    │══════════════►│  RuntimeEvent::fromArray │
│  → fflush(STDOUT)    │  (JSONL lines)│  → emit() to TUI stdout  │
│                      │               │                          │
│  posix_isatty guard  │               │  Non-JSONL lines:        │
│  (pipe=true, tty=no) │               │  silently skipped         │
│                      │               │                          │
│  On write failure:   │               │  Malformed JSONL:        │
│  throw RuntimeException              │  logged, skipped          │
│  → consumer dies     │               │                          │
│  → ConsumerSupervisor│               │                          │
│    restarts          │               │                          │
└──────────────────────┘               └──────────────────────────┘
```

### 2c. Canonical Events (via events.jsonl)

```
run_control CONSUMER         CONTROLLER PROCESS              TUI
┌──────────────────┐        ┌───────────────────────┐      ┌─────┐
│                  │        │                       │      │     │
│ RunOrchestrator  │        │ event drain timer     │      │     │
│  onStartRun()    │        │  every 50ms           │      │     │
│  onAdvanceRun()  │        │                       │      │     │
│  onLlmStepResult │        │ runEventCursors       │      │     │
│  onToolCallResult│        │  {runId: lastSeq}     │      │     │
│    │             │        │                       │      │     │
│    ▼             │        │ InProcessAgentSession │      │     │
│  RunCommit       │        │ Client::events(runId) │      │     │
│    │             │        │  → EventStore         │      │     │
│    ▼             │        │    .allFor(runId)     │      │     │
│  events.jsonl ◄──┤ write  │    → RuntimeEvent[]   │      │     │
│  (LOCK_EX append)│        │                       │      │     │
│                  │        │  Skip:                │      │     │
│  state.json ◄────┤ write  │  - seq = 0 (transient)│     │     │
│  (CAS versioned) │        │  - seq ≤ cursor (dup)  │     │     │
│                  │        │                       │      │     │
│                  │        │  emit() → TUI stdout  │─────►│event│
└──────────────────┘        └───────────────────────┘      └─────┘
```

### 2d. Steer/Cancel Mid-Run

```
TUI                    Controller             run_control          LLM Consumer
│                         │                      │                    │
│ steer(id, text) ───────►│                      │                    │
│                         │ command.ack ─────────►│                    │
│                         │                      │                    │
│                         │ UserMessageHandler   │                    │
│                         │  → client.send()     │                    │
│                         │    → runner.steer()  │                    │
│                         │      → commandBus    │                    │
│                         │        .dispatch() ──┼──► ApplyCommand   │
│                         │                      │                    │
│                         │                      │ ApplyCommandHandler│
│                         │                      │  → inject message  │
│                         │                      │  → next LLM turn   │
│                         │                      │                    │
│  steer applied ◄────────│  = next turn.started │                    │
│                         │                      │                    │
│ ── OR ──                │                      │                    │
│                         │                      │                    │
│ cancel(id) ────────────►│                      │                    │
│                         │ command.ack ─────────►│                    │
│                         │                      │                    │
│                         │ CancelHandler        │                    │
│                         │  → client.cancel()   │                    │
│                         │    → runner.cancel() │                    │
│                         │      → commandBus    │                    │
│                         │        .dispatch() ──┼──► ApplyCommand   │
│                         │ run.cancelled ◄──────│   (cancel kind)   │
│                         │                      │                    │
│                         │                      │ CancelToken set    │
│                         │                      │  → LLM stop check  │
│                         │                      │  → Tool stop check │
│                         │                      │                    │
│                         │                      │ ◄──────────────────┤
│  cancellation ◄─────────│  events flow         │  consumer checks   │
│  events                 │  via event drain     │  cancel token      │
└─────────────────────────┴──────────────────────┴────────────────────┘
```

**Cancel ladder (cooperative, no hard kill in initial impl):**
1. Cancel command dispatched to run_control transport
2. RunOrchestrator sets cancel token in RunStore
3. LLM consumer checks token between stream chunks
4. Tool consumer checks token before execution
5. No SIGKILL of consumers (deferred to future worker status heartbeat)

### 2e. Controller Crash + Auto-Restart

```
TUI                        Controller(crashed!)       New Controller
│                              │                          │
│ readEvents() → pipe broken   │  (dead)                  │
│  → ensureProcessRunning()    │                          │
│    → enforceRestartRateLimit │                          │
│    → stopProcess() (cleanup) │  proc_terminate          │
│    → spawnProcess() ─────────┼─────────────────────────►│
│                              │                          │
│                              │                    proc_open()
│                              │  ◄──── runtime.ready ────│
│                              │                          │
│    ◄── waitForRuntimeReady() │                          │
│                              │                          │
│    writeCommand(resume) ─────┼─────────────────────────►│
│  (auto-resume activeRunId)   │                          │
│                              │                    ResumeHandler
│                              │                     → client.resume()
│                              │                       → runner.continue()
│                              │                         → ApplyCommand(Continue)
│                              │                           → run_control consumer
│                              │                             → load state from
│                              │                                events.jsonl
│                              │                                state.json
│                              │                             → continue run
│                              │                          │
│  run.resumed ◄───────────────│  ◄── event drain ────────│
│  turn.started ◄──────────────│  ◄── event drain ────────│
│  streaming deltas ◄──────────│  ◄── LLM stdout poll ────│
│                              │                          │
│  (session transparently      │                          │
│   continues from             │                          │
│   where it died)             │                          │
```

**Rate limit**: max 3 restarts per 60s sliding window. Exceeded → RuntimeException → session dead.

**Key detail**: `activeRunId` tracks current run for auto-resume. Set on start()/resume()/send()/cancel()/events(). Not cleared (run continues until terminal event from drain).

---

## 3. Doctrine SQLite Queue Layout

```
┌──────────────┬───────────────────────────────────────┬──────────────────┐
│ Queue        │ Messages                              │ Serializer        │
├──────────────┼───────────────────────────────────────┼──────────────────┤
│ run_control  │ StartRun                              │ PhpSerializer    │
│              │ ApplyCommand (steer/follow_up/cancel/  │ (native PHP)     │
│              │   continue/human_response)            │                  │
│              │ AdvanceRun                            │ Reason: StartRun  │
│              │ LlmStepResult  (sync, within process) │ contains complex  │
│              │ ToolCallResult (sync, within process) │ objects (AgentMsg │
│              │                                       │ [], RunMetadata)  │
├──────────────┼───────────────────────────────────────┼──────────────────┤
│ llm          │ ExecuteLlmStep                        │ Symfony          │
│              │                                       │ Serializer       │
│              │ Processed by: ExecuteLlmStepWorker    │ (scalar/array    │
│              │ Result → agent.command.bus (sync)     │ only)            │
├──────────────┼───────────────────────────────────────┼──────────────────┤
│ tool         │ ExecuteToolCall                       │ Symfony          │
│              │                                       │ Serializer       │
│              │ Processed by: ExecuteToolCallWorker   │ (scalar/array    │
│              │ Result → agent.command.bus (sync)     │ only)            │
└──────────────┴───────────────────────────────────────┴──────────────────┘

Storage:
  .hatfield/messenger.sqlite
  auto_setup: true (creates messenger_messages table on first send/get)
  PDO SQLite auto-creates DB file if parent dir is writable

Result routing (within consumer process):
  ExecuteLlmStepWorker  →  LlmStepResult  →  agent.command.bus  →  RunOrchestrator
  ExecuteToolCallWorker →  ToolCallResult →  agent.command.bus  →  RunOrchestrator

Why results stay sync:
  - AssistantMessage has polymorphic ContentInterface[] arrays
  - Default Symfony Serializer cannot round-trip them
  - RunOrchestrator is registered on agent.command.bus in same process
  - Self-advance callbacks (postCommit) dispatch AdvanceRun synchronously
```

---

## 4. Event Delivery Paths

```
═══════════════════════════════════════════════════════════════════════════
                      TWO PARALLEL EVENT DELIVERY PATHS
═══════════════════════════════════════════════════════════════════════════

   CANONICAL (seq > 0)                TRANSIENT (seq = 0)
   ═══════════════════                ═══════════════════

   run_control consumer              LLM consumer process
   ┌────────────────┐               ┌──────────────────────────────┐
   │ RunOrchestrator│               │ AssistantTextStreamSubscriber│
   │  onStartRun()  │               │ AssistantThinkingStreamSub.  │
   │  onAdvanceRun()│               │ ToolCallStreamSubscriber     │
   │  onLlmStepRes  │               │                              │
   │  onToolCallRes │               │  Each subscriber:            │
   └───────┬────────┘               │  sink.emit(event) ─── in-proc│
           │                         │  stdoutSink.emit(event) ────│──┐
           ▼                         │                              │  │
   ┌──────────────────┐              └──────────────────────────────┘  │
   │ RunCommit        │                                                 │
   │ → events.jsonl   │     StdoutRuntimeEventSink (in LLM consumer)   │
   │   LOCK_EX append │     ┌─────────────────────────────────────┐    │
   └────────┬─────────┘     │ posix_isatty guard (pipe only)      │    │
            │               │ fwrite(STDOUT) → JSONL line          │◄───┘
            ▼               │ fflush(STDOUT)                       │
   ┌──────────────────────┐ └──────────────────┬──────────────────┘
   │ Controller Event     │                    │
   │ Drain (50ms repeat)  │                    ▼
   │                      │     ┌──────────────────────────────────┐
   │ InProcessAgentSession│     │ Controller LLM Stdout Poll       │
   │ Client::events(runId)│     │ (10ms repeat)                    │
   │  → EventStore        │     │                                  │
   │    .allFor(runId)    │     │ ConsumerSupervisor               │
   │  → RuntimeEvent[]    │     │  → getProcess('llm')             │
   │                      │     │    → getIncrementalOutput()      │
   │ Skip:                │     │      → partial-line buffer       │
   │  - seq = 0           │     │      → parse JSONL               │
   │  - seq ≤ cursor      │     │      → RuntimeEvent::fromArray   │
   └──────────┬───────────┘     └──────────────────┬───────────────┘
              │                                    │
              └──────────────┬─────────────────────┘
                             ▼
              ┌──────────────────────────────────┐
              │ HeadlessController::emitInternal()│
              │ → JsonlCodec::encodeEvent()       │
              │ → fwrite(TUI stdout)              │
              │ → fflush(TUI stdout)              │
              └──────────────────┬───────────────┘
                                 │
                                 ▼
              ┌──────────────────────────────────┐
              │ TUI: JsonlProcessAgentSession    │
              │ Client::readEvents()              │
              │ → plain-line buffer               │
              │ → JsonlCodec::decodeEvent()       │
              │ → yield RuntimeEvent              │
              └──────────────────┬───────────────┘
                                 │
                                 ▼
              ┌──────────────────────────────────┐
              │ RuntimeEventPoller               │
              │ → updateActivity()               │
              │ → TranscriptProjector            │
              │ → screen.update()                │
              └──────────────────────────────────┘
```

---

## 5. Command Protocol

```
═══════════════════════════════════════════════════════════════════════════
                        TUI → CONTROLLER (stdin JSONL)
═══════════════════════════════════════════════════════════════════════════

Command           Payload
───────────────────────────────────────────────────────────────────────────
start_run         {type:"start_run", id:"cmd_...", payload:{prompt, model?, reasoning?}}
user_message      {type:"user_message", id:"cmd_...", runId, payload:{text}}
follow_up         {type:"follow_up", id:"cmd_...", runId, payload:{text}}
steer             {type:"steer", id:"cmd_...", runId, payload:{text}}
cancel            {type:"cancel", id:"cmd_...", runId}
resume            {type:"resume", id:"cmd_...", runId}

Handler dispatch (via EventDispatcher + #[AsEventListener]):
  start_run     → StartRunHandler     → client.start()
  user_message  → UserMessageHandler  → client.send(message)
  follow_up     → UserMessageHandler  → client.send(follow_up)
  steer         → UserMessageHandler  → client.send(steer → message)
  cancel        → CancelHandler       → client.cancel()
  resume        → ResumeHandler       → client.resume()

═══════════════════════════════════════════════════════════════════════════
                       CONTROLLER → TUI (stdout JSONL)
═══════════════════════════════════════════════════════════════════════════

Family          Event Type                Origin
───────────────────────────────────────────────────────────────────────────
runtime         runtime.ready             Controller boot complete
command         command.ack               Immediate (<20ms) on any command
command         command.rejected          Dispatch failure with reason
protocol        protocol.error            Invalid JSONL decode
lifecycle       run.started (seq:0)       Synthetic, after start dispatch
lifecycle       run.resumed (seq:1)       After resume handler
lifecycle       run.completed             From events.jsonl drain
lifecycle       run.failed                From events.jsonl drain
lifecycle       run.cancelled             From events.jsonl drain
lifecycle       turn.started              From events.jsonl drain
assistant_stream assistant.text_started    LLM stdout pipe (transient)
assistant_stream assistant.text_delta      LLM stdout pipe (transient)
assistant_stream assistant.text_completed  LLM stdout pipe (transient)
assistant_stream assistant.thinking_started LLM stdout pipe (transient)
assistant_stream assistant.thinking_delta   LLM stdout pipe (transient)
assistant_stream assistant.thinking_completed LLM stdout pipe (transient)
assistant_stream assistant.message_started  events.jsonl drain (canonical)
assistant_stream assistant.message_completed events.jsonl drain (canonical)
assistant_stream assistant.message_failed   events.jsonl drain (canonical)
tool            tool_call.started         LLM stdout pipe (transient)
tool            tool_call.arguments_delta LLM stdout pipe (transient)
tool            tool_call.arguments_completed LLM stdout pipe + events.jsonl
tool            tool_execution.started    events.jsonl drain (canonical)
tool            tool_execution.completed  events.jsonl drain (canonical)
tool            tool_execution.failed     events.jsonl drain (canonical)
cancellation    cancellation.requested     events.jsonl drain (canonical)
metadata        usage.updated             events.jsonl drain (canonical)
```

**Deduplication**: TUI RuntimeEventPoller tracks `lastSeq` per runId.
Seq=0 events (transient) are never deduplicated. Seq>0 events skip if seq ≤ cursor.

---

## 6. Consumer Supervision

```
┌──────────────────────────────────────────────────────────────────┐
│                    CONSUMER SUPERVISION FLOW                      │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ConsumerSupervisor::launch(transportName)                        │
│  ┌────────────────────────────────────────────────┐              │
│  │ entrypoint = $_SERVER['argv'][0]                │              │
│  │ cwd = getcwd()                                 │              │
│  │                                                │              │
│  │ Process([                                      │              │
│  │   PHP_BINARY,                                  │              │
│  │   entrypoint,           // bin/console / PHAR  │              │
│  │   'messenger:consume',                         │              │
│  │   transportName,        // run_control/llm/tool│              │
│  │   '--no-interaction',                          │              │
│  │   '--time-limit=3600',  // 1h max lifetime     │              │
│  │ ],                                            │              │
│  │   cwd: cwd,                                   │              │
│  │   timeout: null,        // non-blocking        │              │
│  │ )                                              │              │
│  │ → start()  // async start, don't wait         │              │
│  └────────────────────────────────────────────────┘              │
│                           │                                       │
│                           ▼                                       │
│  ConsumerSupervisor::supervise()  [every 5s via EventLoop::repeat]│
│  ┌────────────────────────────────────────────────┐              │
│  │ for each consumer in $consumers:               │              │
│  │   if isRunning() → OK, continue               │              │
│  │   if crashed:                                 │              │
│  │     log exitCode + stderr                     │              │
│  │     unset($consumers[transport])              │              │
│  │     → attemptRestart(transport)               │              │
│  └────────────────────────────────────────────────┘              │
│                           │                                       │
│                           ▼                                       │
│  ConsumerSupervisor::attemptRestart(transportName)                │
│  ┌──────────────────────────────────────────────────────────────┐│
│  │ restartWindows[transport]: sliding 60s window                ││
│  │ restartCounts[transport]:  current count in window            ││
│  │                                                              ││
│  │ if count ≥ MAX_RESTARTS(3) → CRITICAL log, BAIL              ││
│  │                                                              ││
│  │ delay = INITIAL(1000ms) × 2^count = {1s, 2s, 4s}            ││
│  │                                                              ││
│  │ EventLoop::delay(delay_seconds):  ← NON-BLOCKING             ││
│  │   if restart window still valid:                             ││
│  │     → launch(transportName)                                  ││
│  └──────────────────────────────────────────────────────────────┘│
│                                                                   │
│  ConsumerSupervisor::shutdown()                                   │
│  ┌────────────────────────────────────────────────┐              │
│  │ for each consumer:                             │              │
│  │   process->stop(5, SIGTERM)  // 5s grace       │              │
│  │   if still running → SIGKILL  // hard kill     │              │
│  │ $consumers = []                                │              │
│  └────────────────────────────────────────────────┘              │
└──────────────────────────────────────────────────────────────────┘
```

**Orphan cleanup at controller startup** (HeadlessController::killOrphanedConsumers):
```
pgrep -f messenger:consume
  → for each PID:
    → check ppid == 1 (orphaned by SIGKILL'd parent)
    → check /proc/pid/cwd matches our CWD
    → check /proc/pid/cmdline contains known queue name
    → posix_kill(SIGTERM) → 500ms wait → posix_kill(SIGKILL) if alive
```

---

## 7. Controller Event Loop (Revolt)

```
┌──────────────────────────────────────────────────────────────────┐
│                HeadlessController::run()                          │
│                Revolt EventLoop::run()                            │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │ INITIALIZATION                                               │ │
│  │  fopen('php://stdout','w')                                   │ │
│  │  killOrphanedConsumers()                                     │ │
│  │  emit(runtime.ready) ← tells TUI controller is alive         │ │
│  │  launch(run_control)                                         │ │
│  │  launch(llm)                                                 │ │
│  │  launch(tool)                                                │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │ EVENT WATCHERS & TIMERS                                      │ │
│  │                                                              │ │
│  │ onReadable(STDIN)        ──► TUI commands                   │ │
│  │   fgets() → parse JSONL → decodeCommand()                    │ │
│  │   → ackCommand() (command.ack JSONL to stdout)               │ │
│  │   → EventDispatcher → CommandHandler (dispatch to consumer)  │ │
│  │   EOF → cancel watcher                                       │ │
│  │                                                              │ │
│  │ repeat(10ms)             ──► LLM stream poll                │ │
│  │   pollLlmStdout()                                            │ │
│  │   → getProcess('llm')->getIncrementalOutput()                │ │
│  │   → partial-line buffer → parse JSONL → emit to TUI          │ │
│  │                                                              │ │
│  │ repeat(50ms)             ──► Canonical event drain          │ │
│  │   InProcessAgentSessionClient::events(runId)                 │ │
│  │   → events.jsonl (seq > 0, skip seq ≤ cursor)               │ │
│  │   → emit to TUI                                              │ │
│  │                                                              │ │
│  │ repeat(5s)               ──► Consumer supervision           │ │
│  │   ConsumerSupervisor::supervise()                             │ │
│  │   → isRunning() checks → restart on crash                    │ │
│  │                                                              │ │
│  │ onSignal(SIGTERM)        ──► Graceful shutdown              │ │
│  │ onSignal(SIGINT)                                            │ │
│  │   → shutdown() → ConsumerSupervisor::shutdown()              │ │
│  │   → EventLoop::stop()                                        │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  emit(RuntimeEvent):                                             │
│    → register cursor on run.started / run.resumed                │
│    → release cursor on run.completed / run.failed / run.cancelled│
│    → emitInternal(): JsonlCodec::encodeEvent() → fwrite(stdout)  │
│      → fflush(stdout)                                            │
│      → on write failure: shutdown + EventLoop::stop()            │
└──────────────────────────────────────────────────────────────────┘
```

---

## 8. Session Storage Layout

```
.hatfield/
├── settings.yaml              Project-local settings (LLM config, themes)
│
├── messenger.sqlite           Doctrine SQLite transport
│   └── messenger_messages     Queue table (auto_setup: true)
│
└── sessions/
    └── <runId>/               runId = session_id = UUID
        ├── metadata.yaml      {run_id, model, timestamps, session metadata}
        ├── state.json         Current run state (CAS versioned with LOCK_EX)
        │                      {status, turnNo, messages, context, ...}
        ├── events.jsonl       Canonical events (append-only, LOCK_EX)
        │                      Each line: {v, type, runId, seq, timestamp, payload}
        │                      seq starts at 1 (seq=0 reserved for transient)
        ├── transcript.jsonl   Projected transcript blocks (regenerated)
        │                      [{role, content, timestamp, ...}]
        └── idempotency.jsonl  Cross-process idempotency log
                               {idempotencyKey, timestamp, handlerClass}
                               Used by RunMessageProcessor for CAS retry
```

---

## 9. Key Classes Quick Reference

```
Class                           Layer       Role
───────────────────────────────────────────────────────────────────────────
JsonlProcessAgentSessionClient  Process/    TUI→Controller bridge
                                │           proc_open, JSONL protocol,
                                │           auto-restart, rate limiting

HeadlessController              Controller/ Revolt event loop hub
                                │           stdin→EventDispatcher,
                                │           stdout→TUI, consumer lifecycle

ConsumerSupervisor              Controller/ Symfony Process spawn+supervise
                                │           5s isRunning checks,
                                │           EventLoop::delay backoff

StartRunHandler                 CommandH/   controller cmd → start_run
UserMessageHandler              CommandH/   controller cmd → steer/follow_up
CancelHandler                   CommandH/   controller cmd → cancel
ResumeHandler                   CommandH/   controller cmd → resume (crash recovery)

StdoutRuntimeEventSink          Stream/     STDOUT JSONL from LLM consumer
                                │           posix_isatty guard, fflush

AssistantTextStreamSubscriber   Stream/     TextDelta → runtime events
AssistantThinkingStreamSub.     Stream/     ThinkingDelta → runtime events
ToolCallStreamSubscriber        Stream/     ToolCallStart/Delta → runtime events

InProcessAgentSessionClient     InProcess/  Controller-side sync AgentCore calls
                                │           (used by command handlers)

RuntimeEventPoller              Tui/Runtime/ TUI poller: events→activity→transcript
SubmitListener                  Tui/Listener/ TUI input→client.start/send/cancel
TickPollListener                Tui/        50ms poll trigger in TUI event loop

AgentRunner                     AgentCore/  Command bus dispatch entry point
RunOrchestrator                 CodingAgent/ All command bus message handlers
                                │           (one class, 5 #[AsMessageHandler] methods)

RunMessageProcessor             CodingAgent/ CAS retry loop (3x, 50/100/200ms)
JsonlIdempotencyStore           CodingAgent/ Cross-process idempotency.ioCR file
HatfieldSessionStore            CodingAgent/ Session path resolution, .hatfield/
SessionRunStore                 CodingAgent/ state.json CAS read/write
SessionRunEventStore            CodingAgent/ events.jsonl append-only write
```

---

## 10. Cancel Ladder

```
┌──────────────────────────────────────────────────────────────────┐
│  Level  Description                        Latency    Mechanism  │
├──────────────────────────────────────────────────────────────────┤
│    1    Cancel token check (cooperative)   1-100ms    token in   │
│         LLM stream chunk check                        RunStore    │
│         Tool pre-execution check                                     │
│                                                                   │
│    2    Tool process kill               < 1s       SIGTERM to    │
│         (future: per-run PID tracking)              tool process  │
│    3    Consumer SIGTERM                < 5s       ConsumerSup-   │
│         (future: worker status heartbeat)          ervisor.stop() │
│    4    Consumer SIGKILL                < 200ms    posix_kill(9)  │
│         (absolute last resort)                                 │
└──────────────────────────────────────────────────────────────────┘

Initial implementation: Level 1 only (cooperative cancel).
Levels 2-4 deferred to future worker status heartbeat + per-run PID tracking.
```

---

## 11. Startup Sequence

```
TUI Process                     Controller Process         Consumers
──────                         ──────────────────         ─────────
InteractiveMode
  → sessionInit.initialize()
  → client.start(request)
    → ensureProcessRunning()
      → proc_open(agent --controller)
      → waitForRuntimeReady()
        ◄──────── runtime.ready ──────┐
                                      │ boot kernel
                                      │ killOrphanedConsumers()
                                      │ → emit(runtime.ready)
                                      │ → launch(run_control) ──► start
                                      │ → launch(llm)      ─────► start
                                      │ → launch(tool)     ─────► start
                                      │ → EventLoop::run()
  → writeCommand(start_run) ────────►│
                                      │ handleCommandLine()
                                      │ → command.ack ─────────────► TUI
                                      │ → StartRunHandler
                                      │   → client.start()
                                      │     → runner.start()
                                      │       → commandBus.dispatch()
                                      │         → run_control queue
                                      │ ◄──────── run.started(seq:0)
        ◄── run.started ◄─────────────│
  → RunHandle{runId, running}
  → screen.setTranscript
  → TUI event loop:
      TickPollListener(50ms)
        → RuntimeEventPoller.poll()
          → client.events(runId)
            → readEvents() ──────────►│
          ◄── events ◄────────────────│ event drain + LLM stdout
```<｜end▁of▁thinking｜>Now let me validate it compiles and commit:

<｜｜DSML｜｜tool_calls>
<｜｜DSML｜｜invoke name="bash">
<｜｜DSML｜｜parameter name="command" string="true">cd /home/ineersa/projects/agent-core-worktrees/async-stdout-runtime-events && wc -l docs/async-runtime-architecture.md && echo "---" && head -3 docs/async-runtime-architecture.md