<?php

declare(strict_types=1);

use App\Core\Support\Paths;
use PHPUnit\Framework\TestCase;
use function App\Core\Support\controller;

require_once dirname(__DIR__) . '/Support/ShowroomCatalogFixture.php';

final class ModuleFormContactResourceTest extends TestCase
{
    private const RESOURCES = [
        'moduleFormContact01',
        'moduleFormContact02',
        'moduleFormContact03',
    ];

    private const TEXT_SUFFIXES = [
        'legend',
        'intro',
        'label_name',
        'label_phone',
        'label_mail',
        'label_message',
        'terms',
        'terms_error',
        'label_captcha',
        'submit',
        'sending',
        'success_title',
        'success_text',
        'new_query',
        'network_error',
        'server_error',
    ];

    private const PLACEHOLDER_SUFFIXES = [
        'ph_name',
        'ph_phone',
        'ph_mail',
        'ph_message',
        'ph_captcha',
    ];

    private string $originalCwd = '';
    private array $originalEnv = [];

    /**
     * @var array<string, array{exists: bool, value?: mixed}>
     */
    private array $globalState = [];

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->originalEnv = $_ENV;

        Paths::setProjectRoot(dirname(__DIR__, 2) . '/stubs');
        chdir(Paths::publicPath());

        $_ENV['RAIZ'] = 'http://localhost:1309';
        $_ENV['LANG_DEFAULT'] = 'es';

