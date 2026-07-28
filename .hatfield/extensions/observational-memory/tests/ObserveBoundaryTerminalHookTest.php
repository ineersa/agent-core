<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Extension\InMemoryExtensionApiBridge;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryTerminalHook;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: hot hook dispatches only on terminal agent_end / final failure, with scalar payload.
 */
final class ObserveBoundaryTerminalHookTest extends TestCase
{
    public function testDispatchesOnAgentEndCompleted(): void
    {
        $api = new InMemoryExtensionApiBridge('/tmp');
        $settings = OmSettings::fromArray([
            'observer' => [
                'model' => 'llama_cpp_test/test',
                'schema_version' => 'o1',
                'renderer_version' => 'r1',
            ],
            'reflector' => [
                'model' => 'llama_cpp_test/test',
            ],
        ]);
        $hook = new ObserveBoundaryTerminalHook($api, $settings, new NullLogger());

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-1',
            turnNo: 2,
            status: 'completed',
            events: [
                new AfterTurnCommitEventSummaryDTO(seq: 8, type: 'llm_step_completed', payload: []),
                new AfterTurnCommitEventSummaryDTO(seq: 9, type: 'agent_end', payload: ['reason' => 'completed']),
            ],
            effectsCount: 0,
        ));

        $jobs = $api->getDispatchedExtensionAgentJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame(ObserveBoundaryTerminalHook::HANDLER_ID, $jobs[0]->handlerId);
        $this->assertSame('run-1', $jobs[0]->payload['run_id']);
        $this->assertSame(9, $jobs[0]->payload['terminal_end_seq']);
        $this->assertSame('completed', $jobs[0]->payload['terminal_status']);
    }

    public function testSkipsIntermediateToolBatch(): void
    {
        $api = new InMemoryExtensionApiBridge('/tmp');
        $settings = OmSettings::fromArray([
            'observer' => [
                'model' => 'llama_cpp_test/test',
                'schema_version' => 'o1',
                'renderer_version' => 'r1',
            ],
            'reflector' => [
                'model' => 'llama_cpp_test/test',
            ],
        ]);
        $hook = new ObserveBoundaryTerminalHook($api, $settings, new NullLogger());

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-1',
            turnNo: 2,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(seq: 5, type: 'tool_batch_committed', payload: []),
            ],
            effectsCount: 0,
        ));

        $this->assertSame([], $api->getDispatchedExtensionAgentJobs());
    }
}
