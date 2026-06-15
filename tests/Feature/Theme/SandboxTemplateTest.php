<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\SiteTemplate;
use App\Theme\Sandbox\SandboxException;
use App\Theme\Sandbox\SandboxParser;
use App\Theme\Sandbox\SandboxRenderer;
use App\Theme\Sandbox\TemplateContract;
use App\Theme\Sandbox\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Theme Studio 1.6 — the SANDBOXED template editor (ADR-0038). The headline safety contract: the restricted
| template language can render data safely but can NEVER execute PHP, call a non-whitelisted function, reach a
| model/service, or emit unescaped dynamic values. The first block is the adversarial battery — every escape
| attempt must FAIL (throw, or render to safe/empty), never succeed.
*/

uses(RefreshDatabase::class);

function render(string $source, array $context = []): string
{
    return (new SandboxRenderer)->render($source, $context);
}

// ───────────────────────────── ADVERSARIAL: escape attempts must all fail ─────────────────────────────

it('rejects PHP-ish operators and sigils at parse time', function (string $src) {
    expect(fn () => render($src))->toThrow(SandboxException::class);
})->with([
    '{{ 7 * 7 }}',          // arithmetic op
    '{{ 1 + 1 }}',
    '{{ $x }}',             // PHP variable sigil
    '{{ user["name"] }}',   // index access
    '{{ a; b }}',           // statement separator
    '{{ a::b }}',           // scope resolution
    '{{ a->b }}',           // object operator (-> → "-" illegal)
    '{{ a | b }}',          // pipe
    '{{ {} }}',             // brace
    '{{ `id` }}',           // backtick
    '{{ a.b() .c }}',       // call result then dot — leftover token
    '{{ user.delete() }}',  // method-call shape leaves a dangling "("
]);

it('refuses to call any function outside the helper whitelist', function (string $name) {
    expect(fn () => render("{{ {$name}('x') }}", []))->toThrow(SandboxException::class);
})->with(['system', 'exec', 'eval', 'shell_exec', 'passthru', 'file_get_contents', 'app', 'config', 'dd', 'abort']);

it('never reaches an object property or method even if one leaks into the context', function () {
    $obj = new class
    {
        public string $secret = 'TOP-SECRET';

        public function password(): string
        {
            return 'hunter2';
        }
    };

    // Path access on an object yields null (no property read); outputting the object yields '' (no dump).
    expect(render('[{{ obj.secret }}][{{ obj.password }}][{{ obj }}]', ['obj' => $obj]))
        ->toBe('[][][]');
});

it('does not re-evaluate template syntax that arrives inside a data value (no double rendering)', function () {
    $out = render('{{ bio }}', ['bio' => "{{ system('id') }}"]);

    // The payload is emitted as ESCAPED literal text, never parsed/executed.
    expect($out)->toContain('system')               // shown verbatim…
        ->and($out)->not->toContain('uid=')          // …not executed
        ->and($out)->toContain('{{');                // braces preserved as text, not re-run
});

it('HTML-escapes every dynamic value (no stored XSS through {{ }})', function () {
    $out = render('<p>{{ bio }}</p>', ['bio' => '<script>alert(1)</script><img src=x onerror=y>']);

    expect($out)->toContain('&lt;script&gt;')
        ->and($out)->not->toContain('<script>')
        ->and($out)->not->toContain('onerror=y>'); // the raw handler is escaped
});

it('caps runaway loops, output size and nesting (no hang / OOM)', function () {
    // Loop iteration cap.
    $big = ['big' => range(1, SandboxRenderer::MAX_ITERATIONS + 50)];
    expect(fn () => render('{% for i in big %}x{% endfor %}', $big))->toThrow(SandboxException::class);

    // Output-size cap (each iteration emits a chunk; total exceeds MAX_OUTPUT).
    $rows = ['rows' => range(1, 4000)];
    expect(fn () => render('{% for r in rows %}{{ pad }}{% endfor %}', $rows + ['pad' => str_repeat('y', 100)]))
        ->toThrow(SandboxException::class);

    // Source-size cap.
    expect(fn () => render(str_repeat('a', SandboxParser::MAX_SOURCE + 1)))->toThrow(SandboxException::class);
});

it('caps deeply nested expressions (no parse-time stack overflow)', function () {
    expect(fn () => render('{{ '.str_repeat('(', 200).'a'.str_repeat(')', 200).' }}'))->toThrow(SandboxException::class);
    expect(fn () => render('{{ '.str_repeat('not ', 200).'a }}'))->toThrow(SandboxException::class);
});

it('rejects malformed control structure (unbalanced tags)', function (string $src) {
    expect(fn () => render($src))->toThrow(SandboxException::class);
})->with([
    '{% if user.is_guest %}hi',          // missing endif
    '{% for x in items %}y',             // missing endfor
    '{% endif %}',                       // stray close
    '{% if %}x{% endif %}',              // empty condition
    '{% for x items %}{% endfor %}',     // missing "in"
    '{% wat %}{% endwat %}',             // unknown tag
]);

// ───────────────────────────── FUNCTIONAL: the language works ─────────────────────────────

