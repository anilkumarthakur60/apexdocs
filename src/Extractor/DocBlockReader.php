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
 */
final class DocBlockReader
{
    private static ?Lexer $lexer = null;

    private static ?PhpDocParser $parser = null;

    private function __construct() {}

    private static function lexer(): Lexer
    {
        return self::$lexer ??= new Lexer;
    }

    private static function parser(): PhpDocParser
    {
        if (self::$parser === null) {
            $constExpr = new ConstExprParser;
            $typeParser = new TypeParser($constExpr);
            self::$parser = new PhpDocParser($typeParser, $constExpr);
        }

        return self::$parser;
    }

    public static function parse(string|false $docComment): ?PhpDocNode
    {
        if (! $docComment) {
            return null;
        }
        try {
            $tokens = new TokenIterator(self::lexer()->tokenize($docComment));

            return self::parser()->parse($tokens);
        } catch (\Throwable) {
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

    public static function hasTag(string|false $docComment, string $tag): bool
    {
        if (! $docComment) {
            return false;
        }

        return str_contains($docComment, '@'.ltrim($tag, '@'));
    }
}
