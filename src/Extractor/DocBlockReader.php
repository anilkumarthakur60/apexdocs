<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;

/**
 * Reads PHPDoc comments using phpstan/phpdoc-parser.
 * No framework dependencies.
 *
 * Supports both major versions of the parser: v2 threads a ParserConfig
 * through every constructor, v1 takes none. Getting this wrong is silent —
 * construction throws, parsing returns null, and every @return annotation in
 * the project stops contributing to the spec — so the two wirings are chosen
 * explicitly rather than discovered by exception.
 */
final class DocBlockReader
{
    private static ?Lexer $lexer = null;

    private static ?PhpDocParser $parser = null;

    private function __construct() {}

    private static function lexer(): Lexer
    {
        if (self::$lexer === null) {
            self::boot();
        }

        /** @var Lexer */
        return self::$lexer;
    }

    private static function parser(): PhpDocParser
    {
        if (self::$parser === null) {
            self::boot();
        }

        /** @var PhpDocParser */
        return self::$parser;
    }

    private static function boot(): void
    {
        $configClass = 'PHPStan\\PhpDocParser\\ParserConfig';

        if (class_exists($configClass)) {
            // phpdoc-parser ^2
            $config = new $configClass(usedAttributes: []);
            $constExpr = new ConstExprParser($config);
            self::$lexer = new Lexer($config);
            self::$parser = new PhpDocParser($config, new TypeParser($config, $constExpr), $constExpr);

            return;
        }

        // phpdoc-parser ^1
        /** @phpstan-ignore-next-line — v1 constructors take no ParserConfig */
        $constExpr = new ConstExprParser;
        /** @phpstan-ignore-next-line */
        self::$lexer = new Lexer;
        /** @phpstan-ignore-next-line */
        self::$parser = new PhpDocParser(new TypeParser($constExpr), $constExpr);
    }

    public static function parse(string|false $docComment): ?PhpDocNode
    {
        if (! $docComment) {
            return null;
        }

        $lexer = self::lexer();
        $parser = self::parser();

        try {
            return $parser->parse(new TokenIterator($lexer->tokenize($docComment)));
        } catch (\Throwable) {
            // Malformed annotation — the rest of the docblock is not worth losing.
            return null;
        }
    }

    /** First non-tag, non-empty line — used as summary. */
    public static function summary(string|false $docComment): string
    {
        if (! $docComment) {
            return '';
        }
        foreach (explode("\n", $docComment) as $line) {
            $line = trim($line, " *\t\r/");
            if ($line !== '' && ! str_starts_with($line, '@') && ! str_starts_with($line, '{')) {
                return $line;
            }
        }

        return '';
    }

    /** Everything between the first line and the first @tag. */
    public static function description(string|false $docComment): string
    {
        if (! $docComment) {
            return '';
        }
        $lines = explode("\n", $docComment);
        $desc = [];
        $pastSummary = false;
        foreach ($lines as $line) {
            $clean = trim($line, " *\t\r/");
            if (! $pastSummary) {
                if ($clean !== '') {
                    $pastSummary = true;
                }

                continue;
            }
            if (str_starts_with($clean, '@')) {
                break;
            }
            $desc[] = $clean;
        }

        return trim(implode("\n", $desc));
    }

    /** @return string|null  e.g. "UserResource[]", "Collection<User>" */
    public static function returnType(string|false $docComment): ?string
    {
        $node = self::parse($docComment);
        if ($node === null) {
            return null;
        }
        foreach ($node->getReturnTagValues() as $tag) {
            return (string) $tag->type;
        }

        return null;
    }

    /**
     * The type from an `@var` tag, e.g. "UserResource[]" on a property whose
     * reflection type is only `array`.
     */
    public static function varType(string|false $docComment): ?string
    {
        $node = self::parse($docComment);
        if ($node === null) {
            return null;
        }

        foreach ($node->getVarTagValues() as $tag) {
            $type = trim((string) $tag->type);
            if ($type !== '') {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> param name => type string
     */
    public static function paramTypes(string|false $docComment): array
    {
        $node = self::parse($docComment);
        if ($node === null) {
            return [];
        }
        $result = [];
        foreach ($node->getParamTagValues() as $tag) {
            $name = ltrim($tag->parameterName, '$');
            $result[$name] = (string) $tag->type;
        }

        return $result;
    }

    /**
     * Descriptions from `@param` tags, keyed by parameter name (without `$`).
     *
     * @return array<string, string>
     */
    public static function paramDescriptions(string|false $docComment): array
    {
        $node = self::parse($docComment);
        if ($node === null) {
            return [];
        }

        $result = [];
        foreach ($node->getParamTagValues() as $tag) {
            $name = ltrim($tag->parameterName, '$');
            $description = trim(preg_replace('/\s+/', ' ', $tag->description) ?? '');
            if ($name !== '' && $description !== '') {
                $result[$name] = $description;
            }
        }

        return $result;
    }

    public static function hasTag(string|false $docComment, string $tag): bool
    {
        if (! $docComment) {
            return false;
        }

        return str_contains($docComment, '@'.ltrim($tag, '@'));
    }
}
