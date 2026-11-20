import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Download,
    LoaderCircle,
    RotateCw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    ProviderSelect,
    Status,
    TextArea,
    TrackingStageSelect,
} from '@/components/candidature-ui';
import type {
    Providers,
    TrackingStage,
    TrackingStages,
    WorkflowStatus,
} from '@/components/candidature-ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { destroy, download, retry, sent, update } from '@/routes/candidatures';

type Requirement = {
    label: string;
    quote: string;
    importance: 'required' | 'preferred' | 'mission';
    match: 'matched' | 'partial' | 'missing';
    evidence: string;
    cv_excerpt?: string;
};
type Format = 'pdf' | 'docx';
type Assessment = {
    score: number | null;
    grade: string | null;
    readability: number;
    pages: number | null;
    blockers: { label: string; quote: string; match: string }[];
    coverage: number | null;
    checks: { label: string; earned: number; max: number }[];
};
type Result = {
    cv: string;
    letter: string;
    requirements: Requirement[];
    assessments: Record<Format, Assessment | null>;
};
type Dossier = {
    id: string;
    title: string | null;
    company: string | null;
    source_url: string;
    offer_text: string | null;
    confirmed_experience: string | null;
    provider: 'ollama' | 'openai';
    status: WorkflowStatus;
    error_code: string | null;
    revision: number;
    sent_at: string | null;
    tracking_stage: TrackingStage;
    has_sent_documents: boolean;
    result: Result | null;
};
const errors: Record<string, string> = {
    offer_unavailable:
        'L’annonce n’a pas pu être lue. Colle sa description pour reprendre la préparation.',
    offer_not_recognized:
        'Le moteur n’a pas reconnu l’annonce dans le texte récupéré. Vérifie la description ci-dessous avant de relancer.',
    company_missing:
        'Le nom de l’entreprise n’a pas pu être vérifié. Confirme-le ci-dessous.',
    provider_unavailable:
        'Le moteur sélectionné est indisponible. Vérifie qu’Ollama fonctionne ou choisis un moteur configuré.',
    provider_key: 'OpenAI a refusé la clé associée à ce compte.',
    provider_quota:
        'OpenAI a signalé une limite d’usage ou un quota insuffisant.',
    provider_output:
        'Le moteur n’a pas terminé les documents. Le texte de l’annonce est disponible ci-dessous pour relancer.',
    provider_error: 'La préparation a été interrompue. Tu peux la relancer.',
};
const matchLabels = {
    matched: 'Étayée',
    partial: 'Partielle',
    missing: 'À confirmer',
};
const importanceLabels = {
    required: 'Prérequis obligatoire',
    preferred: 'Atout',
    mission: 'Mission',
};

