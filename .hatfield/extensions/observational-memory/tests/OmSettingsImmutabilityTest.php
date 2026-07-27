<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: nested settings parse correctly; version override is immutable; ratio validation fails closed.
 */
final class OmSettingsImmutabilityTest extends TestCase
{
    public function testNestedSettingsAndVersionOverride(): void
    {
        $base = OmSettings::fromArray([
            'enabled' => true,
            'storage' => ['database' => 'custom/om.sqlite'],
            'observer' => [
                'model' => 'provider/observer',
                'thinking_level' => 'low',
                'context_window_ratio' => 0.5,
                'schema_version' => 'obs-old',
                'renderer_version' => 'render-old',
            ],
            'reflector' => [
                'model' => 'provider/reflector',
                'thinking_level' => 'high',
                'context_window_ratio' => 0.6,
                'reflect_after_observation_tokens' => 12_000,
                'schema_version' => 'ref-v9',
            ],
            'pools' => [
                'observations_max_tokens' => 5555,
                'reflections_max_tokens' => 6666,
            ],
            'compaction' => [
                'wait_timeout_seconds' => 44,
            ],
        ]);

        $this->assertSame('custom/om.sqlite', $base->databasePath);
        $this->assertSame('provider/observer', $base->observerModel);
        $this->assertSame(0.5, $base->observerContextWindowRatio);
        $this->assertSame(12_000, $base->reflectAfterObservationTokens);
        $this->assertSame(5000, $base->observerEnvelope(10_000));

        $copy = $base->withRendererAndObserverVersions('render-new', 'obs-new');
        $this->assertSame('render-new', $copy->rendererVersion);
        $this->assertSame('obs-new', $copy->observerSchemaVersion);
        $this->assertSame($base->observerModel, $copy->observerModel);
        $this->assertSame($base->reflectAfterObservationTokens, $copy->reflectAfterObservationTokens);
        $this->assertSame($base->observationsMaxTokens, $copy->observationsMaxTokens);
        $this->assertSame('render-old', $base->rendererVersion);
    }

    public function testInvalidRatioRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OmSettings::fromArray([
            'observer' => [
                'model' => 'provider/observer',
                'context_window_ratio' => 1.0,
            ],
        ]);
    }
}
