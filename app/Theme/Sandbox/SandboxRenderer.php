<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

/**
 * Renders a parsed sandbox template AST against a CONTEXT (ADR-0038). This is the trust boundary, so the
 * rules are absolute:
 *
 *  • The context is a tree of ONLY scalars + arrays (the caller must never put an object/model/closure in
 *    it). Variable resolution therefore does ARRAY-KEY access and nothing else — there is no syntax and no
 *    code path that can call a method, read a property, or reach a service. A missing key resolves to null.
 *  • A "call" resolves its NAME against a fixed helper registry (self::helpers()). A name not in the registry
 *    is a render error — there is no way to invoke an arbitrary PHP function.
 *  • Every {{ … }} value is HTML-escaped (e()) before it is emitted. There is no raw-output construct.
 *  • Runtime limits cap total loop iterations and total output size, so a hostile template can't hang or OOM.
 *
 * (Literal template text between tags is the AUTHOR's HTML structure and is emitted as written — admins are
 * trusted authors; a save-time lint, in TemplateService, additionally rejects <script>/handlers as
 * defence-in-depth. The DATA, which can come from users, is what is escaped here.)
 */
final class SandboxRenderer
{
    public const MAX_OUTPUT = 200000;

    public const MAX_ITERATIONS = 5000;

    private int $iterations = 0;

    private int $outputBytes = 0;

    /** Parse + render a template. @throws SandboxException on a parse error or a runtime limit breach. */
    public function render(string $source, array $context): string
    {
        $this->iterations = 0;
        $this->outputBytes = 0;
        $nodes = SandboxParser::parse($source);

        return $this->renderNodes($nodes, $context);
    }

    /** Return null if the source parses, or a SAFE error message if it does not (for the editor). */
    public static function validate(string $source): ?string
    {
        try {
            SandboxParser::parse($source);

            return null;
        } catch (SandboxException $e) {
            return $e->getMessage();
        }
    }

