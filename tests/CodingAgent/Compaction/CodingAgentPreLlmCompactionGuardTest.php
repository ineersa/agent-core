<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Contract\Compaction\PreLlmCompactionGuardInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\ActiveModelResolverInterface;
use Ineersa\CodingAgent\Compaction\CodingAgentPreLlmCompactionGuard;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Config\CompactionConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Compaction\CodingAgentPreLlmCompactionGuard
 */
#[AllowMockObjectsWithoutExpectations]
final class CodingAgentPreLlmCompactionGuardTest extends TestCase
{
    private CodingAgentPreLlmCompactionGuard $guard;
    private CompactionTokenEstimator $tokenEstimator;
    private CompactionConfig $compactionConfig;
    /** @var ActiveModelResolverInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $modelResolver;

    protected function setUp(): void
    {
        $this->tokenEstimator = new CompactionTokenEstimator();
        $this->compactionConfig = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 50,
        );
        $this->modelResolver = $this->createMock(ActiveModelResolverInterface::class);
        // Most tests don't care about the model; return null by default.
        $this->modelResolver->method('getActiveModel')->willReturn(null);

        $this->guard = new CodingAgentPreLlmCompactionGuard(
            $this->compactionConfig,
            $this->tokenEstimator,
            $this->modelResolver,
        );
    }

    private function makeTextMessage(string $role, string $text): AgentMessage
    {
        return AgentMessage::fromPayload([
            'content' => [['text' => $text]],
            'role' => $role,
        ]);
    }

    public function testReturnsTrueWhenThresholdExceeded(): void
    {
        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)), // ~62 tokens > 50
        ];

        self::assertTrue(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    public function testReturnsFalseWhenBelowThreshold(): void
    {
        $messages = [
            $this->makeTextMessage('user', 'Hello'),
        ];

        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    public function testReturnsFalseWhenAutoDisabled(): void
    {
        $disabledConfig = new CompactionConfig(autoEnabled: false, compactAfterTokens: 1);
        $guard = new CodingAgentPreLlmCompactionGuard(
            $disabledConfig,
            $this->tokenEstimator,
            $this->modelResolver,
        );

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];

        self::assertFalse(
            $guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    public function testReturnsFalseWhenCompactionInFlight(): void
    {
        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)),
        ];

        self::assertFalse(
            $this->guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, 'compact-1234567890'),
        );
    }

    public function testRespectsModelOverrides(): void
    {
        $configWithOverride = new CompactionConfig(
            autoEnabled: true,
            compactAfterTokens: 50,
            modelOverrides: [
                'openai/gpt-4' => ['compact_after_tokens' => 10000],
            ],
        );
        // Replace the default mock with one that returns the overridden model.
        $modelResolver = $this->createMock(ActiveModelResolverInterface::class);
        $modelResolver->method('getActiveModel')
            ->with('run-1')
            ->willReturn('openai/gpt-4');

        $guard = new CodingAgentPreLlmCompactionGuard(
            $configWithOverride,
            $this->tokenEstimator,
            $modelResolver,
        );

        $messages = [
            $this->makeTextMessage('user', str_repeat('x', 200)), // ~62 tokens < 10000 override
        ];

        self::assertFalse(
            $guard->shouldCompactBeforeLlmStep('run-1', 1, $messages, null),
        );
    }

    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(PreLlmCompactionGuardInterface::class, $this->guard);
    }
}
