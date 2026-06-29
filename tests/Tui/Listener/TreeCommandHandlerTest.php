<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\TreeCommandHandler;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(TreeCommandHandler::class)]
final class TreeCommandHandlerTest extends TestCase
{
    #[Test]
    public function testHandleReturnsNoOp(): void
    {
        $tree = new TurnTreeView(
            runId: 'test',
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(
                    turnNo: 1,
                    parentTurnNo: null,
                    childTurnNos: [],
                    anchorSeq: 2,
                    title: 'Root turn',
                    promptPreview: '',
                    createdAt: null,
                    isCurrentLeaf: true,
                ),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 1,
            activePathTurnNos: [1],
        );

        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $picker = new TreePickerController($provider);

        $tui = new Tui();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            new PromptEditor(),
        );
        $screen->mount($tui);
        $state = new TuiSessionState(sessionId: 'test-session', resuming: false);

        $picker->setRuntimeRefs($tui, $screen, $state);
        $handler = new TreeCommandHandler($picker);

        $command = new SlashCommand(name: 'tree', args: '', originalText: '/tree');
        $result = $handler->handle($command);

        self::assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testHandleOpensPickerWithTreeData(): void
    {
        $tree = new TurnTreeView(
            runId: 'test',
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(
                    turnNo: 1,
                    parentTurnNo: null,
                    childTurnNos: [],
                    anchorSeq: 2,
                    title: 'Root turn',
                    promptPreview: '',
                    createdAt: null,
                    isCurrentLeaf: true,
                ),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 1,
            activePathTurnNos: [1],
        );

        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $picker = new TreePickerController($provider);

        $tui = new Tui();
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            new PromptEditor(),
        );
        $screen->mount($tui);
        $state = new TuiSessionState(sessionId: 'test-session', resuming: false);

        $picker->setRuntimeRefs($tui, $screen, $state);
        $handler = new TreeCommandHandler($picker);

        $command = new SlashCommand(name: 'tree', args: '', originalText: '/tree');
        $handler->handle($command);

        self::assertTrue($picker->isOpen(), 'Handler should cause picker to open');
    }
}
