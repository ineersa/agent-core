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
