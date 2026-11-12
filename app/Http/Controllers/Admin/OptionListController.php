<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OptionList;
use App\Models\OptionListItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared lists, like Facilities, that form questions pick from. */
class OptionListController extends Controller
{
    public function index(Request $request)
    {
        $lists = OptionList::with('items.addedBy')->withCount('items')->orderBy('name')->get();
        $current = $lists->firstWhere('id', (int) $request->query('list')) ?? $lists->first();

        return view('admin.lists', ['lists' => $lists, 'current' => $current]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100|unique:option_lists,name']);
        $list = OptionList::create(['name' => trim($data['name']), 'allow_add' => true]);

        return redirect()->route('admin.lists', ['list' => $list->id])->with('status', "Created the {$list->name} list.");
    }

    public function update(Request $request, OptionList $list)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('option_lists', 'name')->ignore($list->id)],
            'allow_add' => 'boolean',
        ]);
        $list->update(['name' => trim($data['name']), 'allow_add' => $request->boolean('allow_add')]);

        return back()->with('status', "Saved the {$list->name} list.");
    }

    public function destroy(OptionList $list)
    {
        if (\App\Models\FormField::where('option_list_id', $list->id)->exists()) {
            return back()->with('error', "A form question uses the {$list->name} list, so it can't be deleted.");
        }
        $list->delete();

        return redirect()->route('admin.lists')->with('status', "Deleted the {$list->name} list.");
    }

    /** Adds one or more entries, one per line; ones already on the list are skipped. */
    public function storeItems(Request $request, OptionList $list)
    {
        $data = $request->validate(['names' => 'required|string|max:20000']);
        $added = [];
        foreach (preg_split('/\r\n|\r|\n/', $data['names']) as $name) {
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '' || mb_strlen($name) > 200 || $list->find($name)) {
                continue;
            }
            $list->items()->create(['name' => $name, 'added_by' => $request->user()->id]);
            $added[] = $name;
        }
        audit_note($added ? 'Added to '.$list->name.': '.implode(', ', $added).'.' : "Nothing new to add to {$list->name}.");

        return back()->with('status', $added ? 'Added '.count($added).' to '.$list->name.'.' : 'Those are all on the list already.');
    }

    public function updateItem(Request $request, OptionListItem $item)
    {
        $data = $request->validate(['name' => 'required|string|max:200']);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']));
        $same = $item->list->find($name);
        if ($same && $same->id !== $item->id) {
            return back()->with('error', "\"{$same->name}\" is already on the list.");
        }
        $item->update(['name' => $name]);

        return back()->with('status', "Saved \"{$item->name}\". Past submissions keep the old name.");
    }

    public function toggleItem(OptionListItem $item)
    {
        $item->update(['active' => ! $item->active]);

        return back()->with('status', $item->active ? "\"{$item->name}\" is offered again." : "\"{$item->name}\" is hidden. It stays on past submissions.");
    }

    public function destroyItem(OptionListItem $item)
    {
        $item->delete();

        return back()->with('status', "Removed \"{$item->name}\". Past submissions keep it.");
    }
}
