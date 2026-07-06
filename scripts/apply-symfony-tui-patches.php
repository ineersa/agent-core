#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$target = $root.'/vendor/symfony/tui/Render/ScreenWriter.php';

if (!is_file($target)) {
    exit(0);
}

$contents = file_get_contents($target);
if (str_contains($contents, 'CUP: absolute row/col')) {
    exit(0);
}

$old = <<<'BLOCK'
        $rowDelta = $targetRow - $this->hardwareCursorRow;
        $buffer = '';

        if ($rowDelta > 0) {
            $buffer .= "\x1b[{$rowDelta}B";
        } elseif ($rowDelta < 0) {
            $buffer .= "\x1b[".(-$rowDelta).'A';
        }

        // Move to absolute column (1-indexed)
        $buffer .= "\x1b[".($targetCol + 1).'G';
BLOCK;

$new = <<<'BLOCK'
        // CUP: absolute row/col (1-indexed). Relative CUD/CUU can mis-position
        // the hardware cursor when prior frames scrolled the viewport.
        $buffer = "\x1b[".($targetRow + 1).';'.($targetCol + 1).'H';
BLOCK;

if (!str_contains($contents, $old)) {
    fwrite(STDERR, "ScreenWriter cursor block not found; manual patch required\n");
    exit(1);
}

file_put_contents($target, str_replace($old, $new, $contents));
echo "Applied symfony-tui ScreenWriter cursor patch\n";
