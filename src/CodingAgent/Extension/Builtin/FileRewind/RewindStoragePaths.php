<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

final readonly class RewindStoragePaths
{
    public function __construct(
        private string $projectCwd,
    ) {
    }

    public function hiddenGitDir(RewindProjectIdentity $identity): string
    {
        $base = rtrim($this->projectCwd, '/').'/.hatfield/rewind/snapshots/'.$identity->projectHash.'/git';

        return str_replace('\\', '/', $base);
    }

    public function tmpDir(RewindProjectIdentity $identity): string
    {
        $dir = rtrim($this->projectCwd, '/').'/.hatfield/rewind/snapshots/'.$identity->projectHash.'/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return str_replace('\\', '/', $dir);
    }
}
