<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: withRendererAndObserverVersions only swaps those two fields and
 * retains every other setting exactly (guards against partial reconstruction bugs).
 */
final class OmSettingsImmutabilityTest extends TestCase
{
    public function testWithRendererAndObserverVersionsRetainsAllOtherFields(): void
    {
        $base = OmSettings::fromArray([
            'enabled' => true,
            'database_path' => 'custom/om.sqlite',
            'observer_model' => 'provider/observer',
            'reflector_model' => 'provider/reflector',
            'renderer_version' => 'render-old',
            'observer_schema_version' => 'obs-old',
            'reflector_schema_version' => 'ref-v9',
            'max_observations' => 7,
            'observer_input_budget_tokens' => 1111,
            'tool_result_max_chars' => 2222,
            'content_max_chars' => 333,
            'wait_timeout_seconds' => 44,
            'observations_max_tokens' => 5555,
            'reflections_max_tokens' => 6666,
            'reflector_input_budget_tokens' => 7777,
            'max_reflections' => 3,
            'reflection_content_max_chars' => 888,
            'replacement_max_chars' => 9999,
        ]);

        $copy = $base->withRendererAndObserverVersions('render-new', 'obs-new');

        $this->assertSame('render-new', $copy->rendererVersion);
        $this->assertSame('obs-new', $copy->observerSchemaVersion);
        $this->assertSame($base->enabled, $copy->enabled);
        $this->assertSame($base->databasePath, $copy->databasePath);
        $this->assertSame($base->observerModel, $copy->observerModel);
        $this->assertSame($base->reflectorModel, $copy->reflectorModel);
        $this->assertSame($base->reflectorSchemaVersion, $copy->reflectorSchemaVersion);
        $this->assertSame($base->maxObservations, $copy->maxObservations);
        $this->assertSame($base->observerInputBudgetTokens, $copy->observerInputBudgetTokens);
        $this->assertSame($base->toolResultMaxChars, $copy->toolResultMaxChars);
        $this->assertSame($base->contentMaxChars, $copy->contentMaxChars);
        $this->assertSame($base->waitTimeoutSeconds, $copy->waitTimeoutSeconds);
        $this->assertSame($base->observationsMaxTokens, $copy->observationsMaxTokens);
        $this->assertSame($base->reflectionsMaxTokens, $copy->reflectionsMaxTokens);
        $this->assertSame($base->reflectorInputBudgetTokens, $copy->reflectorInputBudgetTokens);
        $this->assertSame($base->maxReflections, $copy->maxReflections);
        $this->assertSame($base->reflectionContentMaxChars, $copy->reflectionContentMaxChars);
        $this->assertSame($base->replacementMaxChars, $copy->replacementMaxChars);
        // Original remains unchanged (immutable).
        $this->assertSame('render-old', $base->rendererVersion);
        $this->assertSame('obs-old', $base->observerSchemaVersion);
    }
}
