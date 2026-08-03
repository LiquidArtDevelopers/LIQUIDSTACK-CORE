<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ShowroomSemanticProbe
{
    private static string $coreRoot;

    public static function configure(string $coreRoot): void
    {
        self::$coreRoot = $coreRoot;
    }

    public static function controller(string $name, mixed ...$arguments): string
    {
        $root = self::templateRoot($name);
        $resource = htmlspecialchars($name, ENT_QUOTES);

        if (in_array($root, ['img', 'input', 'source'], true)) {
            return "<{$root} data-showroom-resource=\"{$resource}\">";
        }

        return "<{$root} data-showroom-resource=\"{$resource}\"></{$root}>";
    }

    private static function templateRoot(string $name): string
    {
        $paths = [
            self::$coreRoot . "/stubs/App/templates/_{$name}.html",
        ];
        $modulePaths = glob(
            self::$coreRoot
                . "/modules/*/resources/project/App/templates/_{$name}.html"
        );
        if (is_array($modulePaths)) {
            array_push($paths, ...$modulePaths);
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $template = file_get_contents($path);
            if (is_string($template)
                && preg_match('/<([a-z][a-z0-9-]*)\b/i', $template, $match) === 1
            ) {
                return strtolower($match[1]);
            }
        }

        return 'div';
    }
}

final class ShowroomSemanticCompositionTest extends TestCase
{
    public function testShowroomResourcesRespectSectionComposition(): void
    {
        $root = dirname(__DIR__, 2);
        ShowroomSemanticProbe::configure($root);
        $partials = glob($root . '/stubs/App/views/showroom/_*.php') ?: [];
        $modulePartials = glob(
            $root . '/modules/*/resources/project/App/views/showroom/_*.php'
        ) ?: [];
        $partials = array_merge($partials, $modulePartials);
        sort($partials);

        self::assertNotEmpty($partials);

        foreach ($partials as $partial) {
            $html = $this->renderWithSemanticProbes($partial);
            $document = new DOMDocument();
            $previousLibxmlState = libxml_use_internal_errors(true);

            try {
                self::assertTrue($document->loadHTML(
                    '<!DOCTYPE html><html><body><main>'
                        . $html
                        . '</main></body></html>'
                ));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousLibxmlState);
            }

            $xpath = new DOMXPath($document);
            $resources = $xpath->query('//*[@data-showroom-resource]');
            self::assertNotFalse($resources);

            foreach ($resources as $resource) {
                self::assertInstanceOf(DOMElement::class, $resource);
                $name = $resource->getAttribute('data-showroom-resource');
                $rootTag = strtolower($resource->tagName);
                $ancestors = $xpath->query('ancestor::section', $resource);
                self::assertNotFalse($ancestors);
                $context = basename($partial) . ": {$name} ({$rootTag})";

                if ($rootTag === 'section') {
                    self::assertSame(
                        0,
                        $ancestors->length,
                        "{$context} no debe anidarse dentro de otra section"
                    );
                    continue;
                }

                if ($rootTag === 'article'
                    || str_starts_with($name, 'art')
                    || str_starts_with($name, 'module')
                ) {
                    self::assertGreaterThan(
                        0,
                        $ancestors->length,
                        "{$context} debe pertenecer a una section"
                    );
                }
            }
        }
    }

    public function testShowroomIndexGroupsItsArticlesInASection(): void
    {
        $shell = file_get_contents(
            dirname(__DIR__, 2) . '/stubs/App/views/_showroom.php'
        );

        self::assertIsString($shell);
        self::assertStringContainsString(
            '<section class="showroomCatalog-index">',
            $shell
        );
        self::assertStringNotContainsString(
            '<div class="showroomCatalog-index">',
            $shell
        );
    }

    private function renderWithSemanticProbes(string $partial): string
    {
        $source = file_get_contents($partial);
        self::assertIsString($source);
        $showroomLanguage = 'es';
        $source = str_replace(
            'controller(',
            'ShowroomSemanticProbe::controller(',
            $source
        );

        ob_start();
        try {
            eval('?>' . $source);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
