<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

final readonly class MemoryChunker
{
    public function __construct(private SemanticSettings $settings)
    {
    }

    /** @return list<string> */
    public function split(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('Memory text must be valid UTF-8.');
        }
        $chunks = [];
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length) {
            $chunk = mb_strcut($text, $offset, $this->settings->chunkBytes, 'UTF-8');
            $lines = explode("\n", $chunk);
            if (\count($lines) > $this->settings->chunkLines) {
                $chunk = implode("\n", \array_slice($lines, 0, $this->settings->chunkLines))."\n";
            }
            $chunks[] = $chunk;
            $end = $offset + \strlen($chunk);
            if ($end >= $length) {
                break;
            }
            // Advance at least one full codepoint even when a line limit leaves a
            // chunk shorter than the requested overlap. Never split UTF-8 bytes.
            $next = max($offset + 1, $end - $this->settings->overlapBytes);
            while ($next < $end && (\ord($text[$next]) & 0xC0) === 0x80) {
                ++$next;
            }
            $offset = $next;
        }

        return $chunks;
    }
}
