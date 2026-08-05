<?php

declare(strict_types=1);

namespace Ineersa\Platform\Tests\Bridge\Generic;

use Ineersa\Platform\Bridge\Generic\SanitizedGenericModelClient;
use Ineersa\Platform\Diagnostics\PromptCacheRequestDiagnosticsRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;

final class SanitizedGenericModelClientTest extends TestCase
{
    public function testStripsRunIdAndResolverKeysFromDelegatedOptions(): void
    {
        $inner = new RecordingModelClient();
        $client = new SanitizedGenericModelClient($inner);
        $model = new CompletionsModel('test', [Capability::INPUT_TEXT]);

        $client->request($model, ['messages' => []], [
            'run_id' => 'session-stable-uuid',
            'tools_ref' => 'default',
            'turn_no' => 3,
            'stream' => true,
            'temperature' => 0.0,
        ]);

        $this->assertSame([
            'stream' => true,
            'temperature' => 0.0,
        ], $inner->lastOptions);
    }

    public function testPreservesPayloadAndModelOnDelegation(): void
    {
        $inner = new RecordingModelClient();
        $client = new SanitizedGenericModelClient($inner);
        $model = new CompletionsModel('m1', [Capability::INPUT_TEXT]);
        $payload = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        $client->request($model, $payload, ['run_id' => 'volatile-run']);

        $this->assertSame($model, $inner->lastModel);
        $this->assertSame($payload, $inner->lastPayload);
        $this->assertSame([], $inner->lastOptions);
    }

    public function testRecordsHttpDiagnosticsAndStripsRecorderOption(): void
    {
        $inner = new RecordingModelClient();
        $client = new SanitizedGenericModelClient($inner, provider: 'deepseek');
        $model = new CompletionsModel('m1', [Capability::INPUT_TEXT]);
        $recorder = new PromptCacheRequestDiagnosticsRecorder();

        $client->request($model, [
            'messages' => [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'hi'],
            ],
            'tools' => [['type' => 'function', 'function' => ['name' => 'read']]],
        ], [
            'run_id' => 'child-run-1',
            'stream' => true,
            PromptCacheRequestDiagnosticsRecorder::OPTION_KEY => $recorder,
        ]);

        $this->assertSame(['stream' => true], $inner->lastOptions);
        $this->assertArrayNotHasKey(PromptCacheRequestDiagnosticsRecorder::OPTION_KEY, $inner->lastOptions);
        $records = $recorder->records();
        $this->assertCount(1, $records);
        $this->assertSame('http', $records[0]['transport']);
        $this->assertSame('deepseek', $records[0]['provider']);
        $this->assertSame('full_context', $records[0]['mode']);
        $this->assertStringNotContainsString('child-run-1', json_encode($records, \JSON_THROW_ON_ERROR));
    }
}

final class RecordingModelClient implements ModelClientInterface
{
    public ?Model $lastModel = null;

    /** @var array<string, mixed>|string|null */
    public array|string|null $lastPayload = null;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function supports(Model $model): bool
    {
        return $model instanceof CompletionsModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->lastModel = $model;
        $this->lastPayload = $payload;
        $this->lastOptions = $options;

        return new InMemoryRawResult([]);
    }
}
