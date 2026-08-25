<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * The one operation that may currently produce a result for a run.
 *
 * This is deliberately a bounded checkpoint, not a receipt/history ledger.
 */
enum CurrentOperationKindEnum: string
{
    case Llm = 'llm';
    case ToolBatch = 'tool_batch';
    case Shell = 'shell';
    case Compaction = 'compaction';
    case Advance = 'advance';
}
