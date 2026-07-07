<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

/**
 * Incremental stdout reads from controller-owned messenger consumer processes.
 *
 * Keys match ConsumerSupervisor composite keys (e.g. llm#0, tool#1).
 */
interface ConsumerStdoutSourceInterface
{
    /**
     * @return iterable<string, string> consumerKey => incremental stdout chunk
     */
    public function readIncrementalStdoutByConsumer(): iterable;
}
