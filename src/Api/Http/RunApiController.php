<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Http;

use Ineersa\AgentCore\Api\Dto\ReplayEventsQueryRequest;
use Ineersa\AgentCore\Api\Dto\RunCommandRequest;
use Ineersa\AgentCore\Api\Dto\RunStreamEvent;
use Ineersa\AgentCore\Api\Dto\StartRunRequest;
use Ineersa\AgentCore\Api\Dto\TranscriptPageQueryRequest;
use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Api\AuthorizeRunInterface;
use Ineersa\AgentCore\Contract\RunAccessStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunAccessScope;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Infrastructure\Mercure\RunTopicPolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/agent/runs')]
final readonly class RunApiController
{
    public function __construct(
        private AgentRunnerInterface $runner,
        private MessageBusInterface $commandBus,
        private RunStoreInterface $runStore,
        private RunAccessStoreInterface $runAccessStore,
        private RunReadService $runReadService,
        private RunEventSerializer $eventSerializer,
        private RunTopicPolicy $topicPolicy,
        private AuthorizeRunInterface $authorizeRun,
    ) {
    }

    #[Route('', name: 'agent_loop_api_run_start', methods: ['POST'])]
    public function startRun(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')] StartRunRequest $payload,
    ): JsonResponse {
        $this->authorize($request);
        $accessMetadata = $payload->metadata;

        $runId = $this->runner->start(new StartRunInput(
            systemPrompt: $payload->system_prompt ?? '',
            messages: [new AgentMessage(
                role: 'user',
                content: [[
                    'type' => 'text',
                    'text' => $payload->prompt ?? '',
                ]],
            )],
            metadata: new RunMetadata(
                session: $accessMetadata->session,
                model: $accessMetadata->model,
                toolsScope: $accessMetadata->tools_scope,
            ),
        ));

        $this->runAccessStore->save(new RunAccessScope(
            runId: $runId,
            tenantId: $accessMetadata->tenant_id ?? '',
            userId: $accessMetadata->user_id ?? '',
            sessionMetadata: $accessMetadata->session,
        ));

        return new JsonResponse([
            'run_id' => $runId,
            'status' => 'queued',
            'stream_topic' => $this->topicPolicy->topicFor($runId),
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/{runId}/commands', name: 'agent_loop_api_run_command', methods: ['POST'])]
    public function sendCommand(
        string $runId,
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')] RunCommandRequest $payload,
    ): JsonResponse {
        $this->authorize($request);
        $kind = $payload->kind ?? '';
        $idempotencyKey = $payload->idempotency_key ?? '';

        $state = $this->runStore->get($runId) ?? throw new NotFoundHttpException('Run not found.');

        $stepId = hash('sha256', $idempotencyKey)
                |> (static fn ($x) => substr($x, 0, 16))
                |> (static fn ($x) => \sprintf('api-cmd-%s', $x));

        try {
            $this->commandBus->dispatch(new ApplyCommand(
                runId: $runId,
                turnNo: $state->turnNo,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: $idempotencyKey,
                kind: $kind,
                payload: $payload->payload,
                options: $payload->options,
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

    #[Route('/{runId}', name: 'agent_loop_api_run_summary', methods: ['GET'])]
    public function runSummary(string $runId, Request $request): JsonResponse
    {
        $this->authorize($request);
        $summary = $this->runReadService->summary($runId);
        if (null === $summary) {
            throw new NotFoundHttpException('Run not found.');
        }

        $summary['stream_topic'] = $this->topicPolicy->topicFor($runId);

        return new JsonResponse($summary);
    }

    #[Route('/{runId}/messages', name: 'agent_loop_api_run_messages', methods: ['GET'])]
    public function transcriptPage(
        string $runId,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] TranscriptPageQueryRequest $query,
    ): JsonResponse {
        $this->authorize($request);
        $page = $this->runReadService->transcriptPage($runId, $query->cursor, max(1, $query->limit));
        if (null === $page) {
            throw new NotFoundHttpException('Run not found.');
        }

        return new JsonResponse($page);
    }

    #[Route('/{runId}/events', name: 'agent_loop_api_run_events', methods: ['GET'])]
    public function replayEvents(
        string $runId,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ReplayEventsQueryRequest $query,
    ): JsonResponse {
        $this->authorize($request);
        $lastEventId = $query->last_event_id;

        $replay = $this->runReadService->replayAfter($runId, $lastEventId);
        if (null === $replay) {
            throw new NotFoundHttpException('Run not found.');
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

    private function authorize(Request $request): void
    {
        $route = (string) $request->attributes->get('_route', '');
        $this->authorizeRun->authorize($request, $route);
    }
}
