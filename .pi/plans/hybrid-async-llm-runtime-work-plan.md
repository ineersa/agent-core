# Investigate and implement a concurrent LLM executor

Status: proposed work procedure. No implementation tasks or product changes are authorized by publishing this plan.

Use this procedure with the [architecture explanation](hybrid-async-llm-runtime-plan.md). Read that explanation for the rationale, current memory observations, diagrams, alternatives, and recovery constraints. This file specifies actions and evidence for a future approved implementation.

## Establish ownership and prerequisites

1. Obtain approval for the first investigation slice before creating implementation tasks.
2. Load the active task-workflow router and the applicable phase procedure.
3. Run `task_list` before tracked work.
4. Read the exact task, its finalized scope, root `AGENTS.md`, and the nearest module instructions.
5. Load `.agents/skills/testing/SKILL.md` and read `tests/AGENTS.md` before planning or editing tests.
6. Keep each experiment in an isolated task worktree.
7. Use the exact worktree path with JetBrains semantic tools.
8. Keep production settings and active session processes unchanged during experiments.

Main owns the integrated design and product decisions. Read-only scouts can investigate independent providers or framework contracts. Keep write ownership sequential unless separate worktrees and an integration order are explicitly assigned.

Treat the phases below as dependent slices, not a request for one large rewrite. Do not create all implementation tasks before the feasibility gates settle the design.

## Prepare an independently approved Symfony AI 0.13 upgrade

