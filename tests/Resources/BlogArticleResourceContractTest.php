<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

use function App\Core\Support\controller;

final class BlogArticleResourceContractTest extends TestCase
{
    private string $originalCwd = '';
    private string $originalProjectRoot = '';

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->originalProjectRoot = Paths::projectRoot();
        Paths::setProjectRoot(self::moduleProjectRoot());
        chdir(self::coreRoot());
    }

    protected function tearDown(): void
    {
        Paths::setProjectRoot($this->originalProjectRoot);
        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
    }

    public function testBasicArticleComposesExistingModulesAndKeepsSemantics(): void
    {
        $html = controller('artBlogArticle01', 0, [
            'article_data' => $this->articleData('article-basic-01'),
            '{article-intro}' => '<div class="moduleH1Type04 artBlogArticle01-heading">'
                . '<p class="artBlogArticle01-date"><time datetime="2026-08-03T09:00:00+00:00">'
                . 'August 3, 2026</time></p>'
                . '<h1 class="moduleH1Type04-title">Matrix article</h1>'
                . '</div>',
            '{article-back}' => '<a class="moduleButtonType04 artBlogArticle01-backAction"'
                . ' rel="up" href="/en/news"><span>Back to news</span></a>',
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('/html/body/article'));
        self::assertCount(0, $xpath->query('//article//article | //section'));
        self::assertCount(1, $xpath->query('/html/body/article/header'));
        self::assertCount(1, $xpath->query('//article//h1'));
        self::assertCount(1, $xpath->query('//article//time[@datetime]'));
        self::assertCount(1, $xpath->query('//article//a[@rel="up"]'));
        self::assertSame(
            'artBlogArticle01-00-heading',
            $xpath->evaluate('string(/html/body/article/@aria-labelledby)')
        );
        self::assertStringContainsString('artBlogArticle01--basic', $html);
        self::assertStringContainsString('moduleH1Type04', $html);
        self::assertStringContainsString('moduleButtonType04', $html);
        self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
    }

    public function testCoverModifierAndPreviewHeadingAreExplicit(): void
    {
        $data = $this->articleData('article-cover-01');
        $data['body_html'] = '<div class="blogDocument">'
            . '<h2>Body section</h2><h3>Body subsection</h3></div>';
        $data['header_media_html'] = '<figure id="trusted-cover" '
            . 'class="blogDocument__image blogDocument__image--cover">'
            . '<img src="/media/cover.avif" alt="Matrix cover"></figure>';
        $html = controller('artBlogArticle01', 1, [
            'article_data' => $data,
            '{article-intro}' => '<div><h3>Preview heading</h3></div>',
        ]);
        $xpath = $this->createXpath($html);

        self::assertCount(1, $xpath->query('//article//h3'));
        self::assertCount(1, $xpath->query('//article//h4'));
        self::assertCount(1, $xpath->query('//article//h5'));
        self::assertCount(0, $xpath->query('//article//h1'));
        self::assertStringContainsString('artBlogArticle01--cover', $html);
        self::assertStringContainsString(
            'data-blog-template="article-cover-01"',
            $html
        );
        self::assertCount(
            1,
            $xpath->query(
                '/html/body/article/header/figure[@id="trusted-cover"]'
            )
        );
        self::assertCount(
            0,
            $xpath->query(
                '/html/body/article/div[contains(@class, "artBlogArticle01-body")]'
                    . '//figure[@id="trusted-cover"]'
            )
        );
    }

    public function testTrustedHtmlFragmentsDoNotRelaxScalarEscaping(): void
    {
        $data = $this->articleData('article-basic-01');
        $data['h1'] = '<script>alert(1)</script>';
        $data['excerpt'] = '<img src=x onerror=alert(1)>';
        $data['published_label'] = '<b>Published</b>';
        $data['back_label'] = '<em>Back</em>';
        $data['back_href'] = 'javascript:alert(1)';
        $data['body_html'] = '<div class="blogDocument"><p id="trusted">'
            . 'Sanitized by CORE</p></div>';

        $html = controller('artBlogArticle01', 0, [
            'article_data' => $data,
        ]);

        self::assertStringContainsString(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $html
        );
        self::assertStringContainsString(
            '&lt;img src=x onerror=alert(1)&gt;',
            $html
        );
        self::assertStringContainsString('id="trusted"', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('moduleButtonType04', $html);
    }

    public function testHeaderMediaIsAcceptedOnlyByTheCoverTemplate(): void
    {
        $data = $this->articleData('article-basic-01');
        $data['header_media_html'] = '<figure>Trusted cover</figure>';

        $this->expectException(InvalidArgumentException::class);
        controller('artBlogArticle01', 0, ['article_data' => $data]);
    }

    public function testReservedTemplatePlaceholdersCannotOverrideValidatedData(): void
    {
        $html = controller('artBlogArticle01', 0, [
            'article_data' => $this->articleData('article-basic-01'),
            '{article-body}' => '<script id="override">alert(1)</script>',
            '{article-header-media}' => '<script id="media-override">alert(1)</script>',
            '{article-id}' => 'attacker-id',
            '{template}' => 'attacker-template',
            '{modifier}' => 'attacker-modifier',
            '{classVar}' => 'attacker-class',
        ]);

        self::assertStringNotContainsString('override', $html);
        self::assertStringNotContainsString('attacker-', $html);
        self::assertStringContainsString('Trusted body.', $html);
        self::assertStringContainsString('artBlogArticle01--basic', $html);
    }

    public function testUnsupportedOrIncompletePresentationFailsClosed(): void
    {
        foreach ([
            ['template' => 'article-unknown-99'],
            ['template' => 'article-basic-01', 'h1' => 'Missing body'],
        ] as $data) {
            try {
                controller('artBlogArticle01', 0, ['article_data' => $data]);
                self::fail('Invalid Blog article data must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testControllerIsPresentationOnlyAndStylesArePortable(): void
    {
        $controller = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/App/controllers/artBlogArticle01.php'
        );
        $scss = (string) file_get_contents(
            self::moduleProjectRoot()
                . '/src/scss/resources/_artBlogArticle01.scss'
        );

        foreach ([
            'PDO',
            'BlogService',
            'BlogRepository',
            'SELECT ',
            'prepare(',
            'query(',
            'data-inline-',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controller);
        }
        self::assertStringContainsString("\$params['{article-intro}']", $controller);
        self::assertStringContainsString("\$params['{article-back}']", $controller);
        self::assertStringContainsString("\$article['header_media_html']", $controller);
        self::assertStringContainsString(
            '<header class="artBlogArticle01-intro">',
            (string) file_get_contents(
                self::moduleProjectRoot()
                    . '/App/templates/_artBlogArticle01.html'
            )
        );
        self::assertStringContainsString("@use '../config' as c;", $scss);
        self::assertStringContainsString('&--basic', $scss);
        self::assertStringContainsString('&--cover', $scss);
        self::assertStringContainsString('@media (min-width: c.$tablet)', $scss);
        self::assertDoesNotMatchRegularExpression('/@media\s*\([^)]*max-width/i', $scss);
        self::assertDoesNotMatchRegularExpression(
            '/\bc\.\$color(?:0[4-9]|[1-9][0-9])/',
            $scss
        );
        self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $scss);
    }

    /** @return array<string, mixed> */
    private function articleData(string $template): array
    {
        return [
            'template' => $template,
            'h1' => 'Matrix article',
            'excerpt' => 'A structured Matrix article excerpt.',
            'published_label' => 'Published',
            'published_text' => 'August 3, 2026',
            'published_at' => '2026-08-03T09:00:00+00:00',
            'body_html' => '<div class="blogDocument"><p>Trusted body.</p></div>',
            'back_label' => 'Back to news',
            'back_href' => '/en/news',
        ];
    }

    private function createXpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        return new DOMXPath($document);
    }

    private static function moduleProjectRoot(): string
    {
        return self::coreRoot() . '/modules/blog/resources/project';
    }

    private static function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
