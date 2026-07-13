<?php

declare(strict_types=1);

namespace Rapidez\ComposerTemplateDiffPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class TemplateDiffPlugin implements PluginInterface, EventSubscriberInterface
{
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

    public function onPostUpdate(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();
        $vendorDir = $composer->getConfig()->get('vendor-dir');

        $projectRoot = dirname($vendorDir);

        try {
            $publishPaths = $this->resolvePublishPaths($projectRoot);

            if ($publishPaths === []) {
                $io->write('<comment>[rapidez-template-diff] No publishable view paths found.</comment>');

                return;
            }

            [$updated, $missingSource] = $this->updateViewHashes($projectRoot, $publishPaths);

            $io->write(sprintf('<info>[rapidez-template-diff] Updated %d view override hash(es).</info>', $updated));

            if ($missingSource > 0) {
                $io->write(sprintf('<comment>[rapidez-template-diff] Skipped %d file(s) without matching source view.</comment>', $missingSource));
            }
        } catch (Throwable $e) {
            $io->writeError(sprintf('<warning>[rapidez-template-diff] %s</warning>', $e->getMessage()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolvePublishPaths(string $projectRoot): array
    {
        $artisan = $projectRoot.'/artisan';

        if (!is_file($artisan)) {
            return [];
        }

        $command = sprintf(
            '%s artisan tinker --execute=%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg("echo json_encode(\\Illuminate\\Support\\ServiceProvider::pathsToPublish(null, 'views'));"),
        );

        $output = $this->runInProjectRoot($command, $projectRoot);
        $json = $this->extractJsonObject($output);

        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $publishPaths = [];

        foreach ($decoded as $source => $target) {
            if (is_string($source) && is_string($target)) {
                $publishPaths[$source] = $target;
            }
        }

        return $publishPaths;
    }

    private function runInProjectRoot(string $command, string $projectRoot): string
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $projectRoot);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to execute command to resolve publish paths.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        return (string) $stdout.(string) $stderr;
    }

    private function extractJsonObject(string $output): ?string
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            return $trimmed;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $match) === 1) {
            return $match[0];
        }

        return null;
    }

    /**
     * @param array<string, string> $publishPaths
     * @return array{int, int}
     */
    private function updateViewHashes(string $projectRoot, array $publishPaths): array
    {
        $themeRoots = [];
        $themesPath = $projectRoot.'/resources/themes';

        if (is_dir($themesPath)) {
            $themePaths = glob($themesPath.'/*');

            if ($themePaths === false) {
                $themePaths = [];
            }

            foreach ($themePaths as $themePath) {
                if (is_dir($themePath)) {
                    $themeRoots[] = realpath($themePath) ?: $themePath;
                }
            }
        }

        $updated = 0;
        $missingSource = 0;
        $processedFiles = [];

        foreach ($publishPaths as $sourceRoot => $targetRoot) {
            $resolvedSourceRoot = realpath($sourceRoot) ?: $sourceRoot;
            $resolvedTargetRoot = realpath($targetRoot) ?: $targetRoot;

            $overrideRoots = [$resolvedTargetRoot];
            $targetWithinResources = $this->targetWithinResourcesViews($resolvedTargetRoot);

            if ($targetWithinResources !== null) {
                foreach ($themeRoots as $themeRoot) {
                    $overrideRoots[] = $themeRoot.'/'.$targetWithinResources;
                }
            }

            foreach ($overrideRoots as $overrideRoot) {
                if (!is_dir($overrideRoot)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($overrideRoot, RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo instanceof \SplFileInfo) {
                        continue;
                    }

                    $overridePath = $fileInfo->getPathname();

                    if (!str_ends_with($overridePath, '.blade.php')) {
                        continue;
                    }

                    $dedupeKey = realpath($overridePath) ?: $overridePath;

                    if (isset($processedFiles[$dedupeKey])) {
                        continue;
                    }

                    $sourceFile = $this->resolveSourceFile($overrideRoot, $overridePath, $resolvedSourceRoot);

                    if ($sourceFile === null) {
                        $missingSource++;
                        continue;
                    }

                    $content = (string) file_get_contents($overridePath);

                    $hashLine = '{{-- vendor-hash:'.md5_file($sourceFile).' --}}';
                    $newLine = str_contains($content, "\r\n") ? "\r\n" : "\n";

                    if (preg_match('/^\{\{--\s*vendor-hash:[^\r\n]*--\}\}(\r?\n)?/', $content, $match) === 1) {
                        $replacement = $hashLine.($match[1] ?? $newLine);
                        $updatedContent = (string) preg_replace('/^\{\{--\s*vendor-hash:[^\r\n]*--\}\}(\r?\n)?/', $replacement, $content, 1);
                    } else {
                        $updatedContent = $hashLine.$newLine.$content;
                    }

                    if ($updatedContent !== $content) {
                        file_put_contents($overridePath, $updatedContent);
                        $updated++;
                    }

                    $processedFiles[$dedupeKey] = true;
                }
            }
        }

        return [$updated, $missingSource];
    }

    private function targetWithinResourcesViews(string $targetRoot): ?string
    {
        $normalized = str_replace('\\', '/', $targetRoot);
        $needle = '/resources/views/';

        if (!str_contains($normalized, $needle)) {
            return null;
        }

        return explode($needle, $normalized, 2)[1] ?: null;
    }

    private function relativePath(string $root, string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    }

    private function resolveSourceFile(string $overrideRoot, string $overridePath, string $sourceRoot): ?string
    {
        if (!$this->isWithinRoot($overrideRoot, $overridePath)) {
            return null;
        }

        $relativePath = $this->relativePath($overrideRoot, $overridePath);

        if ($relativePath === '') {
            return null;
        }

        $sourceFile = rtrim($sourceRoot, '/').'/'.$relativePath;

        if (!is_file($sourceFile)) {
            return null;
        }

        return $sourceFile;
    }

    private function isWithinRoot(string $root, string $path): bool
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot.'/');
    }
}
