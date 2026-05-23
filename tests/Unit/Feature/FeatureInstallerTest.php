<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Feature;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Config\ClientConfig;
use VeronaLabs\WpPremiumSdk\Feature\FeatureInstaller;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

class FeatureInstallerTest extends TestCase
{
    private string $modulesDir;

    protected function setUp(): void
    {
        WpStub::reset();
        $this->modulesDir = sys_get_temp_dir().'/sdk-modules-'.uniqid();
        mkdir($this->modulesDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->modulesDir);
    }

    public function test_installed_modules_maps_slug_to_version(): void
    {
        $this->writeModule('entry-pages', '1.2.0');
        $this->writeModule('goals', '2.0.0');

        $this->assertEquals(
            ['entry-pages' => '1.2.0', 'goals' => '2.0.0'],
            $this->installer()->installedModules(),
        );
    }

    public function test_installed_modules_ignores_dirs_without_a_manifest(): void
    {
        $this->writeModule('goals', '2.0.0');
        mkdir($this->modulesDir.'/not-a-module', 0777, true);

        $this->assertEquals(['goals' => '2.0.0'], $this->installer()->installedModules());
    }

    public function test_installed_modules_empty_when_dir_missing(): void
    {
        $this->removeDir($this->modulesDir);

        $this->assertSame([], $this->installer()->installedModules());
    }

    private function installer(): FeatureInstaller
    {
        return new FeatureInstaller(new ClientConfig([
            'product_slug' => 'wp-statistics',
            'option_key' => 'wp_statistics_premium',
            'oauth_state_prefix' => 'x_',
            'oauth_callback_params' => ['code' => 'c', 'state' => 's'],
            'api_base_url' => 'https://nexus.test',
            'text_domain' => 'td',
            'current_version' => '15.0.0',
            'modules_path' => $this->modulesDir,
        ]));
    }

    private function writeModule(string $slug, string $version): void
    {
        mkdir($this->modulesDir.'/'.$slug, 0777, true);
        file_put_contents(
            $this->modulesDir.'/'.$slug.'/manifest.json',
            json_encode(['slug' => $slug, 'version' => $version]),
        );
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
