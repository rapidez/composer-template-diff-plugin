<?php

declare(strict_types=1);

namespace Rapidez\ComposerTemplateDiffPlugin;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class UpdateVendorHashesCommand extends BaseCommand
{
    private TemplateHashUpdater $hashUpdater;

    public function __construct(TemplateHashUpdater $hashUpdater)
    {
        parent::__construct();

        $this->hashUpdater = $hashUpdater;
    }

    protected function configure(): void
    {
        $this
            ->setName('update-vendor-hashes')
            ->setDescription('Updates vendor hash comments for published Blade template overrides.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->hashUpdater->updateVendorHashes($this->requireComposer(), $this->getIO());

            return 0;
        } catch (Throwable $e) {
            $this->getIO()->writeError(sprintf('<warning>[rapidez-template-diff] %s</warning>', $e->getMessage()));

            return 1;
        }
    }
}
