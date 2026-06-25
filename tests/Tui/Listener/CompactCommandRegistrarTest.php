<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandRegistry;
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
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'compact-registrar');
        $state = new TuiSessionState('compact-registrar');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $registrar = new CompactCommandRegistrar($registry);
        $registrar->register($context);

        $this->assertTrue($registry->has('compact'));
        $this->assertTrue($registry->has('cmp'));

        $meta = $registry->getMetadata('compact');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('compact', $meta->name);
        $this->assertContains('cmp', $meta->aliases);
        $this->assertSame('Compact the conversation to reduce token usage', $meta->description);
    }

    #[Test]
    public function compactCommandAppearsInHelpOutput(): void
    {
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'compact-help');
        $state = new TuiSessionState('compact-help');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new CompactCommandRegistrar($registry))->register($context);

        $result = $registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('/compact', $result->text);
        $this->assertStringContainsString('Compact', $result->text);
    }

    #[Test]
    public function idempotentRegistrationReplacesHandlerWithoutThrowing(): void
    {
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'compact-idempotent');
        $state = new TuiSessionState('compact-idempotent');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $registrar = new CompactCommandRegistrar($registry);
        $registrar->register($context);
        $registrar->register($context);

        $this->assertTrue($registry->has('compact'));
        $result = $registry->execute(new SlashCommand('compact', '', '/compact'));
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('No active session to compact.', $result->text);
    }
}
