<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Notification;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Single owner for ModelNotificationDTO boundary translation.
 *
 * Decodes the generic `model_notifications` array carried by tool-result
 * details or AgentMessage details into DTOs, and encodes DTOs once at the
 * canonical RunEvent boundary via Serializer normalize with
 * SKIP_NULL_VALUES. Notification order is preserved in both directions.
 */
final class ModelNotificationCodec
{
    /**
     * Decode `model_notifications` from a details boundary array.
     *
     * Accepts any value; non-array details, missing keys, and empty lists
     * yield no notifications.
     *
     * @return list<ModelNotificationDTO>
     */
    public static function denormalizeFromDetails(DenormalizerInterface $denormalizer, mixed $details): array
    {
        $raw = \is_array($details) ? ($details['model_notifications'] ?? null) : null;
        if (!\is_array($raw) || [] === $raw) {
            return [];
        }

        /** @var list<ModelNotificationDTO> $notifications */
        $notifications = $denormalizer->denormalize($raw, ModelNotificationDTO::class.'[]');

        return $notifications;
    }

    /**
     * Collect model_notification RunEvent specs from typed notifications.
     *
     * @param list<ModelNotificationDTO> $notifications
     *
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public static function toEventSpecs(NormalizerInterface $normalizer, array $notifications): array
    {
        if ([] === $notifications) {
            return [];
        }

        $specs = [];
        foreach ($notifications as $notif) {
            /** @var array<string, mixed> $payload */
            $payload = $normalizer->normalize($notif, null, [
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            ]);
            $specs[] = [
                'type' => RunEventTypeEnum::ModelNotification->value,
                'payload' => $payload,
            ];
        }

        return $specs;
    }
}
