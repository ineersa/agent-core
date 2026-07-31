<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: top-level model parse, no thinking/old nested model/reflection max compatibility.
 */
final class OmSettingsImmutabilityTest extends TestCase
{
    public function testTopLevelModelAndVersionOverride(): void
    {
        $base = OmSettings::fromArray([
            'storage' => ['database' => 'custom/om.sqlite'],
            'model' => 'provider/shared',
            'observer' => [
                'context_window_ratio' => 0.5,
                'schema_version' => 'obs-old',
                'renderer_version' => 'render-old',
            ],
            'reflector' => [
                'reflect_after_observation_tokens' => 12_000,
                'schema_version' => 'ref-v9',
            ],
            'pools' => [
                'observations_max_tokens' => 5555,
            ],
        ]);

        $this->assertSame('custom/om.sqlite', $base->databasePath);
        $this->assertSame('provider/shared', $base->model);
        $this->assertSame('provider/shared', $base->requireModel());
        $this->assertSame(0.5, $base->observerContextWindowRatio);
        $this->assertSame(12_000, $base->reflectAfterObservationTokens);
        $this->assertSame(5555, $base->observationsMaxTokens);
        $this->assertSame(5000, $base->observerEnvelope(10_000));
        $this->assertSame(16, OmSettings::DEFAULT_AGENT_MAX_TOOL_CALLS);

        $copy = $base->withRendererAndObserverVersions('render-new', 'obs-new');
        $this->assertSame('render-new', $copy->rendererVersion);
        $this->assertSame('obs-new', $copy->observerSchemaVersion);
        $this->assertSame($base->model, $copy->model);
        $this->assertSame($base->reflectAfterObservationTokens, $copy->reflectAfterObservationTokens);
        $this->assertSame($base->observationsMaxTokens, $copy->observationsMaxTokens);
        $this->assertSame('render-old', $base->rendererVersion);
    }

    public function testNestedModelAndThinkingKeysAreIgnored(): void
    {
        $settings = OmSettings::fromArray([
            'model' => 'provider/shared',
            'observer' => [
                'model' => 'provider/ignored-observer',
                'thinking_level' => 'low',
            ],
            'reflector' => [
                'model' => 'provider/ignored-reflector',
                'thinking_level' => 'high',
            ],
            'pools' => [
                'reflections_max_tokens' => 9999,
            ],
        ]);

        $this->assertSame('provider/shared', $settings->model);
        $this->assertFalse(property_exists($settings, 'observerModel'));
        $this->assertFalse(property_exists($settings, 'reflectorModel'));
        $this->assertFalse(property_exists($settings, 'reflectionsMaxTokens'));
        $this->assertFalse(property_exists($settings, 'observerThinkingLevel'));
    }

    public function testMissingModelFailsClosed(): void
    {
        $settings = OmSettings::fromArray([]);
        $this->expectException(\RuntimeException::class);
        $settings->requireModel();
    }

    public function testInvalidRatioRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OmSettings::fromArray([
            'model' => 'provider/shared',
            'observer' => [
                'context_window_ratio' => 1.0,
            ],
        ]);
    }
}
