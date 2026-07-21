<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Lifecycle;

use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecycleDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecyclePhaseEnum;
use Psr\Log\LoggerInterface;

/**
 * Dispatches owning-runtime lifecycle notifications to extension hooks.
 *
 * Failures are isolated and never prevent controller shutdown.
 *
 * @internal
 */
final class RuntimeLifecycleNotifier
{
    private bool $started = false;
    private bool $stopping = false;

    public function __construct(
        private readonly ExtensionHookRegistry $hookRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar|list<mixed>|array<string, mixed>|null> $metadata
     */
    public function notifyStarted(string $ownerKind = 'headless_controller', array $metadata = []): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->dispatch(new RuntimeLifecycleDTO(
            phase: RuntimeLifecyclePhaseEnum::Started,
            ownerKind: $ownerKind,
            occurredAt: new \DateTimeImmutable(),
            metadata: $metadata,
        ));
    }

    /**
     * @param array<string, scalar|list<mixed>|array<string, mixed>|null> $metadata
     */
    public function notifyStopping(string $ownerKind = 'headless_controller', array $metadata = []): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->dispatch(new RuntimeLifecycleDTO(
            phase: RuntimeLifecyclePhaseEnum::Stopping,
            ownerKind: $ownerKind,
            occurredAt: new \DateTimeImmutable(),
            metadata: $metadata,
        ));
    }

    private function dispatch(RuntimeLifecycleDTO $lifecycle): void
    {
        foreach ($this->hookRegistry->runtimeLifecycleHooks() as $hook) {
            try {
                $hook->onRuntimeLifecycle($lifecycle);
            } catch (\Throwable $e) {
                $this->logger->warning('extension.runtime_lifecycle_hook_failed', [
                    'component' => 'extension_runtime_lifecycle',
                    'event_type' => 'runtime_lifecycle_hook_failed',
                    'phase' => $lifecycle->phase->value,
                    'owner_kind' => $lifecycle->ownerKind,
                    'hook' => $hook::class,
                    'exception_class' => $e::class,
                ]);
            }
        }
    }
}
