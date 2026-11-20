<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatureDocuments;
use App\Services\OfferReader;
use App\Services\ResumeAdaptation;
use App\Services\ResumeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class CandidatureWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const RESUME = "Camille Test\ncamille@example.test\nDéveloppeuse web\n\nProfil\nDéveloppement web et applications métiers.\n\nExpérience professionnelle\nMigration d’un portail Laravel et mise en place de tests automatisés.\n\nCompétences\nLaravel, tests automatisés et SQL.\n\nFormation\nLicence informatique, juin 2021.";

    private const OFFER = 'Atelier Nord recrute une Développeuse web. Expérience Laravel requise pour assurer la maintenance du portail. Kubernetes est souhaité pour participer à l’évolution des outils de déploiement.';

    public function test_member_generates_and_downloads_documents_without_using_server_key(): void
    {
        config(['cvgenerator.openai_key' => 'server-test-value']);
        $member = User::factory()->create();
        $member->forceFill(['openai_key' => 'personal-test-value'])->save();

        $this->mock(OfferReader::class)->shouldReceive('read')->once()->with('https://example.org/jobs/123')->andReturn(self::OFFER);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response())]);

        $pdf = app(CandidatureDocuments::class)->export(self::RESUME, 'cv', 'pdf');
        $stored = $this->actingAs($member)->post(route('candidatures.store'), [
            ...$this->input(), 'resume_text' => null,
            'resume_file' => UploadedFile::fake()->createWithContent('cv-test.pdf', $pdf),
        ]);
        $candidature = Candidature::query()->sole();

        $stored->assertRedirect(route('candidatures.show', $candidature));
        $this->assertSame('ready', $candidature->status);
        $this->assertNotSame(self::RESUME, $candidature->getRawOriginal('resume_text'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer personal-test-value')
            && $request['store'] === false);

        $this->get(route('candidatures.show', $candidature))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('candidatures/show')
                ->where('candidature.company', 'Atelier Nord')
                ->where('candidature.result.assessments.pdf.grade', 'C')
                ->where('candidature.result.assessments.pdf.pages', 1)
                ->where('candidature.result.assessments.docx.grade', 'C')
                ->where('candidature.result.assessments.docx.coverage', 67)
                ->where('candidature.result.requirements.1.match', 'missing')
                ->missing('auth.user.openai_key'));

        foreach (['cv', 'letter'] as $document) {
            $pdf = $this->get(route('candidatures.download', [$candidature, $document, 'pdf']))->assertOk();
            $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
            $docx = $this->get(route('candidatures.download', [$candidature, $document, 'docx']))->assertOk();
            $this->assertStringStartsWith("PK\x03\x04", (string) $docx->getContent());
        }

        $this->get(route('candidatures.download', [$candidature, 'cv', 'pdf', 'preview' => 1, 'embedded' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Camille Test')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->get(route('dashboard', ['offer_from' => $candidature->id]))
            ->assertInertia(fn (Assert $page) => $page->where('initialOfferUrl', 'https://example.org/jobs/123'));

        $this->patch(route('candidatures.sent', $candidature), ['sent' => true])->assertRedirect();
        $changed = str_replace('camille@example.test', '', self::RESUME);
        $this->patch(route('candidatures.update', $candidature), [
            'cv' => $changed, 'letter' => $candidature->result['letter'], 'revision' => 0,
        ])->assertRedirect();
        $this->assertLessThan(90, $candidature->refresh()->result['assessments']['docx']['score']);
        $this->assertSame(self::RESUME, $candidature->sent_documents['cv']);
        $this->patch(route('candidatures.update', $candidature), [
            'cv' => self::RESUME, 'letter' => $candidature->result['letter'], 'revision' => 0,
        ])->assertSessionHasErrors('cv');

        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard', ['offer_from' => $candidature->id]))
            ->assertInertia(fn (Assert $page) => $page->where('initialOfferUrl', null));
        $this->get(route('candidatures.show', $candidature))->assertNotFound();
        $this->get(route('candidatures.download', [$candidature, 'cv', 'pdf']))->assertNotFound();
        $this->delete(route('candidatures.destroy', $candidature))->assertNotFound();
    }

    public function test_guests_cannot_generate_and_members_cannot_use_the_server_key(): void
    {
        config(['cvgenerator.openai_key' => 'server-test-value']);
        $member = User::factory()->create();
        Http::fake();

        $this->actingAs($member)->post(route('candidatures.store'), $this->input())->assertSessionHasErrors('provider');
        $member->forceFill(['role' => 'guest'])->save();
        $this->actingAs($member)->post(route('candidatures.store'), $this->input())->assertForbidden();

        $this->assertDatabaseCount('candidatures', 0);
        Http::assertNothingSent();
    }

    public function test_dashboard_tracks_stages_and_filters_saved_offers_and_generated_resumes(): void
    {
        $member = User::factory()->create();
        $attributes = ['source_url' => 'https://example.org/jobs/123', 'resume_text' => self::RESUME, 'provider' => 'ollama'];
        $sent = $member->candidatures()->create([
            ...$attributes, 'title' => 'Développeuse web', 'company' => 'Atelier Nord', 'status' => 'ready',
            'sent_at' => now(), 'offer_text' => self::OFFER.' Localisation : 29200 Brest, France Télétravail partiel',
            'result' => ['cv' => self::RESUME, 'letter' => 'Lettre', 'requirements' => []],
        ]);
        $pending = $member->candidatures()->create([...$attributes, 'status' => 'failed']);
        $this->actingAs($member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('trackingCounts.sent', 1)->where('trackingCounts.interested', 1));

        $this->patch(route('candidatures.move', $sent), ['stage' => 'interview'])->assertRedirect();
        $this->patch(route('candidatures.move', $pending), ['stage' => 'wrong'])->assertSessionHasErrors('stage');
        $this->assertSame('interview', $sent->refresh()->trackingStage());
        $this->assertNotNull($sent->sent_at);

        $this->get(route('dashboard', ['view' => 'offers', 'q' => 'Nord']))->assertInertia(fn (Assert $page) => $page
            ->where('view', 'offers')->where('trackingCounts.interview', 1)->has('candidatures.data', 1)
            ->where('candidatures.data.0.id', $sent->id)->where('candidatures.data.0.source_url', $attributes['source_url'])
            ->where('candidatures.data.0.location.city', 'Brest')
            ->where('candidatures.data.0.location.longitude', fn (float $longitude): bool => $longitude < -4 && $longitude > -5)
            ->where('candidatures.data.0.location.latitude', fn (float $latitude): bool => $latitude > 48 && $latitude < 49)
            ->missing('candidatures.data.0.offer_text'));
        $this->get(route('dashboard', ['view' => 'resumes']))->assertInertia(fn (Assert $page) => $page
            ->where('view', 'resumes')->has('candidatures.data', 1)->where('candidatures.data.0.id', $sent->id)
            ->missing('candidatures.data.0.result'));

        $this->actingAs(User::factory()->create())
            ->patch(route('candidatures.move', $sent), ['stage' => 'closed'])->assertNotFound();
        $this->assertSame('interview', $sent->refresh()->trackingStage());
    }

    public function test_france_travail_search_uses_server_credentials_and_maps_public_offers(): void
    {
        config(['cvgenerator.france_travail_id' => 'test-client', 'cvgenerator.france_travail_secret' => 'test-secret']);
        $member = User::factory()->create();
        Http::preventStrayRequests();
        Http::fake([
            'https://entreprise.francetravail.fr/connexion/oauth2/access_token*' => Http::response(['access_token' => 'test-token', 'expires_in' => 600]),
            'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search*' => Http::response(['resultats' => [[
                'id' => '123TEST', 'intitule' => 'Développeuse web', 'entreprise' => ['nom' => 'Atelier Nord'],
                'typeContratLibelle' => 'CDI', 'lieuTravail' => ['libelle' => '29 - BREST', 'longitude' => -4.49, 'latitude' => 48.39],
            ]]], 206, ['Content-Range' => 'offres 0-29/35']),
        ]);

        $this->actingAs($member)->getJson(route('offers.search', [
            'keywords' => 'développeuse web', 'city' => 'Brest 29200', 'radius' => 30, 'contract' => 'CDI',
        ]))->assertOk()->assertJsonPath('offers.0.location.city', 'BREST')
            ->assertJsonPath('offers.0.location.latitude', 48.39)
            ->assertJsonPath('offers.0.url', 'https://candidat.francetravail.fr/offres/recherche/detail/123TEST')
            ->assertJsonPath('next_page', 2)->assertDontSee('test-secret');

        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return $request->hasHeader('Authorization', 'Bearer test-token')
                && ($query['commune'] ?? null) === '29019'
                && ($query['distance'] ?? null) === '30'
                && ($query['typeContrat'] ?? null) === 'CDI';
        });
    }

    public function test_france_travail_search_does_not_follow_an_authentication_redirect(): void
    {
        config(['cvgenerator.france_travail_id' => 'test-client', 'cvgenerator.france_travail_secret' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake([
            'https://entreprise.francetravail.fr/connexion/oauth2/access_token*' => Http::response('', 302, ['Location' => 'https://secours-hp.francetravail.fr/accueil/']),
        ]);

        $this->actingAs(User::factory()->create())->getJson(route('offers.search', ['keywords' => 'développeuse', 'city' => '']))
            ->assertStatus(503)->assertJsonPath('message', 'France Travail est temporairement indisponible. Réessaie dans quelques minutes.');

        Http::assertSentCount(1);
    }

    public function test_france_travail_reports_invalid_client_credentials_separately_from_offer_permissions(): void
    {
        config(['cvgenerator.france_travail_id' => 'test-client', 'cvgenerator.france_travail_secret' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake([
            'https://entreprise.francetravail.fr/connexion/oauth2/access_token*' => Http::response(['error' => 'invalid_client'], 400),
        ]);

        $this->actingAs(User::factory()->create())->getJson(route('offers.search', ['keywords' => 'développeuse']))
            ->assertStatus(503)->assertSee('invalid_client')->assertDontSee('test-secret');

        Http::assertSentCount(1);
    }

    public function test_cv_optimization_restores_omitted_evidence_and_preserves_the_letter(): void
    {
        config(['cvgenerator.ollama_enabled' => true]);
        $member = User::factory()->create();
        $letter = 'Madame, Monsieur. Voici la lettre relue et personnalisée que je souhaite garder.';
        $candidature = $member->candidatures()->create([
            'source_url' => 'https://example.org/jobs/123',
            'resume_text' => self::RESUME,
            'offer_text' => self::OFFER,
            'status' => 'ready',
            'provider' => 'ollama',
            'result' => ['cv' => self::RESUME, 'letter' => $letter, 'requirements' => []],
        ]);
        $response = $this->response()['output'][0]['content'][0]['text'];
        $generated = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        $generated['letter'] = '';
        $generated['cv'] = str_replace('Migration d’un portail Laravel et mise en place de tests automatisés.', 'Développement et suivi technique du portail.', self::RESUME);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => json_encode($generated, JSON_THROW_ON_ERROR)]])]);

        $this->actingAs($member)->post(route('candidatures.retry', $candidature), [
            'provider' => 'ollama', 'cv_only' => true,
        ])->assertRedirect(route('candidatures.show', $candidature));

        $result = $candidature->refresh()->result;
        $this->assertSame($letter, $result['letter']);
        $this->assertStringContainsString("Atouts pour le poste\n- Migration d’un portail Laravel", $result['cv']);
        $this->assertStringNotContainsString('Kubernetes', $result['cv']);
        $this->assertSame(67, $result['assessments']['pdf']['score']);
        $this->assertSame(67, $result['assessments']['pdf']['coverage']);
    }

    public function test_unmet_mandatory_experience_and_degree_limit_the_score(): void
    {
        $cv = self::RESUME."\n4 ans d’expérience avec Laravel.";
        $requirements = [
            ['label' => 'Expérience', 'quote' => '10 ans d’expérience requis', 'importance' => 'required', 'match' => 'matched', 'evidence' => '4 ans d’expérience avec Laravel', 'cv_excerpt' => '4 ans d’expérience avec Laravel'],
            ['label' => 'BTS', 'quote' => 'BTS obligatoire', 'importance' => 'required', 'match' => 'missing', 'evidence' => '', 'cv_excerpt' => ''],
        ];
        $draft = app(ResumeAdaptation::class)->apply($cv, $cv, $requirements);
        $assessment = app(CandidatureDocuments::class)->assess($draft['cv'], $draft['requirements'], 'pdf', app(ResumeText::class));

        $this->assertSame(100, $assessment['readability']);
        $this->assertSame(25, $assessment['score']);
        $this->assertSame(['partial', 'missing'], array_column($assessment['blockers'], 'match'));
        $this->assertSame(['10 ans d’expérience requis', 'BTS obligatoire'], array_column($assessment['blockers'], 'quote'));
    }

    public function test_adapted_cv_prioritizes_verified_evidence_within_the_correct_job(): void
    {
        config(['cvgenerator.ollama_enabled' => true]);
        $member = User::factory()->create();
        $cv = <<<'TEXT'
Camille Test
Développeuse web
camille@example.test

Expérience professionnelle
Développeuse | Agence Est | 2022–2025
- Maintenance de pages statiques.
- Migration d’un portail Laravel et mise en place de tests automatisés.

Assistante | Atelier Sud | 2020–2022
- Suivi des commandes.
- Mise à jour du catalogue.

Compétences
Laravel, tests automatisés, SQL et PhpStorm.
TEXT;
        $generated = json_decode($this->response()['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $generated['cv'] = str_replace("camille@example.test\n", '', $cv);
        $generated['requirements'][] = ['label' => 'SQL', 'quote' => 'SQL est souhaité', 'importance' => 'preferred', 'match' => 'matched', 'evidence' => 'SQL', 'cv_excerpt' => 'SQL'];
        $generated['requirements'][] = ['label' => 'PHP', 'quote' => 'PHP est souhaité', 'importance' => 'preferred', 'match' => 'matched', 'evidence' => 'PHP', 'cv_excerpt' => 'PHP'];
        $this->mock(OfferReader::class)->shouldReceive('read')->once()->andReturn(self::OFFER.' SQL est souhaité. PHP est souhaité.');
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => json_encode($generated, JSON_THROW_ON_ERROR)]])]);

        $response = $this->actingAs($member)->post(route('candidatures.store'), [
            ...$this->input(), 'resume_text' => $cv, 'provider' => 'ollama',
        ]);

        $candidature = Candidature::query()->sole();
        $response->assertRedirect(route('candidatures.show', $candidature));
        $this->assertSame('ready', $candidature->status);
        $this->assertStringContainsString("Développeuse web\ncamille@example.test\n\nExpérience professionnelle", $candidature->result['cv']);
        $this->assertStringContainsString("Développeuse | Agence Est | 2022–2025\n- Migration d’un portail Laravel et mise en place de tests automatisés.\n- Maintenance de pages statiques.", $candidature->result['cv']);
        $this->assertStringContainsString("Assistante | Atelier Sud | 2020–2022\n- Suivi des commandes.\n- Mise à jour du catalogue.", $candidature->result['cv']);
        $this->assertSame(1, $candidature->result['assessments']['pdf']['pages']);
        $this->assertSame('matched', $candidature->result['requirements'][2]['match']);
        $this->assertSame('missing', $candidature->result['requirements'][3]['match']);
        Http::assertSentCount(1);
    }

    public function test_confirmed_experience_can_support_a_mission_without_making_it_mandatory(): void
    {
        config(['cvgenerator.ollama_enabled' => true]);
        $member = User::factory()->create();
        $offer = 'Atelier Nord recrute une Développeuse web. Assurer le montage de PC sur mesure et le diagnostic de pannes. Travailler en équipe pour atteindre les objectifs du magasin. Certification CompTIA A+ obligatoire.';
        $example = 'À titre personnel, j’ai assemblé deux PC et diagnostiqué une panne mémoire.';
        $candidature = $member->candidatures()->create([
            'source_url' => 'https://example.org/jobs/123',
            'resume_text' => self::RESUME, 'offer_text' => $offer,
            'status' => 'ready', 'provider' => 'ollama',
            'result' => ['cv' => self::RESUME, 'letter' => 'Lettre déjà relue', 'requirements' => []],
        ]);
        $generated = json_decode($this->response()['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        $generated['letter'] = '';
        $generated['requirements'] = [
            ['label' => 'Montage PC', 'quote' => 'Assurer le montage de PC sur mesure et le diagnostic de pannes.', 'importance' => 'required', 'match' => 'matched', 'evidence' => $example, 'cv_excerpt' => $example],
            ['label' => 'Travail en équipe', 'quote' => 'Travailler en équipe pour atteindre les objectifs du magasin.', 'importance' => 'required', 'match' => 'missing', 'evidence' => '', 'cv_excerpt' => ''],
            ['label' => 'Certification CompTIA A+', 'quote' => 'Certification CompTIA A+ obligatoire.', 'importance' => 'required', 'match' => 'partial', 'evidence' => $example, 'cv_excerpt' => $example],
        ];
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => json_encode($generated, JSON_THROW_ON_ERROR)]])]);

        $this->actingAs($member)->post(route('candidatures.retry', $candidature), [
            'provider' => 'ollama', 'cv_only' => true, 'confirmed_experience' => $example,
        ])->assertRedirect(route('candidatures.show', $candidature));

        $candidature->refresh();
        $this->assertSame($example, $candidature->confirmed_experience);
        $this->assertNotSame($example, $candidature->getRawOriginal('confirmed_experience'));
        $this->assertSame(['mission', 'mission', 'required'], array_column($candidature->result['requirements'], 'importance'));
        $this->assertSame([['label' => 'Certification CompTIA A+', 'quote' => 'Certification CompTIA A+ obligatoire.', 'match' => 'missing']], $candidature->result['assessments']['pdf']['blockers']);
        $this->assertSame(25, $candidature->result['assessments']['pdf']['score']);
        $this->assertStringContainsString($example, $candidature->result['cv']);
        $this->assertSame('Lettre déjà relue', $candidature->result['letter']);
        Http::assertSent(fn (Request $request): bool => json_decode($request['messages'][1]['content'], true)['exemples_confirmes'] === $example);
    }

    public function test_pdf_export_rejects_a_cv_that_cannot_fit_on_one_page(): void
    {
        $member = User::factory()->create();
        $cv = self::RESUME."\n".str_repeat("Travail de coordination du développement et du support applicatif.\n", 110);
        $candidature = $member->candidatures()->create([
            'source_url' => 'https://example.org/jobs/123',
            'resume_text' => self::RESUME, 'status' => 'ready', 'provider' => 'ollama',
            'result' => ['cv' => $cv, 'letter' => 'Lettre de test', 'requirements' => []],
        ]);

        $this->actingAs($member)->get(route('candidatures.download', [$candidature, 'cv', 'pdf']))->assertUnprocessable();
    }

    public function test_selected_pdf_template_is_available_in_the_preview_and_download(): void
    {
        $member = User::factory()->create();
        $candidature = $member->candidatures()->create([
            'source_url' => 'https://example.org/jobs/123',
            'resume_text' => self::RESUME,
            'company' => 'Atelier Nord',
            'status' => 'ready',
            'provider' => 'ollama',
            'result' => ['cv' => self::RESUME, 'letter' => 'Lettre de test', 'requirements' => []],
        ]);
        $this->actingAs($member);
        $route = [$candidature, 'cv', 'pdf'];
        $this->get(route('candidatures.show', $candidature))->assertInertia(fn (Assert $page) => $page
            ->where('pdfTemplates', [
                'classique' => 'Classique', 'editorial' => 'Éditorial', 'minimal' => 'Minimal',
                'marine' => 'Marine', 'sauge' => 'Sauge', 'terracotta' => 'Terracotta',
                'signature' => 'Signature', 'chronologie' => 'Chronologie', 'ardoise' => 'Ardoise', 'studio' => 'Studio',
            ]));

        foreach (array_keys(CandidatureDocuments::PDF_TEMPLATES) as $template) {
            $parameters = [...$route, 'template' => $template];
            $preview = $this->get(route('candidatures.download', [...$parameters, 'preview' => 1, 'embedded' => 1]))
                ->assertOk()
                ->assertSee('class="paper template-'.$template.'"', false);
            if (in_array($template, ['marine', 'sauge', 'ardoise'], true)) {
                $preview->assertSee('id="cv-columns"', false);
            }

            $filename = $template === 'classique' ? 'cv-atelier-nord.pdf' : 'cv-atelier-nord-'.$template.'.pdf';
            $response = $this->get(route('candidatures.download', $parameters))
                ->assertOk()
                ->assertHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');
            $pdf = (new Parser)->parseContent((string) $response->getContent());
            $this->assertCount(1, $pdf->getPages());
            $text = app(ResumeAdaptation::class)->normalize($pdf->getText());
            $this->assertStringContainsString("migration d'un portail laravel et mise en place de tests automatisés. compétences laravel, tests automatisés et sql. formation licence informatique, juin 2021.", $text);
        }

        $this->get(route('candidatures.download', [...$route, 'template' => 'inconnu']))->assertNotFound();
        $this->get(route('candidatures.download', [...$route, 'template' => ['minimal']]))->assertNotFound();
    }

    public function test_a_dense_cv_uses_the_full_width_when_a_sidebar_would_overflow(): void
    {
        $cv = "Camille Martin\nDéveloppeuse web\ncamille@example.test\n\nExpérience professionnelle\n";
        foreach (range(1, 31) as $index) {
            $cv .= "- Mission $index : développement d’applications et suivi des incidents applicatifs.\n";
        }
        $cv .= "\nCompétences\nLaravel, tests automatisés et SQL.\n\nFormation\nLicence informatique, juin 2021.";
        $documents = app(CandidatureDocuments::class);

        $preview = $documents->preview($cv, 'cv', 'marine');
        $pdf = (new Parser)->parseContent($documents->export($cv, 'cv', 'pdf', 'marine'));

        $this->assertStringContainsString('class="paper template-marine"', $preview);
        $this->assertStringNotContainsString('id="cv-columns"', $preview);
        $this->assertCount(1, $pdf->getPages());
        $this->assertSame(31, substr_count($pdf->getText(), 'Mission '));
        $this->assertStringContainsString('Licence informatique, juin 2021.', $pdf->getText());
    }

    public function test_failed_offer_can_be_resumed_with_manually_supplied_text_and_local_ai(): void
    {
        config(['cvgenerator.ollama_enabled' => true]);
        $member = User::factory()->create();
        $this->mock(OfferReader::class)->shouldReceive('read')->once()->andThrow(new \RuntimeException('offer_unavailable'));
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:11434/api/chat' => Http::response(['message' => ['content' => $this->response()['output'][0]['content'][0]['text']]]),
        ]);

        $this->actingAs($member)->post(route('candidatures.store'), [...$this->input(), 'provider' => 'ollama'])->assertRedirect();
        $candidature = Candidature::query()->sole();
        $this->assertSame('offer_unavailable', $candidature->error_code);
        Http::assertNothingSent();

        $this->post(route('candidatures.retry', $candidature), ['offer_text' => self::OFFER, 'provider' => 'ollama'])->assertRedirect();
        $this->assertSame('ready', $candidature->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_an_incomplete_ai_response_preserves_the_offer_and_a_retry_can_report_missing_prerequisites(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['openai_key' => 'personal-test-value'])->save();
        $offer = 'Kicklox recrute un Développeur Java. La maîtrise de Java est obligatoire. Le poste consiste à concevoir et intégrer des API REST dans une architecture N-Tiers.';
        $candidature = $member->candidatures()->create([
            'source_url' => 'https://example.org/jobs/123', 'resume_text' => self::RESUME, 'offer_text' => $offer,
            'provider' => 'openai', 'status' => 'failed', 'error_code' => 'offer_unavailable',
        ]);
        $this->mock(OfferReader::class)->shouldNotReceive('read');
        $incomplete = $this->response();
        $incomplete['output'][0]['content'][0]['text'] = '{"usable_offer":false,"title":"","company":"","cv":"","letter":"","requirements":[]}';
        $complete = $this->response();
        $complete['output'][0]['content'][0]['text'] = json_encode([
            'title' => 'Développeur Java', 'company' => 'Kicklox', 'cv' => self::RESUME,
            'letter' => "Objet : Candidature au poste Java / API REST\n\nMadame, Monsieur, je vous présente ma candidature chez Kicklox.",
            'requirements' => [
                ['label' => 'Java', 'quote' => 'La maîtrise de Java est obligatoire.', 'importance' => 'required', 'match' => 'missing', 'evidence' => '', 'cv_excerpt' => ''],
            ],
        ], JSON_THROW_ON_ERROR);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push($incomplete)->push($complete)]);

        $this->actingAs($member)->post(route('candidatures.retry', $candidature), [
            'provider' => 'openai', 'offer_text' => $offer, 'title' => 'Développeur Java', 'company' => 'Kicklox',
        ])->assertRedirect(route('candidatures.show', $candidature));

        $this->assertSame('provider_output', $candidature->refresh()->error_code);
        $this->get(route('candidatures.show', $candidature))->assertInertia(fn (Assert $page) => $page
            ->where('candidature.offer_text', $offer)->where('candidature.error_code', 'provider_output'));

        $this->post(route('candidatures.retry', $candidature), ['provider' => 'openai'])->assertRedirect();

        $this->assertSame('ready', $candidature->refresh()->status);
        $this->assertSame($offer, $candidature->offer_text);
        $this->assertSame('missing', $candidature->result['requirements'][0]['match']);
        $this->assertSame("Objet : Développeur Java — Kicklox\n\nMadame, Monsieur, je vous présente ma candidature chez Kicklox.", $candidature->result['letter']);
        Http::assertSent(fn (Request $request): bool => ! isset($request['text']['format']['schema']['properties']['usable_offer']));
    }

    public function test_admin_can_manage_roles_and_seo_while_private_pages_remain_unindexed(): void
    {
        config(['cvgenerator.indexable' => true, 'app.url' => 'https://cvgenerator.test']);
        $member = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($member)->get(route('admin.index'))->assertForbidden();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->patch(route('admin.role', $admin), ['role' => 'user'])->assertForbidden();
        $this->patch(route('admin.role', $member), ['role' => 'guest'])->assertRedirect();
        $this->assertSame('guest', $member->refresh()->role);

        $this->put(route('admin.seo'), ['title' => 'CV Generator local', 'robots_notes' => "Allow: /settings\n"])->assertSessionHasErrors('robots_notes');
        $this->put(route('admin.seo'), ['title' => 'CV Generator local', 'robots_notes' => '# Commentaire', 'llms' => '# CV Generator'])->assertRedirect();
        $this->get(route('home'))->assertOk()->assertSee('<title>CV Generator local</title>', false);
        $this->get(route('seo.robots'))->assertSee('Disallow: /settings')->assertSee('# Commentaire');
        $this->get(route('seo.sitemap'))->assertSee('<loc>https://cvgenerator.test/</loc>', false);
        $this->get(route('seo.llms'))->assertSee('# CV Generator');
        $this->get(route('admin.index'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_registration_can_be_closed_without_removing_the_frontend_routes(): void
    {
        config(['cvgenerator.registration' => false]);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), [
            'name' => 'Compte de test', 'email' => 'registration@example.test',
            'password' => 'Test-Password-9000', 'password_confirmation' => 'Test-Password-9000',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    }

    public function test_saved_keys_are_encrypted_and_never_flashed_or_serialized(): void
    {
        $member = User::factory()->create();
        $key = 'sk-project-test-fixture-12345';
        $this->actingAs($member);
        $this->put(route('ai-settings.update'), ['openai_key' => $key])->assertRedirect(route('password.confirm'));
        $this->assertNull($member->refresh()->openai_key);
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->put(route('ai-settings.update'), ['openai_key' => 'invalid-sensitive-value'])->assertSessionHasErrors('openai_key');
        $this->assertNull(session()->getOldInput('openai_key'));
        $this->put(route('ai-settings.update'), ['openai_key' => $key])->assertRedirect();
        $this->assertSame($key, $member->refresh()->openai_key);
        $this->assertNotSame($key, $member->getRawOriginal('openai_key'));
        $this->get(route('ai-settings.edit'))->assertDontSee($key)->assertInertia(fn (Assert $page) => $page->where('hasPersonalKey', true));
        $this->delete(route('ai-settings.destroy'))->assertRedirect();
        $this->assertNull($member->refresh()->openai_key);
    }

    public function test_offer_reader_extracts_job_posting_without_navigation_and_rejects_local_destinations(): void
    {
        $reader = new OfferReader;
        $html = '<title>Recherche</title><script type="application/ld+json">'.json_encode([
            '@graph' => [[
                '@type' => 'JobPosting', 'title' => 'Développeuse web',
                'hiringOrganization' => ['name' => 'Atelier Nord'],
                'jobLocation' => ['address' => ['addressLocality' => 'Brest', 'postalCode' => '29200', 'addressCountry' => 'FR']],
                'description' => '<p>'.self::OFFER.'</p>',
            ]],
        ], JSON_THROW_ON_ERROR).'</script><nav>Menu</nav>';

        $this->assertStringContainsString('Entreprise : Atelier Nord', $reader->readHtml($html));
        $this->assertStringContainsString('Lieu : 29200 Brest', $reader->readHtml($html));
        $this->assertStringNotContainsString('Menu', $reader->readHtml($html));

        $this->expectExceptionMessage('offer_unavailable');
        $reader->read('http://127.0.0.1/');
    }

    public function test_offer_reader_extracts_the_linkedin_description_without_sign_in_or_related_jobs(): void
    {
        $html = '<title>Atelier Nord | LinkedIn Jobs</title><main>'
            .'<h1 class="top-card-layout__title topcard__title">Développeuse web</h1>'
            .'<a class="topcard__org-name-link">Atelier Nord</a>'
            .'<form>Sign in to find your next job.</form>'
            .'<div class="show-more-less-html__markup relative"><p>'.self::OFFER.'</p></div>'
            .'<section>Offres d’emploi similaires</section></main>';

        $offer = (new OfferReader)->readHtml($html);

        $this->assertSame("Poste : Développeuse web\nEntreprise : Atelier Nord\n\n".self::OFFER, $offer);
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return ['source_url' => 'https://example.org/jobs/123', 'resume_text' => self::RESUME, 'provider' => 'openai'];
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return ['output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
            'usable_offer' => true,
            'title' => 'Développeuse web',
            'company' => 'Atelier Nord',
            'cv' => self::RESUME,
            'letter' => 'Madame, Monsieur, je vous présente ma candidature au poste de Développeuse web chez Atelier Nord.',
            'requirements' => [
                ['label' => 'Laravel', 'quote' => 'Expérience Laravel requise', 'importance' => 'required', 'match' => 'matched', 'evidence' => 'Migration d’un portail Laravel', 'cv_excerpt' => 'Migration d’un portail Laravel'],
                ['label' => 'Kubernetes', 'quote' => 'Kubernetes est souhaité', 'importance' => 'preferred', 'match' => 'matched', 'evidence' => 'Travail Kubernetes inventé', 'cv_excerpt' => 'Kubernetes'],
            ],
        ], JSON_THROW_ON_ERROR)]]]]];
    }
}
