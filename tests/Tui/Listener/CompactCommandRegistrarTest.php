<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\CompactCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompactCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function registersCompactCommandWithMetadataAndAlias(): void
    {
        $catalog = new SlashCommandCatalog();
        $harness = new VirtualTuiHarness(sessionId: 'compact-registrar');
        $state = new TuiSessionState('compact-registrar');
        $context = $this->buildContext($harness, $state, $catalog);

        $registrar = new CompactCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $this->assertTrue($catalog->has('compact'));
        $this->assertTrue($catalog->has('cmp'));

        $meta = $catalog->getMetadata('compact');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('compact', $meta->name);
        $this->assertContains('cmp', $meta->aliases);
        $this->assertSame('Compact the conversation to reduce token usage', $meta->description);
    }

    #[Test]
    public function compactCommandAppearsInHelpOutput(): void
    {
        $catalog = new SlashCommandCatalog();
        $harness = new VirtualTuiHarness(sessionId: 'compact-help');
        $state = new TuiSessionState('compact-help');
        $context = $this->buildContext($harness, $state, $catalog);

        (new CompactCommandRegistrar())->registerCatalog($catalog);
        (new CompactCommandRegistrar())->register($context);

        $result = $context->sessionServices->commandRegistry->execute(new SlashCommand('help', '', '/help'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('/compact', $result->text);
        $this->assertStringContainsString('Compact', $result->text);
    }

    #[Test]
    public function repeatRegistrationBindsFreshHandlerWithoutThrowing(): void
    {
        $catalog = new SlashCommandCatalog();
        $harness = new VirtualTuiHarness(sessionId: 'compact-repeat');
        $state = new TuiSessionState('compact-repeat');
        $context = $this->buildContext($harness, $state, $catalog);

        $registrar = new CompactCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);
        $registrar->register($context);

        $this->assertTrue($catalog->has('compact'));
        $result = $context->sessionServices->commandRegistry->execute(new SlashCommand('compact', '', '/compact'));
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('No active session to compact.', $result->text);
    }

    private function buildContext(VirtualTuiHarness $harness, TuiSessionState $state, SlashCommandCatalog $catalog): \Ineersa\Tui\Runtime\TuiRuntimeContext
    {
        return $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
    }
}
