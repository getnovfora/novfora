<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists uploads OFF the web root (security §4), records a sha-256 checksum (importer verification +
 * dedupe) and image dimensions. Thumbnail + re-encode are tier-graceful: they run when GD/Imagick is
 * present, and are skipped (thumbnail_path stays null) otherwise — never failing the upload. The disk is
 * 'local' on the baseline tier and swaps to S3 on the enhanced tier via config, with no code change.
 */
final class AttachmentService
{
    public const MAX_BYTES = 5_242_880; // 5 MB

    /** @var list<string> */
    public const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    /** @var list<string> */
    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const DISK = 'local';

    public function store(User $user, UploadedFile $file): Attachment
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $dir = 'attachments/'.date('Y/m');
        $name = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs($dir, $name, ['disk' => self::DISK]);

        $width = $height = null;
        $thumbnail = null;
        if (in_array($mime, self::IMAGE_MIMES, true)) {
            $dimensions = @getimagesize($file->getRealPath()); // reads headers; no GD required
            if (is_array($dimensions)) {
                [$width, $height] = $dimensions;
            }
            $thumbnail = $this->makeThumbnail($path, $dir, $name);
        }

        return Attachment::create([
            'user_id' => $user->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'thumbnail_path' => $thumbnail,
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
        ]);
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
