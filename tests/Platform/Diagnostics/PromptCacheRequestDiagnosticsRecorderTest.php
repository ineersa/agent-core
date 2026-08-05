<?php

declare(strict_types=1);

namespace Ineersa\Platform\Tests\Diagnostics;

use Ineersa\Platform\Diagnostics\PromptCacheRequestDiagnosticsRecorder;
use PHPUnit\Framework\TestCase;

final class PromptCacheRequestDiagnosticsRecorderTest extends TestCase
{
    public function testStablePrefixThenToolMutationReportsFirstDifferenceAndOmitsRawSentinels(): void
    {
        $recorder = new PromptCacheRequestDiagnosticsRecorder();
        $key = '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee';

        $base = [
            'instructions' => 'system prologue with secret-token-SHOULD-NOT-PERSIST',
            'tools' => [
                ['type' => 'function', 'name' => 'read', 'parameters' => ['type' => 'object']],
                ['type' => 'function', 'name' => 'bash', 'parameters' => ['type' => 'object']],
            ],
            'input' => [
                ['role' => 'user', 'content' => 'hello Authorization: Bearer sk-secret'],
            ],
            'prompt_cache_key' => $key,
        ];

        $recorder->record($base, 'openai-codex', 'websocket-cached', $key, [
            'mode' => 'full_context',
            'model' => 'gpt-5.6',
            'prompt_cache_key_present' => true,
            'previous_response_id_present' => false,
            'wire_input_count' => 1,
        ]);

        $mutated = $base;
        $mutated['tools'][1] = ['type' => 'function', 'name' => 'bash', 'parameters' => ['type' => 'object', 'required' => ['command']]];
        $mutated['input'][] = ['role' => 'user', 'content' => 'second turn with raw tool output dump'];
        $recorder->record($mutated, 'openai-codex', 'websocket-cached', $key, [
            'mode' => 'continuation_delta',
            'model' => 'gpt-5.6',
            'prompt_cache_key_present' => true,
            'previous_response_id_present' => true,
            'wire_input_count' => 1,
        ]);

        $records = $recorder->records();
        $this->assertCount(2, $records);
        $this->assertSame('full_context', $records[0]['mode']);
        $this->assertSame('continuation_delta', $records[1]['mode']);
        $this->assertTrue($records[1]['previous_response_id_present']);

        $compare = PromptCacheRequestDiagnosticsRecorder::compareComponents(
            $records[0]['components'],
            $records[1]['components'],
        );
        $this->assertGreaterThan(0, $compare['common_prefix_len']);
        $this->assertNotNull($compare['first_diff']);
        $this->assertSame('changed', $compare['first_diff']['kind']);
        $this->assertSame('tools', $compare['first_diff']['previous']['section'] ?? null);
        $this->assertSame('bash', $compare['first_diff']['previous']['name'] ?? null);
        $this->assertNotSame(
            $compare['first_diff']['previous']['bytes'] ?? null,
            $compare['first_diff']['current']['bytes'] ?? null,
        );

        $serialized = json_encode($records, \JSON_THROW_ON_ERROR);
        foreach ([
            'secret-token-SHOULD-NOT-PERSIST',
            'Authorization: Bearer sk-secret',
            'raw tool output dump',
            $key,
            'Bearer',
        ] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serialized);
        }
        $this->assertStringContainsString('"hmac"', $serialized);
        $this->assertStringContainsString('"bytes"', $serialized);
        $this->assertStringNotContainsString('"content":', $serialized);
    }

    public function testGenericMessagesLeadingSystemCountsAsInstructions(): void
    {
        $recorder = new PromptCacheRequestDiagnosticsRecorder();
        $recorder->record([
            'messages' => [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'hi'],
            ],
            'tools' => [
                ['type' => 'function', 'function' => ['name' => 'read']],
            ],
        ], 'deepseek', 'http', 'child-run-id');

        $components = $recorder->records()[0]['components'];
        // Component order follows body field order: messages (instructions+input), then tools.
        $this->assertSame('instructions', $components[0]['section']);
        $this->assertSame('messages', $components[1]['section']);
        $this->assertSame('user', $components[1]['role']);
        $this->assertSame('tools', $components[2]['section']);
        $this->assertSame('read', $components[2]['name']);
    }
}
