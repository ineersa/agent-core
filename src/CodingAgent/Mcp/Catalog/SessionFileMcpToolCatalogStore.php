<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Session-file implementation of the MCP tool catalog store.
 *
 * Writes catalogs to `<projectCwd>/.hatfield/sessions/<runId>/mcp-tools.json`.
 * Uses atomic temp-file + rename so concurrent or polling readers see only
 * complete snapshots.
 *
 * Run ID is sanitized to prevent path traversal; session directory is
 * created on demand.
 */
final class SessionFileMcpToolCatalogStore implements McpToolCatalogStoreInterface
{
    /**
     * @param string $projectCwd Absolute project root path
     */
    public function __construct(
        private readonly string $projectCwd,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function write(string $runId, McpToolCatalogDTO $catalog): void
    {
        $this->sanitizeRunId($runId);
        $dir = $this->sessionDir($runId);
        $this->ensureDirectory($dir);

        $targetPath = $dir.'/mcp-tools.json';

        try {
            $json = json_encode(
                $catalog->toArray(),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            );

            AtomicFileWriter::write($targetPath, $json);
        } catch (AtomicFileWriterException $exception) {
            throw new \RuntimeException('rename' === $exception->stage ? \sprintf('Failed to atomic-rename MCP catalog for run "%s".', $runId) : \sprintf('Failed to write MCP catalog for run "%s".', $runId), previous: $exception);
        }
    }

    public function read(string $runId): ?McpToolCatalogDTO
    {
        $this->sanitizeRunId($runId);
        $path = $this->sessionDir($runId).'/mcp-tools.json';

        $content = @file_get_contents($path);
        if (false === $content) {
            return null;
        }

        try {
            // Keep JSON objects distinct from arrays inside schema values.
            $data = (new JsonDecode())->decode($content, JsonEncoder::FORMAT);
        } catch (NotEncodableValueException $exception) {
            $this->logger?->warning('MCP catalog JSON decode failed — treating as missing catalog.', [
                'component' => 'mcp',
                'event_type' => 'mcp.catalog.read_decode_failed',
                'run_id' => $runId,
                'path' => $path,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!$data instanceof \stdClass && !\is_array($data)) {
            return null;
        }

        $data = (array) $data;
        $this->normalizeInputSchemas($data);

        return McpToolCatalogDTO::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    private function normalizeInputSchemas(array $data): void
    {
        $normalizer = new Serializer([new ObjectNormalizer()]);
        foreach ((array) ($data['servers'] ?? []) as $server) {
            if (!$server instanceof \stdClass || !\is_array($server->tools ?? null)) {
                continue;
            }

            foreach ($server->tools as $tool) {
                if ($tool instanceof \stdClass && isset($tool->inputSchema)) {
                    // Keep the legacy associative input schema without touching output values.
                    $tool->inputSchema = $normalizer->normalize($tool->inputSchema);
                }
            }
        }
    }

    /**
     * Build the session directory path for a run.
     */
    private function sessionDir(string $runId): string
    {
        return $this->projectCwd.'/.hatfield/sessions/'.$runId;
    }

    /**
     * Reject run IDs containing path traversal sequences, NUL bytes, or slashes.
     *
     * @throws \RuntimeException
     */
    private function sanitizeRunId(string $runId): void
    {
        if ('' === $runId) {
            throw new \RuntimeException('MCP catalog run ID must not be empty.');
        }

        if (\strlen($runId) !== strcspn($runId, "/\\\0")) {
            throw new \RuntimeException(\sprintf('MCP catalog run ID contains invalid characters (path separators or NUL): "%s".', $runId));
        }

        // Reject ".." segments that could escape the sessions directory.
        if (str_contains($runId, '..')) {
            throw new \RuntimeException(\sprintf('MCP catalog run ID contains ".." segment: "%s".', $runId));
        }
    }

    /**
     * Create a directory if it does not exist.
     *
     * @throws \RuntimeException if a non-directory entry exists at the path
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create MCP catalog session directory: a non-directory entry exists at "%s".', $dir));
        }

        if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create MCP catalog session directory: "%s".', $dir));
        }
    }
}
