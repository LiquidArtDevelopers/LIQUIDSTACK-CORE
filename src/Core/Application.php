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

        /*
         * Los módulos operativos tienen un bootstrap propio. Resolverlos antes
         * de incluir configuración legacy evita heredar sesiones, cookies,
         * cabeceras o efectos laterales de proyectos ya existentes.
         */
        $this->loadProjectConfig();
        $this->ensureSession();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->handlePost(
                $request,
                $environment,
                $environmentResult->isUsable()
            );
            return;
        }

        $this->handleGet(
            $request,
            $environment,
            $environmentResult->isUsable()
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

    /**
     * @param array<string, mixed> $environment
     */
    private function handlePost(
        Request $request,
        array $environment,
        bool $environmentUsable
    ): void
    {
        $url            = urldecode($_SERVER['REQUEST_URI'] ?? '/');
        $routesPath     = Paths::appPath() . '/config/routes/post.php';
        $arrayRutasPost = is_file($routesPath) ? require $routesPath : [];

        if (isset($arrayRutasPost[$url])) {
            require_once Paths::appPath() . '/app/' . $arrayRutasPost[$url];
            return;
        }

        $moduleResponse = ModulePublicRouteDispatcher::forProject(
            $this->projectRoot,
            $environment,
            $this->coreRoot,
            $environmentUsable
        )->dispatch($request);
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

    /**
     * @param array<string, mixed> $environment
     */
    private function handleGet(
        Request $request,
        array $environment,
        bool $environmentUsable
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
            $this->renderMatchedRoute($lang, $requestedUrl, $rutasPorIdioma[$requestedUrl]);
            return;
        }

        $showroomRoute = ShowroomCategoryRoute::resolve($url, $rutasPorIdioma);
        if ($showroomRoute !== null) {
            $this->renderMatchedRoute($lang, $url, $showroomRoute);
            return;
        }

        $moduleResponse = ModulePublicRouteDispatcher::forProject(
            $this->projectRoot,
            $environment,
            $this->coreRoot,
            $environmentUsable
        )->dispatch($request);
        if ($moduleResponse !== null) {
            $moduleResponse->emit();
            return;
        }

        $this->renderNotFound($lang);
    }

    private function renderMatchedRoute(string $lang, string $requestedUrl, array $rutaConfig): void
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

        require_once $view;
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
