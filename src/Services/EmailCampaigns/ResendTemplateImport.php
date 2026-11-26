<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Visnsstudio\VisnsPackages\Models\EmailTemplate;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * Copies the team's Resend templates into this module's templates, as editor
 * content.
 *
 * IDEMPOTENT, KEYED ON RESEND'S ID, stored inside the content (no schema
 * change):
 *
 *     content.source = {provider: 'resend', id, updated_at, hash}
 *
 * `hash` is the SHA-256 of the html this import wrote. A template whose html
 * no longer matches it was changed here, and a later import leaves it alone;
 * an untouched one is replaced whenever what the import would write now
 * (html, text or subject) differs from what it holds: a change at Resend and
 * an improvement to the import alike. So the import can be run again at any
 * time, and a run cut short (Resend's 429) simply finishes next time.
 *
 * Images on Resend's CDN (`email_campaigns.import.rehost_hosts`) are copied
 * onto our public image disk through `EmailImageStore`, content-addressed, so
 * the same Resend content always produces the same html. An image that cannot
 * be copied keeps its Resend URL, with a warning.
 *
 * Resend's placeholders are `{{{KEY}}}` / `{{{KEY|fallback}}}`. The contact
 * fields map to our merge tags (`{first_name}` …, mapped back to Resend's
 * syntax at send); the unsubscribe link stays as Resend's; any other variable
 * takes its fallback, else stays as typed with a warning.
 *
 * Never logs html, text or names: counts only.
 */
class ResendTemplateImport
{
    public const MAX_PAGES = 20;

    public const PROVIDER = 'resend';

    /** Resend placeholder => our merge tag (keys lower-cased). */
    private const TAGS = [
        'first_name' => '{first_name}',
        'contact.first_name' => '{first_name}',
        'last_name' => '{last_name}',
        'contact.last_name' => '{last_name}',
        'email' => '{email}',
        'contact.email' => '{email}',
        'company' => '{company}',
        'contact.company' => '{company}',
    ];

    private const UNSUBSCRIBE = ['resend_unsubscribe_url', 'unsubscribe_url'];

    /** Images copied from one template at most. */
    public const MAX_IMAGES = 60;

    public const IMAGE_FAILED = 'an image could not be copied (it stays on Resend\'s server)';

    public const IMAGE_CAP = 'has more than 60 images; the rest stay on Resend\'s server';

    /** @var array<string, ?string> this run's copies: source URL => our URL (null = could not copy) */
    private array $copies = [];

    /** @var callable(int): void */
    private $sleep;

    private int $calls = 0;

    public function __construct(private ResendClient $resend, private EmailEditorHtml $sanitiser)
    {
        $this->sleep = static fn (int $microseconds) => usleep($microseconds);
    }

    /** Tests replace the sleep so the pacing costs no time. */
    public function sleepUsing(callable $sleep): self
    {
        $this->sleep = $sleep;

        return $this;
    }

    /**
     * @return array{
     *     reason: ?string,
     *     imported: array<int, string>,
     *     updated: array<int, string>,
     *     unchanged: array<int, string>,
     *     skipped: array<int, array{name: string, reason: string}>,
     *     warnings: array<int, array{name: string, text: string}>,
     *     stopped: ?string
     * }  `reason` is set (and nothing else happened) when Resend could not be read at all
     */
    public function run(?int $userId): array
    {
        $this->calls = 0;
        $this->copies = [];
        $out = [
            'reason' => null,
            'imported' => [],
            'updated' => [],
            'unchanged' => [],
            'skipped' => [],
            'warnings' => [],
            'stopped' => null,
        ];

        /* 1. every template's summary, page by page ------------------------ */
        $summaries = [];
        $after = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->call(fn () => $this->resend->listTemplates($after));

            if (! $result->ok) {
                if ($page === 0) {
                    $out['reason'] = $result->throttled()
                        ? 'Resend is busy (too many requests). Try the import again in a minute.'
                        : 'Resend could not list the templates: ' . $result->error;
                    $this->log($out);

                    return $out;
                }

                $out['stopped'] = $this->stopSentence($result, 0);
                $this->log($out);

                return $out;
            }

            $rows = array_values(array_filter((array) ($result->data['data'] ?? []), fn ($row) => is_array($row) && ($row['id'] ?? '') !== ''));

            foreach ($rows as $row) {
                $summaries[(string) $row['id']] ??= $row;
            }

            if (empty($result->data['has_more']) || $rows === []) {
                break;
            }

            $after = (string) end($rows)['id'];
        }

        if ($summaries === []) {
            $this->log($out);

            return $out;
        }

        /* 2. what earlier imports wrote, by Resend id ----------------------- */
        $existing = [];
        $names = [];

        foreach (EmailTemplate::query()->get(['id', 'name', 'content']) as $template) {
            $content = (array) $template->content;
            $source = is_array($content['source'] ?? null) ? $content['source'] : null;

            if ($source && ($source['provider'] ?? null) === self::PROVIDER && ($source['id'] ?? '') !== '') {
                $existing[(string) $source['id']] ??= $template;
            } else {
                $names[mb_strtolower((string) $template->name)] = true;
            }
        }

        /* 3. each template in full ----------------------------------------- */
        $done = 0;

        foreach ($summaries as $id => $summary) {
            $result = $this->call(fn () => $this->resend->getTemplate($id));
            $label = $this->name($result->data['name'] ?? $summary['name'] ?? null, $id);

            if (! $result->ok) {
                if ($result->status !== null && $result->status >= 400 && $result->status < 500 && ! $result->throttled()) {
                    $out['skipped'][] = ['name' => $label, 'reason' => $result->status === 404
                        ? 'no longer in Resend'
                        : 'Resend would not give it: ' . $result->error];

                    continue;
                }

                $out['stopped'] = $this->stopSentence($result, $done);

                break;
            }

            $done++;
            $template = $result->data;
            $html = (string) ($template['html'] ?? '');

            if (trim($html) === '') {
                $out['skipped'][] = ['name' => $label, 'reason' => 'no HTML (a React-only template?)'];

                continue;
            }

            if (strlen($html) > EmailContent::HTML_MAX) {
                $out['skipped'][] = ['name' => $label, 'reason' => 'too large to keep as a template'];

                continue;
            }

            $updatedAt = (string) ($template['updated_at'] ?? $summary['updated_at'] ?? '');
            $row = $existing[$id] ?? null;

            if ($row) {
                $content = (array) $row->content;
                $source = (array) $content['source'];

                if (! hash_equals((string) ($source['hash'] ?? ''), hash('sha256', (string) ($content['html'] ?? '')))) {
                    $out['skipped'][] = ['name' => (string) $row->name, 'reason' => 'edited here since the last import'];

                    continue;
                }

                [$fresh, $warnings] = $this->content($template, $id, $updatedAt);

                if ($this->same($fresh, $content)) {
                    $out['unchanged'][] = (string) $row->name;

                    continue;
                }

                $row->content = $fresh;
                $row->save();

                $out['updated'][] = (string) $row->name;
                $this->warn($out, (string) $row->name, $warnings);

                continue;
            }

            [$content, $warnings] = $this->content($template, $id, $updatedAt);
            $name = isset($names[mb_strtolower($label)]) ? mb_substr($label, 0, 182) . ' (Resend)' : $label;

            $created = EmailTemplate::create(['name' => $name, 'content' => $content, 'user_id' => $userId]);
            $existing[$id] = $created;

            $out['imported'][] = $name;
            $this->warn($out, $name, $warnings);
        }

        $this->log($out);

        return $out;
    }

    /**
     * The stored content of one Resend template, and the warnings it earns.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function content(array $template, string $id, string $updatedAt): array
    {
        $warnings = [];
        $fallbacks = [];

        foreach ((array) ($template['variables'] ?? []) as $variable) {
            if (is_array($variable) && isset($variable['key']) && array_key_exists('fallback_value', $variable) && $variable['fallback_value'] !== null && ! is_array($variable['fallback_value'])) {
                $fallbacks[mb_strtolower(trim((string) $variable['key']))] = (string) (is_bool($variable['fallback_value']) ? ($variable['fallback_value'] ? 'true' : 'false') : $variable['fallback_value']);
            }
        }

        $html = $this->map((string) $template['html'], $fallbacks, true, $warnings);
        $html = $this->rehost($html, $warnings);
        $text = $this->map((string) ($template['text'] ?? ''), $fallbacks, false, $warnings);

        // The same cleaning path an editor save takes.
        $stored = EmailContent::storedEditor(['format' => EmailContent::FORMAT_EDITOR, 'doc' => null, 'html' => $html, 'text' => $text], $this->sanitiser);

        $stored['subject'] = isset($template['subject']) && is_scalar($template['subject']) ? mb_substr((string) $template['subject'], 0, 255) : null;
        $stored['source'] = [
            'provider' => self::PROVIDER,
            'id' => $id,
            'updated_at' => $updatedAt,
            'hash' => hash('sha256', $stored['html']),
        ];

        return [$stored, array_values(array_unique($warnings))];
    }

    /**
     * Resend's `{{{KEY}}}` / `{{{KEY|fallback}}}` in our terms.
     *
     * @param  array<string, string>  $fallbacks  the template's declared fallbacks, by lower-cased key
     * @param  array<int, string>  $warnings
     */
    private function map(string $source, array $fallbacks, bool $html, array &$warnings): string
    {
        return (string) preg_replace_callback('/\{\{\{\s*([^{}|]+?)\s*(?:\|([^{}]*))?\}\}\}/', function ($hit) use ($fallbacks, $html, &$warnings) {
            $key = mb_strtolower(trim($hit[1]));

            if (isset(self::TAGS[$key])) {
                return self::TAGS[$key];
            }

            if (in_array($key, self::UNSUBSCRIBE, true)) {
                $warnings[] = 'has its own unsubscribe link as well as the footer\'s';

                return '{{{RESEND_UNSUBSCRIBE_URL}}}';
            }

            // An inline fallback is already written in the template's own
            // markup; a declared one is plain text.
            if (isset($hit[2])) {
                return $hit[2];
            }

            if (array_key_exists($key, $fallbacks)) {
                return $html ? e($fallbacks[$key]) : $fallbacks[$key];
            }

            $warnings[] = '{{{' . trim($hit[1]) . '}}} has no value here; edit the template';

            return $hit[0];
        }, $source);
    }

    /** @param  array<int, string>  $warnings */
    private function warn(array &$out, string $name, array $warnings): void
    {
        foreach ($warnings as $text) {
            $out['warnings'][] = ['name' => $name, 'text' => $text];
        }
    }

    /** Would writing $fresh change what a person sees in $stored? (Bookkeeping aside.) */
    private function same(array $fresh, array $stored): bool
    {
        foreach (['html', 'text', 'subject'] as $key) {
            if (($fresh[$key] ?? null) !== ($stored[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copy every image on a rehost host (`<img src>`, `background=""`) onto our
     * image disk and point the html at the copy. Never throws: an image that
     * cannot be copied keeps its URL and earns a warning.
     *
     * @param  array<int, string>  $warnings
     */
    private function rehost(string $html, array &$warnings): string
    {
        $hosts = array_values(array_filter(array_map(
            fn ($host) => is_string($host) ? mb_strtolower(trim($host)) : '',
            (array) ModuleConfig::get('email_campaigns.import.rehost_hosts', ['cdn.resend.app'])
        )));

        if ($hosts === []) {
            return $html;
        }

        preg_match_all('/<img\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1/is', $html, $sources);
        preg_match_all('/<[a-z][^>]*?\sbackground\s*=\s*(["\'])(.*?)\1/is', $html, $backgrounds);

        // Raw attribute value => the URL it means (entities decoded).
        $found = [];

        foreach (array_merge($sources[2], $backgrounds[2]) as $raw) {
            $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

            if (in_array($scheme, ['http', 'https'], true) && in_array($host, $hosts, true)) {
                $found[$raw] = $url;
            }
        }

        $urls = array_values(array_unique($found));

        if (count($urls) > self::MAX_IMAGES) {
            $warnings[] = self::IMAGE_CAP;
            $urls = array_slice($urls, 0, self::MAX_IMAGES);
        }

        $replace = [];

        foreach ($found as $raw => $url) {
            if (! in_array($url, $urls, true)) {
                continue;
            }

            $copy = $this->copy($url);

            if ($copy === null) {
                $warnings[] = self::IMAGE_FAILED;

                continue;
            }

            $replace[$raw] = e($copy);
        }

        // Longest first, so a URL that is a prefix of another never splits it.
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $replace === [] ? $html : strtr($html, $replace);
    }

    /** Our URL for a copy of this image, fetched once a run; null when it could not be copied. */
    private function copy(string $url): ?string
    {
        if (array_key_exists($url, $this->copies)) {
            return $this->copies[$url];
        }

        $max = EmailImageStore::maxBytes();
        $copy = null;

        try {
            $response = Http::timeout(10)
                ->withOptions([
                    'allow_redirects' => false, // only the listed hosts are fetched; a redirect could point anywhere
                    // Give up early on a declared size over the limit.
                    'on_headers' => function ($headers) use ($max) {
                        $length = $headers->getHeaderLine('Content-Length');

                        if ($length !== '' && (int) $length > $max) {
                            throw new \RuntimeException('image too large');
                        }
                    },
                ])
                ->get($url);

            if ($response->successful()) {
                $bytes = $response->body();
                $copy = strlen($bytes) <= $max ? EmailImageStore::store($bytes) : null;
            }
        } catch (\Throwable) {
            $copy = null;
        }

        return $this->copies[$url] = $copy;
    }

    private function name($name, string $id): string
    {
        $name = trim(is_scalar($name) ? (string) $name : '');

        return mb_substr($name !== '' ? $name : 'Resend template ' . $id, 0, 191);
    }

    private function stopSentence(ResendResult $result, int $done): string
    {
        $so = $done === 1 ? '1 template was read' : $done . ' templates were read';

        return $result->throttled()
            ? 'Resend asked us to slow down after ' . $so . '. Run the import again in a minute to finish; what is done stays done.'
            : 'Resend stopped answering after ' . $so . ' (' . $result->error . '). Run the import again later to finish.';
    }

    /** One paced Resend call: Resend allows 10 requests a second a team. */
    private function call(callable $request): ResendResult
    {
        if ($this->calls++ > 0) {
            $gap = (int) ModuleConfig::get('email_campaigns.import.pace_ms', 200);

            if ($gap > 0) {
                ($this->sleep)($gap * 1000);
            }
        }

        return $request();
    }

    private function log(array $out): void
    {
        Log::info('email_campaigns.resend_import', [
            'imported' => count($out['imported']),
            'updated' => count($out['updated']),
            'unchanged' => count($out['unchanged']),
            'skipped' => count($out['skipped']),
            'warnings' => count($out['warnings']),
            'stopped' => $out['stopped'] !== null,
            'unreachable' => $out['reason'] !== null,
            'requests' => $this->calls,
        ]);
    }
}
