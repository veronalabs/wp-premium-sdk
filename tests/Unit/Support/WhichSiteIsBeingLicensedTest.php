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
 */
final class WhichSiteIsBeingLicensedTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
        Request::useNetworkLicenceFor('');
    }

    protected function tearDown(): void
    {
        WpStub::reset();
        Request::useNetworkLicenceFor('');
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
     * A network-activated plugin was installed once, for all of them, so the network
     * holds the licence and every subsite answers with the network's address.
     */
    public function test_a_network_activated_plugin_licenses_the_whole_network(): void
    {
        WpStub::$isMultisite = true;
        WpStub::$networkHomeUrl = 'https://example.com';
        WpStub::$networkActivatedPlugins = ['acme/acme.php'];
        Request::useNetworkLicenceFor('acme/acme.php');

        WpStub::$homeUrl = 'https://example.com/site1';
        $first = Request::currentDomain();

        WpStub::$homeUrl = 'https://example.com/site2';
        $second = Request::currentDomain();

        self::assertSame('example.com', $first);
        self::assertSame($first, $second, 'A network-activated plugin is one licence for the network.');
    }

    /**
     * Activated on one subsite only, the network address is not the answer — that subsite
     * bought its own licence.
     */
    public function test_a_plugin_activated_on_one_subsite_licenses_that_subsite(): void
    {
        WpStub::$isMultisite = true;
        WpStub::$networkHomeUrl = 'https://example.com';
        WpStub::$networkActivatedPlugins = [];
        Request::useNetworkLicenceFor('acme/acme.php');
        WpStub::$homeUrl = 'https://example.com/site1';

        self::assertSame('example.com/site1', Request::currentDomain());
    }

    /**
     * When the host plugin has not said which file it is, the network question cannot be
     * answered. Answering for this site alone counts more sites rather than fewer, which
     * is the safe direction to be wrong in.
     */
    public function test_without_a_plugin_file_the_subsite_answers_for_itself(): void
    {
        WpStub::$isMultisite = true;
        WpStub::$networkHomeUrl = 'https://example.com';
        WpStub::$networkActivatedPlugins = ['acme/acme.php'];
        WpStub::$homeUrl = 'https://example.com/site1';

        self::assertSame('example.com/site1', Request::currentDomain());
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
