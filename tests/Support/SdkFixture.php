<?php

namespace VeronaLabs\WpPremiumSdk\Tests\Support;

/**
 * The host identity the {@see BuildsSdk} tests run as. Kept in a class rather than the
 * trait, because constants in traits need PHP 8.2 and the SDK supports 7.4.
 */
final class SdkFixture
{
    public const PLUGIN = 'wp-statistics-premium/wp-statistics-premium.php';

    public const OPTION = 'wp_statistics_premium';
}
