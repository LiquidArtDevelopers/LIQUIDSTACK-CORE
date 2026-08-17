<?php

declare(strict_types=1);

use App\Core\Blog\StructuredContent\Rendering\BlogConsentIframeProjector;
use App\Core\Blog\StructuredContent\Rendering\BlogRenderingException;
use PHPUnit\Framework\TestCase;

final class BlogConsentIframeProjectorTest extends TestCase
{
    public function testMarkupWithoutIframeDoesNotRequireDom(): void
    {
        $html = '<p>Contenido sin iframe.</p>';

        self::assertSame(
            $html,
            (new BlogConsentIframeProjector(false))->inert($html)
        );
    }

    public function testIframeProjectionFailsClosedWithoutDom(): void
    {
        try {
            (new BlogConsentIframeProjector(false))->inert(
                '<iframe src="https://www.youtube-nocookie.com/embed/demo">'
                    . '</iframe>'
            );
            self::fail('Missing DOM must block iframe projection.');
        } catch (BlogRenderingException $exception) {
            self::assertSame(
                BlogRenderingException::INVALID_RENDER_STATE,
                $exception->issueCode()
            );
        }
    }
}