export default function Show({
    candidature,
    providers,
    canGenerate,
    pdfTemplates,
    trackingStages,
}: {
    candidature: Dossier;
    providers: Providers;
    canGenerate: boolean;
    pdfTemplates: Record<string, string>;
    trackingStages: TrackingStages;
}) {
    const { id, title, company, status, result, error_code, sent_at } =
        candidature;
    const running = status === 'queued' || status === 'processing';
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [pdfTemplate, setPdfTemplate] = useState(
        Object.keys(pdfTemplates)[0],
    );

    return (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 pb-12 sm:px-7">
            <Head title={title || 'Nouvelle candidature'} />
            <Link
                href={dashboard()}
                className="flex w-fit items-center gap-2 text-sm font-medium hover:underline"
            >
                <ArrowLeft className="size-4" /> Mes candidatures
            </Link>
            <header className="flex flex-wrap items-start justify-between gap-5 rounded-2xl border bg-card p-5 shadow-xs sm:p-7">
                <div className="min-w-0">
                    <div className="mb-3">
                        <Status
                            status={status}
                            sent={Boolean(sent_at) && status === 'ready'}
                        />
                    </div>
                    <h1 className="text-3xl font-semibold tracking-tight break-words sm:text-5xl">
                        {title ?? 'Nouvelle candidature'}
                    </h1>
                    {company && (
                        <p className="mt-2 text-lg font-medium text-brand-ink">
                            {company}
                        </p>
                    )}
                    <a
                        href={candidature.source_url}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-3 inline-block text-sm font-medium text-brand-ink underline underline-offset-4"
                    >
                        Voir l’annonce d’origine
                    </a>
                </div>
                <div className="grid min-w-40 gap-2">
                    <p className="text-xs font-medium text-muted-foreground">
                        Étape du dossier
                    </p>
                    <TrackingStageSelect
                        id={id}
                        stage={candidature.tracking_stage}
                        stages={trackingStages}
                    />
                    {status === 'ready' && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() =>
                                router.patch(
                                    sent.url(id),
                                    { sent: !sent_at },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Check />{' '}
                            {sent_at
                                ? 'Repasser à relire'
                                : 'Marquer comme envoyée'}
                        </Button>
                    )}
                </div>
            </header>
            {(result || candidature.offer_text) && (
                <nav
                    aria-label="Sections du dossier"
                    className="flex flex-wrap items-center gap-2 text-sm"
                >
                    {result && !running && (
                        <>
                            <Button asChild size="sm" variant="outline">
                                <a href="#documents">Documents</a>
                            </Button>
                            <Button asChild size="sm" variant="outline">
                                <a href="#ats-title">Analyse ATS</a>
                            </Button>
                        </>
                    )}
                    {candidature.offer_text && (
                        <Button asChild size="sm" variant="outline">
                            <a href="#annonce">Annonce</a>
                        </Button>
                    )}
                </nav>
            )}
            {running && <Processing status={status} />}
            {(status === 'ready' || status === 'failed') &&
                result &&
                canGenerate && (
                    <>
                        <OptimizeCv
                            candidature={candidature}
                            providers={providers}
                        />
                        <Link
                            href={dashboard({ query: { offer_from: id } })}
                            className="w-fit text-sm font-medium text-brand-ink underline underline-offset-4"
                        >
                            Tester un autre CV sur cette offre
                        </Link>
                    </>
                )}
            {status === 'failed' && (
                <section className="rounded-xl border border-amber-500/35 bg-card p-5 sm:p-7">
                    <h2 className="text-xl font-semibold">
                        Reprendre la préparation
                    </h2>
                    <p
                        role="status"
                        className="mt-2 text-sm text-muted-foreground"
                    >
                        {errors[
                            error_code === 'offer_unavailable' &&
                            candidature.offer_text
                                ? 'offer_not_recognized'
                                : (error_code ?? 'provider_error')
                        ] ?? errors.provider_error}
                    </p>
                    {canGenerate && (
                        <RetryForm
                            key={candidature.revision}
                            candidature={candidature}
                            providers={providers}
                        />
                    )}
                </section>
            )}
            {result && !running && (
                <Documents
                    key={candidature.revision}
                    candidature={candidature}
                    result={result}
                    pdfTemplates={pdfTemplates}
                    pdfTemplate={pdfTemplate}
                    onPdfTemplateChange={setPdfTemplate}
                />
            )}
            {candidature.has_sent_documents && (
                <details className="rounded-xl border bg-card px-5 py-4">
                    <summary className="cursor-pointer font-semibold">
                        Documents du dernier envoi
                    </summary>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {(['cv', 'letter'] as const).flatMap((document) =>
                            (['pdf', 'docx'] as const).map((format) => (
                                <Button
                                    key={document + format}
                                    asChild
                                    variant="outline"
                                >
                                    <a
                                        href={download.url(
                                            {
                                                candidature: id,
                                                document,
                                                format,
                                            },
                                            { query: { sent: 1 } },
                                        )}
                                    >
                                        {document === 'cv' ? 'CV' : 'Lettre'}{' '}
                                        {format === 'pdf' ? 'PDF' : 'Word'}
                                    </a>
                                </Button>
                            )),
                        )}
                    </div>
                </details>
            )}
            {candidature.offer_text && (
                <details
                    className="scroll-mt-5 rounded-xl border bg-card px-5 py-4"
                    id="annonce"
                    open
                >
                    <summary className="cursor-pointer font-semibold">
                        Texte lu dans l’annonce
                    </summary>
                    <p className="mt-4 max-h-80 overflow-auto text-sm leading-relaxed wrap-anywhere whitespace-pre-wrap text-muted-foreground">
                        {candidature.offer_text}
                    </p>
                </details>
            )}
            <div className="flex flex-wrap items-center justify-end gap-2">
                {confirmDelete ? (
                    <>
                        <span className="text-sm">
                            Supprimer définitivement le dossier ?
                        </span>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmDelete(false)}
                        >
                            Annuler
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={deleting}
                            onClick={() =>
                                router.delete(destroy.url(id), {
                                    onStart: () => setDeleting(true),
                                    onFinish: () => setDeleting(false),
                                })
                            }
                        >
                            Supprimer
                        </Button>
                    </>
                ) : (
                    <Button
                        variant="ghost"
                        onClick={() => setConfirmDelete(true)}
                    >
                        <Trash2 /> Supprimer ce dossier
                    </Button>
                )}
            </div>
        </div>
    );
}

