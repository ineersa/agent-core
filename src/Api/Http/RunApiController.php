<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Http;

use Ineersa\AgentCore\Api\Dto\RunStreamEvent;
use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunAccessStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunAccessScope;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Mercure\RunTopicPolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposes HTTP endpoints for starting runs, submitting commands, and reading run summaries, transcripts, and replay events with scoped access checks and payload validation.
 */
#[AsController]
#[Route('/agent/runs')]
final class RunApiController
{
    public function __construct(
        private AgentRunnerInterface $runner,
        private MessageBusInterface $commandBus,
        private RunStoreInterface $runStore,
        private RunAccessStoreInterface $runAccessStore,
        private RunReadService $runReadService,
        private RunEventSerializer $eventSerializer,
        private RunTopicPolicy $topicPolicy,
    ) {
    }

    /**
     * Creates a new agent run by processing POST request payload and returning the created run ID.
     */
    #[Route('', name: 'agent_loop_api_run_start', methods: ['POST'])]
    public function startRun(Request $request): JsonResponse
    {
        $payload = $this->jsonBody($request);

        $prompt = $this->normalizeString($payload['prompt'] ?? null);
        if (null === $prompt) {
            return $this->error('Field "prompt" must be a non-empty string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $systemPrompt = $this->normalizeString($payload['system_prompt'] ?? null) ?? '';
        $model = $this->normalizeString($payload['model'] ?? null);

        $sessionMetadata = $payload['session'] ?? $payload['session_metadata'] ?? [];
        if (!\is_array($sessionMetadata)) {
            return $this->error('Field "session" must be a JSON object.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $toolsScope = $payload['tools_scope'] ?? null;
        if (null !== $toolsScope && !\is_array($toolsScope)) {
            return $this->error('Field "tools_scope" must be a JSON object when provided.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $requestActor = $this->requestActor($request);
        $tenantId = $this->normalizeString($sessionMetadata['tenant_id'] ?? $requestActor['tenant_id']);
        $userId = $this->normalizeString($sessionMetadata['user_id'] ?? $requestActor['user_id']);

        if (null === $tenantId || null === $userId) {
            return $this->error(
                'Tenant and user scope are required. Provide X-Agent-Tenant-Id/X-Agent-User-Id headers or session.tenant_id/session.user_id.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $metadata = [
            'session' => $sessionMetadata,
        ];

        if (null !== $model) {
            $metadata['model'] = $model;
        }

        if (null !== $toolsScope) {
            $metadata['tools_scope'] = $toolsScope;
        }

        $runHandle = $this->runner->start(new StartRunInput(
            systemPrompt: $systemPrompt,
            messages: [new AgentMessage(
                role: 'user',
                content: [[
                    'type' => 'text',
                    'text' => $prompt,
                ]],
            )],
            metadata: $metadata,
        ));

        $this->runAccessStore->save(new RunAccessScope(
            runId: $runHandle->runId,
            tenantId: $tenantId,
            userId: $userId,
            sessionMetadata: $sessionMetadata,
        ));

        return new JsonResponse([
            'run_id' => $runHandle->runId,
            'status' => 'queued',
            'stream_topic' => $this->topicPolicy->topicFor($runHandle->runId),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Sends a command to an existing run identified by runId from POST request payload.
     */
    #[Route('/{runId}/commands', name: 'agent_loop_api_run_command', methods: ['POST'])]
    public function sendCommand(string $runId, Request $request): JsonResponse
    {
        $authorizationError = $this->authorizeRun($runId, $request);
        if (null !== $authorizationError) {
            return $authorizationError;
        }

        $payload = $this->jsonBody($request);

        $kind = $this->normalizeString($payload['kind'] ?? null);
        if (null === $kind) {
            return $this->error('Field "kind" must be a non-empty string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!CoreCommandKind::isCore($kind) && !str_starts_with($kind, 'ext:')) {
            return $this->error(
                'Field "kind" must be one of core kinds or start with "ext:".',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $idempotencyKey = $this->normalizeString($payload['idempotency_key'] ?? null);
        if (null === $idempotencyKey) {
            return $this->error('Field "idempotency_key" must be a non-empty string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $commandPayload = $payload['payload'] ?? [];
        if (!\is_array($commandPayload)) {
            return $this->error('Field "payload" must be a JSON object.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $options = $payload['options'] ?? [];
        if (!\is_array($options)) {
            return $this->error('Field "options" must be a JSON object.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $unknownOptions = array_values(array_diff(array_keys($options), ['cancel_safe']));
        if ([] !== $unknownOptions) {
            sort($unknownOptions);

            return $this->error(
                \sprintf('Unknown command options: %s.', implode(', ', $unknownOptions)),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (\array_key_exists('cancel_safe', $options) && !\is_bool($options['cancel_safe'])) {
            return $this->error('Option "cancel_safe" must be a boolean.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (CoreCommandKind::isCore($kind) && \array_key_exists('cancel_safe', $options)) {
            return $this->error(
                'Option "cancel_safe" is reserved for extension commands.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $state = $this->runStore->get($runId);
        $stepId = \sprintf('api-cmd-%s', substr(hash('sha256', $idempotencyKey), 0, 16));

        try {
            $this->commandBus->dispatch(new ApplyCommand(
                runId: $runId,
                turnNo: $state->turnNo ?? 0,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: $idempotencyKey,
                kind: $kind,
                payload: $commandPayload,
                options: $options,
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch API command message.', previous: $exception);
        }

        $this->runAccessStore->touch($runId);

        return new JsonResponse([
            'run_id' => $runId,
            'kind' => $kind,
            'accepted' => true,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Retrieves summary details for a run identified by runId via GET request.
     */
    #[Route('/{runId}', name: 'agent_loop_api_run_summary', methods: ['GET'])]
    public function runSummary(string $runId, Request $request): JsonResponse
    {
        $authorizationError = $this->authorizeRun($runId, $request);
        if (null !== $authorizationError) {
            return $authorizationError;
        }

        $summary = $this->runReadService->summary($runId);
        if (null === $summary) {
            return $this->error('Run not found.', Response::HTTP_NOT_FOUND);
        }

        $summary['stream_topic'] = $this->topicPolicy->topicFor($runId);

        return new JsonResponse($summary);
    }

    /**
     * Returns a paginated transcript of messages for a run identified by runId via GET request.
     */
    #[Route('/{runId}/messages', name: 'agent_loop_api_run_messages', methods: ['GET'])]
    public function transcriptPage(string $runId, Request $request): JsonResponse
    {
        $authorizationError = $this->authorizeRun($runId, $request);
        if (null !== $authorizationError) {
            return $authorizationError;
        }

        $cursor = $this->parseNonNegativeInt($request->query->get('cursor', '0'), 'cursor');
        $limit = $this->parseNonNegativeInt($request->query->get('limit', '50'), 'limit');

        $page = $this->runReadService->transcriptPage($runId, $cursor, max(1, $limit));
        if (null === $page) {
            return $this->error('Run not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($page);
    }

    /**
     * Streams events for a run identified by runId via GET request, supporting cursor-based pagination.
     */
    #[Route('/{runId}/events', name: 'agent_loop_api_run_events', methods: ['GET'])]
    public function replayEvents(string $runId, Request $request): JsonResponse
    {
        $authorizationError = $this->authorizeRun($runId, $request);
        if (null !== $authorizationError) {
            return $authorizationError;
        }

        $lastEventIdInput = $request->headers->get('Last-Event-ID', $request->query->get('last_event_id', '0'));
        $lastEventId = $this->parseNonNegativeInt($lastEventIdInput, 'Last-Event-ID');

        $replay = $this->runReadService->replayAfter($runId, $lastEventId);
        if (null === $replay) {
            return $this->error('Run not found.', Response::HTTP_NOT_FOUND);
        }

        $events = array_map(
            fn ($event): array => $this->eventSerializer->normalizeRunEvent($event),
            $replay['events'],
        );

        if ($replay['resync_required']) {
            $events = [$this->eventSerializer->normalizeStreamEvent(new RunStreamEvent(
                runId: $runId,
                seq: $lastEventId + 1,
                turnNo: 0,
                type: 'resync_required',
                payload: [
                    'reason' => 'event_gap_detected',
                    'missing_sequences' => $replay['missing_sequences'],
                    'reload_endpoint' => \sprintf('/agent/runs/%s/messages', $runId),
                ],
            ))];
        }

        return new JsonResponse([
            'run_id' => $runId,
            'from_seq' => $lastEventId,
            'source' => $replay['source'],
            'resync_required' => $replay['resync_required'],
            'reload_endpoint' => \sprintf('/agent/runs/%s/messages', $runId),
            'events' => $events,
        ]);
    }

    private function authorizeRun(string $runId, Request $request): ?JsonResponse
    {
        $scope = $this->runAccessStore->get($runId);
        if (null === $scope) {
            return $this->error('Run not found.', Response::HTTP_NOT_FOUND);
        }

        $actor = $this->requestActor($request);
        if ($scope->tenantId !== $actor['tenant_id'] || $scope->userId !== $actor['user_id']) {
            return $this->error('Forbidden run access.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * Extracts actor identity and context from the HTTP request headers or body.
     *
     * @return array{tenant_id: ?string, user_id: ?string}
     */
    private function requestActor(Request $request): array
    {
        return [
            'tenant_id' => $this->normalizeString($request->headers->get('X-Agent-Tenant-Id')),
            'user_id' => $this->normalizeString($request->headers->get('X-Agent-User-Id')),
        ];
    }

    /**
     * Parses and returns the JSON body from the HTTP request as an associative array.
     *
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $rawContent = trim($request->getContent());
        if ('' === $rawContent) {
            return [];
        }

        try {
            $decoded = json_decode($rawContent, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON request body.', previous: $exception);
        }

        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('JSON request body must be an object.');
        }

        return $decoded;
    }

    private function parseNonNegativeInt(mixed $value, string $fieldName): int
    {
        if (\is_int($value)) {
            if ($value >= 0) {
                return $value;
            }

            throw new BadRequestHttpException(\sprintf('Field "%s" must be >= 0.', $fieldName));
        }

        if (!\is_string($value) || '' === trim($value) || !ctype_digit($value)) {
            throw new BadRequestHttpException(\sprintf('Field "%s" must be a non-negative integer.', $fieldName));
        }

        return (int) $value;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
