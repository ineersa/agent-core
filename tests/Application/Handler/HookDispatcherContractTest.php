<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\HookSubscriberRegistry;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class HookDispatcherContractTest extends TestCase
{
    public function testBoundaryHookSubscribersCanObserveAndMutateContext(): void
    {
        $subscriber = new class implements HookSubscriberInterface {
            public static function subscribedHooks(): array
            {
                return [BoundaryHookName::BEFORE_COMMAND_APPLY];
            }

            public function handle(string $hookName, array $context): array
            {
                $context['observed_hook'] = $hookName;
                $context['applied'] = true;

                return $context;
            }
        };

        $dispatcher = new HookDispatcher(
            new HookSubscriberRegistry([$subscriber]),
            new EventDispatcher(),
        );

        $context = $dispatcher->dispatch(BoundaryHookName::BEFORE_COMMAND_APPLY, ['run_id' => 'run-stage-01']);

        self::assertSame(BoundaryHookName::BEFORE_COMMAND_APPLY, $context['observed_hook']);
        self::assertTrue($context['applied']);
    }

    public function testUnknownBoundaryHookNamesAreRejected(): void
    {
        $dispatcher = new HookDispatcher(
            new HookSubscriberRegistry([]),
            new EventDispatcher(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown boundary hook "before_anything"');

        $dispatcher->dispatch('before_anything');
    }
}
