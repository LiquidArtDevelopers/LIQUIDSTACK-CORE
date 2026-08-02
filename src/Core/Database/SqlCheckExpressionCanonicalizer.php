<?php

declare(strict_types=1);

namespace App\Core\Database;

/** Canonicalizes equivalent CHECK metadata emitted by SQLite/MySQL/MariaDB. */
final class SqlCheckExpressionCanonicalizer
{
    /** Compacts non-boolean SQL metadata without rewriting its structure. */
    public static function compact(string $expression): string
    {
        $tokens = self::tokens($expression);
        while (
            count($tokens) >= 2
            && $tokens[0] === '('
            && self::matchingClosingParenthesis($tokens, 0)
                === count($tokens) - 1
        ) {
            $tokens = array_slice($tokens, 1, -1);
        }

        return implode('', $tokens);
    }

    public static function canonicalize(string $expression): string
    {
        $tokens = self::normalizePredicates(self::tokens($expression));
        if ($tokens === []) {
            return '';
        }

        return self::serialize(self::booleanNode($tokens));
    }

    /** @return list<string> */
    private static function tokens(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        for ($index = 0; $index < $length;) {
            $character = $expression[$index];
            if (ctype_space($character)) {
                $index++;
                continue;
            }
            if ($character === "'") {
                $start = $index++;
                while ($index < $length) {
                    if ($expression[$index] !== "'") {
                        $index++;
                        continue;
                    }
                    if (($expression[$index + 1] ?? '') === "'") {
                        $index += 2;
                        continue;
                    }
                    $index++;
                    break;
                }
                $tokens[] = substr($expression, $start, $index - $start);
                continue;
            }
            if (in_array($character, ['`', '"', '['], true)) {
                $close = $character === '[' ? ']' : $character;
                $index++;
                $identifier = '';
                while ($index < $length) {
                    if ($expression[$index] !== $close) {
                        $identifier .= strtolower($expression[$index++]);
                        continue;
                    }
                    if (($expression[$index + 1] ?? '') === $close) {
                        $identifier .= strtolower($close);
                        $index += 2;
                        continue;
                    }
                    $index++;
                    break;
                }
                $tokens[] = $identifier;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $character) === 1) {
                $start = $index++;
                while (
                    $index < $length
                    && preg_match('/[A-Za-z0-9_]/', $expression[$index])
                        === 1
                ) {
                    $index++;
                }
                $word = strtolower(substr(
                    $expression,
                    $start,
                    $index - $start
                ));
                $lookahead = $index;
                while (
                    $lookahead < $length
                    && ctype_space($expression[$lookahead])
                ) {
                    $lookahead++;
                }
                if (
                    $word === '_utf8mb4'
                    && ($expression[$lookahead] ?? '') === "'"
                ) {
                    $index = $lookahead;
                    continue;
                }
                $tokens[] = match ($word) {
                    'lcase' => 'lower',
                    'rlike' => 'regexp',
                    default => $word,
                };
                continue;
            }
            $pair = substr($expression, $index, 2);
            if (in_array($pair, ['!=', '<=', '>=', '<>'], true)) {
                $tokens[] = $pair === '!=' ? '<>' : $pair;
                $index += 2;
                continue;
            }
            $tokens[] = strtolower($character);
            $index++;
        }

