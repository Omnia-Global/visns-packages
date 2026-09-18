<?php

namespace Visnsstudio\VisnsPackages\Services\EmailCampaigns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The few Resend REST calls the email campaign module makes.
 *
 * Laravel's HTTP client rather than resend/resend-php: the SDK is a thin
 * wrapper over exactly these paths, and calling them directly adds no
 * dependency to every project that installs this package and lets a test fake
 * the wire with `Http::fake()`. The paths are the SDK's own (v1.10).
 *
 * EVERY METHOD ANSWERS A ResendResult AND NONE THROWS. A caller deciding what
 * to tell a person needs the status and Resend's own sentence, and an exception
 * would lose both at the first network hiccup.
 */
class ResendClient
{
    public function configured(): bool
    {
        return trim((string) ModuleConfig::get('email_campaigns.api_key')) !== '';
    }

    public function createSegment(string $name): ResendResult
    {
        return $this->send('post', 'segments', ['name' => $name]);
    }

    public function deleteSegment(string $id): ResendResult
    {
        return $this->send('delete', 'segments/' . rawurlencode($id));
    }

    /**
     * Update a contact by email, or create it (into the segment) when there is
     * none. `unsubscribed` is never sent: Resend owns it, and writing it from
     * here could re-subscribe somebody who opted out.
     */
    public function upsertContact(string $email, array $fields, string $segmentId): ResendResult
    {
        $update = $this->send('patch', 'contacts/' . rawurlencode($email), $fields);

        if ($update->status === 404) {
            return $this->send('post', 'contacts', $fields + [
                'email' => $email,
                'segments' => [['id' => $segmentId]],
            ]);
        }

        if (! $update->ok) {
            return $update;
        }

        $added = $this->send('post', 'contacts/' . rawurlencode($email) . '/segments/' . rawurlencode($segmentId));

        // Already in the segment is success, whatever Resend calls it.
        return ($added->ok || $added->status === 409) ? ResendResult::success($added->status, $added->data) : $added;
    }

    public function removeFromSegment(string $email, string $segmentId): ResendResult
    {
        $result = $this->send('delete', 'contacts/' . rawurlencode($email) . '/segments/' . rawurlencode($segmentId));

        // Not there any more is what removing wanted.
        return $result->status === 404 ? ResendResult::success(404, []) : $result;
    }

    /** A custom contact property, e.g. `company`. An existing one is fine. */
    public function ensureProperty(string $key): ResendResult
    {
        $result = $this->send('post', 'contact-properties', [
            'key' => $key,
            'type' => 'string',
            'fallback_value' => '',
        ]);

        return ($result->ok || in_array($result->status, [409, 422], true))
            ? ResendResult::success($result->status, $result->data)
            : $result;
    }

    public function createBroadcast(array $parameters): ResendResult
    {
        return $this->send('post', 'broadcasts', $parameters);
    }

    /** Send a created broadcast now, or at `$scheduledAt` (ISO 8601). */
    public function sendBroadcast(string $id, ?string $scheduledAt = null): ResendResult
    {
        return $this->send(
            'post',
            'broadcasts/' . rawurlencode($id) . '/send',
            $scheduledAt ? ['scheduled_at' => $scheduledAt] : []
        );
    }

    public function getBroadcast(string $id): ResendResult
    {
        return $this->send('get', 'broadcasts/' . rawurlencode($id));
    }

    public function cancelBroadcast(string $id): ResendResult
    {
        return $this->send('post', 'broadcasts/' . rawurlencode($id) . '/cancel');
    }

    /** One ordinary email: the test send. */
    public function sendEmail(array $parameters): ResendResult
    {
        return $this->send('post', 'emails', $parameters);
    }

    private function send(string $method, string $path, array $body = []): ResendResult
    {
        if (! $this->configured()) {
            return ResendResult::failure(null, 'Email campaigns are not connected to Resend: set RESEND_KEY.');
        }

        try {
            /** @var Response $response */
            $response = $this->http()->{$method}($path, $method === 'get' ? [] : $body);
        } catch (\Throwable $e) {
            return ResendResult::failure(null, 'Resend could not be reached: ' . $e->getMessage());
        }

        $data = (array) ($response->json() ?? []);

        if ($response->successful()) {
            return ResendResult::success($response->status(), $data);
        }

        return ResendResult::failure(
            $response->status(),
            (string) ($data['message'] ?? $data['error'] ?? ('Resend answered ' . $response->status() . '.')),
            $data
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) ModuleConfig::get('email_campaigns.api_base', 'https://api.resend.com'), '/'))
            ->withToken((string) ModuleConfig::get('email_campaigns.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }
}
