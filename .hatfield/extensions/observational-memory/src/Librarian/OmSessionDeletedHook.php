<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Librarian;

use Ineersa\Hatfield\ExtensionApi\DTO\AfterSessionDeletedHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionDeletedHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OmSessionDeletedHook implements AfterSessionDeletedHookInterface
{
    public function __construct(private LibrarianSettingsDTO $settings, private ExecInterface $exec, private LoggerInterface $logger, private ?HttpClientInterface $httpClient = null)
    {
    }

    public function onAfterSessionDeleted(AfterSessionDeletedHookContextDTO $context): void
    {
        if (null !== $context->parentRunId || !(new LibrarianProjectPolicy($this->exec, $this->logger))->allows($context->cwd)) {
            return;
        }
        $root = realpath($context->cwd);
        $client = new LibrarianOmClient($this->settings, false === $root ? Path::canonicalize($context->cwd) : $root, $this->logger, $this->httpClient);
        $client->deleteSession($context->runId);
    }
}
