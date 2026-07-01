<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Transcript\ViewImageTranscriptFormatter;
use PHPUnit\Framework\TestCase;

final class ViewImageTranscriptFormatterTest extends TestCase
{
    public function testFormatsMetadataWithoutRawJsonDump(): void
    {
        $formatter = new ViewImageTranscriptFormatter();
        $lines = $formatter->formatToolResultLines([
            'type' => 'view_image',
            'path' => '/a/b.png',
            'media_type' => 'image/png',
            'width' => 10,
            'height' => 20,
            'bytes' => 99,
            'attachment_refs' => [['type' => 'image_ref']],
        ]);

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('path: /a/b.png', $joined);
        $this->assertStringNotContainsString('attachment_refs', $joined);
        $this->assertStringNotContainsString('image_ref', $joined);
    }
}
