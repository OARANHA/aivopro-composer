<?php

declare(strict_types=1);

namespace AiVoPro\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\DependencyResolver\Operation\OperationInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected IOInterface $io;
    protected Composer $composer;
    protected Installer $installer;
    protected string $webroot;

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);

        $this->webroot = $this->determineWebroot($composer);
        $io->write(sprintf('<info>Using webroot: %s</info>', $this->webroot), true, IOInterface::VERBOSE);
    }

    /**
     * Determine the webroot path from various sources
     */
    private function determineWebroot(Composer $composer): string
    {
        // Check environment variable
        $envWebroot = getenv('PUBLIC_DIR');
        if ($envWebroot !== false) {
            return $envWebroot;
        }

        // Default value
        return 'public';
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // No action needed
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // No action needed
    }

    /**
     * @return array<string, string|array<string>>
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageUpdate',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPackageUninstall',
        ];
    }

    /**
     * Handle post package installation event
     */
    public function onPackageInstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);

        if (!$package || !$this->isAiVoProPackage($package)) {
            return;
        }

        $this->copyPackageFiles($package);
    }

    /**
     * Handle post package update event
     */
    public function onPackageUpdate(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);

        if (!$package || !$this->isAiVoProPackage($package)) {
            return;
        }

        // Remove old files first, then copy new ones
        $this->removePackageFiles($package);
        $this->copyPackageFiles($package);
    }

    /**
     * Handle pre package uninstallation event
     */
    public function onPackageUninstall(PackageEvent $event)
    {
        $operation = $event->getOperation();
        $package = $this->getPackageFromOperation($operation);

        if (!$package || !$this->isAiVoProPackage($package)) {
            return;
        }

        $this->removePackageFiles($package);
    }

    /**
     * Extract the package from an operation based on operation type
     */
    private function getPackageFromOperation(OperationInterface $operation): ?PackageInterface
    {
        $operationType = $operation->getOperationType();

        switch ($operationType) {
            case 'install':
                return $operation instanceof \Composer\DependencyResolver\Operation\InstallOperation
                    ? $operation->getPackage()
                    : null;

            case 'update':
                return $operation instanceof \Composer\DependencyResolver\Operation\UpdateOperation
                    ? $operation->getTargetPackage()
                    : null;

            case 'uninstall':
                return $operation instanceof \Composer\DependencyResolver\Operation\UninstallOperation
                    ? $operation->getPackage()
                    : null;

            default:
                return null;
        }
    }

    /**
     * Check if package is an AiVoPro package
     */
    private function isAiVoProPackage(PackageInterface $package): bool
    {
        $packageType = $package->getType();
        return in_array($packageType, ['aivopro-plugin', 'aivopro-theme']);
    }

    /**
     * Copy files from package to target directory
     */
    private function copyPackageFiles(PackageInterface $package): void
    {
        $extra = $package->getExtra();

        // Skip if no public files are defined in the package
        if (!isset($extra['public']) || !is_array($extra['public']) || empty($extra['public'])) {
            return;
        }

        $publicFiles = $extra['public'];
        $installPath = $this->installer->getInstallPath($package);
        $packageName = $package->getPrettyName();
        $fs = new Filesystem();

        // Prepare mappings to store for later removal
        $mappings = [];

        // Default public directory for this package: {webroot}/e/{vendor}/{package}
        $defaultPublicDir = $this->webroot . '/e/' . $packageName;

        foreach ($publicFiles as $entry) {
            try {
                // Normalize entry to source/target pair
                if (is_string($entry)) {
                    // Legacy format: simple string path preserves full path structure
                    $source = $entry;
                    $targetPath = $defaultPublicDir . '/' . $entry;
                } elseif (is_array($entry) && isset($entry['source'])) {
                    // New format: {source, target}
                    $source = $entry['source'];
                    $target = $entry['target'] ?? null;

                    // Determine target path
                    if ($target === null) {
                        $sourceBase = $this->getBasePathFromPattern($source);
                        $targetPath = $defaultPublicDir . '/' . basename($sourceBase);
                    } elseif ($target === '/.' || $target === '/') {
                        $sourceBase = $this->getBasePathFromPattern($source);
                        $targetPath = $this->webroot . '/' . basename($sourceBase);
                    } elseif ($target === '.') {
                        $sourceBase = $this->getBasePathFromPattern($source);
                        $targetPath = $defaultPublicDir . '/' . basename($sourceBase);
                    } elseif (strpos($target, '/') === 0) {
                        $targetPath = $this->webroot . '/' . ltrim($target, '/');
                    } else {
                        $targetPath = $defaultPublicDir . '/' . ltrim($target, '/');
                    }
                } else {
                    $this->io->writeError(sprintf(
                        '<warning>Invalid public entry format in package %s</warning>',
                        $packageName
                    ));
                    continue;
                }

                // Check if source contains glob patterns
                if ($this->containsGlobPattern($source)) {
                    $this->copyGlobPattern($installPath, $source, $targetPath, $fs, $packageName, $mappings);
                } else {
                    $sourcePath = $installPath . '/' . $source;

                    if (!file_exists($sourcePath)) {
                        $this->io->writeError(sprintf(
                            '<warning>Source path %s does not exist for package %s</warning>',
                            $sourcePath,
                            $packageName
                        ));
                        continue;
                    }

                    // Create target directory if it doesn't exist
                    $targetDir = is_dir($sourcePath) ? $targetPath : dirname($targetPath);
                    if (!is_dir($targetDir)) {
                        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                            throw new \RuntimeException(sprintf(
                                'Failed to create target directory: %s',
                                $targetDir
                            ));
                        }
                    }

                    // Copy file or directory
                    if (is_dir($sourcePath)) {
                        $fs->copy($sourcePath, $targetPath);
                        $this->io->write(sprintf(
                            '<info>Copied directory from %s to %s</info>',
                            $sourcePath,
                            $targetPath
                        ));
                    } else {
                        if (!copy($sourcePath, $targetPath)) {
                            throw new \RuntimeException(sprintf(
                                'Failed to copy file from %s to %s',
                                $sourcePath,
                                $targetPath
                            ));
                        }
                        $this->io->write(sprintf(
                            '<info>Copied file from %s to %s</info>',
                            $sourcePath,
                            $targetPath
                        ));
                    }

                    $mappings[$source] = $targetPath;
                }
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<error>Error processing entry for package %s: %s</error>',
                    $packageName,
                    $e->getMessage()
                ));
                continue;
            }
        }

        // Store the mappings for later removal
        if (!empty($mappings)) {
            try {
                $this->storeFileMappings($package, $mappings);
            } catch (\Exception $e) {
                $this->io->writeError(sprintf(
                    '<error>Failed to store file mappings for package %s: %s</error>',
                    $packageName,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Check if a path contains glob patterns
     */
    private function containsGlobPattern(string $path): bool
    {
        return strpos($path, '*') !== false || strpos($path, '?') !== false || strpos($path, '[') !== false;
    }

    /**
     * Get base path from a glob pattern
     */
    private function getBasePathFromPattern(string $pattern): string
    {
        $pattern = preg_replace('/\/\*+\/?$/', '', $pattern);
        $pattern = preg_replace('/[?*\[\]]/', '', $pattern);
        return $pattern;
    }

    /**
     * Copy files matching a glob pattern
     */
    private function copyGlobPattern(
        string $installPath,
        string $sourcePattern,
        string $targetBase,
        Filesystem $fs,
        string $packageName,
        array &$mappings
    ): void {
        $fullPattern = $installPath . '/' . $sourcePattern;

        if (preg_match('/\/\*+$/', $sourcePattern)) {
            $sourceDir = $installPath . '/' . preg_replace('/\/\*+$/', '', $sourcePattern);
            if (is_dir($sourceDir)) {
                $this->copyDirectoryContents($sourceDir, $targetBase, $fs, $packageName, $mappings);
            }
            return;
        }

        $matches = glob($fullPattern);
        if (!empty($matches)) {
            foreach ($matches as $sourcePath) {
                if (file_exists($sourcePath)) {
                    $relativePath = substr($sourcePath, strlen($installPath) + 1);
                    $targetPath = $targetBase . '/' . $relativePath;
                    
                    $targetDir = is_dir($sourcePath) ? $targetPath : dirname($targetPath);
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }

                    if (is_dir($sourcePath)) {
                        $fs->copy($sourcePath, $targetPath);
                    } else {
                        copy($sourcePath, $targetPath);
                    }
                    $mappings[$sourcePath] = $targetPath;
                }
            }
        }
    }

    /**
     * Copy directory contents recursively
     */
    private function copyDirectoryContents(string $sourceDir, string $targetDir, Filesystem $fs, string $packageName, array &$mappings): void
    {
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = substr($sourcePath, strlen($sourceDir) + 1);
            $targetPath = $targetDir . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $targetFileDir = dirname($targetPath);
                if (!is_dir($targetFileDir)) {
                    mkdir($targetFileDir, 0755, true);
                }
                copy($sourcePath, $targetPath);
            }
            $mappings[$sourcePath] = $targetPath;
        }
    }

    /**
     * Store file mappings for later removal
     */
    private function storeFileMappings(PackageInterface $package, array $mappings): void
    {
        $packageName = $package->getPrettyName();
        $mappingsFile = $this->getMappingsFilePath();

        $allMappings = [];
        if (file_exists($mappingsFile)) {
            $content = file_get_contents($mappingsFile);
            if ($content !== false) {
                $allMappings = json_decode($content, true) ?: [];
            }
        }

        // Store relative paths from webroot
        $relativeMappings = [];
        foreach ($mappings as $source => $target) {
            $webrootPrefix = $this->webroot . '/';
            if (strpos($target, $webrootPrefix) === 0) {
                $relativeTarget = substr($target, strlen($webrootPrefix));
            } else {
                $relativeTarget = $target;
            }
            $relativeMappings[$source] = $relativeTarget;
        }

        $allMappings[$packageName] = $relativeMappings;
        file_put_contents($mappingsFile, json_encode($allMappings, JSON_PRETTY_PRINT));
    }

    /**
     * Remove files that were copied from package
     */
    private function removePackageFiles(PackageInterface $package): void
    {
        $packageName = $package->getPrettyName();
        $mappingsFile = $this->getMappingsFilePath();

        if (!file_exists($mappingsFile)) {
            return;
        }

        $content = file_get_contents($mappingsFile);
        if ($content === false) {
            return;
        }

        $allMappings = json_decode($content, true) ?: [];
        if (!isset($allMappings[$packageName])) {
            return;
        }

        $mappings = $allMappings[$packageName];
        $fs = new Filesystem();

        foreach ($mappings as $source => $relativeTarget) {
            $target = strpos($relativeTarget, '/') === 0 
                ? $relativeTarget 
                : $this->webroot . '/' . ltrim($relativeTarget, '/');

            if (file_exists($target)) {
                $fs->remove($target);
                $this->io->write(sprintf(
                    '<info>Removed %s during uninstallation of %s</info>',
                    $target,
                    $packageName
                ));
            }
        }

        // Remove package directory if empty
        $packageDir = $this->webroot . '/e/' . $packageName;
        if (is_dir($packageDir) && $this->isDirEmpty($packageDir)) {
            $fs->removeDirectory($packageDir);
        }

        unset($allMappings[$packageName]);
        file_put_contents($mappingsFile, json_encode($allMappings, JSON_PRETTY_PRINT));
    }

    /**
     * Check if a directory is empty
     */
    private function isDirEmpty(string $dir): bool
    {
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * Get the path to the file mappings storage
     */
    private function getMappingsFilePath(): string
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        return $vendorDir . '/aivopro-file-mappings.json';
    }
}
