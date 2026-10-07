# Live LLM validation

- Use only `llama_cpp_test/test` on port 9052, never production providers in E2E.
- Keep the model deterministic with temperature zero and a fixed seed.
- Run focused `castor test:llm-real --filter=X` for provider, schema, model-routing, prompt, streaming, or tool-conversion changes, not every task.
- `castor test:controller` is opt-in. The full gate already runs the live group.
- Name the exact tool and relative path in prompts. Assert tool/event/schema/stream/artifact contracts, not model prose.
- Give each scenario a unique first user prompt, such as `[llm-real:write-file]`, to avoid proxy cache collisions.
- For tool proof, match `tool_name` and `tool_call_id`, and fail on the relevant execution error.
- Use early-exit event collection. Slow tool calls require diagnosis, not a larger wait.

## Readiness and cache

Castor checks actual generation before live lanes, not health alone. A fresh `var/tmp/llm-generation-ready.cache` skips repeated probes for 120 seconds. Use `HATFIELD_LLM_READY_TTL=0` to force a recheck when diagnosing readiness.

The separate `/home/ineersa/projects/llama-proxy` service fronts the model on port 9052. It records cache misses and replays hits, including streaming chunks. It strips prologue messages from cache keys only; do not add app-side prompt stripping.

The full gate fails on proxy cache growth. Warm it intentionally with `castor test:llm-real` before the transition gate. Cache clearing requires another warmup.

For proxy diagnostics, use `/__llama_proxy/health` and `/__llama_proxy/cache/stats`. Cache mutation is explicit maintenance, not a routine retry step. Supply the configured admin token without printing it.

Committed fixtures under `tests/AgentCore/Fixtures/traces/` and controller fixture directories are separate from proxy HTTP cassettes. Maintain fixtures directly; there is no supported live-recording Castor task.

The normal provider timeout is 30 seconds and test DI uses 5 seconds. Do not raise HTTP timeouts to mask a stuck process or provider path.
