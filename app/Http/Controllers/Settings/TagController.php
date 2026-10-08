<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\TagRequest;
use App\Models\Tag;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tags: add, rename, recolour, delete (the confirm dialog says how many leads lose the tag).
 */
class TagController extends Controller
{
    public function store(TagRequest $request): RedirectResponse
    {
        $tag = Tag::create($request->tagData());
        Audit::log('tag.created', $tag, ['name' => $tag->name]);

        return redirect()->route('settings.tags')->with('status', "Tag “{$tag->name}” created");
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        $tag->fill($request->tagData());
        $changes = collect($tag->getDirty())->map(fn ($to, $field) => ['from' => $tag->getOriginal($field), 'to' => $to])->all();
        $tag->save();

        if ($changes !== []) {
            Audit::log('tag.updated', $tag, ['changes' => $changes]);
        }

        return redirect()->route('settings.tags')->with('status', "Tag “{$tag->name}” saved");
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $name = $tag->name;
        $removed = DB::transaction(function () use ($tag) {
            $count = DB::table('lead_tag')->where('tag_id', $tag->id)->delete();
            $tag->delete();

            return $count;
        });
        Audit::log('tag.deleted', null, ['tag_id' => $tag->id, 'name' => $name, 'leads_untagged' => $removed]);

        return redirect()->route('settings.tags')
            ->with('status', "Tag “{$name}” deleted".($removed ? " and removed from {$removed} ".str('lead')->plural($removed) : ''));
    }
}
