<?php
// #ddev-generated

namespace DrupalDev\ComposerGitInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\Locker;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public static function getSubscribedEvents(): array
    {
        return [
            'pre-command-run' => ['onPreCommand', -100],
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        // Seed installer-paths and config.platform from core's composer.json
        // into the root package so the fallback in getInstallPath() works
        // before the merge plugin has had a chance to merge core's extra, and
        // so PHPStan sees the correct platform PHP version.
        //
        // config.platform must also be written to the root composer file on
        // disk because PHPStan reads it directly from that file (via the
        // $COMPOSER env var) in a separate process — the in-memory Config
        // merge is not visible to it.
        $coreFile = getcwd() . '/composer.json';
        if (file_exists($coreFile)) {
            $coreConfig = json_decode(file_get_contents($coreFile), true);

            $rootExtra = $composer->getPackage()->getExtra();
            if (empty($rootExtra['installer-paths']) && !empty($coreConfig['extra']['installer-paths'])) {
                $rootExtra['installer-paths'] = $coreConfig['extra']['installer-paths'];
                $composer->getPackage()->setExtra($rootExtra);
            }

            if (!empty($coreConfig['config']['platform'])) {
                $composer->getConfig()->merge(
                    ['config' => ['platform' => $coreConfig['config']['platform']]],
                    $coreFile
                );
                $this->syncPlatformToRootFile($coreConfig['config']['platform']);
            }
        }

        $this->pinRootVersionFromCoreLock();
        $this->registerInstaller();

        $composer->getEventDispatcher()->addSubscriber(new CoreLockPinner($composer, $io));
    }

    /**
     * Re-register before each command so our installer takes priority over
     * composer/installers, which activates from vendor after us.
     */
    public function onPreCommand(): void
    {
        $this->registerInstaller();
    }

    /**
     * Pin the root package's version from drupal/core's entry in composer.lock.
     *
     * composer-merge-plugin substitutes 'self.version' in merged requirements
     * with the root package's version. Composer's VersionGuesser is unreliable
     * inside the web container, and a missed guess defaults to 1.0.0.0, which
     * makes drupal/core's self.version requirements (drupal/core,
     * core-project-message, core-recipe-unpack, core-vendor-hardening)
     * unresolvable.
     *
     * core's own composer.lock always records drupal/core at the canonical
     * composer version for the current branch/tag (dev-main, 11.x-dev,
     * 11.1.6, …), so we take it from there.
     */
    private function pinRootVersionFromCoreLock(): void
    {
        $lockFile = new JsonFile(getcwd() . '/composer.lock');
        if (!$lockFile->exists()) {
            return;
        }

        $locker = new Locker($this->io, $lockFile, $this->composer->getInstallationManager(), '{}');
        if (!$locker->isLocked()) {
            return;
        }

        $package = $locker->getLockedRepository()->findPackage('drupal/core', '*');
        if (!$package) {
            return;
        }

        $root = $this->composer->getPackage();
        while ($root instanceof AliasPackage) {
            $root = $root->getAliasOf();
        }

        // Package's version-related fields are write-once via the constructor
        // and have no setters in modern Composer, so reflect to override them.
        $stability = VersionParser::parseStability($package->getVersion());
        $values = [
            'version' => $package->getVersion(),
            'prettyVersion' => $package->getPrettyVersion(),
            'stability' => $stability,
            'dev' => $stability === 'dev',
        ];
        $reflection = new \ReflectionClass($root);
        foreach ($values as $name => $value) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }
            $property = $reflection->getProperty($name);
            $property->setValue($root, $value);
        }
    }

    private function registerInstaller(): void
    {
        $installer = new GitPreservingInstaller($this->io, $this->composer);
        $this->composer->getInstallationManager()->addInstaller($installer);
    }

    /**
     * Write config.platform into the root composer file (e.g. composer.local.json)
     * so that tools like PHPStan, which read that file directly in a separate
     * process, pick up the correct platform PHP version.
     *
     * Only writes when the value differs from what is already on disk to avoid
     * unnecessary file churn.
     *
     * @param array<string, string> $platform
     */
    private function syncPlatformToRootFile(array $platform): void
    {
        $envComposer = getenv('COMPOSER');
        $rootFileName = is_string($envComposer) && $envComposer !== '' ? basename($envComposer) : 'composer.json';
        $rootFile = getcwd() . '/' . $rootFileName;

        // Only act when we're actually running under an overlay root file that
        // is separate from the core composer.json we just read.
        if ($rootFile === getcwd() . '/composer.json' || !file_exists($rootFile)) {
            return;
        }

        $rootData = json_decode(file_get_contents($rootFile), true);
        if (!is_array($rootData)) {
            return;
        }

        if (($rootData['config']['platform'] ?? null) === $platform) {
            return;
        }

        $rootData['config']['platform'] = $platform;
        file_put_contents(
            $rootFile,
            json_encode($rootData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
