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
        $validator = Validation::createValidator();
        if (0 !== \count($validator->validate($payload, $constraints))) {
            // The envelope correlation survives malformed/old payloads, but its
            // public contract only requires a nonempty string. Log identifiers,
            // never arbitrary payload text or validation messages containing it.
            $runId = null;
            foreach ([$correlationId, $payload['run_id'] ?? null] as $candidate) {
                if (\is_string($candidate) && 0 === \count($validator->validate($candidate, new Assert\Regex('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/')))) {
                    $runId = $candidate;
                    break;
                }
            }
            $endSeq = $payload['terminal_end_seq'] ?? null;
            $this->logger->warning('om.observe.invalid_job', [
                'component' => 'observational_memory', 'event_type' => 'om.observe.invalid_job',
                'run_id' => $runId, 'session_id' => $runId, 'job_id' => $jobId,
                'end_seq' => \is_int($endSeq) && $endSeq > 0 ? $endSeq : null,
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
