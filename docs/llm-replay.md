# Provider HTTP cassettes

Regular QA is offline with respect to model providers: `castor test`, `castor test:controller-replay`, `castor test:tui`, and `castor check` do not require a model server or proxy. In the test container, the LLM transport uses Symfony AI `CassetteHttpClient` and `HttpCassette` with `record: false` and no live client. Tests that do not explicitly select a cassette reject all model requests.

Run the public extension agent scenario with `castor test --filter=ConfiguredModelAgentRunnerCassetteTest`. It uses the real provider factory, OpenAI-compatible SSE conversion, and agent/tool loop. The test selects its cassette by scenario name. Symfony verifies each full request against the next interaction; missing, mismatched, and exhausted cassettes fail without network fallback. Keep inputs deterministic instead of stripping prompts or normalizing tool calls. The recorder replaces only the configured endpoint with a placeholder URL, in addition to Symfony's built-in redaction.

## Explicit capture

1. Configure an enabled generic `llama_cpp` chat provider with the wire model ID `flash` and `completions_path: /chat/completions`, and start the server. A different path (including the implicit `/v1/chat/completions`) is rejected rather than silently routing elsewhere.
2. Run `castor llm:cassette:record`. The task reads the user AI catalog (bundled fallback) and the usual defaults, user, and project settings without bootstrapping a user catalog. It imports only the base URL and API key. Recording and replay share the fixed request definition, including the `flash` model and `reasoning_effort: none`; operator reasoning and compatibility settings do not alter the request. The server must accept that request. The scenario runs in isolated QA HOME and database paths. It prints a new private capture directory under `var/`; it never updates the checked-in fixture.
3. Inspect the private JSON for credentials, personal data, and machine-local details. Review the HTTP interactions and tool behavior before manually copying an approved cassette to `tests/CodingAgent/Fixtures/cassettes/`. Run the focused offline test after promotion.

A live recording attempt can fail if the model does not follow the tool instructions. Such a capture is evidence for diagnosis, not an approved fixture. Replays cover conversion and application behavior for recorded bytes, not model quality or live connectivity.
