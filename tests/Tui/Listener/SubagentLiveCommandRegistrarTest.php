<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Listener\SubagentLiveCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubagentLiveCommandRegistrar::class)]
final class SubagentLiveCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function registersAgentsLiveAndAgentsMainWithoutAgentsCancel(): void
    {
        $catalog = new SlashCommandCatalog();
        $harness = new VirtualTuiHarness(sessionId: 'subagent-live-registrar');
        $state = new TuiSessionState('subagent-live-registrar');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();

        $registrar = new SubagentLiveCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $this->assertTrue($catalog->has('agents-live'));
        $this->assertTrue($catalog->has('agents-main'));
        $this->assertFalse($catalog->has('agents-cancel'));
    }
}
