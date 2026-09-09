<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ArtAccordion02StyleContractTest extends TestCase
{
    public function testFormerViewModifierIsNowCanonicalAndColorSafe(): void
    {
        $scss = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/scss/_artAccordion02.scss'
        );

        foreach ([
            'background-color: c.$color00bis;',
            'width: min(100%, 70rem);',
            '.artAccordion02-headerCopy :is(h2, h3, h4, h5, h6) {',
            'object-fit: contain;',
            'border-radius: .5rem;',
            'box-shadow: none;',
            'font-weight: 700;',
            '.artAccordion02-trigger:focus-visible {',
            'outline: 3px solid c.$color02;',
            '.artAccordion02-indicator {',
            'color: c.$color02;',
        ] as $contract) {
            self::assertStringContainsString($contract, $scss);
        }

        self::assertStringNotContainsString(
            '.artAccordion02_00_classVar',
            $scss
        );
        self::assertStringNotContainsString('c.$color04', $scss);
        self::assertStringNotContainsString('c.$color05', $scss);
        self::assertStringNotContainsString('#f7f9fc', strtolower($scss));
        self::assertDoesNotMatchRegularExpression(
            '/rgba\s*\(\s*(?:39\s*,\s*57\s*,\s*83|69\s*,\s*100\s*,\s*147)/i',
            $scss
        );
    }
}
