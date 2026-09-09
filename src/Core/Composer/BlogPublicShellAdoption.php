<?php

declare(strict_types=1);

namespace App\Core\Composer;

use App\Core\Blog\PublicShell\BlogPublicShellDefaultSecurityPolicy;
use App\Core\Blog\PublicShell\BlogPublicShellSecurityConfig;
use App\Core\Environment\ProjectEnvironmentLoader;
use JsonException;
use ParseError;
use Throwable;

final class BlogPublicShellAdoption
{
    private const COOKIE_LAD_ORIGIN = 'https://webda.eus';

    /** @var list<string> */
    private const REQUIRED_PROJECT_FILES = [
        'App/views/blog-article.php',
        'src/js/blogArticle.js',
        'src/scss/blogArticle.scss',
        'App/app/_moduleBlogPublicArticle.php',
        'public/assets/modules/blog/blog-public.js',
        'App/includes/_globalHead.php',
        'App/includes/_globalBody.php',
        'App/includes/_nav.php',
        'App/includes/_footer.php',
        'App/controllers/_moduleBlogResources.php',
        'App/controllers/sectionBlogRelated01.php',
        'App/controllers/moduleButtonType04.php',
        'App/templates/_sectionBlogRelated01.html',
        'App/templates/_moduleButtonType04.html',
        'src/js/_global.js',
        'src/js/resources/_languagePreference.mjs',
    ];

    /** @var list<string> */
    private const PAGE_META_KEYS = [
        'title',
        'headline',
        'description',
        'canonical',
        'alternates',
        'x_default',
        'type',
        'image',
        'published_at',
        'updated_at',
    ];

    private const EXPECTED_KEY = BlogPublicShellAdoptionResult::CONFIG_KEY;
    private const EXPECTED_VALUE = BlogPublicShellAdoptionResult::CONFIG_VALUE;

