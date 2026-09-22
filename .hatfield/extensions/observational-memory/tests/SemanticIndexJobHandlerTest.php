<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexJobHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SemanticIndexJobHandlerTest extends IsolatedKernelTestCase
{
    #[Test]
    public function workerEnqueuesContinuationOnlyUntilAllChunksAreDurable(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('om-semantic-job');
        $path = $directory.'/om.sqlite';
        $connection = self::getContainer()->get('test.om_database_factory')->connectAndMigrate($path);
        try {
            $connection->insert('om_observation', [
                'observation_id' => str_repeat('a', 64), 'run_id' => '32', 'boundary_key' => 'boundary',
                'source_start_seq' => 1, 'source_end_seq' => 1, 'source_refs_json' => '[]',
                'content' => str_repeat('document ', 650), 'content_hash' => 'hash', 'relevance' => 'high',
                'timestamp' => '2026-09-12 12:00', 'token_count' => 1000, 'observer_model' => 'test/model',
                'observer_schema_version' => '1', 'created_at' => '2026-09-12T12:00:00+00:00',
            ]);
            $settings = ['storage' => ['database' => $path], 'semantic' => ['embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed']]];
            $queued = [];
            $api = $this->createMock(ExtensionApiInterface::class);
            $api->method('getCwd')->willReturn($directory);
            $api->method('getSettings')->willReturn($settings);
            $api->expects($this->once())->method('dispatchExtensionAgentJob')->willReturnCallback(static function (ExtensionAgentJobRequestDTO $request) use (&$queued): void {
                $queued[] = $request;
            });
            $batchSizes = [];
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$batchSizes): MockResponse {
                $input = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['input'];
                $batchSizes[] = \count($input);

                return new MockResponse(json_encode(['data' => array_map(static fn (int $i): array => ['index' => $i, 'embedding' => [1.0, 0.5]], array_keys($input))], \JSON_THROW_ON_ERROR));
            });
            $handler = new SemanticIndexJobHandler(new TestLogger(), $http);
            $handler->handle($api, ['run_id' => '32'], 'first', '32');
            $this->assertSame(0, (int) $connection->fetchOne('SELECT complete FROM om_semantic_state'));
            $this->assertSame(SemanticIndexJobHandler::HANDLER_ID, $queued[0]->handlerId);
            $this->assertSame(['run_id' => '32'], $queued[0]->payload);
            $handler->handle($api, $queued[0]->payload, $queued[0]->jobId, '32');
            $this->assertSame(1, (int) $connection->fetchOne('SELECT complete FROM om_semantic_state'));
            $this->assertSame([4, 2], $batchSizes);
        } finally {
            $connection->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
