<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\UuidV7;

/**
 * Thesis: rewind/edit that discards forward history must clear reasoning_baseline.
 * Otherwise last_emitted=high with a discarded high transition leaves selected high
 * suppressed against baseline=medium on the next request.
 */
final class HistoryTailDiscardClearsReasoningBaselineTest extends IsolatedKernelTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('history-tail-baseline', 0o750);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testActualDiscardClearsBaselineSoSelectedHighRebaselinesWithoutSpuriousUpdate(): void
    {
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $sessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $em,
            dispatcher: new EventDispatcher(),
        );

        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = 'openai-codex/gpt-6-astra';
        $entity->reasoning = 'high';
        $entity->providerCacheKey = UuidV7::v7()->toRfc4122();
        $em->persist($entity);
        $em->flush();
        $sessionId = (string) $entity->id;

        // Epoch starts at medium, then a high switch is remembered on a forward turn.
        $this->assertNull($sessionStore->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'medium'));
        $this->assertSame(
            'high',
            $sessionStore->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'high')['update'] ?? null,
        );
        $sessionStore->rememberReasoningTransition(
            $sessionId,
            'openai-codex/gpt-6-astra',
            hash('sha256', 'user||forward-high-anchor|0'),
            'high',
        );
        $this->assertNotSame([], $sessionStore->listReasoningTransitions($sessionId, 'openai-codex/gpt-6-astra'));
        $this->assertSame('high', $sessionStore->findSession($sessionId)?->reasoning);

        $runId = $sessionId;
        $events = [
            $this->event($runId, 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event($runId, 3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event($runId, 4, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            // Rewind/edit: tip moves behind retained forward turn that held the high transition.
            $this->event($runId, 5, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'history_select',
            ]),
        ];

        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->method('allFor')->willReturn($events);
        $eventStore->expects($this->never())->method('appendTransition');

        $service = new HistoryTailDiscardService(
            $eventStore,
            new HistoryProjector(),
            $sessionStore,
            new NullLogger(),
        );

        $result = $service->prepareForwardTailDiscard(
            $runId,
            new RunState(
                runId: $runId,
                status: RunStatus::Completed,
                version: 1,
                turnNo: 1,
                lastSeq: 5,
            ),
        );

        $this->assertNotNull($result);
        $this->assertNotEmpty($sessionStore->listReasoningTransitions($sessionId, 'openai-codex/gpt-6-astra'));
        $service->afterDiscardCommitted($runId);
        $this->assertSame(
            ['continuation_generation' => $sessionStore->continuationGeneration($sessionId)],
            $sessionStore->findSession($sessionId)?->reasoningBaseline,
        );
        $this->assertSame('high', $sessionStore->findSession($sessionId)?->reasoning);
        $this->assertSame([], $sessionStore->listReasoningTransitions($sessionId, 'openai-codex/gpt-6-astra'));

        // Rebuilt request after edited prompt must establish selected high as the
        // new baseline without emitting a spurious configuration_update.
        $this->assertNull($sessionStore->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'high'));
        $this->assertNull(
            $sessionStore->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'high')['update'] ?? null,
        );
    }

    public function testNoOpAtTipDoesNotClearBaseline(): void
    {
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $sessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $em,
            dispatcher: new EventDispatcher(),
        );

        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = 'openai-codex/gpt-6-astra';
        $entity->reasoning = 'high';
        $entity->providerCacheKey = UuidV7::v7()->toRfc4122();
        $em->persist($entity);
        $em->flush();
        $sessionId = (string) $entity->id;

        $this->assertNull($sessionStore->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'medium'));
        $sessionStore->rememberReasoningTransition(
            $sessionId,
            'openai-codex/gpt-6-astra',
            hash('sha256', 'user||tip-anchor|0'),
            'high',
        );
        $before = $sessionStore->findSession($sessionId)?->reasoningBaseline;
        $this->assertNotNull($before);

        $events = [
            $this->event($sessionId, 1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event($sessionId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
        ];

        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->method('allFor')->willReturn($events);
        $eventStore->expects($this->never())->method('appendTransition');

        $service = new HistoryTailDiscardService(
            $eventStore,
            new HistoryProjector(),
            $sessionStore,
            new NullLogger(),
        );

        $result = $service->prepareForwardTailDiscard(
            $sessionId,
            new RunState(
                runId: $sessionId,
                status: RunStatus::Completed,
                version: 1,
                turnNo: 1,
                lastSeq: 2,
            ),
        );

        $this->assertNull($result);
        $this->assertSame($before, $sessionStore->findSession($sessionId)?->reasoningBaseline);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $runId, int $seq, int $turnNo, string $type, array $payload): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }
}
