<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\TransientTuiExtensionContextInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSemanticColorEnum;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Builds temporary OM status/view widgets from structured query data.
 *
 * Uses host-created semantic styles + native Symfony TUI widgets only.
 */
final class OmTransientWidgetFactory
{
    /**
     * @param array{
     *   covered_through_seq: ?int,
     *   active_generation_id: ?string,
     *   reflection_count: int,
     *   reflection_tokens: int,
     *   reflections_max_tokens: int,
     *   observation_count: int,
     *   observation_tokens: int,
     *   observations_max_tokens: int,
     *   compaction: array{queued:int,running:int,succeeded:int,failed:int,timed_out:int}
     * } $data
     */
    public static function status(
        TransientTuiExtensionContextInterface $tui,
        array $data,
    ): AbstractWidget {
        $root = new ContainerWidget();
        $root->add(self::markdown($tui, "## Observational memory status\n", TuiSemanticColorEnum::Text));

        $root->add(self::markdown($tui, "### Topology\n", TuiSemanticColorEnum::Text));
        $root->add(self::markdown(
            $tui,
            "- **worker:** Hatfield-managed single FIFO `extension_agent`\n"
            ."- **max_retries:** `1`\n"
            ."- **failure_transport:** `none`\n",
            TuiSemanticColorEnum::Text,
        ));

        $root->add(self::markdown($tui, "### Coverage\n", TuiSemanticColorEnum::Text));
        $covered = null === $data['covered_through_seq']
            ? 'none'
            : (string) $data['covered_through_seq'];
        $root->add(self::markdown(
            $tui,
            '- **covered_through_seq:** `'.$covered."`\n",
            TuiSemanticColorEnum::Text,
        ));
        $root->add(self::text(
            $tui,
            'OM contiguous watermark only; does not claim canonical completeness',
            TuiSemanticColorEnum::Muted,
            dim: true,
            italic: true,
        ));

        $root->add(self::markdown($tui, "### Active generation\n", TuiSemanticColorEnum::Text));
        $generationId = null === $data['active_generation_id']
            ? 'none'
            : (string) $data['active_generation_id'];
        $root->add(self::markdown(
            $tui,
            '- **generation_id:** `'.$generationId."`\n"
            .\sprintf(
                "- **reflections:** %d tokens / limit %d (count %d)\n",
                $data['reflection_tokens'],
                $data['reflections_max_tokens'],
                $data['reflection_count'],
            )
            .\sprintf(
                "- **candidate observations:** %d tokens / limit %d (count %d)\n",
                $data['observation_tokens'],
                $data['observations_max_tokens'],
                $data['observation_count'],
            ),
            TuiSemanticColorEnum::Text,
        ));

        $root->add(self::markdown($tui, "### Compaction requests\n", TuiSemanticColorEnum::Text));
        $root->add(self::markdown(
            $tui,
            \sprintf("- **queued:** %d\n", $data['compaction']['queued'])
            .\sprintf("- **running:** %d\n", $data['compaction']['running'])
            .\sprintf("- **succeeded:** %d\n", $data['compaction']['succeeded'])
            .\sprintf("- **failed:** %d\n", $data['compaction']['failed'])
            .\sprintf("- **timed_out:** %d\n", $data['compaction']['timed_out']),
            TuiSemanticColorEnum::Text,
        ));
        $root->add(self::text(
            $tui,
            'Durable OM SQLite only; no host Messenger liveness/failure ledger',
            TuiSemanticColorEnum::Muted,
            dim: true,
            italic: true,
        ));

        return $root;
    }

    /**
     * @param array{
     *   active_generation_id: ?string,
     *   reflections: list<array{reflection_id:string,content:string,supporting_observation_ids:list<string>}>,
     *   observations: list<array{observation_id:string,timestamp:string,relevance:string,content:string,source_refs:list<array{run_id:string,seq:int}>}>
     * } $data
     */
    public static function view(
        TransientTuiExtensionContextInterface $tui,
        array $data,
    ): AbstractWidget {
        $root = new ContainerWidget();
        $root->add(self::markdown($tui, "## Observational memory view\n", TuiSemanticColorEnum::Text));

        $generationId = null === $data['active_generation_id']
            ? 'none'
            : (string) $data['active_generation_id'];
        $root->add(self::markdown(
            $tui,
            '- **Active generation:** `'.$generationId."`\n",
            TuiSemanticColorEnum::Text,
        ));

        $root->add(self::markdown($tui, "### Reflections\n", TuiSemanticColorEnum::Text));
        if ([] === $data['reflections']) {
            $root->add(self::text($tui, '(none)', TuiSemanticColorEnum::Muted, dim: true, italic: true));
        } else {
            foreach ($data['reflections'] as $reflection) {
                $root->add(self::markdown(
                    $tui,
                    $reflection['content']."\n",
                    TuiSemanticColorEnum::Text,
                ));
                $support = [] === $reflection['supporting_observation_ids']
                    ? '(none)'
                    : implode(', ', $reflection['supporting_observation_ids']);
                $root->add(self::text(
                    $tui,
                    'id '.$reflection['reflection_id'].' · supporting '.$support,
                    TuiSemanticColorEnum::Muted,
                    dim: true,
                    italic: true,
                ));
            }
        }

        $root->add(self::markdown($tui, "### Observations\n", TuiSemanticColorEnum::Text));
        if ([] === $data['observations']) {
            $root->add(self::text($tui, '(none)', TuiSemanticColorEnum::Muted, dim: true, italic: true));
        } else {
            foreach ($data['observations'] as $observation) {
                $root->add(self::markdown(
                    $tui,
                    $observation['content']."\n",
                    self::relevanceColor($observation['relevance']),
                ));
                $refs = self::formatSourceRefs($observation['source_refs']);
                $root->add(self::text(
                    $tui,
                    'id '.$observation['observation_id']
                    .' · '.$observation['timestamp']
                    .' · '.$observation['relevance']
                    .' · sources '.$refs,
                    TuiSemanticColorEnum::Muted,
                    dim: true,
                    italic: true,
                ));
            }
        }

        return $root;
    }

    private static function relevanceColor(string $relevance): TuiSemanticColorEnum
    {
        return match (strtolower(trim($relevance))) {
            'low' => TuiSemanticColorEnum::Muted,
            'medium' => TuiSemanticColorEnum::Accent,
            'high' => TuiSemanticColorEnum::Warning,
            'critical' => TuiSemanticColorEnum::Error,
            default => TuiSemanticColorEnum::Text,
        };
    }

    /**
     * @param list<array{run_id: string, seq: int}> $refs
     */
    private static function formatSourceRefs(array $refs): string
    {
        if ([] === $refs) {
            return '(none)';
        }

        $parts = [];
        foreach ($refs as $ref) {
            $parts[] = \sprintf('(%s,%d)', $ref['run_id'], $ref['seq']);
        }

        return implode(', ', $parts);
    }

    private static function markdown(
        TransientTuiExtensionContextInterface $tui,
        string $markdown,
        TuiSemanticColorEnum $color,
    ): MarkdownWidget {
        $widget = new MarkdownWidget($markdown);
        $widget->setStyle($tui->createTextStyle($color));

        return $widget;
    }

    private static function text(
        TransientTuiExtensionContextInterface $tui,
        string $text,
        TuiSemanticColorEnum $color,
        bool $dim = false,
        bool $italic = false,
    ): TextWidget {
        $widget = new TextWidget($text);
        $widget->setStyle($tui->createTextStyle($color, $dim, $italic));

        return $widget;
    }
}
