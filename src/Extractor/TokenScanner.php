<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ReflectionMethod;

/**
 * Lexical helpers over a PHP token list: finding an array literal, splitting it
 * into elements, splitting a call into arguments, and locating a top-level
 * operator inside an expression.
 *
 * "Top-level" always means at bracket depth zero, which is what makes the `=>`
 * of an array element distinguishable from the `=>` of a `match` arm nested
 * inside its value, and a `,` between elements from a `,` between the arguments
 * of a call.
 *
 * Tokens are normalised to `[id, text]` pairs with whitespace and comments
 * dropped; single-character tokens, which `token_get_all()` returns as bare
 * strings, get the id -1 so every token can be read the same way.
 *
 * Used by {@see ArrayShapeReader} to read a payload method without running it.
 * Pure PHP — no framework dependency.
 */
final class TokenScanner
{
    /** Id given to single-character tokens, which have no T_* constant. */
    public const CHAR = -1;

    private function __construct() {}

    /**
     * The significant tokens of a method, taken from its own lines so the rest
     * of the file is never parsed.
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function ofMethod(ReflectionMethod $method): array
    {
        $file = (string) $method->getFileName();
        $lines = @file($file);

        if ($lines === false) {
            return [];
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $source = implode('', array_slice($lines, $start - 1, max(1, $end - $start + 1)));

        $tokens = [];
        foreach (@token_get_all("<?php\n".$source) as $token) {
            if (is_string($token)) {
                $tokens[] = [self::CHAR, $token];

                continue;
            }
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = [$token[0], $token[1]];
        }

        return $tokens;
    }

    /**
     * Split the array literal starting at $cursor into element token slices,
     * leaving $cursor on the closing bracket. Null when there is no literal
     * there — `return $this->attributes;` and friends.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<list<array{0: int, 1: string}>>|null
     */
    public static function arrayElements(array $tokens, int &$cursor): ?array
    {
        $count = count($tokens);
        $i = $cursor;

        if (($tokens[$i][1] ?? '') === '[') {
            $close = ']';
            $i++;
        } elseif (($tokens[$i][0] ?? 0) === T_ARRAY && ($tokens[$i + 1][1] ?? '') === '(') {
            $close = ')';
            $i += 2;
        } else {
            return null;
        }

        $elements = [];
        $buffer = [];
        $depth = 0;

        for (; $i < $count; $i++) {
            $text = $tokens[$i][1];

            if ($depth === 0 && $text === $close) {
                if ($buffer !== []) {
                    $elements[] = $buffer;
                }
                $cursor = $i;

                return $elements;
            }

            if (self::opens($text)) {
                $depth++;
            } elseif (self::closes($text)) {
                $depth--;
            } elseif ($depth === 0 && $text === ',') {
                if ($buffer !== []) {
                    $elements[] = $buffer;
                }
                $buffer = [];

                continue;
            }

            $buffer[] = $tokens[$i];
        }

        // Unbalanced (a one-line method whose closing brace shares a line with
        // other code): better to document nothing than half a literal.
        return null;
    }

    private static function opens(string $text): bool
    {
        return in_array($text, ['[', '(', '{', '#[', '${'], true);
    }

    private static function closes(string $text): bool
    {
        return in_array($text, [']', ')', '}'], true);
    }

    /**
     * Index of the first top-level token with this id (or this text, for
     * single-character tokens passed as $text with {@see CHAR}).
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    public static function indexOfTopLevel(array $tokens, int $id, ?string $text = null): ?int
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if (self::opens($token[1])) {
                $depth++;

                continue;
            }
            if (self::closes($token[1])) {
                $depth--;

                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($text === null ? $token[0] === $id : ($token[0] === $id && $token[1] === $text)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The string key of an element, or null when it is not a literal string —
     * `self::FIELD => …` cannot be read without evaluating code, and a numeric
     * key means the literal is a list rather than an object.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    public static function literalKey(array $tokens): ?string
    {
        if (count($tokens) !== 1 || $tokens[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $raw = substr($tokens[0][1], 1, -1);

        return str_replace(['\\\\', "\\'", '\\"'], ['\\', "'", '"'], $raw);
    }

    /** Drop a wrapping pair of parentheses: `('a')`. */
    public static function unwrap(array $tokens): array
    {
        while (count($tokens) > 2 && $tokens[0][1] === '(' && end($tokens)[1] === ')') {
            $inner = array_slice($tokens, 1, -1);
            if (self::balanced($inner)) {
                $tokens = $inner;

                continue;
            }
            break;
        }

        return $tokens;
    }

    private static function balanced(array $tokens): bool
    {
        $depth = 0;

        foreach ($tokens as $token) {
            if (self::opens($token[1])) {
                $depth++;
            } elseif (self::closes($token[1])) {
                $depth--;
            }
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * The method name of `$this->name(…)`, or null when the expression is not
     * a method call on `$this`.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    public static function methodCallOnThis(array $tokens): ?string
    {
        if (($tokens[0][0] ?? 0) !== T_VARIABLE || $tokens[0][1] !== '$this') {
            return null;
        }

        if (! in_array($tokens[1][0] ?? 0, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return null;
        }

        if (($tokens[2][0] ?? 0) !== T_STRING || ($tokens[3][1] ?? '') !== '(') {
            return null;
        }

        return $tokens[2][1];
    }

    /**
     * The $index-th argument of the first call in the expression.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<array{0: int, 1: string}>
     */
    public static function argument(array $tokens, int $index): array
    {
        return self::arguments($tokens)[$index] ?? [];
    }

    /**
     * The argument list of the first call in the expression, split into token
     * slices.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     * @return list<list<array{0: int, 1: string}>>
     */
    public static function arguments(array $tokens): array
    {
        $count = count($tokens);
        $open = null;

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][1] === '(') {
                $open = $i;

                break;
            }
        }

        if ($open === null) {
            return [];
        }

        $arguments = [];
        $buffer = [];
        $depth = 0;

        for ($i = $open + 1; $i < $count; $i++) {
            $text = $tokens[$i][1];

            if ($depth === 0 && $text === ')') {
                break;
            }
            if (self::opens($text)) {
                $depth++;
            } elseif (self::closes($text)) {
                $depth--;
            } elseif ($depth === 0 && $text === ',') {
                $arguments[] = $buffer;
                $buffer = [];

                continue;
            }
            $buffer[] = $tokens[$i];
        }
        $arguments[] = $buffer;

        return $arguments;
    }

    /** @param list<array{0: int, 1: string}> $tokens */
    public static function isNullLiteral(array $tokens): bool
    {
        return count($tokens) === 1
            && $tokens[0][0] === T_STRING
            && strtolower($tokens[0][1]) === 'null';
    }

    /**
     * The class name starting at $index — one token in PHP 8 unless written
     * with leading separators.
     *
     * @param  list<array{0: int, 1: string}>  $tokens
     */
    public static function name(array $tokens, int $index): ?string
    {
        $name = '';
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            if (! in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                break;
            }
            $name .= $tokens[$i][1];
        }

        return $name === '' ? null : $name;
    }
}
