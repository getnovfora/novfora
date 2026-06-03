<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Permissions\PermissionInspector;
use App\Permissions\Scope;
use Illuminate\Console\Command;

/**
 * `php artisan hearth:why <user> <permission> <scope>` — explain a permission decision (security §1.4).
 *
 * Examples:
 *   php artisan hearth:why 1 forum.post.create forum:2
 *   php artisan hearth:why admin@example.test forum.topic.delete thread:17
 *   php artisan hearth:why 5 admin.access global
 */
class PermissionWhyCommand extends Command
{
    protected $signature = 'hearth:why {user : user id or email} {permission : permission key} {scope=global : global|category:ID|forum:ID|thread:ID}';

    protected $description = 'Explain why a user can or cannot do something at a scope (the ACL inspector).';

    public function handle(PermissionInspector $inspector): int
    {
        $user = $this->resolveUser($this->argument('user'));
        if (! $user) {
            $this->components->error("No user matched [{$this->argument('user')}].");

            return self::FAILURE;
        }

        try {
            $scope = Scope::parse((string) $this->argument('scope'));
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $report = $inspector->inspect($user, (string) $this->argument('permission'), $scope);

        $report['granted']
            ? $this->components->info('ALLOWED — '.$report['summary'])
            : $this->components->error('DENIED — '.$report['summary']);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>User</>', "{$report['user']['label']} (#{$report['user']['id']}, {$report['user']['status']})");
        $this->components->twoColumnDetail('<fg=gray>Permission</>', $report['permission']);
        $this->components->twoColumnDetail('<fg=gray>Scope</>', $report['scope']);
        $this->components->twoColumnDetail('<fg=gray>Decisive rule</>', $report['reason'].($report['decided_by'] ? " (by {$report['decided_by']} @ ".($report['decided_at_scope'] ?? '—').')' : ''));
        $this->components->twoColumnDetail('<fg=gray>Scope chain</>', implode('  →  ', $report['scope_chain']));
        $this->components->twoColumnDetail('<fg=gray>Holders considered</>', implode(', ', $report['holders']));

        $this->newLine();
        if ($report['entries'] === []) {
            $this->components->warn('No ACL entries matched these holders for this permission in this chain → deny-by-default.');
        } else {
            $this->components->info('Candidate ACL entries (most general → most specific):');
            $this->table(
                ['Holder', 'Scope', 'Value'],
                array_map(fn (array $e) => [$e['holder'], $e['scope'], $e['value']], $report['entries']),
            );
        }

        return self::SUCCESS;
    }

    private function resolveUser(string $ref): ?User
    {
        return is_numeric($ref)
            ? User::find((int) $ref)
            : User::where('email', $ref)->orWhere('username', $ref)->first();
    }
}
