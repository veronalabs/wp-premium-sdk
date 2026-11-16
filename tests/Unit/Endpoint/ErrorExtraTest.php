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
}
