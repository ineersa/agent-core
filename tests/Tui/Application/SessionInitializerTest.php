<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SessionInitializerTest extends TestCase
{
    private string $projectDir;
    private SessionInitializer $sessionInit;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('session-initializer');
        $store = new HatfieldSessionStore(new AppConfig(new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'), new \Ineersa\CodingAgent\Config\LoggingConfig(), cwd: $this->projectDir), $this->createStub(EntityManagerInterface::class), new EventDispatcher());
        $this->sessionInit = new SessionInitializer($store, new TranscriptBlockFactory());
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testResumeWaitsForOwnerTransferWithoutChangingTheDurableCursor(): void
    {
        $state = new TuiSessionState('42', true);
        $state->lastSeq = 7;
        $blocks = $this->sessionInit->buildInitialTranscript($state);
        $this->assertFalse($state->sessionReady);
        $this->assertSame(7, $state->lastSeq);
        $this->assertSame('Restoring session...', $blocks[0]->text);
        $this->assertSame(0, $blocks[0]->seq);
    }

    public function testBuildInitialTranscriptFreshSessionReturnsWelcome(): void
    {
        // Fresh session does not call projector, but PHPUnit
        // expects mock expectations on setUp-managed mocks.

        $state = new TuiSessionState('test-fresh', false);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        $this->assertStringContainsString('Welcome to Hatfield', $blocks[0]->text);
    }

    public function testInitializeDraftReturnsEmptySessionId(): void
    {
        // Draft init is pure in-memory — no projector interaction.

        $state = $this->sessionInit->initializeDraft();

        $this->assertSame('', $state->sessionId);
        $this->assertFalse($state->resuming);
        $this->assertNull($state->request);
        $this->assertNull($state->handle);
    }

    public function testInitializeDraftWithRequestPreservesRequest(): void
    {
        $request = new StartRunRequest(prompt: '', runId: '', model: 'gpt-4');
        $state = $this->sessionInit->initializeDraft($request);

        $this->assertSame('', $state->sessionId);
        $this->assertSame($request, $state->request);
    }

    public function testBuildInitialTranscriptForDraftReturnsWelcome(): void
    {
        // Draft sessions never enter the replay path, so projector is unused.

        $state = $this->sessionInit->initializeDraft();
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        $this->assertStringContainsString('Welcome to Hatfield', $blocks[0]->text);
    }

    public function testDraftPromotionStartRunRequestNullDefaultsDoNotTypeError(): void
    {
        // Does not touch projector — this is a pure DTO construction test.

        $stateRequest = null;
        $sessionId = 'promo-test-42';
        $text = 'Hello from draft';

        $request = new StartRunRequest(
            prompt: $text,
            runId: $sessionId,
            cwd: $stateRequest->cwd ?? '',
            options: $stateRequest->options ?? [],
            model: $stateRequest?->model,
            reasoning: $stateRequest?->reasoning
        );

        $this->assertSame('Hello from draft', $request->prompt);
        $this->assertSame('promo-test-42', $request->runId);
        $this->assertSame('', $request->cwd);
        $this->assertSame([], $request->options);
        $this->assertNull($request->model);
        $this->assertNull($request->reasoning);
    }

    public function testDraftPromotionStartRunRequestPreservesDraftValues(): void
    {
        // Does not touch projector — pure DTO construction test.

        $stateRequest = new StartRunRequest(
            prompt: 'stale',
            runId: '',
            cwd: '/custom/path',
            options: ['foo' => 'bar'],
            model: 'gpt-4',
            reasoning: 'high'
        );
        $sessionId = 'promo-test-43';
        $text = 'Real user message';

        $request = new StartRunRequest(
            prompt: $text,
            runId: $sessionId,
            cwd: $stateRequest->cwd,
            options: $stateRequest->options,
            model: $stateRequest->model,
            reasoning: $stateRequest->reasoning
        );

        $this->assertSame('Real user message', $request->prompt);
        $this->assertSame('promo-test-43', $request->runId);
        $this->assertSame('/custom/path', $request->cwd);
        $this->assertSame(['foo' => 'bar'], $request->options);
        $this->assertSame('gpt-4', $request->model);
        $this->assertSame('high', $request->reasoning);
    }
}
