<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/stubs/App/controllers/navMegamenu01.php';
require_once dirname(__DIR__, 2) . '/stubs/App/controllers/footerInfo01.php';

final class OptionalGlobalMediaControllerTest extends TestCase
{
    /** @var array<string, array{exists: bool, value: mixed}> */
    private array $previousGlobals = [];

    /** @var array<string, array{exists: bool, value: mixed}> */
    private array $previousEnvironment = [];

    /** @var array<string, mixed> */
    private array $previousSession = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'RAIZ' => 'http://localhost:1309',
            'LANG_DEFAULT' => 'es',
            'ES_SIMPLIFICADO' => '1',
        ] as $key => $value) {
            $this->rememberEnvironment($key);
            $_ENV[$key] = $value;
        }

        $this->putGlobal('lang', 'es');
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->previousGlobals as $key => $previous) {
            if ($previous['exists']) {
                $GLOBALS[$key] = $previous['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }

        foreach ($this->previousEnvironment as $key => $previous) {
            if ($previous['exists']) {
                $_ENV[$key] = $previous['value'];
            } else {
                unset($_ENV[$key]);
            }
        }

        $_SESSION = $this->previousSession;
        parent::tearDown();
    }

    public function testNavigationKeepsEditableDefaultSocialSlots(): void
    {
        $this->configureNavigationGlobals();
        $legalRoutes = $this->legalRouteDefinitions();
        $this->putGlobal('arrayRutasGet', $legalRoutes);

        $html = $this->fromStubRoot(static fn (): string =>
            controller_navMegamenu01(0, [
                'offices' => [],
                'show_private_access' => false,
                'public_link_keys' => [[
                    'link' => 'navMegamenu01_00_blog',
                    'text' => 'navMegamenu01_00_blogText',
                ]],
            ])
        );

        self::assertStringContainsString('class="rrss"', $html);
        self::assertStringContainsString(
            '<img data-lang="navMegamenu01_00_forward"',
            $html
        );
        foreach ([
            'col02link_01' => '/es/politica-de-cookies',
            'col02link_02' => '/es/politica-de-privacidad',
            'col02link_03' => '/es/aviso-legal',
        ] as $key => $href) {
            self::assertStringContainsString(
                'data-lang="navMegamenu01_00_' . $key . '" href="' . $href . '"',
                $html
            );
        }
        self::assertStringContainsString('class="menu-group"', $html);
        self::assertStringNotContainsString('class="legal"', $html);
        self::assertStringNotContainsString('data-tipo=', $html);
        self::assertSame(4, substr_count($html, '<a data-lang="navMegamenu01_00_rrss_'));
        self::assertStringNotContainsString('src="http://localhost:1309/"', $html);
        self::assertStringNotContainsString('href=""', $html);
        foreach (['fb', 'in', 'yt', 'ig'] as $network) {
            self::assertStringContainsString(
                'src="http://localhost:1309/assets/img/system/' . $network . '.svg"',
                $html
            );
        }
        self::assertStringContainsString('href="/es/blog"', $html);
        self::assertStringNotContainsString('Acceder', $html);
    }

    public function testNavigationKeepsACompleteSocialLink(): void
    {
        $this->configureNavigationGlobals();
        $this->putGlobal(
            'navMegamenu01_00_rrss_yt',
            (object) ['title' => 'Canal de ejemplo']
        );
        $this->putGlobal(
            'navMegamenu01_00_rrss_yt_href',
            'https://example.com'
        );
        $this->putGlobal(
            'navMegamenu01_00_rrss_yt_img',
            (object) [
                'src' => 'assets/img/social.svg',
                'alt' => 'Canal',
                'title' => 'Canal',
            ]
        );

        $html = $this->fromStubRoot(static fn (): string =>
            controller_navMegamenu01(0, [
                'offices' => [],
                'show_private_access' => false,
            ])
        );

        self::assertStringContainsString('class="rrss"', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString(
            'src="http://localhost:1309/assets/img/social.svg"',
            $html
        );
    }

    public function testNavigationResolvesLegalRoutesForTheActiveLanguage(): void
    {
        $this->configureNavigationGlobals();
        $this->putGlobal('lang', 'en');
        $legalRoutes = $this->legalRouteDefinitions();
        $this->putGlobal('arrayRutasGet', $legalRoutes);

        $html = $this->fromStubRoot(static fn (): string =>
            controller_navMegamenu01(0, [
                'offices' => [],
                'show_private_access' => false,
            ])
        );

        foreach ([
            '/en/cookie-policy',
            '/en/privacy-policy',
            '/en/legal-notice',
        ] as $href) {
            self::assertStringContainsString('href="' . $href . '"', $html);
        }
    }

    public function testNavigationAcceptsAnExactProjectOwnedPublicHref(): void
    {
        $this->configureNavigationGlobals();
        $this->putGlobal(
            'navMegamenu01_00_commerce',
            (object) ['href' => 'commerce', 'title' => 'Tienda']
        );
        $this->putGlobal(
            'navMegamenu01_00_commerceText',
            (object) ['text' => 'Tienda']
        );

        $html = $this->fromStubRoot(static fn (): string =>
            controller_navMegamenu01(0, [
                'offices' => [],
                'show_private_access' => false,
                'public_link_keys' => [[
                    'link' => 'navMegamenu01_00_commerce',
                    'text' => 'navMegamenu01_00_commerceText',
                    'href' => '/es/catalogo-personalizado',
                ]],
            ])
        );

        self::assertStringContainsString(
            'href="/es/catalogo-personalizado"',
            $html
        );
        self::assertStringNotContainsString('href="/es/commerce"', $html);
    }

    public function testNavigationKeepsPrivateAccessByDefault(): void
    {
        $this->configureNavigationGlobals();

        $anonymous = $this->fromStubRoot(
            static fn (): string => controller_navMegamenu01(0)
        );
        self::assertStringContainsString('Acceder', $anonymous);
        self::assertStringContainsString('href="/es/login"', $anonymous);

        $_SESSION['id_rol'] = 1;
        $authenticated = $this->fromStubRoot(
            static fn (): string => controller_navMegamenu01(0)
        );
        self::assertStringNotContainsString('Acceder', $authenticated);
        self::assertStringContainsString('Área privada', $authenticated);
        self::assertStringContainsString(
            'href="/es/area-privada"',
            $authenticated
        );
    }

    public function testNavigationRendersOnlyExplicitProjectOffices(): void
    {
        $this->configureNavigationGlobals();

        $withoutOffices = $this->fromStubRoot(static fn (): string =>
            controller_navMegamenu01(0, ['show_private_access' => false])
        );
        self::assertStringNotContainsString('Oficina de ejemplo', $withoutOffices);

        $withOffice = $this->fromStubRoot(static fn (): string =>
            controller_navMegamenu01(0, [
                'show_private_access' => false,
                'offices' => [[
                    'label' => 'Oficina de ejemplo',
                    'tels' => ['+34 900 000 000'],
                    'addr' => 'Dirección configurable',
                    'map' => 'https://example.com/map',
                ]],
            ])
        );
        self::assertStringContainsString('Oficina de ejemplo', $withOffice);
        self::assertStringContainsString('+34 900 000 000', $withOffice);
        self::assertStringContainsString('Dirección configurable', $withOffice);
        self::assertStringContainsString(
            'href="https://example.com/map"',
            $withOffice
        );
    }

    public function testFooterOmitsAnEmptyOptionalImage(): void
    {
        $this->configureFooterGlobals('');

        $html = $this->fromStubRoot(
            static fn (): string => controller_footerInfo01(0)
        );

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('src="http://localhost:1309/"', $html);
    }

    public function testFooterKeepsAConfiguredImage(): void
    {
        $this->configureFooterGlobals('assets/img/logo.svg');

        $html = $this->fromStubRoot(
            static fn (): string => controller_footerInfo01(0)
        );

        self::assertStringContainsString(
            'src="http://localhost:1309/assets/img/logo.svg"',
            $html
        );
        self::assertStringContainsString('alt="Proyecto"', $html);
    }

    private function configureNavigationGlobals(): void
    {
        $prefix = 'navMegamenu01_00_';
        foreach ([
            'forward' => ['src' => 'assets/img/forward.svg', 'alt' => '>', 'title' => '>'],
            'home' => ['href' => '', 'title' => 'Inicio'],
            'homeText' => ['text' => 'Inicio'],
            'services' => ['href' => 'servicios', 'title' => 'Servicios'],
            'servicesText' => ['text' => 'Servicios'],
            'servicesItem0' => ['href' => 'servicios/servicio', 'title' => 'Servicio'],
            'servicesItem0Text' => ['text' => 'Servicio'],
            'contactLink' => ['href' => 'contacto', 'title' => 'Contacto'],
            'contactText' => ['text' => 'Contacto'],
            'login' => ['href' => 'login', 'title' => 'Acceder'],
            'loginText' => ['text' => 'Acceder'],
            'link0' => ['href' => 'area-privada', 'title' => 'Área privada'],
            'link0Text' => ['text' => 'Área privada'],
            'link1' => ['href' => 'documentos', 'title' => 'Documentos'],
            'link1Text' => ['text' => 'Documentos'],
            'link2' => ['href' => 'comunicados', 'title' => 'Comunicados'],
            'link2Text' => ['text' => 'Comunicados'],
            'link3' => ['href' => 'perfil', 'title' => 'Perfil'],
            'link3Text' => ['text' => 'Perfil'],
            'link4' => ['href' => 'cuenta', 'title' => 'Cuenta'],
            'link4Text' => ['text' => 'Cuenta'],
            'content_of_this_website' => ['text' => 'Contenido'],
            'link_of_interest' => ['text' => 'Enlaces'],
            'follow_us_social_media' => ['text' => 'Redes'],
            'contact' => ['text' => 'Contacto'],
            'logo_business' => ['src' => 'assets/img/logo.svg', 'alt' => 'Proyecto', 'title' => 'Proyecto'],
            'correo_link' => ['title' => 'Correo'],
            'correo_text' => ['text' => 'info@example.com'],
            'correo_img' => ['src' => 'assets/img/mail.svg', 'alt' => 'Correo', 'title' => 'Correo'],
            'tel_link' => ['title' => 'Llamar'],
            'tel_img' => ['src' => 'assets/img/tel.svg', 'alt' => 'Teléfono', 'title' => 'Teléfono'],
            'mp_img' => ['src' => 'assets/img/mobile.svg', 'alt' => 'Móvil', 'title' => 'Móvil'],
            'ubicacion_link' => ['title' => 'Abrir mapa'],
            'ubicacion_img' => ['src' => 'assets/img/map.svg', 'alt' => 'Mapa', 'title' => 'Mapa'],
            'col02span2_01' => ['text' => 'Cookies'],
            'col02span2_02' => ['text' => 'Privacidad'],
            'col02span2_03' => ['text' => 'Aviso legal'],
            'col02link_01' => ['href' => '', 'title' => 'Cookies'],
            'col02link_02' => ['href' => '', 'title' => 'Privacidad'],
            'col02link_03' => ['href' => '', 'title' => 'Aviso legal'],
        ] as $suffix => $value) {
            $this->putGlobal($prefix . $suffix, (object) $value);
        }

        $this->putGlobal($prefix . 'correo_link_href', 'info@example.com');
        foreach (['yt', 'in', 'fb', 'ig'] as $network) {
            $this->putGlobal(
                "{$prefix}rrss_{$network}",
                (object) ['title' => '']
            );
            $this->putGlobal("{$prefix}rrss_{$network}_href", '');
            $this->putGlobal(
                "{$prefix}rrss_{$network}_img",
                (object) ['src' => '', 'alt' => '', 'title' => '']
            );
        }

        $this->putGlobal(
            'navMegamenu01_00_blog',
            (object) ['href' => 'blog', 'title' => 'Blog']
        );
        $this->putGlobal(
            'navMegamenu01_00_blogText',
            (object) ['text' => 'Blog']
        );
    }

    private function configureFooterGlobals(string $source): void
    {
        $this->putGlobal(
            'footerInfo01_00_img1',
            (object) [
                'src' => $source,
                'alt' => 'Proyecto',
                'title' => 'Proyecto',
            ]
        );

        foreach ([
            'footerInfo0100_cookie-policy' => 'Cookies',
            'footerInfo0100_terms-privacy' => 'Privacidad',
            'footerInfo0100_legal-notice' => 'Aviso legal',
            'footerInfo0100_rights-reserved' => 'Derechos reservados',
            'footerInfo0100_lad-info-p' => 'Desarrollo',
        ] as $key => $text) {
            $this->putGlobal($key, (object) ['text' => $text]);
        }

        $this->putGlobal(
            'footerInfo0100_lad-info-a',
            (object) ['title' => 'Proveedor']
        );
        $this->putGlobal('footerInfo01_lad_info_href', 'https://example.com');
    }

    /** @return array<string, array<string, array{content: string}>> */
    private function legalRouteDefinitions(): array
    {
        return [
            'es' => [
                '/es/politica-de-cookies' => [
                    'content' => 'politica-de-cookies',
                ],
                '/es/politica-de-privacidad' => [
                    'content' => 'politica-de-privacidad',
                ],
                '/es/aviso-legal' => [
                    'content' => 'aviso-legal',
                ],
            ],
            'en' => [
                '/en/cookie-policy' => [
                    'content' => 'gestion-cookies',
                ],
                '/en/privacy-policy' => [
                    'content' => 'politica-de-privacidad',
                ],
                '/en/legal-notice' => [
                    'content' => 'aviso-legal',
                ],
            ],
        ];
    }

    private function fromStubRoot(callable $callback): string
    {
        $root = dirname(__DIR__, 2);
        $previousRoot = Paths::projectRoot();
        $previousCwd = (string) getcwd();
        Paths::setProjectRoot($root . '/stubs');
        chdir($root . '/stubs');

        try {
            return $callback();
        } finally {
            Paths::setProjectRoot($previousRoot);
            if ($previousCwd !== '') {
                chdir($previousCwd);
            }
        }
    }

    private function putGlobal(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->previousGlobals)) {
            $this->previousGlobals[$key] = [
                'exists' => array_key_exists($key, $GLOBALS),
                'value' => $GLOBALS[$key] ?? null,
            ];
        }
        $GLOBALS[$key] = $value;
    }

    private function rememberEnvironment(string $key): void
    {
        $this->previousEnvironment[$key] = [
            'exists' => array_key_exists($key, $_ENV),
            'value' => $_ENV[$key] ?? null,
        ];
    }
}
