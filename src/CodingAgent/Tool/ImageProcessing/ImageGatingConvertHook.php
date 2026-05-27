<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Model\ImageCapabilityCheckerInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Converts AgentCore messages into a Symfony AI MessageBag while gating
 * image attachments based on the resolved target model's capabilities.
 *
 * When the target model supports images, image_ref content parts from tool
 * results are converted into real Symfony AI Image attachments via the
 * standard AgentMessageConverter.
 *
 * When the target model does NOT support images, image_ref content parts
 * are stripped from the messages before conversion, and replaced with
 * text-only metadata placeholders so the model receives a readable
 * description instead of unusable image data.
 *
 * This hook runs as a ConvertToLlmHook, which is called before the
 * platform adapter's fallback converter. The hook pre-processes messages
 * so the downstream converter never needs to know about model capabilities.
 */
final readonly class ImageGatingConvertHook implements ConvertToLlmHookInterface
{
    public function __construct(
        private ImageCapabilityCheckerInterface $imageCapabilityChecker,
        private AgentMessageConverter $messageConverter,
    ) {
    }

    public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null, string $modelName = ''): MessageBag
    {
        $imagesSupported = $this->isImagesSupported($modelName);

        if (!$imagesSupported) {
            $messages = $this->stripImageRefParts($messages);
        }

        return $this->messageConverter->toMessageBag($messages);
    }

    /**
     * Determine whether the resolved model supports image inputs.
     */
    private function isImagesSupported(string $modelName): bool
    {
        if ('' === $modelName) {
            return false;
        }

        return $this->imageCapabilityChecker->supportsImages($modelName);
    }

    /**
     * Replace image_ref content parts with text-only metadata placeholders.
     *
     * When a tool message carries image_ref parts but the target model
     * does not support images, the image data would be unusable. This
     * method replaces each image_ref part with a text summary so the model
     * can still reason about the tool result without receiving image bytes.
     *
     * @param list<\Ineersa\AgentCore\Domain\Message\AgentMessage> $messages
     *
     * @return list<\Ineersa\AgentCore\Domain\Message\AgentMessage>
     */
    private function stripImageRefParts(array $messages): array
    {
        $stripped = [];

        foreach ($messages as $message) {
            $hasImageRef = false;
            $newContent = [];

            foreach ($message->content as $contentPart) {
                if (!\is_array($contentPart)) {
                    $newContent[] = $contentPart;
                    continue;
                }

                if ('image_ref' === ($contentPart['type'] ?? null)) {
                    $hasImageRef = true;

                    $path = $contentPart['path'] ?? '(unknown)';
                    $mediaType = $contentPart['media_type'] ?? 'unknown';
                    $width = $contentPart['width'] ?? '?';
                    $height = $contentPart['height'] ?? '?';
                    $bytes = $contentPart['bytes'] ?? 0;

                    $newContent[] = [
                        'type' => 'text',
                        'text' => \sprintf(
                            '[Tool result image: %s (%s, %sx%s, %d bytes). '
                            .'Actual image omitted — the active model does not support images.]',
                            $path,
                            $mediaType,
                            $width,
                            $height,
                            $bytes,
                        ),
                    ];

                    continue;
                }

                $newContent[] = $contentPart;
            }

            if ($hasImageRef) {
                $stripped[] = new \Ineersa\AgentCore\Domain\Message\AgentMessage(
                    role: $message->role,
                    content: $newContent,
                    timestamp: $message->timestamp,
                    name: $message->name,
                    toolCallId: $message->toolCallId,
                    toolName: $message->toolName,
                    details: $message->details,
                    isError: $message->isError,
                    metadata: $message->metadata,
                );
            } else {
                $stripped[] = $message;
            }
        }

        return $stripped;
    }
}
