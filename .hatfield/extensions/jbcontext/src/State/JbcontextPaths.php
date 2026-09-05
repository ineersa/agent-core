<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

final readonly class JbcontextPaths
{
    public function __construct(
        public string $projectRoot,
        public string $statusPath,
        public string $ideaDir,
        public string $skillDestinationDir,
        public string $scoutDestinationPath,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $root = rtrim($projectRoot, '/');

        return new self(
            projectRoot: $root,
            statusPath: $root.'/.hatfield/extensions-data/jbcontext/status.json',
            ideaDir: $root.'/.idea',
            skillDestinationDir: $root.'/.hatfield/skills/jbcontext-semantic-search',
            scoutDestinationPath: $root.'/.hatfield/agents/scout.md',
        );
    }
}
