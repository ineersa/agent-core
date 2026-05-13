<?php

declare(strict_types=1);

namespace App\Runtime\Protocol;

/**
 * Encodes/decodes runtime commands and events to/from JSONL format.
 *
 * JSONL = one JSON object per line. Used for both stdin commands and stdout events
 * in the headless process transport.
 */
final class JsonlCodec
{
    /**
     * Encodes a RuntimeCommand to a JSONL line.
     */
    public static function encodeCommand(RuntimeCommand $command): string
    {
        return self::encodeLine($command->toArray());
    }

    /**
     * Decodes a JSONL line into a RuntimeCommand.
     */
    public static function decodeCommand(string $line): RuntimeCommand
    {
        return RuntimeCommand::fromArray(self::decodeLine($line));
    }

    /**
     * Encodes a RuntimeEvent to a JSONL line.
     */
    public static function encodeEvent(RuntimeEvent $event): string
    {
        return self::encodeLine($event->toArray());
    }

    /**
     * Decodes a JSONL line into a RuntimeEvent.
     */
    public static function decodeEvent(string $line): RuntimeEvent
    {
        return RuntimeEvent::fromArray(self::decodeLine($line));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeLine(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeLine(string $line): array
    {
        $trimmed = trim($line);

        if ('' === $trimmed) {
            throw new \RuntimeException('Empty line in JSONL stream');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('Expected JSON object, got %s', \get_debug_type($data)));
        }

        return $data;
    }
}
