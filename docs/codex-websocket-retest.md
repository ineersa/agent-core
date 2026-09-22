# Cached Codex WebSocket retest

Investigation date: 2026-09-22. Task: `2026-09-08-retest-and-stabilize-cached-codex-websocket-transport`.

## Finding and fix

The direct transport probe passed, but an actual controller session reproduced a continuation defect. Cached connections were reused while every normal assistant turn and tool result sent full history with `reason=divergent_input`.

`CodexWebSocketContinuationComparator` compared JSON encodings literally. Provider output contains fields that `CodexContract` does not replay, and the normalizers emit object keys in a different order. Tool arguments also pass through JSON decoding and encoding. Feeding raw provider items directly into the next request, as the first probe did, bypassed these differences.

The fix compares equivalent history representations without changing transmitted items:

- Object key order does not affect equality. List order still matters.
- Assistant message comparison excludes `id`, `status`, and `phase`, which the assistant normalizer omits. Output-text comparison excludes `annotations` and `logprobs`.
- Function-call comparison excludes `status` and compares parsed arguments. Call identity remains significant.
- Changed assistant text, tool arguments, call IDs, or input order still reject continuation.

No retry, transport default, setting, API, or persistence behavior changed. The cached transport remains opt-in.

## Revisions and setup

| Investigation | Revision |
| --- | --- |
| Direct cached versus plain baseline | `2715f242b62be7500850a56751a1923db4ee1da4` |
| Application-level failing baseline | `b72f80cc81b9d06674fe06739a46364224e0c66f` |
| Fixed implementation and deterministic tests | `102d2175edcddf4bba570de1acf6813be7cde0eb` |

The fixed controller probe ran against the production code subsequently committed as `102d2175e`. Later report/comment edits do not change that behavior.

Both probes used the real OAuth endpoint at `wss://chatgpt.com/backend-api/codex/responses`. Neither was added to an ordinary E2E suite. The temporary Castor task and scripts were under ignored `var/tmp/codex-probe/`.

The application probe used `ControllerE2eTestCase` process discovery and JSONL facilities, `AgentTestExecutable`, and `TestDirectoryIsolation`. It launched source `bin/console agent --controller` with production provider construction and message normalization, one LLM worker, one tool worker, and only the `read` tool enabled.

Each probe project had an isolated HOME, CWD, compiled cache, sessions directory, application database, and Messenger transport database. All six transport DSNs used that project's transport database. Child environment was explicit and did not inherit `HATFIELD_SESSION_ID`. The current user owned every discovered child. Stored auth was read through `CodexAuthStorage`; the isolated credential record omitted the refresh token. Real auth, settings, sessions, and databases were not modified. Temporary projects and credentials were removed after teardown.

Controller shutdown used stdin EOF, not forced termination. The probe checked the tracked process tree before restart. Both controller lifetimes stopped all eight owned processes, including Messenger consumers, with zero survivors. No fallback signals were needed. Logs also recorded cache reset with `reason=session_close` and `codex.websocket.worker.shutdown`.

## Application-level before and after

The same short conversation exercised an initial answer, a real read-tool loop, process stop/resume, cancellation on a positive text delta, and a subsequent turn.

| Stage | Failing baseline | Fixed observation |
| --- | --- | --- |
| Initial answer | Full context, two input items | Same; one completed run |
| Next user turn | Reused socket, four input items, no previous response ID | Reused socket, one user item, previous response ID |
| Tool result | Reused socket, six input items, no previous response ID | Reused socket, only `function_call_output`, previous response ID; tool and run completed |
| Stop and resume | Fresh connection, full history | Fresh connection, eight input items, no previous response ID; run completed |
| Cancel during streaming | Cancellation completed | Cancel sent after `assistant.text_delta`; `cancellation.requested`, `turn.cancelled`, and `run.cancelled` observed; cached entry invalidated |
| Turn after cancellation | Probe used the wrong command | `follow_up` completed on a fresh connection with 11 full-history items and no previous response ID |

