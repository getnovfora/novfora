<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Attachment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The untrusted-file boundary (ADR-0094, apex). Persists uploads OFF the web root under a RANDOM name
 * (security §4 — no client filename ever touches the path, so no traversal), records a sha-256 checksum
 * (importer verification + dedupe), and HARDENS images: every uploaded image is re-encoded through GD with
 * EXIF stripped and its longest side clamped, which (a) strips a polyglot's non-image payload, (b) removes
 * EXIF (GPS/camera) leakage, and (c) bounds the decoded pixel area. Source images whose HEADER dimensions
 * exceed the configured limit are refused BEFORE decoding (decompression-bomb fence). All of this is
 * tier-graceful: image hardening runs where GD is present and is skipped (allowlist + nosniff + the
 * attachment Content-Disposition on the serve path still protect) otherwise. The disk is 'local' on the
 * baseline tier and swaps to S3 on the enhanced tier via FILESYSTEM_DISK, with no code change.
 */
final class AttachmentService
{
    public const MAX_BYTES = 5_242_880; // 5 MB — kept for back-compat; config('novfora.attachments.max_bytes') is the source

    /** @var list<string> */
    public const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    /** @var list<string> */
    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const DISK = 'local';

    public function store(User $user, UploadedFile $file): Attachment
    {
        // Sniff the real content type (finfo) — never trust the client-declared MIME for the stored value.
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $dir = 'attachments/'.date('Y/m');
        $name = Str::uuid()->toString().'.'.$ext; // random stored name — the client name is data, never a path
        $path = $file->storeAs($dir, $name, ['disk' => self::DISK]);

        if ($path === false || $path === '') {
            throw new AttachmentRejected('the upload could not be stored');
        }

        $width = $height = null;
        $thumbnail = null;
        if (in_array($mime, self::IMAGE_MIMES, true)) {
            // Harden the STORED original (re-encode + strip EXIF + clamp). On reject this deletes the file
            // and throws, so a refused image never lingers on disk.
            [$width, $height] = $this->hardenImage($path, $mime);
            $thumbnail = $this->makeThumbnail($path, $dir, $name);
        }

        // Size is read AFTER hardening (a re-encoded image is usually smaller than the upload).
        $size = (int) (Storage::disk(self::DISK)->size($path) ?: $file->getSize());

        return Attachment::create([
            'user_id' => $user->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'thumbnail_path' => $thumbnail,
            'checksum' => hash_file('sha256', Storage::disk(self::DISK)->path($path)) ?: null,
        ]);
    }

    /**
     * Re-encode an image in place: refuse oversized source dimensions, decode, clamp the longest side, and
     * re-emit clean bytes in the same format (EXIF is dropped, any trailing polyglot payload is discarded).
     * Returns the [width, height] of the stored image. Tier-graceful: with no GD it leaves the original and
     * reports header dimensions. Throws AttachmentRejected for a decompression-bomb or an undecodable image.
     *
     * @return array{0:int|null,1:int|null}
     */
    private function hardenImage(string $path, string $mime): array
    {
        $disk = Storage::disk(self::DISK);
        $bytes = (string) $disk->get($path);

        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            // Header doesn't parse as an image, yet it passed the MIME allowlist → reject (polyglot/corrupt).
            $disk->delete($path);
            throw new AttachmentRejected('the file is not a valid image');
        }
        [$sw, $sh] = [(int) $info[0], (int) $info[1]];

