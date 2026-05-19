<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Transient sink for runtime events produced during LLM streaming.
 *
 * Transport-agnostic: in-process implementations buffer events
 * in memory; process/headless implementations write JSONL to stdout.
 *
 * Events emitted through this sink are EPHEMERAL by default. They
 * are not persisted to canonical AgentCore event stores unless a
 * downstream consumer (e.g., RuntimeEventPoller) chooses to do so.
 */
interface RuntimeEventSinkInterface
{
    public function emit(RuntimeEvent $event): void;
}
