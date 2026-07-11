<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\Tui\ImagePaste\ClipboardImageReaderInterface;
use Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO;

final class FakeClipboardImageReader implements ClipboardImageReaderInterface
{
    public function __construct(
        private ?ClipboardImageReadResultDTO $result = null,
    ) {
    }

    public function readImageToTempFile(): ClipboardImageReadResultDTO
    {
        return $this->result ?? ClipboardImageReadResultDTO::unavailable('test unavailable');
    }
}
