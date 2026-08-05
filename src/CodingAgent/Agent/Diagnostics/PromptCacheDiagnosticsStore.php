<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Diagnostics;

use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Psr\Log\LoggerInterface;

/**
 * CodingAgent-owned prompt-cache diagnostics sidecar (not canonical RunEvent).
 *
 * Parent:  .hatfield/sessions/<sessionId>/diagnostics/prompt-cache.jsonl
 * Child:   .hatfield/sessions/<parent>/artifacts/agents/<artifactId>/diagnostics/prompt-cache.jsonl
 *
 * Unknown/ephemeral run ids that are neither a parent session nor a registered
 * child artifact are skipped (no global orphan path). Append uses FILE_APPEND|LOCK_EX only.
 */
final class PromptCacheDiagnosticsStore
{
    private const string RELATIVE_FILE = 'diagnostics/prompt-cache.jsonl';

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly SessionAgentArtifactPathResolver $artifactPathResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $record Privacy-safe structural record only
     */
    public function append(string $runId, array $record): void
    {
        $path = $this->resolvePath($runId);
        if (null === $path) {
            return;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, SessionAgentArtifactPathResolver::DIR_PERMISSIONS, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create diagnostics directory "%s".', $dir));
        }

        $json = json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $written = file_put_contents($path, $json."\n", \FILE_APPEND | \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to append prompt-cache diagnostics for run "%s".', $runId));
        }
        @chmod($path, SessionAgentArtifactPathResolver::FILE_PERMISSIONS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readForRun(string $runId): array
    {
        $path = $this->resolvePath($runId);
        if (null === $path || !is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (false === $raw || '' === $raw) {
            return [];
        }

        $records = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warning('session.prompt_cache_diagnostics.line_unreadable', [
                    'component' => 'prompt_cache_diagnostics_store',
                    'event_type' => 'session.prompt_cache_diagnostics.line_unreadable',
                    'run_id' => $runId,
                    'exception_class' => $e::class,
                ]);
                continue;
            }
            if (\is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    private function resolvePath(string $runId): ?string
    {
        if ('' === $runId) {
            return null;
        }

        if (null !== $this->hatfieldSessionStore->findSession($runId)) {
            return $this->hatfieldSessionStore->resolveSessionsBasePath().'/'.$runId.'/'.self::RELATIVE_FILE;
        }

        $child = $this->childRunDirectory->locate($runId);
        if (null === $child) {
            return null;
        }

        return $this->artifactPathResolver->resolveArtifactDir($child->parentRunId, $child->artifactId).'/'.self::RELATIVE_FILE;
    }
}