Resume was not simulated with a provider option. The first controller and its workers exited. A second controller opened the same isolated project and received the JSONL `resume` command, followed by a user message. This is the attach path used by `JsonlProcessAgentSessionClient` for CLI/TUI resume. `ResumeHandler` called `InProcessAgentSessionClient::attach`, emitted `run.resumed`, and reset the reasoning epoch. No TUI was launched.

Every final application stage reported zero error-level log records. Cancellation was synchronized on a streaming event, not a delay.

## Requirement-to-proof map

| Requirement | Evidence |
| --- | --- |
| Cached versus plain baseline | Direct two-turn comparison: plain opened two sockets; cached opened one and sent a one-item delta. Application baseline then exposed the normalization gap. |
| Multi-turn and tool-loop correctness | Fixed controller run emitted one real tool execution and completed the subsequent model turn. Both eligible requests sent one-item deltas. Direct probe additionally checked requested tool name/arguments, unique output-item IDs, consecutive output indexes, and one created/completed response per request. |
| Astra baseline and updates | Direct probe held low baseline, changed to medium, repeated unchanged medium, and returned to low. Cached input counts were `1,2,1,2`; five requests used one socket versus five for plain. Existing transition-hook tests cover normalizer/history placement. |
| Resume and model changes | Actual controller process restart and attach established fresh continuation. Direct Astra-to-catalog-model `gpt-5.6-luna` comparison opened a new socket and sent full history for both transports. |
| Cancellation and later requests | Actual cancellation after text streaming invalidated the cache; a subsequent `follow_up` completed on a new connection. |
| Disconnect and expiry | Direct probe closed its owned socket and separately advanced the cache's injected clock two hours. Later requests used fresh sockets and full history. Deterministic cache tests cover idle TTL and maximum age; interrupted-continuation tests cover peer EOF. |
| Rejected continuation | Deliberately absent response ID produced an error and closed the socket. A distinct next request completed without continuation. The committed interruption test checks rejection of an established delta and forbids implicit resend. |
| Socket and worker ownership | Direct probes' opened/closed socket counts matched. Both real controller process trees stopped on EOF with zero survivors. Worker diagnostics found no stale QA candidates. |
| Deterministic regression | Two contract-backed continuation-state tests failed with a null delta before the fix and passed afterward. Negative assertions preserve text, argument, call-ID, and list-order distinctions. |

## Commands and results

Temporary live commands, run from the task worktree:

```sh
CODEX_PROBE_SCENARIO=astra castor --castor-file=var/tmp/codex-probe/castor.php probe
CODEX_PROBE_SCENARIO=tool castor --castor-file=var/tmp/codex-probe/castor.php probe
CODEX_PROBE_SCENARIO=lifecycle castor --castor-file=var/tmp/codex-probe/castor.php probe
CODEX_PROBE_SCENARIO=model castor --castor-file=var/tmp/codex-probe/castor.php probe
CODEX_PROBE_SCENARIO=rejection castor --castor-file=var/tmp/codex-probe/castor.php probe
CODEX_PROBE_SCENARIO=shape castor --castor-file=var/tmp/codex-probe/castor.php probe
castor --castor-file=var/tmp/codex-probe/castor.php controller-probe
```

The final controller probe passed. Sanitized local outputs are `controller.log`, `controller-fixed.log`, and `controller-final.log`. The intermediate fixed run proved tool continuation but still rejected text history. A shape-only probe exposed the omitted `phase` field, which the final fix handles. These temporary artifacts are not required by the product and are not committed.

Live bounds were 90 seconds per direct scenario and 180 seconds for the controller scenario. Controller waits used positive events with 12-second readiness, 25-second model-turn, 15-second cancellation, and 10-second shutdown caps. These are throwaway probe safety bounds, not production or PHPUnit timeout increases. There were no blind retries or sleeps to create interaction windows.

Committed validation:

```sh
castor test --filter=testNormalizedAssistantHistoryContinuesNativeResponseWithoutReplayingIt
castor test --filter=testNormalizedToolHistoryContinuesNativeResponseWithoutReplayingCall
castor test --filter='Codex.*Test|RawWebSocketResultTest|ResultConverterWebSocketTest|SessionAwareModelResolverTest|AstraReasoningTransition.*Test'
castor phpstan
castor deptrac
castor test:llm-real --filter=LlamaCppSmokeTest
castor cs-check
castor clean:cleanup:workers:list
```

Both new regressions failed before the fix. The final focused suite passed with 252 tests and 953 assertions. Maximum individual case was 0.407323 seconds; new normalization cases were at most 0.000336 seconds. PHPStan, Deptrac, style, and the local llama.cpp smoke passed. Worker diagnostics found no stale QA candidates. The llama.cpp smoke is separate from the real Codex evidence.

## Probe corrections and limits

The original direct probe incorrectly required populated terminal `response.output`. The endpoint emits empty terminal output while `response.output_item.done` carries text and tool items. Correcting that probe assumption verified the existing fallback; it was not a new product fix.

An exploratory request used `gpt-5.3-codex`, which is absent from this checkout's catalog, and failed for both transports. The catalog-backed `gpt-5.6-luna` model change passed. The original post-cancel controller command was `user_message`, which maps to steering and did not start a new cancelled run. Using the supported `follow_up` command resolved that probe error without a runtime change.

This is bounded correctness evidence, not a long-duration reliability or cache-hit-rate claim. The live controller used one LLM worker and no TUI. Provider-enforced expiry and loss of a previously valid server continuation were not forced; clock-driven expiry and deliberate rejection cover the client behavior. No contention failure reproduced, so concurrent stress lanes were not run. Full `castor check` and independent review remain task-workflow gates, not results of this investigation.

## Interactive Sol observation

A subsequent tmux run at `df1f2fd36130e070c8c4096855bb5739499576c4` launched the actual interactive TUI with cached `openai-codex/gpt-5.6-sol`, low reasoning, and isolated HOME, CWD, databases, auth, and sessions:

```sh
castor --castor-file=var/tmp/codex-probe/castor.php tui-probe
```

Sol was available. The first answer sent three full-context items. The second answer reused the socket with a one-user-item continuation. A real read-tool loop then sent one user item and one `function_call_output`, both with `previous_response_id`. The terminal showed completed answers and the tool result. Canonical events recorded three completed runs and exactly one completed tool execution. Those stages had no error-level log records.

The cancellation observation barrier failed because it required the first two numbered output lines to remain visible together. The failure snapshot instead shows generated integers 117–156 and `Working...`, so output was progressing. Escape was never sent. The probe exited through the normal Ctrl+D action, without external termination signals. This run does **not** prove TUI cancellation, a post-cancel request, or TUI resume. Work stopped rather than repeating the long output journey.

The probe tracked only one pane PID, not the full worker tree. Pane exit and a clean `castor clean:cleanup:workers:list` result do not establish complete worker teardown for this TUI run. Earlier controller-level ownership proof remains separate. The isolated project and credentials were removed.

Ignored inspection artifacts:

- `var/tmp/codex-probe/tui-sol.log`: sanitized event counts and transport summaries.
- `var/reports/codex-tui-sol/`: plain snapshots `startup.txt`, `first-answer.txt`, `second-answer.txt`, `read-tool.txt`, and `final-or-failure.txt`.
- `var/reports/codex-tui-sol/.hatfield/tmp/tui/smoke/`: ANSI snapshots `startup-20260922-140858.ansi`, `first-answer-20260922-140901.ansi`, `second-answer-20260922-140903.ansi`, `read-tool-20260922-140908.ansi`, and `final-or-failure-20260922-140933.ansi`.

## Harbor evaluation preflight

The proposed one-task evaluation was stopped **before any model request or task container launch**. Agent-core was at `df1f2fd36130e070c8c4096855bb5739499576c4`; the read-only Harbor adapter checkout was at `7e95b67`. Harbor reported version 0.22.0, and Docker client/server both reported 29.8.1.

