<?php

namespace Visnsstudio\VisnsPackages\Controllers;

use Illuminate\Http\Request;
use Visnsstudio\VisnsPackages\Models\SmsTemplate;
use Visnsstudio\VisnsPackages\Support\ModuleConfig;
use Visnsstudio\VisnsPackages\Support\SmsAccess;

/**
 * Canned SMS bodies.
 *
 * Reading is part of composing a message, so anyone with access gets the list.
 * Writing is administrative - a template is a thing the whole practice sends in
 * the practice's name - so it needs manage.
 *
 * Deliberately no placeholder engine. A templated SMS gets edited before it is
 * sent anyway, and a merge field that silently resolved to an empty string in a
 * client's text would be worse than no template at all.
 */
class SmsTemplateController extends \App\Http\Controllers\Controller
{
    /**
     * Every active template, in the order the practice arranged them.
     *
     * Administrators see the inactive ones too, because otherwise a template
     * they just deactivated would vanish from the screen they deactivated it on.
     */
    public function index(Request $request)
    {
        $manages = SmsAccess::manages($request->user());

        $query = SmsTemplate::query();

        if (! $manages || ! $request->boolean('include_inactive')) {
            $query->where('active', true);
        }

        $templates = $query
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (SmsTemplate $t) => $this->row($t))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireManage($request);

        $data = $this->validated($request);

        $template = SmsTemplate::create([
            'name' => $data['name'],
            'body' => $data['body'],
            'active' => $data['active'] ?? true,
            'sort' => $data['sort'] ?? 0,
        ]);

        return response()->json($this->row($template), 201);
    }

    public function update(Request $request, $id)
    {
        $this->requireManage($request);

        $template = SmsTemplate::find($id);

        if ($template === null) {
            abort(404, 'Not found.');
        }

        $data = $this->validated($request);

        foreach (['name', 'body', 'active', 'sort'] as $field) {
            if (array_key_exists($field, $data)) {
                $template->{$field} = $data[$field];
            }
        }

        $template->save();

        return response()->json($this->row($template));
    }

    /**
     * Soft delete: a retired template is worth being able to bring back, and a
     * hard delete from an admin screen is the mistake that gets noticed a week
     * later.
     */
    public function destroy(Request $request, $id)
    {
        $this->requireManage($request);

        $template = SmsTemplate::find($id);

        if ($template === null) {
            abort(404, 'Not found.');
        }

        $template->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SmsTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'body' => $template->body,
            'active' => (bool) $template->active,
            'sort' => (int) $template->sort,
        ];
    }

    private function requireManage(Request $request): void
    {
        if (! SmsAccess::manages($request->user())) {
            abort(403, 'Only a messaging administrator can change templates.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $max = (int) ModuleConfig::get('messaging.max_body_length', 1600);

        return $request->validate([
            'name' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:191'],
            'body' => [$request->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:' . ($max > 0 ? $max : 1600)],
            'active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer'],
        ]);
    }
}
