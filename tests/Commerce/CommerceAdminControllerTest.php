<?php

declare(strict_types=1);

use App\Core\Commerce\Admin\CommerceAdminReadRepository;
use App\Core\Commerce\Admin\CommerceAdminMediaRepository;
use App\Core\Commerce\Admin\CommerceAdminTaxonomyRepository;
use App\Core\Commerce\CommerceCapabilities;
use App\Core\Commerce\CommerceCatalogService;
use App\Core\Commerce\Configuration\CommerceConfig;
use App\Core\Commerce\Http\CommerceAdminHttpController;
use App\Core\Commerce\Http\CommerceAdminHttpRuntime;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\Commerce\Persistence\PdoCommerceCatalogRepository;
use App\Core\Http\Request;
use App\Core\Modules\Commerce\CommerceMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Navigation\WebAdminNavigationCatalog;
use App\Core\WebAdmin\Navigation\WebAdminNavigationItem;
use App\Core\WebAdmin\Media\MediaService;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use PHPUnit\Framework\TestCase;

final class CommerceAdminTestClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 10:00:00 UTC');
    }
}

final class CommerceAdminControllerTest extends TestCase
{
    private PDO $pdo;
    private CommerceAdminHttpController $controller;
    private string $session;
    private string $csrf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $webScope = MigrationScope::forTablePrefix('webadmin', 'ls_webadmin_');
        $commerceScope = MigrationScope::forTablePrefix('commerce', 'ls_commerce_');
        foreach (WebAdminMigrationProvider::migrations() as $migration) {
            $this->apply($migration, $webScope);
        }
        foreach (CommerceMigrationProvider::migrations() as $migration) {
            $this->apply(
                $migration,
                $migration->targetScopeModuleId() === 'webadmin'
                    ? $webScope : $commerceScope
            );
        }

