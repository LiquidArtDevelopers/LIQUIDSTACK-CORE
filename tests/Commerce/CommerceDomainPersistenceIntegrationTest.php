<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\AttributeDefinitionDraft;
use App\Core\Commerce\AttributeValueDraft;
use App\Core\Commerce\CategoryLocalizationDraft;
use App\Core\Commerce\CommerceAttributeType;
use App\Core\Commerce\CommerceBasketService;
use App\Core\Commerce\CommerceCatalogService;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInquiryService;
use App\Core\Commerce\InquiryContact;
use App\Core\Commerce\Money;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceBasketRepository;
use App\Core\Commerce\Persistence\PdoCommerceCatalogRepository;
use App\Core\Commerce\Persistence\PdoCommerceInquiryRepository;
use App\Core\Commerce\ProductLocalizationDraft;
use App\Core\Commerce\TagLocalizationDraft;
use App\Core\Modules\Commerce\CommerceMigrationProvider;
use App\Core\Modules\Migrations\MigrationScope;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommerceDomainPersistenceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private CommerceCatalogService $catalog;
    private CommerceBasketService $baskets;
    private CommerceInquiryService $inquiries;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('commerce', 'ls_commerce_');
        $migrations = iterator_to_array(CommerceMigrationProvider::migrations(), false);
        foreach (array_slice($migrations, 0, 2) as $migration) {
            foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
                $this->pdo->exec($sql);
            }
        }
        $tables = CommerceTableNames::fromPdo($this->pdo, 'ls_commerce_');
        $repository = new PdoCommerceCatalogRepository($this->pdo, $tables);
        $this->catalog = new CommerceCatalogService($repository, 'es', ['es', 'eu']);
        $basketRepository = new PdoCommerceBasketRepository(
            $this->pdo,
            $tables,
            $repository
        );
        $this->baskets = new CommerceBasketService($basketRepository, 'es');
        $this->inquiries = new CommerceInquiryService(
            new PdoCommerceInquiryRepository(
                $this->pdo,
                $tables,
                $repository,
                'ventas@example.test'
            ),
            'es'
        );
    }

    public function testCompleteInquiryCatalogVerticalIsLocalizedAndIdempotent(): void
    {
        $now = $this->utc('2032-04-05 10:00:00.000000');
        $rootCategory = $this->catalog->createCategory(
            CategoryLocalizationDraft::source('es', 'Furgonetas', 'furgonetas'),
            null,
            $now
        );
        $childCategory = $this->catalog->createCategory(
            CategoryLocalizationDraft::source('es', 'Camper', 'camper'),
            $rootCategory,
            $now
        );
        $this->catalog->saveCategoryLocalization(
            $childCategory,
            CategoryLocalizationDraft::translated('eu', 'Autokarabanak', 'autokarabanak'),
            1,
            $now
        );

        try {
            $this->catalog->setCategoryParent($rootCategory, $childCategory, 1, $now);
            self::fail('A nested category cycle must be rejected.');
        } catch (CommerceConflictException $exception) {
            self::assertSame(CommerceConflictException::CATEGORY_CYCLE, $exception->kind());
        }

        $product = $this->catalog->createProduct(
            ProductLocalizationDraft::source(
                'es',
                'Volkswagen California',
                'volkswagen-california',
                'Camper lista para viajar',
                'Ficha completa del vehiculo.'
            ),
            'VAN-001',
            new Money(5_990_000, 'EUR'),
            $now
        );
        $productId = $product->publicId();
        $fallback = $this->catalog->product($productId, 'eu');
        self::assertNotNull($fallback);
        self::assertTrue($fallback->isFallback());
        self::assertSame('es', $fallback->resolvedLocale());
        self::assertSame('Volkswagen California', $fallback->title());
        $storedFallback = $this->pdo->query(
            "SELECT title, slug FROM ls_commerce_product_localizations WHERE locale = 'eu'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['title' => null, 'slug' => null], $storedFallback);

        $this->catalog->assignCategories(
            $productId,
            [$rootCategory, $childCategory],
            $childCategory,
            $now
        );
        $tag = $this->catalog->createTag(
            TagLocalizationDraft::source('es', 'Ocasión', 'ocasion'),
            $now
        );
        $this->catalog->saveTagLocalization(
            $tag,
            TagLocalizationDraft::translated('eu', 'Aukera', 'aukera'),
            $now
        );
        $this->catalog->assignTags($productId, [$tag], $now);

        $kilometres = $this->catalog->defineAttribute(
            new AttributeDefinitionDraft(
                'kilometres',
                CommerceAttributeType::NUMBER,
                'es',
                'Kilómetros',
                $childCategory,
                'km',
                true
            ),
            $now
        );
        $fuel = $this->catalog->defineAttribute(
            new AttributeDefinitionDraft(
                'fuel',
                CommerceAttributeType::SELECT,
                'es',
                'Combustible',
                $childCategory,
                null,
                true,
                1
            ),
            $now
        );
        $diesel = $this->catalog->addAttributeOption(
            $fuel,
            'diesel',
            0,
            ['es' => 'Diésel'],
            $now
        );
        $this->catalog->setAttributeValue(
            $productId,
            $kilometres,
            AttributeValueDraft::number('120000'),
            $now
        );
        $this->catalog->setAttributeValue(
            $productId,
            $fuel,
            AttributeValueDraft::select($diesel),
            $now
        );
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_product_attribute_values'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_product_attribute_value_options'
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ls_commerce_attribute_option_localizations "
                . "WHERE locale = 'eu' AND label IS NULL AND translation_status = 'fallback'"
        )->fetchColumn());

        $active = $this->catalog->activateProduct(
            $productId,
            5,
            ['es' => '/vehiculos', 'eu' => '/ibilgailuak'],
            $now
        );
        self::assertSame(
            '/vehiculos/furgonetas/camper/volkswagen-california',
            $active->publicPath()
        );
        $eu = $this->catalog->product($productId, 'eu');
        self::assertNotNull($eu);
        self::assertSame(
            '/ibilgailuak/furgonetas/autokarabanak/volkswagen-california',
            $eu->publicPath()
        );
        self::assertSame([$productId], array_map(
            static fn ($listed): string => $listed->publicId(),
            $this->catalog->listPublished('eu')
        ));

        $translated = $this->catalog->saveProductLocalization(
            $productId,
            ProductLocalizationDraft::translated(
                'eu',
                'Volkswagen California euskaraz',
                'volkswagen-california-eu'
            ),
            6,
            $now
        );
        self::assertFalse($translated->isFallback());
        $oldEuPath = (string) $translated->publicPath();
        $newEuPath = $this->catalog->refreshPublicPath(
            $productId,
            'eu',
            '/ibilgailuak',
            $now
        );
        self::assertSame(
            '/ibilgailuak/furgonetas/autokarabanak/volkswagen-california-eu',
            $newEuPath
        );
        $redirect = $this->catalog->resolvePublicPath($oldEuPath, 'eu');
        self::assertNotNull($redirect);
        self::assertTrue($redirect->isRedirect());
        self::assertSame($newEuPath, $redirect->currentPath());

        $productInternalId = (int) $this->pdo->query(
            "SELECT id FROM ls_commerce_products WHERE public_id = "
                . $this->pdo->quote($productId)
        )->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO ls_commerce_product_media '
                . '(product_id, media_asset_public_id, role, sort_order, created_at, updated_at) '
                . 'VALUES (:product, :media, :role, 0, :created, :updated)'
        )->execute([
            'product' => $productInternalId,
            'media' => '99999999-9999-4999-8999-999999999999',
            'role' => 'cover',
            'created' => $now->format('Y-m-d H:i:s.u'),
            'updated' => $now->format('Y-m-d H:i:s.u'),
        ]);

        $basket = $this->baskets->create('eu', $now);
        $basket = $this->baskets->put($basket->token(), $productId, 1, $now);
        $basket = $this->baskets->put($basket->token(), $productId, 1, $now);
        self::assertCount(1, $basket->lines());
        self::assertSame(1, $basket->lines()[0]->quantity());

        $operationId = '88888888-8888-4888-8888-888888888888';
        $contact = new InquiryContact('Ane Bidaiari', 'ANE@example.test', '600123123');
        $result = $this->inquiries->submit(
            $operationId,
            $basket->token(),
            $contact,
            'privacy-2032-01',
            $now
        );
        self::assertFalse($result->replayed());
        self::assertSame(1, $result->lineCount());
        $replayed = $this->inquiries->submit(
            $operationId,
            $basket->token(),
            $contact,
            'privacy-2032-01',
            $now
        );
        self::assertTrue($replayed->replayed());
        self::assertSame($result->publicId(), $replayed->publicId());
        self::assertSame(1, $this->catalog->inquiryCount($productId));
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_inquiry_lines'
        )->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_inquiry_outbox'
        )->fetchColumn());
        self::assertSame(
            [
                ['audience' => 'admin', 'recipient_email' => 'ventas@example.test'],
                ['audience' => 'requester', 'recipient_email' => 'ane@example.test'],
            ],
            $this->pdo->query(
                'SELECT audience, recipient_email FROM ls_commerce_inquiry_outbox '
                    . 'ORDER BY audience ASC'
            )->fetchAll(PDO::FETCH_ASSOC)
        );
        self::assertSame($newEuPath, $this->pdo->query(
            'SELECT public_path FROM ls_commerce_inquiry_lines'
        )->fetchColumn());

        try {
            $this->inquiries->submit(
                $operationId,
                $basket->token(),
                new InquiryContact('Otra persona', 'otra@example.test'),
                'privacy-2032-01',
                $now
            );
            self::fail('An operation id cannot be reused with another payload.');
        } catch (CommerceConflictException $exception) {
            self::assertSame(
                CommerceConflictException::IDEMPOTENCY_MISMATCH,
                $exception->kind()
            );
        }
        self::assertSame(1, $this->catalog->inquiryCount($productId));
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_inquiry_outbox'
        )->fetchColumn());
    }

    private function utc(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC')
        );
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);

        return $parsed;
    }
}
