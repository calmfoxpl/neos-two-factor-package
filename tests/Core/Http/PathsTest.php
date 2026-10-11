<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests\Core\Http;

use Calmfox\TwoFactor\Core\Http\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function testThePagesReachTheirActions(): void
    {
        self::assertSame(['controller' => 'Challenge', 'action' => 'index', 'format' => 'html'], Paths::match('GET', 'neos/two-factor/verify'));
        self::assertSame(['controller' => 'Challenge', 'action' => 'verify', 'format' => 'html'], Paths::match('POST', 'neos/two-factor/verify'));
        self::assertSame(['controller' => 'Setup', 'action' => 'index', 'format' => 'html'], Paths::match('GET', 'neos/two-factor/setup'));
        self::assertSame(['controller' => 'Setup', 'action' => 'pairApp', 'format' => 'html'], Paths::match('POST', 'neos/two-factor/setup/app'));
        self::assertSame(['controller' => 'Challenge', 'action' => 'signOut', 'format' => 'html'], Paths::match('POST', 'neos/two-factor/sign-out'));
    }

    public function testThePasskeyEndpointsAnswerInJson(): void
    {
        foreach (['verify/passkey-options', 'verify/passkey', 'setup/passkey-options', 'setup/passkey', 'account/passkey-options', 'account/passkey'] as $page) {
            self::assertSame('json', Paths::match('POST', Paths::path($page))['format'] ?? null, $page);
        }
    }

    public function testSlashesAroundThePathDoNotMatter(): void
    {
        self::assertNotNull(Paths::match('GET', '/neos/two-factor/verify/'));
    }

    public function testAHeadRequestIsTreatedAsAGet(): void
    {
        self::assertSame('index', Paths::match('HEAD', 'neos/two-factor/setup')['action'] ?? null);
    }

    /** A state change behind a GET could be triggered by a link or an image on another site. */
    public function testNothingThatChangesStateAnswersAGet(): void
    {
        foreach (['verify/passkey', 'setup/app', 'setup/passkey', 'sign-out', 'verify/passkey-options', 'setup/passkey-options', 'account/passkey-options', 'account/passkey', 'manage/reset', 'manage/turn-off'] as $page) {
            self::assertNull(Paths::match('GET', Paths::path($page)), $page);
        }
    }

    public function testOtherAddressesAreLeftToFlow(): void
    {
        self::assertNull(Paths::match('GET', 'neos/login'));
        self::assertNull(Paths::match('GET', 'neos/two-factor'));
        self::assertNull(Paths::match('GET', 'neos/two-factor/unknown'));
        self::assertNull(Paths::match('GET', 'about-us/neos/two-factor/verify'));
        self::assertNull(Paths::match('GET', 'neos/two-factorverify'));
    }

    public function testAnUnknownPageHasNoPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Paths::path('elsewhere');
    }

    public function testABrowserTabGetsAPageAndAScriptDoesNot(): void
    {
        self::assertTrue(Paths::wantsPage('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'));
        self::assertTrue(Paths::wantsPage(''));
        self::assertFalse(Paths::wantsPage('application/json'));
        self::assertFalse(Paths::wantsPage('text/html', 'XMLHttpRequest'));
    }

    /** A request that arrived on another port still remembers its page on the site's base. */
    public function testAnAddressOnTheSiteIsBuiltOnItsBaseUri(): void
    {
        self::assertSame('https://www.example.com/neos/content?node=a%20b', Paths::onSite('https://www.example.com/', '/neos/content', 'node=a%20b'));
        self::assertSame('http://localhost:8082/neos', Paths::onSite('http://localhost:8082/', 'neos'));
        self::assertSame('https://www.example.com/', Paths::onSite('https://www.example.com/', ''));
    }
}
