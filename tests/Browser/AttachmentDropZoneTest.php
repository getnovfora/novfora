<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\Support\Users;

/*
| Dusk coverage for the ADR-0094 drag-and-drop attach zone (design-polish Slice 2). The server-observable
| half — upload authz, MIME/size hardening, association-on-publish, orphan prune — is covered by the
| always-on Feature suite (AttachmentHardeningTest / AttachmentAuthorizationTest); these in-browser specs
| add the interaction proof. Requires a Chrome-enabled environment — run with `php artisan dusk`. CI-only:
| the baseline gate box has no headless browser (see docker/dusk/), so these are CI-pending.
|
| Selector contract (resources/views/components/content-editor.blade.php): `.novfora-attach` is the zone,
| `.novfora-attach-browse` the click-to-browse fallback, `.novfora-attach-max` the per-file size readout,
| `.novfora-attach-item` a queued upload with `.is-done` once accepted. The composer wires the zone via
| `:upload-url` (⚡create-topic.blade.php), so it renders on the new-topic screen.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->member = Users::inGroups(['members'], ['username' => 'ada', 'email' => 'ada@novfora.test']);
    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
});

it('renders the discoverable attach zone in the composer (browse control + max-size readout)', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->member)
            ->visit(route('topics.create', $this->forum))
            ->waitFor('.novfora-prose', 15)        // editor island mounted
            ->waitFor('.novfora-attach', 15)        // the drop zone renders when an upload URL is wired
            ->assertVisible('.novfora-attach-browse')
            ->assertSeeIn('.novfora-attach-max', 'Max'); // "Max N.N MB per file" readout
    });
});

it('uploads a browsed file and shows it as Added in the attach list', function () {
    $path = tempnam(sys_get_temp_dir(), 'novfora-dusk').'.txt';
    file_put_contents($path, 'a small attachable text file');

    $this->browse(function (Browser $browser) use ($path) {
        $browser->loginAs($this->member)
            ->visit(route('topics.create', $this->forum))
            ->waitFor('.novfora-attach', 15)
            // The multi-file input lives INSIDE .novfora-attach; the single image picker sits outside it,
            // so this selector targets the attach-zone input uniquely.
            ->attach('.novfora-attach input[type="file"]', $path)
            ->waitFor('.novfora-attach-item', 15)
            ->waitForText('Added', 20)               // u.status === 'done'
            ->assertPresent('.novfora-attach-item.is-done');
    });

    @unlink($path);
});
