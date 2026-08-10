<?php

declare(strict_types=1);

namespace Rapidez\ComposerTemplateDiffPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Throwable;

class TemplateDiffPlugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private TemplateHashUpdater $hashUpdater;

    public function __construct(?TemplateHashUpdater $hashUpdater = null)
    {
        $this->hashUpdater = $hashUpdater ?? new TemplateHashUpdater();
    }

    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdate',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => UpdateVendorHashesCommandProvider::class,
        ];
    }

    public function onPostUpdate(Event $event): void
    {
        $this->updateVendorHashes($event->getComposer(), $event->getIO());
    }

    public function updateVendorHashes(Composer $composer, IOInterface $io): int
    {
        try {
            $this->hashUpdater->updateVendorHashes($composer, $io);

            return 0;
        } catch (Throwable $e) {
            $io->writeError(sprintf('<warning>[rapidez-template-diff] %s</warning>', $e->getMessage()));

            return 1;
        }
    }
}
