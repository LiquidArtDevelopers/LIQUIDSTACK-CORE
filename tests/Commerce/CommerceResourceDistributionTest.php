<?php

declare(strict_types=1);

use App\Core\Composer\ManagedFileSynchronizer;
use App\Core\Composer\ModuleProjectFileSynchronizer;
use App\Core\Commerce\CommercePublicMediaReference;
use App\Core\Commerce\CommercePublicProduct;
use App\Core\Commerce\LocalizedProduct;
use App\Core\Commerce\ProductAvailabilityStatus;
use App\Core\Commerce\ProductEditorialStatus;
use App\Core\Modules\ModuleCatalog;
use App\Core\Modules\ModuleSelection;
use Composer\IO\BufferIO;
use Composer\Util\Filesystem as ComposerFilesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CommerceResourceDistributionTest extends TestCase
{
    private string $root;
    private string $project;
    private string $temporary;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->temporary = sys_get_temp_dir()
            . '/liquidstack-commerce-resources-'
            . bin2hex(random_bytes(8));
        $this->project = $this->temporary . '/project';
        (new Filesystem())->mkdir($this->project);
    }

    protected function tearDown(): void
    {
        (new ComposerFilesystem())->removeDirectory($this->temporary);
    }

    public function testManifestDeclaresACompleteRouteNeutralResourceFamily(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(
                $this->root . '/modules/commerce/module.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame([
            'artCommerceItem01',
            'sectionCommerceCatalog01',
            'sectionCommerceInquiry01',
        ], $manifest['resources']);
        self::assertNotEmpty($manifest['project_files']);

        $targets = [];
        foreach ($manifest['project_files'] as $entry) {
            self::assertFileExists(
                $this->root . '/modules/commerce/' . $entry['source']
            );
            self::assertNotContains($entry['target'], $targets);
            $targets[] = $entry['target'];
        }

        self::assertNotContains('App/config/routes/get.php', $targets);
        self::assertNotContains('App/config/rutas.js', $targets);
        self::assertContains('App/views/commerce.php', $targets);
        self::assertContains(
            'App/config/languages/commerce/en.json',
            $targets
        );
        self::assertContains('App/views/commerce-item.php', $targets);
        self::assertContains('App/views/commerce-inquiry.php', $targets);
        self::assertContains(
            'App/app/commerce/CommercePresentationAdapter.php',
            $targets
        );
    }

    public function testPublicBridgeUsesCoreAndKeepsFixturesExplicit(): void
    {
        $projectResources = $this->root
            . '/modules/commerce/resources/project';
        $bridge = (string) file_get_contents(
            $projectResources . '/App/app/_moduleCommercePublic.php'
        );
        $adapter = (string) file_get_contents(
            $projectResources
                . '/App/app/commerce/CommercePresentationAdapter.php'
        );
        $item = (string) file_get_contents(
            $projectResources . '/App/controllers/artCommerceItem01.php'
        );
        $catalog = (string) file_get_contents(
            $projectResources
                . '/App/controllers/sectionCommerceCatalog01.php'
        );
        $javascript = (string) file_get_contents(
            $projectResources . '/src/js/resources/_commerce.js'
        );

        self::assertStringContainsString(
            'CommercePublicHttpRuntimeFactory',
            $bridge
        );
        self::assertStringContainsString(
            'basketCookieName',
            $bridge
        );
        self::assertStringContainsString("\$commerceItemPage", $bridge);
        self::assertStringContainsString("\$commerceEnvFlag('DEV_MODE')", $bridge);
        self::assertStringContainsString(
            'LIQUIDSTACK_COMMERCE_DEVELOPMENT_FIXTURES',
            $bridge
        );
        self::assertStringContainsString(
            'CommercePublicItemPageAdapter',
            $adapter
        );
        self::assertStringContainsString(
            'CommerceCorePresentationAdapter',
            $adapter
        );
        self::assertStringContainsString('catalogPage(', $adapter);
        self::assertStringContainsString('altText()', $adapter);
        self::assertStringContainsString('acceptsInquiries()', $adapter);
        self::assertStringContainsString('inquiryCount()', $adapter);
        self::assertStringContainsString("'{count}'", $item);
        self::assertStringNotContainsString(' usuarios ', $item);
        self::assertStringContainsString('disabled aria-disabled', $item);
        self::assertStringContainsString('disabled aria-disabled', $catalog);
        self::assertStringNotContainsString('localStorage', $javascript);
        self::assertStringNotContainsString('sessionStorage', $javascript);
        self::assertStringNotContainsString('document.cookie', $javascript);
    }

    public function testDistributedConfigDerivesRoutesFromActiveLanguages(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->project . '/App/config/modules');
        $filesystem->dumpFile(
            $this->project . '/App/config/langs.php',
            "<?php return ['es', 'eu', 'en', 'fr'];\n"
        );
        $filesystem->copy(
            $this->root
                . '/modules/commerce/resources/project/App/config/modules/'
                . 'commerce.php',
            $this->project . '/App/config/modules/commerce.php'
        );

        $config = require $this->project
            . '/App/config/modules/commerce.php';

        self::assertFalse($config['public']['enabled']);
        self::assertSame('/es/comercio', $config['public_paths']['es']);
        self::assertSame('/eu/merkataritza', $config['public_paths']['eu']);
        self::assertSame('/en/commerce', $config['public_paths']['en']);
        self::assertSame('/fr/commerce', $config['public_paths']['fr']);
        self::assertSame(
            '/fr/commerce/interest-list',
            $config['inquiry_paths']['fr']
        );
    }

    public function testModuleSyncPublishesResourcesAndPreservesProjectConfig(): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile(
            $this->project . '/App/config/modules/commerce.php',
            "<?php\nreturn ['project' => true];\n"
        );
        $filesystem->dumpFile(
            $this->project . '/App/config/languages/commerce/es.json',
            '{"title":{"text":"Proyecto"}}'
        );
        $history = $this->temporary . '/history.json';
        $filesystem->dumpFile(
            $history,
            "{\n  \"schema\": 1,\n  \"files\": []\n}\n"
        );

        $sync = $this->synchronize($history);

        self::assertSame([], $sync->blockers());
        self::assertSame(
            "<?php\nreturn ['project' => true];\n",
            file_get_contents(
                $this->project . '/App/config/modules/commerce.php'
            )
        );
        $catalog = json_decode(
            (string) file_get_contents(
                $this->project
                    . '/App/config/languages/commerce/es.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('Proyecto', $catalog['title']['text']);
        self::assertArrayHasKey('commerce_action_add', $catalog);
        self::assertFileExists(
            $this->project . '/App/config/languages/commerce/en.json'
        );
        self::assertFileExists(
            $this->project . '/App/views/commerce-item.php'
        );
        self::assertFileExists(
            $this->project . '/src/js/resources/_commerce.js'
        );
        self::assertFileExists(
            $this->project
                . '/src/scss/resources/_sectionCommerceCatalog01.scss'
        );

        $second = $this->synchronize($history);
        self::assertSame([], $second->blockers());
        self::assertSame('Proyecto', json_decode(
            (string) file_get_contents(
                $this->project
                    . '/App/config/languages/commerce/es.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        )['title']['text']);
    }

    public function testPresentationAdapterConsumesTheTypedPublicProjection(): void
    {
        require_once $this->root
            . '/modules/commerce/resources/project/App/app/commerce/'
            . 'CommercePresentationAdapter.php';

        $product = new CommercePublicProduct(
            new LocalizedProduct(
                '10000000-0000-4000-8000-000000000001',
                'REF-1',
                'en',
                'en',
                false,
                'Example product',
                'example-product',
                'Example summary',
                'Example description',
                null,
                null,
                ProductEditorialStatus::ACTIVE,
                ProductAvailabilityStatus::SOLD,
                null,
                '/en/commerce/example-product',
                1
            ),
            [],
            [],
            [],
            [new CommercePublicMediaReference(
                '20000000-0000-4000-8000-000000000002',
                'cover',
                0,
                'en',
                'en',
                false,
                'Localized alternative text',
                'Localized caption',
                [[
                    'width' => 640,
                    'height' => 480,
                    'path' => '/_liquidstack/commerce/media/'
                        . '20000000-0000-4000-8000-000000000002/640.avif',
                ]]
            )]
        );
        $view = \App\CommercePresentation\CommercePublicProductAdapter::adapt(
            $product,
            static fn (string $key): string => match ($key) {
                'commerce_availability_sold' => 'Sold',
                'commerce_commercial_inquiry' => 'Price on request',
                default => $key,
            }
        );

        self::assertNotNull($view);
        self::assertSame('Localized alternative text', $view->imageAlt());
        self::assertSame('Localized caption', $view->imageTitle());
        self::assertFalse($view->acceptsInquiries());
        self::assertCount(1, $view->imageVariants());
    }

    private function synchronize(
        string $history
    ): ManagedFileSynchronizer {
        $io = new BufferIO();
        $catalog = ModuleCatalog::fromModulesRoot($this->root . '/modules');
        $selection = ModuleSelection::fromRequirementNames(
            $catalog,
            ['liquidstack/commerce']
        );
        $sync = new ManagedFileSynchronizer(
            $this->project,
            $this->root,
            $io,
            $history,
            $this->project . '/.liquidstack/core/managed-files.json'
        );
        (new ModuleProjectFileSynchronizer(
            $this->project,
            $io
        ))->queue($selection, $sync);
        $sync->apply();

        return $sync;
    }
}
