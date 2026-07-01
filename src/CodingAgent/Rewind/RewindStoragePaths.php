<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\CodingAgent\Config\AppConfig;

final readonly class RewindStoragePaths
{
    public function __construct(
        private AppConfig $appConfig,
    ) {
    }

    public function hiddenGitDir(RewindProjectIdentity $identity): string
    {
        $base = rtrim($this->appConfig->cwd, '/').'/.hatfield/rewind/snapshots/'.$identity->projectHash.'/git';

        return str_replace('\\', '/', $base);
    }

    public function tmpDir(RewindProjectIdentity $identity): string
    {
        $dir = rtrim($this->appConfig->cwd, '/').'/.hatfield/rewind/snapshots/'.$identity->projectHash.'/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return str_replace('\\', '/', $dir);
    }
}
