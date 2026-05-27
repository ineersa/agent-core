# Pi image flow reference for `view_image`

Source repository: `/home/ineersa/claw/pi-mono`

This file captures the Pi patterns that should be copied/adapted for Agent Core. The important part is **not** the TypeScript syntax; it is the message shape and provider behavior: image bytes are a first-class content block, not JSON text.

## 1. Shared content block type

Source: `packages/ai/src/types.ts`

```ts
export interface ImageContent {
    type: "image";
    data: string; // base64 encoded image data
    mimeType: string; // e.g., "image/jpeg", "image/png"
}

export interface ToolResultMessage<TDetails = any> {
    role: "toolResult";
    toolCallId: string;
    toolName: string;
    content: (TextContent | ImageContent)[]; // Supports text and images
    details?: TDetails;
    isError: boolean;
    timestamp: number;
}
```

Agent Core analogue: tool results must be able to carry a structured image reference/content part separate from textual content. Do not put `base64` inside JSON text.

## 2. Read/view image execution returns `[text, image]`

Source: `packages/coding-agent/src/core/tools/read.ts`

```ts
const mimeType = ops.detectImageMimeType ? await ops.detectImageMimeType(absolutePath) : undefined;
let content: (TextContent | ImageContent)[];
const nonVisionImageNote = getNonVisionImageNote(ctx?.model);

if (mimeType) {
    const buffer = await ops.readFile(absolutePath);
    if (autoResizeImages) {
        const resized = await resizeImage(buffer, mimeType);
        if (!resized) {
            let textNote = `Read image file [${mimeType}]\n[Image omitted: could not be resized below the inline image size limit.]`;
            if (nonVisionImageNote) textNote += `\n${nonVisionImageNote}`;
            content = [{ type: "text", text: textNote }];
        } else {
            const dimensionNote = formatDimensionNote(resized);
            let textNote = `Read image file [${resized.mimeType}]`;
            if (dimensionNote) textNote += `\n${dimensionNote}`;
            if (nonVisionImageNote) textNote += `\n${nonVisionImageNote}`;
            content = [
                { type: "text", text: textNote },
                { type: "image", data: resized.data, mimeType: resized.mimeType },
            ];
        }
    } else {
        let textNote = `Read image file [${mimeType}]`;
        if (nonVisionImageNote) textNote += `\n${nonVisionImageNote}`;
        content = [
            { type: "text", text: textNote },
            { type: "image", data: buffer.toString("base64"), mimeType },
        ];
    }
}
```

Agent Core adaptation:

- `ViewImageTool` should return/stage a small text result plus an image reference/content part.
- If using the Pi-style fallback, keep the actual tool-call message as text and inject a **synthetic follow-up user message** containing the image attachment.
- Non-vision behavior must be explicit, not silent base64-as-text fallback.

## 3. Non-vision model guard

Source: `packages/coding-agent/src/core/tools/read.ts`

```ts
function getNonVisionImageNote(model: Model<Api> | undefined): string | undefined {
    if (!model || model.input.includes("image")) {
        return undefined;
    }
    return "[Current model does not support images. The image will be omitted from this request.]";
}
```

Agent Core adaptation:

- If current model capabilities are available, gate `view_image` on image support.
- If not yet available, preserve a clear placeholder and document the limitation; do not send image bytes as text.

## 4. Resize policy

Source: `packages/coding-agent/src/utils/image-resize-core.ts`

Important defaults:

```ts
const DEFAULT_MAX_BYTES = 4.5 * 1024 * 1024; // base64 payload, below Anthropic 5MB

const DEFAULT_OPTIONS: Required<ImageResizeOptions> = {
    maxWidth: 2000,
    maxHeight: 2000,
    maxBytes: DEFAULT_MAX_BYTES,
    jpegQuality: 80,
};
```

Strategy:

1. Apply EXIF orientation.
2. If already below width/height and encoded base64 limit, keep original.
3. Resize to max width/height.
4. Try PNG and JPEG encodings.
5. Try decreasing JPEG quality: configured, 85, 70, 55, 40.
6. If still too large, reduce dimensions by 0.75 repeatedly.
7. Return `null` if image cannot be brought under the limit.

Agent Core adaptation:

- Use PHP image tooling available in the project/environment. Prefer a small, testable service (e.g. `ImageAttachmentProcessor`) over embedding resize logic in `ViewImageTool`.
- The exact resize library can differ; preserve the policy and tests.

## 5. Provider serialization patterns

### OpenAI Responses

Source: `packages/ai/src/providers/openai-responses-shared.ts`

User images:

```ts
return {
    type: "input_image",
    detail: "auto",
    image_url: `data:${item.mimeType};base64,${item.data}`,
};
```

Tool-result images when supported:

```ts
if (hasImages && model.input.includes("image")) {
    const contentParts: ResponseFunctionCallOutputItemList = [];

    if (hasText) {
        contentParts.push({ type: "input_text", text: sanitizeSurrogates(textResult) });
    }

    for (const block of msg.content) {
        if (block.type === "image") {
            contentParts.push({
                type: "input_image",
                detail: "auto",
                image_url: `data:${block.mimeType};base64,${block.data}`,
            });
        }
    }

    output = contentParts;
} else {
    output = sanitizeSurrogates(hasText ? textResult : "(see attached image)");
}
```

### Anthropic

Source: `packages/ai/src/providers/anthropic.ts`

```ts
return {
    type: "image" as const,
    source: {
        type: "base64" as const,
        media_type: block.mimeType as "image/jpeg" | "image/png" | "image/gif" | "image/webp",
        data: block.data,
    },
};
```

### Gemini

Source: `packages/ai/src/providers/google-shared.ts`

Gemini 3+ can include images in multimodal function responses. Older/non-compatible models get a separate user turn:

```ts
const imageParts: Part[] = imageContent.map((imageBlock) => ({
    inlineData: {
        mimeType: imageBlock.mimeType,
        data: imageBlock.data,
    },
}));

const functionResponsePart: Part = {
    functionResponse: {
        name: msg.toolName,
        response: msg.isError ? { error: responseValue } : { output: responseValue },
        ...(hasImages && modelSupportsMultimodalFunctionResponse && { parts: imageParts }),
        ...(includeId ? { id: msg.toolCallId } : {}),
    },
};

if (hasImages && !modelSupportsMultimodalFunctionResponse) {
    contents.push({
        role: "user",
        parts: [{ text: "Tool result image:" }, ...imageParts],
    });
}
```

Agent Core adaptation:

- Symfony AI 0.9 `ToolCallMessage` is string-only, so do not try to force images through `Message::ofToolCall()`.
- Implement the Pi-style fallback first: normal tool text result + synthetic follow-up user message containing `Symfony\AI\Platform\Message\Content\Image`.
- Later provider-specific function-call-output image support can be added where Symfony exposes it.