    public function run(
        string $projectRoot,
        bool $apply
    ): BlogPublicShellAdoptionResult {
        $root = $this->regularProjectRoot($projectRoot);
        $initialPlan = $this->plan($root);
        if (!$apply) {
            return new BlogPublicShellAdoptionResult(
                $initialPlan['already_active'] ? 'already_active' : 'ready',
                false
            );
        }
        if ($initialPlan['already_active']) {
            return new BlogPublicShellAdoptionResult('already_active', false);
        }

        $lock = $this->acquireProjectLock($root);
        try {
            // Re-read every precondition while holding the command lock. This
            // prevents two adoption commands from applying the same stale
            // plan, while preserving edits made between preview and apply.
            $plan = $this->plan($root);
            if ($plan['already_active']) {
                return new BlogPublicShellAdoptionResult(
                    'already_active',
                    false
                );
            }

            $this->replaceConfig(
                $root,
                $plan['config_path'],
                $plan['original'],
                $plan['updated']
            );

            return new BlogPublicShellAdoptionResult('activated', true);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * @return array{
     *     already_active: bool,
     *     config_path: string,
     *     original: string|null,
     *     updated: string
     * }
     */
    private function plan(string $root): array
    {
        $this->assertBlogEnabled($root);
        foreach (self::REQUIRED_PROJECT_FILES as $relativePath) {
            $this->assertRegularProjectFile(
                $root,
                $relativePath,
                'blog.public_shell_adoption.scaffold_missing',
                'blog.public_shell_adoption.scaffold_path_invalid'
            );
        }

        $relativeConfig = BlogPublicShellAdoptionResult::CONFIG_PATH;
        $configTarget = $this->configTarget($root);
        $configPath = $configTarget['path'];
        if (!$configTarget['exists']) {
            $this->assertPublicShellContract(
                $root,
                $this->projectLocales($root, [])
            );

            return [
                'already_active' => false,
                'config_path' => $configPath,
                'original' => null,
                'updated' => $this->minimalConfig(),
            ];
        }
        $contents = @file_get_contents($configPath);
        if (!is_string($contents)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_unreadable',
                $relativeConfig
            );
        }

        $document = $this->parseLiteralReturnArray($contents);
        $this->assertPublicShellContract(
            $root,
            $this->projectLocales(
                $root,
                $this->publicPathLocales($document)
            )
        );
        $matches = [];
        foreach ($document['entries'] as $entry) {
            if (
                $entry['key'] === self::EXPECTED_KEY
                && $entry['key_type'] === 'string'
            ) {
                $matches[] = $entry;
            }
        }
        if (count($matches) > 1) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_value_incompatible',
                $relativeConfig
            );
        }
        if ($matches !== []) {
            $entry = $matches[0];
            if (
                $entry['value_type'] !== 'string'
                || $entry['value'] !== self::EXPECTED_VALUE
            ) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_value_incompatible',
                    $relativeConfig
                );
            }

            return [
                'already_active' => true,
                'config_path' => $configPath,
                'original' => $contents,
                'updated' => $contents,
            ];
        }

        $updated = $this->insertConfigEntry($contents, $document);
        $updatedDocument = $this->parseLiteralReturnArray($updated);
        $updatedMatches = array_values(array_filter(
            $updatedDocument['entries'],
            static fn (array $entry): bool =>
                $entry['key_type'] === 'string'
                && $entry['key'] === self::EXPECTED_KEY
        ));
        if (
            count($updatedMatches) !== 1
            || $updatedMatches[0]['value_type'] !== 'string'
            || $updatedMatches[0]['value'] !== self::EXPECTED_VALUE
        ) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_not_literal',
                $relativeConfig
            );
        }

        return [
            'already_active' => false,
            'config_path' => $configPath,
            'original' => $contents,
            'updated' => $updated,
        ];
    }

    private function regularProjectRoot(string $projectRoot): string
    {
        $candidate = rtrim($projectRoot, '/\\');
        if (
            $candidate === ''
            || !is_dir($candidate)
            || is_link($candidate)
        ) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.project_root_invalid'
            );
        }
        $resolved = realpath($candidate);
        if (!is_string($resolved) || $resolved === '' || is_link($resolved)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.project_root_invalid'
            );
        }

        return rtrim($resolved, '/\\');
    }

    private function assertBlogEnabled(string $root): void
    {
        $composerPath = $this->assertRegularProjectFile(
            $root,
            'composer.json',
            'blog.public_shell_adoption.composer_invalid',
            'blog.public_shell_adoption.composer_invalid'
        );
        $raw = @file_get_contents($composerPath);
        if (!is_string($raw)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.composer_invalid',
                'composer.json'
            );
        }

        try {
            $decoded = json_decode(
                str_starts_with($raw, "\xEF\xBB\xBF")
                    ? substr($raw, 3)
                    : $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.composer_invalid',
                'composer.json'
            );
        }
        $requirements = is_array($decoded)
            ? ($decoded['require'] ?? null)
            : null;
        if (
            !is_array($requirements)
            || array_is_list($requirements)
            || !array_key_exists('liquidstack/blog', $requirements)
        ) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.module_not_enabled',
                'composer.json'
            );
        }
    }

    private function assertRegularProjectFile(
        string $root,
        string $relativePath,
        string $missingCode,
        string $invalidCode
    ): string {
        $segments = explode('/', $relativePath);
        $cursor = $root;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new BlogPublicShellAdoptionException(
                    $invalidCode,
                    $relativePath
                );
            }
            if (!file_exists($cursor)) {
                throw new BlogPublicShellAdoptionException(
                    $missingCode,
                    $relativePath
                );
            }
        }
        if (!is_file($cursor) || !is_readable($cursor)) {
            throw new BlogPublicShellAdoptionException(
                $invalidCode,
                $relativePath
            );
        }
        $resolved = realpath($cursor);
        if (
            !is_string($resolved)
            || !$this->pathIsInside($resolved, $root)
        ) {
            throw new BlogPublicShellAdoptionException(
                $invalidCode,
                $relativePath
            );
        }

        return $cursor;
    }

    /** @return array{path: string, exists: bool} */
    private function configTarget(string $root): array
    {
        $app = $root . DIRECTORY_SEPARATOR . 'App';
        $config = $app . DIRECTORY_SEPARATOR . 'config';
        foreach ([$app, $config] as $directory) {
            if (
                !is_dir($directory)
                || is_link($directory)
                || !$this->pathIsInside(
                    (string) (realpath($directory) ?: ''),
                    $root
                )
            ) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_path_invalid',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
        }

        $modules = $config . DIRECTORY_SEPARATOR . 'modules';
        if (is_link($modules) || (file_exists($modules) && !is_dir($modules))) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_path_invalid',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }
        if (is_dir($modules)) {
            $resolvedModules = realpath($modules);
            if (
                !is_string($resolvedModules)
                || !$this->pathIsInside($resolvedModules, $root)
            ) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_path_invalid',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
        }

        $path = $modules . DIRECTORY_SEPARATOR . 'blog.php';
        if (is_link($path)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_path_invalid',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }
        if (!file_exists($path)) {
            return ['path' => $path, 'exists' => false];
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_path_invalid',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || !$this->pathIsInside($resolved, $root)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_path_invalid',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }

        return ['path' => $path, 'exists' => true];
    }

    private function minimalConfig(): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . "    '" . self::EXPECTED_KEY . "' => '"
            . self::EXPECTED_VALUE . "',\n];\n";
    }

    private function pathIsInside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return str_starts_with($path, $root . '/');
    }

    /** @param list<string> $locales */
    private function assertPublicShellContract(
        string $root,
        array $locales
    ): void {
        $this->assertArticleViewContract($root);
        $this->assertArticleHookContract($root);
        $this->assertGlobalHeadContract($root);
        $this->assertFrontendContract($root);
        $this->assertGlobalCatalogs($root, $locales);
        $this->assertPublicShellSecurity($root);
    }

    private function assertArticleViewContract(string $root): void
    {
        $relativePath = 'App/views/blog-article.php';
        $contents = $this->readProjectFile($root, $relativePath);
        $tokens = $this->phpTokens(
            $contents,
            'blog.public_shell_adoption.view_contract_invalid',
            $relativePath
        );
        $html = $this->htmlLandmarks($tokens);
        foreach ([
            'doctype',
            'head_open_end',
            'head_close',
            'body_open_end',
            'smooth_wrapper',
            'smooth_content',
            'main_close',
            'body_close',
        ] as $landmark) {
            if (!isset($html[$landmark])) {
                $this->contractFailure(
                    'blog.public_shell_adoption.view_contract_invalid',
                    $relativePath
                );
            }
        }
        if (
            !($html['doctype'] < $html['head_open_end'])
            || !($html['head_open_end'] < $html['head_close'])
            || !($html['head_close'] < $html['body_open_end'])
            || !($html['body_open_end'] < $html['smooth_wrapper'])
            || !($html['smooth_wrapper'] < $html['smooth_content'])
            || !($html['smooth_content'] < $html['main_close'])
            || !($html['main_close'] < $html['body_close'])
            || !($html['body_open_end'] < $html['body_close'])
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.view_contract_invalid',
                $relativePath
            );
        }

        $includes = $this->staticDirIncludes($tokens);
        $hook = $this->uniqueInclude(
            $includes,
            '/../app/_moduleBlogPublicArticle.php',
            [T_REQUIRE, T_REQUIRE_ONCE]
        );
        $globalHead = $this->uniqueInclude(
            $includes,
            '/../includes/_globalHead.php'
        );
        $globalBody = $this->uniqueInclude(
            $includes,
            '/../includes/_globalBody.php'
        );
        $navigation = $this->uniqueInclude(
            $includes,
            '/../includes/_nav.php'
        );
        $footer = $this->uniqueInclude(
            $includes,
            '/../includes/_footer.php'
        );
        if (
            $hook === null
            || $globalHead === null
            || $globalBody === null
            || $navigation === null
            || $footer === null
            || $hook['brace_depth'] !== 0
            || $globalHead['brace_depth'] !== 0
            || $globalBody['brace_depth'] !== 0
            || $navigation['brace_depth'] !== 0
            || $footer['brace_depth'] !== 0
            || !($hook['offset'] < $html['doctype'])
            || !($html['head_open_end'] < $globalHead['offset'])
            || !($globalHead['offset'] < $html['head_close'])
            || !($html['body_open_end'] < $globalBody['offset'])
            || !($globalBody['offset'] < $navigation['offset'])
            || !($navigation['offset'] < $html['smooth_wrapper'])
            || !($navigation['offset'] < $footer['offset'])
            || !($html['main_close'] < $footer['offset'])
            || !($footer['offset'] < $html['body_close'])
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.view_contract_invalid',
                $relativePath
            );
        }

        $projection = $this->templateOutputProjection($tokens);
        if (
            !$this->validArticleTemplateProjection($projection)
            || !$this->hasCspNonceScriptContract($tokens)
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.view_contract_invalid',
                $relativePath
            );
        }
    }

    private function assertArticleHookContract(string $root): void
    {
        $relativePath = 'App/app/_moduleBlogPublicArticle.php';
        $contents = $this->readProjectFile($root, $relativePath);
        $tokens = $this->phpTokens(
            $contents,
            'blog.public_shell_adoption.hook_contract_invalid',
            $relativePath
        );
        $semantic = preg_replace(
            '/\s+/',
            '',
            $this->semanticPhpSource($tokens)
        );
        if (!is_string($semantic)) {
            $this->contractFailure(
                'blog.public_shell_adoption.hook_contract_invalid',
                $relativePath
            );
        }
        foreach ([
            'BlogPublicArticleViewModel',
            'BlogPublicArticleShellContext',
            'BlogPublicArticleTaxonomyRenderer',
            '$cspNonce=$blogArticleShell->nonce()',
            '$blogPublicRuntimeUrl=$blogArticleShell->publicRuntimeUrl()',
            '$articleHero=$blogArticle->headerHtml()',
            '$articleMain=$blogArticle->mainHtml()',
            '$articleCustomCss=$blogArticle->customCss()',
            '$articleCategories=$blogArticle->categories()',
            '$articleTags=$blogArticle->tags()',
            '$articleTaxonomiesHtml=',
            'BlogPublicArticleTaxonomyRenderer())->render(',
            '$relatedArticles=$blogArticle->relatedArticles()',
        ] as $required) {
            if (!str_contains($semantic, $required)) {
                $this->contractFailure(
                    'blog.public_shell_adoption.hook_contract_invalid',
                    $relativePath
                );
            }
        }
        if (!isset($this->pageMetaKeys($tokens)['headline'])) {
            $this->contractFailure(
                'blog.public_shell_adoption.hook_contract_invalid',
                $relativePath
            );
        }
    }

    private function assertGlobalHeadContract(string $root): void
    {
        $relativePath = 'App/includes/_globalHead.php';
        $contents = $this->readProjectFile($root, $relativePath);
        $tokens = $this->phpTokens(
            $contents,
            'blog.public_shell_adoption.global_head_contract_invalid',
            $relativePath
        );
        $keys = $this->pageMetaKeys($tokens);
        foreach (self::PAGE_META_KEYS as $requiredKey) {
            if (!isset($keys[$requiredKey])) {
                $this->contractFailure(
                    'blog.public_shell_adoption.global_head_contract_invalid',
                    $relativePath
                );
            }
        }
        if (!$this->hasCspNonceScriptContract($tokens)) {
            $this->contractFailure(
                'blog.public_shell_adoption.global_head_contract_invalid',
                $relativePath
            );
        }
    }

    private function validArticleTemplateProjection(string $projection): bool
    {
        $htmlOpening = [];
        if (preg_match(
            '/<html\b(?<attributes>(?:"[^"]*"|\'[^\']*\'|[^>])*)>/is',
            $projection,
            $htmlOpening
        ) !== 1) {
            return false;
        }
        foreach ([
            'data-blog-analytics-enabled',
            'data-blog-analytics-retention-days',
            'data-blog-analytics-session-timeout',
            'data-blog-analytics-page-grant',
        ] as $attribute) {
            if (!str_contains($htmlOpening['attributes'], $attribute)) {
                return false;
            }
        }

        $wrapper = $this->htmlIdOffset($projection, 'smooth-wrapper');
        $content = $this->htmlIdOffset($projection, 'smooth-content');
        $mainMatches = [];
        if (preg_match_all(
            '/<main\b(?:(?:"[^"]*"|\'[^\']*\'|[^>])*)>'
                . '(?<body>.*?)<\/main\s*>/is',
            $projection,
            $mainMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) !== 1) {
            return false;
        }
        $mainOffset = $mainMatches[0][0][1];
        $mainBody = $mainMatches[0]['body'][0];
        $hero = strpos($projection, '__LIQUIDSTACK_ARTICLE_HERO__');
        $taxonomies = strpos(
            $mainBody,
            '__LIQUIDSTACK_ARTICLE_TAXONOMIES__'
        );
        $main = strpos($mainBody, '__LIQUIDSTACK_ARTICLE_MAIN__');
        if (
            !is_int($wrapper)
            || !is_int($content)
            || $hero === false
            || $taxonomies === false
            || $main === false
            || !($wrapper < $content && $content < $hero)
            || !($hero < $mainOffset)
            || !($taxonomies < $main)
        ) {
            return false;
        }

        $styles = [];
        if (preg_match_all(
            '/<style\b(?<attributes>(?:"[^"]*"|\'[^\']*\'|[^>])*)>'
                . '(?<body>.*?)<\/style\s*>/is',
            $projection,
            $styles,
            PREG_SET_ORDER
        ) < 1) {
            return false;
        }
        $customCssReady = false;
        foreach ($styles as $style) {
            if (
                $this->attributesUseCspNonce($style['attributes'])
                && str_contains(
                    $style['body'],
                    '__LIQUIDSTACK_ARTICLE_CUSTOM_CSS__'
                )
            ) {
                $customCssReady = true;
                break;
            }
        }
        if (!$customCssReady) {
            return false;
        }

        $scripts = [];
        preg_match_all(
            '/<script\b(?<attributes>(?:"[^"]*"|\'[^\']*\'|[^>])*)>/i',
            $projection,
            $scripts,
            PREG_SET_ORDER
        );
        foreach ($scripts as $script) {
            if (
                $this->attributesUseCspNonce($script['attributes'])
                && preg_match(
                    '/\bsrc\s*=\s*(?:"[^"]*'
                        . '__LIQUIDSTACK_PUBLIC_RUNTIME__[^"]*"'
                        . '|\'[^\']*__LIQUIDSTACK_PUBLIC_RUNTIME__'
                        . '[^\']*\')/i',
                    $script['attributes']
                ) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function htmlIdOffset(string $html, string $id): ?int
    {
        if (preg_match(
            '/\bid\s*=\s*(["\'])' . preg_quote($id, '/') . '\1/i',
            $html,
            $match,
            PREG_OFFSET_CAPTURE
        ) !== 1) {
            return null;
        }

        return $match[0][1];
    }

    /**
     * Project-owned templates may build a nonce attribute through helper
     * variables. Track those assignments back to the runtime-provided nonce,
     * then require every literal script element to emit it.
     *
     * @param list<array<string, mixed>> $tokens
     */
    private function hasCspNonceScriptContract(array $tokens): bool
    {
        $projection = $this->templateOutputProjection($tokens);
        $scripts = [];
        if (preg_match_all(
            '/<script\b(?<attributes>(?:"[^"]*"|\'[^\']*\'|[^>])*)>/i',
            $projection,
            $scripts,
            PREG_SET_ORDER
        ) < 1) {
            return false;
        }
        foreach ($scripts as $script) {
            if (!$this->attributesUseCspNonce($script['attributes'])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $tokens */
    private function templateOutputProjection(array $tokens): string
    {
        $assignments = [];
        $nonceAttributeVariables = [];
        $count = count($tokens);
        for ($index = 0; $index + 2 < $count; ++$index) {
            if (
                ($tokens[$index]['interpolated'] ?? false)
                || $tokens[$index]['id'] !== T_VARIABLE
            ) {
                continue;
            }
            $operator = $this->nextPhpToken($tokens, $index + 1);
            if (
                !isset($tokens[$operator])
                || $tokens[$operator]['text'] !== '='
            ) {
                continue;
            }
            $dependencies = [];
            $buildsNonceAttribute = false;
            $depth = 0;
            for ($cursor = $operator + 1; $cursor < $count; ++$cursor) {
                $candidate = $tokens[$cursor];
                if (!($candidate['interpolated'] ?? false)) {
                    if (in_array($candidate['text'], ['(', '[', '{'], true)) {
                        ++$depth;
                    } elseif (in_array(
                        $candidate['text'],
                        [')', ']', '}'],
                        true
                    )) {
                        $depth = max(0, $depth - 1);
                    } elseif (
                        ($candidate['text'] === ';'
                            || $candidate['id'] === T_CLOSE_TAG)
                        && $depth === 0
                    ) {
                        break;
                    }
                }
                if ($candidate['id'] === T_VARIABLE) {
                    $dependencies[$candidate['text']] = true;
                }
                if (
                    in_array($candidate['id'], [
                        T_CONSTANT_ENCAPSED_STRING,
                        T_ENCAPSED_AND_WHITESPACE,
                    ], true)
                    && preg_match('/\bnonce\s*=/i', $candidate['text']) === 1
                ) {
                    $buildsNonceAttribute = true;
                }
            }
            $target = $tokens[$index]['text'];
            $assignments[$target][] = array_keys($dependencies);
            if ($buildsNonceAttribute) {
                $nonceAttributeVariables[$target] = true;
            }
            if (count($assignments) > 256) {
                return '';
            }
        }

        $nonceAttributeCandidates = [];
        foreach (array_keys($nonceAttributeVariables) as $variable) {
            if ($this->variableDependsOn(
                $variable,
                '$cspNonce',
                $assignments,
                []
            )) {
                $nonceAttributeCandidates[$variable] = true;
            }
        }

        $projection = '';
        for ($index = 0; $index < $count; ++$index) {
            if ($tokens[$index]['id'] === T_INLINE_HTML) {
                $projection .= preg_replace(
                    '/<!--.*?-->/s',
                    ' ',
                    $tokens[$index]['text']
                ) ?? '';
                continue;
            }
            if ($tokens[$index]['id'] === T_OPEN_TAG_WITH_ECHO) {
                $start = $index + 1;
                while (
                    $index < $count
                    && $tokens[$index]['id'] !== T_CLOSE_TAG
                ) {
                    ++$index;
                }
                $projection .= $this->echoProjection(
                    array_slice($tokens, $start, $index - $start),
                    $assignments,
                    $nonceAttributeCandidates
                );
                continue;
            }
            if ($tokens[$index]['id'] !== T_ECHO) {
                continue;
            }
            $start = $index + 1;
            while (
                $index < $count
                && $tokens[$index]['text'] !== ';'
                && $tokens[$index]['id'] !== T_CLOSE_TAG
            ) {
                ++$index;
            }
            $projection .= $this->echoProjection(
                array_slice($tokens, $start, $index - $start),
                $assignments,
                $nonceAttributeCandidates
            );
        }

        return $projection;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @param array<string, list<list<string>>> $assignments
     * @param array<string, true> $nonceAttributeCandidates
     */
    private function echoProjection(
        array $tokens,
        array $assignments,
        array $nonceAttributeCandidates
    ): string {
        $variables = [];
        foreach ($tokens as $token) {
            if ($token['id'] === T_VARIABLE) {
                $variables[$token['text']] = true;
                if (isset($nonceAttributeCandidates[$token['text']])) {
                    return ' nonce="__LIQUIDSTACK_CSP_VALUE__" ';
                }
            }
        }
        $semantic = preg_replace(
            '/\s+/',
            '',
            $this->semanticPhpSource($tokens)
        ) ?? '';
        $markers = [];
        foreach ([
            '$cspNonce' => '__LIQUIDSTACK_CSP_VALUE__',
            '$articleHero' => '__LIQUIDSTACK_ARTICLE_HERO__',
            '$articleTaxonomiesHtml' =>
                '__LIQUIDSTACK_ARTICLE_TAXONOMIES__',
            '$articleMain' => '__LIQUIDSTACK_ARTICLE_MAIN__',
            '$articleCustomCss' => '__LIQUIDSTACK_ARTICLE_CUSTOM_CSS__',
            '$blogPublicRuntimeUrl' => '__LIQUIDSTACK_PUBLIC_RUNTIME__',
        ] as $source => $marker) {
            foreach (array_keys($variables) as $variable) {
                if ($this->variableDependsOn(
                    $variable,
                    $source,
                    $assignments,
                    []
                )) {
                    $markers[$marker] = true;
                    break;
                }
            }
        }
        foreach ([
            '->headerHtml()' => '__LIQUIDSTACK_ARTICLE_HERO__',
            '->mainHtml()' => '__LIQUIDSTACK_ARTICLE_MAIN__',
            '->customCss()' => '__LIQUIDSTACK_ARTICLE_CUSTOM_CSS__',
            '->publicRuntimeUrl()' => '__LIQUIDSTACK_PUBLIC_RUNTIME__',
        ] as $method => $marker) {
            if (str_contains($semantic, $method)) {
                $markers[$marker] = true;
            }
        }

        return $markers === []
            ? '__LIQUIDSTACK_PHP_ECHO__'
            : implode(' ', array_keys($markers));
    }

    private function attributesUseCspNonce(string $attributes): bool
    {
        return preg_match(
            '/\bnonce\s*=\s*(?:"[^"]*__LIQUIDSTACK_CSP_VALUE__[^"]*"'
                . '|\'[^\']*__LIQUIDSTACK_CSP_VALUE__[^\']*\')/i',
            $attributes
        ) === 1;
    }

    /**
     * @param array<string, list<list<string>>> $assignments
     * @param array<string, true> $visited
     */
    private function variableDependsOn(
        string $variable,
        string $source,
        array $assignments,
        array $visited
    ): bool {
        if ($variable === $source) {
            return true;
        }
        if (isset($visited[$variable])) {
            return false;
        }
        $visited[$variable] = true;
        foreach ($assignments[$variable] ?? [] as $dependencies) {
            foreach ($dependencies as $dependency) {
                if ($this->variableDependsOn(
                    $dependency,
                    $source,
                    $assignments,
                    $visited
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $tokens */
    private function semanticPhpSource(array $tokens): string
    {
        $semantic = '';
        foreach ($tokens as $token) {
            if (
                ($token['interpolated'] ?? false)
                || in_array($token['id'], [
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                    T_INLINE_HTML,
                    T_START_HEREDOC,
                    T_END_HEREDOC,
                ], true)
            ) {
                $semantic .= str_repeat(' ', strlen($token['text']));
                continue;
            }
            $semantic .= $token['text'];
        }

        return $semantic;
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @return array<string, true>
     */
    private function pageMetaKeys(
        array $tokens,
        bool $includeAssignedKeys = true
    ): array
    {
        $significant = array_values(array_filter(
            $tokens,
            static fn (array $token): bool =>
                !($token['interpolated'] ?? false)
                && !in_array($token['id'], [
                    T_OPEN_TAG,
                    T_OPEN_TAG_WITH_ECHO,
                    T_CLOSE_TAG,
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_INLINE_HTML,
                ], true)
        ));
        $keys = [];
        $count = count($significant);
        for ($index = 0; $index + 3 < $count; ++$index) {
            if (
                $significant[$index]['id'] !== T_VARIABLE
                || $significant[$index]['text'] !== '$pageMeta'
            ) {
                continue;
            }
            if (
                $significant[$index + 1]['text'] === '['
                && $significant[$index + 2]['id']
                    === T_CONSTANT_ENCAPSED_STRING
                && $significant[$index + 3]['text'] === ']'
            ) {
                $key = $this->decodeLiteralString(
                    $significant[$index + 2]['text']
                );
                if (is_string($key)) {
                    $keys[$key] = true;
                }
                continue;
            }
            if (
                !$includeAssignedKeys
                || $significant[$index + 1]['text'] !== '='
                || $significant[$index + 2]['text'] !== '['
            ) {
                continue;
            }
            $depth = 0;
            for ($cursor = $index + 2; $cursor < $count; ++$cursor) {
                if ($significant[$cursor]['text'] === '[') {
                    ++$depth;
                    continue;
                }
                if ($significant[$cursor]['text'] === ']') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if (
                    $depth === 1
                    && $significant[$cursor]['id']
                        !== T_CONSTANT_ENCAPSED_STRING
                ) {
                    continue;
                }
                if (
                    $depth !== 1
                    || ($significant[$cursor + 1]['id'] ?? null)
                        !== T_DOUBLE_ARROW
                ) {
                    continue;
                }
                $key = $this->decodeLiteralString(
                    $significant[$cursor]['text']
                );
                if (is_string($key)) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    private function assertFrontendContract(string $root): void
    {
        $entryImports = $this->javascriptImports(
            $root,
            'src/js/blogArticle.js'
        );
        $required = [
            'src/js/_global.js' => false,
            'src/js/resources/_languagePreference.mjs' => false,
            'src/scss/blogArticle.scss' => false,
        ];
        $languageBinding = null;
        foreach ($entryImports as $import) {
            if (array_key_exists($import['resolved'], $required)) {
                $required[$import['resolved']] = true;
            }
            if (
                $import['resolved']
                    === 'src/js/resources/_languagePreference.mjs'
            ) {
                $binding = [];
                if (preg_match(
                    '/(?:\{|,)\s*bindLanguageNavigation'
                        . '(?:\s+as\s+'
                        . '(?<local>[A-Za-z_$][A-Za-z0-9_$]*))?'
                        . '\s*(?=,|\})/',
                    $import['clause'],
                    $binding
                ) === 1) {
                    $local = $binding['local'] ?? '';
                    $languageBinding = $local !== ''
                        ? $local
                        : 'bindLanguageNavigation';
                }
            }
        }
        if (
            in_array(false, $required, true)
            || !is_string($languageBinding)
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.entry_contract_invalid',
                'src/js/blogArticle.js'
            );
        }
        $this->assertDirectScssImports(
            $root,
            'src/scss/blogArticle.scss'
        );
        $entry = $this->withoutJavascriptComments(
            $this->readProjectFile($root, 'src/js/blogArticle.js')
        );
        $code = preg_replace(
            '/(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"'
                . '|`(?:\\\\.|[^`\\\\])*`)/s',
            ' ',
            $entry
        );
        if (
            !is_string($code)
            || preg_match(
                '/\b' . preg_quote($languageBinding, '/') . '\s*\('
                    . '\s*window\s*,\s*document\b/',
                $code
            ) !== 1
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.entry_contract_invalid',
                'src/js/blogArticle.js'
            );
        }
    }

    /**
     * @return list<array{clause: string, specifier: string, resolved: string}>
     */
    private function javascriptImports(
        string $root,
        string $relativePath
    ): array {
        $source = $this->withoutJavascriptComments(
            $this->readDependency($root, $relativePath)
        );
        $matches = [];
        preg_match_all(
            '/(?:^|[;\r\n])\s*import\s+'
                . '(?:(?<clause>[^\'";]+?)\s+from\s+)?'
                . '(?<quote>[\'"])(?<specifier>[^\'"]+)\k<quote>\s*;?/m',
            $source,
            $matches,
            PREG_SET_ORDER
        );
        $imports = [];
        foreach ($matches as $match) {
            $specifier = $match['specifier'] ?? '';
            if (!is_string($specifier) || !str_starts_with($specifier, '.')) {
                continue;
            }
            $resolved = $this->resolveLocalImport(
                $root,
                $relativePath,
                $specifier,
                false
            );
            $imports[] = [
                'clause' => is_string($match['clause'] ?? null)
                    ? trim($match['clause'])
                    : '',
                'specifier' => $specifier,
                'resolved' => $resolved,
            ];
        }

        return $imports;
    }

    private function assertDirectScssImports(
        string $root,
        string $relativePath
    ): void {
        $source = $this->withoutJavascriptComments(
            $this->readDependency($root, $relativePath)
        );
        $matches = [];
        preg_match_all(
            '/@(use|forward|import)\s+([\'"])(\.[^\'"]+)\2/i',
            $source,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $this->resolveLocalImport(
                $root,
                $relativePath,
                $match[3],
                true
            );
        }
    }

    private function assertPublicShellSecurity(string $root): void
    {
        $environment = (new ProjectEnvironmentLoader())->load($root);
        if (!$environment->isUsable()) {
            $this->contractFailure(
                'blog.public_shell_adoption.security_not_ready',
                BlogPublicShellSecurityConfig::PROJECT_FILE
            );
        }
        try {
            $config = BlogPublicShellSecurityConfig::fromProject(
                $root,
                BlogPublicShellDefaultSecurityPolicy::fromEnvironment(
                    $environment->values(),
                    $environment->isUsable()
                )
            );
            $headers = $config->securityPolicy()->context()->headers();
        } catch (Throwable) {
            $this->contractFailure(
                'blog.public_shell_adoption.security_not_ready',
                BlogPublicShellSecurityConfig::PROJECT_FILE
            );
        }
        if (
            $this->projectShellUsesCookieLad($root)
            && !$this->cspAllowsCookieLad($headers)
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.security_not_ready',
                BlogPublicShellSecurityConfig::PROJECT_FILE
            );
        }
    }

    private function projectShellUsesCookieLad(string $root): bool
    {
        foreach ([
            'App/views/blog-article.php',
            'App/includes/_globalHead.php',
            'App/includes/_globalBody.php',
        ] as $relativePath) {
            $source = $this->activePhpHtmlSource(
                $this->readProjectFile($root, $relativePath)
            );
            if (preg_match(
                '#https://webda\.eus/apis/cookielad/loader\.js'
                    . '(?:\?|[\'"\s<])#i',
                $source
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    private function activePhpHtmlSource(string $source): string
    {
        $active = '';
        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }
            $active .= is_array($token) ? $token[1] : $token;
        }

        return preg_replace('/<!--.*?-->/s', ' ', $active) ?? '';
    }

    /** @param array<string, string> $headers */
    private function cspAllowsCookieLad(array $headers): bool
    {
        $value = $headers['Content-Security-Policy'] ?? null;
        if (!is_string($value)) {
            return false;
        }
        $directives = [];
        foreach (explode(';', $value) as $directive) {
            $tokens = preg_split('/\s+/', trim($directive));
            if (!is_array($tokens) || $tokens === []) {
                continue;
            }
            $name = strtolower((string) array_shift($tokens));
            $directives[$name] = $tokens;
        }
        foreach (['script-src', 'style-src', 'img-src', 'connect-src'] as $name) {
            if (!in_array(
                self::COOKIE_LAD_ORIGIN,
                $directives[$name] ?? [],
                true
            )) {
                return false;
            }
        }

        return true;
    }

    private function resolveLocalImport(
        string $root,
        string $from,
        string $specifier,
        bool $sass
    ): string {
        if (
            str_contains($specifier, "\0")
            || str_contains($specifier, '?')
            || str_contains($specifier, '#')
            || str_contains($specifier, '\\')
        ) {
            $this->contractFailure(
                'blog.public_shell_adoption.scaffold_dependency_path_invalid',
                $from
            );
        }
        $segments = explode('/', dirname($from) . '/' . $specifier);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($normalized === []) {
                    $this->contractFailure(
                        'blog.public_shell_adoption.scaffold_dependency_path_invalid',
                        $from
                    );
                }
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }
        $base = implode('/', $normalized);
        $allowedProjectTree = str_starts_with($base, 'src/')
            || (!$sass && str_starts_with($base, 'App/'));
        if (!$allowedProjectTree) {
            $this->contractFailure(
                'blog.public_shell_adoption.scaffold_dependency_path_invalid',
                $from
            );
        }

        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $candidates = [$base];
        if ($sass && $extension === '') {
            $directory = dirname($base);
            $name = basename($base);
            $prefix = $directory === '.' ? '' : $directory . '/';
            $candidates = [
                $base . '.scss',
                $prefix . '_' . $name . '.scss',
                $base . '/index.scss',
                $base . '/_index.scss',
            ];
        } elseif (!$sass && $extension === '') {
            $candidates = [
                $base . '.js',
                $base . '.mjs',
                $base . '/index.js',
                $base . '/index.mjs',
            ];
        }
        foreach ($candidates as $candidate) {
            $absolute = $root . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            if (file_exists($absolute) || is_link($absolute)) {
                $this->assertRegularProjectFile(
                    $root,
                    $candidate,
                    'blog.public_shell_adoption.scaffold_dependency_missing',
                    'blog.public_shell_adoption.scaffold_dependency_path_invalid'
                );

                return $candidate;
            }
        }

        throw new BlogPublicShellAdoptionException(
            'blog.public_shell_adoption.scaffold_dependency_missing',
            $candidates[0]
        );
    }

    private function readDependency(string $root, string $relativePath): string
    {
        $this->assertRegularProjectFile(
            $root,
            $relativePath,
            'blog.public_shell_adoption.scaffold_dependency_missing',
            'blog.public_shell_adoption.scaffold_dependency_path_invalid'
        );

        return $this->readProjectFile($root, $relativePath);
    }

    private function readProjectFile(string $root, string $relativePath): string
    {
        $path = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.scaffold_path_invalid',
                $relativePath
            );
        }

        return $contents;
    }

    /**
     * @param list<string> $publicPathLocales
     * @return list<string>
     */
    private function projectLocales(
        string $root,
        array $publicPathLocales
    ): array {
        $relativePath = 'App/config/langs.php';
        $path = $this->assertRegularProjectFile(
            $root,
            $relativePath,
            'blog.public_shell_adoption.locale_config_missing',
            'blog.public_shell_adoption.locale_config_invalid'
        );
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            $this->contractFailure(
                'blog.public_shell_adoption.locale_config_invalid',
                $relativePath
            );
        }
        $locales = $this->parseLiteralLocaleList($contents, $relativePath);
        foreach ($publicPathLocales as $locale) {
            if (!in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    /** @return list<string> */
    private function parseLiteralLocaleList(
        string $contents,
        string $relativePath
    ): array {
        $source = str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            $this->contractFailure(
                'blog.public_shell_adoption.locale_config_invalid',
                $relativePath
            );
        }
        $tokens = [];
        $offset = 0;
        foreach ($rawTokens as $rawToken) {
            $id = is_array($rawToken) ? $rawToken[0] : null;
            $text = is_array($rawToken) ? $rawToken[1] : $rawToken;
            $start = $offset;
            $offset += strlen($text);
            if (in_array($id, [
                T_OPEN_TAG,
                T_CLOSE_TAG,
                T_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
            ], true)) {
                continue;
            }
            if ($id === T_INLINE_HTML) {
                if (trim($text) === '') {
                    continue;
                }
                $this->contractFailure(
                    'blog.public_shell_adoption.locale_config_invalid',
                    $relativePath
                );
            }
            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'start' => $start,
                'end' => $offset,
            ];
        }

        try {
            $index = 0;
            if (!$this->tokenId($tokens, $index, T_RETURN)) {
                $this->notLiteral();
            }
            ++$index;
            $array = $this->parseLiteralValue($tokens, $index);
            if ($array['type'] !== 'array') {
                $this->notLiteral();
            }
            if ($this->tokenText($tokens, $index, ';')) {
                ++$index;
            }
            if ($index !== count($tokens)) {
                $this->notLiteral();
            }
        } catch (BlogPublicShellAdoptionException) {
            $this->contractFailure(
                'blog.public_shell_adoption.locale_config_invalid',
                $relativePath
            );
        }

        $locales = [];
        foreach ($array['entries'] as $entry) {
            $locale = $entry['value'] ?? null;
            $normalizedLocale = $this->normalizeLocale($locale);
            if (
                $entry['key_type'] !== 'implicit'
                || $entry['value_type'] !== 'string'
                || $normalizedLocale === null
            ) {
                $this->contractFailure(
                    'blog.public_shell_adoption.locale_config_invalid',
                    $relativePath
                );
            }
            $locales[$normalizedLocale] = true;
        }
        if ($locales === [] || count($locales) > 64) {
            $this->contractFailure(
                'blog.public_shell_adoption.locale_config_invalid',
                $relativePath
            );
        }

        return array_keys($locales);
    }

    /** @param list<string> $locales */
    private function assertGlobalCatalogs(string $root, array $locales): void
    {
        $directory = 'App/config/languages/global';
        $this->assertRegularProjectDirectory(
            $root,
            $directory,
            'blog.public_shell_adoption.catalog_missing',
            'blog.public_shell_adoption.catalog_invalid'
        );
        foreach ($locales as $locale) {
            $relativePath = $directory . '/' . $locale . '.json';
            $path = $this->assertRegularProjectFile(
                $root,
                $relativePath,
                'blog.public_shell_adoption.catalog_missing',
                'blog.public_shell_adoption.catalog_invalid'
            );
            $raw = @file_get_contents($path);
            try {
                $decoded = is_string($raw)
                    ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR)
                    : null;
            } catch (JsonException) {
                $decoded = null;
            }
            if (!is_object($decoded)) {
                $this->contractFailure(
                    'blog.public_shell_adoption.catalog_invalid',
                    $relativePath
                );
            }
        }
    }

    private function assertRegularProjectDirectory(
        string $root,
        string $relativePath,
        string $missingCode,
        string $invalidCode
    ): string {
        $cursor = $root;
        foreach (explode('/', $relativePath) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new BlogPublicShellAdoptionException(
                    $invalidCode,
                    $relativePath
                );
            }
            if (!file_exists($cursor)) {
                throw new BlogPublicShellAdoptionException(
                    $missingCode,
                    $relativePath
                );
            }
        }
        $resolved = realpath($cursor);
        if (
            !is_dir($cursor)
            || !is_string($resolved)
            || !$this->pathIsInside($resolved, $root)
        ) {
            throw new BlogPublicShellAdoptionException(
                $invalidCode,
                $relativePath
            );
        }

        return $cursor;
    }

    /**
     * @param array{entries: list<array<string, mixed>>} $document
     * @return list<string>
     */
    private function publicPathLocales(array $document): array
    {
        foreach ($document['entries'] as $entry) {
            if (
                $entry['key_type'] !== 'string'
                || $entry['key'] !== 'public_paths'
                || $entry['value_type'] !== 'array'
                || !is_array($entry['value'])
            ) {
                continue;
            }
            $locales = [];
            foreach ($entry['value'] as $pathEntry) {
                $locale = $pathEntry['key'] ?? null;
                $normalizedLocale = $this->normalizeLocale($locale);
                if (
                    ($pathEntry['key_type'] ?? null) === 'string'
                    && $normalizedLocale !== null
                    && ($pathEntry['value_type'] ?? null) === 'string'
                ) {
                    $locales[$normalizedLocale] = true;
                }
            }

            return array_keys($locales);
        }

        return [];
    }

    private function normalizeLocale(mixed $locale): ?string
    {
        if (
            !is_string($locale)
            || preg_match(
                '/\A[a-z]{2}(?:-[a-z0-9]{2,8})?\z/i',
                $locale
            ) !== 1
        ) {
            return null;
        }

        return strtolower($locale);
    }

    /**
     * @return list<array{id: int|null, text: string, start: int, end: int}>
     */
    private function phpTokens(
        string $contents,
        string $issueCode,
        string $relativePath
    ): array {
        try {
            $rawTokens = token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError) {
            $this->contractFailure($issueCode, $relativePath);
        }
        $tokens = [];
        $offset = 0;
        $interpolatedDelimiter = null;
        foreach ($rawTokens as $rawToken) {
            $id = is_array($rawToken) ? $rawToken[0] : null;
            $text = is_array($rawToken) ? $rawToken[1] : $rawToken;
            $interpolated = $interpolatedDelimiter !== null;
            if ($id === T_START_HEREDOC) {
                $interpolated = true;
                $interpolatedDelimiter = 'heredoc';
            } elseif ($id === T_END_HEREDOC) {
                $interpolated = true;
                $interpolatedDelimiter = null;
            } elseif ($id === null && ($text === '"' || $text === '`')) {
                $interpolated = true;
                $interpolatedDelimiter = $interpolatedDelimiter === null
                    ? $text : null;
            }
            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'start' => $offset,
                'end' => $offset + strlen($text),
                'interpolated' => $interpolated,
            ];
            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     * @return array<string, int>
     */
    private function htmlLandmarks(array $tokens): array
    {
        $patterns = [
            'doctype' => '/<!doctype\s+html\b[^>]*>/i',
            'head_open_end' => '/<head\b[^>]*>/i',
            'head_close' => '/<\/head\s*>/i',
            'body_open_end' => '/<body\b[^>]*>/i',
            'smooth_wrapper' => '/<[^>]+\bid\s*=\s*(["\'])'
                . 'smooth-wrapper\1[^>]*>/i',
            'smooth_content' => '/<[^>]+\bid\s*=\s*(["\'])'
                . 'smooth-content\1[^>]*>/i',
            'main_close' => '/<\/main\s*>/i',
            'body_close' => '/<\/body\s*>/i',
        ];
        $positions = [];
        foreach ($tokens as $token) {
            if ($token['id'] !== T_INLINE_HTML) {
                continue;
            }
            $visible = preg_replace_callback(
                '/<!--.*?-->/s',
                static fn (array $match): string => str_repeat(
                    ' ',
                    strlen($match[0])
                ),
                $token['text']
            );
            if (!is_string($visible)) {
                continue;
            }
            foreach ($patterns as $name => $pattern) {
                if (isset($positions[$name])) {
                    continue;
                }
                if (preg_match(
                    $pattern,
                    $visible,
                    $match,
                    PREG_OFFSET_CAPTURE
                ) !== 1) {
                    continue;
                }
                $position = $token['start'] + $match[0][1];
                $positions[$name] = str_ends_with($name, '_open_end')
                    ? $position + strlen($match[0][0])
                    : $position;
            }
        }

        return $positions;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     * @return list<array{kind: int, path: string, offset: int, brace_depth: int}>
     */
    private function staticDirIncludes(array $tokens): array
    {
        $includes = [];
        $braceDepth = 0;
        foreach ($tokens as $index => $token) {
            if ($token['text'] === '{') {
                ++$braceDepth;
                continue;
            }
            if ($token['text'] === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                continue;
            }
            if (!in_array($token['id'], [
                T_REQUIRE,
                T_REQUIRE_ONCE,
                T_INCLUDE,
                T_INCLUDE_ONCE,
            ], true)) {
                continue;
            }
            $cursor = $this->nextPhpToken($tokens, $index + 1);
            $parenthesized = isset($tokens[$cursor])
                && $tokens[$cursor]['text'] === '(';
            if ($parenthesized) {
                $cursor = $this->nextPhpToken($tokens, $cursor + 1);
            }
            if (!isset($tokens[$cursor]) || $tokens[$cursor]['id'] !== T_DIR) {
                continue;
            }
            $cursor = $this->nextPhpToken($tokens, $cursor + 1);
            if (!isset($tokens[$cursor]) || $tokens[$cursor]['text'] !== '.') {
                continue;
            }
            $cursor = $this->nextPhpToken($tokens, $cursor + 1);
            if (
                !isset($tokens[$cursor])
                || $tokens[$cursor]['id'] !== T_CONSTANT_ENCAPSED_STRING
            ) {
                continue;
            }
            $path = $this->decodeLiteralString($tokens[$cursor]['text']);
            $cursor = $this->nextPhpToken($tokens, $cursor + 1);
            if ($parenthesized) {
                if (
                    !isset($tokens[$cursor])
                    || $tokens[$cursor]['text'] !== ')'
                ) {
                    continue;
                }
                $cursor = $this->nextPhpToken($tokens, $cursor + 1);
            }
            if (
                !is_string($path)
                || !isset($tokens[$cursor])
                || (
                    $tokens[$cursor]['text'] !== ';'
                    && $tokens[$cursor]['id'] !== T_CLOSE_TAG
                )
            ) {
                continue;
            }
            $includes[] = [
                'kind' => $token['id'],
                'path' => str_replace('\\', '/', $path),
                'offset' => $token['start'],
                'brace_depth' => $braceDepth,
            ];
        }

        return $includes;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     */
    private function nextPhpToken(array $tokens, int $index): int
    {
        while (
            isset($tokens[$index])
            && in_array($tokens[$index]['id'], [
                T_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
            ], true)
        ) {
            ++$index;
        }

        return $index;
    }

    /**
     * @param list<array{kind: int, path: string, offset: int, brace_depth: int}> $includes
     * @param list<int>|null $kinds
     * @return array{kind: int, path: string, offset: int, brace_depth: int}|null
     */
    private function uniqueInclude(
        array $includes,
        string $path,
        ?array $kinds = null
    ): ?array {
        $matches = array_values(array_filter(
            $includes,
            static fn (array $include): bool =>
                $include['path'] === $path
                && ($kinds === null
                    || in_array($include['kind'], $kinds, true))
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function withoutJavascriptComments(string $source): string
    {
        $result = '';
        $state = 'code';
        $quote = '';
        $length = strlen($source);
        for ($index = 0; $index < $length; ++$index) {
            $character = $source[$index];
            $next = $index + 1 < $length ? $source[$index + 1] : '';
            if ($state === 'line') {
                if ($character === "\n" || $character === "\r") {
                    $state = 'code';
                    $result .= $character;
                } else {
                    $result .= ' ';
                }
                continue;
            }
            if ($state === 'block') {
                if ($character === '*' && $next === '/') {
                    $result .= '  ';
                    ++$index;
                    $state = 'code';
                } else {
                    $result .= in_array($character, ["\n", "\r"], true)
                        ? $character
                        : ' ';
                }
                continue;
            }
            if ($state === 'string') {
                $result .= $character;
                if ($character === '\\' && $next !== '') {
                    $result .= $next;
                    ++$index;
                } elseif ($character === $quote) {
                    $state = 'code';
                }
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $state = 'string';
                $quote = $character;
                $result .= $character;
                continue;
            }
            if ($character === '/' && $next === '/') {
                $result .= '  ';
                ++$index;
                $state = 'line';
                continue;
            }
            if ($character === '/' && $next === '*') {
                $result .= '  ';
                ++$index;
                $state = 'block';
                continue;
            }
            $result .= $character;
        }

        return $result;
    }

    private function contractFailure(string $code, string $path): never
    {
        throw new BlogPublicShellAdoptionException($code, $path);
    }

    /**
     * @return array{
     *     entries: list<array{
     *         key_type: string,
     *         key: int|string|null,
     *         value_type: string,
     *         value: mixed
     *     }>,
     *     close_offset: int,
     *     first_entry_offset: int|null,
     *     last_value_end: int|null,
     *     trailing_comma: bool
     * }
     */
    private function parseLiteralReturnArray(string $contents): array
    {
        $bomBytes = str_starts_with($contents, "\xEF\xBB\xBF") ? 3 : 0;
        $source = $bomBytes === 3 ? substr($contents, 3) : $contents;
        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_not_literal',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }

        $tokens = [];
        $offset = $bomBytes;
        foreach ($rawTokens as $rawToken) {
            $id = is_array($rawToken) ? $rawToken[0] : null;
            $text = is_array($rawToken) ? $rawToken[1] : $rawToken;
            $start = $offset;
            $offset += strlen($text);
            if (in_array($id, [
                T_OPEN_TAG,
                T_CLOSE_TAG,
                T_WHITESPACE,
                T_COMMENT,
                T_DOC_COMMENT,
            ], true)) {
                continue;
            }
            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'start' => $start,
                'end' => $offset,
            ];
        }

        $index = 0;
        if ($this->tokenId($tokens, $index, T_DECLARE)) {
            ++$index;
            $this->expectText($tokens, $index, '(');
            if (
                !$this->tokenId($tokens, $index, T_STRING)
                || strtolower($tokens[$index]['text']) !== 'strict_types'
            ) {
                $this->notLiteral();
            }
            ++$index;
            $this->expectText($tokens, $index, '=');
            if (
                !$this->tokenId($tokens, $index, T_LNUMBER)
                || $tokens[$index]['text'] !== '1'
            ) {
                $this->notLiteral();
            }
            ++$index;
            $this->expectText($tokens, $index, ')');
            $this->expectText($tokens, $index, ';');
        }
        if (!$this->tokenId($tokens, $index, T_RETURN)) {
            $this->notLiteral();
        }
        ++$index;
        $array = $this->parseLiteralValue($tokens, $index);
        if ($array['type'] !== 'array') {
            $this->notLiteral();
        }
        $this->expectText($tokens, $index, ';');
        if ($index !== count($tokens)) {
            $this->notLiteral();
        }

        return [
            'entries' => $array['entries'],
            'close_offset' => $array['close_offset'],
            'first_entry_offset' => $array['first_entry_offset'],
            'last_value_end' => $array['last_value_end'],
            'trailing_comma' => $array['trailing_comma'],
        ];
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     * @return array<string, mixed>
     */
    private function parseLiteralValue(array $tokens, int &$index): array
    {
        if (!isset($tokens[$index])) {
            $this->notLiteral();
        }
        $token = $tokens[$index];
        if ($token['text'] === '[' || $token['id'] === T_ARRAY) {
            return $this->parseLiteralArray($tokens, $index);
        }
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            ++$index;

            return [
                'type' => 'string',
                'value' => $this->decodeLiteralString($token['text']),
                'start' => $token['start'],
                'end' => $token['end'],
            ];
        }
        if ($token['id'] === T_LNUMBER) {
            ++$index;

            return [
                'type' => 'integer',
                'value' => (int) str_replace('_', '', $token['text']),
                'start' => $token['start'],
                'end' => $token['end'],
            ];
        }
        if ($token['id'] === T_DNUMBER) {
            ++$index;

            return [
                'type' => 'float',
                'value' => (float) str_replace('_', '', $token['text']),
                'start' => $token['start'],
                'end' => $token['end'],
            ];
        }
        if ($token['id'] === T_STRING) {
            $keyword = strtolower($token['text']);
            if (!in_array($keyword, ['true', 'false', 'null'], true)) {
                $this->notLiteral();
            }
            ++$index;

            return [
                'type' => $keyword === 'null' ? 'null' : 'boolean',
                'value' => match ($keyword) {
                    'true' => true,
                    'false' => false,
                    default => null,
                },
                'start' => $token['start'],
                'end' => $token['end'],
            ];
        }
        if ($token['text'] === '-' || $token['text'] === '+') {
            $sign = $token['text'] === '-' ? -1 : 1;
            $start = $token['start'];
            ++$index;
            $number = $this->parseLiteralValue($tokens, $index);
            if (!in_array($number['type'], ['integer', 'float'], true)) {
                $this->notLiteral();
            }
            $number['value'] *= $sign;
            $number['start'] = $start;

            return $number;
        }

        $this->notLiteral();
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     * @return array<string, mixed>
     */
    private function parseLiteralArray(array $tokens, int &$index): array
    {
        $open = $tokens[$index];
        $longSyntax = $open['id'] === T_ARRAY;
        ++$index;
        if ($longSyntax) {
            $this->expectText($tokens, $index, '(');
            $closing = ')';
        } else {
            $closing = ']';
        }

        $entries = [];
        $firstEntryOffset = null;
        $lastValueEnd = null;
        $trailingComma = false;
        if ($this->tokenText($tokens, $index, $closing)) {
            $close = $tokens[$index];
            ++$index;

            return [
                'type' => 'array',
                'value' => [],
                'entries' => [],
                'start' => $open['start'],
                'end' => $close['end'],
                'close_offset' => $close['start'],
                'first_entry_offset' => null,
                'last_value_end' => null,
                'trailing_comma' => false,
            ];
        }

        while (true) {
            $firstEntryOffset ??= $tokens[$index]['start'] ?? null;
            $first = $this->parseLiteralValue($tokens, $index);
            $keyType = 'implicit';
            $key = null;
            $value = $first;
            if ($this->tokenId($tokens, $index, T_DOUBLE_ARROW)) {
                if (!in_array($first['type'], ['string', 'integer'], true)) {
                    $this->notLiteral();
                }
                if ($first['type'] === 'string' && $first['value'] === null) {
                    $this->notLiteral();
                }
                $keyType = $first['type'];
                $key = $first['value'];
                ++$index;
                $value = $this->parseLiteralValue($tokens, $index);
            }
            $entries[] = [
                'key_type' => $keyType,
                'key' => $key,
                'value_type' => $value['type'],
                'value' => $value['value'] ?? null,
            ];
            $lastValueEnd = $value['end'];
            if ($this->tokenText($tokens, $index, ',')) {
                ++$index;
                $trailingComma = true;
                if ($this->tokenText($tokens, $index, $closing)) {
                    break;
                }
                continue;
            }
            $trailingComma = false;
            if (!$this->tokenText($tokens, $index, $closing)) {
                $this->notLiteral();
            }
            break;
        }

        $close = $tokens[$index];
        ++$index;

        return [
            'type' => 'array',
            'value' => $entries,
            'entries' => $entries,
            'start' => $open['start'],
            'end' => $close['end'],
            'close_offset' => $close['start'],
            'first_entry_offset' => $firstEntryOffset,
            'last_value_end' => $lastValueEnd,
            'trailing_comma' => $trailingComma,
        ];
    }

    private function decodeLiteralString(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            $this->notLiteral();
        }
        $quote = $literal[0];
        if ($literal[strlen($literal) - 1] !== $quote) {
            $this->notLiteral();
        }
        $inner = substr($literal, 1, -1);
        if ($quote === '"') {
            // Escaped double-quoted keys could conceal the key we intend to
            // add. Reject them instead of attempting to emulate PHP string
            // interpolation and escape semantics.
            return strpbrk($inner, '\\$') === false ? $inner : null;
        }
        if ($quote !== "'") {
            $this->notLiteral();
        }

        $decoded = '';
        $length = strlen($inner);
        for ($index = 0; $index < $length; ++$index) {
            $character = $inner[$index];
            if (
                $character === '\\'
                && $index + 1 < $length
                && in_array($inner[$index + 1], ['\\', "'"], true)
            ) {
                $decoded .= $inner[++$index];
                continue;
            }
            $decoded .= $character;
        }

        return $decoded;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     */
    private function expectText(
        array $tokens,
        int &$index,
        string $text
    ): void {
        if (!$this->tokenText($tokens, $index, $text)) {
            $this->notLiteral();
        }
        ++$index;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     */
    private function tokenId(array $tokens, int $index, int $id): bool
    {
        return isset($tokens[$index]) && $tokens[$index]['id'] === $id;
    }

    /**
     * @param list<array{id: int|null, text: string, start: int, end: int}> $tokens
     */
    private function tokenText(
        array $tokens,
        int $index,
        string $text
    ): bool {
        return isset($tokens[$index]) && $tokens[$index]['text'] === $text;
    }

    private function notLiteral(): never
    {
        throw new BlogPublicShellAdoptionException(
            'blog.public_shell_adoption.config_not_literal',
            BlogPublicShellAdoptionResult::CONFIG_PATH
        );
    }

    /**
     * @param array{
     *     close_offset: int,
     *     first_entry_offset: int|null,
     *     last_value_end: int|null,
     *     trailing_comma: bool
     * } $document
     */
    private function insertConfigEntry(
        string $contents,
        array $document
    ): string {
        $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $closeOffset = $document['close_offset'];
        $working = $contents;
        if (
            $document['last_value_end'] !== null
            && !$document['trailing_comma']
        ) {
            $commaOffset = $document['last_value_end'];
            $working = substr($working, 0, $commaOffset)
                . ',' . substr($working, $commaOffset);
            if ($commaOffset <= $closeOffset) {
                ++$closeOffset;
            }
        }

        $closingLineStart = strrpos(
            substr($working, 0, $closeOffset),
            "\n"
        );
        $closingLineStart = $closingLineStart === false
            ? 0
            : $closingLineStart + 1;
        $closingIndent = substr(
            $working,
            $closingLineStart,
            $closeOffset - $closingLineStart
        );
        $closingOnOwnLine = trim($closingIndent, " \t\r") === '';
        $closingIndent = $closingOnOwnLine ? $closingIndent : '';
        $itemIndent = $this->indentAtOffset(
            $working,
            $document['first_entry_offset']
        ) ?? ($closingIndent . '    ');
        $entry = $itemIndent . "'" . self::EXPECTED_KEY . "' => '"
            . self::EXPECTED_VALUE . "',";

        if ($closingOnOwnLine) {
            return substr($working, 0, $closingLineStart)
                . $entry . $newline
                . substr($working, $closingLineStart);
        }

        return substr($working, 0, $closeOffset)
            . $newline . $entry . $newline . $closingIndent
            . substr($working, $closeOffset);
    }

    private function indentAtOffset(
        string $contents,
        ?int $offset
    ): ?string {
        if ($offset === null) {
            return null;
        }
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = substr($contents, $lineStart, $offset - $lineStart);

        return trim($indent, " \t\r") === '' ? $indent : null;
    }

    /** @return resource */
    private function acquireProjectLock(string $root)
    {
        $lockPath = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'liquidstack-blog-public-shell-'
            . hash('sha256', $root) . '.lock';
        if (is_link($lockPath)) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.lock_failed'
            );
        }
        $handle = @fopen($lockPath, 'c+b');
        if (!is_resource($handle) || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.lock_failed'
            );
        }

        return $handle;
    }

    private function replaceConfig(
        string $root,
        string $configPath,
        ?string $expected,
        string $updated
    ): void {
        try {
            $suffix = bin2hex(random_bytes(12));
        } catch (Throwable) {
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_write_failed',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }

        $createdDirectory = false;
        $configDirectory = dirname($configPath);
        if (!is_dir($configDirectory)) {
            if (
                file_exists($configDirectory)
                || is_link($configDirectory)
                || !@mkdir($configDirectory, 0777, false)
            ) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_write_failed',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
            $createdDirectory = true;
        }
        $resolvedDirectory = realpath($configDirectory);
        if (
            !is_string($resolvedDirectory)
            || is_link($configDirectory)
            || !$this->pathIsInside($resolvedDirectory, $root)
        ) {
            if ($createdDirectory) {
                @rmdir($configDirectory);
            }
            throw new BlogPublicShellAdoptionException(
                'blog.public_shell_adoption.config_path_invalid',
                BlogPublicShellAdoptionResult::CONFIG_PATH
            );
        }
        if ($expected === null) {
            if (file_exists($configPath) || is_link($configPath)) {
                if ($createdDirectory) {
                    @rmdir($configDirectory);
                }
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_changed_concurrently',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
        } else {
            $current = @file_get_contents($configPath);
            if (!is_string($current) || !hash_equals($expected, $current)) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_changed_concurrently',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
            if (!is_file($configPath) || is_link($configPath)) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_path_invalid',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
        }
        $stagedPath = dirname($configPath) . DIRECTORY_SEPARATOR
            . '.blog-public-shell-' . $suffix . '.tmp';
        $handle = null;
        try {
            $handle = @fopen($stagedPath, 'x+b');
            if (!is_resource($handle) || is_link($stagedPath)) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_write_failed',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
            $remaining = $updated;
            while ($remaining !== '') {
                $written = @fwrite($handle, $remaining);
                if (!is_int($written) || $written <= 0) {
                    throw new BlogPublicShellAdoptionException(
                        'blog.public_shell_adoption.config_write_failed',
                        BlogPublicShellAdoptionResult::CONFIG_PATH
                    );
                }
                $remaining = substr($remaining, $written);
            }
            if (!@fflush($handle)) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_write_failed',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
            if (function_exists('fsync')) {
                @fsync($handle);
            }
            @fclose($handle);
            $handle = null;

            $staged = @file_get_contents($stagedPath);
            if (!is_string($staged) || !hash_equals($updated, $staged)) {
                throw new BlogPublicShellAdoptionException(
                    'blog.public_shell_adoption.config_write_failed',
                    BlogPublicShellAdoptionResult::CONFIG_PATH
                );
            }
            $this->parseLiteralReturnArray($staged);

            // A same-directory rename is the commit point: readers see either
            // the old complete file or the already validated new one.
            if ($expected === null) {
                if (file_exists($configPath) || is_link($configPath)) {
                    throw new BlogPublicShellAdoptionException(
                        'blog.public_shell_adoption.config_changed_concurrently',
                        BlogPublicShellAdoptionResult::CONFIG_PATH
                    );
                }
                if (!@link($stagedPath, $configPath)) {
                    throw new BlogPublicShellAdoptionException(
                        'blog.public_shell_adoption.config_write_failed',
                        BlogPublicShellAdoptionResult::CONFIG_PATH
                    );
                }
                $this->removeStagedFile($stagedPath);
                if (!file_exists($stagedPath) && !is_link($stagedPath)) {
                    $stagedPath = '';
                }
            } else {
                $current = @file_get_contents($configPath);
                if (!is_string($current) || !hash_equals($expected, $current)) {
                    throw new BlogPublicShellAdoptionException(
                        'blog.public_shell_adoption.config_changed_concurrently',
                        BlogPublicShellAdoptionResult::CONFIG_PATH
                    );
                }
                $permissions = @fileperms($configPath);
                if (is_int($permissions)) {
                    @chmod($stagedPath, $permissions & 0777);
                }
                if (!@rename($stagedPath, $configPath)) {
                    throw new BlogPublicShellAdoptionException(
                        'blog.public_shell_adoption.config_write_failed',
                        BlogPublicShellAdoptionResult::CONFIG_PATH
                    );
                }
                $stagedPath = '';
            }
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if ($stagedPath !== '') {
                $this->removeStagedFile($stagedPath);
            }
            if (
                $createdDirectory
                && !file_exists($configPath)
                && !is_link($configPath)
            ) {
                @rmdir($configDirectory);
            }
        }
    }

    private function removeStagedFile(string $path): void
    {
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }
}
