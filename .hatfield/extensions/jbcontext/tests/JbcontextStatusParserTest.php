<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextStatusParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextStatusParserTest extends TestCase
{
    #[Test]
    public function emptyIndicesAreIneligible(): void
    {
        $this->assertFalse(JbcontextStatusParser::hasExistingSnapshot([
            'type' => 'status_result',
            'indices' => [],
            'message' => 'No indices found',
        ]));
    }

    #[Test]
    public function snapshotPresenceIsRequired(): void
    {
        $this->assertFalse(JbcontextStatusParser::hasExistingSnapshot([
            'type' => 'status_result',
            'indices' => [
                ['indexAlias' => ['name' => 'CodeBlocks'], 'snapshots' => []],
            ],
        ]));

        $this->assertTrue(JbcontextStatusParser::hasExistingSnapshot([
            'type' => 'status_result',
            'indices' => [
                [
                    'indexAlias' => ['name' => 'CodeBlocks'],
                    'snapshots' => [
                        ['revision' => 'abc', 'branches' => ['main']],
                    ],
                ],
            ],
        ]));
    }
}
