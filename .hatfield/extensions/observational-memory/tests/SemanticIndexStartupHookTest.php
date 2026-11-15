<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexStartupHook;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SemanticIndexStartupHookTest extends IsolatedKernelTestCase
{
    #[Test]
    public function realStartupCleanupStillAllowsMainBackfillAndExecutionBeforeProjectionRecovery(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $runId = $sessions->createSession();
        $forkId = $sessions->createSession();
        $sessions->updateMetadata($forkId, ['parent_id' => $runId]);
        $projection = $container->get(RunOperationalProjectionRepository::class);
        $projection->replace(RunState::queued($runId));
        $this->assertNotNull($projection->findRelationship($runId));

        $directory = TestDirectoryIsolation::createProjectTempDir('om-startup-backfill');
        $path = $directory.'/om.sqlite';
        $settings = [
            'storage' => ['database' => $path],
            'semantic' => ['embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed']],
        ];
        $config = $container->get(AppConfig::class);
        $originalExtensions = $config->extensions;
        $config->extensions = new ExtensionsConfig(settings: [OmSettings::SETTINGS_KEY => $settings]);
        $connection = $container->get('test.om_database_factory')->connectAndMigrate($path);
        try {
            $connection->insert('om_observation', [
                'observation_id' => str_repeat('a', 64), 'run_id' => $runId, 'boundary_key' => 'boundary',
                'source_start_seq' => 1, 'source_end_seq' => 1, 'source_refs_json' => '[]',
                'content' => 'Retained memory needing startup reconciliation', 'content_hash' => 'hash', 'relevance' => 'high',
                'timestamp' => '2026-09-12 12:00', 'token_count' => 10, 'observer_model' => 'test/model',
                'observer_schema_version' => '1', 'created_at' => '2026-09-12T12:00:00+00:00',
            ]);
            $api = $container->get(ExtensionApiInterface::class);
            $api->registerSessionStartHook(new SemanticIndexStartupHook($api, OmSettings::fromArray($settings)));
            /** @var InMemoryTransport $transport */
            $transport = $container->get('messenger.transport.extension_agent');
            $transport->reset();

            // Use the kernel's actual listener priorities: cleanup (256), then
            // extension startup (0). No workers, sleeps, or user turn are needed.
            $events = $container->get('event_dispatcher');
            $events->dispatch(new ControllerSessionStartingEvent($runId));
            $this->assertNull($projection->findRelationship($runId));
            $this->assertCount(1, $transport->getSent());
            $message = $transport->getSent()[0]->getMessage();
            $this->assertInstanceOf(ExtensionAgentJobMessage::class, $message);
            $this->assertSame(SemanticIndexJobHandler::HANDLER_ID, $message->handlerId);
            $this->assertSame(['run_id' => $runId], $message->payload);

            // The queued job can win the race with run_control recovery, so
            // execute it while the projection is still absent as well.
            $http = new MockHttpClient(static function (): MockResponse {
                return new MockResponse('{"data":[{"index":0,"embedding":[1.0,0.5]}]}');
            });
            (new SemanticIndexJobHandler(new TestLogger(), $http))->handle($api, $message->payload, $message->jobId, $message->correlationId);
            $this->assertGreaterThan(0, $http->getRequestsCount());
            $this->assertSame(1, (int) $connection->fetchOne('SELECT complete FROM om_semantic_state'));
            $this->assertNull($projection->findRelationship($runId));

            // Persisted fork identity is still a child; absence from both
            // authoritative identity sources must never imply a main run.
            $this->assertTrue($api->isChildRun($forkId));
            foreach ([$forkId, 'unknown-startup-run', $runId.'d451-native-child', str_repeat('9', 30)] as $excludedId) {
                $transport->reset();
                $events->dispatch(new ControllerSessionStartingEvent($excludedId));
                $this->assertCount(0, $transport->getSent());
            }
        } finally {
            $config->extensions = $originalExtensions;
            $connection->close();
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    #[Test]
    public function explicitlySelectedChildrenNeverScheduleBackfill(): void
    {
        $settings = OmSettings::fromArray(['semantic' => ['embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed']]]);
        foreach (['subagent-run', 'fork-run'] as $runId) {
            $api = $this->createMock(ExtensionApiInterface::class);
            $api->expects($this->once())->method('isChildRun')->with($runId)->willReturn(true);
            $api->expects($this->never())->method('dispatchExtensionAgentJob');
            $api->expects($this->never())->method('getCwd');
            (new SemanticIndexStartupHook($api, $settings))->onAfterSessionStart(new AfterSessionStartHookContextDTO($runId));
        }
    }

    #[Test]
    public function startupSchedulesBackfillWithoutEmbeddingInTheController(): void
    {
        $settings = OmSettings::fromArray(['semantic' => ['embedding_api' => ['base_url' => 'http://embed.test/v1', 'model_id' => 'embed']]]);
        $api = $this->createMock(ExtensionApiInterface::class);
        $api->expects($this->once())->method('dispatchExtensionAgentJob')->with($this->callback(static function (ExtensionAgentJobRequestDTO $job): bool {
            self::assertSame(SemanticIndexJobHandler::HANDLER_ID, $job->handlerId);
            self::assertSame(['run_id' => '32'], $job->payload);
            self::assertSame('32', $job->correlationId);

            return true;
        }));
        (new SemanticIndexStartupHook($api, $settings))->onAfterSessionStart(new AfterSessionStartHookContextDTO('32'));
    }
}
