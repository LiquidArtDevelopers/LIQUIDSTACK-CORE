<?php

namespace App\Core;

use App\Core\Environment\ProjectEnvironmentLoader;
use App\Core\Environment\ProjectEnvironmentLoadResult;
use App\Core\Http\Request;
use App\Core\Routing\ModulePublicRouteDispatcher;
use App\Core\Routing\ModuleRouteDispatcher;
use App\Core\Routing\ShowroomCategoryRoute;
use App\Core\Routing\UrlResolver;
use App\Core\Support\Paths;
use RuntimeException;

class Application
{
    private string $projectRoot;

    private ?string $coreRoot;

    public function __construct(string $projectRoot, ?string $coreRoot = null)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->coreRoot = $coreRoot;
        Paths::setProjectRoot($this->projectRoot);
    }

    public function run(): void
    {
        $environmentResult = $this->bootEnvironment();
        $environment = $environmentResult->values();

        /*
         * Mantiene el contrato legacy de $_ENV, pero usa la misma vista
         * combinada del entorno que los comandos CLI. De este modo, los
         * secretos inyectados por el proceso no desaparecen en instalaciones
         * cuyo variables_order no contiene la letra E.
         */
        $_ENV = array_replace($_ENV, $environment);

        $request = Request::fromGlobals();
        $moduleResponse = ModuleRouteDispatcher::forProject(
            $this->projectRoot,
            $environment,
            $this->coreRoot,
            $environmentResult->isUsable()
        )->dispatch($request);

        if ($moduleResponse !== null) {
            $moduleResponse->emit();
            return;
        }

        $publicRouteDispatcher = ModulePublicRouteDispatcher::forProject(
            $this->projectRoot,
            $environment,
            $this->coreRoot,
            $environmentResult->isUsable()
        );
        $moduleResponse = $publicRouteDispatcher->dispatchBeforeLegacy(
            $request
        );
        if ($moduleResponse !== null) {
            $moduleResponse->emit();
            return;
        }

        /*
         * Los módulos operativos tienen un bootstrap propio. Resolverlos antes
         * de incluir configuración legacy evita heredar sesiones, cookies,
         * cabeceras o efectos laterales de proyectos ya existentes.
         */
        $this->loadProjectConfig();

        $deferPublicSession = in_array(
            $request->method(),
            ['GET', 'HEAD'],
            true
        ) && $publicRouteDispatcher->claimsPublicRead($request);

        if (!$deferPublicSession) {
            // Preserve the exact legacy bootstrap order outside a namespace
            // that a public module may actually handle.
            $this->ensureSession();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->handlePost(
                $request,
                $publicRouteDispatcher
            );
            return;
        }

        $this->handleGet(
            $request,
            $publicRouteDispatcher,
            $deferPublicSession
        );
    }

    private function bootEnvironment(): ProjectEnvironmentLoadResult
    {
        $autoloadPath = $this->projectRoot . '/vendor/autoload.php';
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }

        return (new ProjectEnvironmentLoader())->load($this->projectRoot);
    }

    private function loadProjectConfig(): void
    {
        $configPath = Paths::appPath() . '/config/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('Config file not found at ' . $configPath);
        }
        require_once $configPath;

        $rolesPath = Paths::appPath() . '/config/enums/_roles.php';
        if (is_file($rolesPath)) {
            require_once $rolesPath;
        }
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function handlePost(
        Request $request,
        ModulePublicRouteDispatcher $publicRouteDispatcher
    ): void
    {
        $url            = urldecode($_SERVER['REQUEST_URI'] ?? '/');
        $routesPath     = Paths::appPath() . '/config/routes/post.php';
        $arrayRutasPost = is_file($routesPath) ? require $routesPath : [];

        if (isset($arrayRutasPost[$url])) {
            require_once Paths::appPath() . '/app/' . $arrayRutasPost[$url];
            return;
        }

        $moduleResponse = $publicRouteDispatcher->dispatch($request);
        if ($moduleResponse !== null) {
            $moduleResponse->emit();
            return;
        }

        $message_not_found = json_encode([
            'error'   => 404,
            'message' => 'Resource not found',
        ]);
        header('Content-type: application/json; charset=utf-8');
        http_response_code(404);
        echo $message_not_found;
    }

    private function handleGet(
        Request $request,
        ModulePublicRouteDispatcher $publicRouteDispatcher,
        bool $deferPublicSession
    ): void
    {
        $context = UrlResolver::resolve($_SERVER, $_COOKIE, $_ENV);

        $lang         = $context->lang;
        $url          = $context->url;
        $urlWithQuery = $context->urlWithQuery;

        $GLOBALS['langs']        = $context->langs;
        $GLOBALS['lang']         = $context->lang;
        $GLOBALS['url']          = $context->url;
        $GLOBALS['urlWithQuery'] = $context->urlWithQuery;
        $GLOBALS['urlLang']      = $context->urlLang;

        $arrayRutasGet = require Paths::appPath() . '/config/routes/get.php';
        $GLOBALS['arrayRutasGet'] = $arrayRutasGet;

        $rutasPorIdioma = $arrayRutasGet[$lang] ?? [];

        $requestedUrl = $urlWithQuery ?? $url;
        $matched      = isset($rutasPorIdioma[$requestedUrl]);

        if (!$matched) {
            $routeKey = $requestedUrl;
            $matched  = matchQueryRoute($routeKey, array_keys($rutasPorIdioma));
            if ($matched) {
                $requestedUrl = $routeKey;
            }
        }

        if ($matched) {
            $this->ensureRouteSession(
                $rutasPorIdioma[$requestedUrl],
                $deferPublicSession
            );
            $this->renderMatchedRoute(
                $lang,
                $requestedUrl,
                $rutasPorIdioma[$requestedUrl]
            );
            return;
        }

        $showroomRoute = ShowroomCategoryRoute::resolve($url, $rutasPorIdioma);
        if ($showroomRoute !== null) {
            $this->ensureRouteSession(
                $showroomRoute,
                $deferPublicSession
            );
            $this->renderMatchedRoute($lang, $url, $showroomRoute);
            return;
        }

        $moduleResponse = $publicRouteDispatcher->dispatch($request);
        if ($moduleResponse !== null) {
            $moduleResponse->emit();
            return;
        }

        // A modular miss returns to the established project 404 with all the
        // legacy guarantees, including an active PHP session.
        $this->ensureSession();
        $this->renderNotFound($lang);
    }

    /** @param array<string, mixed> $route */
    private function ensureRouteSession(
        array $route,
        bool $deferPublicSession
    ): void
    {
        if (
            $deferPublicSession
            && ($route['session'] ?? null) === false
        ) {
            return;
        }

        $this->ensureSession();
    }

    private function renderMatchedRoute(
        string $lang,
        string $requestedUrl,
        array $rutaConfig
    ): void
    {
        $url       = $requestedUrl;
        $view      = $rutaConfig['view'];
        $content   = $rutaConfig['content'] ?? null;
        $resources = $rutaConfig['resources'] ?? null;

        if (is_string($content) && $content !== '') {
            $data = $this->readLanguageCatalog('global', $lang);

            /*
             * Compatibilidad con stacks anteriores a la vista canónica
             * `_showroom.php`: CORE aporta las claves nuevas de `templates`
             * como base, mientras que el catálogo local `showroom` conserva
             * prioridad sobre cualquier copy ya personalizado.
             */
            if ($resources === 'templates' && $content === 'showroom') {
                $data = array_replace(
                    $data,
                    $this->readLanguageCatalog('templates', $lang)
                );
            }

            $data = array_replace(
                $data,
                $this->readLanguageCatalog($content, $lang)
            );

            if ($data) {
                extract($data);
                foreach ($data as $k => $v) {
                    $GLOBALS[$k] = $v;
                }
            }
        }

        if (is_string($resources) && $resources !== '' && !$this->isDevMode()) {
            $assets = $this->resolveProductionAssets($resources);

            if ($assets['css'] !== []) {
                // Preserve the legacy view contract: `$css` is one URL.
                $css = $assets['css'][0];
                $GLOBALS['css'] = $css;
            }
            if ($assets['js'] !== null) {
                $js = $assets['js'];
                $GLOBALS['js'] = $js;
            }
        }

        require_once $view;
    }

    /**
     * Resolve the exact Vite entry instead of guessing from a filename glob.
     *
     * A prefix such as `blog*` can also match `blogArticle` or a dynamic
     * showroom chunk named `blog`. The manifest is the only source that
     * preserves the relationship with `src/js/{resource}.js`.
     *
     * @return array{css: list<string>, js: ?string}
     */
    private function resolveProductionAssets(string $resources): array
    {
        $manifestPath = Paths::publicPath() . '/.vite/manifest.json';
        $manifest = null;

        if (is_file($manifestPath) && is_readable($manifestPath)) {
            $contents = file_get_contents($manifestPath);
            if (is_string($contents)) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $manifest = $decoded;
                }
            }
        }

        $entry = is_array($manifest)
            ? ($manifest["src/js/{$resources}.js"] ?? null)
            : null;
        if (is_array($entry)) {
            $jsPath = $this->validatedPublicAssetPath(
                $entry['file'] ?? null,
                'js'
            );
            if ($jsPath !== null) {
                $cssUrls = [];
                $cssPaths = $entry['css'] ?? [];
                if (is_array($cssPaths)) {
                    foreach ($cssPaths as $cssPath) {
                        $validated = $this->validatedPublicAssetPath(
                            $cssPath,
                            'css'
                        );
                        if ($validated !== null) {
                            $cssUrls[] = $this->publicAssetUrl($validated);
                        }
                    }
                }

                return [
                    'css' => array_values(array_unique($cssUrls)),
                    'js' => $this->publicAssetUrl($jsPath),
                ];
            }
        }

        return $this->resolveLegacyProductionAssets($resources);
    }

    /** @return array{css: list<string>, js: ?string} */
    private function resolveLegacyProductionAssets(string $resources): array
    {
        $cssFiles = glob(
            Paths::publicPath() . "/assets/css/{$resources}-*.css"
        );
        $jsFiles = glob(
            Paths::publicPath() . "/assets/js/{$resources}-*.js"
        );
        $cssFiles = is_array($cssFiles) ? $cssFiles : [];
        $jsFiles = is_array($jsFiles) ? $jsFiles : [];

        /*
         * A legacy build without a readable manifest is only unambiguous
         * when one candidate exists. Choosing the first of several files can
         * silently execute a different resource or an obsolete build.
         */
        $css = count($cssFiles) === 1
            ? [$this->publicAssetUrl('assets/css/' . basename($cssFiles[0]))]
            : [];
        $js = count($jsFiles) === 1
            ? $this->publicAssetUrl('assets/js/' . basename($jsFiles[0]))
            : null;

        return ['css' => $css, 'js' => $js];
    }

    /** @param mixed $path */
    private function validatedPublicAssetPath($path, string $type): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $expectedPrefix = "assets/{$type}/";
        $expectedSuffix = ".{$type}";
        if (
            !str_starts_with($path, $expectedPrefix)
            || !str_ends_with($path, $expectedSuffix)
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || !is_file(Paths::publicPath() . '/' . $path)
        ) {
            return null;
        }

        return $path;
    }

    private function publicAssetUrl(string $path): string
    {
        $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

        return $root . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function readLanguageCatalog(string $catalog, string $lang): array
    {
        $path = Paths::appPath()
            . "/config/languages/{$catalog}/{$lang}.json";

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents);

        return is_object($decoded)
            ? get_object_vars($decoded)
            : [];
    }

    private function renderNotFound(string $lang): void
    {
        
        $data = (array) json_decode(file_get_contents(Paths::appPath() . "/config/languages/global/{$lang}.json"));
        if ($data) {
            extract($data);
            foreach ($data as $k => $v) {
                $GLOBALS[$k] = $v;
            }
        }

        $data = (array) json_decode(file_get_contents(Paths::appPath() . "/config/languages/404/{$lang}.json"));
        if ($data) {
            extract($data);
            foreach ($data as $k => $v) {
                $GLOBALS[$k] = $v;
            }
        }

        $resources = '404';

        if (!$this->isDevMode()) {
            $cssFiles = glob(Paths::publicPath() . "/assets/css/{$resources}*.css");
            $jsFiles  = glob(Paths::publicPath() . "/assets/js/{$resources}*.js");

            if ($cssFiles) {
                $css              = ($_ENV['RAIZ'] ?? '') . '/assets/css/' . basename($cssFiles[0]);
                $GLOBALS['css'] = $css;
            }
            if ($jsFiles) {
                $js              = ($_ENV['RAIZ'] ?? '') . '/assets/js/' . basename($jsFiles[0]);
                $GLOBALS['js'] = $js;
            }
        }

        http_response_code(404);
        require_once Paths::appPath() . '/views/404.php';
    }

    private function isDevMode(): bool
    {
        $value = $_ENV['DEV_MODE'] ?? getenv('DEV_MODE');

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower((string) $value);

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
