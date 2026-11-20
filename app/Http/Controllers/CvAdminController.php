<?php

namespace App\Http\Controllers;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CvAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '';

        return Inertia::render('admin', [
            'members' => User::query()
                ->when($search, fn ($query) => $query->where(fn ($where) => $where->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
                ->latest()->paginate(15, ['id', 'name', 'email', 'role', 'email_verified_at'])->withQueryString(),
            'search' => $search,
            'stats' => [
                'users' => User::query()->count(),
                'candidatures' => Candidature::query()->count(),
                'ready' => Candidature::query()->where('status', 'ready')->count(),
            ],
            'seo' => DB::table('site_settings')->pluck('value', 'name')->all(),
            'indexable' => (bool) config('cvgenerator.indexable'),
            'hasEnvironmentKey' => filled(config('cvgenerator.openai_key')),
        ]);
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403);
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'user', 'guest'])]]);
        DB::transaction(function () use ($request, $user, $data): void {
            $accounts = User::query()->whereKey([$request->user()->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($accounts->get($request->user()->id)?->isAdmin(), 403);
            $target = $accounts->get($user->id);
            abort_if($target === null, 404);
            $target->forceFill(['role' => $data['role']])->save();
        });

        return back();
    }

    public function seo(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:75', 'not_regex:/[\r\n]/'],
            'description' => ['nullable', 'string', 'max:190', 'not_regex:/[\r\n]/'],
            'robots_notes' => ['nullable', 'string', 'max:1000', 'regex:~\A(?:[ \t]*(?:#[^\r\n]*|Disallow: /[^\r\n]*)?(?:\R|\z))*\z~u'],
            'llms' => ['nullable', 'string', 'max:12000'],
        ]);

        $settings = [];
        foreach ($data as $name => $value) {
            $settings[] = ['name' => $name, 'value' => $value ?? ''];
        }
        DB::table('site_settings')->upsert($settings, ['name'], ['value']);

        return back();
    }
}
