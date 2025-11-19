<?php

namespace App\Core;

use App\Core\Routing\UrlResolver;
use App\Core\Support\Paths;
use Dotenv\Dotenv;
use RuntimeException;

class Application
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        Paths::setProjectRoot($this->projectRoot);
    }

    public function run(): void
    {
        $this->bootEnvironment();
        $this->loadProjectConfig();
        $this->ensureSession();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->handlePost();
            return;
        }

        $this->handleGet();
    }

    private function bootEnvironment(): void
    {
        $autoloadPath = $this->projectRoot . '/vendor/autoload.php';
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }

        $dotenv = Dotenv::createImmutable($this->projectRoot);
        $dotenv->safeLoad();
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

    private function handlePost(): void
    {
        $url            = urldecode($_SERVER['REQUEST_URI'] ?? '/');
        $routesPath     = Paths::appPath() . '/config/routes/post.php';
        $arrayRutasPost = is_file($routesPath) ? require $routesPath : [];

        if (isset($arrayRutasPost[$url])) {
            require_once Paths::appPath() . '/app/' . $arrayRutasPost[$url];
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

    private function handleGet(): void
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

        $this->renderNotFound($lang);
    }

    private function renderMatchedRoute(string $lang, string $requestedUrl, array $rutaConfig): void
    {
        $url       = $requestedUrl;
        $view      = $rutaConfig['view'];
        $content   = $rutaConfig['content'] ?? null;
        $resources = $rutaConfig['resources'] ?? null;

        if (is_string($content) && $content !== '') {
            $data = (array) json_decode(file_get_contents(Paths::appPath() . "/config/languages/{$content}/{$lang}.json"));
            if ($data) {
                extract($data);
                foreach ($data as $k => $v) {
                    $GLOBALS[$k] = $v;
                }
            }

            $data = (array) json_decode(file_get_contents(Paths::appPath() . "/config/languages/global/{$lang}.json"));
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

    private function renderNotFound(string $lang): void
    {
        $data = (array) json_decode(file_get_contents(Paths::appPath() . "/config/languages/404/{$lang}.json"));
        if ($data) {
            extract($data);
            foreach ($data as $k => $v) {
                $GLOBALS[$k] = $v;
            }
        }

        $data = (array) json_decode(file_get_contents(Paths::appPath() . "/config/languages/global/{$lang}.json"));
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
