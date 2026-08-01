<?php

declare(strict_types=1);

namespace App\Core\Routing;

use Closure;
use InvalidArgumentException;
use PhpToken;
use Throwable;

/**
 * Inspects literal route keys without loading or executing project PHP files.
 */
final class StaticRouteCollisionInspector
{
    /** @var array<string, string> */
    private const ROUTE_FILES = [
        'GET' => 'App/config/routes/get.php',
        'POST' => 'App/config/routes/post.php',
    ];

    /** @var Closure(string): string|false */
    private readonly Closure $reader;

    /**
     * The optional reader exists to make read failures deterministic in tests.
     * Production callers should use the default.
     *
     * @param (Closure(string): string|false)|null $reader
     */
    public function __construct(?Closure $reader = null)
    {
        $this->reader = $reader
            ?? static fn (string $path): string|false => file_get_contents($path);
    }

    /**
     * @return array{
     *     prefix: string,
     *     complete: bool,
     *     collisions: list<array{method: string, route: string, source: string, line: int}>,
     *     issues: list<array{code: string, source: string}>
     * }
     */
    public function inspect(string $projectRoot, string $prefix): array
    {
        $prefix = $this->normalizePrefix($prefix);
        $collisions = [];
        $issues = [];

        foreach (self::ROUTE_FILES as $method => $source) {
            $path = $this->projectPath($projectRoot, $source);
            $issue = $this->fileIssue($projectRoot, $path);
            if ($issue !== null) {
                $issues[] = ['code' => $issue, 'source' => $source];
                continue;
            }

            // A project may legitimately have no routes for this method.
            if (!file_exists($path)) {
                continue;
            }

            try {
                $contents = ($this->reader)($path);
            } catch (Throwable) {
                $contents = false;
            }

            if (!is_string($contents)) {
                $issues[] = [
                    'code' => 'route_file.read_failed',
                    'source' => $source,
                ];
                continue;
            }

            try {
                $analysis = $this->routeKeyAnalysis($contents);
            } catch (Throwable) {
                $issues[] = [
                    'code' => 'route_file.invalid_php',
                    'source' => $source,
                ];
                continue;
            }

            if ($analysis['dynamic']) {
                $issues[] = [
                    'code' => 'route_file.dynamic_key',
                    'source' => $source,
                ];
            }

            foreach ($analysis['routes'] as $route) {
                $normalized = $this->normalizeRoute($route['route']);
                if (
                    $normalized === null
                    || !$this->collides($prefix, $normalized)
                ) {
                    continue;
                }

                $key = $method . ' ' . $normalized;
                $collisions[$key] ??= [
                    'method' => $method,
                    'route' => $normalized,
                    'source' => $source,
                    'line' => $route['line'],
                ];
            }
        }

        return [
            'prefix' => $prefix,
            'complete' => $issues === [],
            'collisions' => array_values($collisions),
            'issues' => $issues,
        ];
    }

    private function normalizePrefix(string $prefix): string
    {
        $normalized = $this->normalizeRoute($prefix);
        if ($normalized === null || $normalized === '/') {
            throw new InvalidArgumentException(
                'El prefijo WebAdmin no es una ruta valida.'
            );
        }

        return $normalized;
    }

    private function normalizeRoute(string $route): ?string
    {
        $queryOffset = strcspn($route, '?#');
        $path = substr($route, 0, $queryOffset);

        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_contains($path, "\\")
            || str_contains($path, '//')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || preg_match('#(?:^|/)\.\.?($|/)#', $path) === 1
        ) {
            return null;
        }

