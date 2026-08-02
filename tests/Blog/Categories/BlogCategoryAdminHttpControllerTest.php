<?php

declare(strict_types=1);

namespace Tests\Blog\Categories;

use App\Core\Blog\BlogService;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\PdoBlogCategoryRepository;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\Http\BlogCategoryAdminHttpController;
use App\Core\Blog\Http\BlogCategoryAdminHtmlRenderer;
use App\Core\Blog\Http\BlogCategoryAdminHttpRuntime;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Http\Request;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CategoryControllerClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 10:00:00 UTC');
    }
}

final class BlogCategoryAdminHttpControllerTest extends TestCase
{
    private const ACTOR = '10000000-0000-4000-8000-000000000001';

    private PDO $pdo;
    private BlogCategoryAdminHttpController $controller;
    private string $sessionToken;
    private string $csrfToken;

    public function testRendererKeepsLegacyThreeArgumentEditContract(): void
    {
        $method = new \ReflectionMethod(
            BlogCategoryAdminHtmlRenderer::class,
            'editForm'
        );
        $parameter = $method->getParameters()[3];

        self::assertTrue($parameter->isOptional());
        self::assertTrue($parameter->getDefaultValue());
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $webAdminScope = MigrationScope::forTablePrefix(
            'webadmin',
            'ls_webadmin_'
        );
        $blogScope = MigrationScope::forTablePrefix('blog', 'ls_blog_');
        $webAdminMigration = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        $this->executeMigration($webAdminMigration, $webAdminScope);
        foreach (BlogMigrationProvider::migrations() as $migration) {
            $this->executeMigration(
                $migration,
                $migration->targetScopeModuleId() === 'webadmin'
                    ? $webAdminScope
                    : $blogScope
            );
        }

        $clock = new CategoryControllerClock();
        $config = new WebAdminConfig(
            '/admin',
            'ls_webadmin_',
            'LS_WEBADMIN_SID',
            300,
            3600,
            'test'
        );
        $securityKey = SecurityKey::fromRawBytes(str_repeat('K', 32));
        $tokens = new SecureTokenGenerator();
        $this->sessionToken = rtrim(strtr(
            base64_encode(str_repeat('S', 32)),
            '+/',
            '-_'
        ), '=');
        $this->csrfToken = $securityKey->deriveToken(
            'csrf.session',
            $this->sessionToken
        );
        $this->seedActor($tokens);

        $tables = WebAdminTableNames::fromPdo(
            $this->pdo,
            'ls_webadmin_'
        );
        $hasher = PasswordHasher::productive();
        $uuidGenerator = new RandomUuidV4Generator();
        $authentication = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($this->pdo, $tables),
            $config,
            $securityKey,
            $clock,
            $uuidGenerator,
            $hasher,
            $tokens
        );
        $authorization = new WebAdminAuthorizationService(
            $this->pdo,
            $tables,
            $clock,
            $tokens,
            $hasher
        );
        $blogService = new BlogService(
            new PdoBlogRepository($this->pdo, $blogScope),
            $uuidGenerator,
            $clock
        );
        $categoryService = new BlogCategoryService(
            new PdoBlogCategoryRepository($this->pdo, $blogScope),
            $uuidGenerator,
            $clock
        );
        $languages = ['es', 'en', 'eu'];
        $runtime = new BlogCategoryAdminHttpRuntime(
            $languages,
            BlogConfig::defaults($languages),
            $config,
            $blogService,
            $categoryService,
            $authentication,
            $authorization,
            $this->pdo,
            new WebAdminMutationActorGate(
                $this->pdo,
                $tables,
                $config,
                $securityKey,
                $clock,
                $tokens,
                $hasher
            )
        );
        $this->controller = new BlogCategoryAdminHttpController($runtime);
    }

    public function testCrudRequiresCapabilityAndCsrfWithoutPartialWrites(): void
    {
        $wrongCsrf = $this->controller->create($this->post(
            '/admin/blog/categories/create',
            [
                'csrf' => str_repeat('X', 43),
                'category' => '',
                'locale' => 'es',
                'name' => 'Noticias',
                'slug' => 'noticias',
            ]
        ));
        self::assertSame(403, $wrongCsrf->status());
        self::assertSame(0, $this->categoryCount());

        $created = $this->controller->create($this->post(
            '/admin/blog/categories/create',
            [
                'csrf' => $this->csrfToken,
                'category' => '',
                'locale' => 'es',
                'name' => 'Noticias',
                'slug' => 'noticias',
            ]
        ));
        self::assertSame(303, $created->status());
        self::assertSame(
            '/admin/blog/categories/updated',
            $created->headers()['Location']
        );
        self::assertSame(1, $this->categoryCount());

        $this->removeCapability(
            BlogCategoryAdminHttpController::EDIT_CAPABILITY
        );
        self::assertSame(403, $this->controller->newCategory(
            $this->get('/admin/blog/categories/new')
        )->status());
        self::assertSame(1, $this->categoryCount());

        $this->removeCapability(
            BlogCategoryAdminHttpController::VIEW_CAPABILITY
        );
        self::assertSame(403, $this->controller->index(
            $this->get('/admin/blog/categories')
        )->status());
    }

    public function testGetAndHeadHaveIdenticalAuthorizationAndReadiness(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $anonymous = $this->controller->index(Request::fromInput([
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/admin/blog/categories',
                'HTTPS' => 'on',
            ]));
            self::assertSame(303, $anonymous->status());
            self::assertSame('', $anonymous->body());
            self::assertSame(
                '/admin/login',
                $anonymous->headers()['Location']
            );
        }

        $get = $this->controller->index(
            $this->get('/admin/blog/categories')
        );
        self::assertSame(200, $get->status());
        self::assertStringContainsString(
            'Categor&iacute;as del Blog',
            $get->body()
        );
        $head = $this->controller->index(Request::fromInput([
            'REQUEST_METHOD' => 'HEAD',
            'REQUEST_URI' => '/admin/blog/categories',
            'HTTPS' => 'on',
        ], cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ]));
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());
        self::assertSame(
            $get->headers()['Cache-Control'],
            $head->headers()['Cache-Control']
        );
    }

    public function testLocalizationFormOnlyOffersMissingActiveLanguages(): void
    {
        $newCategory = $this->controller->newCategory(
            $this->get('/admin/blog/categories/new')
        );
        self::assertSame(200, $newCategory->status());
        self::assertSame(3, substr_count($newCategory->body(), '<option '));
        self::assertStringContainsString(
            '<option value="es" selected>es</option>',
            $newCategory->body()
        );

        $category = $this->insertCategory(700);
        $addLanguage = $this->controller->newCategory($this->get(
            '/admin/blog/categories/new',
            ['category' => $category]
        ));
        self::assertSame(200, $addLanguage->status());
        self::assertSame(2, substr_count($addLanguage->body(), '<option '));
        self::assertStringNotContainsString(
            '<option value="es"',
            $addLanguage->body()
        );
        self::assertStringContainsString(
            '<option value="en" selected>en</option>',
            $addLanguage->body()
        );
        self::assertStringContainsString(
            '<option value="eu">eu</option>',
            $addLanguage->body()
        );

        $editWithMissingLanguages = $this->controller->edit($this->get(
            '/admin/blog/categories/edit',
            ['category' => $category, 'locale' => 'es']
        ));
        self::assertSame(200, $editWithMissingLanguages->status());
        self::assertStringContainsString(
            'A&ntilde;adir otro idioma',
            $editWithMissingLanguages->body()
        );

        foreach ([
            'en' => ['Financial news', 'financial-news'],
            'eu' => ['Finantza albisteak', 'finantza-albisteak'],
        ] as $locale => [$name, $slug]) {
            $created = $this->controller->create($this->post(
                '/admin/blog/categories/create',
                [
                    'csrf' => $this->csrfToken,
                    'category' => $category,
                    'locale' => $locale,
                    'name' => $name,
                    'slug' => $slug,
                ]
            ));
            self::assertSame(303, $created->status());

            $remaining = $this->controller->newCategory($this->get(
                '/admin/blog/categories/new',
                ['category' => $category]
            ));
            self::assertSame(200, $remaining->status());
            if ($locale === 'en') {
                self::assertSame(1, substr_count(
                    $remaining->body(),
                    '<option '
                ));
                self::assertStringContainsString(
                    '<option value="eu" selected>eu</option>',
                    $remaining->body()
                );
            } else {
                self::assertStringNotContainsString(
                    '<form',
                    $remaining->body()
                );
                self::assertStringContainsString(
                    'todos los idiomas activos',
                    $remaining->body()
                );
            }
        }

        $completeEdit = $this->controller->edit($this->get(
            '/admin/blog/categories/edit',
            ['category' => $category, 'locale' => 'es']
        ));
        self::assertSame(200, $completeEdit->status());
        self::assertStringNotContainsString(
            'A&ntilde;adir otro idioma',
            $completeEdit->body()
        );
        self::assertStringContainsString(
            'todos los idiomas activos',
            $completeEdit->body()
        );

        $unknown = $this->controller->newCategory($this->get(
            '/admin/blog/categories/new',
            ['category' => '89999999-9999-4999-8999-999999999999']
        ));
        self::assertSame(404, $unknown->status());
    }

    public function testAssignmentFormAndMutationPreserveMoreThanFiftyItems(): void
    {
        $postPublicId = $this->insertPost();
        $categories = [];
        for ($sequence = 1; $sequence <= 51; ++$sequence) {
            $categories[] = $this->insertCategory($sequence);
        }

        $form = $this->controller->assignment($this->get(
            '/admin/blog/categories/assign',
            ['post' => $postPublicId, 'locale' => 'es']
        ));
        self::assertSame(200, $form->status());
        self::assertStringContainsString('Categoria 51', $form->body());
        self::assertSame(51, substr_count(
            $form->body(),
            'name="categories[]"'
        ));

        $saved = $this->controller->saveAssignment($this->post(
            '/admin/blog/categories/assign',
            [
                'csrf' => $this->csrfToken,
                'post' => $postPublicId,
                'categories' => $categories,
            ]
        ));
        self::assertSame(303, $saved->status());
        self::assertSame(51, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_post_categories'
        )->fetchColumn());
    }

    private function seedActor(SecureTokenGenerator $tokens): void
    {
        $this->pdo->exec(
            "INSERT INTO ls_webadmin_users "
            . "(public_id, email_canonical, status, auth_version, activated_at) "
            . "VALUES ('" . self::ACTOR . "', 'editor@example.test', "
            . "'active', 1, '2030-01-01 09:00:00.000000')"
        );
        $userId = (int) $this->pdo->lastInsertId();
        $credential = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_credentials '
            . '(user_id, password_hash, password_set_at) VALUES (?, ?, ?)'
        );
        self::assertTrue($credential->execute([
            $userId,
            PasswordHasher::productive()->hash(
                'Correct horse battery staple'
            ),
            '2030-01-01 09:00:00.000000',
        ]));
        foreach ([
            'webadmin.access',
            BlogCategoryAdminHttpController::VIEW_CAPABILITY,
            BlogCategoryAdminHttpController::EDIT_CAPABILITY,
        ] as $capability) {
            $statement = $this->pdo->prepare(
                'INSERT INTO ls_webadmin_user_capabilities '
                . '(user_id, capability_id) SELECT :user, id FROM '
                . 'ls_webadmin_capabilities WHERE code = :code'
            );
            self::assertTrue($statement->execute([
                'user' => $userId,
                'code' => $capability,
            ]));
            self::assertSame(1, $statement->rowCount(), $capability);
        }
        $session = $this->pdo->prepare(
            'INSERT INTO ls_webadmin_sessions '
            . '(public_id, user_id, session_type, token_hash, '
            . 'csrf_token_hash, auth_version, created_at, last_seen_at, '
            . 'idle_expires_at, absolute_expires_at) VALUES '
            . '(:public_id, :user, :type, :token, :csrf, 1, :created, '
            . ':seen, :idle, :absolute)'
        );
        self::assertTrue($session->execute([
            'public_id' => '20000000-0000-4000-8000-000000000002',
            'user' => $userId,
            'type' => 'authenticated',
            'token' => $tokens->hashForStorage($this->sessionToken),
            'csrf' => $tokens->hashForStorage($this->csrfToken),
            'created' => '2030-01-01 09:55:00.000000',
            'seen' => '2030-01-01 09:59:00.000000',
            'idle' => '2030-01-01 10:05:00.000000',
            'absolute' => '2030-01-01 11:00:00.000000',
        ]));
    }

    private function removeCapability(string $capability): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ls_webadmin_user_capabilities WHERE capability_id = '
            . '(SELECT id FROM ls_webadmin_capabilities WHERE code = ?)'
        );
        self::assertTrue($statement->execute([$capability]));
    }

    private function insertPost(): string
    {
        $publicId = '90000000-0000-4000-8000-000000000001';
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_posts '
            . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        );
        self::assertTrue($statement->execute([$publicId, self::ACTOR]));
        $postId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_post_localizations '
            . '(public_id, post_id, locale, slug, h1, body_text, '
            . 'created_by_user_public_id, updated_by_user_public_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            '91000000-0000-4000-8000-000000000001',
            $postId,
            'es',
            'matrix',
            'Matrix',
            'Contenido Matrix',
            self::ACTOR,
            self::ACTOR,
        ]));

        return $publicId;
    }

    private function insertCategory(int $sequence): string
    {
        $category = sprintf(
            '80000000-0000-4000-8000-%012x',
            $sequence
        );
        $localization = sprintf(
            '81000000-0000-4000-8000-%012x',
            $sequence
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_categories '
            . '(public_id, created_by_user_public_id) VALUES (?, ?)'
        );
        self::assertTrue($statement->execute([$category, self::ACTOR]));
        $categoryId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO ls_blog_category_locales '
            . '(public_id, category_id, locale, slug, name, '
            . 'created_by_user_public_id, updated_by_user_public_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        self::assertTrue($statement->execute([
            $localization,
            $categoryId,
            'es',
            'categoria-' . $sequence,
            'Categoria ' . $sequence,
            self::ACTOR,
            self::ACTOR,
        ]));

        return $category;
    }

    private function categoryCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM ls_blog_categories'
        )->fetchColumn();
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.50',
        ], query: $query, cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ]);
    }

    /** @param array<string, mixed> $form */
    private function post(string $path, array $form): Request
    {
        return Request::fromInput([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '192.0.2.50',
        ], form: $form, cookies: [
            'LS_WEBADMIN_SID' => $this->sessionToken,
        ], headers: [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'Category admin test browser',
        ]);
    }

    private function executeMigration(
        MigrationDefinition $migration,
        MigrationScope $scope
    ): void {
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($this->pdo->exec($sql));
        }
    }
}
