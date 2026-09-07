<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

final readonly class JbcontextPaths
{
    public function __construct(
        public string $projectRoot,
        public string $sessionsRoot,
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
            sessionsRoot: $root.'/.hatfield/extensions-data/jbcontext/sessions',
            ideaDir: $root.'/.idea',
            skillDestinationDir: $root.'/.hatfield/skills/jbcontext-semantic-search',
            scoutDestinationPath: $root.'/.hatfield/agents/scout.md',
        );
    }

    public function sessionStatusPath(string $sessionId): string
    {
        $sessionId = trim($sessionId);
        if ('' === $sessionId) {
            throw new \InvalidArgumentException('jbcontext session id must be non-empty.');
        }
        if (str_contains($sessionId, '/') || str_contains($sessionId, '\\') || str_contains($sessionId, '..')) {
            throw new \InvalidArgumentException('jbcontext session id must be a plain identifier.');
        }

        return $this->sessionsRoot.'/'.$sessionId.'/status.json';
    }
}