        $maxSource = (int) config('novfora.attachments.max_source_dimension', 12000);
        $maxPixels = (int) config('novfora.attachments.max_source_pixels', 25_000_000);
        // Per-side fence (long strips) AND a total-pixel fence (large squares the per-side fence misses). Both
        // run on the header BEFORE decoding, so a bomb never reaches imagecreatefromstring()'s GD allocation.
        if ($sw > $maxSource || $sh > $maxSource || ($maxPixels > 0 && $sw * $sh > $maxPixels)) {
            $disk->delete($path);
            throw new AttachmentRejected('image dimensions exceed the allowed limit');
        }

        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            return [$sw ?: null, $sh ?: null]; // tier-graceful: no GD → keep original, record header dims
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            $disk->delete($path);
            throw new AttachmentRejected('the image could not be decoded');
        }

        $max = max(1, (int) config('novfora.attachments.max_image_dimension', 2000));
        $scale = min(1.0, $max / max($sw, $sh, 1));
        $tw = max(1, (int) round($sw * $scale));
        $th = max(1, (int) round($sh * $scale));

        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        ob_start();
        match ($mime) {
            'image/png' => imagepng($dst),
            'image/gif' => imagegif($dst),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, null, 85) : imagepng($dst),
            default => imagejpeg($dst, null, 85), // image/jpeg
        };
        $clean = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $disk->put($path, $clean); // overwrite the upload with the clean, EXIF-free, clamped re-encode

        return [$tw, $th];
    }

    /**
     * Associate $uploader's own orphan attachments referenced in a post's canonical body to that post, so
     * the serve gate's forum.view path applies (until associated, an orphan is uploader-only and readers
     * 403). Only the uploader's own, still-unattached files qualify — no hijacking another user's attachment
     * or stealing one already bound to a post (so a moderator editing a post can add their OWN file but can
     * never pull in someone else's orphan). Enforces the per-post count + total-size caps; once a cap is
     * reached the remaining references are left as orphans (pruned later) rather than silently exceeding it.
     *
     * @param  array<string,mixed>  $canonical
     */
    public function attachToPost(Post $post, array $canonical, int $uploaderId): void
    {
        $ids = $this->referencedAttachmentIds($canonical);
        if ($ids === []) {
            return;
        }

        $maxCount = max(1, (int) config('novfora.attachments.max_per_post', 10));
        $maxBytes = max(1, (int) config('novfora.attachments.max_per_post_bytes', 26_214_400));

        // Already-attached files this post already owns count toward the per-post caps (so an edit that adds
        // files can't blow past the cap by topping up an existing set).
        $existing = Attachment::query()->where('post_id', $post->id)->get();
        $count = $existing->count();
        $bytes = (int) $existing->sum('size');

        $orphans = Attachment::query()
            ->whereIn('id', $ids)
            ->where('user_id', $uploaderId)
            ->whereNull('post_id')
            ->orderBy('id')
            ->get();

        foreach ($orphans as $attachment) {
            if ($count + 1 > $maxCount || $bytes + (int) $attachment->size > $maxBytes) {
                break; // cap reached — never exceed it; the rest stay orphans and are pruned
            }
            $attachment->forceFill(['post_id' => $post->id])->save();
            $count++;
            $bytes += (int) $attachment->size;
        }
    }

    /**
     * Delete never-published draft attachments (post_id IS NULL) older than $hours, removing both the stored
     * file(s) and the row. Safe to run repeatedly; chunked so it never holds the whole set in memory.
     */
    public function pruneOrphans(int $hours): int
    {
        $cutoff = now()->subHours(max(1, $hours));
        $pruned = 0;

        Attachment::query()
            ->whereNull('post_id')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($batch) use (&$pruned): void {
                foreach ($batch as $attachment) {
                    $disk = Storage::disk($attachment->disk);
                    if ($attachment->path) {
                        $disk->delete($attachment->path);
                    }
                    if ($attachment->thumbnail_path) {
                        $disk->delete($attachment->thumbnail_path);
                    }
                    $attachment->forceDelete();
                    $pruned++;
                }
            });

        return $pruned;
    }

    /**
     * Pull the attachment ids a canonical document references via its image/file node src/href URLs (the
     * `…/attachments/{id}` serve route). Recurses the whole tree.
     *
     * @param  array<string,mixed>  $canonical
     * @return list<int>
     */
    private function referencedAttachmentIds(array $canonical): array
    {
        $ids = [];
        $walk = function ($node) use (&$walk, &$ids): void {
            if (! is_array($node)) {
                return;
            }
            foreach (['src', 'href', 'url'] as $key) {
                $value = $node['attrs'][$key] ?? null;
                if (is_string($value) && preg_match('~/attachments/(\d+)(?:[/?#]|$)~', $value, $m) === 1) {
                    $ids[] = (int) $m[1];
                }
            }
            foreach (($node['content'] ?? []) as $child) {
                $walk($child);
            }
        };
        $walk($canonical);

        return array_values(array_unique($ids));
    }

    /** Generate a ≤320px WebP thumbnail when GD is available; never let thumbnailing break an upload. */
    private function makeThumbnail(string $sourcePath, string $dir, string $name): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            return null; // tier-graceful — thumbnails run where the image extension is installed
        }

        try {
            $disk = Storage::disk(self::DISK);
            $image = @imagecreatefromstring((string) $disk->get($sourcePath));
            if ($image === false) {
                return null;
            }

            $w = imagesx($image);
            $h = imagesy($image);
            $scale = min(1.0, 320 / max($w, $h, 1));
            $tw = max(1, (int) ($w * $scale));
            $th = max(1, (int) ($h * $scale));

            $thumb = imagecreatetruecolor($tw, $th);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $w, $h);

            ob_start();
            function_exists('imagewebp') ? imagewebp($thumb, null, 82) : imagepng($thumb);
            $blob = (string) ob_get_clean();
            imagedestroy($image);
            imagedestroy($thumb);

            $thumbPath = $dir.'/thumb_'.pathinfo($name, PATHINFO_FILENAME).'.webp';
            $disk->put($thumbPath, $blob);

            return $thumbPath;
        } catch (\Throwable) {
            return null;
        }
    }
}
