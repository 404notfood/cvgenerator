import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Monitor, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, edit, update } from '@/routes/ai-settings';

export default function AISettings({
    hasPersonalKey,
    hasEnvironmentKey,
    ollamaEnabled,
}: {
    hasPersonalKey: boolean;
    hasEnvironmentKey: boolean;
    ollamaEnabled: boolean;
}) {
    const form = useForm({ openai_key: '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put(update.url(), {
            preserveScroll: true,
            onFinish: () => form.reset('openai_key'),
        });
    }

    return (
        <div className="grid gap-5">
            <Head title="Moteurs IA" />
            <header>
                <h2 className="text-2xl font-semibold tracking-tight">
                    Moteurs IA
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Choisis le moteur au moment de préparer une candidature.
                </p>
            </header>
            <section className="rounded-lg border bg-card p-5">
                <h3 className="flex items-center gap-2 font-semibold">
                    <Monitor className="size-4" /> Ollama local
                </h3>
                <p className="mt-2 text-sm text-muted-foreground">
                    {ollamaEnabled
                        ? 'Disponible dans le formulaire. Ollama doit être démarré sur le serveur et le modèle configuré doit être installé.'
                        : 'Désactivé par la configuration du serveur.'}
                </p>
            </section>
            <section className="rounded-lg border bg-card p-5">
                <h3 className="flex items-center gap-2 font-semibold">
                    <KeyRound className="size-4" /> Clé OpenAI personnelle
                </h3>
                <p className="mt-2 text-sm text-muted-foreground">
                    Ta clé est chiffrée en base et utilisée uniquement lorsque
                    ton compte choisit OpenAI. La facturation reste sur ton
                    compte OpenAI.
                </p>
                {(hasPersonalKey || hasEnvironmentKey) && (
                    <p className="mt-3 flex items-center gap-2 text-sm">
                        <ShieldCheck className="size-4" />{' '}
                        {hasPersonalKey
                            ? 'Clé personnelle enregistrée'
                            : 'Clé serveur disponible pour ton compte administrateur'}
                    </p>
                )}
                <form onSubmit={save} className="mt-4 grid gap-2">
                    <Label htmlFor="openai_key">
                        {hasPersonalKey
                            ? 'Remplacer la clé'
                            : 'Ajouter une clé'}
                    </Label>
                    <Input
                        id="openai_key"
                        type="password"
                        value={form.data.openai_key}
                        autoComplete="new-password"
                        spellCheck={false}
                        maxLength={512}
                        required
                        onChange={(event) =>
                            form.setData('openai_key', event.target.value)
                        }
                    />
                    <InputError message={form.errors.openai_key} />
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.openai_key}
                        >
                            Enregistrer la clé
                        </Button>
                        {hasPersonalKey && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.delete(destroy.url(), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Retirer la clé
                            </Button>
                        )}
                    </div>
                </form>
            </section>
        </div>
    );
}

AISettings.layout = { breadcrumbs: [{ title: 'Moteurs IA', href: edit() }] };
