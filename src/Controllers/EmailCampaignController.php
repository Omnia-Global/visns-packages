<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Visnsstudio\VisnsPackages\Models\EmailCampaign;
use Visnsstudio\VisnsPackages\Models\EmailList;
use Visnsstudio\VisnsPackages\Models\EmailListMember;
use Visnsstudio\VisnsPackages\Models\EmailTemplate;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailCampaignRenderer;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailCampaignReport;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailCampaignSender;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\EmailListBuilder;
use Visnsstudio\VisnsPackages\Services\EmailCampaigns\ResendClient;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;

/**
 * The email campaign module's endpoints: campaigns, lists, templates, images.
 *
 * Reads are `permissions.access`; every write and every send is
 * `permissions.manage` (routes in the service provider). Refusals are 422 with
 * a sentence, never a bare status: the screen prints what the server says.
 */
class EmailCampaignController extends Controller
{
    public function __construct(
        private EmailCampaignSender $sender,
        private EmailCampaignRenderer $renderer,
        private EmailCampaignReport $reports,
        private EmailListBuilder $lists,
        private ResendClient $resend,
    ) {
    }

    /* ---------------------------------------------------------------------- */
    /* the overview                                                           */
    /* ---------------------------------------------------------------------- */

    /** `GET {base}` — campaigns, lists and what the screen needs to know. */
    public function index(Request $request): JsonResponse
    {
        $source = $this->lists->source();

        return response()->json([
            'campaigns' => EmailCampaign::query()->with(['list', 'user'])->orderByDesc('id')->limit(200)->get()
                ->map(fn (EmailCampaign $campaign) => $this->campaignRow($campaign))->values(),
            'lists' => EmailList::query()->orderBy('name')->get()->map(fn (EmailList $list) => $this->listRow($list))->values(),
            'settings' => [
                'connected' => $this->resend->configured(),
                'webhook' => trim((string) ModuleConfig::get('email_campaigns.webhook_secret')) !== '',
                'from_name' => ModuleConfig::get('email_campaigns.from_name'),
                'from_email' => ModuleConfig::get('email_campaigns.from_email'),
                'reply_to' => ModuleConfig::get('email_campaigns.reply_to'),
                'source' => $source ? [
                    'noun' => $source->groupNoun(),
                    'options' => $source->options(),
                ] : null,
                'merge_tags' => EmailCampaignRenderer::TAGS,
                'max_list_size' => (int) ModuleConfig::get('email_campaigns.max_list_size', 20000),
            ],
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* campaigns                                                              */
    /* ---------------------------------------------------------------------- */

    public function show(int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->with(['list', 'user'])->findOrFail($id);

        return response()->json(['campaign' => $this->campaignDetail($campaign)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'list_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
        ]);

        $content = self::starterBlocks();

        if (! empty($data['template_id'])) {
            $template = EmailTemplate::query()->find((int) $data['template_id']);
            $content = $template ? (array) $template->content : $content;
        }

        $campaign = EmailCampaign::create([
            'name' => $data['name'],
            'list_id' => $this->existingList($data['list_id'] ?? null),
            'content' => $content,
            'from_name' => ModuleConfig::get('email_campaigns.from_name'),
            'from_email' => ModuleConfig::get('email_campaigns.from_email'),
            'reply_to' => ModuleConfig::get('email_campaigns.reply_to'),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['campaign' => $this->campaignDetail($campaign->fresh(['list', 'user']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->findOrFail($id);

        if (! $campaign->isEditable()) {
            return $this->refuse('This campaign has been ' . $campaign->status . ', so it can no longer be changed. Duplicate it to send something similar.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from_email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'reply_to' => ['sometimes', 'nullable', 'email', 'max:191'],
            'list_id' => ['sometimes', 'nullable', 'integer'],
            'content' => ['sometimes', 'array', 'max:100'],
            'content.*.type' => ['required_with:content', 'string', 'in:heading,text,image,button,divider,spacer,columns'],
        ]);

        if (array_key_exists('list_id', $data)) {
            $data['list_id'] = $this->existingList($data['list_id']);
        }

        $campaign->fill($data);

        // An edit to a cancelled or failed campaign starts it over as a draft.
        if ($campaign->isDirty() && $campaign->status !== EmailCampaign::STATUS_DRAFT) {
            $campaign->status = EmailCampaign::STATUS_DRAFT;
            $campaign->error = null;
        }

        $campaign->save();

        return response()->json(['campaign' => $this->campaignDetail($campaign->fresh(['list', 'user']))]);
    }

    /** `POST {base}/campaigns/{id}/preview` — the email as a reader would see it, sample filled. */
    public function preview(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->findOrFail($id);
        $blocks = $request->has('content') ? (array) $request->input('content', []) : (array) $campaign->content;

        $sample = $this->sample($request);
        $subject = (string) $request->input('subject', $campaign->subject);

        return response()->json([
            'html' => $this->renderer->render($blocks, [
                'subject' => $subject,
                'preview_text' => $request->input('preview_text', $campaign->preview_text),
            ], $sample),
            // As the recipient's inbox will show it, tags filled.
            'subject' => $this->renderer->line($subject, $sample),
        ]);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->findOrFail($id);
        $data = $request->validate(['to' => ['nullable', 'email', 'max:191']]);
        $to = $data['to'] ?? (string) ($request->user()->email ?? '');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->refuse('Say which address the test should go to.');
        }

        $result = $this->sender->test($campaign, $to, $this->sample($request));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->with('list')->findOrFail($id);
        $data = $request->validate(['scheduled_at' => ['nullable', 'date']]);
        $when = ! empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;

        $result = $this->sender->send($campaign, $when);

        return response()->json($result + ['campaign' => $this->campaignDetail($campaign->fresh(['list', 'user']))], $result['ok'] ? 200 : 422);
    }

    public function cancel(int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->findOrFail($id);
        $result = $this->sender->cancel($campaign);

        return response()->json($result + ['campaign' => $this->campaignDetail($campaign->fresh(['list', 'user']))], $result['ok'] ? 200 : 422);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $original = EmailCampaign::query()->findOrFail($id);

        $copy = EmailCampaign::create([
            'name' => mb_substr($original->name . ' (copy)', 0, 191),
            'subject' => $original->subject,
            'preview_text' => $original->preview_text,
            'from_name' => $original->from_name,
            'from_email' => $original->from_email,
            'reply_to' => $original->reply_to,
            'list_id' => $this->existingList($original->list_id),
            'content' => $original->content,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['campaign' => $this->campaignDetail($copy->fresh(['list', 'user']))], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = EmailCampaign::query()->findOrFail($id);

        if (in_array($campaign->status, [EmailCampaign::STATUS_SCHEDULED, EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SENT], true)) {
            return $this->refuse('A campaign that has been sent or scheduled is the record of what went out, so it cannot be deleted. Cancel a scheduled one first.');
        }

        $campaign->delete();

        return response()->json(['ok' => true]);
    }

    /* ---------------------------------------------------------------------- */
    /* lists                                                                  */
    /* ---------------------------------------------------------------------- */

    public function showList(Request $request, int $id): JsonResponse
    {
        $list = EmailList::query()->findOrFail($id);
        $search = trim((string) $request->query('search', ''));

        $members = $list->members()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $q->where(fn ($w) => $w->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('company', 'like', $like));
            })
            ->where('status', '!=', EmailListMember::STATUS_REMOVING)
            ->orderBy('email')
            ->limit(500)
            ->get()
            ->map(fn (EmailListMember $member) => [
                'id' => $member->id,
                'email' => $member->email,
                'name' => trim($member->first_name . ' ' . $member->last_name) ?: null,
                'company' => $member->company,
                'status' => $member->status,
                'error' => $member->error,
                'unsubscribed_at' => $member->unsubscribed_at?->toIso8601String(),
            ]);

        $source = $this->lists->source();
        $groups = (array) (($list->filters ?? [])['groups'] ?? []);

        return response()->json([
            'list' => $this->listRow($list) + [
                'filters' => $list->filters,
                'group_labels' => $source && $groups ? $source->groupLabels($groups) : [],
            ],
            'members' => $members,
        ]);
    }

    /** `GET {base}/groups?search=` — the contact source's groups (clients). */
    public function groups(Request $request): JsonResponse
    {
        $source = $this->lists->source();

        return response()->json([
            'groups' => $source ? $source->groups(mb_substr((string) $request->query('search', ''), 0, 100), 25) : [],
        ]);
    }

    public function storeList(Request $request): JsonResponse
    {
        $data = $this->validateList($request, true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $list = EmailList::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'kind' => $data['kind'],
            'filters' => $data['kind'] === EmailList::KIND_CRM ? $this->filters($request) : null,
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        $counts = $list->kind === EmailList::KIND_CRM
            ? $this->lists->refresh($list)
            : $this->lists->import($list, (array) $request->input('rows', []));

        return response()->json(['list' => $this->listRow($list->fresh()), 'counts' => $counts, 'message' => $this->countsSentence($counts)], 201);
    }

    public function updateList(Request $request, int $id): JsonResponse
    {
        $list = EmailList::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $list->fill($data);

        if ($list->kind === EmailList::KIND_CRM && ($request->has('groups') || $request->has('options'))) {
            $list->filters = $this->filters($request);
        }

        $list->save();

        $counts = $list->kind === EmailList::KIND_CRM
            ? $this->lists->refresh($list)
            : ($request->has('rows') ? $this->lists->import($list, (array) $request->input('rows', [])) : null);

        return response()->json([
            'list' => $this->listRow($list->fresh()),
            'counts' => $counts,
            'message' => $counts ? $this->countsSentence($counts) : 'Saved.',
        ]);
    }

    public function refreshList(int $id): JsonResponse
    {
        $list = EmailList::query()->findOrFail($id);

        if ($list->kind !== EmailList::KIND_CRM) {
            return $this->refuse('An imported list is refreshed by importing a new file.');
        }

        $counts = $this->lists->refresh($list);

        return response()->json(['list' => $this->listRow($list->fresh()), 'counts' => $counts, 'message' => $this->countsSentence($counts)]);
    }

    public function destroyList(int $id): JsonResponse
    {
        $list = EmailList::query()->findOrFail($id);

        $inUse = EmailCampaign::query()
            ->where('list_id', $list->id)
            ->whereIn('status', [EmailCampaign::STATUS_SCHEDULED, EmailCampaign::STATUS_SENDING])
            ->exists();

        if ($inUse) {
            return $this->refuse('A scheduled or sending campaign is going to this list. Cancel it first.');
        }

        if ($list->resend_segment_id) {
            // The contacts stay in Resend (and so do their unsubscribes); only
            // the segment goes. A failure here leaves an unused segment, which
            // is harmless, so it does not stop the delete.
            $this->resend->deleteSegment($list->resend_segment_id);
        }

        $list->members()->delete();
        $list->delete();

        return response()->json(['ok' => true]);
    }

    /* ---------------------------------------------------------------------- */
    /* templates and images                                                   */
    /* ---------------------------------------------------------------------- */

    public function templates(): JsonResponse
    {
        return response()->json([
            'templates' => EmailTemplate::query()->orderBy('name')->get()->map(fn (EmailTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'blocks' => count((array) $template->content),
                'updated_at' => $template->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'content' => ['required', 'array', 'max:100'],
            'content.*.type' => ['required', 'string', 'in:heading,text,image,button,divider,spacer,columns'],
        ]);

        $template = EmailTemplate::create($data + ['user_id' => $request->user()?->getAuthIdentifier()]);

        return response()->json(['template' => ['id' => $template->id, 'name' => $template->name], 'message' => 'Saved as a template.'], 201);
    }

    public function destroyTemplate(int $id): JsonResponse
    {
        EmailTemplate::query()->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * `POST {base}/images` — an image for a campaign, on a PUBLIC disk, because
     * a stranger's mail client has to be able to fetch it.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $max = (int) ModuleConfig::get('email_campaigns.image_max_kb', 5120);
        $request->validate(['image' => ['required', 'file', 'max:' . $max]]);

        $file = $request->file('image');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? null;

        // Sniffed, never the name or the browser's claim. No SVG: it is a
        // document, and most mail clients will not draw one anyway.
        if ($ext === null) {
            return $this->refuse('Use a JPEG, PNG, GIF or WebP image.');
        }

        $disk = (string) ModuleConfig::get('email_campaigns.image_disk', 'public');
        $path = trim((string) ModuleConfig::get('email_campaigns.image_directory', 'email-campaigns'), '/')
            . '/' . now()->format('Y/m') . '/' . Str::random(24) . '.' . $ext;

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()), 'public');

        $url = Storage::disk($disk)->url($path);

        if (! preg_match('#^https?://#i', $url)) {
            $url = rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        return response()->json(['url' => $url], 201);
    }

    /* ---------------------------------------------------------------------- */
    /* payloads                                                               */
    /* ---------------------------------------------------------------------- */

    private function campaignRow(EmailCampaign $campaign): array
    {
        $report = in_array($campaign->status, [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SENT], true)
            ? $this->reports->for($campaign)
            : null;

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'status' => $campaign->status,
            'list' => $campaign->list ? ['id' => $campaign->list->id, 'name' => $campaign->list->name] : null,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'recipients' => $campaign->recipient_count,
            'open_rate' => $report['open_rate'] ?? null,
            'click_rate' => $report['click_rate'] ?? null,
            'user' => $campaign->user?->name,
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
    }

    private function campaignDetail(EmailCampaign $campaign): array
    {
        $sentish = in_array($campaign->status, [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SENT], true);

        return $this->campaignRow($campaign) + [
            'preview_text' => $campaign->preview_text,
            'from_name' => $campaign->from_name,
            'from_email' => $campaign->from_email,
            'reply_to' => $campaign->reply_to,
            'list_id' => $campaign->list_id,
            'content' => (array) $campaign->content,
            'editable' => $campaign->isEditable(),
            'error' => $campaign->error,
            'blockers' => $campaign->isEditable() ? $this->sender->blockers($campaign) : [],
            'report' => $sentish ? $this->reports->for($campaign) : null,
        ];
    }

    private function listRow(EmailList $list): array
    {
        $counts = $list->members()
            ->selectRaw('status, COUNT(*) as n, SUM(CASE WHEN unsubscribed_at IS NULL THEN 0 ELSE 1 END) as unsub')
            ->groupBy('status')
            ->get();

        $by = fn (string $status) => (int) optional($counts->firstWhere('status', $status))->n;
        $unsubscribed = (int) $counts->sum('unsub');

        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'kind' => $list->kind,
            'members' => $by(EmailListMember::STATUS_SYNCED) + $by(EmailListMember::STATUS_PENDING) + $by(EmailListMember::STATUS_FAILED),
            'pending' => $by(EmailListMember::STATUS_PENDING) + $by(EmailListMember::STATUS_REMOVING),
            'failed' => $by(EmailListMember::STATUS_FAILED),
            'unsubscribed' => $unsubscribed,
            'syncing' => $list->isSyncing(),
            'synced_at' => $list->synced_at?->toIso8601String(),
            'sync_error' => $list->sync_error,
        ];
    }

    /** @return array<string, string> the merge-tag sample for previews and tests */
    private function sample(Request $request): array
    {
        $user = $request->user();
        $first = (string) ($user->firstname ?? '') ?: (string) Str::before((string) ($user->name ?? 'Alex'), ' ');
        $last = (string) ($user->surname ?? '') ?: (string) Str::after((string) ($user->name ?? ''), ' ');

        return [
            'first_name' => $first ?: 'Alex',
            'last_name' => $last,
            'name' => trim($first . ' ' . $last) ?: 'Alex',
            'company' => (string) (ModuleConfig::get('email_campaigns.brand.company') ?? config('app.name')),
            'email' => (string) ($user->email ?? 'alex@example.com'),
        ];
    }

    /** @return array{groups: array<int, string>, options: array<string, bool>} */
    private function filters(Request $request): array
    {
        $source = $this->lists->source();
        $known = $source ? array_keys($source->options()) : [];

        return [
            'groups' => collect((array) $request->input('groups', []))->map(fn ($id) => mb_substr((string) $id, 0, 64))->filter()->unique()->values()->all(),
            'options' => collect((array) $request->input('options', []))->only($known)->map(fn ($on) => (bool) $on)->all(),
        ];
    }

    /** @return array<string, mixed>|JsonResponse */
    private function validateList(Request $request, bool $creating)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', 'in:crm,import'],
            'rows' => ['required_if:kind,import', 'array'],
        ]);

        if ($data['kind'] === EmailList::KIND_CRM && $this->lists->source() === null) {
            return $this->refuse('This application offers no contacts to build a list from. Import a CSV instead.');
        }

        $max = (int) ModuleConfig::get('email_campaigns.max_list_size', 20000);

        if ($data['kind'] === EmailList::KIND_IMPORT && count((array) $request->input('rows', [])) > $max) {
            return $this->refuse('That file has more than ' . number_format($max) . ' rows. Split it into smaller lists.');
        }

        return $data;
    }

    private function existingList($id): ?int
    {
        return $id ? EmailList::query()->whereKey((int) $id)->value('id') : null;
    }

    private function countsSentence(array $counts): string
    {
        $parts = [$counts['total'] . ' ' . ($counts['total'] === 1 ? 'person' : 'people') . ' on the list'];

        if (($counts['added'] ?? 0) > 0) {
            $parts[] = $counts['added'] . ' added';
        }

        if (($counts['removed'] ?? 0) > 0) {
            $parts[] = $counts['removed'] . ' taken off';
        }

        if (! empty($counts['rejected'])) {
            $parts[] = count($counts['rejected']) . ' ' . (count($counts['rejected']) === 1 ? 'row' : 'rows') . ' skipped';
        }

        return implode(', ', $parts) . '. It is copied to Resend in the background over the next minute or two.';
    }

    /** @return array<int, array<string, mixed>> */
    public static function starterBlocks(): array
    {
        return [
            ['type' => 'heading', 'level' => 1, 'text' => 'Hello {first_name}'],
            ['type' => 'text', 'html' => '<p>Write your message here.</p>'],
            ['type' => 'button', 'label' => 'Find out more', 'url' => '', 'align' => 'left'],
        ];
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message, 'error' => $message], 422);
    }
}
