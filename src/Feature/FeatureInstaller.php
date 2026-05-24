<?php

namespace VeronaLabs\WpPremiumSdk\Feature;

use Exception;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use WP_Error;

/**
 * Downloads and installs module ZIPs from signed Nexus URLs.
 *
 * Verifies SHA-256 hash before extraction. Installs into
 * ClientConfig::modulesPath()/{slug}/ so that ModuleLoader can discover them
 * at runtime via manifest.json.
 */
class FeatureInstaller
{
    private ClientConfig $config;

    public function __construct(ClientConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Install a single module from a signed URL.
     *
     * @param  array{slug: string, url: string, hash?: string, version?: string}  $asset
     *
     * @throws Exception
     */
    public function installSingle(array $asset): bool
    {
        if (empty($asset['slug']) || empty($asset['url'])) {
            throw new Exception(__('Invalid asset descriptor.', $this->config->textDomain()));
        }

        $tmp = $this->downloadToTempFile($asset['url']);

        try {
            if (! empty($asset['hash'])) {
                $actual = hash_file('sha256', $tmp);

                if (! hash_equals($asset['hash'], $actual)) {
                    throw new Exception(__('Module ZIP failed hash verification.', $this->config->textDomain()));
                }
            }

            $this->extractZip($tmp, $asset['slug']);

            return true;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Install many modules at once.
     *
     * @param  array<int, array{slug: string, url: string, hash?: string}>  $assets
     * @return array{installed: string[], failed: array<int, array{slug: string, error: string}>}
     */
    public function installMany(array $assets): array
    {
        $installed = [];
        $failed = [];

        foreach ($assets as $asset) {
            try {
                $this->installSingle($asset);
                $installed[] = $asset['slug'];
            } catch (Exception $e) {
                $failed[] = [
                    'slug' => $asset['slug'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['installed' => $installed, 'failed' => $failed];
    }

    /**
     * Modules currently present on disk, as a slug => version map read from each
     * module's manifest.json. Lets the dashboard mark licensed features as
     * installed / not installed.
     *
     * @return array<string, string>
     */
    public function installedModules(): array
    {
        $modulesPath = $this->config->modulesPath();

        if (! $modulesPath || ! is_dir($modulesPath)) {
            return [];
        }

        $modules = [];

        foreach (scandir($modulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestFile = $modulesPath.'/'.$entry.'/manifest.json';

            if (! is_file($manifestFile)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestFile), true);

            if (is_array($manifest) && ! empty($manifest['slug'])) {
                $modules[(string) $manifest['slug']] = (string) ($manifest['version'] ?? '');
            }
        }

        return $modules;
    }

    /**
     * @throws Exception
     */
    private function downloadToTempFile(string $url): string
    {
        if (! function_exists('download_url')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        $tmp = download_url($url, 60);

        if ($tmp instanceof WP_Error) {
            throw new Exception($tmp->get_error_message());
        }

        return $tmp;
    }

    /**
     * @throws Exception
     */
    private function extractZip(string $zipPath, string $slug): void
    {
        $modulesPath = $this->config->modulesPath();

        if (! $modulesPath) {
            throw new Exception(__('Modules path not configured.', $this->config->textDomain()));
        }

        if (! is_dir($modulesPath)) {
            wp_mkdir_p($modulesPath);
        }

        $target = $modulesPath.'/'.$slug;

        if (is_dir($target)) {
            $this->deleteDirectory($target);
        }

        if (! class_exists('ZipArchive')) {
            throw new Exception(__('ZipArchive is required to install modules.', $this->config->textDomain()));
        }

        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new Exception(__('Could not open module ZIP file.', $this->config->textDomain()));
        }

        $extractTo = $modulesPath;
        $zip->extractTo($extractTo);
        $zip->close();

        $extractedRoot = $this->detectExtractedRoot($extractTo, $slug);

        if ($extractedRoot && $extractedRoot !== $target) {
            rename($extractedRoot, $target);
        }
    }

    private function detectExtractedRoot(string $extractTo, string $slug): ?string
    {
        $preferred = $extractTo.'/'.$slug;

        if (is_dir($preferred)) {
            return $preferred;
        }

        $candidates = glob($extractTo.'/*'.$slug.'*', GLOB_ONLYDIR) ?: [];

        return $candidates[0] ?? null;
    }

    private function deleteDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($path);
    }
}