    /** @return array<string,callable> the ONLY callables a template can invoke (all pure, no I/O). */
    public static function helpers(): array
    {
        return [
            'upper' => fn (array $a): string => mb_strtoupper(self::str($a[0] ?? '')),
            'lower' => fn (array $a): string => mb_strtolower(self::str($a[0] ?? '')),
            'capitalize' => fn (array $a): string => ucfirst(mb_strtolower(self::str($a[0] ?? ''))),
            'trim' => fn (array $a): string => trim(self::str($a[0] ?? '')),
            'length' => fn (array $a): int => is_array($a[0] ?? null) ? count($a[0]) : mb_strlen(self::str($a[0] ?? '')),
            'default' => fn (array $a) => self::truthy($a[0] ?? null) ? $a[0] : ($a[1] ?? ''),
            'truncate' => function (array $a): string {
                $s = self::str($a[0] ?? '');
                $n = max(0, (int) ($a[1] ?? 100));

                return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n)).'…' : $s;
            },
            'concat' => fn (array $a): string => implode('', array_map(self::str(...), $a)),
            'number' => fn (array $a): string => number_format((float) ($a[0] ?? 0), (int) ($a[1] ?? 0)),
            'plural' => fn (array $a): string => ((int) ($a[0] ?? 0)) === 1 ? self::str($a[1] ?? '') : self::str($a[2] ?? ''),
        ];
    }

    private function renderNodes(array $nodes, array $context): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['t']) {
                'text' => $this->emit($node['v']),
                'out' => $this->emit(e(self::str($this->eval($node['e'], $context)))),
                'if' => $this->renderIf($node, $context),
                'for' => $this->renderFor($node, $context),
                default => '',
            };
        }

        return $out;
    }

    private function emit(string $chunk): string
    {
        $this->outputBytes += strlen($chunk);
        if ($this->outputBytes > self::MAX_OUTPUT) {
            throw new SandboxException('Template output is too large (limit '.self::MAX_OUTPUT.' bytes).');
        }

        return $chunk;
    }

    private function renderIf(array $node, array $context): string
    {
        foreach ($node['branches'] as $branch) {
            if (self::truthy($this->eval($branch['c'], $context))) {
                return $this->renderNodes($branch['body'], $context);
            }
        }

        return $node['else'] !== null ? $this->renderNodes($node['else'], $context) : '';
    }

    private function renderFor(array $node, array $context): string
    {
        $collection = $this->eval($node['e'], $context);
        if (! is_array($collection)) {
            return '';
        }

        $items = array_values($collection);
        $total = count($items);
        $out = '';
        foreach ($items as $i => $item) {
            if (++$this->iterations > self::MAX_ITERATIONS) {
                throw new SandboxException('Template ran too many loop iterations (limit '.self::MAX_ITERATIONS.').');
            }
            $scope = $context;
            $scope[$node['var']] = $item;
            $scope['loop'] = [
                'index' => $i + 1, 'index0' => $i, 'first' => $i === 0, 'last' => $i === $total - 1, 'count' => $total,
            ];
            $out .= $this->renderNodes($node['body'], $scope);
        }

        return $out;
    }

    // ---- expression evaluation -------------------------------------------------------------------------

    private function eval(array $expr, array $context): mixed
    {
        return match ($expr['t']) {
            'lit' => $expr['v'],
            'path' => $this->resolvePath($expr['p'], $context),
            'call' => $this->callHelper($expr['n'], array_map(fn ($a) => $this->eval($a, $context), $expr['a'])),
            'not' => ! self::truthy($this->eval($expr['e'], $context)),
            'and' => self::truthy($this->eval($expr['l'], $context)) && self::truthy($this->eval($expr['r'], $context)),
            'or' => self::truthy($this->eval($expr['l'], $context)) || self::truthy($this->eval($expr['r'], $context)),
            'cmp' => $this->compare($expr['op'], $this->eval($expr['l'], $context), $this->eval($expr['r'], $context)),
            default => null,
        };
    }

    /**
     * Resolve a dotted path by ARRAY-KEY access only. Any non-array level (or a missing key) yields null.
     * Objects are never traversed — defence in depth even though the context is supposed to be array-only.
     *
     * @param  list<string>  $path
     */
    private function resolvePath(array $path, array $context): mixed
    {
        $current = $context;
        foreach ($path as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            return null;
        }

        return $current;
    }

    /** @param list<mixed> $args */
    private function callHelper(string $name, array $args): mixed
    {
        $helpers = self::helpers();
        if (! isset($helpers[$name])) {
            throw new SandboxException('Unknown helper "'.$name.'()". Allowed: '.implode(', ', array_keys($helpers)).'.');
        }

        return $helpers[$name]($args);
    }

    private function compare(string $op, mixed $l, mixed $r): bool
    {
        // Only scalars compare meaningfully; arrays/objects never do (returns false / true for !=).
        $lc = is_scalar($l) || $l === null;
        $rc = is_scalar($r) || $r === null;
        if (! $lc || ! $rc) {
            return $op === '!=';
        }

        return match ($op) {
            '==' => $l == $r,
            '!=' => $l != $r,
            '<' => self::lt($l, $r),
            '>' => self::lt($r, $l),
            '<=' => ! self::lt($r, $l),
            '>=' => ! self::lt($l, $r),
            default => false,
        };
    }

    private static function lt(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a < (float) $b;
        }

        return strcmp(self::str($a), self::str($b)) < 0;
    }

    private static function truthy(mixed $v): bool
    {
        if (is_array($v)) {
            return $v !== [];
        }
        if (is_string($v)) {
            return $v !== '' && $v !== '0';
        }

        return (bool) $v;
    }

    /** Stringify a sandbox value for output. Arrays/objects never leak a representation. */
    private static function str(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_string($v) => $v,
            is_int($v) || is_float($v) => (string) $v,
            default => '', // arrays, anything else → empty (never a PHP dump)
        };
    }
}
