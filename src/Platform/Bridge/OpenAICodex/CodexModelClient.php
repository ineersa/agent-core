<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CodexModelClient implements ModelClientInterface
{
    /**
     * Internal option keys that must not be serialized into the Codex JSON body.
     *
     * These keys are injected by Hatfield/Symfony AI infrastructure for routing,
     * metadata, or hook dispatch and are not valid Codex Responses API fields.
     */
    private const array INTERNAL_OPTION_KEYS = [
        '_agent_core_invocation',
        '_hatfield_suppress_developer_role',
        'stream',
        'tools_ref',
        'turn_no',
        'run_id',
    ];

    private readonly EventSourceHttpClient $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $accessToken,
        private readonly string $accountId,
        private readonly string $path = '/codex/responses',
        private readonly string $originator = 'hatfield',
    ) {
        $this->httpClient = $httpClient instanceof EventSourceHttpClient
            ? $httpClient
            : new EventSourceHttpClient($httpClient);
    }

    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        // Transform structured output options before option sanitization.
        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        // Strip Hatfield/Symfony AI internal keys that are not valid Codex API fields.
        $bodyOptions = array_diff_key($options, array_flip(self::INTERNAL_OPTION_KEYS));

        $requestOptions = [
            'auth_bearer' => $this->accessToken,
            'headers' => [
                'Content-Type' => 'application/json',
                'chatgpt-account-id' => $this->accountId,
                'originator' => $this->originator,
                'OpenAI-Beta' => 'responses=experimental',
            ],
            'json' => array_merge($bodyOptions, ['model' => $model->getName()], $payload),
        ];

        return new RawHttpResult($this->httpClient->request('POST', $this->baseUrl.$this->path, $requestOptions));
    }
}
