<?php

declare(strict_types=1);

namespace Calmfox\TwoFactor\Tests;

use Calmfox\TwoFactor\Core\Http\Paths;
use Calmfox\TwoFactor\Core\Policy\Policy;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the package that only meet at runtime inside Neos: translation ids used in the
 * templates and classes, the privileges every controller action needs, the modules in the
 * settings. A mistake in any of them is a page that says "calmfox.error.wrongCode" or a
 * backend user who is refused the very page that lets them in, and neither shows up until
 * somebody signs in. Read from the files, so these tests need no Neos either.
 */
final class WiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The stylesheets Neos really ships for modules (Resources/Public/Styles), in 8.3 and 9. */
    private const MODULE_STYLESHEETS = ['Main', 'Lite', 'Minimal'];

    public function testEveryTranslationIdInUseExistsInEnglishAndPolish(): void
    {
        $english = $this->units('en');
        $polish = $this->units('pl');

        foreach ($this->usedIds() as $id) {
            self::assertArrayHasKey($id, $english, sprintf('"%s" is used but missing from en/Main.xlf', $id));
            self::assertArrayHasKey($id, $polish, sprintf('"%s" is used but missing from pl/Main.xlf', $id));
        }
    }

    public function testNothingIsTranslatedThatIsNotUsed(): void
    {
        $used = $this->usedIds();

        foreach (array_keys($this->units('en')) as $id) {
            self::assertContains($id, $used, sprintf('"%s" is in en/Main.xlf but nothing uses it', $id));
        }
    }

    public function testBothLanguagesHaveTheSameUnits(): void
    {
        self::assertSame(array_keys($this->units('en')), array_keys($this->units('pl')));
    }

    public function testPolishIsReallyTranslatedAndKeepsItsPlaceholders(): void
    {
        $english = $this->units('en');

        foreach ($this->units('pl') as $id => $unit) {
            self::assertNotSame('', trim($unit['target']), sprintf('"%s" has no Polish text', $id));
            self::assertSame($english[$id]['source'], $unit['source'], sprintf('"%s" has a different English source in the two files', $id));
            self::assertNotSame($unit['source'], $unit['target'], sprintf('"%s" repeats the English text instead of translating it', $id));
            self::assertSame(self::placeholders($unit['source']), self::placeholders($unit['target']), sprintf('"%s" lost or gained a placeholder', $id));
        }
    }

    /** House style: a sentence with a dash in it is two sentences. */
    public function testNoEmDashInWhatPeopleRead(): void
    {
        foreach (glob(self::ROOT . '/Resources/Private/Translations/*/*.xlf') ?: [] as $file) {
            self::assertStringNotContainsString('—', (string) file_get_contents($file), $file);
        }
    }

    /**
     * Neos denies every controller action that no privilege grants. An action without one is
     * an HTTP 403 on a page nobody can reach any other way.
     */
    public function testEveryControllerActionIsCoveredByAPrivilege(): void
    {
        $matchers = $this->privilegeMatchers();

        foreach ($this->controllerActions() as [$class, $action]) {
            $covered = false;
            foreach ($matchers as [$classPattern, $methodPattern]) {
                // as Flow's PointcutClassNameFilter does: every backslash is a namespace separator
                $classRegex = '/^' . str_replace('\\', '\\\\', $classPattern) . '$/';
                if (1 === preg_match($classRegex, $class) && 1 === preg_match('/^' . $methodPattern . '$/', $action)) {
                    $covered = true;
                }
            }
            self::assertTrue($covered, sprintf('%s->%s() has no privilege in Policy.yaml', $class, $action));
        }
    }

    public function testEveryPageAddressReachesAnExistingAction(): void
    {
        $actions = $this->controllerActions();
        $reflection = new \ReflectionClass(Paths::class);

        foreach ($reflection->getConstants() as $name => $page) {
            if ('PREFIX' === $name || !\is_string($page)) {
                continue;
            }
            foreach (['GET', 'POST'] as $method) {
                $match = Paths::match($method, Paths::path($page));
                if (null === $match) {
                    continue;
                }
                $class = 'Calmfox\\TwoFactor\\Controller\\' . $match['controller'] . 'Controller';
                self::assertContains([$class, $match['action'] . 'Action'], $actions, sprintf('%s %s points at a missing action', $method, $page));
            }
        }
    }

    public function testTheModulesPointAtControllersPrivilegesAndStylesheetsThatExist(): void
    {
        $settings = (string) file_get_contents(self::ROOT . '/Configuration/Settings.yaml');
        $policy = (string) file_get_contents(self::ROOT . '/Configuration/Policy.yaml');

        preg_match_all("/controller: '(Calmfox\\\\TwoFactor\\\\Controller\\\\Module\\\\\\w+)'/", $settings, $controllers);
        self::assertCount(2, $controllers[1], 'two modules: the user\'s own and the administrator\'s');
        foreach ($controllers[1] as $controller) {
            self::assertFileExists(self::ROOT . '/Classes/' . str_replace(['Calmfox\\TwoFactor\\', '\\'], ['', '/'], $controller) . '.php');
        }

        preg_match_all("/privilegeTarget: '([^']+)'/", $settings, $targets);
        foreach ($targets[1] as $target) {
            self::assertStringContainsString("'$target':", $policy, sprintf('the module privilege %s is not declared in Policy.yaml', $target));
        }

        preg_match_all("/mainStylesheet: '([^']+)'/", $settings, $stylesheets);
        foreach ($stylesheets[1] as $stylesheet) {
            self::assertContains($stylesheet, self::MODULE_STYLESHEETS, 'the name must match the file in Neos.Neos, including its case');
        }
    }

    /**
     * The module labels live in their own source, which Neos sends to the content editor's
     * menu (xliff.json). Every label the settings name must exist there in both languages, and
     * the source must be listed for sending, or the menu shows raw ids.
     */
    public function testTheModuleLabelsAreTranslatedAndSentToTheContentEditor(): void
    {
        $settings = (string) file_get_contents(self::ROOT . '/Configuration/Settings.yaml');
        preg_match_all("/'Calmfox\\.TwoFactor:(\\w+):([\\w.]+)'/", $settings, $labels, \PREG_SET_ORDER);
        self::assertCount(4, $labels);

        foreach ($labels as [, $source, $id]) {
            self::assertSame('Modules', $source, 'module labels belong in Modules.xlf, the source the content editor receives');
            foreach (['en', 'pl'] as $language) {
                self::assertArrayHasKey($id, $this->units($language, 'Modules'), sprintf('"%s" is missing from %s/Modules.xlf', $id, $language));
            }
        }
        self::assertMatchesRegularExpression("/autoInclude:\\s+'Calmfox\\.TwoFactor':\\s+- Modules/", $settings);

        $polish = $this->units('pl', 'Modules');
        foreach ($this->units('en', 'Modules') as $id => $unit) {
            self::assertNotSame($unit['source'], $polish[$id]['target'] ?? $unit['source'], sprintf('"%s" is not translated to Polish', $id));
        }
    }

    /** A typo in the default would otherwise quietly fall back to "required". */
    public function testTheDefaultPolicyIsOneThePackageKnows(): void
    {
        preg_match('/^\s+defaultPolicy: (\w+)$/m', (string) file_get_contents(self::ROOT . '/Configuration/Settings.yaml'), $match);

        self::assertContains($match[1] ?? '', Policy::ALL);
    }

    /** @return array<string, array{source: string, target: string}> */
    private function units(string $language, string $source = 'Main'): array
    {
        $xml = simplexml_load_file(self::ROOT . "/Resources/Private/Translations/$language/$source.xlf");
        self::assertNotFalse($xml, "$language/$source.xlf is not well-formed");
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $units = [];
        foreach ($xml->xpath('//x:trans-unit') ?: [] as $unit) {
            $units[(string) $unit['id']] = ['source' => (string) $unit->source, 'target' => (string) $unit->target];
        }

        return $units;
    }

    /** @return list<string> */
    private function usedIds(): array
    {
        $ids = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/Resources/Private'));
        foreach ($files as $file) {
            if ($file->isFile() && 'html' === $file->getExtension()) {
                preg_match_all("/translate[ (]id[=:] ?[\"']([\\w.{}]+)[\"']/", (string) file_get_contents($file->getPathname()), $matches);
                $ids = [...$ids, ...$matches[1]];
            }
        }

        $classes = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/Classes'));
        foreach ($classes as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all("/(?:translate|flash)\\('([\\w.]+)'/", $source, $matches);
                preg_match_all("/new self\\('([\\w.]+)'/", $source, $exceptions);
                $ids = [...$ids, ...$matches[1], ...$exceptions[1]];
            }
        }

        // the policy template builds its ids from the policy names
        $expanded = [];
        foreach ($ids as $id) {
            if (str_contains($id, '{option}')) {
                foreach (Policy::ALL as $policy) {
                    $expanded[] = str_replace('{option}', $policy, $id);
                }
            } else {
                $expanded[] = $id;
            }
        }

        return array_values(array_unique($expanded));
    }

    /** @return list<array{0: string, 1: string}> class and method patterns, as Flow reads them */
    private function privilegeMatchers(): array
    {
        preg_match_all("/matcher: 'method\\((.+)->(.+)\\(\\)\\)'/", (string) file_get_contents(self::ROOT . '/Configuration/Policy.yaml'), $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[1], $match[2]], $matches);
    }

    /** @return list<array{0: string, 1: string}> */
    private function controllerActions(): array
    {
        $actions = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/Classes/Controller'));
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'abstract class')) {
                continue;
            }
            preg_match('/^namespace ([^;]+);/m', $source, $namespace);
            preg_match('/^class (\w+)/m', $source, $class);
            preg_match_all('/public function (\w+Action)\(/', $source, $methods);
            foreach ($methods[1] as $method) {
                $actions[] = [$namespace[1] . '\\' . $class[1], $method];
            }
        }

        return $actions;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\d+\}/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }
}
