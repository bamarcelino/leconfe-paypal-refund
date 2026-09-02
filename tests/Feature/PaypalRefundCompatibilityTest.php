<?php

declare(strict_types=1);

namespace PaypalRefund\Tests\Feature;

use App\Classes\Plugin;
use App\Managers\PluginManager;
use Filament\Infolists\Components\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class PaypalRefundCompatibilityTest extends TestCase
{
    public function test_plugin_entrypoint_loads_on_leconfe_1_5(): void
    {
        $plugin = require $this->pluginRoot().'/index.php';

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertTrue(class_exists(Actions::class));
        $this->assertTrue(class_exists(Section::class));
        $this->assertTrue(class_exists(TextEntry::class));
    }

    public function test_manifest_declares_version_1_2_0(): void
    {
        $manifest = Yaml::parseFile($this->pluginRoot().'/index.yaml');

        $this->assertSame('PaypalRefund', $manifest['folder']);
        $this->assertSame('1.2.0', $manifest['version']);
    }

    public function test_plugin_structure_passes_leconfe_validation(): void
    {
        app(PluginManager::class)->validatePlugin($this->pluginRoot());

        $this->addToAssertionCount(1);
    }

    public function test_receipt_override_preserves_the_leconfe_1_5_template(): void
    {
        $coreReceipt = file_get_contents(
            base_path('resources/views/panel/scheduledConference/pages/receipt.blade.php')
        );
        $pluginReceipt = file_get_contents(
            $this->pluginRoot().'/resources/views/overrides/panel/scheduledConference/pages/receipt.blade.php'
        );

        $withoutRefundNotice = preg_replace(
            '/^[ \t]*\{\{-- PaypalRefund: refund notice start --\}\}\R.*?^[ \t]*\{\{-- PaypalRefund: refund notice end --\}\}\R/ms',
            '',
            $pluginReceipt,
            1,
            $replacementCount,
        );

        $this->assertSame(1, $replacementCount, 'The marked refund notice block was not found exactly once.');
        $this->assertSame($coreReceipt, $withoutRefundNotice);
    }

    private function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
