# TOOLS-04 Implement view_image tool

## Goal
Implement the separate image viewing tool.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R02 (Hatfield tool definition convention) and TOOLS-R03 (registry-backed Toolbox, settings, and allowlist wiring).
- Depends on TOOLS-00 (`ToolExecutionContextInterface`, `CancellationGuard`).
- Depends on TOOLS-01 (`PathResolver`).

Scope:
- Create/complete `src/CodingAgent/Tool/ViewImageTool.php`.
- Provide a Hatfield tool definition/provider for `view_image` instead of relying on `#[AsTool]` metadata.
- Register `view_image` as a permanent tool through the TOOLS-R02 built-in tool registrar/`ToolRegistryInterface`, including provider description, explicit JSON schema, prompt line, and concise guidelines.
- Tool definition JSON schema should match `__invoke(string $path)`.
- Resolve path with `PathResolver`.
- Check cancellation via `CancellationGuard` before reading image bytes.
- Use `League\MimeTypeDetection\FinfoMimeTypeDetector` (already available via vendor) for magic-byte MIME detection.
- Support only `image/jpeg`, `image/png`, `image/gif`, `image/webp`.
- Read image byte/dimension limits from Hatfield tool settings introduced by TOOLS-R04.
- Read binary content and return image data in the format supported by the current AgentCore tool-result pipeline. If first-class multimodal tool-result content is required, update `ToolExecutor`/`AgentMessageConverter` in this task instead of silently degrading images to ordinary text.
- Reject unsupported/non-image files with a clear text error result.
- Add focused tests using tiny fixture images or generated minimal image bytes.

Out of scope:
- Do not put image handling into the read tool.
- No PDF/notebook support.
- No custom ImageDetector utility.

## Acceptance criteria
- `view_image` tool is discoverable through registry-backed Symfony Toolbox metadata and present in `ToolRegistryInterface` permanent metadata.
- Supported image MIME types are accepted based on magic bytes, not extension alone.
- Unsupported files return a clear error and do not produce an image block.
- Cancellation before reading image bytes uses the standard cancellation path.
- Returned content includes base64 image data and media type in the format expected by Symfony AI/project ToolResult conventions, and tests prove it survives conversion into the next LLM request as intended.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-17T04:42:04.933Z
