<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranscriptBlockRendererTest extends TestCase
{
    #[DataProvider('questionStatuses')]
    public function testQuestionCardShowsItsStatus(string $status, string $header): void
    {
        $harness = new VirtualTuiHarness();
        $harness->screen()->setWorkingVisible(false);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'question',
                kind: TranscriptBlockKindEnum::Question,
                runId: 'virtual-startup-session',
                seq: 1,
                text: 'Continue deployment? ('.$status.')',
                meta: ['prompt' => 'Continue deployment?', 'status' => $status],
            ),
        ]);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString($header, $text);
        $this->assertStringContainsString('Continue deployment?', $text);
        if ('pending' === $status) {
            $this->assertStringContainsString('awaiting answer', $text);
        } else {
            $this->assertStringNotContainsString('Human input required', $text);
            $this->assertStringNotContainsString('awaiting answer', $text);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function questionStatuses(): iterable
    {
        yield 'cancelled' => ['cancelled', 'Human input cancelled'];
        yield 'pending' => ['pending', 'Human input required'];
        yield 'answered' => ['answered', 'Human input answered'];
        yield 'rejected' => ['rejected', 'Human input rejected'];
    }
}
