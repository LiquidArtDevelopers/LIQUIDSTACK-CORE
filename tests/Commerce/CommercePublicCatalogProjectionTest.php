<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\AttributeDefinitionDraft;
use App\Core\Commerce\AttributeValueDraft;
use App\Core\Commerce\CategoryLocalizationDraft;
use App\Core\Commerce\CommerceAttributeType;
use App\Core\Commerce\CommerceCatalogService;
use App\Core\Commerce\CommercePublicCatalogQuery;
use App\Core\Commerce\CommercePublicTaxonomyTerm;
use App\Core\Commerce\Media\CommercePublicMediaDelivery;
use App\Core\Commerce\Media\PdoCommercePublicMediaRepository;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceCatalogRepository;
use App\Core\Commerce\ProductLocalizationDraft;
use App\Core\Commerce\TagLocalizationDraft;
use App\Core\Modules\Commerce\CommerceMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Media\MediaFileMetadata;
use App\Core\WebAdmin\Media\MediaStorageInterface;
use App\Core\WebAdmin\Media\MediaStoredVariant;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommercePublicCatalogProjectionTest extends TestCase
{
    private PDO $pdo;
    private PdoCommerceCatalogRepository $repository;
    private CommerceCatalogService $catalog;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $webAdminMigrations = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        );
        $this->apply($webAdminMigrations[0], $webAdminScope);
        $this->apply($webAdminMigrations[1], $webAdminScope);

        $commerceScope = MigrationScope::forTablePrefix(
            'commerce',
            'ls_commerce_'
        );
        $commerceMigrations = iterator_to_array(
            CommerceMigrationProvider::migrations(),
            false
        );
        $this->apply($commerceMigrations[0], $commerceScope);
        $this->apply($commerceMigrations[1], $commerceScope);

        $this->repository = new PdoCommerceCatalogRepository(
            $this->pdo,
            CommerceTableNames::fromPdo($this->pdo, 'ls_commerce_'),
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_')
        );
        $this->catalog = new CommerceCatalogService(
            $this->repository,
            'es',
            ['es', 'eu']
        );
        $this->now = new DateTimeImmutable(
            '2033-05-06 10:00:00.000000',
            new DateTimeZone('UTC')
        );
    }

    public function testSearchFiltersAndDetailUseLocalizedFallbacksAndWebAdminMedia(): void
    {
        $root = $this->catalog->createCategory(
            CategoryLocalizationDraft::source(
                'es',
                'Furgonetas',
                'furgonetas'
            ),
            null,
            $this->now
        );
        $child = $this->catalog->createCategory(
            CategoryLocalizationDraft::source('es', 'Camper', 'camper'),
            $root,
            $this->now
        );
        $this->catalog->saveCategoryLocalization(
            $child,
            CategoryLocalizationDraft::translated(
                'eu',
                'Autokarabanak',
                'autokarabanak'
            ),
            1,
            $this->now
        );
        $tag = $this->catalog->createTag(
            TagLocalizationDraft::source('es', 'OcasiÃ³n', 'ocasion'),
            $this->now
        );
        $product = $this->catalog->createProduct(
            ProductLocalizationDraft::source(
                'es',
                'Volkswagen California Ocean',
                'volkswagen-california-ocean',
                'Camper revisada y lista para viajar.',
                'Ficha completa con equipamiento camper.'
            ),
            'VAN-001',
            null,
            $this->now
        );
        $this->catalog->assignCategories(
            $product->publicId(),
            [$root, $child],
            $child,
            $this->now
        );
        $this->catalog->assignTags(
            $product->publicId(),
            [$tag],
            $this->now
        );

        $kilometres = $this->catalog->defineAttribute(
            new AttributeDefinitionDraft(
                'kilometres',
                CommerceAttributeType::NUMBER,
                'es',
                'KilÃ³metros',
                $child,
                'km',
                true
            ),
            $this->now
        );
        $featured = $this->catalog->defineAttribute(
            new AttributeDefinitionDraft(
                'featured',
                CommerceAttributeType::BOOLEAN,
                'es',
                'Destacada',
                $child,
                null,
                false,
                1
            ),
            $this->now
        );
        $fuel = $this->catalog->defineAttribute(
            new AttributeDefinitionDraft(
                'fuel',
                CommerceAttributeType::SELECT,
                'es',
                'Combustible',
                $child,
                null,
                true,
                2
            ),
            $this->now
        );
        $notes = $this->catalog->defineAttribute(
            new AttributeDefinitionDraft(
                'equipment_notes',
                CommerceAttributeType::TEXT,
                'es',
                'Equipamiento',
                $child,
                null,
                false,
                3
            ),
            $this->now
        );
        $diesel = $this->catalog->addAttributeOption(
            $fuel,
            'diesel',
            0,
            ['es' => 'DiÃ©sel'],
            $this->now
        );
        $this->catalog->setAttributeValue(
            $product->publicId(),
            $kilometres,
            AttributeValueDraft::number('120000'),
            $this->now
        );
        $this->catalog->setAttributeValue(
            $product->publicId(),
            $featured,
            AttributeValueDraft::boolean(true),
            $this->now
        );
        $this->catalog->setAttributeValue(
            $product->publicId(),
            $fuel,
            AttributeValueDraft::select($diesel),
            $this->now
        );
        $this->catalog->setAttributeValue(
            $product->publicId(),
            $notes,
            AttributeValueDraft::text('es', 'Techo elevable'),
            $this->now
        );
        $lockVersion = (int) $this->pdo->query(
            'SELECT lock_version FROM ls_commerce_products'
        )->fetchColumn();
        $this->catalog->activateProduct(
            $product->publicId(),
            $lockVersion,
            ['es' => '/es/comercio', 'eu' => '/eu/merkataritza'],
            $this->now
        );
        $this->insertMedia($product->publicId());

        $page = $this->repository->searchPublished(
            'eu',
            'es',
            new CommercePublicCatalogQuery(
                'California',
                'furgonetas',
                'ocasion',
                12
            )
        );

        self::assertFalse($page->hasNext());
        self::assertCount(1, $page->items());
        $projection = $page->items()[0];
        self::assertTrue($projection->product()->isFallback());
        self::assertSame('es', $projection->product()->resolvedLocale());
        self::assertSame(
            '/eu/merkataritza/furgonetas/autokarabanak/volkswagen-california-ocean',
            $projection->product()->publicPath()
        );

        self::assertCount(2, $projection->categories());
        self::assertSame('furgonetas', $projection->categories()[1]->slug());
        self::assertTrue($projection->categories()[1]->isFallback());
        self::assertSame('autokarabanak', $projection->categories()[0]->slug());
        self::assertTrue($projection->categories()[0]->isCanonical());
        self::assertCount(1, $projection->tags());
        self::assertSame('ocasion', $projection->tags()[0]->slug());
        self::assertTrue($projection->tags()[0]->isFallback());

        $attributes = [];
        foreach ($projection->attributes() as $attribute) {
            $attributes[$attribute->code()] = $attribute;
        }
        self::assertSame('120000', $attributes['kilometres']->value());
        self::assertTrue($attributes['featured']->value());
        self::assertSame(
            'DiÃ©sel',
            $attributes['fuel']->value()[0]->label()
        );
        self::assertSame(
            'Techo elevable',
            $attributes['equipment_notes']->value()
        );
        self::assertTrue($attributes['equipment_notes']->isFallback());
        self::assertSame(
            'es',
            $attributes['equipment_notes']->valueResolvedLocale()
        );
        self::assertTrue(
            $attributes['equipment_notes']->isValueFallback()
        );

        $cover = $projection->cover();
        self::assertNotNull($cover);
        self::assertSame('Exterior principal', $cover->altText());
        self::assertSame('Vista exterior', $cover->caption());
        self::assertTrue($cover->isFallback());
        self::assertSame('es', $cover->resolvedLocale());
        self::assertSame([
            [
                'width' => 480,
                'height' => 320,
                'path' => '/_liquidstack/commerce/media/'
                    . '99999999-9999-4999-8999-999999999999/480.avif',
            ],
            [
                'width' => 900,
                'height' => 600,
                'path' => '/_liquidstack/commerce/media/'
                    . '99999999-9999-4999-8999-999999999999/900.avif',
            ],
        ], $cover->variants());

        self::assertSame([], $this->repository->searchPublished(
            'eu',
            'es',
            new CommercePublicCatalogQuery('California', null, 'berria')
        )->items());
        self::assertSame([], $this->repository->searchPublished(
            'eu',
            'es',
            new CommercePublicCatalogQuery('No existe')
        )->items());

        $categories = $this->repository->publicTaxonomyTerms(
            'eu',
            'es',
            CommercePublicTaxonomyTerm::CATEGORY
        );
        self::assertCount(2, $categories);
        $categoriesBySlug = [];
        foreach ($categories as $category) {
            $categoriesBySlug[$category->slug()] = $category;
        }
        self::assertSame(
            $categoriesBySlug['furgonetas']->publicId(),
            $categoriesBySlug['autokarabanak']->parentPublicId()
        );
        $tags = $this->repository->publicTaxonomyTerms(
            'eu',
            'es',
            CommercePublicTaxonomyTerm::TAG
        );
        self::assertCount(1, $tags);
        self::assertSame('ocasion', $tags[0]->slug());

        $mediaRepository = new PdoCommercePublicMediaRepository(
            $this->pdo,
            CommerceTableNames::fromPdo($this->pdo, 'ls_commerce_'),
            WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_')
        );
        $storage = $this->createMock(MediaStorageInterface::class);
        $storage->expects(self::once())
            ->method('probeVerified')
            ->with(self::callback(
                static fn (MediaStoredVariant $variant): bool =>
                    $variant->width() === 900
                    && $variant->height() === 600
                    && $variant->bytes() === 512
            ))
            ->willReturn(new MediaFileMetadata(900, 600, 512));
        $file = (new CommercePublicMediaDelivery(
            $mediaRepository,
            $storage
        ))->file(
            '99999999-9999-4999-8999-999999999999',
            640,
            true
        );
        self::assertNotNull($file);
        self::assertSame(512, $file->bytes());

        $this->pdo->exec(
            "UPDATE ls_commerce_products SET editorial_status = 'inactive'"
        );
        self::assertNull($mediaRepository->publishedVariant(
            '99999999-9999-4999-8999-999999999999',
            640
        ));
    }

    private function insertMedia(string $productPublicId): void
    {
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
                . '(public_id, email_canonical, status) VALUES '
                . "('01234567-89ab-4cde-8f01-23456789abc0', "
                . "'media@example.test', 'active')"
        );
        $this->pdo->exec(
            'INSERT INTO ls_webadmin_media_assets ('
                . 'public_id, label, source_mime, source_width, source_height, '
                . 'source_bytes, source_sha256, created_by_user_id) VALUES ('
                . "'99999999-9999-4999-8999-999999999999', "
                . "'Exterior principal', 'image/jpeg', 1800, 1200, 2048, '"
                . str_repeat('a', 64) . "', 1)"
        );
        foreach ([[480, 320], [900, 600]] as [$width, $height]) {
            $this->pdo->exec(
                'INSERT INTO ls_webadmin_media_variants ('
                    . 'asset_id, width, height, bytes, sha256, storage_key, mime) '
                    . "VALUES (1, {$width}, {$height}, 512, '"
                    . str_repeat((string) ($width === 480 ? 'b' : 'c'), 64)
                    . "', '99/99999999-9999-4999-8999-999999999999/"
                    . "{$width}.avif', 'image/avif')"
            );
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_commerce_product_media ('
                . 'product_id, media_asset_public_id, role, sort_order) '
                . 'SELECT id, :media, :role, 0 FROM ls_commerce_products '
                . 'WHERE public_id = :product'
        );
        $statement->execute([
            'media' => '99999999-9999-4999-8999-999999999999',
            'role' => 'cover',
            'product' => $productPublicId,
        ]);
        $this->pdo->exec(
            'INSERT INTO ls_commerce_product_media_localizations '
                . '(media_id, locale, alt_text, caption, translation_status) '
                . "SELECT id, 'es', 'Exterior principal', 'Vista exterior', "
                . "'source' FROM ls_commerce_product_media"
        );
        $this->pdo->exec(
            'INSERT INTO ls_commerce_product_media_localizations '
                . '(media_id, locale, alt_text, caption, translation_status) '
                . "SELECT id, 'eu', NULL, NULL, 'fallback' "
                . 'FROM ls_commerce_product_media'
        );
    }

    private function apply(
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            $this->pdo->exec($sql);
        }
    }
}
