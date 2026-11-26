<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The one rule for an image a campaign email shows: where it lives, which
 * types and how big.
 *
 * Images go to a PUBLIC disk (`email_campaigns.image_disk`), because a
 * stranger's mail client has to be able to fetch them. The type is sniffed off
 * the bytes, never taken from a name or a header; JPEG, PNG, GIF and WebP only.
 * No SVG: it is a document, and most mail clients will not draw one anyway.
 *
 * Used by the editor's upload (`EmailCampaignController::uploadImage`) and by
 * the Resend template import, which copies Resend-hosted images here.
 */
class EmailImageStore
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** The largest image accepted, in bytes. */
    public static function maxBytes(): int
    {
        return max(1, (int) ModuleConfig::get('email_campaigns.image_max_kb', 5120)) * 1024;
    }

    /** The file extension for these bytes, or null when they are not an allowed image. */
    public static function extension(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return self::TYPES[$mime] ?? null;
    }

    /**
     * Store an image under a content-addressed name, `<directory>/<folder>/<sha256>.<ext>`:
     * the same bytes always land on the same path (and the same URL), so a
     * repeat store writes nothing and duplicates cannot pile up.
     *
     * @return ?string  the absolute public URL, or null when the bytes are not an allowed image or too big
     */
    public static function store(string $bytes, string $folder = 'resend'): ?string
    {
        $ext = self::accept($bytes);

        if ($ext === null) {
            return null;
        }

        $path = self::directory() . '/' . trim($folder, '/') . '/' . hash('sha256', $bytes) . '.' . $ext;
        $disk = Storage::disk(self::disk());

        if (! $disk->exists($path)) {
            $disk->put($path, $bytes, 'public');
        }

        return self::url($path);
    }

    /**
     * Store an uploaded image under a fresh dated name, `<directory>/Y/m/<random>.<ext>`.
     *
     * @return ?string  the absolute public URL, or null when the bytes are not an allowed image or too big
     */
    public static function storeUpload(string $bytes): ?string
    {
        $ext = self::accept($bytes);

        if ($ext === null) {
            return null;
        }

        $path = self::directory() . '/' . now()->format('Y/m') . '/' . Str::random(24) . '.' . $ext;
        Storage::disk(self::disk())->put($path, $bytes, 'public');

        return self::url($path);
    }

    private static function accept(string $bytes): ?string
    {
        return strlen($bytes) > self::maxBytes() ? null : self::extension($bytes);
    }

    private static function disk(): string
    {
        return (string) ModuleConfig::get('email_campaigns.image_disk', 'public');
    }

    private static function directory(): string
    {
        return trim((string) ModuleConfig::get('email_campaigns.image_directory', 'email-campaigns'), '/');
    }

    /** The disk's URL for a path, made absolute (a mail client has no base URL). */
    private static function url(string $path): string
    {
        $url = Storage::disk(self::disk())->url($path);

        if (! preg_match('#^https?://#i', $url)) {
            $url = rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        return $url;
    }
}
