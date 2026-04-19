<?php

namespace VeronaLabs\WpPremiumSdk\Module;

use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\License\LicenseManager;

/**
 * Discovers installed modules under ClientConfig::modulesPath() and loads
 * their entry classes only when the current license grants access.
 *
 * Each module directory is expected to contain:
 *   manifest.json → {slug, version, namespace, main_class}
 *   src/<namespace>/<main_class>.php
 */
class ModuleLoader
{
    public function __construct(
        private ClientConfig $config,
        private LicenseManager $license,
    ) {}

    public function register(): void
    {
        add_action('init', [$this, 'loadLicensedModules'], 20);
    }

    public function loadLicensedModules(): void
    {
        $modulesPath = $this->config->modulesPath();

        if (! $modulesPath || ! is_dir($modulesPath)) {
            return;
        }

        foreach ($this->discover() as $manifest) {
            if (! $this->license->hasFeature($manifest['slug'])) {
                continue;
            }

            $this->bootModule($manifest);
        }
    }

    /**
     * @return array<int, array{slug: string, version: string, namespace?: string, main_class?: string, path: string}>
     */
    public function discover(): array
    {
        $manifests = [];
        $modulesPath = $this->config->modulesPath();

        foreach (scandir($modulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $moduleDir = $modulesPath.'/'.$entry;
            $manifestFile = $moduleDir.'/manifest.json';

            if (! is_dir($moduleDir) || ! file_exists($manifestFile)) {
                continue;
            }

            $manifest = json_decode(file_get_contents($manifestFile) ?: 'null', true);

            if (! is_array($manifest) || empty($manifest['slug'])) {
                continue;
            }

            $manifest['path'] = $moduleDir;
            $manifests[] = $manifest;
        }

        return $manifests;
    }

    /**
     * @param  array{slug: string, namespace?: string, main_class?: string, path: string}  $manifest
     */
    private function bootModule(array $manifest): void
    {
        if (empty($manifest['main_class'])) {
            return;
        }

        $namespace = $manifest['namespace'] ?? '';
        $fqcn = rtrim($namespace, '\\').'\\'.$manifest['main_class'];

        if (! class_exists($fqcn)) {
            // Best-effort PSR-4 fallback: src/{main_class}.php
            $classFile = $manifest['path'].'/src/'.str_replace('\\', '/', $manifest['main_class']).'.php';

            if (file_exists($classFile)) {
                require_once $classFile;
            }
        }

        if (class_exists($fqcn) && method_exists($fqcn, 'boot')) {
            (new $fqcn)->boot();
        }
    }
}
