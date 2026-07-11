<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

interface ClipboardImageReaderInterface
{
    /**
     * Read image bytes from the OS clipboard when available.
     *
     * Uses fixed argv and Symfony Process — never interpolates clipboard data
     * into shell strings. On SSH/headless hosts the clipboard is the remote
     * machine, not the user's desktop.
     */
    public function readImageToTempFile(): ClipboardImageReadResultDTO;
}
