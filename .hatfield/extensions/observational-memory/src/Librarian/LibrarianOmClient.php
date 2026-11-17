<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Librarian;

use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianResponseDTO;
use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Content-free boundary signals and bounded session projections; no client retry state. */
final readonly class LibrarianOmClient
{
    private const int MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private LibrarianSettingsDTO $settings,
        private string $projectRoot,
        private LoggerInterface $logger,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function observe(string $runId, int $endSeq, string $status): void
    {
        $this->request('observe', $runId, $endSeq, [
            'contract_version' => 1,
            'source_kind' => 'hatfield',
            'session_id' => $runId,
            'parent_session_id' => null,
            'end_seq' => $endSeq,
            'terminal_status' => $status,
        ]);
    }

    public function deleteSession(string $runId): void
    {
        $this->request('delete', $runId);
    }

    public function projection(string $runId): ?string
    {
        return $this->request('projection', $runId)?->text;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $operation, string $runId, ?int $endSeq = null, ?array $body = null): ?LibrarianResponseDTO
    {
        $status = null;
        $response = null;
        try {
            $parts = parse_url($this->settings->url);
            $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $path = 'observe' === $operation ? '/observe' : '/sessions/hatfield/'.rawurlencode($runId).('delete' === $operation ? '' : '/projection');
            $headers = ['X-Librarian-Project' => $this->projectRoot, 'Accept' => 'application/json'];
            if (null !== $this->settings->token) {
                $headers['Authorization'] = 'Bearer '.$this->settings->token;
            }
            $options = [
                'headers' => $headers,
                'timeout' => $this->settings->timeoutSeconds,
                'max_duration' => $this->settings->timeoutSeconds,
                'max_redirects' => 0,
                'on_progress' => static function (int $downloaded, int $size): void {
                    if (max($downloaded, $size) > self::MAX_RESPONSE_BYTES) {
                        throw new \LengthException('Librarian response exceeded the wire limit.');
                    }
                },
            ];
            if (null !== $body) {
                $options['json'] = $body;
            }
            $http = $this->httpClient ?? new CurlHttpClient();
            $response = $http->request('delete' === $operation ? 'DELETE' : (null === $body ? 'GET' : 'POST'), $origin.'/api/om/v1'.$path, $options);
            $status = $response->getStatusCode();
            if (('observe' === $operation ? 202 : 200) !== $status) {
                throw new \UnexpectedValueException('Unexpected Librarian response status.');
            }
            // Stream with a second cap: never buffer an unbounded chunked response before validation.
            $json = '';
            foreach ($http->stream($response) as $chunk) {
                $json .= $chunk->getContent();
                if (\strlen($json) > self::MAX_RESPONSE_BYTES) {
                    throw new \LengthException('Librarian response exceeded the wire limit.');
                }
            }
            $serializer = new Serializer([new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor())], [new JsonEncoder()]);
            $result = $serializer->deserialize($json, LibrarianResponseDTO::class, 'json');
            $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
            if (0 !== \count($validator->validate($result, groups: ['Default', $operation]))) {
                throw new \UnexpectedValueException('Invalid Librarian response.');
            }

            return $result;
        } catch (\Throwable $error) {
            // Intentional local degradation: ordinary compaction / a later boundary can proceed.
            $this->logger->warning('om.librarian.'.$operation.'_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.librarian.'.$operation.'_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'end_seq' => $endSeq,
                'http_status' => $status,
                'exception_class' => $error::class,
            ]);

            return null;
        } finally {
            $response?->cancel();
        }
    }
}
