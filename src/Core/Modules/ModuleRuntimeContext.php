<?php

declare(strict_types=1);

namespace App\Core\Modules;

use RuntimeException;
use Throwable;

final class ModuleRuntimeContext
{
    /** @var list<string>|null */
    private ?array $languages = null;

    /**
     * @param array<string, mixed> $environment
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $environment = [],
        private readonly bool $environmentUsable = true
    ) {
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    /** @return array<string, mixed> */
    public function environment(): array
    {
        return $this->environment;
    }

    public function environmentIsUsable(): bool
    {
        return $this->environmentUsable;
    }

    /** @return list<string> */
    public function languages(): array
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        $path = rtrim($this->projectRoot, '/\\') . '/App/config/langs.php';
        if (!file_exists($path) && !is_link($path)) {
            return $this->languages = [];
        }

        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException(
                'App/config/langs.php debe ser un fichero regular legible.'
            );
        }

        $languages = $this->loadLanguagesFile($path);
        if (!is_array($languages) || !array_is_list($languages)) {
            throw new RuntimeException(
                'App/config/langs.php debe devolver una lista de idiomas.'
            );
        }

        $normalized = [];
        foreach ($languages as $language) {
            if (
                !is_string($language)
                || preg_match('/\A[a-z]{2}(?:-[a-z0-9]{2,8})?\z/i', $language) !== 1
            ) {
                throw new RuntimeException(
                    'App/config/langs.php contiene un idioma no válido.'
                );
            }

            $normalized[] = strtolower($language);
        }

        return $this->languages = array_values(array_unique($normalized));
    }

    private function loadLanguagesFile(string $path): mixed
    {
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $languages = (static function (string $file): mixed {
                return require $file;
            })($path);
            $output = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw new RuntimeException(
                'App/config/langs.php no se pudo cargar de forma segura.'
            );
        }

        if ($output !== '') {
            throw new RuntimeException(
                'App/config/langs.php no puede emitir contenido.'
            );
        }

        return $languages;
    }
}
