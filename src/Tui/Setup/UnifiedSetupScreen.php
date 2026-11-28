<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlowInterface;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\FocusEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Tui\Widget\TabItem;
use Symfony\Component\Tui\Widget\TabsWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final readonly class UnifiedSetupScreen
{
    public function __construct(private TuiTheme $theme)
    {
    }

    /** @param \Closure(SettingsLayerEnum): array{string, CoreSettingsScreen, ProvidersSetupFlowInterface, SetupScreen, ExtensionsSetupScreen, DefinitionSetupScreen, DefinitionSetupScreen} $build */
    public function mount(Tui $tui, SettingsLayerEnum $layer, \Closure $build): void
    {
        $body = new ContainerWidget();
        $body->setStyle(new Style(padding: Padding::xy(2, 0), gap: 1));
        $title = new TextWidget($this->theme->color(ThemeColorEnum::Accent, 'Hatfield setup'));
        $title->setStyle(new Style(bold: true));
        $target = new TextWidget('');
        $notice = new TextWidget('');
        $tabSlot = new ContainerWidget();
        $body->add($title)->add($target)->add($notice)->add($tabSlot);
        $tui->add($body);
        $footer = new TextWidget('');
        $removeHint = function (bool $show) use ($footer): void {
            $footer->setText($this->theme->muted('Tab / Shift+Tab  Switch tab  ·  F2  Switch scope  ·  Esc  Back / Exit'.($show ? '  ·  d · Remove from this scope' : '')));
        };
        $removeHint(false);
        $tui->add($footer);
        $tui->addListener(static function (FocusEvent $event) use ($removeHint): void {
            $widget = $event->getTarget();
            $removeHint($widget instanceof ScopedSettingsListWidget && [] === $widget->all() && $widget->canRemove());
        });

        $tabs = null;
        $focus = [];
        $providerInputPending = static fn (): bool => false;
        $definitionBodyOpen = static fn (): bool => false;
        $load = function (?int $index) use ($tui, $tabSlot, $target, $notice, $build, $removeHint, &$layer, &$tabs, &$focus, &$providerInputPending, &$definitionBodyOpen): void {
            [$path, $settings, $flow, $providers, $extensions, $skills, $agents] = $build($layer);
            $providerInputPending = $providers->hasUnsubmittedInput(...);
            $definitionBodyOpen = static fn (): bool => $skills->hasOpenBodyEditor() || $agents->hasOpenBodyEditor();
            $exit = $tui->stop(...);
            $target->setText($this->theme->color(ThemeColorEnum::Accent, $layer->name).' scope  ·  '.$path);
            $notice->setText('');
            $panes = array_map(static fn (): ContainerWidget => new ContainerWidget(), range(1, 5));
            foreach ($panes as $pane) {
                $pane->setStyle(new Style(padding: Padding::xy(2, 1), gap: 1));
            }
            $tabs = new TabsWidget([
                new TabItem('settings', 'Settings', $panes[0]),
                new TabItem('providers', 'Providers', $panes[1]),
                new TabItem('extensions', 'Extensions', $panes[2]),
                new TabItem('skills', 'Skills', $panes[3]),
                new TabItem('agents', 'Agents', $panes[4]),
            ]);
            $tabSlot->clear()->add($tabs);
            // Mount each pane while attached so the native focus manager can accept its control.
            for ($i = 0; $i < 5; ++$i) {
                $tabs->setActiveTab($i);
                match ($i) {
                    0 => $settings->mount($tui, $panes[0], $layer, $flow, $exit, $this->heading(...), $this->theme, $removeHint),
                    1 => $providers->mount($tui, $panes[1], $exit),
                    2 => $extensions->mount($tui, $panes[2], $exit),
                    3 => $skills->mount($tui, $panes[3], $exit),
                    4 => $agents->mount($tui, $panes[4], $exit),
                };
                $focus[$i] = $tui->getFocus();
            }
            $index ??= [] === $flow->configuredModelRefs() ? 1 : 0;
            $tabs->setActiveTab($index);
            $tui->setFocus($focus[$index]);
        };
        $load(null);
        $keys = new Keybindings(['setup_next' => [Key::TAB], 'setup_previous' => ['shift+tab'], 'setup_scope' => [Key::F2]]);
        $tui->addListener(function (InputEvent $event) use ($tui, $keys, $load, $notice, &$tabs, &$focus, &$layer, &$providerInputPending, &$definitionBodyOpen): void {
            $key = $event->getData();
            $scope = $keys->matches($key, 'setup_scope');
            $next = $keys->matches($key, 'setup_next');
            if (!$scope && !$next && !$keys->matches($key, 'setup_previous')) {
                return;
            }
            $event->stopPropagation();
            $current = $tui->getFocus();
            $index = $tabs->getActiveTabIndex();
            // Symfony detaches and closes a SettingsList submenu when its tab is hidden.
            $editing = $current instanceof SettingsListWidget && [] !== $current->all();
            if ($definitionBodyOpen()) {
                $notice->setText($this->theme->warning('Finish editing the definition body first (check Skills or Agents if hidden). Enter saves · Esc cancels.'));
            } elseif ($editing || ($scope && $providerInputPending())) {
                $notice->setText($this->theme->warning('Confirm or cancel the open editor first (check the Providers tab if it is hidden).'));
            } elseif ($scope) {
                $layer = SettingsLayerEnum::User === $layer ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
                $load($index);
            } else {
                $notice->setText('');
                $focus[$index] = $current;
                $index = ($index + ($next ? 1 : 4)) % 5;
                $tabs->setActiveTab($index);
                $tui->setFocus($focus[$index]);
            }
            $tui->requestRender();
        });
    }

    private function heading(string $text): TextWidget
    {
        return (new TextWidget($this->theme->color(ThemeColorEnum::Accent, $text)))->setStyle(new Style(bold: true));
    }
}
