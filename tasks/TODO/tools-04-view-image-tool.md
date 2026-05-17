# TOOLS-04 Implement view_image tool

## Goal
Implement the separate image viewing tool.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-01 (`PathResolver`).

Scope:
- Create/complete `src/CodingAgent/Tool/ViewImageTool.php`.
- Register with `#[AsTool('view_image', description: 'View an image file')]`.
- Schema should be derived from `__invoke(string $path)`.
- Resolve path with `PathResolver`.
- Use `League\MimeTypeDetection\FinfoMimeTypeDetector` (already available via vendor) for magic-byte MIME detection.
- Support only `image/jpeg`, `image/png`, `image/gif`, `image/webp`.
- Read binary content and return a Symfony AI-compatible image content block with base64 data and media type.
- Reject unsupported/non-image files with a clear text error result.
- Add focused tests using tiny fixture images or generated minimal image bytes.

Out of scope:
- Do not put image handling into the read tool.
- No PDF/notebook support.
- No custom ImageDetector utility.

## Acceptance criteria
- `view_image` tool is discoverable through Symfony AI toolbox metadata.
- Supported image MIME types are accepted based on magic bytes, not extension alone.
- Unsupported files return a clear error and do not produce an image block.
- Returned content includes base64 image data and media type in the format expected by Symfony AI/project ToolResult conventions.
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
