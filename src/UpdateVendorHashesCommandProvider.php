<?php

declare(strict_types=1);

namespace Rapidez\ComposerTemplateDiffPlugin;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use InvalidArgumentException;

class UpdateVendorHashesCommandProvider implements CommandProvider
{
    private TemplateHashUpdater $hashUpdater;

    /**
     * @param array{composer?: mixed, io?: mixed, plugin?: mixed} $args
     */
    public function __construct(array $args)
    {
        if (!isset($args['composer']) || !$args['composer'] instanceof Composer) {
            throw new InvalidArgumentException('Composer instance is required to register commands.');
        }

        if (!isset($args['io']) || !$args['io'] instanceof IOInterface) {
            throw new InvalidArgumentException('IO instance is required to register commands.');
        }

        $this->hashUpdater = new TemplateHashUpdater();
    }

    /**
     * @return BaseCommand[]
     */
    public function getCommands(): array
    {
        return [new UpdateVendorHashesCommand($this->hashUpdater)];
    }
}
