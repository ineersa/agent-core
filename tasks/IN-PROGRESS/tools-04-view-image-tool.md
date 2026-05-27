# TOOLS-04 Implement view_image tool

## Goal
Implement the separate image viewing tool as a **first-class multimodal attachment path**, not as JSON/base64 text.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Current PR status:
- The current `view_image` implementation that returns `base64`/`data_url` inside JSON text is **not acceptable**.
- The OutputCap fallback commit prevents a runtime hang but still fails the product goal: the model receives only metadata/path, not image pixels.
- Rework PR #62 before merge; do not merge the current architecture.

Dependencies:
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox, settings, allowlist, `ToolRuntime`).
- Depends on TOOLS-01 (`PathResolver`).
- Depends on the Symfony AI multimodal content primitives available in vendor (`Symfony\AI\Platform\Message\Content\Image`, `ImageUrl`).

Scope:
- Keep `view_image` as a separate permanent Hatfield tool with explicit JSON schema and registry metadata.
- Schema should accept `path: string` and optionally `detail?: auto|low|high|original` if provider/model capability can be enforced.
- Resolve path with `PathResolver`; use `ToolRuntime::run()` for cancellation checkpoints.
- Detect supported image MIME types by magic bytes, not file extension: `image/jpeg`, `image/png`, `image/gif`, `image/webp`.
- Validate file readability, max bytes, and dimensions through typed image settings.
- Add image processing before provider request:
  - resize-to-fit around 2000/2048px max dimension by default,
  - enforce provider-safe encoded payload limit (Pi uses 4.5MB base64 below Anthropic 5MB),
  - preserve/normalize supported formats deliberately,
  - apply JPEG/WebP EXIF orientation if feasible,
  - use an in-memory/LRU cache keyed by file digest + mode where helpful.
- Persist only image references/metadata in AgentCore state/events/transcript (`path`, `media_type`, `bytes`, `width`, `height`, `detail`, processed dimensions), never full base64/data_url blobs.
- Extend the AgentCore↔Symfony AI conversion path so the next provider request receives a real image attachment/content block:
  - Prefer Symfony AI `Image::fromFile($path)` / `ImageUrl` where provider conversion supports it.
  - Because Symfony AI 0.9 `ToolCallMessage` content is string-only, implement an explicit strategy for tool-result images: provider-specific function-call-output image content where available, or a Pi-style synthetic follow-up user message containing the image attachment after the tool text result.
- Reject/non-vision fallback must be explicit: if the active model does not accept images, reject `view_image` or emit a clear placeholder. Do not silently serialize image bytes as text.
- Add tests that prove large images do not bloat `state.json` and that the rebuilt provider `MessageBag` contains an actual image content object/request item.

Out of scope:
- Do not put image handling into the read tool for this task.
- No PDF/notebook support.
- No JSON/base64-as-text or OutputCap-as-substitute-for-vision behavior.

## Acceptance criteria
- `view_image` is discoverable through registry-backed Symfony Toolbox metadata and present in `ToolRegistryInterface` permanent metadata.
- Supported image MIME types are accepted based on magic bytes; unsupported files return clear errors.
- Runtime session artifacts store path/reference + metadata only; a realistic 1–2MB image must not create multi-MB `state.json`/transcript entries.
- The next LLM/provider request contains a real image attachment/content block (`Image`, `ImageUrl`, `input_image`, provider function-call-output image, or equivalent), not JSON text with base64.
- Non-vision model behavior is explicit and tested.
- Image resize/quality/detail policy is covered by focused tests.
- Product-level validation exercises the real flow (`castor run:agent-test`, `castor test:tui`, `castor test:llm-real`, or `castor test:controller`) and demonstrates the model receives an image without hanging.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-04-view-image-tool
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-04-view-image-tool
Fork run: 7f6qg8ydcsfp
PR URL: https://github.com/ineersa/agent-core/pull/62
PR Status: open
Started: 2026-05-27T18:57:52.472Z
Completed:

## Work log
- Created: 2026-05-17T04:42:04.933Z

## Task workflow update - 2026-05-27T18:57:52.472Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-04-view-image-tool.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-04-view-image-tool.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-04-view-image-tool.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-04-view-image-tool.
- Summary: Starting TOOLS-04. Note: task text is stale where it mentions CancellationGuard/ToolExecutionContextInterface and TOOLS-R04 image settings; current architecture uses ToolRuntime::run()/StackToolExecutionContextAccessor, and concrete image settings should be added as typed DTOs in this task if needed.

## Task workflow update - 2026-05-27T18:58:25.059Z
- Recorded fork run: 0oh2p3navn62
- Summary: Implementation fork launched for TOOLS-04 view_image. Instructions include updating stale CancellationGuard references to ToolRuntime, adding typed image settings if needed, preserving base64/media_type through ToolExecutor and AgentMessageConverter despite Symfony AI ToolCallMessage string-only content, and focused tests/validation.

