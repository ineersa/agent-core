<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Compact transcript lines for view_image tool cards (metadata only).
 *
 * Symfony TUI in this project has no public ImageWidget for inline terminal previews;
 * do not emit Kitty/iTerm/sixel escapes from transcript rendering.
 */
final class ViewImageTranscriptFormatter
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function formatToolCallLines(array $arguments): array
    {
        $path = $arguments['path'] ?? null;
        if (!\is_string($path) || '' === $path) {
            return [];
        }

        return ['path: '.$path];
    }

    /**
     * @param array<string, mixed>|string|null $result
     *
     * @return list<string>
     */
    public function formatToolResultLines(array|string|null $result): array
    {
        if (\is_string($result)) {
            return $this->formatDecodedResult($this->tryDecodeJson($result));
        }

        if (!\is_array($result)) {
            return [];
        }

        return $this->formatDecodedResult($result);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function formatDecodedResult(array $data): array
    {
        if (($data['type'] ?? null) !== 'view_image') {
            return [];
        }

        $lines = [];
        $path = $data['path'] ?? null;
        if (\is_string($path) && '' !== $path) {
            $lines[] = 'path: '.$path;
        }

        $media = $data['media_type'] ?? null;
        if (\is_string($media) && '' !== $media) {
            $lines[] = 'media: '.$media;
        }

        $width = $data['width'] ?? null;
        $height = $data['height'] ?? null;
        if (\is_int($width) && \is_int($height) && $width > 0 && $height > 0) {
            $lines[] = \sprintf('size: %dx%d', $width, $height);
        }

        $bytes = $data['bytes'] ?? null;
        if (\is_int($bytes) && $bytes >= 0) {
            $lines[] = 'bytes: '.$bytes;
        }

        if (true === ($data['processed_dimensions'] ?? false) || 1 === ($data['processed_dimensions'] ?? null)) {
            $lines[] = 'processed: resized for provider';
        }

        $warning = $data['warning'] ?? null;
        if (\is_string($warning) && '' !== $warning) {
            $lines[] = 'warning: '.$warning;
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function tryDecodeJson(string $result): array
    {
        try {
            $decoded = json_decode($result, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