The fastest task in the existing ten-candidate report is `swe-bench/scikit-learn__scikit-learn-10297`. Its historical digest is `sha256:99b7fc2ffa0f8b2d2c7e9691991ea3d607899e37203a582b5342e306dfedc090`. The previous run took 211.35 seconds and returned reward 1.0. These are selection evidence from the old report, **not a new verifier result**. A future evaluation must resolve and verify the task digest again.

Three preflight findings prevent the requested run as currently configured:

1. **Current native artifact unavailable.** `castor phar:ensure` passed, and an ignored Castor artifact probe confirmed the PHAR embeds commit `df1f2fd36`. Its SHA-256 is `c862c7f093d2dcf11d727931f9fedbde6fd81a0f394c4334c79c7c71c7e1d20b`. Harbor's documented adapter uploads and directly executes a self-contained native binary; it does not provision PHP or upload a PHAR runtime. Cached native releases predate this fix. Local static prerequisites `re2c`, `flex`, and `gperf` are missing, and no local `micro.sfx` was found. No system packages were installed and no static build or CI job was launched.
2. **Existing CI cache does not match.** GitHub lists a Linux amd64 SPC cache of 1,128,711,491 bytes with key `spc-linux-amd64-25a08afd30c1db8ba6289fc0aa0c9cadf938570225f2363269c39d28a709fd97`. The current inputs produce key `spc-linux-amd64-71a27a80380bf51a66367811dc28b7348a49ec38c4decc613277340546e0cb18`. The build policy permits exact matches only. Available uploaded artifacts are fused releases, not a standalone current SFX. No stale binary was substituted or modified.
3. **Adapter teardown violates this investigation's ownership rules.** `controller_driver.py` explicitly sets `HATFIELD_SESSION_ID`, then unconditionally calls `terminate_process_group` in `finally`. That helper sends process-group SIGTERM and SIGKILL without UID or protected-tag checks. This is not the EOF-based shutdown used by the successful controller probe. The adapter was not changed or executed.

The adapter preserves canonical session events but does not copy the project's `.hatfield/logs`. A future approved run also needs structured transport summaries retained before container deletion. The existing `logging.path` setting can direct logs into Harbor's retained agent directory; a sanitized export can then retain reuse, delta, full-context, and error counts without raw payloads.

The smallest next path requires approval for a current native build on a prepared runner and a narrow adapter shutdown correction. Alternatively, supporting the current PHAR requires an approved adapter/runtime-provisioning change. Neither is an existing drop-in option. No new verifier result, tool-cycle count, transport count, token cost, or container-teardown result exists for this blocked evaluation. Harbor tracked files and containers were untouched.

## Approved native build and one Harbor trial

After explicit approval, the native-build and adapter blockers above were resolved. The single paid trial completed the agent run, but its verifier failed before running tests. No second trial was attempted.

### Build and adapter provenance

- Native executable: task worktree `var/tmp/dist/hatfield.linux-amd64`, embedded commit `777ed88a750a0eab7a376f1b840e6c721b2146bb`.
- Native SHA-256: `7077e3d1254f94d2e7a0d959d7992050089e6a161202db152fbf9a30e813c679`.
- PHAR SHA-256: `b6474612100e8d5ad9d3f0d303431156c8e9507645693c70fa0bbe6960ba7ec5`.
- Harbor commits: `266b4cf25dbab492ab8323e2bd056fa8380280a6` replaces process-group signals with bounded stdin-EOF shutdown and removes inherited session tags from new children; `5512c64d0c24415b677d3d6a69e17d58ee46efcd` makes the retained log directory writable by the configured non-root agent.

Build prerequisites were downloaded and extracted under ignored `var/tmp/static-tools`, not installed system-wide. The pinned SPC checkout was copied from the integration checkout without changing that checkout. An initial preparation script put downloaded packages in the wrong working directory; those owned files were moved under the ignored directory and extracted before the actual build. No stale binary or mismatched CI cache was reused.

`castor distribution:build-static` built pinned PHP 8.5.8 and phpmicro, verified the PHP source hash, combined the current PHAR through SPC, and passed native smoke/topology checks. `castor distribution:verify` passed resource, native topology, and checksum checks. Logs are in `var/tmp/static-tools/build-prepared.log` and `verify.log`. Harbor's install smoke independently reported the same embedded commit before model execution.

