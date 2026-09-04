<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Transcript\TranscriptToolCollapsedPresentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranscriptToolCollapsedPresentation::class)]
final class TranscriptToolCollapsedPresentationTest extends TestCase
{
    #[Test]
    public function collapsedScalarArgumentsTruncateOnUtf8CharactersNotBytes(): void
    {
        $presentation = new TranscriptToolCollapsedPresentation();
        $emoji = '🙂';
        $value = str_repeat($emoji, TranscriptToolCollapsedPresentation::SCALAR_ARGUMENT_MAX_CHARS + 5);

        $collapsed = $presentation->collapsedArguments('move_task', ['label' => $value]);

        $this->assertArrayHasKey('label', $collapsed);
        $this->assertIsString($collapsed['label']);
        $this->assertStringEndsWith('...', $collapsed['label']);

        $truncated = substr($collapsed['label'], 0, -3);
        $this->assertSame(TranscriptToolCollapsedPresentation::SCALAR_ARGUMENT_TRUNCATE_CHARS, mb_strlen($truncated));
        $this->assertSame(
            str_repeat($emoji, TranscriptToolCollapsedPresentation::SCALAR_ARGUMENT_TRUNCATE_CHARS),
            $truncated,
        );
        $this->assertTrue(mb_check_encoding($collapsed['label'], 'UTF-8'));
    }
}
