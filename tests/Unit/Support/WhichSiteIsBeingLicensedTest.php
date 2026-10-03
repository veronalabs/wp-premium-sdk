<?php

declare(strict_types=1);

namespace VeronaLabs\WpPremiumSdk\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use VeronaLabs\WpPremiumSdk\Support\Request;
use VeronaLabs\WpPremiumSdk\Tests\WpStub;

/**
 * What the SDK calls "this site" decides what the customer pays for, so both directions
 * of getting it wrong cost somebody real money.
 *
 * Reporting too little: a subdirectory network runs `example.com/site1` and
 * `example.com/site2` as genuinely separate sites, and reducing them to `example.com`
 * would activate a thousand of them against one seat.
 *
 * Reporting too much: a multilingual plugin serves `/en` and `/fr` from a single
 * installation, and reporting the viewed page would spend a seat per language. The
 * store's own records show that already happening to customers on the older client.
 *
 * `home_url()` answers both: it is the installation's address, it keeps the path that
 * separates one subsite from another, and it does not change when a visitor switches
 * language.
 *
 * On a network every subsite is its own site with its own seat, whether the plugin is
 * network-activated or not (wp-premium-sdk#7).
 */
final class WhichSiteIsBeingLicensedTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    protected function tearDown(): void
    {
        WpStub::reset();
    }

    public function test_a_plain_site_is_its_own_host(): void
    {
        WpStub::$homeUrl = 'https://example.com';

        self::assertSame('example.com', Request::currentDomain());
    }

    /**
     * Neither the scheme nor `www.` tells one site from another.
     */
    public function test_the_scheme_and_www_are_not_part_of_the_identity(): void
    {
        WpStub::$homeUrl = 'http://www.example.com';

        self::assertSame('example.com', Request::currentDomain());
    }

    /**
     * `example.com/` and `example.com` are one site, and must not become two records.
     */
    public function test_a_trailing_slash_does_not_make_a_second_site(): void
    {
        WpStub::$homeUrl = 'https://example.com/';

        self::assertSame('example.com', Request::currentDomain());
    }

    /**
     * The whole point of keeping the path. Two subsites of one network are two sites.
     */
    public function test_two_subsites_of_a_subdirectory_network_are_two_sites(): void
    {
        WpStub::$isMultisite = true;

        WpStub::$homeUrl = 'https://example.com/site1';
        $first = Request::currentDomain();

        WpStub::$homeUrl = 'https://example.com/site2';
        $second = Request::currentDomain();

        self::assertSame('example.com/site1', $first);
        self::assertSame('example.com/site2', $second);
        self::assertNotSame($first, $second, 'A subdirectory network must not collapse to one seat.');
    }

    /**
     * Network-activating the plugin does not turn the network into one seat: each
     * subsite still answers with its own address.
     */
    public function test_a_network_activated_plugin_still_counts_every_subsite(): void
    {
        WpStub::$isMultisite = true;
        WpStub::$networkHomeUrl = 'https://example.com';
        WpStub::$networkActivatedPlugins = ['acme/acme.php'];

        WpStub::$homeUrl = 'https://example.com/site1';
        $first = Request::currentDomain();

        WpStub::$homeUrl = 'https://example.com/site2';
        $second = Request::currentDomain();

        self::assertSame('example.com/site1', $first);
        self::assertSame('example.com/site2', $second);
    }

    /**
     * The old opt-in is kept only so hosts that still call it do not fatal. It must
     * not bring the network-wide answer back.
     */
    public function test_the_deprecated_network_opt_in_changes_nothing(): void
    {
        WpStub::$isMultisite = true;
        WpStub::$networkHomeUrl = 'https://example.com';
        WpStub::$networkActivatedPlugins = ['acme/acme.php'];
        WpStub::$homeUrl = 'https://example.com/site1';

        Request::useNetworkLicenceFor('acme/acme.php');

        self::assertSame('example.com/site1', Request::currentDomain());
    }

    /**
     * Comparing a site on the license with this one must ignore spelling only.
     */
    public function test_normalise_domain_ignores_spelling_but_keeps_the_path(): void
    {
        self::assertSame('example.com/site1', Request::normaliseDomain('HTTPS://www.Example.com:443/site1/'));
        self::assertSame('example.com', Request::normaliseDomain('example.com.'));
        self::assertSame('example.com:8080', Request::normaliseDomain('http://example.com:8080'));
        self::assertNotSame(Request::normaliseDomain('example.com/site1'), Request::normaliseDomain('example.com/site2'));
    }

    /**
     * A subdomain network already differs by host, so nothing here changes it.
     */
    public function test_a_subdomain_network_is_told_apart_by_host(): void
    {
        WpStub::$isMultisite = true;
        WpStub::$homeUrl = 'https://s1.example.com';

        self::assertSame('s1.example.com', Request::currentDomain());
    }

    /**
     * The case this was written for. `home_url()` does not move when a visitor switches
     * language, so one installation stays one site however many languages it serves.
     */
    public function test_a_multilingual_site_is_one_site(): void
    {
        WpStub::$homeUrl = 'https://example.com';

        self::assertSame('example.com', Request::currentDomain());
    }
}
