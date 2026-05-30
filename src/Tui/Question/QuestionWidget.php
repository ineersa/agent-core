<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Render-only widget to display the active question request.
 *
 * Shows an informational banner above the editor with the question
 * prompt, available options, and action hints.  Does not handle input
 * routing, key dispatch, or answer submission — those are owned by
 * later tasks (QH-03+).
 *
 * Renders nothing when no request is set.
 *
 * @see QuestionCoordinator for the lifecycle manager
 * @see QuestionKind for the supported interaction types
 */
final class QuestionWidget implements TuiWidget
{
    private ?QuestionRequest $request = null;

    /**
     * Set the active question to render.
     *
     * Pass null to clear the display.
     */
    public function setRequest(?QuestionRequest $request): void
    {
        $this->request = $request;
    }

    /**
     * Return the currently set request, or null.
     */
    public function getRequest(): ?QuestionRequest
    {
        return $this->request;
    }

    /**
     * @return list<string>
     */
    public function render(TuiRenderContext $context): array
    {
        if (null === $this->request) {
            return [];
        }

        return match ($this->request->kind) {
            QuestionKind::Text => $this->renderText($context),
            QuestionKind::Confirm => $this->renderConfirm($context),
            QuestionKind::Choice => $this->renderChoice($context),
            QuestionKind::Approval => $this->renderApproval($context),
        };
    }

    /**
     * Resolve the header text: custom header if set, otherwise a
     * kind-appropriate default.
     */
    private function resolveHeader(): string
    {
        \assert(null !== $this->request);

        return $this->request->header ?? match ($this->request->kind) {
            QuestionKind::Text => 'Human input required',
            QuestionKind::Confirm => 'Confirmation required',
            QuestionKind::Choice => 'Choose an option',
            QuestionKind::Approval => 'Approval requested',
        };
    }

    /**
     * @return list<string>
     */
    private function renderText(TuiRenderContext $ctx): array
    {
        \assert(null !== $this->request);
        $hint = $this->request->secret
            ? '[answer will be hidden, type and press Enter]'
            : '[type answer and press Enter]';

        return [
            $ctx->theme->color(ThemeColorEnum::Warning, \sprintf('? %s', $this->resolveHeader())),
            $ctx->theme->color(ThemeColorEnum::Text, \sprintf('  %s', $this->request->prompt)),
            $ctx->theme->color(ThemeColorEnum::Muted, \sprintf('  %s', $hint)),
        ];
    }

    /**
     * @return list<string>
     */
    private function renderConfirm(TuiRenderContext $ctx): array
    {
        \assert(null !== $this->request);

        return [
            $ctx->theme->color(ThemeColorEnum::Warning, \sprintf('? %s', $this->resolveHeader())),
            $ctx->theme->color(ThemeColorEnum::Text, \sprintf('  %s', $this->request->prompt)),
            $ctx->theme->color(ThemeColorEnum::Muted, '  y = yes, n = no'),
        ];
    }

    /**
     * @return list<string>
     */
    private function renderChoice(TuiRenderContext $ctx): array
    {
        \assert(null !== $this->request);

        $lines = [
            $ctx->theme->color(ThemeColorEnum::Warning, \sprintf('? %s', $this->resolveHeader())),
            $ctx->theme->color(ThemeColorEnum::Text, \sprintf('  %s', $this->request->prompt)),
        ];

        $i = 1;
        foreach ($this->request->choices as $option) {
            \assert($option instanceof QuestionOption);
            $number = $ctx->theme->color(ThemeColorEnum::Accent, \sprintf('%d.', $i));
            $label = $ctx->theme->color(ThemeColorEnum::Accent, $option->label);

            $line = '' !== $option->description
                ? \sprintf(
                    '  %s %s%s',
                    $number,
                    $label,
                    $ctx->theme->color(ThemeColorEnum::Muted, \sprintf(' — %s', $option->description)),
                )
                : \sprintf('  %s %s', $number, $label);

            $lines[] = $line;
            ++$i;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderApproval(TuiRenderContext $ctx): array
    {
        \assert(null !== $this->request);

        return [
            $ctx->theme->color(ThemeColorEnum::Warning, \sprintf('? %s', $this->resolveHeader())),
            $ctx->theme->color(ThemeColorEnum::Text, \sprintf('  %s', $this->request->prompt)),
            $ctx->theme->color(ThemeColorEnum::Muted, '  y = approve, n = reject'),
        ];
    }
}
