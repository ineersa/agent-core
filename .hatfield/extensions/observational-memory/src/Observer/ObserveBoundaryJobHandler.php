<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianOmClient;
use Ineersa\HatfieldExt\ObservationalMemory\Librarian\LibrarianProjectPolicy;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Worker-local, best-effort content-free signal. No extension-owned retry state. */
final readonly class ObserveBoundaryJobHandler implements ExtensionAgentJobHandlerInterface
{
    public function __construct(private LoggerInterface $logger, private ?HttpClientInterface $httpClient = null)
    {
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $constraints = new Assert\Collection(fields: [
            'run_id' => [new Assert\Type('string'), new Assert\NotBlank()],
            'terminal_end_seq' => [new Assert\Type('integer'), new Assert\NotNull(), new Assert\Positive()],
            'terminal_status' => [new Assert\Choice(choices: ['completed', 'cancelled', 'failed']), new Assert\NotNull()],
        ]);
        if (0 !== \count(Validation::createValidator()->validate($payload, $constraints))) {
            $this->logger->warning('om.observe.invalid_job', [
                'component' => 'observational_memory', 'event_type' => 'om.observe.invalid_job',
                'run_id' => null, 'session_id' => null, 'job_id' => $jobId,
            ]);

            return;
        }
        $runId = $payload['run_id'];
        // Recheck at execution: explicitly queued children and worktrees must also be inert.
        if ($api->isChildRun($runId) || !(new LibrarianProjectPolicy($api->exec(), $this->logger))->allows($api->getCwd())) {
            return;
        }
        $settings = OmSettings::fromApi($api);
        $resolved = realpath($api->getCwd());
        $root = Path::canonicalize(false === $resolved ? $api->getCwd() : $resolved);
        (new LibrarianOmClient($settings->librarian, $root, $this->logger, $this->httpClient))->observe(
            $runId, $payload['terminal_end_seq'], $payload['terminal_status'],
        );
    }
}
