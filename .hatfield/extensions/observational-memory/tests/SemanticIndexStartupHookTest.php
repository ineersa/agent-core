<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexJobHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticIndexStartupHook;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SemanticIndexStartupHookTest extends TestCase
{
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
