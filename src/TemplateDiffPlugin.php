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
    private const DISABLED_ENV_KEY = 'RAPIDEZ_TEMPLATE_DIFF_DISABLED';

    private TemplateHashUpdater $hashUpdater;

    /**
     * @var array<string, string>|null
     */
    private ?array $dotenvCache = null;

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
        if (!$this->isPluginEnabled($event->getComposer())) {
            $event->getIO()->write('<comment>[rapidez-template-diff] Skipping automatic hash update (plugin disabled by config).</comment>');

            return;
        }

        $this->updateVendorHashes($event->getComposer(), $event->getIO());
    }

    private function isPluginEnabled(Composer $composer): bool
    {
        $explicitDisable = $this->resolveConfigValue($composer, self::DISABLED_ENV_KEY);

        if ($explicitDisable !== null) {
            return !$this->isTruthyValue($explicitDisable);
        }

        $appEnv = strtolower(trim((string) ($this->resolveConfigValue($composer, 'APP_ENV') ?? '')));

        return in_array($appEnv, ['local', 'dev', 'development'], true);
    }

    private function resolveConfigValue(Composer $composer, string $key): ?string
    {
        $value = getenv($key);

        if ($value !== false) {
            return trim((string) $value);
        }

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return trim($_SERVER[$key]);
        }

        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return trim($_ENV[$key]);
        }

        $dotenvValues = $this->readDotenvValues($composer);

        if (isset($dotenvValues[$key])) {
            return trim($dotenvValues[$key]);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readDotenvValues(Composer $composer): array
    {
        if ($this->dotenvCache !== null) {
            return $this->dotenvCache;
        }

        $projectRoot = $this->resolveProjectRoot($composer);

        if ($projectRoot === null) {
            $this->dotenvCache = [];

            return $this->dotenvCache;
        }

        $dotenvPath = $projectRoot.'/.env';

        if (!is_file($dotenvPath) || !is_readable($dotenvPath)) {
            $this->dotenvCache = [];

            return $this->dotenvCache;
        }

        $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            $this->dotenvCache = [];

            return $this->dotenvCache;
        }

        $values = [];

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            if (str_starts_with($trimmedLine, 'export ')) {
                $trimmedLine = trim(substr($trimmedLine, 7));
            }

            if (!str_contains($trimmedLine, '=')) {
                continue;
            }

            [$name, $rawValue] = explode('=', $trimmedLine, 2);
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $values[$name] = $this->normalizeDotenvValue($rawValue);
        }

        $this->dotenvCache = $values;

        return $this->dotenvCache;
    }

    private function normalizeDotenvValue(string $rawValue): string
    {
        $value = trim($rawValue);

        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];
        $lastChar = $value[strlen($value) - 1];

        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === '\'' && $lastChar === '\'')) {
            return substr($value, 1, -1);
        }

        $commentPos = strpos($value, ' #');

        if ($commentPos !== false) {
            $value = rtrim(substr($value, 0, $commentPos));
        }

        return $value;
    }

    private function resolveProjectRoot(Composer $composer): ?string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');

        if ($vendorDir === '') {
            return null;
        }

        $resolvedVendorDir = realpath($vendorDir);

        if ($resolvedVendorDir === false) {
            $cwd = getcwd();

            if ($cwd === false) {
                return null;
            }

            $resolvedVendorDir = $cwd.'/'.$vendorDir;
        }

        return dirname($resolvedVendorDir);
    }

    private function isTruthyValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
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
