<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Install\EnvWriter;

/*
| Surfaced by the RH-4 APEX adversarial review of the installer surface. The no-SSH installer writes
| operator-supplied values (APP_NAME, MAIL_FROM_NAME from the Site Name; APP_URL/ASSET_URL from the Site URL)
| into .env. dotenv interpolates `${VAR}`/`$VAR` in unquoted (and double-quoted) values on load, so a value
| like `X${DB_PASSWORD}` written BARE would resolve to a secret on the next request (e.g. leaked as the
| outbound-email From name, or APP_NAME -> page <title>). EnvWriter::format() must always neutralise `$`.
*/

beforeEach(function () {
    $this->envPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'novfora-envsec-'.bin2hex(random_bytes(6)).'.env';
    config(['novfora.install.env_path' => $this->envPath]);
});

afterEach(function () {
    @unlink($this->envPath);
});

it('never writes a bare $-interpolatable value, and a real dotenv parse keeps it literal (no secret leak)', function () {
    $writer = app(EnvWriter::class);

    // DB_PASSWORD is written first (and sits high in .env.example), so an UNescaped `${DB_PASSWORD}` below it
    // WOULD interpolate. APP_KEY (above everything) is the worst case — `${APP_KEY}` -> APP_NAME -> <title>.
    $writer->set([
        'DB_PASSWORD' => 'topsecret123',
        'MAIL_FROM_NAME' => 'X${DB_PASSWORD}',
        'APP_NAME' => '${APP_KEY}',
    ]);

    $raw = (string) file_get_contents($this->envPath);

    // Written quoted + $-escaped — never the bare interpolatable form.
    expect($raw)->toContain('MAIL_FROM_NAME="X\${DB_PASSWORD}"');
    expect($raw)->not->toContain('MAIL_FROM_NAME=X${DB_PASSWORD}');
    expect($raw)->toContain('APP_NAME="\${APP_KEY}"');

    // The end-to-end proof: a real phpdotenv parse resolves nested variables, and these must stay LITERAL.
    $parsed = Dotenv\Dotenv::parse($raw);
    expect($parsed['MAIL_FROM_NAME'])->toBe('X${DB_PASSWORD}');
    expect($parsed['APP_NAME'])->toBe('${APP_KEY}');
    expect($parsed['DB_PASSWORD'])->toBe('topsecret123');
});

it('still leaves a simple value (a base64 APP_KEY) unquoted, like key:generate', function () {
    $writer = app(EnvWriter::class);
    $writer->set(['APP_KEY' => 'base64:abcDEF123+/=']);

    expect((string) file_get_contents($this->envPath))->toContain("\nAPP_KEY=base64:abcDEF123+/=");
});

it('writes a subdirectory ASSET_URL safely (no interpolation even if the path carried a $)', function () {
    $writer = app(EnvWriter::class);
    $writer->set(['ASSET_URL' => 'https://example.com/community']);

    $parsed = Dotenv\Dotenv::parse((string) file_get_contents($this->envPath));
    expect($parsed['ASSET_URL'])->toBe('https://example.com/community');
});