it('outputs variables by dotted path', function () {
    expect(render('{{ site.name }} — {{ stats.posts }}', ['site' => ['name' => 'NovFora'], 'stats' => ['posts' => 12]]))
        ->toBe('NovFora — 12');
});

it('renders if / elseif / else', function () {
    $tpl = '{% if n > 10 %}big{% elseif n > 0 %}small{% else %}none{% endif %}';
    expect(render($tpl, ['n' => 50]))->toBe('big');
    expect(render($tpl, ['n' => 3]))->toBe('small');
    expect(render($tpl, ['n' => 0]))->toBe('none');
});

it('loops with a loop.index and binds the item', function () {
    $out = render('{% for t in topics %}{{ loop.index }}:{{ t.title }} {% endfor %}', [
        'topics' => [['title' => 'A'], ['title' => 'B']],
    ]);
    expect($out)->toBe('1:A 2:B ');
});

it('applies the whitelisted helpers', function () {
    expect(render('{{ upper(x) }}', ['x' => 'hi']))->toBe('HI');
    expect(render('{{ number(n) }}', ['n' => 1234567]))->toBe('1,234,567');
    expect(render("{{ plural(n, 'reply', 'replies') }}", ['n' => 1]))->toBe('reply');
    expect(render("{{ plural(n, 'reply', 'replies') }}", ['n' => 5]))->toBe('replies');
    expect(render('{{ truncate(s, 5) }}', ['s' => 'abcdefgh']))->toContain('abcde');
    expect(render("{{ default(missing, 'fallback') }}", []))->toBe('fallback');
    expect(render('{{ length(items) }}', ['items' => [1, 2, 3]]))->toBe('3');
});

it('preserves literal template structure (author HTML) verbatim', function () {
    expect(render('<div class="x"><span>{{ v }}</span></div>', ['v' => 'ok']))
        ->toBe('<div class="x"><span>ok</span></div>');
});

it('validate() returns null for good source and a message for bad', function () {
    expect(SandboxRenderer::validate('{{ a.b }}'))->toBeNull();
    expect(SandboxRenderer::validate('{{ 1 * 2 }}'))->toBeString();
});

// ───────────────────────────── SERVICE: storage, lint, render-by-key, integration ─────────────────────

it('renders nothing for a template that is not enabled', function () {
    expect(app(TemplateService::class)->render('home_welcome'))->toBe('');
});

it('save → enabled → renders; revert restores the default; remove → nothing', function () {
    $svc = app(TemplateService::class);

    $svc->save('home_welcome', 'Hi {{ site.name }}');
    expect($svc->render('home_welcome'))->toContain('Hi ');

    $svc->revert('home_welcome');
    expect($svc->source('home_welcome'))->toBe(TemplateContract::default('home_welcome'));

    $svc->remove('home_welcome');
    expect($svc->render('home_welcome'))->toBe('');
});

it('the save lint blocks scripts, handlers and javascript URLs', function (string $bad) {
    expect(fn () => app(TemplateService::class)->save('home_welcome', $bad))->toThrow(SandboxException::class);
})->with([
    '<script>alert(1)</script>',
    '<p onclick="x()">hi</p>',
    '<a href="javascript:alert(1)">x</a>',
    '<style>body{display:none}</style>',
    '<iframe src="evil"></iframe>',
    '{{ 1 * 2 }}', // also rejects un-parseable source
    // Tag-split bypass attempts (found by the adversarial review) — the lint scans the literal SKELETON,
    // so splitting a forbidden token across a tag no longer slips through.
    '<scr{{ x }}ipt>alert(1)</scr{{ x }}ipt>',
    '<img src=x on{{ x }}error="alert(1)">',
    '<a href="javascr{{ x }}ipt:alert(1)">x</a>',
    '<scr{% if false %}{% endif %}ipt>alert(1)</script>',
]);

it('a broken stored template degrades to empty rather than breaking render', function () {
    // Force a row whose source is invalid (bypassing save’s lint) to prove render fails safe.
    SiteTemplate::create(['template_key' => 'home_welcome', 'source' => '{{ 1 * 2 }}', 'is_enabled' => true]);

    expect(app(TemplateService::class)->render('home_welcome'))->toBe('');
});

it('renders the enabled home_welcome template on the forum index', function () {
    $this->seed();
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    app(TemplateService::class)->save('home_welcome', '<p>SANDBOX-WELCOME {{ site.name }}</p>');

    $this->get(route('forums.index'))->assertOk()->assertSee('SANDBOX-WELCOME');
});

// ───────────────────────────── EDITOR (Livewire) ─────────────────────────────

it('blocks non-admins from the template editor (403)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.settings.templates')->assertStatus(403);
});

it('lets a 2FA admin customise, save, and revert a template', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.templates')
        ->call('customize', 'home_welcome')
        ->set('source', '<p>Edited {{ site.name }}</p>')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(TemplateService::class)->render('home_welcome'))->toContain('Edited');

    Livewire::test('admin.settings.templates')
        ->call('edit', 'home_welcome')
        ->set('source', '{{ 1 * 2 }}')
        ->call('save'); // invalid → not saved, error surfaced
    expect(app(TemplateService::class)->source('home_welcome'))->not->toContain('1 * 2');
});
