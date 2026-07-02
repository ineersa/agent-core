<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\FileRewind;

use Ineersa\CodingAgent\Extension\FileRewind\FileRewindRuntimePorts;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewEntryDTO;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewProviderInterface;
use PHPUnit\Framework\TestCase;

final class FileRewindRuntimePortsTest extends TestCase
{
    public function testPreviewAndCheckpointDelegation(): void
    {
        $preview = new class implements FileRewindPreviewProviderInterface {
            public function previewForTurn(string $sessionId, int $turnNo): array
            {
                return [new FileRewindPreviewEntryDTO('a.txt', 'modified', 1, 2, false, false)];
            }

            public function hasCheckpointForTurn(string $sessionId, int $turnNo): bool
            {
                return 2 === $turnNo;
            }
        };
        $ports = new FileRewindRuntimePorts();
        $ports->bind($preview, new class implements FileRewindActionHandlerInterface {
            public function execute(string $sessionId, int $turnNo, FileRewindActionEnum $action): void {}
        });

        self::assertTrue($ports->hasCheckpoint('s', 2));
        self::assertFalse($ports->hasCheckpoint('s', 1));
        self::assertSame([['path' => 'a.txt', 'status' => 'modified', 'added' => 1, 'removed' => 2]], $ports->preview('s', 2));
    }
}
