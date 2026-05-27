# Agent Core `view_image` redo implementation notes

Task: `tasks/IN-PROGRESS/tools-04-view-image-tool.md`
PR branch/worktree: `task/tools-04-view-image-tool` / `/home/ineersa/projects/agent-core-worktrees/tools-04-view-image-tool`

## Hard requirements

1. Delete/replace the current JSON/base64 tool-result architecture.
2. Do **not** return `base64`, `data_url`, or full image bytes as JSON text.
3. Do **not** use `OutputCap` as the primary image delivery mechanism. It may remain useful for text tools, but capped image metadata is not a successful `view_image` result.
4. Persist only path/reference + metadata in AgentCore session artifacts.
5. The next provider request must include a first-class image attachment/content block.
6. Product-level validation must exercise the real runtime flow; unit tests alone are insufficient.

## Recommended minimal architecture: Pi-style fallback

Because Symfony AI 0.9 `ToolCallMessage` only accepts string content, implement this path first:

```text
assistant emits tool_call view_image(path)
  -> ExecuteToolCallWorker runs ViewImageTool
  -> ToolExecutor/ToolCallResultHandler commits normal text tool result:
       "Loaded image /path/to/file (image/png, 1234x567, 89KB). See attached image."
  -> Tool result details/content also contains compact image reference metadata:
       { type: "image_ref", path, media_type, bytes, width, height, detail, ... }
  -> Before the follow-up LLM step, AgentMessageConverter (or adjacent converter/service)
     emits the normal ToolCallMessage text PLUS a synthetic UserMessage:
       Message::ofUser(new Text("Tool result image for view_image:"), Image::fromFile($path))
  -> Provider receives real image content through Symfony AI image normalizers.
```

This mirrors Pi/Gemini fallback behavior: when direct multimodal function/tool response is not supported, send a separate user turn containing the image.

## Suggested code seams

Use project search/IDE tools to confirm exact class names before editing. Likely seams:

- `src/CodingAgent/Tool/ViewImageTool.php`
  - Still implements `HatfieldToolProviderInterface` + `ToolHandlerInterface`.
  - Validate args/path/mime/dimensions.
  - Return compact structured result with text + `image_ref` metadata only.
- `src/AgentCore/Infrastructure/SymfonyAi/AgentMessageConverter.php`
  - Currently `toToolCallMessage()` stringifies tool details and `contentToText()` drops non-text content.
  - Add conversion support for `image_ref` content/details by appending a synthetic `UserMessage` after the tool call message.
  - This may require `convertAgentMessage()`/`toMessageBag()` to return one-or-many Symfony messages instead of exactly one.
- Domain/session message content shape
  - Prefer adding an explicit content part shape like:
    ```php
    ['type' => 'image_ref', 'path' => $resolvedPath, 'media_type' => $mime, 'bytes' => $bytes, 'width' => $w, 'height' => $h]
    ```
  - Keep serialized content small.
- Tests
  - Add converter tests proving `MessageBag` contains:
    1. `ToolCallMessage` with small text content.
    2. `UserMessage` with `Text` + `Symfony\AI\Platform\Message\Content\Image`.
  - Add session/artifact test proving no full base64/data_url is persisted.
  - Add runtime/controller/TUI product validation for a real image prompt.

## Symfony AI facts verified in vendor

- `Symfony\AI\Platform\Message\Content\Image extends File`.
- `Image::fromFile($path)` stores a lazy closure and path; `asDataUrl()` reads/encodes at provider normalization time.
- `ImageNormalizer` normalizes to OpenAI-style:
  ```php
  ['type' => 'image_url', 'image_url' => ['url' => $image->asDataUrl()]]
  ```
- `ImageUrlNormalizer` normalizes URLs similarly.
- `ToolCallMessage` constructor is string-only:
  ```php
  public function __construct(private readonly ToolCall $toolCall, private readonly string $content)
  ```

## Keep from the current PR if useful

- `PathResolver` usage.
- magic-byte MIME detection via `FinfoMimeTypeDetector` or project equivalent.
- typed image settings DTO shape if it is clean.
- validation tests for unsupported files, unreadable paths, dimensions, size limits.
- `ToolRuntime::run()` cancellation checkpoint usage.

## Remove/rework from the current PR

- Returning arrays containing `base64` and `data_url` from `ViewImageTool`.
- `OutputCap` injection into `ViewImageTool` for image delivery.
- Tests that assert JSON stringification/base64 survives the text-only tool pipeline.
- Any claim that output-cap path is sufficient for model vision.

## Product validation target

Use Castor only. At minimum, run focused tests plus one real runtime workflow:

```bash
castor test --filter=ViewImageTool
castor test --filter=AgentMessageConverter
castor phpstan
castor deptrac
castor cs-check
castor test:controller   # or castor run:agent-test / castor test:tui if controller is insufficient
```

For the product workflow, prompt with a small local PNG/JPEG and verify:

- run completes (no stuck `Running…` after `tool_batch_committed`),
- session artifacts do not contain multi-MB base64/data_url blobs,
- provider message conversion includes `Image`/image attachment content,
- assistant can describe the image, or a clear non-vision-model error is shown.
