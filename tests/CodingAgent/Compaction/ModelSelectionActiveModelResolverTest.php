<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\CodingAgent\Compaction\ModelSelectionActiveModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Config-surface resolver smoke: session/default only.
 * Execution identity is covered by RunState/ExecuteLlmStep regressions.
 */
final class ModelSelectionActiveModelResolverTest extends IsolatedKernelTestCase
{
    public function testResolveActiveModelUsesSessionSelectionService(): void
    {
        $service = self::getContainer()->get(ModelSelectionService::class);
        \assert($service instanceof ModelSelectionService);

        $resolver = new ModelSelectionActiveModelResolver($service);
        $model = $resolver->resolveActiveModel('');
        $this->assertNotNull($model);
        $this->assertNotSame('', trim((string) $model));
    }
}
