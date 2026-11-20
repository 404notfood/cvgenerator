<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatureAi;
use App\Services\CandidatureDocuments;
use App\Services\CvPageOverflow;
use App\Services\FranceTravailOffers;
use App\Services\OfferLocation;
use App\Services\ResumeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CandidatureController extends Controller
{
    public function index(Request $request, CandidatureAi $ai, OfferLocation $locations, FranceTravailOffers $franceTravail): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'offer_from' => ['nullable', 'uuid'],
            'view' => ['nullable', Rule::in(['tracking', 'offers', 'resumes', 'discovery'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $offerFrom = $filters['offer_from'] ?? null;
        $view = $filters['view'] ?? 'tracking';
        $search = $filters['q'] ?? '';
        $counts = array_fill_keys(array_keys(Candidature::TRACKING_STAGES), 0);
        $groups = $user->candidatures()->toBase()->selectRaw('tracking_stage, COUNT(*) AS total, COUNT(sent_at) AS sent_total')
            ->groupBy('tracking_stage')->get();
        foreach ($groups as $group) {
            if ($group->tracking_stage === null) {
                $counts['sent'] += (int) $group->sent_total;
                $counts['interested'] += (int) $group->total - (int) $group->sent_total;
            } else {
                $counts[$group->tracking_stage] += (int) $group->total;
            }
        }
        $query = $user->candidatures()->latest();
        if ($view === 'offers') {
            $query->whereNotNull('offer_text');
        } elseif ($view === 'resumes') {
            $query->whereNotNull('result');
        }
        if ($search !== '') {
            $query->where(fn ($query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('company', 'like', '%'.$search.'%'));
        }
        $list = $query->paginate(30, ['id', 'title', 'company', 'status', 'sent_at', 'tracking_stage', 'created_at', ...($view === 'offers' ? ['source_url', 'offer_text'] : [])])
            ->appends(array_filter(['view' => $view, 'q' => $search]))
            ->through(fn (Candidature $entry): array => [
                ...$entry->only(['id', 'title', 'company', 'status', 'created_at']),
                'tracking_stage' => $entry->trackingStage(),
                'source_url' => $view === 'offers' ? $entry->source_url : null,
                'location' => $view === 'offers' ? $locations->locate($entry->offer_text) : null,
            ]);

        return Inertia::render('dashboard', [
            'candidatures' => $list,
            'canGenerate' => $user->canGenerate(),
            'providers' => $this->providers($user, $ai),
            'hasPreviousResume' => array_sum($counts) > 0,
            'trackingCounts' => $counts,
            'trackingStages' => Candidature::TRACKING_STAGES,
            'view' => $view,
            'franceTravailConfigured' => $user->canGenerate() && $franceTravail->configured(),
            'search' => $search,
            'initialOfferUrl' => $offerFrom && $user->canGenerate()
                ? $user->candidatures()->whereKey($offerFrom)->first(['source_url'])?->source_url
                : null,
        ]);
    }

    public function searchOffers(Request $request, FranceTravailOffers $franceTravail, OfferLocation $locations): JsonResponse
    {
        $data = $request->validate([
            'keywords' => ['required', 'string', 'min:2', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'radius' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contract' => ['nullable', Rule::in(['CDI', 'CDD', 'MIS'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (! $franceTravail->configured()) {
            return response()->json(['message' => 'Les identifiants France Travail sont à renseigner côté serveur.'], 503);
        }
        $city = $data['city'] ?? null;
        $commune = $city !== null ? $locations->communeCode($city) : null;
        if ($city !== null && $commune === null) {
            return response()->json(['message' => 'Commune inconnue ou ambiguë. Indique le nom et le code postal, par exemple Laval 53000.'], 422);
        }

        try {
            return response()->json($franceTravail->search($data['keywords'], $commune, (int) ($data['radius'] ?? 30), $data['contract'] ?? null, (int) ($data['page'] ?? 1)));
        } catch (RuntimeException $exception) {
            [$message, $status] = match ($exception->getMessage()) {
                'france_travail_credentials' => ['France Travail refuse l’identifiant ou le secret de l’application (invalid_client). Vérifie que les deux valeurs du fichier .env viennent de la même application sur francetravail.io.', 503],
                'france_travail_authentication' => ['France Travail a refusé la demande de jeton. Vérifie que l’application déclarée sur francetravail.io est active.', 503],
                'france_travail_access' => ['France Travail a refusé l’accès. Vérifie que cette application est habilitée à utiliser l’API Offres d’emploi.', 503],
                'france_travail_quota' => ['Le quota de France Travail est atteint. Réessaie plus tard.', 429],
                'france_travail_unavailable' => ['France Travail est temporairement indisponible. Réessaie dans quelques minutes.', 503],
                default => throw $exception,
            };

            return response()->json(['message' => $message], $status);
        }
    }

    public function store(Request $request, ResumeText $resume, CandidatureAi $ai): RedirectResponse
    {
        $data = $request->validate([
            'source_url' => ['required', 'url:http,https', 'max:2048'],
            'resume_file' => ['nullable', 'file', 'max:4096', 'extensions:pdf,docx,txt'],
            'resume_text' => ['nullable', 'string', 'min:60', 'max:30000'],
            'reuse_resume' => ['nullable', 'boolean'],
            'provider' => ['required', Rule::in(['ollama', 'openai'])],
        ]);
        $user = $request->user();
        $this->requireProvider($user, $ai, $data['provider']);

        try {
            $text = $request->hasFile('resume_file')
                ? $resume->extract($request->file('resume_file'))
                : ($data['resume_text'] ?? null);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['resume_file' => $exception->getMessage()]);
        }

        if (! $text && ($data['reuse_resume'] ?? false)) {
            $text = $user->candidatures()->latest()->first(['resume_text'])?->resume_text;
        }
        if (! filled($text)) {
            throw ValidationException::withMessages(['resume_file' => 'Ajoutez votre CV ou collez son texte.']);
        }

        $candidature = DB::transaction(function () use ($user, $data, $text): Candidature {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->candidatures()->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['provider' => 'Une candidature est déjà en préparation.']);
            }

            return $user->candidatures()->create([
                'status' => 'queued', 'source_url' => $data['source_url'],
                'resume_text' => $text, 'provider' => $data['provider'],
            ]);
        });
        PrepareCandidature::dispatch($candidature->id, 0);

        return to_route('candidatures.show', $candidature);
    }

    public function show(Request $request, Candidature $candidature, CandidatureAi $ai, CandidatureDocuments $documents, ResumeText $resume): Response
    {
        $this->own($request, $candidature);
        $result = $candidature->result;
        if ($result && ($result['assessment_version'] ?? 0) !== CandidatureDocuments::ASSESSMENT_VERSION
            && ! in_array($candidature->status, ['queued', 'processing'], true)) {
            $result = DB::transaction(function () use ($candidature, $documents, $resume): ?array {
                $current = Candidature::query()->lockForUpdate()->findOrFail($candidature->id);
                $result = $current->result;
                if ($result && ($result['assessment_version'] ?? 0) !== CandidatureDocuments::ASSESSMENT_VERSION
                    && ! in_array($current->status, ['queued', 'processing'], true)) {
                    $result = $documents->withAssessments($result, $resume);
                    $current->update(['result' => $result]);
                }

                return $result;
            });
        }

        return Inertia::render('candidatures/show', [
            'candidature' => [
                'id' => $candidature->id,
                'title' => $candidature->title,
                'company' => $candidature->company,
                'source_url' => $candidature->source_url,
                'offer_text' => $candidature->offer_text,
                'confirmed_experience' => $candidature->confirmed_experience,
                'provider' => $candidature->provider,
                'status' => $candidature->status,
                'error_code' => $candidature->error_code,
                'revision' => $candidature->revision,
                'sent_at' => $candidature->sent_at?->toIso8601String(),
                'tracking_stage' => $candidature->trackingStage(),
                'has_sent_documents' => $candidature->getRawOriginal('sent_documents') !== null,
                'result' => $result,
            ],
            'providers' => $this->providers($request->user(), $ai),
            'canGenerate' => $request->user()->canGenerate(),
            'pdfTemplates' => CandidatureDocuments::PDF_TEMPLATES,
            'trackingStages' => Candidature::TRACKING_STAGES,
        ]);
    }

    public function retry(Request $request, Candidature $candidature, CandidatureAi $ai): RedirectResponse
    {
        $this->own($request, $candidature);
        $data = $request->validate([
            'offer_text' => ['nullable', 'string', 'min:100', 'max:30000'],
            'title' => ['nullable', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:200'],
            'provider' => ['required', Rule::in(['ollama', 'openai'])],
            'cv_only' => ['sometimes', 'boolean'],
            'confirmed_experience' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);
        $user = $request->user();
        $this->requireProvider($user, $ai, $data['provider']);
        $cvOnly = (bool) ($data['cv_only'] ?? false);

        $revision = DB::transaction(function () use ($candidature, $user, $data, $cvOnly): int {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->candidatures()->whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['provider' => 'Une candidature est déjà en préparation.']);
            }
            $current = $user->candidatures()->lockForUpdate()->findOrFail($candidature->id);
            if ($cvOnly && (! $current->result || ! in_array($current->status, ['ready', 'failed'], true))) {
                throw ValidationException::withMessages(['provider' => 'Le CV doit être prêt pour être réoptimisé.']);
            }
            $nextRevision = $current->revision + 1;
            $current->update([
                'offer_text' => array_key_exists('offer_text', $data) ? $data['offer_text'] : $current->offer_text,
                'confirmed_experience' => array_key_exists('confirmed_experience', $data) ? $data['confirmed_experience'] : $current->confirmed_experience,
                'title' => $data['title'] ?? $current->title,
                'company' => $data['company'] ?? $current->company,
                'provider' => $data['provider'],
                'status' => 'queued',
                'error_code' => null,
                'revision' => $nextRevision,
            ]);

            return $nextRevision;
        });
        PrepareCandidature::dispatch($candidature->id, $revision, $cvOnly, filled($data['offer_text'] ?? null));

        return to_route('candidatures.show', $candidature);
    }

    public function update(Request $request, Candidature $candidature, CandidatureDocuments $documents, ResumeText $resume): RedirectResponse
    {
        $this->own($request, $candidature);
        $data = $request->validate([
            'cv' => ['required', 'string', 'min:60', 'max:10000'],
            'letter' => ['required', 'string', 'min:60', 'max:8000'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        $result = $candidature->result;
        abort_unless($result && ! in_array($candidature->status, ['queued', 'processing'], true), 409);
        $result['cv'] = $data['cv'];
        $result['letter'] = $data['letter'];
        $result = $documents->withAssessments($result, $resume);

        DB::transaction(function () use ($candidature, $data, $result): void {
            $current = Candidature::query()->lockForUpdate()->findOrFail($candidature->id);
            if ($current->revision !== (int) $data['revision'] || in_array($current->status, ['queued', 'processing'], true)) {
                throw ValidationException::withMessages(['cv' => 'Ce dossier a changé. Rechargez la page avant de sauvegarder.']);
            }
            $current->update(['result' => $result, 'revision' => $current->revision + 1]);
        });

        return to_route('candidatures.show', $candidature);
    }

    public function markSent(Request $request, Candidature $candidature): RedirectResponse
    {
        $this->own($request, $candidature);
        $data = $request->validate(['sent' => ['required', 'boolean']]);
        DB::transaction(function () use ($candidature, $data): void {
            $current = Candidature::query()->lockForUpdate()->findOrFail($candidature->id);
            abort_unless($current->status === 'ready' && $current->result, 409);
            if ($data['sent'] && $current->sent_at === null) {
                $current->update([
                    'sent_at' => now(),
                    'sent_documents' => Arr::only($current->result, ['cv', 'letter']),
                    'tracking_stage' => $current->trackingStage() === 'interested' ? 'sent' : $current->trackingStage(),
                ]);
            } elseif (! $data['sent'] && $current->sent_at !== null) {
                $current->update([
                    'sent_at' => null,
                    'tracking_stage' => $current->trackingStage() === 'sent' ? 'interested' : $current->trackingStage(),
                ]);
            }
        });

        return back();
    }

    public function move(Request $request, Candidature $candidature): RedirectResponse
    {
        $this->own($request, $candidature);
        $stage = $request->validate(['stage' => ['required', Rule::in(array_keys(Candidature::TRACKING_STAGES))]])['stage'];
        DB::transaction(function () use ($candidature, $stage): void {
            $current = Candidature::query()->lockForUpdate()->findOrFail($candidature->id);
            $changes = ['tracking_stage' => $stage];
            if ($stage === 'sent' && $current->sent_at === null && $current->status === 'ready' && $current->result) {
                $changes['sent_at'] = now();
                $changes['sent_documents'] = Arr::only($current->result, ['cv', 'letter']);
            }
            $current->update($changes);
        });

        return back();
    }

    public function download(Request $request, Candidature $candidature, string $document, string $format, CandidatureDocuments $documents): HttpResponse
    {
        $this->own($request, $candidature);
        $data = $request->boolean('sent') ? $candidature->sent_documents : $candidature->result;
        abort_unless(in_array($document, ['cv', 'letter'], true) && in_array($format, ['pdf', 'docx'], true) && $data, 404);
        $template = $request->query('template', CandidatureDocuments::DEFAULT_PDF_TEMPLATE);
        if ($document !== 'cv' || $format !== 'pdf') {
            $template = CandidatureDocuments::DEFAULT_PDF_TEMPLATE;
        }
        abort_unless(is_string($template) && isset(CandidatureDocuments::PDF_TEMPLATES[$template]), 404);

        try {
            if ($format === 'pdf' && $request->boolean('preview') && $request->boolean('embedded')) {
                return response($documents->preview($data[$document], $document, $template))->header('Content-Type', 'text/html; charset=UTF-8');
            }
            $file = $documents->export($data[$document], $document, $format, $template);
        } catch (CvPageOverflow $exception) {
            abort(422, $exception->getMessage());
        }
        $name = ($document === 'cv' ? 'cv' : 'lettre').'-'.(Str::slug($candidature->company ?: 'candidature') ?: 'candidature');
        if ($template !== CandidatureDocuments::DEFAULT_PDF_TEMPLATE) {
            $name .= '-'.$template;
        }
        $disposition = $format === 'pdf' && $request->boolean('preview') ? 'inline' : 'attachment';

        return response($file, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => $disposition.'; filename="'.$name.'.'.$format.'"',
        ]);
    }

    public function destroy(Request $request, Candidature $candidature): RedirectResponse
    {
        $this->own($request, $candidature);
        $candidature->delete();

        return to_route('dashboard');
    }

    private function own(Request $request, Candidature $candidature): void
    {
        abort_unless($candidature->user_id === $request->user()->id, 404);
    }

    /** @return array{ollama: bool, openai: bool} */
    private function providers(User $user, CandidatureAi $ai): array
    {
        return ['ollama' => $ai->available($user, 'ollama'), 'openai' => $ai->available($user, 'openai')];
    }

    private function requireProvider(User $user, CandidatureAi $ai, string $provider): void
    {
        if (! $ai->available($user, $provider)) {
            throw ValidationException::withMessages(['provider' => 'Ce moteur n’est pas configuré pour votre compte.']);
        }
    }
}