The adapter now refuses root execution. EOF shutdown waits for live members of the owned controller session, drains stdout, records survivor counts, and raises on failure without sending signals. Existing normal/error artifact preservation remains in place. Harbor's deterministic suite passed: `.venv/bin/python -m pytest --durations=5`, 19 tests in 0.37 seconds, maximum case 0.07 seconds. New tests cover a real controller/worker EOF handshake and a loud shutdown failure with signals forbidden. The main-path test verifies an enclosing `HATFIELD_SESSION_ID` does not reach the child.

### Trial and results

Job: `hatfield-harbor/jobs/codex-cached-20260922`, trial `scikit-learn__scikit-learn-10297__2RYxEoC`.

The downloaded registry task resolved to the expected original digest `sha256:99b7fc2ffa0f8b2d2c7e9691991ea3d607899e37203a582b5342e306dfedc090`. Its ignored local copy changed only `agent.user` and Dockerfile ownership setup to run as `hatfield-eval`. Instruction, solution, and verifier files were unchanged. Harbor recorded local task digest `sha256:edef2bf6d698c0719dbb34f483be002f2f10be9981d4795a32ad17c92458c0cc`.

The run used `harbor run -p jobs/cached-preflight/tasks/scikit-learn__scikit-learn-10297 -a hatfield_harbor.agent:HatfieldHarborAgent -m openai-codex/gpt-5.6-luna -n 1 -k 1 --max-retries 0 --agent-timeout-multiplier 0.1 --verifier-timeout-multiplier 0.2 --job-name codex-cached-20260922 -o jobs`, with explicit executable, settings, access-only auth, commit, and `reasoning=medium` agent kwargs. Agent time was bounded to 300 seconds; verifier time to 600 seconds. Isolated settings selected cached WebSocket, disabled model retries, used one LLM worker, and retained structured logs through `logging.path=/logs/agent/hatfield-logs`.

| Observation | Result |
| --- | --- |
| Agent terminal | `run.completed`; 14 LLM steps |
| Tools | 15 calls: 4 bash, 7 edit, 4 read; 11 completed, 4 failed |
| Cached transport | 1 created connection, 13 reuses, 13 continuation deltas, 1 initial full-context request |
| Transport failures | No divergent-input fallback, I/O error, timeout, or error-level log entry |
| Shutdown | `session_close` cache reset and worker shutdown logged; 8 observed processes, 0 survivors |
| Duration | Agent 88.86 seconds; total 118.29 seconds |
| Usage | 160,835 input tokens, 121,856 cache-read tokens, 3,249 output tokens, 875 thinking tokens |
| Cost | $0.0759086 from canonical usage accounting, not an invoice |
| Verifier | No reward. `RewardFileNotFoundError`; issue and regression tests did not run |

The verifier's first Git checkout failed with `fatal: detected dubious ownership in repository at '/testbed'`. The required non-root overlay made the agent own `/testbed`, while the unchanged verifier ran as root. This is an evaluation-environment failure, not a model failure or cached-transport failure. A future approved attempt needs a scoped Git trust/ownership correction for the verifier. The trial was not rerun to obtain a score.

Evidence is retained under the trial's `agent/` directory: `hatfield.runtime.provenance.json`, `install.smoke.txt`, `controller.summary.json`, `controller.teardown.json`, canonical sessions, and `hatfield-logs/agent-2026-09-22.log`. Verifier evidence is `verifier/test-stdout.txt`. The privacy-safe aggregate is `jobs/cached-preflight/report.json`; its generic `adapter_exception` flag includes this verifier exception and must not be interpreted as a controller failure.

Harbor deleted the owned task container; a name-scoped container check found none remaining. The access-only host credential copy was removed. A token-content scan found zero credential matches in retained agent artifacts. `castor clean:cleanup:workers:list` found no stale QA candidates. No real auth was refreshed or changed, and no external process signals were used by the revised adapter.
