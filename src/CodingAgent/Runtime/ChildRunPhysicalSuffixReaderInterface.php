<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Physical unread-suffix reader for child event archives.
 *
 * {@see EventStoreInterface::rangeFor()} still scans from the file head, so
 * child snapshot advances must use this reverse-cursor path instead.
 */
interface ChildRunPhysicalSuffixReaderInterface
{
    /**
     * @return list<RunEvent>
     */
    public function readAfterSeq(string $runId, int $cursor): array;
}
