<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\ExtensionApi\Compaction;

use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: public compaction metadata rejects non-JSON-encodable values
 * (objects already rejected; non-finite floats must also fail closed).
 */
final class BeforeCompactionHookResultDTOTest extends TestCase
{
    public function testRejectsNonFiniteFloatMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finite float');

        new BeforeCompactionHookResultDTO(metadata: ['score' => \INF]);
    }

    public function testAcceptsFiniteFloatMetadata(): void
    {
        $dto = new BeforeCompactionHookResultDTO(metadata: ['score' => 1.25, 'nested' => ['ratio' => 0.0]]);
        $this->assertSame(1.25, $dto->metadata['score']);
        $this->assertSame(0.0, $dto->metadata['nested']['ratio']);
    }
}