function Processing({ status }: { status: WorkflowStatus }) {
    usePoll(2300, { only: ['candidature'] });

    return (
        <div
            className="flex items-center gap-4 rounded-xl border bg-card p-6"
            role="status"
        >
            <LoaderCircle className="size-6 animate-spin motion-reduce:animate-none" />
            <div>
                <p className="font-semibold">
                    {status === 'queued'
                        ? 'En attente du traitement'
                        : 'Analyse de l’annonce et rédaction en cours'}
                </p>
                <p className="text-sm text-muted-foreground">
                    Tu peux quitter cette page et revenir au dossier.
                </p>
            </div>
        </div>
    );
}

function OptimizeCv({
    candidature,
    providers,
}: {
    candidature: Dossier;
    providers: Providers;
}) {
    const form = useForm({
        provider: providers[candidature.provider]
            ? candidature.provider
            : providers.ollama
              ? 'ollama'
              : 'openai',
        cv_only: true,
        confirmed_experience: candidature.confirmed_experience ?? '',
    });
    const openCriteria =
        candidature.result?.requirements
            .filter(({ match }) => match !== 'matched')
            .slice(0, 5) ?? [];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(retry.url(candidature.id), { preserveScroll: true });
    }

    if (!providers.ollama && !providers.openai) {
        return null;
    }

    return (
        <details className="rounded-xl border bg-card px-5 py-4">
            <summary className="cursor-pointer font-semibold">
                Faire correspondre mon expérience
            </summary>
            <form className="mt-3 grid max-w-2xl gap-3" onSubmit={submit}>
                <p className="text-sm text-muted-foreground">
                    Ajoute des exemples concrets qui manquent à ton CV. Précise
                    ce que tu as fait et dans quel contexte, au travail, en
                    bénévolat ou sur un projet personnel.
                </p>
                {openCriteria.length > 0 && (
                    <p className="text-sm">
                        <strong>
                            Points à documenter si tu les as pratiqués
                        </strong>
                        <span className="block text-muted-foreground">
                            {openCriteria.map(({ label }) => label).join(' · ')}
                        </span>
                    </p>
                )}
                <Label htmlFor="confirmed-experience">Mes exemples réels</Label>
                <TextArea
                    id="confirmed-experience"
                    rows={5}
                    maxLength={3000}
                    value={form.data.confirmed_experience}
                    onChange={(event) =>
                        form.setData('confirmed_experience', event.target.value)
                    }
                    placeholder="Exemple : à titre personnel, j’ai assemblé deux PC, identifié une panne de mémoire et réinstallé Windows."
                />
                <InputError message={form.errors.confirmed_experience} />
                <Label htmlFor="optimization-provider">Moteur</Label>
                <ProviderSelect
                    id="optimization-provider"
                    available={providers}
                    value={form.data.provider}
                    onChange={(value) =>
                        form.setData(
                            'provider',
                            value === 'openai' ? 'openai' : 'ollama',
                        )
                    }
                />
                {form.data.provider === 'openai' && (
                    <p className="text-xs text-muted-foreground">
                        Le CV et tes exemples seront transmis à OpenAI avec la
                        clé associée à ton compte.
                    </p>
                )}
                <InputError message={form.errors.provider} />
                <Button
                    type="submit"
                    size="sm"
                    className="mt-1 w-fit"
                    disabled={form.processing}
                >
                    <RotateCw /> Adapter le CV à l’offre
                </Button>
            </form>
        </details>
    );
}

