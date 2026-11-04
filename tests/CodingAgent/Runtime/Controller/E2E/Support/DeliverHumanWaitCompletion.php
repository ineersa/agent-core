<?php

declare(strict_types=1);

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Kernel;

require $argv[1].'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $bus = $kernel->getContainer()->get('test.service_container')->get('agent.command.bus');
    $runId = $argv[2];
    $bus->dispatch(new DeliverBackgroundCompletionDTO($runId, 'human-wait-completion', 'Background work finished.', origin: ['kind' => 'subagent']));
    // Both envelopes precede the controller's subsequent answer on run_control.
    // Cross the consumption boundary explicitly, without a timing-window wait.
    $bus->dispatch(new AdvanceRun($runId, 2, 'human-wait-boundary', 1, 'human-wait-boundary'));
} finally {
    $kernel->shutdown();
}
