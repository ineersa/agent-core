<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\InputWidget;

/** Symfony input behavior with no rendering of sensitive input. */
final class SetupInputWidget extends InputWidget
{
    public bool $secret = false;

    public function render(RenderContext $context): array
    {
        return $this->secret ? ['Hidden input'] : parent::render($context);
    }
}
