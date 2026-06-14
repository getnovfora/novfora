<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

/**
 * Parses a sandbox EXPRESSION (the bit inside `{{ … }}` or a tag condition) into a tiny, safe AST (ADR-0038).
 *
 * The grammar is intentionally minimal — literals, dotted variable PATHS, whitelisted helper CALLS, the
 * boolean operators and/or/not, and the six comparisons. There is **no** arithmetic, no indexing, no `$`,
 * no `::`, no `->`, no assignment, no `new`. The tokenizer accepts ONLY this character set; ANY other byte
 * (`$ ; : [ ] { } \ -> + - * / % & | ^ ~ @ # backtick` …) is a hard parse error. A helper call is just a
 * NAME plus arguments — the name is resolved against a fixed registry at eval time (SandboxRenderer), so a
 * call can never reach a PHP function. This file produces structure only; it executes nothing.
 *
 * AST node shapes (plain arrays — no objects to traverse):
 *   ['t'=>'lit','v'=>scalar|null]                    a literal
 *   ['t'=>'path','p'=>['a','b','c']]                 a dotted variable path (array access only)
 *   ['t'=>'call','n'=>'name','a'=>[expr,…]]          a whitelisted helper call
 *   ['t'=>'not','e'=>expr]
 *   ['t'=>'and'|'or','l'=>expr,'r'=>expr]
 *   ['t'=>'cmp','op'=>'=='|'!='|'<'|'>'|'<='|'>=','l'=>expr,'r'=>expr]
 */
final class SandboxExpression
{
    /** Nesting cap on parentheses / call-args / `not` chains — guards against a parse-time stack overflow. */
    public const MAX_DEPTH = 100;

    /** @var list<array{k:string,v:string}> */
    private array $tokens;

    private int $pos = 0;

    private function __construct(string $source)
    {
        $this->tokens = self::tokenize($source);
    }

    /** Parse an expression string into a safe AST. @throws SandboxException on any invalid syntax/character. */
    public static function parse(string $source): array
    {
        $parser = new self($source);
        $ast = $parser->parseOr(0);
        if ($parser->pos !== count($parser->tokens)) {
            throw new SandboxException('Unexpected token in expression near "'.($parser->tokens[$parser->pos]['v'] ?? '').'".');
        }

        return $ast;
    }

    // ---- tokenizer (the character allowlist gate) --------------------------------------------------------

