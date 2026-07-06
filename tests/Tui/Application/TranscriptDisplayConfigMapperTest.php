<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\CodingAgent\Config\TuiTranscriptConfig;
use Ineersa\CodingAgent\Config\TuiTranscriptPreviewsConfig;
use Ineersa\CodingAgent\Config\TuiTranscriptThinkingConfig;
use Ineersa\Tui\Application\TranscriptDisplayConfigMapper;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;

/**
 * Tests that TranscriptDisplayConfigMapper correctly maps from Hatfield
 * config DTOs to TUI-local display config, and that TranscriptDisplayState
 * initializes correctly from the mapped config.
 *
 * @see TranscriptDisplayConfigMapper
 * @see TranscriptDisplayState
 */
class TranscriptDisplayConfigMapperTest extends TestCase
{
    private TranscriptDisplayConfigMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new TranscriptDisplayConfigMapper();
    }

    public function testMapWithDefaults(): void
    {
        $hatfieldConfig = new TuiTranscriptConfig();

        $displayConfig = $this->mapper->map($hatfieldConfig);

        $this->assertTrue($displayConfig->thinkingVisible);
        $this->assertSame('dim_italic', $displayConfig->thinkingStyle);
        $this->assertFalse($displayConfig->previewsExpandedByDefault);
        $this->assertSame(8, $displayConfig->toolResultPreviewLines);
        $this->assertSame(20, $displayConfig->diffPreviewLines);
    }

    public function testMapWithOverrides(): void
    {
        $hatfieldConfig = new TuiTranscriptConfig(
            thinking: new TuiTranscriptThinkingConfig(
                visible: false,
                style: 'dim',
            ),
            previews: new TuiTranscriptPreviewsConfig(
                expandedByDefault: true,
                toolResultLines: 12,
                diffLines: 30,
            ),
        );

        $displayConfig = $this->mapper->map($hatfieldConfig);

        $this->assertFalse($displayConfig->thinkingVisible);
        $this->assertSame('dim', $displayConfig->thinkingStyle);
        $this->assertTrue($displayConfig->previewsExpandedByDefault);
        $this->assertSame(12, $displayConfig->toolResultPreviewLines);
        $this->assertSame(30, $displayConfig->diffPreviewLines);
    }

    public function testDisplayStateInitializesFromExpandedByDefault(): void
    {
        // When previewsExpandedByDefault is false, state starts collapsed
        $configFalse = $this->mapper->map(new TuiTranscriptConfig(
            previews: new TuiTranscriptPreviewsConfig(expandedByDefault: false),
        ));
        $stateFalse = new TranscriptDisplayState(
            previewableBlocksExpanded: $configFalse->previewsExpandedByDefault,
        );
        $this->assertFalse($stateFalse->previewableBlocksExpanded);

        // When previewsExpandedByDefault is true, state starts expanded
        $configTrue = $this->mapper->map(new TuiTranscriptConfig(
            previews: new TuiTranscriptPreviewsConfig(expandedByDefault: true),
        ));
        $stateTrue = new TranscriptDisplayState(
            previewableBlocksExpanded: $configTrue->previewsExpandedByDefault,
        );
        $this->assertTrue($stateTrue->previewableBlocksExpanded);
    }

    public function testDisplayStateIsMutable(): void
    {
        // Ensure TranscriptDisplayState is actually mutable (not readonly)
        $state = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $this->assertFalse($state->previewableBlocksExpanded);

        $state->previewableBlocksExpanded = true;
        $this->assertTrue($state->previewableBlocksExpanded);
    }
}
