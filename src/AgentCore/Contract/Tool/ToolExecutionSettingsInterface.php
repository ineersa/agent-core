<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Provides tool execution defaults resolved by the application layer.
 *
 * AgentCore owns the execution policy model, but Hatfield/CodingAgent owns
 * settings discovery and precedence. This contract keeps ToolExecutor free of
 * CodingAgent config dependencies while allowing the app container to inject
 * values from merged Hatfield settings instead of hard-coded service literals.
 */
interface ToolExecutionSettingsInterface
{
    public function defaultMode(): string;

    public function defaultTimeoutSeconds(): int;

    public function maxParallelism(): int;
}