Complete this dependency migration before finalizing the concurrent consumer integration. Keep the existing fixed worker topology during the upgrade. Read the [verified upstream findings](hybrid-async-llm-runtime-plan.md#upstream-findings-and-the-symfony-ai-013-baseline) for release evidence and the distinction between lazy execution and scheduling.

1. Obtain approval for the dependency upgrade as a separate tracked slice.
2. Inspect the installed lockfile and the full 0.12-to-0.13 changes across all used Symfony AI packages.
3. Confirm PR 2436's released `Execution` contract against tagged source, not an open PR branch.
4. Trace `AgentInterface` implementations, decorators, mocks, direct `Agent` construction, and result consumers with IDE references.
5. Migrate `ConfiguredModelAgentRunner` tool-loop wiring, isolated toolbox, tool budget, and fault-tolerant tool behavior to supported 0.13 facilities.
6. Replace concrete `StreamResult` assumptions with supported consumption that actually drives streamed `Execution` to completion.
7. Keep result consumption inside the intended error, tracing, and lifecycle scopes because work and exceptions are lazy.
8. Verify one-shot iteration, final-result caching, streamed metadata, and resource cleanup when iteration stops early.
9. Recheck Platform converters, provider bridges, stream errors, assistant replay, and tool-call deltas for other 0.13 changes.
10. Run targeted Castor checks and the tracked transition's required full gate.

Prove that an extension job with a streamed lazy result actually executes and completes before completion is logged. Prove that a deferred exception is propagated through the established failure path. Prove tool-budget isolation between separate executions. Do not add a second full agent orchestration loop around `Execution`.

Update the dependency lock through existing project facilities and reload the IDE build model if its dependency view becomes stale. Record the new lock baseline and repeat the relevant fixed-topology measurements. Compare the async candidate against this upgraded synchronous baseline, not against a different dependency version.

Do not install PR 1829's proposed Fiber strategy or assume it shipped in 0.13. Do not add PHP 8.6 as a prerequisite for `Io\Poll`. Neither change is needed to evaluate the existing Platform parallel-call facility.

## Phase 0: record a controlled baseline

### Record the source and runtime environment

1. Record the commit, installed dependency versions, executable type, PHP version, and build mode.
2. Read worker counts through the Hatfield settings tool, not settings-file reads.
3. Record enabled provider paths and extension job capabilities without recording credentials or prompts.
4. Identify the owned controller tree and distinguish its consumers from unrelated sessions and QA processes.
5. Record whether native extensions or observability agents materially affect process memory.

Use `git rev-parse HEAD` and dependency lock metadata for provenance. Use `castor list` to discover existing QA and diagnostic facilities. If a repeatable measurement needs code, add a focused Castor-owned investigation task rather than an ad hoc production command.

### Measure comparable scenarios

Measure these scenarios with equivalent fixture payloads and the same configured concurrency:

1. A fresh idle controller after consumers are ready.
2. One ordinary model turn.
3. Four independent child model requests.
4. Two multi-turn children where one request remains pending while another completes and queues its next turn.
5. A compaction request alongside an ordinary request.
6. Mixed supported HTTP and WebSocket requests.
7. A cancellation while the provider supplies no chunks.
8. Return to idle after repeated bounded work.

Use deterministic local provider fixtures for behavioral measurements. Use a separate controlled performance run for representative payload sizes. Do not use production provider traffic as a timing fixture.

Record the following for each scenario:

- Whole-tree RSS and PSS, with explicit inclusion of TUI and external children where measured.
- Per-role PSS and PHP heap peak, reported separately.
- Active requests, claimed envelopes, and queued envelopes.
- Queue-to-admission latency and time to first visible delta.
- Time from one request's completion to admission of queued work.
- Cancellation observation and resource-release latency.
- CPU time, queue polling frequency, and observed SQLite contention.
- Live request payload sizes and retained memory after settlement.
- Process and socket counts before startup and after teardown.

Do not infer a leak from one high allocator watermark. Compare repeated equivalent cycles and retained request references. Do not claim PSS can attribute memory to individual requests within one process.

### Inventory the execution contract

Trace these production entry points and their callers:

- `ExecuteLlmStepWorker` and `ExecuteCompactionStepWorker`.
- `LlmPlatformAdapter` and `PreparedInvocationPlatform`.
- `SymfonyAiProviderFactory` and all configured model clients.
- `LlmWorkerFailedEventSubscriber` and the LLM retry strategy.
- `ConsumerSupervisor` and controller shutdown handling.
- Run cancellation status readers and provider-specific cancellation.
- Stream observers, tracing, request hooks, configured resetters, and provider caches.

Recheck the installed Messenger code directly. Verify where the command constructs `Worker`, when it calls execution hooks, when it drains acknowledgements, and when services reset. Do not design against a method that the installed loop never calls.

### Phase 0 exit gate

Publish a compact evidence report with source anchors, observed memory, supported provider paths, and unresolved integration points. Separate measured facts from estimates.

Do not proceed if the estimated process saving is too small to justify the concurrency work. Present the measured trade-off rather than introducing new runtime machinery automatically.

## Phase 1: prove provider progress and Messenger ownership separately

### Experiment A: cooperative provider execution

Start from Symfony's documented pattern of invoking several Platform requests before consuming their deferred results. Establish which adapters start network work lazily, then test readiness-based stream progress. Do not count an array of lazy Agent `Execution` objects as active requests: consuming those objects drives their side effects.

1. Use an existing Symfony test container and local controlled transport fixtures.
2. Admit request A and hold its response behind an explicit barrier.
3. Admit request B and deliver a chunk before releasing A.
4. Assert B's correctly attributed chunk arrives while A remains pending.
5. Finish B and assert its result without finishing A.
6. Cancel A through the real cancellation seam while A has no readable chunk.
7. Assert A releases only its own response resources.

Repeat the proof at the provider adapters' lowest correct layer. Cover a generic HTTP path first. Extend it to Grok, Codex HTTP, and Codex WebSocket before approving production cutover.

For Codex HTTP, preserve the documented buffered behavior unless incremental streaming is explicitly included in a separate approved scope. Prove concurrent network progress without claiming intermediate Codex deltas that the current parser does not emit.

For WebSocket, prove exclusive active leases and correct behavior when another request uses the same connection identity. Preserve uncertain-delivery classifications and existing retry restrictions.

For mixed HTTP and WebSocket, hold one transport pending and advance the other. Reject a design that succeeds only when requests use the same transport type.

Document the actual cooperative seam. Identify any bridge that blocks during connection, headers, authentication, conversion, retries, or response iteration.

### Experiment B: native Messenger completion

1. Boot the kernel with isolated application and Messenger databases.
2. Deliver two real execution envelopes through the candidate integration.
3. Hold one provider request behind a barrier.
4. Complete the other request and dispatch its existing result.
5. Inspect transport state at the actual acknowledgement boundary.
6. Assert an admitted but unfinished request remains unacknowledged.
7. Fail one request through the existing retryable exception path.
8. Assert the unrelated request is neither retried nor failed.
9. Fail result dispatch and verify the existing unrecoverable behavior.
10. Verify the exhausted-failure subscriber still sees receiver name `llm` and the expected envelope identity.

Use `BatchHandlerInterface` only as a candidate, not as an assumed final architecture. Test partial-batch behavior and the distinction between calling `Acknowledger::ack()` and the worker persisting the acknowledgement.

### Experiment C: reject a whole-wave barrier

1. Set active capacity to two.
2. Admit A and B through Messenger.
3. Keep B pending behind a barrier.
4. Complete A and queue C through the normal effect path.
5. Assert C reaches provider admission before B is released.

This experiment is a release requirement for continuous admission. It does not depend on a race or a generous timeout. If C cannot start, record the candidate as a bounded-wave implementation.

Do not promote a bounded-wave candidate by changing the requirement silently. Keep it as evidence or request an explicit product decision about the latency trade-off.

### Experiment D: cap admission and preserve resets

1. Fill the configured active capacity with barrier-held requests.
2. Queue additional envelopes without releasing active requests.
3. Assert the candidate does not accumulate an unbounded private claimed queue.
4. Release one request and assert exactly the permitted replacement work enters.
5. Trigger the candidate's reset boundary while another request is active.
6. Assert no shared reset closes or clears the active request's resources.
7. Drain all requests and verify the intended global reset or recycle occurs.

Account for the framework's fetch batch explicitly. If admission can transiently claim one extra envelope, document and bound that behavior rather than calling fetch size the concurrency cap.

### Phase 1 decision gate

Choose the smallest candidate that passes all required contracts using existing facilities.

If continuous admission needs a custom consumer, publish a focused design comparison before implementation. Include:

- The supported framework extension point or upstream change.
- The exact command and service wiring.
- The owner of claim, completion, failure events, and retry publication.
- The admission algorithm and the point where it drives provider I/O.
- The reset and shutdown policy.
- The amount of Messenger internals that would otherwise be duplicated.
- The effect on current Castor and controller test facilities.

Obtain explicit approval before replacing framework facilities, adding a dependency, changing recovery, or accepting bounded-wave scheduling. Do not ask the user whether a technical integration works. Answer that from the experiments.

## Phase 2: separate provider lifecycle without duplicating behavior

### Preserve preparation and finalization

1. Identify the smallest owning module for prepared invocation state.
2. Reuse current model resolution, context hooks, conversion hooks, and tool schema generation.
3. Keep synchronous preparation non-interleaved until its shared-state audit passes.
4. Create request-local accumulators for deltas, tool calls, usage, notifications, and errors.
5. Retain existing provider converters through the proven cooperative adapter.
6. Reuse existing `PlatformInvocationResult` construction and worker result mapping.
7. Preserve the thinking-only retry, retryable failure classification, and diagnostic sanitization.
8. Release raw responses and request buffers once the existing completion path no longer needs them.

Do not add domain dependencies on transport responses, Amp, Revolt, Messenger worker internals, or TUI types. Use `depfile.yaml` to establish the allowed module placement.

Keep a synchronous entry point only for supported callers that still need it. Implement it through shared preparation and finalization when practical. Do not keep two independent prompt, schema, or parsing pipelines.

### Close the state audit

Verify each concurrent request has distinct:

- Operation and delivery identity.
- Current model, reasoning selection, tool snapshot, and cancellation state.
- Provider response, parser state, and connection lease.
- Stream buffer and final result state.
- Log correlation and tracing scope.
- Completion guard and owned cleanup actions.

Inspect extension hooks and provider auth refresh for process-wide mutation. Do not assume a Fiber-local logger makes the whole service graph safe.

Keep tools out of concurrent execution. The synchronous tool context stack remains unchanged in this scope.

### Phase 2 exit gate

Demonstrate output parity at the provider boundary for ordinary turns and compaction. Include tools-disabled compaction, model selection at invocation time, retry cases, malformed responses, and cancellation.

Reject a seam that duplicates provider protocol implementations or requires one container per active request without an evidence-backed justification.

## Phase 3: integrate the approved continuous executor

### Preserve delivery behavior

1. Keep the existing `llm` transport and message classes.
2. Limit the new scheduling behavior to that transport.
3. Admit work according to the approved cap before starting provider I/O.
4. Keep each original delivery alive until its existing outcome path completes.
5. Publish `LlmStepResult` and `CompactionStepResult` through the existing command bus.
6. Preserve failure subscriber ordering and transport retry behavior.
7. Keep result publication separate from canonical state mutation.
8. Preserve operation identity on retries and explicit repair.

Do not add raw transport SQL, a new receipt ledger, or a custom retry queue. Do not acknowledge all requests because one batch finishes.

Resolve duplicate-active-envelope behavior explicitly. Preserve each transport receipt separately from the logical operation. Prove that no active record is overwritten and no duplicate is silently declared complete.

### Implement lifecycle ownership

1. Keep the controller as owner of the LLM process.
2. Stop admission before graceful drain or recycle.
3. Observe cancellation independently of incoming response chunks.
4. Finalize or cancel owned work within the existing bounded shutdown policy.
5. Preserve unacknowledged claims when abrupt termination prevents settlement.
6. Emit sanitized diagnostics for affected operations.
7. Preserve explicit repair rather than resetting claims at startup.

Do not signal active development-session workers during proof. Test processes must have explicit ownership, isolated databases, and deterministic teardown.

For a batch candidate, settle every admitted acknowledger while the process remains alive. Its destructor throws if it is discarded without completion. Do not mistake that exception for successful drain or attempt to promise cleanup after abrupt process loss.

### Prove failure isolation

Cover these cases at the lowest correct layer:

- A fails while B succeeds.
- A is cancelled while B continues streaming.
- A's result dispatch fails while B remains valid.
- A malformed stream does not corrupt B's parser.
- A retry waits without blocking unrelated active streams.
- A global database failure does not cause unsafe automatic provider reinvocation.
- A worker crash strands only admitted work under current claim semantics.
- A restart does not claim that stranded work was repaired.

Use a minimal real worker subprocess proof for crash and shutdown behavior that cannot be established in-process. Do not repeat complete agent journeys for each exception branch.

### Phase 3 exit gate

Accept the integration only after independent review of acknowledgement, failure, shared-state, and lifecycle code. Record the framework behavior relied upon and the tests that detect future dependency changes.

## Phase 4: complete provider coverage and change process topology

### Finalize product decisions

Obtain explicit decisions for any unresolved user-visible changes before implementation:

1. The replacement concurrency setting for the old process-count setting.
2. The supported provider set for the production cutover.
3. Any proposed change to crash recovery or cancellation guarantees.
4. Any framework replacement required by the feasibility report.

Do not silently reinterpret `runtime.llm_worker_count`. Do not add compatibility aliases during active development. Do not add an automatic provider fallback to make an incomplete adapter appear supported.

### Change only the LLM lane

1. Update `HeadlessController` to start one dedicated concurrent LLM process through the approved integration.
2. Preserve eager startup and existing readiness meaning.
3. Keep the tool, agent, MCP, extension-agent, scheduler, and run-control process boundaries unchanged.
4. Preserve consumer stdout event attribution and bounded buffering.
5. Preserve supervision, restart diagnostics, and process ownership.
6. Remove superseded LLM-pool code and tests whose contracts no longer exist.
7. Retain or replace behavioral tests that protect the same user-visible concurrency contract.

Prove that concurrent stream callbacks cannot interleave partial JSONL records on stdout. Exercise a stalled controller reader and verify bounded output buffering, continued cancellation checks, and explicit handling when output cannot progress. Do not let one synchronous stdout write freeze every active request indefinitely.

Update the owning module instructions and runtime docs when their descriptions change. Update settings documentation and defaults only after the setting decision is finalized. All Hatfield settings operations use the settings tool.

### Phase 4 exit gate

Require ordinary turns, compaction, parent and child execution, mixed providers, cancellation, retry exhaustion, and shutdown to pass through the actual process-mode runtime. Do not approve based solely on mock HTTP overlap.

Do not expose a partially supported executor as the default. Keep the cutover on the implementation branch until all finalized supported paths pass.

## Phase 5: compare equal workloads and decide whether to ship

1. Repeat the Phase 0 workload matrix with equivalent inputs and active-request capacity.
2. Compare whole-tree PSS, not only the new process's PHP heap.
3. Report CPU and polling changes rather than assuming an event loop reduces all database work.
4. Compare request admission, first visible delta, continuation admission, and cancellation.
5. Compare post-work memory retention across repeated equivalent cycles.
6. Check process and resource teardown after the measurements.
7. Record the measured saving and any degraded behavior.

Keep these acceptance conditions explicit:

- Fewer LLM PHP processes at equal active-request capacity.
- Lower whole-tree resident footprint for the representative workload.
- Independent progress of active requests.
- Admission of queued work when a slot becomes free, without a whole-wave barrier.
- No new loss of acknowledgement, retry, cancellation, or result-delivery semantics.
- No provider or extension contract silently dropped.
- No shared-state contamination or resource leak.

A performance improvement must not come from reducing concurrency, disabling tracing only in one variant, omitting supported providers, or shrinking test payloads.

If the candidate fails acceptance, retain the current runtime and record the evidence. Do not compensate by increasing memory limits, hiding a blocking path, or adding a speculative fallback.

## Validation procedure

### Reuse existing test infrastructure

Start with these existing facilities and their current owning tests:

- `IsolatedKernelTestCase` for database and container behavior.
- `ControllerReplayE2eTestCase` for real process-mode runtime contracts.
- `ConsumerSupervisorTest` and `ConsumerStdoutPollerTest` for ownership and output.
- `HeadlessControllerLlmWorkerPoolProcessTest` for current pool behavior that needs contract-preserving replacement at cutover.
- `LlmWorkerFailedEventSubscriberTest` for exhausted failure delivery.
- `DeferredToolCompletionRuntimeTest` and deferred subagent launch and recovery tests for unchanged child semantics.
- Existing provider bridge and result-converter tests for protocol behavior.

Find the exact current paths through IDE navigation before editing. The names above identify existing tests, not an instruction to create duplicate files.

### Keep concurrency proof deterministic

Use explicit barriers, controlled response chunks, ready notifications, and owned socket or process fixtures. Ensure failure releases every barrier during teardown.

Do not use arbitrary sleeps, delayed fixtures, elapsed-time races, retry-until-green, or increased timeouts. Keep individual cases within the repository's ten-second ceiling. A synthetic transport can prove ordering, but at least one controlled real I/O proof must establish that the network adapter actually yields.

Do not use real production providers for these proofs. Keep any required live provider smoke on the configured test model through Castor.

### Run the appropriate Castor lanes

Discover available commands with `castor list`. During implementation, run focused checks from the exact task worktree:

```bash
castor test --filter=<focused-contract>
castor deptrac
castor phpstan <changed-path>
castor docs:validate
```

Run `castor test:controller-replay` when the process integration is ready. Use minimal TUI coverage for actual terminal and process contracts rather than another broad journey.

Use focused `castor test:llm-real` for changed provider-visible behavior, according to the testing skill. Do not treat deterministic replay as proof that an unsupported live stream adapter is correct.

For tracked implementation tasks, `move_task` to `CODE-REVIEW` owns the full `castor check` gate. Do not run the full gate separately immediately before that transition. If required environments are unavailable, remain `IN-PROGRESS` with the blocker.

### Obtain independent review

Request read-only review against the exact revision and finalized requirements. Require review of:

- Framework extension-point claims against installed code.
- Per-envelope acknowledgement and retry semantics.
- Provider conversion parity and mixed-transport progress.
- Shared state, resets, and tracing.
- Cancellation, crash, and process teardown behavior.
- Memory evidence at equal concurrency.
- Absence of unsupported APIs, settings, fallback paths, and architectural dependencies.

Do not accept a test-related handoff unless the reviewer states that the testing skill and `tests/AGENTS.md` were read and followed.

## Deliverables by slice

Produce these artifacts sequentially:

1. Baseline and framework/provider capability report.
2. Deterministic feasibility evidence and the selected integration decision.
3. Provider lifecycle seam with parity evidence.
4. Approved concurrent Messenger integration with isolation and lifecycle proof.
5. Complete provider coverage and the one-process LLM cutover.
6. Equal-workload performance report and final independent review.

Do not assign calendar estimates until the converter and Messenger feasibility gates are complete. Those gates determine whether this is a contained integration or a framework-level project.

## Documentation-only validation for this plan

For the plan publication itself, run `castor docs:validate`, review relative links and Mermaid source, and inspect the Git diff. The docs validator covers the bundled documentation catalog and does not establish runtime correctness or necessarily validate `.pi/plans` diagrams.

No runtime tests, settings changes, or worker restarts are required to publish this plan. Record those limits honestly in the completion report.
