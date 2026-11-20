<?php

namespace App\Http\Controllers;

use App\Services\CandidatureAi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AISettingsController extends Controller
{
    public function edit(Request $request, CandidatureAi $ai): Response
    {
        return Inertia::render('settings/ai', [
            'hasPersonalKey' => filled($request->user()->getRawOriginal('openai_key')),
            'hasEnvironmentKey' => $request->user()->isAdmin() && filled(config('cvgenerator.openai_key')),
            'ollamaEnabled' => $ai->available($request->user(), 'ollama'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'openai_key' => ['required', 'string', 'min:18', 'max:512', 'regex:/^sk-[A-Za-z0-9_-]+$/D'],
        ]);
        $request->user()->forceFill(['openai_key' => $data['openai_key']])->save();

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['openai_key' => null])->save();

        return back();
    }
}