## Task workflow update - 2026-05-27T19:05:36.017Z
- Recorded fork run: This session (forked implementation)
- Validation: castor test --filter=ViewImageTool: 30 tests, 91 assertions, OK; castor test: 1132 tests, 10394 assertions, OK (7 pre-existing deprecations); castor phpstan: file_errors=0, errors=0; castor deptrac: violations=0, errors=0, uncovered=379, allowed=762; castor cs-check: ok (files_fixed=0)
- Summary: TOOLS-04 view_image tool implemented and validated. 
- ViewImageTool with HatfieldToolProviderInterface + ToolHandlerInterface
- ImageToolConfig typed DTO wired into ToolsConfig
- ToolRuntime::run() for cancellation, FinfoMimeTypeDetector for magic-byte MIME
- Structured result with base64, media_type, data_url, dimensions
- ToolExecutor::normalizeResultText uses JSON_UNESCAPED_SLASHES
- 30 focused tests pass (91 assertions), 1132 total pass
- castor deptrac 0 violations, phpstan 0 errors, cs-check clean
- Commit f0d08658 on task/tools-04-view-image-tool

## Task workflow update - 2026-05-27T19:06:19.766Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-04-view-image-tool to origin.
- branch 'task/tools-04-view-image-tool' set up to track 'origin/task/tools-04-view-image-tool'.
- Created PR: https://github.com/ineersa/agent-core/pull/62
- Validation: castor test --filter=ViewImageTool — pass (30 tests, 91 assertions); castor test — pass (1132 tests, 10394 assertions; 7 pre-existing deprecations); castor phpstan — pass (errors=0, file_errors=0); castor deptrac — pass (violations=0); castor cs-check — pass
- Summary: TOOLS-04 implementation complete at f0d08658. Added ViewImageTool with typed ImageToolConfig, image MIME/dimension/size validation, structured base64/data_url result, settings/docs updates, and conversion survival coverage through ToolExecutor/AgentMessageConverter.

## Task workflow update - 2026-05-27T19:25:07.715Z
- Summary: User runtime-tested PR #62 via TUI with prompt 'Could you describe image /home/ineersa/Downloads/Gemini_Generated_Image_nfpz31nfpz31nfpz.png'. Snapshot shows model emitted view_image tool call and UI stuck at 'Running…' for ~3m20s; llama.cpp received requests but no tool result appeared in UI. Need reproduce/fix runtime tool-result flow on PR branch.

## Task workflow update - 2026-05-27T19:25:25.754Z
- Recorded fork run: fcl21xrx9kqm
- Summary: Launched runtime-debug fork fcl21xrx9kqm to reproduce/fix view_image stuck Running issue using prompt with /home/ineersa/Downloads/Gemini_Generated_Image_nfpz31nfpz31nfpz.png. Fork instructed to inspect runtime artifacts/logs, test via castor run:agent-test or controller, investigate queue/worker/container/message-size/tool-result flow, fix, validate, commit, and push.

## Task workflow update - 2026-05-27T19:31:31.668Z
- Recorded fork run: fcl21xrx9kqm
- Validation: fork reported: castor test --filter=ViewImageTool passes (31 tests, 107 assertions); fork reported: castor test --filter=OutputCap passes; fork reported: castor test passes (1133 tests, 10410 assertions); fork reported: castor phpstan passes (errors=0, file_errors=0); fork reported: castor deptrac passes (0 violations); fork reported: castor cs-check passes; parent verified PR #62 head 595b5db1 mergeStateStatus CLEAN
- Summary: Follow-up fork fixed runtime hang risk by capping oversized ViewImageTool results through OutputCap. ViewImageTool now persists full JSON/base64 payload when serialized result exceeds OutputCap defaultCap and returns compact metadata with output_cap_path; OutputCap exposes config(); ViewImageTool tests cover capped output. Commit 595b5db1 pushed to task/tools-04-view-image-tool / PR #62.

## Task workflow update - 2026-05-27T19:31:51.159Z
- Validation: parent rerun: castor test --filter=ViewImageTool passes (31 tests, 107 assertions); parent rerun: castor phpstan passes (errors=0, file_errors=0); parent rerun: castor deptrac passes (0 violations); parent rerun: castor cs-check passes (files_fixed=0)

## Task workflow update - 2026-05-27T19:43:18.777Z
- Summary: Architecture reset after user feedback and scout research: current JSON/base64-as-text implementation and OutputCap fallback are no-go. Proper view_image must persist only image references/metadata and attach actual image content to provider requests using Symfony AI multimodal primitives or provider-aware/synthetic-user-message strategy for tool-result images. Plan and task scope updated with Pi/Codex findings: path-only persistence, resize/quality limits, model capability gating, non-vision fallback, and product-level validation requirements.

## Task workflow update - 2026-05-27T19:43:25.421Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Moved back from code review because current PR architecture is not mergeable: image bytes must not be returned as JSON/base64 text or merely capped by OutputCap. TOOLS-04 now requires first-class multimodal image attachment handling with path-only persistence and provider-request rehydration.

## Task workflow update - 2026-05-27T19:50:03.472Z
- Summary: Added implementation reference files to PR branch at .pi/reference/view-image/: pi-image-flow-reference.md captures Pi content block, resize, and provider serialization patterns; agent-core-redo-implementation-notes.md defines the Agent Core Pi-style fallback architecture (text tool result + synthetic user image message), hard requirements, likely code seams, and validation target.

## Task workflow update - 2026-05-27T19:50:26.515Z
- Recorded fork run: 7f6qg8ydcsfp
- Summary: Launched redo fork 7f6qg8ydcsfp to replace rejected JSON/base64 view_image architecture with Pi-style fallback: compact text tool result plus synthetic follow-up user message carrying Symfony AI Image content, with path-only persistence and no OutputCap image delivery.