        $this->setGlobal('lang', 'es');
        $this->loadResourceGlobals();
    }

    protected function tearDown(): void
    {
        foreach ($this->globalState as $key => $state) {
            if ($state['exists']) {
                $GLOBALS[$key] = $state['value'];
                continue;
            }

            unset($GLOBALS[$key]);
        }

        $_ENV = $this->originalEnv;

        if ($this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
    }

    public function testFamilyProvidesAtomicModuleFilesAndSemantics(): void
    {
        self::assertFileExists(
            Paths::appPath() . '/controllers/_moduleFormContact.php'
        );
        self::assertFileExists(
            Paths::appPath() . '/templates/_moduleFormContact.html'
        );
        self::assertFileExists(
            dirname(__DIR__, 2) . '/resources/js/_moduleFormContact.js'
        );
        self::assertFileExists(
            dirname(__DIR__, 2) . '/resources/scss/_moduleFormContact.scss'
        );

        foreach (self::RESOURCES as $resource) {
            self::assertFileExists(
                Paths::appPath() . "/controllers/{$resource}.php"
            );
            self::assertFileExists(
                Paths::appPath() . "/templates/_{$resource}.html"
            );
            self::assertFileExists(
                dirname(__DIR__, 2) . "/resources/scss/_{$resource}.scss"
            );

            $html = controller($resource, 0);
            $xpath = $this->createXpath($html);
            $root = $xpath->query(
                '/html/body/div['
                . 'contains(concat(" ", normalize-space(@class), " "),'
                . ' " moduleFormContact ")'
                . ' and contains(concat(" ", normalize-space(@class), " "),'
                . ' " ' . $resource . ' ")'
                . ']'
            );

            self::assertNotFalse($root);
            self::assertCount(1, $root, "{$resource} debe tener raíz div");
            self::assertCount(
                0,
                $xpath->query(
                    '//article | //section | //header | //h1 | //h2 | //h3'
                    . ' | //h4 | //h5 | //h6 | //iframe | //address'
                ),
                "{$resource} no debe asumir jerarquía documental ni mapa"
            );
            self::assertCount(1, $xpath->query('//form[@data-form-contact]'));
            self::assertCount(1, $xpath->query('//form/descendant::fieldset'));
            self::assertCount(1, $xpath->query('//fieldset/legend'));
            self::assertStringContainsString(
                $resource,
                trim((string) $xpath->evaluate('string(//fieldset/legend)'))
            );
            self::assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $html);
        }
    }

    public function testRenderedFormsMatchTheExistingContactBackendContract(): void
    {
        foreach (self::RESOURCES as $resource) {
            $xpath = $this->createXpath(controller($resource, 0));

            self::assertCount(
                1,
                $xpath->query('//form[@method="post" and @action="/form"]')
            );

            $expectedControls = [
                'nombre'    => 'input[@type="text"]',
                'telefono'  => 'input[@type="tel"]',
                'correo'    => 'input[@type="email"]',
                'mensaje'   => 'textarea',
                'respuesta' => 'input[@type="text"]',
                'solucion'  => 'input[@type="hidden"]',
                'lang'      => 'input[@type="hidden"]',
                'terminos'  => 'input[@type="checkbox"]',
            ];

            foreach ($expectedControls as $name => $elementQuery) {
                self::assertCount(
                    1,
                    $xpath->query("//{$elementQuery}[@name=\"{$name}\"]"),
                    "{$resource}: falta el control {$name}"
                );
            }

            foreach (['nombre', 'telefono', 'correo', 'mensaje', 'respuesta'] as $name) {
                self::assertCount(
                    1,
                    $xpath->query("//*[@name=\"{$name}\" and @required]"),
                    "{$resource}: {$name} debe conservar la validación cliente"
                );
            }

            self::assertCount(
                1,
                $xpath->query(
                    '//input[@name="nombre" and @minlength="3" and @maxlength="40"]'
                )
            );
            self::assertCount(
                1,
                $xpath->query('//input[@name="telefono" and @maxlength="14"]')
            );
            self::assertCount(
                1,
                $xpath->query('//input[@name="correo" and @maxlength="100"]')
            );
            self::assertCount(
                1,
                $xpath->query(
                    '//textarea[@name="mensaje" and @minlength="10"'
                    . ' and @maxlength="500"]'
                )
            );

            foreach ([
                'nombre_error',
                'telefono_error',
                'correo_error',
                'mensaje_error',
                'captcha_error',
                'terminos_error',
            ] as $errorHook) {
                self::assertCount(
                    1,
                    $xpath->query("//*[@data-form-error=\"{$errorHook}\"]"),
                    "{$resource}: falta el hook local {$errorHook}"
                );
            }
        }

        $routes = $this->readFile(dirname(__DIR__, 2) . '/README.md');
        self::assertMatchesRegularExpression(
            "/['\"]\\/form['\"]\\s*=>\\s*['\"]formContact\\.php['\"]/",
            $routes
        );

        $backend = $this->readFile(Paths::appPath() . '/app/formContact.php');
        foreach ([
            'nombre',
            'telefono',
            'correo',
            'mensaje',
            'respuesta',
            'solucion',
            'lang',
        ] as $field) {
            self::assertStringContainsString(
                '$_POST["' . $field . '"]',
                $backend,
                "El módulo y formContact.php deben compartir {$field}"
            );
        }
        self::assertStringContainsString('new clase_comprobaciones', $backend);
        self::assertStringContainsString('devolver_respuesta(', $backend);

        foreach (['https://example.test/form', '//example.test/form'] as $endpoint) {
            $xpath = $this->createXpath(controller(
                'moduleFormContact01',
                0,
                ['endpoint' => $endpoint]
            ));

            self::assertCount(
                1,
                $xpath->query('//form[@action="/form"]'),
                'El formulario no debe aceptar endpoints externos'
            );
        }
    }

    public function testIdsAndTheirReferencesAreUniqueAcrossVariants(): void
    {
        $html = implode('', array_map(
            static fn (string $resource): string => controller($resource, 0),
            self::RESOURCES
        ));
        $xpath = $this->createXpath($html);
        $ids = [];

        foreach ($xpath->query('//*[@id]') as $element) {
            self::assertInstanceOf(DOMElement::class, $element);
            $id = $element->getAttribute('id');

            self::assertNotSame('', $id);
            self::assertArrayNotHasKey($id, $ids, "ID duplicado: {$id}");
            $ids[$id] = true;
        }

        self::assertNotEmpty($ids);

        foreach (['for', 'aria-labelledby', 'aria-describedby'] as $attribute) {
            foreach ($xpath->query("//*[@{$attribute}]") as $element) {
                self::assertInstanceOf(DOMElement::class, $element);

                foreach (
                    preg_split('/\s+/', trim($element->getAttribute($attribute)))
                    ?: []
                    as $target
                ) {
                    self::assertArrayHasKey(
                        $target,
                        $ids,
                        "{$attribute} referencia un ID inexistente: {$target}"
                    );
                }
            }
        }
    }

    public function testCoreBackendSeedsAreGenericAndTranslated(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $backend = $this->readFile(
            $coreRoot . '/stubs/App/app/formContact.php'
        );
        $mailer = $this->readFile(
            $coreRoot . '/stubs/App/app/_phpmailer.php'
        );
        $composer = $this->readJson($coreRoot . '/composer.json');

        self::assertStringNotContainsString('_aiwa', $backend);
        self::assertStringContainsString('_formContactAdmin.html', $backend);
        self::assertStringContainsString('_formContactUser.html', $backend);
        self::assertStringContainsString(
            'PHPMailer\\PHPMailer\\PHPMailer',
            $mailer
        );
        self::assertArrayHasKey('phpmailer/phpmailer', $composer['require']);

        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                $coreRoot
                . "/stubs/App/config/languages/_email/{$language}.json"
            );

            self::assertSame(
                ['fallos', 'consulta_admin', 'consulta_user'],
                array_keys($catalog)
            );
            foreach ([
                'captcha1',
                'captcha2',
                'nombre1',
                'nombre2',
                'nombre3',
                'tel1',
                'tel2',
                'correo1',
                'correo2',
                'correo3',
                'correo4',
                'consulta1',
                'consulta2',
            ] as $key) {
                self::assertArrayHasKey($key, $catalog['fallos']);
                self::assertNotSame('', trim((string) $catalog['fallos'][$key]));
            }

            foreach (['consulta_admin', 'consulta_user'] as $section) {
                foreach ([
                    'tipo',
                    'asunto',
                    'title',
                    'saludo',
                    'contexto',
                    'explicacion',
                    'datosEncabezado',
                    'headerNombre',
                    'headerTelefono',
                    'headerCorreo',
                    'headerDate',
                    'headerConsulta',
                    'despedida',
                    'equipo',
                ] as $key) {
                    self::assertArrayHasKey($key, $catalog[$section]);
                    self::assertNotSame(
                        '',
                        trim((string) $catalog[$section][$key])
                    );
                }
            }
        }
    }

    public function testTemplateCatalogsHydrateEveryEditableField(): void
    {
        foreach (['es', 'en', 'eu'] as $language) {
            $catalog = $this->readJson(
                Paths::appPath()
                . "/config/languages/templates/{$language}.json"
            );

            foreach (self::RESOURCES as $resource) {
                foreach (self::TEXT_SUFFIXES as $suffix) {
                    $key = "{$resource}_00_{$suffix}";

                    self::assertArrayHasKey($key, $catalog);
                    self::assertSame(
                        ['text'],
                        array_keys($catalog[$key] ?? []),
                        "{$language}: {$key} debe ser texto editable"
                    );
                    self::assertNotSame(
                        '',
                        trim((string) ($catalog[$key]['text'] ?? '')),
                        "{$language}: {$key} debe tener copy de showroom"
                    );
                }

                foreach (self::PLACEHOLDER_SUFFIXES as $suffix) {
                    $key = "{$resource}_00_{$suffix}";

                    self::assertArrayHasKey($key, $catalog);
                    self::assertSame(
                        ['placeholder'],
                        array_keys($catalog[$key] ?? []),
                        "{$language}: {$key} debe editar el placeholder"
                    );
                    self::assertNotSame(
                        '',
                        trim((string) ($catalog[$key]['placeholder'] ?? ''))
                    );
                }

                $privacyKey = "{$resource}_00_privacy";
                self::assertArrayHasKey($privacyKey, $catalog);
                self::assertEqualsCanonicalizing(
                    ['text', 'href', 'title'],
                    array_keys($catalog[$privacyKey] ?? [])
                );
                self::assertStringContainsString(
                    $resource,
                    (string) ($catalog["{$resource}_00_legend"]['text'] ?? '')
                );
            }
        }
    }

    public function testJavascriptIsRootScopedAsyncAndHmrSafe(): void
    {
        $javascript = $this->readFile(
            dirname(__DIR__, 2) . '/resources/js/_moduleFormContact.js'
        );

        self::assertStringContainsString(
            'document.querySelectorAll(".moduleFormContact").forEach(initForm)',
            $javascript
        );
        self::assertStringContainsString(
            'root.querySelector("[data-form-contact]")',
            $javascript
        );
        self::assertStringContainsString(
            'root.querySelectorAll("[data-form-error]")',
            $javascript
        );
        self::assertStringNotContainsString('getElementById', $javascript);
        self::assertStringContainsString('await fetch(', $javascript);
        self::assertStringContainsString('new FormData(form)', $javascript);
        self::assertStringContainsString('credentials: "same-origin"', $javascript);
        self::assertStringContainsString('removeEventListener(', $javascript);
        self::assertStringContainsString('HANDLERS_KEY', $javascript);
    }

    public function testExistingShowroomAndEntrypointRegistrationsStayComplete(): void
    {
        $showroom = ShowroomCatalogFixture::php(Paths::projectRoot());
        foreach (self::RESOURCES as $resource) {
            self::assertSame(
                1,
                preg_match_all(
                    "/controller\\(\\s*['\"]{$resource}['\"]\\s*,\\s*0\\b/",
                    $showroom
                ),
                "{$resource} debe aparecer una sola vez en el showroom"
            );
        }

        $coreRoot = dirname(__DIR__, 2);
        $scss = ShowroomCatalogFixture::scss($coreRoot);
        foreach (self::RESOURCES as $resource) {
            self::assertSame(
                1,
                substr_count(
                    $scss,
                    "@use '../resources/{$resource}';"
                ),
                "{$resource} debe importarse una sola vez"
            );
        }

        $javascript = ShowroomCatalogFixture::javascript($coreRoot);
        self::assertSame(
            1,
            substr_count(
                $javascript,
                "from '../resources/_moduleFormContact.js'"
            )
        );
        self::assertSame(
            1,
            substr_count($javascript, 'initModuleFormContact()')
        );
    }

    private function createXpath(string $html): DOMXPath
    {
        $previousInternalErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        self::assertTrue($loaded, 'El HTML de moduleFormContact no se pudo analizar');

        return new DOMXPath($document);
    }

    private function loadResourceGlobals(): void
    {
        $catalog = $this->readJson(
            Paths::appPath() . '/config/languages/templates/es.json'
        );

        foreach ($catalog as $key => $value) {
            foreach (self::RESOURCES as $resource) {
                if (!str_starts_with($key, "{$resource}_")) {
                    continue;
                }

                $this->setGlobal(
                    $key,
                    is_array($value) ? (object) $value : $value
                );
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(
            $this->readFile($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($decoded, "{$path} no contiene un objeto JSON");

        return $decoded;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        self::assertIsString($content, "No se pudo leer {$path}");

        return $content;
    }

    private function setGlobal(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->globalState)) {
            $this->globalState[$key] = array_key_exists($key, $GLOBALS)
                ? ['exists' => true, 'value' => $GLOBALS[$key]]
                : ['exists' => false];
        }

        $GLOBALS[$key] = $value;
    }
}
