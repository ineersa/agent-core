<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Persists parent tool-call context for background child artifact supervision.
 */
final class AgentChildArtifactLaunchContextStore
{
    private const string FILENAME = 'launch-context.json';

    public function __construct(
        private readonly AgentArtifactPathResolver $pathResolver,
    ) {
    }

    /**
     * @param array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     *     task_summary: string,
     *     agent_name: string,
     *     resolved_model: ?string,
     *     progress_started_micros: int,
     * } $context
     */
    public function write(string $parentRunId, string $artifactId, array $context): void
    {
        $path = $this->resolvePath($parentRunId, $artifactId);
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create artifact directory "%s".', $dir));
        }

        $encoded = json_encode($context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $tmp = $path.'.tmp-' . bin2hex(random_bytes(4));
        if (false === file_put_contents($tmp, $encoded)) {
            throw new \RuntimeException(\sprintf('Cannot write launch context "%s".', $tmp));
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(\sprintf('Cannot rename launch context to "%s".', $path));
        }
    }

    /**
     * @return array{
     *     parent_tool_call_id: string,
     *     parent_turn_no: int,
     *     parent_tool_name: string,
     *     task_summary: string,
     *     agent_name: string,
     *     resolved_model: ?string,
     *     progress_started_micros: int,
     * }|null
     */
    public function read(string $parentRunId, string $artifactId): ?array
    {
        $path = $this->resolvePath($parentRunId, $artifactId);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        $toolCallId = $decoded['parent_tool_call_id'] ?? null;
        $turnNo = $decoded['parent_turn_no'] ?? null;
        $toolName = $decoded['parent_tool_name'] ?? null;
        $taskSummary = $decoded['task_summary'] ?? null;
        $agentName = $decoded['agent_name'] ?? null;
        $startedMicros = $decoded['progress_started_micros'] ?? null;

        if (!\is_string($toolCallId) || !\is_int($turnNo) || !\is_string($toolName)
            || !\is_string($taskSummary) || !\is_string($agentName) || !\is_int($startedMicros)) {
            return null;
        }

        $model = $decoded['resolved_model'] ?? null;
        if (null !== $model && !\is_string($model)) {
            $model = null;
        }

        return [
            'parent_tool_call_id' => $toolCallId,
            'parent_turn_no' => $turnNo,
            'parent_tool_name' => $toolName,
            'task_summary' => $taskSummary,
            'agent_name' => $agentName,
            'resolved_model' => $model,
            'progress_started_micros' => $startedMicros,
        ];
    }

    private function resolvePath(string $parentRunId, string $artifactId): string
    {
        return $this->pathResolver->resolveArtifactDir($parentRunId, $artifactId).'/'.self::FILENAME;
    }
}
