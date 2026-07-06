<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\SubagentLiveCommandRegistrar;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SubagentLiveCommandRegistrar::class)]
final class SubagentLiveCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function registersAgentsLiveAndAgentsMainWithoutAgentsCancel(): void
    {
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'subagent-live-registrar');
        $state = new TuiSessionState('subagent-live-registrar');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $registrar = $this->createRegistrar($registry);
        $registrar->register($context);

        $this->assertTrue($registry->has('agents-live'));
        $this->assertTrue($registry->has('agents-main'));
        $this->assertFalse($registry->has('agents-cancel'));
    }

    private function createRegistrar(SlashCommandRegistry $registry): SubagentLiveCommandRegistrar
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                $this->createStub(TranscriptProjectorInterface::class),
                new NullLogger(),
            ),
        );

        return new SubagentLiveCommandRegistrar(
            $registry,
            $picker,
        );
    }
}
