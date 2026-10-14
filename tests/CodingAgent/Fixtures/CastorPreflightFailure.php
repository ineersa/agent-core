<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 3).'/.castor/shared.php';
require_once dirname(__DIR__, 3).'/.castor/qa_tmux.php';

#[AsTask(name: 'preflight-failure')]
function preflight_failure(string $mode = 'exit'): void
{
    $id = \CastorTasks\initialize_qa_check_run();
    $root = \CastorTasks\project_root_dir();
    $os = \CastorTasks\qa_os_temporary_directory($id, $root);
    $filesystem = new Filesystem();
    $filesystem->dumpFile($root.'/var/reports/'.$id.'/preflight.log', 'controlled preflight failure');
    $filesystem->dumpFile($os.'/proof.txt', 'owned OS diagnostic');
    echo 'ALLOCATED '.json_encode([$id, $os], \JSON_THROW_ON_ERROR)."\n";
    fflush(\STDOUT);
    fgets(\STDIN);
    if ('exit' === $mode) {
        fail_quality('controlled preflight failure');
    }

    throw new RuntimeException('controlled preflight failure');
}
