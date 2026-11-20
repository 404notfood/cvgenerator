<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property ?string $confirmed_experience
 * @property ?array{title: string, company: ?string, cv: string, letter: string, requirements: list<array<string, string>>, assessments?: array<string, mixed>, assessment_version?: int} $result
 * @property ?array{cv: string, letter: string} $sent_documents
 * @property ?Carbon $sent_at
 * @property ?string $tracking_stage
 */
#[Fillable(['source_url', 'resume_text', 'offer_text', 'confirmed_experience', 'title', 'company', 'provider', 'status', 'error_code', 'result', 'revision', 'sent_at', 'sent_documents', 'tracking_stage'])]
#[Hidden(['resume_text', 'offer_text', 'confirmed_experience', 'result', 'sent_documents'])]
class Candidature extends Model
{
    use HasUuids;

    public const TRACKING_STAGES = [
        'interested' => 'Intéressé',
        'sent' => 'CV envoyé',
        'interview' => 'Entretien',
        'offer' => 'Offre reçue',
        'closed' => 'Sans suite',
    ];

    public function trackingStage(): string
    {
        return $this->tracking_stage ?? ($this->sent_at === null ? 'interested' : 'sent');
    }

    protected function casts(): array
    {
        return [
            'source_url' => 'encrypted',
            'resume_text' => 'encrypted',
            'offer_text' => 'encrypted',
            'confirmed_experience' => 'encrypted',
            'result' => 'encrypted:array',
            'sent_at' => 'immutable_datetime',
            'sent_documents' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