function RetryForm({
    candidature,
    providers,
}: {
    candidature: Dossier;
    providers: Providers;
}) {
    const form = useForm({
        offer_text: candidature.offer_text ?? '',
        title: candidature.title ?? '',
        company: candidature.company ?? '',
        provider: providers[candidature.provider]
            ? candidature.provider
            : providers.ollama
              ? 'ollama'
              : 'openai',
    });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(retry.url(candidature.id), { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="mt-4 grid gap-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="title">Poste</Label>
                    <Input
                        id="title"
                        value={form.data.title}
                        onChange={(event) =>
                            form.setData('title', event.target.value)
                        }
                    />
                    <InputError message={form.errors.title} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="company">Entreprise</Label>
                    <Input
                        id="company"
                        value={form.data.company}
                        onChange={(event) =>
                            form.setData('company', event.target.value)
                        }
                    />
                    <InputError message={form.errors.company} />
                </div>
            </div>
            <Label htmlFor="offer_text">Texte de l’annonce</Label>
            <TextArea
                id="offer_text"
                rows={6}
                maxLength={30000}
                value={form.data.offer_text}
                onChange={(event) =>
                    form.setData('offer_text', event.target.value)
                }
            />
            <InputError message={form.errors.offer_text} />
            <Label htmlFor="retry-provider">Moteur</Label>
            <ProviderSelect
                id="retry-provider"
                available={providers}
                value={form.data.provider}
                onChange={(value) =>
                    form.setData(
                        'provider',
                        value === 'openai' ? 'openai' : 'ollama',
                    )
                }
            />
            <InputError message={form.errors.provider} />
            <Button
                className="w-fit"
                disabled={
                    form.processing || (!providers.ollama && !providers.openai)
                }
            >
                <RotateCw /> Relancer
            </Button>
        </form>
    );
}

function Documents({
    candidature,
    result,
    pdfTemplates,
    pdfTemplate,
    onPdfTemplateChange,
}: {
    candidature: Dossier;
    result: Result;
    pdfTemplates: Record<string, string>;
    pdfTemplate: string;
    onPdfTemplateChange: (template: string) => void;
}) {
    const [document, setDocument] = useState<'cv' | 'letter'>('cv');
    const [editing, setEditing] = useState(false);
    const [format, setFormat] = useState<Format>('pdf');
    const form = useForm({
        cv: result.cv,
        letter: result.letter,
        revision: candidature.revision,
    });
    const assessment = result.assessments[format];
    const pdfQuery = document === 'cv' ? { template: pdfTemplate } : {};
    const savedCv = normalizeProof(result.cv);
    const pdfPages = result.assessments.pdf?.pages;
    const overflow = document === 'cv' && (pdfPages ?? 0) > 1;
    const fullPreview =
        !form.isDirty && (document === 'letter' || pdfPages === 1);
    const blockingAssessment =
        result.assessments.pdf ?? result.assessments.docx;
    const profileGap = blockingAssessment?.blockers?.some(
        ({ match }) => match !== 'missing_from_export',
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(update.url(candidature.id), { preserveScroll: true });
    }

    return (
        <div
            id="documents"
            className="grid scroll-mt-5 items-start gap-5 xl:grid-cols-[1fr_19rem]"
        >
            {!!blockingAssessment?.blockers?.length && (
                <section
                    className="rounded-xl border border-amber-500/40 bg-card p-5 xl:col-span-2"
                    aria-labelledby="mandatory-title"
                >
                    <h2 className="font-semibold" id="mandatory-title">
                        {profileGap
                            ? 'Le profil ne démontre pas tous les critères obligatoires'
                            : 'Des preuves obligatoires manquent dans l’export'}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {profileGap
                            ? 'Candidature déconseillée tant que ces prérequis ne sont pas démontrés.'
                            : 'Remets les preuves déjà présentes dans ton profil dans le CV exporté.'}
                    </p>
                    <ul className="mt-3 grid gap-2 text-sm">
                        {blockingAssessment.blockers.map(
                            ({ label, quote, match }, index) => (
                                <li key={index} className="border-t pt-2">
                                    <strong>{label}</strong>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ·{' '}
                                        {match === 'partial'
                                            ? 'Partiellement démontré'
                                            : match === 'missing_from_export'
                                              ? 'Preuve absente de l’export'
                                              : 'Non démontré par le CV'}
                                    </span>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Annonce · « {quote} »
                                    </p>
                                </li>
                            ),
                        )}
                    </ul>
                </section>
            )}
            <form
                onSubmit={submit}
                className="min-w-0 rounded-xl border bg-card p-5 sm:p-6"
            >
                <div className="mb-4 flex flex-wrap items-baseline justify-between gap-3">
                    <h2 className="text-xl font-semibold tracking-tight">
                        Tes documents
                    </h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(!editing)}
                    >
                        {editing ? 'Voir l’aperçu' : 'Modifier le texte'}
                    </Button>
                </div>
                <div
                    className="mb-4 flex flex-wrap gap-5 border-b"
                    aria-label="Document à afficher"
                >
                    {(['cv', 'letter'] as const).map((kind) => (
                        <button
                            key={kind}
                            type="button"
                            aria-pressed={document === kind}
                            className="border-b-2 border-transparent pb-3 text-sm font-semibold text-muted-foreground aria-pressed:border-brand-ink aria-pressed:text-brand-ink"
                            onClick={() => setDocument(kind)}
                        >
                            {kind === 'cv' ? 'CV' : 'Lettre de motivation'}
                        </button>
                    ))}
                </div>
                {document === 'cv' && (
                    <fieldset className="mb-4">
                        <legend className="mb-2 text-sm font-medium">
                            Modèle PDF
                        </legend>
                        <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
                            {Object.entries(pdfTemplates).map(([id, label]) => (
                                <label key={id} className="cursor-pointer">
                                    <input
                                        type="radio"
                                        name="pdf-template"
                                        value={id}
                                        checked={id === pdfTemplate}
                                        onChange={() => onPdfTemplateChange(id)}
                                        className="peer sr-only"
                                    />
                                    <span className="flex flex-col items-center gap-1.5 rounded-lg border px-1 py-2 text-xs font-medium peer-checked:border-primary peer-checked:bg-muted peer-focus-visible:ring-2 peer-focus-visible:ring-ring hover:bg-muted">
                                        <span
                                            aria-hidden="true"
                                            className="cv-template-sample"
                                            data-template={id}
                                        >
                                            <span />
                                            <span />
                                        </span>
                                        {label}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    {(editing || form.isDirty) && (
                        <Button
                            type="submit"
                            disabled={!form.isDirty || form.processing}
                        >
                            Enregistrer
                        </Button>
                    )}
                    {form.isDirty ? (
                        <p className="text-xs text-muted-foreground">
                            Enregistre les modifications pour mettre à jour les
                            exports.
                        </p>
                    ) : overflow ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Ce PDF occuperait {pdfPages} pages. Raccourcis le
                            texte pour activer l’export A4 en une page.
                        </p>
                    ) : (
                        <>
                            <Button asChild variant="ghost">
                                <a
                                    target="_blank"
                                    rel="noreferrer"
                                    href={download.url(
                                        {
                                            candidature: candidature.id,
                                            document,
                                            format: 'pdf',
                                        },
                                        { query: { ...pdfQuery, preview: 1 } },
                                    )}
                                >
                                    Aperçu PDF
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a
                                    href={download.url(
                                        {
                                            candidature: candidature.id,
                                            document,
                                            format: 'pdf',
                                        },
                                        { query: pdfQuery },
                                    )}
                                >
                                    <Download /> PDF
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a
                                    href={download.url({
                                        candidature: candidature.id,
                                        document,
                                        format: 'docx',
                                    })}
                                >
                                    <Download /> Word
                                </a>
                            </Button>
                        </>
                    )}
                </div>
                {editing ? (
                    <>
                        <Label htmlFor="document-text" className="sr-only">
                            {document === 'cv'
                                ? 'Texte du CV'
                                : 'Texte de la lettre'}
                        </Label>
                        <TextArea
                            id="document-text"
                            rows={20}
                            maxLength={document === 'cv' ? 10000 : 8000}
                            value={form.data[document]}
                            onChange={(event) =>
                                form.setData(document, event.target.value)
                            }
                            className="leading-relaxed"
                        />
                    </>
                ) : fullPreview ? (
                    <iframe
                        title={
                            document === 'cv'
                                ? 'Aperçu du CV'
                                : 'Aperçu de la lettre'
                        }
                        className="aspect-[210/305] min-h-[38rem] w-full rounded-lg border"
                        src={download.url(
                            {
                                candidature: candidature.id,
                                document,
                                format: 'pdf',
                            },
                            {
                                query: {
                                    ...pdfQuery,
                                    preview: 1,
                                    embedded: 1,
                                    revision: candidature.revision,
                                },
                            },
                        )}
                    />
                ) : (
                    <div className="rounded-lg bg-muted p-2 sm:p-5">
                        <DocumentPreview
                            kind={document}
                            text={form.data[document]}
                        />
                    </div>
                )}
                <InputError
                    message={
                        form.errors.cv ??
                        form.errors.letter ??
                        form.errors.revision
                    }
                />
            </form>
            <aside className="grid gap-4">
                <section
                    className="rounded-xl border bg-card p-5"
                    aria-labelledby="ats-title"
                >
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h2 id="ats-title" className="font-semibold">
                            Contrôle ATS
                        </h2>
                        <select
                            aria-label="Format contrôlé"
                            value={format}
                            onChange={(event) =>
                                setFormat(
                                    event.target.value === 'docx'
                                        ? 'docx'
                                        : 'pdf',
                                )
                            }
                            className="rounded border bg-background px-2 py-1 text-sm"
                        >
                            <option value="pdf">PDF</option>
                            <option value="docx">Word</option>
                        </select>
                    </div>
                    {assessment ? (
                        <>
                            <div className="mb-4 flex items-center gap-3">
                                <span className="grid size-13 place-items-center rounded-lg bg-brand-soft text-2xl font-semibold text-brand-ink">
                                    {assessment.grade ?? '–'}
                                </span>
                                <div>
                                    <strong className="text-xl">
                                        {assessment.score === null
                                            ? 'Non évalué'
                                            : assessment.score + ' / 100'}
                                    </strong>
                                    <div className="text-xs text-muted-foreground">
                                        Adéquation du CV exporté à l’offre
                                    </div>
                                </div>
                            </div>
                            <p className="border-t pt-3 text-sm font-medium">
                                Lisibilité du fichier · {assessment.readability}{' '}
                                / 100
                            </p>
                            <ul className="mt-3 grid gap-3">
                                {assessment.checks.map((check) => (
                                    <li
                                        key={check.label}
                                        className="grid gap-1 text-xs"
                                    >
                                        <div className="flex justify-between gap-2">
                                            <span>{check.label}</span>
                                            <span className="shrink-0 font-semibold">
                                                {check.earned}/{check.max}
                                            </span>
                                        </div>
                                        <progress
                                            className="h-1.5 w-full accent-primary"
                                            max={check.max}
                                            value={check.earned}
                                            aria-label={check.label}
                                        />
                                    </li>
                                ))}
                            </ul>
                            {assessment.coverage !== null && (
                                <p className="mt-4 border-t pt-3 text-xs">
                                    Critères de l’offre étayés par le profil :{' '}
                                    <strong>{assessment.coverage} %</strong>
                                </p>
                            )}
                            <p className="mt-3 text-xs text-muted-foreground">
                                La note est limitée par la lisibilité, les
                                preuves retrouvées dans l’export et les critères
                                obligatoires.
                            </p>
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            La mesure est indisponible pour ce format.
                        </p>
                    )}
                </section>
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="mb-3 font-semibold">Exigences de l’offre</h2>
                    {result.requirements.map((item, index) => (
                        <details key={index} className="border-t py-2">
                            <summary className="cursor-pointer text-sm font-medium">
                                {item.label}{' '}
                                <span className="text-xs text-muted-foreground">
                                    · {importanceLabels[item.importance]} ·{' '}
                                    {matchLabels[item.match]}
                                </span>
                            </summary>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Annonce · {item.quote}
                            </p>
                            {item.evidence && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Source · {item.evidence}
                                </p>
                            )}
                            {item.cv_excerpt &&
                                savedCv.includes(
                                    normalizeProof(item.cv_excerpt),
                                ) && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Dans le CV · {item.cv_excerpt}
                                    </p>
                                )}
                        </details>
                    ))}
                </section>
            </aside>
        </div>
    );
}

function normalizeProof(text: string) {
    return text
        .replace(/[\u00ad\u200b]/gu, '')
        .replace(/[’‘]/gu, "'")
        .replace(/ﬁ/gu, 'fi')
        .replace(/ﬂ/gu, 'fl')
        .replace(/\s+/gu, ' ')
        .trim()
        .toLowerCase();
}

const cvSectionHeading =
    /^(profil|résumé|expériences? professionnelles?|compétences(?: clés)?|atouts pour le poste|formations?|langues|certifications|projets|profile|summary|work experience|professional experience|skills|key skills|key strengths|education|languages|projects)\s*:?$/iu;

function DocumentPreview({
    kind,
    text,
}: {
    kind: 'cv' | 'letter';
    text: string;
}) {
    return (
        <article
            className="cv-document-preview"
            aria-label={kind === 'cv' ? 'Aperçu du CV' : 'Aperçu de la lettre'}
        >
            {text
                .trim()
                .split(/\r?\n/)
                .map((line, index) => {
                    if (kind === 'cv' && index === 0) {
                        return <h3 key={index}>{line}</h3>;
                    }
                    if (kind === 'cv' && cvSectionHeading.test(line.trim())) {
                        return <h4 key={index}>{line}</h4>;
                    }

                    return <p key={index}>{line}</p>;
                })}
        </article>
    );
}

Show.layout = { breadcrumbs: [{ title: 'Atelier', href: dashboard() }] };