        return $path === '/' ? $path : rtrim($path, '/');
    }

    private function collides(string $prefix, string $route): bool
    {
        return $route === $prefix
            || str_starts_with($route, $prefix . '/');
    }

    private function projectPath(string $projectRoot, string $source): string
    {
        return rtrim($projectRoot, "/\\")
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $source);
    }

    private function fileIssue(string $projectRoot, string $path): ?string
    {
        if ($this->containsSymlink($projectRoot, $path)) {
            return 'route_file.symlink';
        }

        if (!is_file($path)) {
            if (!file_exists($path)) {
                return null;
            }

            return 'route_file.not_regular';
        }

        if (!is_readable($path)) {
            return 'route_file.unreadable';
        }

        return null;
    }

    private function containsSymlink(string $projectRoot, string $path): bool
    {
        $root = rtrim($projectRoot, "/\\");
        if ($root === '' || is_link($root)) {
            return is_link($root);
        }

        $relative = substr($path, strlen($root));
        if (!is_string($relative)) {
            return true;
        }

        $cursor = $root;
        foreach (preg_split('#[/\\\\]+#', trim($relative, "/\\")) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     routes: list<array{route: string, line: int}>,
     *     dynamic: bool
     * }
     */
    private function routeKeyAnalysis(string $contents): array
    {
        $tokens = PhpToken::tokenize($contents, TOKEN_PARSE);
        $routes = [];
        $dynamic = false;
        $returnVariables = [];
        $literalArrayAssignments = [];
        $hasReturn = false;

        foreach ($tokens as $index => $token) {
            if ($token->id !== T_RETURN) {
                continue;
            }

            $hasReturn = true;
            $valueIndex = $this->nextSignificantIndex(
                $tokens,
                $index + 1
            );
            if ($valueIndex === null) {
                $dynamic = true;
            } elseif (
                $tokens[$valueIndex]->text === '['
                || $tokens[$valueIndex]->id === T_ARRAY
            ) {
                // A directly returned array is covered by key analysis.
            } elseif ($tokens[$valueIndex]->id === T_VARIABLE) {
                $afterVariable = $this->nextSignificantIndex(
                    $tokens,
                    $valueIndex + 1
                );
                if (
                    $afterVariable === null
                    || $tokens[$afterVariable]->text !== ';'
                ) {
                    $dynamic = true;
                } else {
                    $returnVariables[$tokens[$valueIndex]->text] = true;
                }
            } else {
                $dynamic = true;
            }
        }

        foreach ($tokens as $index => $token) {
            if (
                $token->id === T_VARIABLE
                && isset($returnVariables[$token->text])
            ) {
                $operatorIndex = $this->nextSignificantIndex(
                    $tokens,
                    $index + 1
                );
                if (
                    $operatorIndex !== null
                    && $tokens[$operatorIndex]->text === '='
                ) {
                    $valueIndex = $this->nextSignificantIndex(
                        $tokens,
                        $operatorIndex + 1
                    );
                    if (
                        $valueIndex !== null
                        && (
                            $tokens[$valueIndex]->text === '['
                            || $tokens[$valueIndex]->id === T_ARRAY
                        )
                    ) {
                        $literalArrayAssignments[$token->text] = true;
                    } else {
                        $dynamic = true;
                    }
                }
            }

            if (
                $token->id === T_VARIABLE
                && $this->isArrayOffsetAssignment($tokens, $index)
            ) {
                $dynamic = true;
            }

            if ($token->text !== '=>') {
                continue;
            }

            $keyIndex = $this->previousSignificantIndex(
                $tokens,
                $index - 1
            );
            if ($keyIndex === null) {
                $dynamic = true;
                continue;
            }
            $key = $tokens[$keyIndex];

            if ($key->id === T_CONSTANT_ENCAPSED_STRING) {
                $beforeKey = $this->previousSignificantIndex(
                    $tokens,
                    $keyIndex - 1
                );
                if (
                    $beforeKey !== null
                    && !in_array(
                        $tokens[$beforeKey]->text,
                        ['[', '(', ','],
                        true
                    )
                ) {
                    $dynamic = true;
                    continue;
                }

                $value = $this->decodeStringLiteral($key->text);
                if ($value === null) {
                    $dynamic = true;
                    continue;
                }
                if (str_starts_with($value, '/')) {
                    $routes[] = [
                        'route' => $value,
                        'line' => $key->line,
                    ];
                }
                continue;
            }

            if (in_array($key->id, [T_LNUMBER, T_DNUMBER], true)) {
                continue;
            }
            if (
                $key->id === T_STRING
                && in_array(
                    strtolower($key->text),
                    ['true', 'false', 'null'],
                    true
                )
            ) {
                continue;
            }

            $dynamic = true;
        }

        if (!$hasReturn) {
            $dynamic = true;
        }
        foreach (array_keys($returnVariables) as $returnVariable) {
            if (!isset($literalArrayAssignments[$returnVariable])) {
                $dynamic = true;
            }
        }

        return ['routes' => $routes, 'dynamic' => $dynamic];
    }

    /** @param list<PhpToken> $tokens */
    private function isArrayOffsetAssignment(
        array $tokens,
        int $variableIndex
    ): bool {
        $cursor = $this->nextSignificantIndex(
            $tokens,
            $variableIndex + 1
        );
        if ($cursor === null || $tokens[$cursor]->text !== '[') {
            return false;
        }

        while ($cursor !== null && $tokens[$cursor]->text === '[') {
            $depth = 0;
            $count = count($tokens);
            for (; $cursor < $count; $cursor++) {
                if ($tokens[$cursor]->text === '[') {
                    ++$depth;
                } elseif ($tokens[$cursor]->text === ']') {
                    --$depth;
                    if ($depth === 0) {
                        ++$cursor;
                        break;
                    }
                }
            }
            if ($depth !== 0) {
                return true;
            }
            $cursor = $this->nextSignificantIndex($tokens, $cursor);
        }

        return $cursor !== null && in_array(
            $tokens[$cursor]->text,
            [
                '=', '+=', '-=', '*=', '**=', '/=', '.=', '%=', '&=',
                '|=', '^=', '<<=', '>>=', '??=',
            ],
            true
        );
    }

    /** @param list<PhpToken> $tokens */
    private function nextSignificantIndex(
        array $tokens,
        int $offset
    ): ?int {
        $count = count($tokens);
        for ($index = max(0, $offset); $index < $count; $index++) {
            if ($this->isSignificant($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $tokens */
    private function previousSignificantIndex(
        array $tokens,
        int $offset
    ): ?int {
        for ($index = min($offset, count($tokens) - 1); $index >= 0; $index--) {
            if ($this->isSignificant($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    private function isSignificant(PhpToken $token): bool
    {
        return !in_array(
            $token->id,
            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
            true
        );
    }

    private function decodeStringLiteral(string $literal): ?string
    {
        $length = strlen($literal);
        if ($length < 2) {
            return null;
        }

        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[$length - 1] !== $quote) {
            return null;
        }

        $value = substr($literal, 1, -1);
        if ($quote === '"') {
            return stripcslashes($value);
        }

        return preg_replace_callback(
            "/\\\\\\\\|\\\\'/",
            static fn (array $match): string => $match[0] === "\\'" ? "'" : "\\",
            $value
        );
    }
}
