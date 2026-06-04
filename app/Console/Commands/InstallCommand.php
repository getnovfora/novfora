<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Install\Installer;
use App\Install\InstallInput;
use App\Install\InstallRunner;
use App\Install\RequirementChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * `php artisan hearth:install` — the CLI install path for VPS / SSH operators (M5). Runs the exact same
 * App\Install\InstallRunner as the web wizard, so both entry points share one audited sequence.
 *
 * Already-installed is refused unless `--force` (CLI access already implies host trust — this is also
 * the documented way to re-run after deliberately removing the install marker on the host).
 */
class InstallCommand extends Command
{
    protected $signature = 'hearth:install
        {--name= : Community name}
        {--url= : Site URL (e.g. https://forum.example.com)}
        {--db-driver=mysql : mysql|mariadb|pgsql|sqlite}
        {--db-host=127.0.0.1}
        {--db-port=3306}
        {--db-database=hearth}
        {--db-username=hearth}
        {--db-password= : DB password (omit to be prompted; empty allowed)}
        {--admin-username=}
        {--admin-email=}
        {--admin-password= : omit to be prompted (hidden input)}
        {--demo : Seed the demo community}
        {--token= : Setup token (storage/install-token.txt; auto-read if omitted)}
        {--skip-checks : Skip the host requirement checks (not recommended)}
        {--force : Re-run even if already installed}';

    protected $description = 'Install Hearth from the command line (the VPS counterpart to the web installer).';

    public function handle(Installer $installer, RequirementChecker $checker, InstallRunner $runner): int
    {
        if ($installer->isInstalled() && ! $this->option('force')) {
            $this->components->error('Hearth is already installed. Re-run with --force (after removing '.$installer->markerPath().' if you mean to reinstall).');

            return self::FAILURE;
        }

        if (! $this->option('skip-checks') && ! $this->runChecks($checker)) {
            return self::FAILURE;
        }

        $data = $this->gather();
        if (($validated = $this->validate($data)) === null) {
            return self::FAILURE;
        }

        $this->components->info('Installing Hearth…');

        try {
            // --force re-run: clear the marker so the runner's own guard passes.
            if ($installer->isInstalled()) {
                $installer->reset();
            }

            $result = $runner->run(new InstallInput(
                siteName: $validated['name'],
                appUrl: rtrim($validated['url'], '/'),
                dbDriver: $validated['db-driver'],
                dbHost: $validated['db-host'],
                dbPort: (int) $validated['db-port'],
                dbDatabase: $validated['db-database'],
                dbUsername: $validated['db-username'],
                dbPassword: (string) $validated['db-password'],
                adminUsername: $validated['admin-username'],
                adminEmail: $validated['admin-email'],
                adminPassword: $validated['admin-password'],
                seedDemo: (bool) $this->option('demo'),
                // Setup token (phase-1.5 F-A): use --token, else auto-read the file (a CLI operator already
                // has the filesystem access the token gates). The runner verifies + consumes it.
                setupToken: (string) ($this->option('token') ?: $installer->readToken() ?? ''),
            ));
        } catch (\Throwable $e) {
            $this->components->error('Install failed (nothing was locked): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('✓ Hearth is installed and locked.');
        if ($result->demoSeeded) {
            $this->line('  • Demo community seeded.');
        }
        if (! $result->storageLinked) {
            $this->components->warn('Could not create public/storage symlink — run `php artisan storage:link`.');
        }
        foreach ($result->notes as $note) {
            $this->line('  • '.$note);
        }
        $this->newLine();
        $this->components->bulletList([
            'Add the cron line so the queue, search, trust levels, and backups run:',
            '  * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            'Sign in as '.$validated['admin-email'].' — you will be required to set up 2FA (staff).',
        ]);

        return self::SUCCESS;
    }

    private function runChecks(RequirementChecker $checker): bool
    {
        $req = $checker->run();
        foreach ($req['checks'] as $c) {
            $icon = $c['status'] === 'pass' ? '<info>✓</info>' : ($c['status'] === 'warn' ? '<comment>!</comment>' : '<error>✕</error>');
            $this->line("  {$icon} {$c['name']} — {$c['detail']}");
        }
        if (! $req['ok']) {
            $this->components->error('Some required checks failed. Fix them, or re-run with --skip-checks to override.');
        }

        return $req['ok'];
    }

    /** @return array<string, string> */
    private function gather(): array
    {
        return [
            'name' => $this->option('name') ?: $this->ask('Community name', 'My Community'),
            'url' => $this->option('url') ?: $this->ask('Site URL', 'http://localhost'),
            'db-driver' => (string) $this->option('db-driver'),
            'db-host' => (string) $this->option('db-host'),
            'db-port' => (string) $this->option('db-port'),
            'db-database' => (string) $this->option('db-database'),
            'db-username' => (string) $this->option('db-username'),
            'db-password' => $this->resolveDbPassword(),
            'admin-username' => $this->option('admin-username') ?: (string) $this->ask('Admin username'),
            'admin-email' => $this->option('admin-email') ?: (string) $this->ask('Admin email'),
            'admin-password' => $this->option('admin-password') ?: (string) $this->secret('Admin password (min 10, mixed case + a number)'),
        ];
    }

    /** The DB password may legitimately be empty; only prompt when interactive and not supplied. */
    private function resolveDbPassword(): string
    {
        $supplied = $this->option('db-password');
        if ($supplied !== null) {
            return (string) $supplied;
        }

        if ($this->option('no-interaction')) {
            return '';
        }

        return (string) ($this->secret('Database password (leave blank if none)') ?? '');
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, string>|null
     */
    private function validate(array $data): ?array
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url', 'max:255'],
            'db-driver' => ['required', Rule::in(['mysql', 'mariadb', 'pgsql', 'sqlite'])],
            'db-host' => ['required_unless:db-driver,sqlite', 'string', 'max:255'],
            'db-port' => ['required_unless:db-driver,sqlite', 'integer', 'between:1,65535'],
            'db-database' => ['required', 'string', 'max:255'],
            'db-username' => ['required_unless:db-driver,sqlite', 'string', 'max:255'],
            'db-password' => ['nullable', 'string', 'max:255'],
            'admin-username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30'],
            'admin-email' => ['required', 'email', 'max:255'],
            'admin-password' => ['required', 'string', Password::min(10)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return null;
        }

        return $validator->validated();
    }
}
