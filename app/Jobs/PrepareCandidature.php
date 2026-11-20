<?php

namespace App\Jobs;

use App\Models\Candidature;
use App\Services\CandidatureAi;
use App\Services\CandidatureDocuments;
use App\Services\OfferReader;
use App\Services\ResumeText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PrepareCandidature implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 390;

    public bool $cvOnly = false;

    public bool $offerProvided = false;

    public function __construct(public string $candidatureId, public int $revision, bool $cvOnly = false, bool $offerProvided = false)
    {
        $this->cvOnly = $cvOnly;
        $this->offerProvided = $offerProvided;
    }

    public function handle(OfferReader $reader, CandidatureAi $ai, CandidatureDocuments $documents, ResumeText $resumeText): void
    {
        $query = Candidature::query()->whereKey($this->candidatureId)->where('revision', $this->revision);
        if (! (clone $query)->where('status', 'queued')->update(['status' => 'processing'])) {
            return;
        }
        $candidature = $query->first();
        if (! $candidature) {
            return;
        }

        try {
            $offer = $candidature->offer_text;
            if (! $offer) {
                try {
                    $offer = $reader->read($candidature->source_url);
                } catch (Throwable) {
                    throw new RuntimeException('offer_unavailable');
                }
            }
            $candidature->update(['offer_text' => $offer]);

            $result = $ai->generate($candidature, $offer, $this->cvOnly, $this->offerProvided);
            if ($this->cvOnly && $candidature->result) {
                $result['letter'] = $candidature->result['letter'];
            }
            $company = $candidature->company ?: ($result['company'] ?? '');
            $title = $candidature->title ?: $result['title'];
            if (! filled($company) || (! $candidature->company && mb_stripos($offer, $company) === false)) {
                throw new RuntimeException('company_missing');
            }
            if (! $candidature->title && mb_stripos($offer, $title) === false) {
                throw new RuntimeException('provider_output');
            }
            if (! $this->cvOnly) {
                if (mb_stripos($result['letter'], $company) === false) {
                    throw new RuntimeException('provider_output');
                }
                if (mb_stripos($result['letter'], $title) === false) {
                    $result['letter'] = $this->withLetterSubject($result['letter'], $title, $company);
                }
            }

            $candidature->update(['status' => 'ready', 'title' => $title, 'company' => $company, 'result' => $documents->withAssessments($result, $resumeText), 'error_code' => null]);
        } catch (Throwable $exception) {
            $code = $exception->getMessage();
            if (! in_array($code, ['offer_unavailable', 'offer_not_recognized', 'company_missing', 'provider_unavailable', 'provider_key', 'provider_quota', 'provider_error', 'provider_output'], true)) {
                $code = $exception instanceof ConnectionException ? 'provider_unavailable' : 'provider_error';
                Log::warning('Préparation de candidature interrompue.', ['exception_type' => $exception::class, 'candidature' => $this->candidatureId]);
            }

            $candidature->update(['status' => 'failed', 'error_code' => $code]);
        }
    }

    private function withLetterSubject(string $letter, string $title, string $company): string
    {
        $letter = ltrim($letter);
        $subject = "$title — $company";
        if (preg_match('/\A((?:objet|subject)\h*:)[^\r\n]*/iu', $letter, $existing) === 1) {
            return $existing[1].' '.$subject.substr($letter, strlen($existing[0]));
        }

        return $subject."\n\n".$letter;
    }

    public function failed(?Throwable $exception): void
    {
        Candidature::query()->whereKey($this->candidatureId)
            ->where('revision', $this->revision)
            ->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'error_code' => 'provider_error']);
    }
}
