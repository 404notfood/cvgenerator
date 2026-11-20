import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';
import { move } from '@/routes/candidatures';

export type Providers = { ollama: boolean; openai: boolean };
export type WorkflowStatus = 'queued' | 'processing' | 'failed' | 'ready';
export type TrackingStage =
    | 'interested'
    | 'sent'
    | 'interview'
    | 'offer'
    | 'closed';
export type TrackingStages = Record<TrackingStage, string>;
export type PageList<T> = {
    data: T[];
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export function ProviderSelect({
    id,
    available,
    value,
    onChange,
}: {
    id: string;
    available: Providers;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <select
            id={id}
            className="h-9 w-full rounded-md border bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring"
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="ollama" disabled={!available.ollama}>
                Ollama local{!available.ollama && ' — désactivé'}
            </option>
            <option value="openai" disabled={!available.openai}>
                OpenAI{!available.openai && ' — clé à configurer'}
            </option>
        </select>
    );
}

export function TrackingStageSelect({
    id,
    stage,
    stages,
}: {
    id: string;
    stage: TrackingStage;
    stages: TrackingStages;
}) {
    const [pendingStage, setPendingStage] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    return (
        <div className="min-w-0">
            <label className="sr-only" htmlFor={`stage-${id}`}>
                Étape de la candidature
            </label>
            <select
                id={`stage-${id}`}
                value={pendingStage ?? stage}
                disabled={pendingStage !== null}
                className="h-8 w-full rounded-md border bg-background px-2 text-xs font-medium text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                onChange={(event) => {
                    const nextStage = event.target.value;
                    setFailed(false);
                    setPendingStage(nextStage);
                    router.patch(
                        move.url(id),
                        { stage: nextStage },
                        {
                            preserveScroll: true,
                            onError: () => setFailed(true),
                            onFinish: () => setPendingStage(null),
                        },
                    );
                }}
            >
                {Object.entries(stages).map(([value, label]) => (
                    <option key={value} value={value}>
                        {label}
                    </option>
                ))}
            </select>
            {failed && (
                <p role="alert" className="mt-1 text-xs text-destructive">
                    Étape non enregistrée.
                </p>
            )}
        </div>
    );
}

export function TextArea({
    className,
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            {...props}
            className={cn(
                'w-full rounded-md border bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring',
                className,
            )}
        />
    );
}

const statuses = {
    queued: ['En attente', 'bg-amber-100 text-amber-950'],
    processing: ['En préparation', 'bg-indigo-100 text-indigo-950'],
    failed: ['À reprendre', 'bg-rose-100 text-rose-950'],
    ready: ['À relire', 'bg-brand-soft text-brand-ink'],
};

export function Status({
    status,
    sent,
}: {
    status: WorkflowStatus;
    sent?: boolean;
}) {
    const [label, color] = sent
        ? ['Envoyée', 'bg-emerald-100 text-emerald-950']
        : statuses[status];

    return (
        <span
            className={cn(
                'shrink-0 rounded px-2 py-1 text-xs font-semibold',
                color,
            )}
        >
            {label}
        </span>
    );
}

export function Pager({ links }: { links: PageList<unknown>['links'] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav
            className="mt-4 flex flex-wrap gap-2 text-sm"
            aria-label="Pagination"
        >
            {links.map(
                ({ url, label, active }, index) =>
                    url && (
                        <Link
                            key={label}
                            href={url}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'rounded border px-2 py-1 hover:bg-muted',
                                active && 'bg-muted font-bold',
                            )}
                        >
                            {index === 0
                                ? 'Précédent'
                                : index === links.length - 1
                                  ? 'Suivant'
                                  : label}
                        </Link>
                    ),
            )}
        </nav>
    );
}
