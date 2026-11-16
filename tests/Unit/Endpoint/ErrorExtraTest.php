<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Endpoint;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Endpoint\AbstractAjaxEndpoint;
use VeronaLabs\WpPremiumSdk\Http\ApiException;

class ErrorExtraTest extends TestCase
{
    public function test_a_support_url_nexus_attached_is_forwarded_beside_the_renewal_block(): void
    {
        $renewal = ['state' => 'expired', 'renew_url' => 'https://my.example.test/renew'];
        $e = new ApiException('License not found', 'invalid_key', [
            'support_url' => 'https://my.example.test/support',
            'renewal' => $renewal,
        ]);

        $this->assertSame(
            ['renewal' => $renewal, 'support_url' => 'https://my.example.test/support'],
            AbstractAjaxEndpoint::errorExtra($e)
        );
    }

    public function test_a_support_url_that_is_not_a_web_address_is_dropped(): void
    {
        foreach (['javascript:alert(1)', 'mailto:help@example.test', '', 42, null] as $bad) {
            $e = new ApiException('License not found', 'invalid_key', ['support_url' => $bad]);

            $this->assertArrayNotHasKey('support_url', AbstractAjaxEndpoint::errorExtra($e));
        }
    }
}
