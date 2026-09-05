<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\JbcontextExtension;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextSessionStartHook;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class JbcontextExtensionRegistrationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-reg-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function registerDoesNotDispatchEligibilityFromWorkerLoad(): void
    {
        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        (new JbcontextExtension())->register($api);

        $this->assertCount(1, $api->tools);
        $this->assertSame('code_search', $api->tools[0]->name);
        $this->assertSame(['text'], $api->tools[0]->parametersJsonSchema['required']);
        $this->assertArrayHasKey('path_filter', $api->tools[0]->parametersJsonSchema['properties']);
        $this->assertArrayHasKey(JbcontextEligibilityJobHandler::HANDLER_ID, $api->handlers);
        $this->assertCount(1, $api->afterTurnHooks);
        $this->assertCount(1, $api->sessionStartHooks);
        $this->assertInstanceOf(JbcontextSessionStartHook::class, $api->sessionStartHooks[0]);
        $this->assertSame([], $api->jobs);
    }

    #[Test]
    public function interactiveTuiTickOnlyRegistersReadOnlyPoller(): void
    {
        $api = new TestExtensionApi($this->projectDir, new RecordingExec());
        $extension = new JbcontextExtension();
        $extension->register($api);

        $ticks = [];
        $sessionId = 'session-a';
        $tui = new class($ticks, $sessionId) implements TuiExtensionContextInterface {
            /** @param list<\Closure(): mixed> $ticks */
            public function __construct(
                private array &$ticks,
                private string $sessionId,
            ) {
            }

            public function getSessionId(): string
            {
                return $this->sessionId;
            }

            public function requestRender(bool $force = false): void
            {
            }

            public function setStatus(string $key, ?string $text): void
            {
            }

            public function onTick(\Closure $listener): void
            {
                $this->ticks[] = $listener;
            }

            public function insertOverlayAfterEditor(AbstractWidget $widget): void
            {
            }

            public function removeOverlay(AbstractWidget $widget): void
            {
            }

            public function setFocus(AbstractWidget $widget): void
            {
            }

            public function formatMuted(string $text): string
            {
                return $text;
            }

            public function formatRolePrefix(string $displayRole): string
            {
                return $displayRole;
            }

            public function turnRowsInDisplayOrder(string $sessionId): array
            {
                return [];
            }
        };

        $extension->registerTui($tui);
        $this->assertCount(1, $ticks);
        $this->assertSame([], $api->jobs);

        ($ticks[0])();
        $this->assertSame([], $api->jobs);
    }
}
