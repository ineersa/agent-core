<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Lifecycle;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\Lifecycle\RuntimeLifecycleNotifier;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecycleDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecycleHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\RuntimeLifecyclePhaseEnum;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: owning-controller lifecycle notifies start then idempotent stop once,
 * and hook failures are isolated.
 */
final class RuntimeLifecycleNotifierTest extends TestCase
{
    public function testStartThenIdempotentStop(): void
    {
        $registry = new ExtensionHookRegistry();
        $phases = [];
        $registry->addRuntimeLifecycleHook(new class($phases) implements RuntimeLifecycleHookInterface {
            /** @param list<string> $phases */
            public function __construct(private array &$phases)
            {
            }

            public function onRuntimeLifecycle(RuntimeLifecycleDTO $lifecycle): void
            {
                $this->phases[] = $lifecycle->phase->value;
            }
        });

        $notifier = new RuntimeLifecycleNotifier($registry, new TestLogger());
        $notifier->notifyStarted('headless_controller');
        $notifier->notifyStarted('headless_controller');
        $notifier->notifyStopping('headless_controller');
        $notifier->notifyStopping('headless_controller');

        $this->assertSame([
            RuntimeLifecyclePhaseEnum::Started->value,
            RuntimeLifecyclePhaseEnum::Stopping->value,
        ], $phases);
    }

    public function testHookFailureDoesNotPreventLaterHooksOrShutdown(): void
    {
        $registry = new ExtensionHookRegistry();
        $second = false;
        $registry->addRuntimeLifecycleHook(new class implements RuntimeLifecycleHookInterface {
            public function onRuntimeLifecycle(RuntimeLifecycleDTO $lifecycle): void
            {
                throw new \RuntimeException('lifecycle boom');
            }
        });
        $registry->addRuntimeLifecycleHook(new class($second) implements RuntimeLifecycleHookInterface {
            public function __construct(private bool &$second)
            {
            }

            public function onRuntimeLifecycle(RuntimeLifecycleDTO $lifecycle): void
            {
                $this->second = true;
            }
        });

        $logger = new TestLogger();
        $notifier = new RuntimeLifecycleNotifier($registry, $logger);
        $notifier->notifyStarted();

        $this->assertTrue($second);
        $this->assertSame('extension.runtime_lifecycle_hook_failed', $logger->records[0]['message'] ?? null);
    }
}
