<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Command;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TransientTuiExtensionContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmQueryService;
use Ineersa\HatfieldExt\ObservationalMemory\Query\OmSessionContext;
use Ineersa\HatfieldExt\ObservationalMemory\Tui\OmTransientWidgetFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class OmStatusCommandHandler implements ExtensionCommandHandlerInterface
{
    public function __construct(
        private readonly OmQueryService $query,
        private readonly OmSessionContext $sessionContext,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(string $args, CommandContextInterface $context): void
    {
        $runId = null;
        try {
            $runId = $this->sessionContext->requireSessionId();
            $tui = $this->sessionContext->tui();
            if ($tui instanceof TransientTuiExtensionContextInterface) {
                // Rich temporary widget above the editor; not transcript history.
                $tui->showTransientWidget(
                    OmTransientWidgetFactory::status($tui, $this->query->statusData($runId)),
                );

                return;
            }

            // Older hosts without the richer TUI surface keep plain notify().
            $context->notify($this->query->formatStatus($runId), 'info');
        } catch (\Throwable) {
            // Never surface raw exception text (paths/content). Structured log only.
            // Capture run_id before try so a throwing lazy getSessionId() cannot rethrow in catch.
            $this->logger->error('om.command.status_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.command.status_failed',
                'run_id' => $runId,
            ]);
            $context->notify('OM status unavailable.', 'error');
        }
    }
}
