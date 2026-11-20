import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    BriefcaseBusiness,
    ChevronDown,
    FileText,
    Kanban,
    Link2,
    LoaderCircle,
    Search,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    Pager,
    ProviderSelect,
    Status,
    TextArea,
    TrackingStageSelect,
} from '@/components/candidature-ui';
import type {
    PageList,
    Providers,
    TrackingStage,
    TrackingStages,
    WorkflowStatus,
} from '@/components/candidature-ui';
import FranceTravailOffers from '@/components/france-travail-offers';
import InputError from '@/components/input-error';
import OfferMap, {
    groupOfferLocations,
    locationKey,
} from '@/components/offer-map';
import type { OfferLocation } from '@/components/offer-map';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit } from '@/routes/ai-settings';
import { show, store } from '@/routes/candidatures';

type Entry = {
    id: string;
    title: string | null;
    company: string | null;
    status: WorkflowStatus;
    tracking_stage: TrackingStage;
    source_url: string | null;
    location: OfferLocation | null;
    created_at: string;
};

const views = {
    tracking: { label: 'Suivi', icon: Kanban },
    offers: { label: 'Offres enregistrées', icon: BriefcaseBusiness },
    discovery: { label: 'France Travail', icon: Search },
    resumes: { label: 'CV adaptés', icon: FileText },
};

type DashboardView = keyof typeof views;

const laneColors: Record<TrackingStage, string> = {
    interested:
        'border-slate-400 bg-slate-500/[.06] text-slate-700 dark:text-slate-300',
    sent: 'border-indigo-500 bg-indigo-500/[.07] text-indigo-800 dark:text-indigo-300',
    interview:
        'border-teal-600 bg-teal-500/[.07] text-teal-800 dark:text-teal-300',
    offer: 'border-amber-500 bg-amber-500/[.08] text-amber-900 dark:text-amber-300',
    closed: 'border-stone-400 bg-stone-500/[.06] text-stone-700 dark:text-stone-300',
};

const dateFormat = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' });

