<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/**
 * Compact immutable RunStarted launch fields for ordinary runtime consumers.
 *
 * Deliberately omits system prompt and message payloads.
 */
final readonly class RunStartedLaunchProjection
{
    /**
     * @param list<string>|null $allowedTools
     * @param list<string>|null $allowedExtensions
     */
    public function __construct(
        public string $model,
        public ?string $reasoning = null,
        public ?int $contextWindow = null,
        public string $sessionKind = 'main',
        public ?string $childKind = null,
        public ?string $parentRunId = null,
        public ?string $agentName = null,
        public ?string $artifactId = null,
        public bool $interactive = true,
        public ?array $allowedTools = null,
        public ?array $allowedExtensions = null,
    ) {
    }

    public function isAgentChild(): bool
    {
        return 'agent_child' === $this->sessionKind;
    }
}
