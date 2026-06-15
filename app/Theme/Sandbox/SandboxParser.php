<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

/**
 * Lexes + parses a sandbox TEMPLATE (ADR-0038) into a safe AST of plain arrays. The template syntax is:
 *
 *   literal text                       — copied through (escaped? no — literal template text is author HTML
 *                                        structure; DYNAMIC values from {{ }} are always escaped at render)
 *   {{ expression }}                   — output (auto-escaped at render)
 *   {% if expr %} … {% elseif expr %} … {% else %} … {% endif %}
 *   {% for item in path %} … {% endfor %}
 *
 * Expressions are delegated to {@see SandboxExpression}. The parser enforces structural limits (size, nesting
 * depth, node count) so a hostile template can't blow the stack or memory at PARSE time; the renderer enforces
 * the runtime limits. AST node shapes:
 *   ['t'=>'text','v'=>string]
 *   ['t'=>'out','e'=>expr]
 *   ['t'=>'if','branches'=>[['c'=>expr,'body'=>nodes],…],'else'=>nodes|null]
 *   ['t'=>'for','var'=>string,'e'=>expr,'body'=>nodes]
 */
final class SandboxParser
{
    public const MAX_SOURCE = 50000;

    public const MAX_DEPTH = 24;

    public const MAX_NODES = 4000;

    /** @var list<array{t:string,v:string}> */
    private array $tokens = [];

    private int $pos = 0;

    private int $nodeCount = 0;

    /** Parse template source into a node list. @throws SandboxException on any malformed structure. */
    public static function parse(string $source): array
    {
        if (strlen($source) > self::MAX_SOURCE) {
            throw new SandboxException('Template is too large (limit '.self::MAX_SOURCE.' characters).');
        }

        $parser = new self;
        $parser->tokens = $parser->lex($source);
        $nodes = $parser->parseNodes(0, []);

        if ($parser->pos !== count($parser->tokens)) {
            $tok = $parser->tokens[$parser->pos] ?? ['v' => ''];
            throw new SandboxException('Unexpected "'.trim($tok['v']).'" — a closing tag has no opener.');
        }

        return $nodes;
    }

    /** @return list<array{t:string,v:string}> */
    private function lex(string $src): array
    {
        $tokens = [];
        $len = strlen($src);
        $i = 0;
        $text = '';

        while ($i < $len) {
            $two = substr($src, $i, 2);
            if ($two === '{{' || $two === '{%') {
                if ($text !== '') {
                    $tokens[] = ['t' => 'text', 'v' => $text];
                    $text = '';
                }
                $close = $two === '{{' ? '}}' : '%}';
                $end = strpos($src, $close, $i + 2);
                if ($end === false) {
                    throw new SandboxException('Unclosed '.($two === '{{' ? '{{ … }}' : '{% … %}').' tag.');
                }
                $inner = trim(substr($src, $i + 2, $end - $i - 2));
                $tokens[] = ['t' => $two === '{{' ? 'out' : 'tag', 'v' => $inner];
                $i = $end + 2;

                continue;
            }

            $text .= $src[$i];
            $i++;
        }
        if ($text !== '') {
            $tokens[] = ['t' => 'text', 'v' => $text];
        }

        return $tokens;
    }

    /**
     * Parse a node list until one of $stoppers (the closing/branching keywords) is reached, leaving that
     * stopper token unconsumed for the caller.
     *
     * @param  list<string>  $stoppers
     */
    private function parseNodes(int $depth, array $stoppers): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SandboxException('Template nesting is too deep (limit '.self::MAX_DEPTH.').');
        }

        $nodes = [];
        while ($this->pos < count($this->tokens)) {
            $tok = $this->tokens[$this->pos];

            if ($tok['t'] === 'text') {
                $this->addNode($nodes, ['t' => 'text', 'v' => $tok['v']]);
                $this->pos++;

                continue;
            }

            if ($tok['t'] === 'out') {
                $this->addNode($nodes, ['t' => 'out', 'e' => SandboxExpression::parse($tok['v'])]);
                $this->pos++;

                continue;
            }

            // A tag: dispatch on the first keyword.
            $keyword = strtok($tok['v'], " \t");
            if ($keyword !== false && in_array($keyword, $stoppers, true)) {
                return $nodes; // leave the stopper for the caller
            }

            if ($keyword === 'if') {
                $this->addNode($nodes, $this->parseIf($depth));

                continue;
            }
            if ($keyword === 'for') {
                $this->addNode($nodes, $this->parseFor($depth));

                continue;
            }

            throw new SandboxException('Unknown tag "{% '.trim($tok['v']).' %}".');
        }

        if ($stoppers !== []) {
            throw new SandboxException('Missing a closing tag (expected one of: '.implode(', ', $stoppers).').');
        }

        return $nodes;
    }

    private function parseIf(int $depth): array
    {
        $branches = [];
        $cond = $this->tagExpression($this->tokens[$this->pos]['v'], 'if');
        $this->pos++; // consume the {% if %}
        $body = $this->parseNodes($depth + 1, ['elseif', 'else', 'endif']);
        $branches[] = ['c' => $cond, 'body' => $body];

        while (true) {
            $tok = $this->tokens[$this->pos] ?? null;
            if ($tok === null) {
                throw new SandboxException('{% if %} is missing its {% endif %}.');
            }
            $keyword = strtok($tok['v'], " \t");

            if ($keyword === 'elseif') {
                $cond = $this->tagExpression($tok['v'], 'elseif');
                $this->pos++;
                $branches[] = ['c' => $cond, 'body' => $this->parseNodes($depth + 1, ['elseif', 'else', 'endif'])];

                continue;
            }
            if ($keyword === 'else') {
                $this->pos++;
                $else = $this->parseNodes($depth + 1, ['endif']);
                $this->pos++; // consume endif

                return ['t' => 'if', 'branches' => $branches, 'else' => $else];
            }
            // endif
            $this->pos++;

            return ['t' => 'if', 'branches' => $branches, 'else' => null];
        }
    }

    private function parseFor(int $depth): array
    {
        // {% for <var> in <path-expression> %}
        $body = trim((string) preg_replace('/^for\b/', '', $this->tokens[$this->pos]['v']));
        if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+in\s+(.+)$/s', $body, $m)) {
            throw new SandboxException('Malformed {% for %} — expected "for <name> in <collection>".');
        }
        $var = $m[1];
        $expr = SandboxExpression::parse($m[2]);
        $this->pos++; // consume {% for %}
        $loopBody = $this->parseNodes($depth + 1, ['endfor']);
        $this->pos++; // consume {% endfor %}

        return ['t' => 'for', 'var' => $var, 'e' => $expr, 'body' => $loopBody];
    }

    /** Parse the expression that follows a tag keyword (e.g. the condition after `if`). */
    private function tagExpression(string $raw, string $keyword): array
    {
        $expr = trim((string) preg_replace('/^'.preg_quote($keyword, '/').'\b/', '', $raw));
        if ($expr === '') {
            throw new SandboxException('{% '.$keyword.' %} needs a condition.');
        }

        return SandboxExpression::parse($expr);
    }

    private function addNode(array &$nodes, array $node): void
    {
        if (++$this->nodeCount > self::MAX_NODES) {
            throw new SandboxException('Template is too complex (node limit '.self::MAX_NODES.').');
        }
        $nodes[] = $node;
    }
}
