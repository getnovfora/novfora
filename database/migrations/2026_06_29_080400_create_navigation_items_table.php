<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Admin-editable public navigation. The default rows preserve the shipped hardcoded primary/header menu, while
// the manager may replace, reorder, hide, or nest items without touching Blade templates.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('navigation_items')) {
            Schema::create('navigation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->nullOnDelete();
                $table->string('title', 80);
                $table->string('link_type', 20)->default('route');
                $table->string('route_name', 120)->nullable();
                $table->string('url', 2048)->nullable();
                $table->string('icon', 40)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->boolean('show_on_desktop')->default(true);
                $table->boolean('show_on_mobile')->default(true);
                $table->boolean('opens_new_tab')->default(false);
                $table->string('visibility', 40)->default('everyone');
                $table->json('group_ids')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->timestamps();

                $table->index(['parent_id', 'position']);
                $table->index(['is_enabled', 'show_on_desktop', 'show_on_mobile']);
            });
        }

        if (Schema::hasTable('navigation_items') && DB::table('navigation_items')->count() === 0) {
            $now = now();
            DB::table('navigation_items')->insert([
                [
                    'title' => 'Forums',
                    'link_type' => 'route',
                    'route_name' => 'forums.index',
                    'url' => null,
                    'show_on_desktop' => true,
                    'show_on_mobile' => true,
                    'position' => 1,
                    'visibility' => 'everyone',
                    'group_ids' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'title' => 'Clubs',
                    'link_type' => 'route',
                    'route_name' => 'clubs.index',
                    'url' => null,
                    'show_on_desktop' => true,
                    'show_on_mobile' => true,
                    'position' => 2,
                    'visibility' => 'everyone',
                    'group_ids' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'title' => 'Trending',
                    'link_type' => 'route',
                    'route_name' => 'trending.index',
                    'url' => null,
                    'show_on_desktop' => true,
                    'position' => 3,
                    'show_on_mobile' => false,
                    'visibility' => 'everyone',
                    'group_ids' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'title' => 'Groups',
                    'link_type' => 'route',
                    'route_name' => 'groups.index',
                    'url' => null,
                    'show_on_desktop' => true,
                    'show_on_mobile' => true,
                    'position' => 4,
                    'visibility' => 'public_groups_directory',
                    'group_ids' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'title' => 'Members',
                    'link_type' => 'route',
                    'route_name' => 'members.index',
                    'url' => null,
                    'show_on_desktop' => true,
                    'show_on_mobile' => true,
                    'position' => 5,
                    'visibility' => 'members_directory',
                    'group_ids' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'title' => "What's new",
                    'link_type' => 'route',
                    'route_name' => 'whats-new',
                    'url' => null,
                    'show_on_desktop' => true,
                    'show_on_mobile' => true,
                    'position' => 6,
                    'visibility' => 'authenticated',
                    'group_ids' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
    }
};
