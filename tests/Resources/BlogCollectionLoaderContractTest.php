<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BlogCollectionLoaderContractTest extends TestCase
{
    public function testItAppendsUniqueServerRenderedBatchesAndKeepsFallback(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root
            . '/tests/Resources/fixtures/blog-collection-loader-harness.mjs';
        $command = sprintf(
            'node %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($root)
        );
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['a', 'b'], $result['afterFirst']['keys']);
        self::assertSame(
            '/es/noticias?page=3',
            $result['afterFirst']['next']
        );
        self::assertNull($result['afterFirst']['busy']);
        self::assertSame('ready', $result['afterFirst']['status']);
        self::assertSame(
            ['liquidstack:blog-collection-appended'],
            $result['afterFirst']['events']
        );
        self::assertSame(['a', 'b', 'c'], $result['afterSecond']['keys']);
        self::assertFalse($result['afterSecond']['hidden']);
        self::assertSame('true', $result['afterSecond']['disabled']);
        self::assertSame('Fin', $result['afterSecond']['label']);
        self::assertSame('end', $result['afterSecond']['status']);
        self::assertSame('Fin', $result['afterSecond']['message']);
        self::assertTrue($result['hiddenAfterBlur']);
        self::assertNull($result['afterTimeout']['busy']);
        self::assertNull($result['afterTimeout']['disabled']);
        self::assertSame('Reintentar', $result['afterTimeout']['label']);
        self::assertSame('error', $result['afterTimeout']['status']);
        self::assertSame('Error', $result['afterTimeout']['message']);
        self::assertSame(
            ['timeout-a', 'timeout-b'],
            $result['afterRetry']['keys']
        );
        self::assertTrue($result['afterRetry']['hidden']);
        self::assertSame('end', $result['afterRetry']['status']);
        self::assertSame('Fin', $result['afterRetry']['message']);

        $source = (string) file_get_contents(
            $root . '/modules/blog/resources/project/src/js/modules/blog/'
            . 'blogCollectionLoader.js'
        );
        foreach ([
            "credentials: 'same-origin'",
            "'X-LiquidStack-Partial': partialName",
            "error?.name !== 'AbortError'",
            'knownKeys.has(key)',
            "mode === 'near-end'",
            'intersectionObserver?.disconnect()',
            'requestController?.abort()',
            'DEFAULT_TIMEOUT_MS = 12_000',
            'documentRef?.activeElement === next',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    public function testAdversarialContinuationLifecycleRemainsRecoverable(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root
            . '/tests/Resources/fixtures/'
            . 'blog-collection-loader-adversarial-harness.mjs';
        $command = sprintf(
            'node %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($root)
        );
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        $result = json_decode(
            implode("\n", $output),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(2, $result['nearEnd']['fetches']);
        self::assertSame(
            ['near-a', 'near-b'],
            $result['nearEnd']['keys']
        );
        self::assertSame(2, $result['nearEnd']['events']);

        self::assertSame(1, $result['invalidContinuation']['fetches']);
        self::assertSame(
            ['invalid-seed'],
            $result['invalidContinuation']['keys']
        );
        self::assertSame('error', $result['invalidContinuation']['status']);

        self::assertTrue($result['cleanup']['aborted']);
        self::assertSame(
            ['cleanup-seed'],
            $result['cleanup']['keys']
        );
        self::assertSame(0, $result['cleanup']['events']);

        self::assertSame(1, $result['cap']['fetches']);
        self::assertFalse($result['cap']['nativeDefaultPrevented']);
        self::assertSame('/es/noticias?page=3', $result['cap']['next']);
    }
}