        $clock = new CommerceAdminTestClock();
        $config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            3600,
            'test'
        );
        $key = SecurityKey::fromRawBytes(str_repeat('K', 32));
        $tokens = new SecureTokenGenerator();
        $this->session = rtrim(strtr(base64_encode(str_repeat('S', 32)), '+/', '-_'), '=');
        $this->csrf = $key->deriveToken('csrf.session', $this->session);
        $this->seedActor($tokens);
        $webTables = WebAdminTableNames::fromPdo($this->pdo, 'ls_webadmin_');
        $commerceTables = CommerceTableNames::fromPdo($this->pdo, 'ls_commerce_');
        $hasher = PasswordHasher::productive();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $webTables),
            $config,
            $key,
            $clock,
            new RandomUuidV4Generator(),
            $hasher,
            $tokens
        );
        $authorization = new WebAdminAuthorizationService(
            $this->pdo,
            $webTables,
            $clock,
            $tokens,
            $hasher
        );
        $commerceConfig = CommerceConfig::defaults(['es', 'eu']);
        $repository = new PdoCommerceCatalogRepository($this->pdo, $commerceTables);
        $mutationGate = new WebAdminMutationActorGate(
            $this->pdo,
            $webTables,
            $config,
            $key,
            $clock,
            $tokens,
            $hasher
        );
        $runtime = new CommerceAdminHttpRuntime(
            ['es', 'eu'],
            $commerceConfig,
            $config,
            new CommerceCatalogService($repository, 'es', ['es', 'eu']),
            new CommerceAdminReadRepository(
                $this->pdo,
                $commerceTables,
                $webTables,
                true
            ),
            $authentication,
            $authorization,
            new WebAdminNavigationCatalog([
                new WebAdminNavigationItem(
                    'commerce',
                    'Productos',
                    '/commerce',
                    CommerceCapabilities::PRODUCTS_VIEW
                ),
            ]),
            $this->pdo,
            $mutationGate,
            $clock,
            new CommerceAdminMediaRepository(
                $this->pdo,
                $commerceTables,
                $webTables,
                $mutationGate,
                true
            ),
            new CommerceAdminTaxonomyRepository(
                $this->pdo,
                $commerceTables,
                $mutationGate
            )
        );
        $this->controller = new CommerceAdminHttpController($runtime);
    }

    public function testProductAndTaxonomyFlowsUseSharedShellAndCsrf(): void
    {
        $empty = $this->controller->index($this->get('/admin/commerce'));
        self::assertSame(200, $empty->status());
        self::assertStringContainsString('<h1>Productos</h1>', $empty->body());
        self::assertSame(1, substr_count($empty->body(), '<main'));

        $denied = $this->productForm();
        $denied['csrf'] = str_repeat('X', 43);
        self::assertSame(403, $this->controller->create(
            $this->post('/admin/commerce/products/create', $denied)
        )->status());
        self::assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_products'
        )->fetchColumn());

        $created = $this->controller->create($this->post(
            '/admin/commerce/products/create',
            $this->productForm()
        ));
        self::assertSame(303, $created->status());
        self::assertStringStartsWith(
            '/admin/commerce/products/edit?product=',
            $created->headers()['Location']
        );
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_products'
        )->fetchColumn());

        $list = $this->controller->index($this->get('/admin/commerce'));
        self::assertStringContainsString('Furgoneta Matrix', $list->body());
        self::assertStringContainsString('12.345,67 EUR', $list->body());

        self::assertSame(303, $this->controller->createCategory($this->post(
            '/admin/commerce/taxonomies/categories/create',
            [
                'csrf' => $this->csrf,
                'name' => 'Furgonetas',
                'slug' => 'furgonetas',
                'parent' => '',
            ]
        ))->status());
        self::assertSame(303, $this->controller->createTag($this->post(
            '/admin/commerce/taxonomies/tags/create',
            [
                'csrf' => $this->csrf,
                'name' => 'Ocasión',
                'slug' => 'ocasion',
            ]
        ))->status());
        $taxonomies = $this->controller->taxonomies(
            $this->get('/admin/commerce/taxonomies')
        );
        self::assertSame(200, $taxonomies->status());
        self::assertStringContainsString('Furgonetas', $taxonomies->body());
        self::assertStringContainsString('Ocasión', $taxonomies->body());
        $category = (string) $this->pdo->query(
            'SELECT public_id FROM ls_commerce_categories LIMIT 1'
        )->fetchColumn();
        self::assertGreaterThanOrEqual(2, substr_count(
            $taxonomies->body(),
            '<option value="' . $category . '">Furgonetas</option>'
        ));
        self::assertStringContainsString('<td>ES</td>', $taxonomies->body());
        self::assertStringContainsString('<td>EU</td>', $taxonomies->body());
    }

    public function testLocalizedCatalogAttributesAndSharedMediaAreManageable(): void
    {
        self::assertSame(303, $this->controller->create($this->post(
            '/admin/commerce/products/create',
            $this->productForm()
        ))->status());
        $product = (string) $this->pdo->query(
            'SELECT public_id FROM ls_commerce_products LIMIT 1'
        )->fetchColumn();

        self::assertSame(303, $this->controller->createCategory($this->post(
            '/admin/commerce/taxonomies/categories/create',
            [
                'csrf' => $this->csrf,
                'name' => 'Furgonetas',
                'slug' => 'furgonetas',
                'parent' => '',
            ]
        ))->status());
        self::assertSame(303, $this->controller->createTag($this->post(
            '/admin/commerce/taxonomies/tags/create',
            [
                'csrf' => $this->csrf,
                'name' => 'Ocasion',
                'slug' => 'ocasion',
            ]
        ))->status());
        $category = (string) $this->pdo->query(
            'SELECT public_id FROM ls_commerce_categories LIMIT 1'
        )->fetchColumn();
        $tag = (string) $this->pdo->query(
            'SELECT public_id FROM ls_commerce_tags LIMIT 1'
        )->fetchColumn();
        $categoryVersion = (string) $this->pdo->query(
            'SELECT lock_version FROM ls_commerce_categories LIMIT 1'
        )->fetchColumn();

        self::assertSame(303, $this->controller->saveCategoryLocalization($this->post(
            '/admin/commerce/taxonomies/categories/localization/save',
            [
                'csrf' => $this->csrf,
                'category' => $category,
                'locale' => 'eu',
                'name' => 'Furgonetak',
                'slug' => 'furgonetak',
                'lock_version' => $categoryVersion,
            ]
        ))->status());
        $categoryVersion = (string) $this->pdo->query(
            'SELECT lock_version FROM ls_commerce_categories LIMIT 1'
        )->fetchColumn();
        self::assertSame(303, $this->controller->saveCategoryOrder($this->post(
            '/admin/commerce/taxonomies/categories/order/save',
            [
                'csrf' => $this->csrf,
                'category' => $category,
                'sort_order' => '7',
                'lock_version' => $categoryVersion,
            ]
        ))->status());
        self::assertSame('7', (string) $this->pdo->query(
            'SELECT sort_order FROM ls_commerce_categories LIMIT 1'
        )->fetchColumn());
        self::assertSame(303, $this->controller->saveTagLocalization($this->post(
            '/admin/commerce/taxonomies/tags/localization/save',
            [
                'csrf' => $this->csrf,
                'tag' => $tag,
                'locale' => 'eu',
                'name' => 'Aukera',
                'slug' => 'aukera',
            ]
        ))->status());

        self::assertSame(303, $this->controller->saveProductTaxonomies($this->post(
            '/admin/commerce/products/taxonomies/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'categories' => [$category],
                'canonical' => $category,
                'tags' => [$tag],
            ]
        ))->status());
        self::assertSame($category, (string) $this->pdo->query(
            'SELECT c.public_id FROM ls_commerce_products p INNER JOIN '
            . 'ls_commerce_categories c ON c.id = p.canonical_category_id LIMIT 1'
        )->fetchColumn());

        self::assertSame(303, $this->controller->createAttribute($this->post(
            '/admin/commerce/taxonomies/attributes/create',
            [
                'csrf' => $this->csrf,
                'code' => 'fuel',
                'type' => 'select',
                'category' => $category,
                'unit' => '',
                'filterable' => '1',
                'sort_order' => '0',
                'name' => 'Combustible',
            ]
        ))->status());
        $attribute = (string) $this->pdo->query(
            'SELECT public_id FROM ls_commerce_attributes LIMIT 1'
        )->fetchColumn();
        self::assertSame(303, $this->controller->createAttributeOption($this->post(
            '/admin/commerce/taxonomies/attributes/options/create',
            [
                'csrf' => $this->csrf,
                'attribute' => $attribute,
                'code' => 'diesel',
                'sort_order' => '0',
                'labels' => ['es' => 'Diesel', 'eu' => 'Diesela'],
            ]
        ))->status());
        $option = (string) $this->pdo->query(
            'SELECT public_id FROM ls_commerce_attribute_options LIMIT 1'
        )->fetchColumn();
        self::assertSame(303, $this->controller->saveAttributeValue($this->post(
            '/admin/commerce/products/attributes/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'attribute' => $attribute,
                'locale' => 'es',
                'value' => $option,
            ]
        ))->status());
        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_product_attribute_value_options'
        )->fetchColumn());

        $media = $this->seedMedia();
        $productVersion = (string) $this->pdo->query(
            'SELECT lock_version FROM ls_commerce_products LIMIT 1'
        )->fetchColumn();
        self::assertSame(303, $this->controller->saveProductMedia($this->post(
            '/admin/commerce/products/media/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'lock_version' => $productVersion,
                'locale' => 'es',
                'roles' => [$media => 'cover'],
                'alts' => [$media => 'Furgoneta preparada para viajar'],
                'captions' => [$media => 'Vista exterior'],
            ]
        ))->status());
        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_commerce_product_media_localizations'
        )->fetchColumn());
        self::assertSame(
            ['fallback', 'source'],
            $this->pdo->query(
                'SELECT translation_status FROM ls_commerce_product_media_localizations '
                . 'ORDER BY translation_status'
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $euEditor = $this->controller->edit($this->get(
            '/admin/commerce/products/edit',
            'GET',
            ['product' => $product, 'locale' => 'eu']
        ));
        self::assertSame(200, $euEditor->status());
        self::assertStringContainsString('Idioma editado: <strong>EU</strong>', $euEditor->body());
        self::assertStringNotContainsString('<select name="locale"', $euEditor->body());
        self::assertSame(303, $this->controller->saveProductMediaLocalization($this->post(
            '/admin/commerce/products/media/localization/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'media' => $media,
                'locale' => 'eu',
                'alt_text' => 'Bidaiatzeko prestatutako furgoneta',
                'caption' => 'Kanpoko ikuspegia',
            ]
        ))->status());
        self::assertSame('translated', (string) $this->pdo->query(
            "SELECT translation_status FROM ls_commerce_product_media_localizations "
            . "WHERE locale = 'eu'"
        )->fetchColumn());

        $productVersion = (string) $this->pdo->query(
            'SELECT lock_version FROM ls_commerce_products LIMIT 1'
        )->fetchColumn();
        self::assertSame(303, $this->controller->save($this->post(
            '/admin/commerce/products/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'lock_version' => $productVersion,
                'sku' => 'MATRIX-01',
                'price' => '',
                'currency' => 'EUR',
                'locale' => 'es',
                'title' => 'Furgoneta Matrix',
                'slug' => 'furgoneta-matrix',
                'summary' => 'Lista para viajar.',
                'description' => 'Descripcion de prueba.',
                'status' => 'inactive',
                'availability' => 'reserved',
            ]
        ))->status());
        $state = $this->pdo->query(
            'SELECT editorial_status, availability_status, price_minor, currency '
            . 'FROM ls_commerce_products LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame([
            'editorial_status' => 'inactive',
            'availability_status' => 'reserved',
            'price_minor' => null,
            'currency' => null,
        ], $state);
        self::assertSame(409, $this->controller->save($this->post(
            '/admin/commerce/products/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'lock_version' => $productVersion,
                'sku' => 'MATRIX-01',
                'price' => '1.00',
                'currency' => 'EUR',
                'locale' => 'es',
                'title' => 'Cambio obsoleto',
                'slug' => 'cambio-obsoleto',
                'summary' => '',
                'description' => '',
                'status' => 'draft',
                'availability' => 'available',
            ]
        ))->status());
        self::assertSame('Furgoneta Matrix', (string) $this->pdo->query(
            "SELECT title FROM ls_commerce_product_localizations WHERE locale = 'es'"
        )->fetchColumn());

        $productVersion = (string) $this->pdo->query(
            'SELECT lock_version FROM ls_commerce_products LIMIT 1'
        )->fetchColumn();
        self::assertSame(303, $this->controller->save($this->post(
            '/admin/commerce/products/save',
            [
                'csrf' => $this->csrf,
                'product' => $product,
                'lock_version' => $productVersion,
                'sku' => 'MATRIX-01',
                'price' => '20000.50',
                'currency' => 'EUR',
                'locale' => 'es',
                'title' => 'Furgoneta Matrix',
                'slug' => 'furgoneta-matrix',
                'summary' => 'Lista para viajar.',
                'description' => 'Descripcion de prueba.',
                'status' => 'active',
                'availability' => 'available',
            ]
        ))->status());
        $state = $this->pdo->query(
            'SELECT editorial_status, availability_status, price_minor, currency '
            . 'FROM ls_commerce_products LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame([
            'editorial_status' => 'active',
            'availability_status' => 'available',
            'price_minor' => 2000050,
            'currency' => 'EUR',
        ], $state);

        $settings = $this->controller->settings(
            $this->get('/admin/commerce/settings')
        );
        self::assertSame(200, $settings->status());
        self::assertStringContainsString('Solicitar informaci&oacute;n:</strong> activo', $settings->body());
        self::assertStringContainsString('Venta online:</strong> no disponible', $settings->body());
    }

    public function testInquiriesAreReadOnlyAndHeadHasNoBody(): void
    {
        $media = $this->seedMedia();
        $inquiryId = '40000000-0000-4000-8000-000000000004';
        $inquiry = $this->pdo->prepare(
            'INSERT INTO ls_commerce_inquiries '
            . '(public_id, operation_id, payload_sha256, basket_id, locale, '
            . 'contact_name, email, phone, message, privacy_version, created_at) '
            . 'VALUES (:public, :operation, :hash, NULL, :locale, :name, :email, '
            . ':phone, :message, :privacy, :created)'
        );
        self::assertNotFalse($inquiry);
        self::assertTrue($inquiry->execute([
            'public' => $inquiryId,
            'operation' => '50000000-0000-4000-8000-000000000005',
            'hash' => str_repeat('c', 64),
            'locale' => 'es',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+34 600 000 000',
            'message' => 'Quiero visitar la furgoneta.',
            'privacy' => '2026-09',
            'created' => '2030-01-01 09:45:00.000000',
        ]));
        $internalInquiryId = (int) $this->pdo->lastInsertId();
        $line = $this->pdo->prepare(
            'INSERT INTO ls_commerce_inquiry_lines '
            . '(inquiry_id, product_public_id, sku, requested_locale, resolved_locale, '
            . 'title, public_path, cover_media_public_id, quantity, unit_price_minor, '
            . 'currency, availability_status, created_at) VALUES '
            . '(:inquiry, :product, :sku, :requested, :resolved, :title, :path, '
            . ':media, 1, 1234567, :currency, :availability, :created)'
        );
        self::assertNotFalse($line);
        self::assertTrue($line->execute([
            'inquiry' => $internalInquiryId,
            'product' => '60000000-0000-4000-8000-000000000006',
            'sku' => 'MATRIX-01',
            'requested' => 'es',
            'resolved' => 'es',
            'title' => 'Furgoneta Matrix',
            'path' => '/es/furgonetas/furgoneta-matrix',
            'media' => $media,
            'currency' => 'EUR',
            'availability' => 'available',
            'created' => '2030-01-01 09:45:00.000000',
        ]));

        $response = $this->controller->inquiries(
            $this->get('/admin/commerce/inquiries')
        );
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Consulta de solo lectura', $response->body());
        self::assertStringContainsString('Ada Lovelace', $response->body());

        $detail = $this->controller->inquiryDetail($this->get(
            '/admin/commerce/inquiries/detail',
            'GET',
            ['inquiry' => $inquiryId]
        ));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Quiero visitar la furgoneta.', $detail->body());
        self::assertStringContainsString('2026-09', $detail->body());
        self::assertStringContainsString('Furgoneta Matrix', $detail->body());
        self::assertStringContainsString('MATRIX-01', $detail->body());
        self::assertStringContainsString('12.345,67 EUR', $detail->body());
        self::assertStringContainsString('/admin/media/file?asset=', $detail->body());

        $head = $this->controller->inquiries(
            $this->get('/admin/commerce/inquiries', 'HEAD')
        );
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());
        self::assertSame('noindex, nofollow, noarchive', $head->headers()['X-Robots-Tag']);
    }

    /** @return array<string, string> */
    private function productForm(): array
    {
        return [
            'csrf' => $this->csrf,
            'sku' => 'MATRIX-01',
            'price' => '12345.67',
            'currency' => 'EUR',
            'locale' => 'es',
            'title' => 'Furgoneta Matrix',
            'slug' => 'furgoneta-matrix',
            'summary' => 'Lista para viajar.',
            'description' => 'Descripci&oacute;n de prueba.',
        ];
    }

    private function seedMedia(): string
    {
        $publicId = '30000000-0000-4000-8000-000000000003';
        $userId = (int) $this->pdo->query(
            'SELECT id FROM ls_webadmin_users LIMIT 1'
        )->fetchColumn();
        $asset = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_assets '
            . '(public_id, label, source_mime, source_width, source_height, '
            . 'source_bytes, source_sha256, created_by_user_id, created_at) VALUES '
            . '(:public_id, :label, :mime, 1200, 800, 1000, :sha, :user, :created)'
        );
        self::assertNotFalse($asset);
        self::assertTrue($asset->execute([
            'public_id' => $publicId,
            'label' => 'Exterior',
            'mime' => 'image/jpeg',
            'sha' => str_repeat('a', 64),
            'user' => $userId,
            'created' => '2030-01-01 09:30:00.000000',
        ]));
        $assetId = (int) $this->pdo->lastInsertId();
        $variant = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_media_variants '
            . '(asset_id, width, height, bytes, sha256, storage_key, mime, created_at) '
            . 'VALUES (:asset, 480, 320, 500, :sha, :storage, :mime, :created)'
        );
        self::assertNotFalse($variant);
        self::assertTrue($variant->execute([
            'asset' => $assetId,
            'sha' => str_repeat('b', 64),
            'storage' => 'media/test/exterior-480.avif',
            'mime' => 'image/avif',
            'created' => '2030-01-01 09:30:00.000000',
        ]));

        return $publicId;
    }

    private function seedActor(SecureTokenGenerator $tokens): void
    {
        $this->pdo->exec("INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status, auth_version, activated_at) VALUES "
            . "('10000000-0000-4000-8000-000000000001', 'editor@example.test', "
            . "'active', 1, '2030-01-01 09:00:00.000000')");
        $user = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare('INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) VALUES (:user, :hash, :at)');
        self::assertNotFalse($credential);
        self::assertTrue($credential->execute([
            'user' => $user,
            'hash' => PasswordHasher::productive()->hash('Correct horse battery staple 1!'),
            'at' => '2030-01-01 09:00:00.000000',
        ]));
        foreach ([
            'webadmin.access',
            MediaService::VIEW_CAPABILITY,
            ...CommerceCapabilities::all(),
        ] as $capability) {
            $statement = $this->pdo->prepare('INSERT INTO ls_webadmin_user_capabilities '
                . '(user_id, capability_id) SELECT :user, id FROM ls_webadmin_capabilities '
                . 'WHERE code = :code');
            self::assertNotFalse($statement);
            self::assertTrue($statement->execute(['user' => $user, 'code' => $capability]));
            self::assertSame(1, $statement->rowCount(), $capability);
        }
        $session = $this->pdo->prepare('INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, csrf_token_hash, '
            . 'auth_version, pending_action_token_id, created_at, last_seen_at, '
            . 'idle_expires_at, absolute_expires_at, revoked_at) VALUES '
            . '(:public_id, :user, :type, :token, :csrf, 1, NULL, :created, '
            . ':seen, :idle, :absolute, NULL)');
        self::assertNotFalse($session);
        self::assertTrue($session->execute([
            'public_id' => '20000000-0000-4000-8000-000000000002',
            'user' => $user,
            'type' => 'authenticated',
            'token' => $tokens->hashForStorage($this->session),
            'csrf' => $tokens->hashForStorage($this->csrf),
            'created' => '2030-01-01 09:55:00.000000',
            'seen' => '2030-01-01 09:59:00.000000',
            'idle' => '2030-01-01 10:05:00.000000',
            'absolute' => '2030-01-01 11:00:00.000000',
        ]));
    }

    private function apply(MigrationDefinition $migration, MigrationScope $scope): void
    {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }

    /** @param array<string, string> $query */
    private function get(
        string $path,
        string $method = 'GET',
        array $query = []
    ): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ], query: $query, cookies: ['LS_WEBADMIN_SID' => $this->session]);
    }

    /** @param array<string, mixed> $form */
    private function post(string $path, array $form): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
        ], form: $form, cookies: ['LS_WEBADMIN_SID' => $this->session], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
    }
}
