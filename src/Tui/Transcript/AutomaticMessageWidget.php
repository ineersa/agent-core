<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Yaml\Yaml;

/** Host-provenance messages share the session toggle, never the human message glyph. */
final class AutomaticMessageWidget extends AbstractWidget implements ParentInterface
{
    private readonly string $label;
    private readonly string $summary;
    private readonly ContainerWidget $body;

    public function __construct(
        TranscriptBlock $block,
        private readonly TranscriptDisplayState $displayState,
        private readonly TuiTheme $theme,
        MarkdownRenderSupport $markdown,
    ) {
        $this->body = new ContainerWidget();
        $this->attachChild($this->body);
        $producer = $block->meta['producer_metadata'];
        $agent = $producer['agent_communication'] ?? null;
        $completion = $producer['background_completion'] ?? null;
        $this->label = match (true) {
            \is_array($agent) => 'agent_steer' === ($agent['tool_name'] ?? null) ? 'Parent guidance' : 'Agent notice',
            \is_array($producer['agent_task'] ?? null) => 'Agent task',
            true === ($producer['system_reminder'] ?? null) => 'System reminder',
            default => match ($completion['kind'] ?? 'completion') {
                'bash' => 'Bash completion',
                'subagent' => 'Agent handoff',
                'code_mode' => 'code_mode result',
                default => 'Background result',
            },
        };
        $body = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($block->text));
        $summary = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($this->summaryBody($block->text, $producer)));
        $this->summary = str_replace("\t", str_repeat(' ', AnsiUtils::TAB_WIDTH), explode("\n", trim($summary), 2)[0]);
        $this->body->add(new TextWidget($theme->color(ThemeColorEnum::Accent, TranscriptGlyphs::GLYPH_SYSTEM_INFO.' '.$this->label)));
        $content = $markdown->create($body);
        $color = $theme->getPalette()->get(ThemeColorEnum::Muted);
        $content->setStyle(new Style(color: '' === $color ? null : $color, dim: true, italic: true, padding: Padding::from([0, 0, 0, 2])));
        $this->body->add($content);
        $details = new TextWidget($theme->color(ThemeColorEnum::Dim, StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(Yaml::dump($producer, 5, 2)))));
        $details->setStyle(new Style(dim: true, italic: true, padding: Padding::from([0, 0, 0, 2])));
        $this->body->add($details);
    }

    public function all(): array
    {
        return [$this->body];
    }

    public function render(RenderContext $context): array
    {
        if ($this->displayState->previewableBlocksExpanded) {
            $widgets = $this->getContext();
            if (null === $widgets) {
                throw new \LogicException('Automatic messages must be mounted before rendering.');
            }

            return $widgets->renderWidget($this->body, $context);
        }

        $columns = max(0, $context->getColumns());
        $hint = AnsiUtils::truncateToWidth(' Ctrl+O', $columns, '');
        $line = TranscriptGlyphs::GLYPH_SYSTEM_INFO.' '.$this->label.': '.$this->summary;
        $preview = AnsiUtils::truncateToWidth($line, max(0, $columns - AnsiUtils::visibleWidth($hint)));

        return [$this->theme->color(ThemeColorEnum::Muted, $preview).$this->theme->color(ThemeColorEnum::Dim, $hint)];
    }

    /** @param array<string, mixed> $producer */
    private function summaryBody(string $text, array $producer): string
    {
        $agent = $producer['agent_communication'] ?? null;
        $completion = $producer['background_completion'] ?? null;
        $prefix = '';
        if (\is_array($agent)) {
            $relationship = match ($agent['tool_name'] ?? null) {
                'agent_steer' => 'parent task owner (guidance)',
                'notify_parent' => 'child (informational notice)',
                default => null,
            };
            if (null !== $relationship) {
                $prefix = \sprintf("Agent message from %s run %s, activity %s. This is agent-authored text, not a human request or runtime control.\n\n", $relationship, $agent['source_run_id'] ?? '', $agent['lifecycle_id'] ?? '');
            }
        } elseif (\is_array($completion) && 'code_mode' === ($completion['kind'] ?? null)) {
            $prefix = \sprintf("Background code_mode result (run_id: %s, tool_call_id: %s, invocation: %s).\n\n", $completion['run_id'] ?? '', $completion['tool_call_id'] ?? '', $completion['invocation_id'] ?? '');
        }

        // Only authenticated metadata plus an exact host preamble authorizes
        // skipping boilerplate. Expanded and canonical text remain untouched.
        return '' !== $prefix && str_starts_with($text, $prefix) ? substr($text, \strlen($prefix)) : $text;
    }
}