    /** @return list<array{k:string,v:string}> */
    private static function tokenize(string $src): array
    {
        $tokens = [];
        $len = strlen($src);
        $i = 0;
        while ($i < $len) {
            $c = $src[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            // String literal: '...' or "..." (no escapes — keep it dead simple).
            if ($c === "'" || $c === '"') {
                $end = strpos($src, $c, $i + 1);
                if ($end === false) {
                    throw new SandboxException('Unterminated string literal.');
                }
                $tokens[] = ['k' => 'str', 'v' => substr($src, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }

            // Number: digits with one optional decimal part.
            if (ctype_digit($c)) {
                $j = $i;
                while ($j < $len && ctype_digit($src[$j])) {
                    $j++;
                }
                if ($j < $len && $src[$j] === '.' && $j + 1 < $len && ctype_digit($src[$j + 1])) {
                    $j++;
                    while ($j < $len && ctype_digit($src[$j])) {
                        $j++;
                    }
                }
                $tokens[] = ['k' => 'num', 'v' => substr($src, $i, $j - $i)];
                $i = $j;

                continue;
            }

            // Identifier / keyword.
            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($src[$j]) || $src[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['k' => 'id', 'v' => substr($src, $i, $j - $i)];
                $i = $j;

                continue;
            }

            // Comparison operators.
            $two = substr($src, $i, 2);
            if (in_array($two, ['==', '!=', '<=', '>='], true)) {
                $tokens[] = ['k' => 'op', 'v' => $two];
                $i += 2;

                continue;
            }
            if ($c === '<' || $c === '>') {
                $tokens[] = ['k' => 'op', 'v' => $c];
                $i++;

                continue;
            }

            // Punctuation.
            $punct = ['(' => 'lpar', ')' => 'rpar', ',' => 'comma', '.' => 'dot'];
            if (isset($punct[$c])) {
                $tokens[] = ['k' => $punct[$c], 'v' => $c];
                $i++;

                continue;
            }

            // ANYTHING else is rejected — this is the hard gate that keeps $ ; : [ ] { } \ -> etc. out.
            throw new SandboxException('Illegal character "'.$c.'" in expression.');
        }

        return $tokens;
    }

    // ---- recursive-descent parser ------------------------------------------------------------------------

    /** @return array{k:string,v:string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function isKeyword(string $word): bool
    {
        $t = $this->peek();

        return $t !== null && $t['k'] === 'id' && $t['v'] === $word;
    }

    private function parseOr(int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SandboxException('Expression is nested too deeply (limit '.self::MAX_DEPTH.').');
        }
        $left = $this->parseAnd($depth);
        while ($this->isKeyword('or')) {
            $this->pos++;
            $left = ['t' => 'or', 'l' => $left, 'r' => $this->parseAnd($depth)];
        }

        return $left;
    }

    private function parseAnd(int $depth): array
    {
        $left = $this->parseNot($depth);
        while ($this->isKeyword('and')) {
            $this->pos++;
            $left = ['t' => 'and', 'l' => $left, 'r' => $this->parseNot($depth)];
        }

        return $left;
    }

    private function parseNot(int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SandboxException('Expression is nested too deeply (limit '.self::MAX_DEPTH.').');
        }
        if ($this->isKeyword('not')) {
            $this->pos++;

            return ['t' => 'not', 'e' => $this->parseNot($depth + 1)];
        }

        return $this->parseComparison($depth);
    }

    private function parseComparison(int $depth): array
    {
        $left = $this->parsePrimary($depth);
        $t = $this->peek();
        if ($t !== null && $t['k'] === 'op') {
            $this->pos++;

            return ['t' => 'cmp', 'op' => $t['v'], 'l' => $left, 'r' => $this->parsePrimary($depth)];
        }

        return $left;
    }

    private function parsePrimary(int $depth): array
    {
        $t = $this->peek();
        if ($t === null) {
            throw new SandboxException('Unexpected end of expression.');
        }

        if ($t['k'] === 'str') {
            $this->pos++;

            return ['t' => 'lit', 'v' => $t['v']];
        }

        if ($t['k'] === 'num') {
            $this->pos++;
            $v = str_contains($t['v'], '.') ? (float) $t['v'] : (int) $t['v'];

            return ['t' => 'lit', 'v' => $v];
        }

        if ($t['k'] === 'lpar') {
            $this->pos++;
            $inner = $this->parseOr($depth + 1);
            $this->expect('rpar', ')');

            return $inner;
        }

        if ($t['k'] === 'id') {
            $name = $t['v'];
            $this->pos++;

            if (in_array($name, ['true', 'false', 'null'], true)) {
                return ['t' => 'lit', 'v' => $name === 'true' ? true : ($name === 'false' ? false : null)];
            }

            // A call: name '(' args ')'
            if (($n = $this->peek()) !== null && $n['k'] === 'lpar') {
                $this->pos++;
                $args = [];
                if (($p = $this->peek()) !== null && $p['k'] !== 'rpar') {
                    $args[] = $this->parseOr($depth + 1);
                    while (($p = $this->peek()) !== null && $p['k'] === 'comma') {
                        $this->pos++;
                        $args[] = $this->parseOr($depth + 1);
                    }
                }
                $this->expect('rpar', ')');

                return ['t' => 'call', 'n' => $name, 'a' => $args];
            }

            // Otherwise a dotted path: name('.' name)*
            $path = [$name];
            while (($d = $this->peek()) !== null && $d['k'] === 'dot') {
                $this->pos++;
                $seg = $this->peek();
                if ($seg === null || $seg['k'] !== 'id') {
                    throw new SandboxException('Expected a name after "." in a variable path.');
                }
                $path[] = $seg['v'];
                $this->pos++;
            }

            return ['t' => 'path', 'p' => $path];
        }

        throw new SandboxException('Unexpected token "'.$t['v'].'" in expression.');
    }

    private function expect(string $kind, string $label): void
    {
        $t = $this->peek();
        if ($t === null || $t['k'] !== $kind) {
            throw new SandboxException('Expected "'.$label.'" in expression.');
        }
        $this->pos++;
    }
}
