<?php

declare(strict_types=1);

namespace Ineersa\Tui\Setup;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TabItem;
use Symfony\Component\Tui\Widget\TabsWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final readonly class UnifiedSetupScreen
{
    public function __construct(
        private CoreSettingsScreen $settings,
        private SetupScreen $providers,
        private ExtensionsSetupScreen $extensions,
        private TuiTheme $theme,
    ) {
    }

    public function run(SettingsLayerEnum $layer, string $path, ProvidersSetupFlowInterface $flow, int $initial): void
    {
        // Pane exit is deliberate (Save or Discard); global focus cycling would bypass it.
        $tui = new Tui(keybindings: new Keybindings(['focus_next' => [], 'focus_previous' => []]));
        $this->mount($tui, $layer, $path, $flow, $initial);
        $tui->run();
    }

    public function mount(Tui $tui, SettingsLayerEnum $layer, string $path, ProvidersSetupFlowInterface $flow, int $initial): void
    {
        $settings = new ContainerWidget();
        $providers = new ContainerWidget();
        $extensions = new ContainerWidget();
        $tabs = new TabsWidget([
            new TabItem('settings', 'Settings', $settings),
            new TabItem('providers', 'Providers', $providers),
            new TabItem('extensions', 'Extensions', $extensions),
        ]);
        $back = static fn () => $tui->setFocus($tabs);
        $this->settings->mount($tui, $settings, $layer, $flow, $back);
        $this->providers->mount($tui, $providers, $back);
        $this->extensions->mount($tui, $extensions, $back);
        $tui->add(new TextWidget('Hatfield setup · '.$layer->name.' · '.$path));
        $warning = 'Saving rewrites YAML and loses comments and formatting.';
        if (SettingsLayerEnum::Project === $layer) {
            $warning .= ' Use environment references for project credentials.';
        }
        $tui->add(new TextWidget($this->theme->warning($warning)));
        $tui->add(new TextWidget($this->theme->muted('←/→ or Tab: switch tabs · Enter: open · Esc: back · Ctrl+D on tabs: exit')));
        $tui->add($tabs);
        $keys = new Keybindings(['quit' => [Key::ctrl('d'), Key::ESCAPE], 'enter' => [Key::ENTER]]);
        $tabs->onInput(static function (string $key) use ($tui, $tabs, $keys): bool {
            if ($keys->matches($key, 'quit')) {
                $tui->stop();

                return true;
            }
            if ($keys->matches($key, 'enter')) {
                // The panes replace their current form as the user navigates; use the live tree.
                $tui->setFocus(self::firstFocusable($tabs->getTabs()[$tabs->getActiveTabIndex()]->getContent()));

                return true;
            }

            return false;
        });
        $tabs->setActiveTab($initial);
        $back();
    }

    private static function firstFocusable(AbstractWidget $widget): ?AbstractWidget
    {
        if ($widget instanceof FocusableInterface) {
            return $widget;
        }
        if ($widget instanceof ParentInterface) {
            foreach ($widget->all() as $child) {
                $found = self::firstFocusable($child);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }
}
