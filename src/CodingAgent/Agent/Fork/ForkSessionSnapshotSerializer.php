<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Symfony\Component\Serializer\SerializerInterface;

/**
 * Serializes ForkSessionSnapshotDTO to/from JSON files for fork child consumption.
 *
 * The snapshot is a standalone JSON file containing all seed messages,
 * the system-prompt append, the task user message, and level/model metadata.
 * Written by the parent (ForkContextBuilder) and loaded by the child
 * (FORK-03 bootstrap) via this serializer.
 *
 * Uses Symfony Serializer for deterministic round-trip fidelity of
 * AgentMessage metadata/tool_calls and enum values.
 *
 * No backward-compatibility dual-format readers in v1.
 */
final readonly class ForkSessionSnapshotSerializer
{
    public function __construct(
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * Serialize a ForkSessionSnapshotDTO to a JSON string.
     *
     * @return string Pretty-printed JSON
     *
     * @throws \RuntimeException on serialization failure
     */
    public function serialize(ForkSessionSnapshotDTO $snapshot): string
    {
        try {
            return $this->serializer->serialize($snapshot, 'json', [
                'json_encode_options' => \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException(\sprintf('Failed to serialize fork snapshot: %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * Deserialize a ForkSessionSnapshotDTO from a JSON string.
     *
     * @return ForkSessionSnapshotDTO The deserialized snapshot
     *
     * @throws \RuntimeException on deserialization failure
     */
    public function deserialize(string $json): ForkSessionSnapshotDTO
    {
        try {
            /* @var ForkSessionSnapshotDTO */
            return $this->serializer->deserialize($json, ForkSessionSnapshotDTO::class, 'json');
        } catch (\Throwable $e) {
            throw new \RuntimeException(\sprintf('Failed to deserialize fork snapshot: %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * Write a snapshot to a JSON file atomically (temp + rename).
     *
     * @param ForkSessionSnapshotDTO $snapshot The snapshot to write
     * @param string                 $path     Absolute filesystem path for the output file
     *
     * @throws \RuntimeException on write failure
     */
    public function toFile(ForkSessionSnapshotDTO $snapshot, string $path): void
    {
        $json = $this->serialize($snapshot);

        $dir = \dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
            }
        }

        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = file_put_contents($tmpPath, $json, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write fork snapshot to: %s', $path));
        }
        chmod($tmpPath, 0o644);
        rename($tmpPath, $path);
    }

    /**
     * Read a ForkSessionSnapshotDTO from a JSON file.
     *
     * @param string $path Absolute filesystem path to the snapshot file
     *
     * @return ForkSessionSnapshotDTO The loaded snapshot
     *
     * @throws \RuntimeException when the file is missing, unreadable, or corrupt
     */
    public function fromFile(string $path): ForkSessionSnapshotDTO
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Fork snapshot file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(\sprintf('Fork snapshot file not readable: %s', $path));
        }

        $json = file_get_contents($path);
        if (false === $json || '' === trim($json)) {
            throw new \RuntimeException(\sprintf('Fork snapshot file is empty: %s', $path));
        }

        return $this->deserialize($json);
    }
}