export default function Dashboard({
    candidatures,
    providers,
    canGenerate,
    hasPreviousResume,
    initialOfferUrl,
    trackingCounts,
    trackingStages,
    view,
    franceTravailConfigured,
    search,
}: {
    candidatures: PageList<Entry>;
    providers: Providers;
    canGenerate: boolean;
    hasPreviousResume: boolean;
    initialOfferUrl: string | null;
    trackingCounts: Record<TrackingStage, number>;
    trackingStages: TrackingStages;
    view: DashboardView;
    franceTravailConfigured: boolean;
    search: string;
}) {
    const total = Object.values(trackingCounts).reduce(
        (sum, count) => sum + count,
        0,
    );
    const [composerOpen, setComposerOpen] = useState(Boolean(initialOfferUrl));
    const [inputMode, setInputMode] = useState<'file' | 'text' | 'reuse'>(
        hasPreviousResume && !initialOfferUrl ? 'reuse' : 'file',
    );
    const form = useForm({
        source_url: initialOfferUrl ?? '',
        resume_file: null as File | null,
        resume_text: '',
        reuse_resume: false,
        provider: providers.ollama ? 'ollama' : 'openai',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (!composerOpen) {
            setComposerOpen(true);
            return;
        }
        form.transform((data) => ({
            ...data,
            reuse_resume: inputMode === 'reuse',
            resume_file: inputMode === 'file' ? data.resume_file : null,
            resume_text: inputMode === 'text' ? data.resume_text : '',
        }));
        form.post(store.url(), { forceFormData: true });
    }

    return (
        <div className="mx-auto flex w-full max-w-[94rem] min-w-0 flex-col gap-6 px-4 pb-12 sm:px-7">
            <Head title="Espace candidature" />
            <header className="flex flex-wrap items-start justify-between gap-5 sm:pt-3">
                <div>
                    <h1 className="text-3xl font-semibold tracking-[-.045em] sm:text-5xl">
                        Ma recherche
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Retrouve tes dossiers et suis l’avancement de tes
                        candidatures.
                    </p>
                </div>
                <dl className="flex flex-wrap gap-2 sm:gap-3">
                    {[
                        ['Dossiers', total],
                        ['Entretiens', trackingCounts.interview],
                        ['Offres reçues', trackingCounts.offer],
                    ].map(([label, count]) => (
                        <div
                            key={label}
                            className="min-w-23 rounded-xl border bg-card px-3 py-2 sm:px-4"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="mt-0.5 text-xl font-semibold text-brand-ink tabular-nums">
                                {count}
                            </dd>
                        </div>
                    ))}
                </dl>
            </header>
            {canGenerate ? (
                <form
                    id="nouvelle"
                    onSubmit={submit}
                    className="rounded-2xl border bg-card px-4 py-4 shadow-xs sm:px-5"
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <Label
                            htmlFor="source_url"
                            className="flex items-center gap-2 text-sm"
                        >
                            <span className="grid size-8 place-items-center rounded-lg bg-brand-soft text-brand-ink">
                                <Link2 className="size-4" />
                            </span>
                            Lien de l’annonce
                        </Label>
                        <Input
                            id="source_url"
                            type="url"
                            value={form.data.source_url}
                            placeholder="https://…"
                            maxLength={2048}
                            required
                            className="min-w-40 flex-1"
                            onChange={(event) =>
                                form.setData('source_url', event.target.value)
                            }
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            aria-expanded={composerOpen}
                            aria-controls="application-options"
                            onClick={() => setComposerOpen(!composerOpen)}
                        >
                            {composerOpen ? 'Replier' : 'Préparer'}{' '}
                            <ChevronDown
                                className={cn(composerOpen && 'rotate-180')}
                            />
                        </Button>
                    </div>
                    <InputError message={form.errors.source_url} />
                    <div id="application-options" hidden={!composerOpen}>
                        <div className="mt-4 grid items-start gap-6 border-t pt-4 lg:grid-cols-2">
                            <div className="grid gap-3">
                                <Label
                                    className="flex items-center gap-2"
                                    htmlFor={
                                        inputMode === 'text'
                                            ? 'resume_text'
                                            : inputMode === 'file'
                                              ? 'resume_file'
                                              : 'reuse-resume'
                                    }
                                >
                                    <FileText className="size-4" /> Ton CV
                                </Label>
                                <div
                                    className="flex flex-wrap gap-2"
                                    aria-label="Mode d’import"
                                >
                                    {(
                                        [
                                            ['file', 'Fichier'],
                                            ['text', 'Texte'],
                                            ...(hasPreviousResume
                                                ? [['reuse', 'Dernier CV']]
                                                : []),
                                        ] as [typeof inputMode, string][]
                                    ).map(([value, label]) => (
                                        <Button
                                            key={value}
                                            type="button"
                                            size="sm"
                                            variant={
                                                inputMode === value
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            aria-pressed={inputMode === value}
                                            onClick={() => setInputMode(value)}
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                                {inputMode === 'file' && (
                                    <Label
                                        htmlFor="resume_file"
                                        className="relative grid min-h-24 cursor-pointer place-content-center justify-items-center gap-1 rounded-lg border border-dashed bg-muted/35 px-4 text-center transition-colors focus-within:ring-2 focus-within:ring-ring hover:bg-muted"
                                    >
                                        <Upload className="size-4" />
                                        <span className="max-w-full truncate">
                                            {form.data.resume_file?.name ??
                                                'Choisir un fichier'}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            PDF texte, Word ou TXT · 4 Mo max.
                                        </span>
                                        <input
                                            className="absolute inset-0 size-full cursor-pointer opacity-0"
                                            id="resume_file"
                                            type="file"
                                            accept=".pdf,.docx,.txt"
                                            onChange={(event) =>
                                                form.setData(
                                                    'resume_file',
                                                    event.target.files?.[0] ??
                                                        null,
                                                )
                                            }
                                        />
                                    </Label>
                                )}
                                {inputMode === 'text' && (
                                    <TextArea
                                        id="resume_text"
                                        placeholder="Colle le contenu de ton CV ici…"
                                        rows={5}
                                        maxLength={30000}
                                        value={form.data.resume_text}
                                        onChange={(event) =>
                                            form.setData(
                                                'resume_text',
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                                {inputMode === 'reuse' && (
                                    <p
                                        id="reuse-resume"
                                        className="rounded-md bg-muted p-3 text-sm text-muted-foreground"
                                    >
                                        Le CV de ta dernière candidature sera
                                        utilisé.
                                    </p>
                                )}
                                <InputError
                                    message={
                                        form.errors.resume_file ??
                                        form.errors.resume_text
                                    }
                                />
                            </div>
                            <div className="grid gap-3">
                                <Label htmlFor="provider">
                                    Moteur de génération
                                </Label>
                                <ProviderSelect
                                    id="provider"
                                    available={providers}
                                    value={form.data.provider}
                                    onChange={(value) =>
                                        form.setData('provider', value)
                                    }
                                />
                                <InputError message={form.errors.provider} />
                                {form.data.provider === 'openai' && (
                                    <p className="text-xs text-muted-foreground">
                                        Le texte du CV et de l’annonce est
                                        transmis à OpenAI. Les appels utilisent
                                        la clé associée à ton compte.
                                    </p>
                                )}
                                <Link
                                    href={edit()}
                                    className="w-fit text-xs underline underline-offset-4"
                                >
                                    Gérer ma clé OpenAI
                                </Link>
                            </div>
                        </div>
                        <div className="mt-5 flex justify-end border-t pt-4">
                            <Button
                                type="submit"
                                size="lg"
                                disabled={
                                    form.processing ||
                                    (!providers.ollama && !providers.openai)
                                }
                            >
                                {form.processing ? (
                                    <LoaderCircle className="animate-spin" />
                                ) : (
                                    <ArrowRight />
                                )}
                                Préparer ma candidature
                            </Button>
                        </div>
                    </div>
                </form>
            ) : (
                <div className="rounded-xl border bg-card p-6">
                    <h2 className="text-lg font-semibold">Accès invité</h2>
                    <p className="text-sm text-muted-foreground">
                        La génération de candidatures est réservée aux comptes
                        membres.
                    </p>
                </div>
            )}
            <section aria-label={views[view].label} className="min-w-0">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-3 border-b pb-3">
                    <nav
                        aria-label="Vues de ma recherche"
                        className="flex flex-wrap gap-1 rounded-xl bg-muted p-1"
                    >
                        {(
                            Object.entries(views) as [
                                DashboardView,
                                (typeof views)[DashboardView],
                            ][]
                        ).map(([value, { label, icon: Icon }]) => (
                            <Link
                                key={value}
                                href={dashboard({
                                    query: {
                                        view: value,
                                        q: search || undefined,
                                    },
                                })}
                                aria-current={
                                    view === value ? 'page' : undefined
                                }
                                className={cn(
                                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:text-foreground',
                                    view === value
                                        ? 'bg-card text-brand-ink shadow-xs'
                                        : 'text-muted-foreground',
                                )}
                            >
                                <Icon aria-hidden className="size-4" /> {label}
                            </Link>
                        ))}
                    </nav>
                    {view !== 'discovery' && (
                        <form
                            className="flex w-full max-w-sm gap-2"
                            role="search"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const term = String(
                                    new FormData(event.currentTarget).get(
                                        'q',
                                    ) ?? '',
                                ).trim();
                                router.get(
                                    dashboard.url(),
                                    { view, q: term || undefined },
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <Label
                                className="sr-only"
                                htmlFor="dashboard-search"
                            >
                                Rechercher un poste ou une entreprise
                            </Label>
                            <Input
                                key={search}
                                id="dashboard-search"
                                name="q"
                                type="search"
                                maxLength={100}
                                defaultValue={search}
                                placeholder="Poste ou entreprise"
                            />
                            <Button
                                variant="outline"
                                size="icon"
                                type="submit"
                                aria-label="Rechercher"
                            >
                                <Search />
                            </Button>
                            {search && (
                                <Button asChild variant="ghost">
                                    <Link href={dashboard({ query: { view } })}>
                                        Effacer
                                    </Link>
                                </Button>
                            )}
                        </form>
                    )}
                </div>
                {view === 'tracking' ? (
                    <TrackingBoard
                        entries={candidatures.data}
                        stages={trackingStages}
                        counts={trackingCounts}
                        filtered={search !== ''}
                    />
                ) : view === 'offers' ? (
                    <SavedOffers
                        entries={candidatures.data}
                        stages={trackingStages}
                        filtered={search !== ''}
                    />
                ) : view === 'discovery' ? (
                    <FranceTravailOffers
                        configured={franceTravailConfigured}
                        onUseOffer={(url) => {
                            form.setData('source_url', url);
                            setComposerOpen(true);
                            document
                                .getElementById('nouvelle')
                                ?.scrollIntoView({ behavior: 'smooth' });
                            document
                                .getElementById('source_url')
                                ?.focus({ preventScroll: true });
                        }}
                    />
                ) : candidatures.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground">
                        {search
                            ? 'Aucun résultat pour cette recherche.'
                            : 'Tes CV adaptés apparaîtront ici après la préparation.'}
                    </p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {candidatures.data.map((entry) => (
                            <li
                                key={entry.id}
                                className="overflow-hidden rounded-xl border bg-card"
                            >
                                <div
                                    className="flex h-29 items-end justify-center overflow-hidden bg-brand-soft px-6 pt-4"
                                    aria-hidden="true"
                                >
                                    <div className="grid w-full max-w-46 gap-2 rounded-t border bg-card px-4 pt-3 pb-4 text-brand-ink shadow-sm">
                                        <FileText className="size-5" />
                                        <div className="h-1 w-2/3 rounded-full bg-current/35" />
                                        <div className="h-1 w-full rounded-full bg-current/15" />
                                        <div className="h-1 w-5/6 rounded-full bg-current/15" />
                                    </div>
                                </div>
                                <div className="grid gap-2 p-4">
                                    <div>
                                        <p className="font-semibold wrap-anywhere">
                                            {entry.title ?? 'CV adapté'}
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {entry.company ??
                                                dateFormat.format(
                                                    new Date(entry.created_at),
                                                )}
                                        </p>
                                    </div>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="w-fit"
                                    >
                                        <Link
                                            href={`${show.url(entry.id)}#documents`}
                                        >
                                            Voir le CV <ArrowUpRight />
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {view !== 'discovery' && <Pager links={candidatures.links} />}
            </section>
        </div>
    );
}

function TrackingBoard({
    entries,
    stages,
    counts,
    filtered,
}: {
    entries: Entry[];
    stages: TrackingStages;
    counts: Record<TrackingStage, number>;
    filtered: boolean;
}) {
    const lanes = Object.entries(stages) as [TrackingStage, string][];

    return (
        <div
            role="region"
            aria-label="Tableau des candidatures"
            tabIndex={0}
            className="max-w-full overflow-x-auto pb-2 focus-visible:ring-2 focus-visible:ring-ring"
        >
            <div className="grid min-w-[66rem] grid-cols-5 gap-3">
                {lanes.map(([stage, label]) => {
                    const cards = entries.filter(
                        (entry) => entry.tracking_stage === stage,
                    );
                    return (
                        <section
                            key={stage}
                            aria-labelledby={`lane-${stage}`}
                            className={cn(
                                'min-h-72 min-w-0 rounded-xl border-t-4 p-3',
                                laneColors[stage],
                            )}
                        >
                            <div className="mb-3 flex items-center justify-between gap-2">
                                <h2
                                    id={`lane-${stage}`}
                                    className="text-sm font-semibold"
                                >
                                    {label}
                                </h2>
                                <span className="rounded-md bg-card px-2 py-0.5 text-xs font-medium tabular-nums">
                                    {filtered ? cards.length : counts[stage]}
                                </span>
                            </div>
                            {cards.length === 0 ? (
                                <p className="rounded-lg border border-dashed bg-card/60 p-3 text-xs text-muted-foreground">
                                    {filtered
                                        ? 'Aucun résultat'
                                        : 'Aucun dossier sur cette page'}
                                </p>
                            ) : (
                                <ul className="grid gap-3">
                                    {cards.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="rounded-lg border bg-card p-3 text-card-foreground shadow-xs"
                                        >
                                            <Link
                                                href={show(entry.id)}
                                                className="flex items-start justify-between gap-2 text-sm font-semibold hover:text-brand-ink"
                                            >
                                                <span className="wrap-anywhere">
                                                    {entry.title ??
                                                        'Nouvelle candidature'}
                                                </span>
                                                <ArrowUpRight
                                                    className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                                    aria-hidden
                                                />
                                            </Link>
                                            <p className="mt-1 text-xs wrap-anywhere text-muted-foreground">
                                                {entry.company ??
                                                    dateFormat.format(
                                                        new Date(
                                                            entry.created_at,
                                                        ),
                                                    )}
                                            </p>
                                            {entry.status !== 'ready' && (
                                                <div className="mt-2">
                                                    <Status
                                                        status={entry.status}
                                                    />
                                                </div>
                                            )}
                                            <div className="mt-3 border-t pt-2">
                                                <TrackingStageSelect
                                                    id={entry.id}
                                                    stage={stage}
                                                    stages={stages}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

function SavedOffers({
    entries,
    stages,
    filtered,
}: {
    entries: Entry[];
    stages: TrackingStages;
    filtered: boolean;
}) {
    const [selected, setSelected] = useState<string | null>(null);
    const locations = groupOfferLocations(entries);
    const unlocated = entries.filter((entry) => entry.location === null).length;
    const active = selected === null ? undefined : locations.get(selected);
    const visible = active
        ? entries.filter(
              (entry) =>
                  entry.location && locationKey(entry.location) === active.id,
          )
        : entries;

    return (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(18rem,2fr)]">
            <OfferMap
                points={Array.from(locations.values())}
                selected={active?.id ?? null}
                onSelect={(point) =>
                    setSelected(active?.id === point.id ? null : point.id)
                }
            />
            <section
                className="min-w-0 rounded-2xl border bg-card"
                aria-labelledby="offer-list-title"
            >
                <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                    <div>
                        <h2
                            id="offer-list-title"
                            className="text-sm font-semibold"
                        >
                            {active?.city ?? 'Annonces enregistrées'}
                        </h2>
                        <p
                            role="status"
                            className="mt-1 text-xs text-muted-foreground"
                        >
                            {visible.length}{' '}
                            {visible.length === 1 ? 'annonce' : 'annonces'}
                            {!active &&
                                unlocated > 0 &&
                                ` · ${unlocated} hors carte`}
                        </p>
                    </div>
                    {active && (
                        <Button
                            size="sm"
                            variant="ghost"
                            type="button"
                            onClick={() => setSelected(null)}
                        >
                            Toutes les annonces
                        </Button>
                    )}
                </div>
                {visible.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-muted-foreground">
                        {filtered
                            ? 'Aucun résultat pour cette recherche.'
                            : 'Les annonces récupérées apparaîtront ici.'}
                    </p>
                ) : (
                    <ul className="max-h-[38rem] divide-y overflow-y-auto px-4">
                        {visible.map((entry) => (
                            <li key={entry.id} className="grid gap-2 py-3">
                                <div>
                                    <Link
                                        href={show(entry.id)}
                                        className="text-sm font-semibold wrap-anywhere hover:underline"
                                    >
                                        {entry.title ?? 'Annonce importée'}
                                    </Link>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {entry.company ??
                                            dateFormat.format(
                                                new Date(entry.created_at),
                                            )}
                                        {entry.location &&
                                            ` · ${entry.location.city}`}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="rounded-md bg-muted px-2 py-1 text-xs font-medium">
                                        {stages[entry.tracking_stage]}
                                    </span>
                                    {entry.source_url && (
                                        <a
                                            href={entry.source_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="flex items-center gap-1 text-xs font-medium text-brand-ink hover:underline"
                                        >
                                            Voir à la source{' '}
                                            <ArrowUpRight className="size-3.5" />
                                        </a>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Ma recherche', href: dashboard() }],
};
