<?php

declare(strict_types=1);

use App\Core\Composer\ScssConfigContractSynchronizer;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ScssConfigContractSynchronizerTest extends TestCase
{
    private Filesystem $filesystem;
    private string $root;
    private string $configPath;
    private string $contractPath;
    private BufferIO $io;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'liquidstack-scss-contract-'
            . bin2hex(random_bytes(8));
        $this->configPath = $this->root . '/src/scss/_config.scss';
        $this->contractPath = $this->root
            . '/manifests/scss-color-contract-v2.json';
        $this->io = new BufferIO();

        $this->filesystem->mkdir([
            dirname($this->configPath),
            dirname($this->contractPath),
        ]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testAddsOnlyMissingVariablesAndKeepsExistingValues(): void
    {
        $original = "\$color00: project-white;\n"
            . "\$filterColor02: project-filter;\n"
            . ".project { color: \$color00; }\n";
        $this->writeFile($this->configPath, $original);
        $this->writeContract([
            [
                'name' => 'color00',
                'value' => '#fff',
            ],
            [
                'name' => 'color02SVG',
                'value' => 'fallback-filter',
                'legacy_alias' => 'filterColor02',
            ],
            [
                'name' => 'color03SVG',
                'value' => 'third-filter',
                'legacy_alias' => 'missingLegacyFilter',
            ],
        ]);

        $synchronizer = $this->synchronizer();
        $added = $synchronizer->sync(
            $this->configPath,
            $this->contractPath
        );
        $updated = (string) file_get_contents($this->configPath);

        self::assertSame(2, $added);
        self::assertTrue($synchronizer->wasSuccessful());
        self::assertStringStartsWith($original . "\n", $updated);
        self::assertStringContainsString(
            "\$color00: project-white;\n",
            $updated
        );
        self::assertStringContainsString(
            "\$color02SVG: \$filterColor02 !default;\n",
            $updated
        );
        self::assertStringContainsString(
            "\$color03SVG: third-filter !default;\n",
            $updated
        );
        self::assertSame(
            1,
            substr_count(
                $updated,
                '// <liquidstack-core:scss-color-contract>'
            )
        );
    }

    public function testPreservesCrLfAndAppendsInsideAnExistingBlock(): void
    {
        $original = "\$color00: #fff;\r\n"
            . "\r\n"
            . "// <liquidstack-core:scss-color-contract>\r\n"
            . "\$color02SVG: existing !default;\r\n"
            . "// </liquidstack-core:scss-color-contract>\r\n"
            . ".after { color: \$color00; }\r\n";
        $this->writeFile($this->configPath, $original);
        $this->writeContract([
            [
                'name' => 'color02SVG',
                'value' => 'replacement-must-not-win',
            ],
            [
                'name' => 'color03SVG',
                'value' => 'new-filter',
            ],
        ]);

        $synchronizer = $this->synchronizer();
        $added = $synchronizer->sync(
            $this->configPath,
            $this->contractPath
        );
        $updated = (string) file_get_contents($this->configPath);

        self::assertSame(1, $added);
        self::assertTrue($synchronizer->wasSuccessful());
        self::assertSame(
            str_replace(
                "// </liquidstack-core:scss-color-contract>\r\n",
                "\$color03SVG: new-filter !default;\r\n"
                    . "// </liquidstack-core:scss-color-contract>\r\n",
                $original
            ),
            $updated
        );
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $updated);
    }

    public function testIsIdempotent(): void
    {
        $this->writeFile($this->configPath, "\$color00: #fff;\n");
        $this->writeContract([
            [
                'name' => 'color02SVG',
                'value' => 'filter-value',
            ],
        ]);
        $synchronizer = $this->synchronizer();

        self::assertSame(
            1,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );
        $first = (string) file_get_contents($this->configPath);

        self::assertSame(
            0,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );
        self::assertTrue($synchronizer->wasSuccessful());
        self::assertSame(
            $first,
            file_get_contents($this->configPath)
        );
    }

    public function testCommentedDeclarationsDoNotSatisfyTheContract(): void
    {
        $this->writeFile(
            $this->configPath,
            "/* \$color03: old-value; */\n"
                . "// \$color04: old-value;\n"
        );
        $this->writeContract([
            [
                'name' => 'color03',
                'value' => '#123456',
            ],
            [
                'name' => 'color04',
                'value' => '#abcdef',
            ],
        ]);

        $synchronizer = $this->synchronizer();

        self::assertSame(
            2,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );
        self::assertTrue($synchronizer->wasSuccessful());

        $updated = (string) file_get_contents($this->configPath);

        self::assertStringContainsString(
            '$color03: #123456 !default;',
            $updated
        );
        self::assertStringContainsString(
            '$color04: #abcdef !default;',
            $updated
        );
    }

    public function testLegacyAliasAfterManagedBlockUsesSafeDefault(): void
    {
        $this->writeFile(
            $this->configPath,
            "// <liquidstack-core:scss-color-contract>\n"
                . "// </liquidstack-core:scss-color-contract>\n"
                . "\$filterColor02: project-filter;\n"
        );
        $this->writeContract([
            [
                'name' => 'color02SVG',
                'value' => 'safe-filter',
                'legacy_alias' => 'filterColor02',
            ],
        ]);

        $synchronizer = $this->synchronizer();

        self::assertSame(
            1,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );

        $updated = (string) file_get_contents($this->configPath);

        self::assertStringContainsString(
            '$color02SVG: safe-filter !default;',
            $updated
        );
        self::assertStringNotContainsString(
            '$color02SVG: $filterColor02',
            $updated
        );
    }

    public function testMissingAndInvalidFilesPreserveTheConfig(): void
    {
        $original = "\$color00: custom;\n";
        $this->writeFile($this->configPath, $original);

        $synchronizer = $this->synchronizer();
        self::assertSame(
            0,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );
        self::assertFalse($synchronizer->wasSuccessful());
        self::assertSame(
            $original,
            file_get_contents($this->configPath)
        );
        self::assertStringContainsString(
            'no es un fichero regular',
            $this->io->getOutput()
        );

        $this->io = new BufferIO();
        $this->writeFile($this->contractPath, '{"schema":2,');

        $synchronizer = $this->synchronizer();
        self::assertSame(
            0,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );
        self::assertFalse($synchronizer->wasSuccessful());
        self::assertSame(
            $original,
            file_get_contents($this->configPath)
        );
        self::assertStringContainsString(
            'contrato JSON es inválido',
            $this->io->getOutput()
        );
    }

    public function testMalformedMarkersPreserveTheConfig(): void
    {
        $original = "\$color00: custom;\n"
            . "// <liquidstack-core:scss-color-contract>\n";
        $this->writeFile($this->configPath, $original);
        $this->writeContract([
            [
                'name' => 'color02SVG',
                'value' => 'filter-value',
            ],
        ]);

        $synchronizer = $this->synchronizer();
        self::assertSame(
            0,
            $synchronizer->sync(
                $this->configPath,
                $this->contractPath
            )
        );
        self::assertFalse($synchronizer->wasSuccessful());
        self::assertSame(
            $original,
            file_get_contents($this->configPath)
        );
        self::assertStringContainsString(
            'marcadores incompletos o desordenados',
            $this->io->getOutput()
        );
    }

    public function testSymlinkConfigAndContractArePreserved(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() no está disponible.');
        }

        $realConfig = $this->root . '/real-config.scss';
        $realContract = $this->root . '/real-contract.json';
        $configLink = $this->root . '/config-link.scss';
        $contractLink = $this->root . '/contract-link.json';
        $original = "\$color00: custom;\n";

        $this->writeFile($realConfig, $original);
        $this->writeFile(
            $realContract,
            json_encode(
                [
                    'schema' => 2,
                    'additive_variables' => [],
                ],
                JSON_THROW_ON_ERROR
            )
        );

        if (
            !@symlink($realConfig, $configLink)
            || !@symlink($realContract, $contractLink)
        ) {
            self::markTestSkipped(
                'El entorno no permite crear enlaces simbólicos.'
            );
        }

        self::assertSame(
            0,
            $this->synchronizer()->sync(
                $configLink,
                $realContract
            )
        );
        self::assertSame(
            0,
            $this->synchronizer()->sync(
                $realConfig,
                $contractLink
            )
        );
        self::assertSame(
            $original,
            file_get_contents($realConfig)
        );
        self::assertStringContainsString(
            'enlace simbólico',
            $this->io->getOutput()
        );
    }

    /**
     * @param list<array<string, string>> $variables
     */
    private function writeContract(array $variables): void
    {
        $this->writeFile(
            $this->contractPath,
            json_encode(
                [
                    'schema' => 2,
                    'additive_variables' => $variables,
                ],
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    private function synchronizer(): ScssConfigContractSynchronizer
    {
        return new ScssConfigContractSynchronizer($this->io);
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile($path, $contents);
    }
}