        return $tokens;
    }

    /** @param list<string> $tokens @return list<string> */
    private static function normalizePredicates(array $tokens): array
    {
        for ($position = 0; $position < count($tokens); $position++) {
            if (
                ($tokens[$position] ?? null) === 'regexp_like'
                && ($tokens[$position + 1] ?? null) === '('
            ) {
                $closing = self::matchingClosingParenthesis(
                    $tokens,
                    $position + 1
                );
                if ($closing !== null) {
                    $arguments = self::splitTopLevel(
                        array_slice(
                            $tokens,
                            $position + 2,
                            $closing - $position - 2
                        ),
                        ','
                    );
                    if (
                        count($arguments) === 2
                        && count($arguments[0]) === 1
                        && count($arguments[1]) === 1
                        && preg_match(
                            '/\A[a-z_][a-z0-9_]*\z/',
                            $arguments[0][0]
                        ) === 1
                        && str_starts_with($arguments[1][0], "'")
                    ) {
                        array_splice(
                            $tokens,
                            $position,
                            $closing - $position + 1,
                            [
                                $arguments[0][0],
                                'regexp',
                                $arguments[1][0],
                            ]
                        );
                    }
                }
            }
        }

        for ($position = 0; $position < count($tokens) - 3; $position++) {
            if (
                preg_match('/\A[a-z_][a-z0-9_]*\z/', $tokens[$position]) !== 1
                || $tokens[$position + 1] !== 'is'
                || $tokens[$position + 2] !== 'not'
                || $tokens[$position + 3] !== 'null'
            ) {
                continue;
            }
            array_splice(
                $tokens,
                $position,
                4,
                ['isnotnull', '(', $tokens[$position], ')']
            );
            $position += 3;
        }

        for ($position = 0; $position < count($tokens) - 2; $position++) {
            if (
                preg_match('/\A[a-z_][a-z0-9_]*\z/', $tokens[$position]) !== 1
                || $tokens[$position + 1] !== 'is'
                || $tokens[$position + 2] !== 'null'
            ) {
                continue;
            }
            array_splice(
                $tokens,
                $position,
                3,
                ['isnull', '(', $tokens[$position], ')']
            );
            $position += 3;
        }

        for ($position = 0; $position < count($tokens) - 6; $position++) {
            if (
                array_slice($tokens, $position, 3) !== ['not', '(', 'isnull']
                || ($tokens[$position + 3] ?? null) !== '('
                || preg_match(
                    '/\A[a-z_][a-z0-9_]*\z/',
                    $tokens[$position + 4] ?? ''
                ) !== 1
                || ($tokens[$position + 5] ?? null) !== ')'
                || ($tokens[$position + 6] ?? null) !== ')'
            ) {
                continue;
            }
            array_splice(
                $tokens,
                $position,
                7,
                ['isnotnull', '(', $tokens[$position + 4], ')']
            );
            $position += 3;
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     * @return string|array{operator: string, children: list<mixed>}
     */
    private static function booleanNode(array $tokens): string|array
    {
        while (
            count($tokens) >= 2
            && $tokens[0] === '('
            && self::matchingClosingParenthesis($tokens, 0)
                === count($tokens) - 1
        ) {
            $tokens = array_slice($tokens, 1, -1);
        }

        foreach (['or', 'and'] as $operator) {
            $parts = self::splitTopLevelBoolean($tokens, $operator);
            if (count($parts) < 2) {
                continue;
            }
            $children = [];
            foreach ($parts as $part) {
                $child = self::booleanNode($part);
                if (is_array($child) && $child['operator'] === $operator) {
                    array_push($children, ...$child['children']);
                    continue;
                }
                $children[] = $child;
            }

            return ['operator' => $operator, 'children' => $children];
        }

        return implode('', $tokens);
    }

    /** @param string|array{operator: string, children: list<mixed>} $node */
    private static function serialize(string|array $node): string
    {
        if (is_string($node)) {
            return $node;
        }

        return $node['operator'] . '(' . implode(',', array_map(
            [self::class, 'serialize'],
            $node['children']
        )) . ')';
    }

    /**
     * @param list<string> $tokens
     * @return list<list<string>>
     */
    private static function splitTopLevelBoolean(
        array $tokens,
        string $operator
    ): array {
        $parts = [];
        $start = 0;
        $depth = 0;
        $between = false;
        foreach ($tokens as $position => $token) {
            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($operator === 'and' && $token === 'between') {
                $between = true;
                continue;
            }
            if ($token !== $operator) {
                continue;
            }
            if ($operator === 'and' && $between) {
                $between = false;
                continue;
            }
            $parts[] = array_slice($tokens, $start, $position - $start);
            $start = $position + 1;
        }
        if ($parts === []) {
            return [$tokens];
        }
        $parts[] = array_slice($tokens, $start);

        return $parts;
    }

    /**
     * @param list<string> $tokens
     * @return list<list<string>>
     */
    private static function splitTopLevel(array $tokens, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        foreach ($tokens as $position => $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            } elseif ($depth === 0 && $token === $delimiter) {
                $parts[] = array_slice($tokens, $start, $position - $start);
                $start = $position + 1;
            }
        }
        $parts[] = array_slice($tokens, $start);

        return $parts;
    }

    /** @param list<string> $tokens */
    private static function matchingClosingParenthesis(
        array $tokens,
        int $openingPosition
    ): ?int {
        $depth = 0;
        for (
            $position = $openingPosition;
            $position < count($tokens);
            $position++
        ) {
            if ($tokens[$position] === '(') {
                $depth++;
            } elseif ($tokens[$position] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
            }
        }

        return null;
    }
}
