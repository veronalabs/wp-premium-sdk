<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\License;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\License\LicenseErrorCode;

class NexusCodesNamedTest extends TestCase
{
    public function test_every_code_nexus_sends_has_a_name(): void
    {
        foreach ([
            'not_activated', 'nothing_to_update', 'product_not_found', 'store_required', 'store_mismatch',
            'store_forbidden', 'exchange_code_invalid', 'exchange_code_wrong_product', 'license_not_found',
            'domain_not_activated', 'no_release', 'plugin_not_found',
        ] as $code) {
            $this->assertContains($code, LicenseErrorCode::all(), $code);
        }
    }
}
