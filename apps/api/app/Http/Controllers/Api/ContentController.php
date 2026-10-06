<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\ContentItem;
use App\Services\App\PushService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Education articles and FAQ for the app, in English and Tamil.
 * Tamil text reaches participants only after a reviewer ticks "Tamil reviewed"; until then they see English.
 */
class ContentController extends Controller
{
    public function index()
    {
        return response()->json(['items' => ContentItem::orderBy('type')->orderBy('sort')->orderBy('id')->get()]);
    }

    private function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', ContentItem::TYPES)],
            'category' => ['nullable', 'string', 'max:60'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'title_en' => ['required', 'string', 'max:200'],
            'body_en' => ['required', 'string', 'max:20000'],
            'title_ta' => ['nullable', 'string', 'max:200'],
            'body_ta' => ['nullable', 'string', 'max:20000'],
            'ta_reviewed' => ['boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $c = new ContentItem(['status' => 'draft', 'updated_by' => $request->user()->id]);
        $this->fill($c, $data, $request);
        Audit::log('content_created', ['entity_type' => 'content', 'entity_id' => $c->id, 'new_value' => $c->title_en]);

        return response()->json($c, 201);
    }

    public function update(Request $request, ContentItem $content)
    {
        $data = $request->validate($this->rules());
        $old = $content->only(array_keys($data));
        $this->fill($content, $data, $request);
        Audit::diff('content_changed', $old, $content->only(array_keys($data)), ['entity_type' => 'content', 'entity_id' => $content->id]);

        return response()->json($content);
    }

    private function fill(ContentItem $c, array $data, Request $request): void
    {
        $taChanged = ($data['title_ta'] ?? null) !== $c->title_ta || ($data['body_ta'] ?? null) !== $c->body_ta;
        // The editor unticks "Tamil reviewed" when Tamil text is edited, so a changed text needs a new tick.
        $reviewed = (bool) ($data['ta_reviewed'] ?? false) && ! empty($data['title_ta']) && ! empty($data['body_ta']);
        $c->fill(collect($data)->except('ta_reviewed')->all());
        $c->forceFill(['ta_reviewed' => $reviewed, 'ta_reviewed_by' => $reviewed ? ($c->ta_reviewed && ! $taChanged ? $c->ta_reviewed_by : $request->user()->id) : null,
            'updated_by' => $request->user()->id, 'sort' => $data['sort'] ?? 0])->save();
    }

    public function publish(Request $request, ContentItem $content)
    {
        $status = $request->validate(['status' => ['required', 'in:draft,published,archived']])['status'];
        $old = $content->status;
        $content->forceFill(['status' => $status, 'published_at' => $status === 'published' ? ($content->published_at ?? now()) : $content->published_at,
            'updated_by' => $request->user()->id])->save();
        Audit::log('content_status', ['entity_type' => 'content', 'entity_id' => $content->id, 'field' => 'status', 'old_value' => $old, 'new_value' => $status]);
        if ($status === 'published' && $old !== 'published' && $request->boolean('notify')) {
            $users = AppUser::with('devices')->where('status', 'active')->get();
            dispatch(fn () => app(PushService::class)->send($users, 'content_new'))->afterResponse();
        }

        return response()->json($content);
    }
}
