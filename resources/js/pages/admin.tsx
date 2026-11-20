import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FileSearch, KeyRound, Settings2, Users } from 'lucide-react';
import type { FormEvent } from 'react';
import { Pager, TextArea } from '@/components/candidature-ui';
import type { PageList } from '@/components/candidature-ui';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, role, seo as updateSeo } from '@/routes/admin';
import { edit } from '@/routes/ai-settings';
import { llms, robots, sitemap } from '@/routes/seo';

type Role = 'admin' | 'user' | 'guest';
type Member = {
    id: number;
    name: string;
    email: string;
    role: Role;
    email_verified_at: string | null;
};
type Seo = {
    title?: string;
    description?: string;
    robots_notes?: string;
    llms?: string;
};

export default function Admin({
    members,
    search,
    stats,
    seo,
    indexable,
    hasEnvironmentKey,
}: {
    members: PageList<Member>;
    search: string;
    stats: { users: number; candidatures: number; ready: number };
    seo: Seo;
    indexable: boolean;
    hasEnvironmentKey: boolean;
}) {
    const { auth } = usePage().props;
    const filter = useForm({ q: search });

    function findMember(event: FormEvent) {
        event.preventDefault();
        filter.get(index.url(), { preserveState: true });
    }

    return (
        <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 pb-12 sm:px-7">
            <Head title="Administration" />
            <header className="border-b pb-5 sm:pt-3">
                <h1 className="text-4xl font-semibold tracking-[-.055em] sm:text-5xl">
                    Administration
                </h1>
            </header>
            <section
                className="grid gap-3 sm:grid-cols-3"
                aria-label="Statistiques"
            >
                {(
                    [
                        ['Comptes', stats.users, Users],
                        ['Candidatures', stats.candidatures, FileSearch],
                        ['Dossiers prêts', stats.ready, Settings2],
                    ] as const
                ).map(([label, number, Icon]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between rounded-xl border bg-card p-4"
                    >
                        <div>
                            <p className="text-sm text-muted-foreground">
                                {label}
                            </p>
                            <strong className="text-2xl">{number}</strong>
                        </div>
                        <Icon className="size-5 text-muted-foreground" />
                    </div>
                ))}
            </section>
            <section className="rounded-xl border bg-card p-5 sm:p-6">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold">
                            Membres et rôles
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Administrateur, membre ou invité.
                        </p>
                    </div>
                    <form
                        className="flex items-center gap-2"
                        onSubmit={findMember}
                    >
                        <Input
                            aria-label="Rechercher un membre"
                            placeholder="Nom ou e-mail"
                            value={filter.data.q}
                            onChange={(event) =>
                                filter.setData('q', event.target.value)
                            }
                        />
                        <Button variant="outline" type="submit">
                            Chercher
                        </Button>
                    </form>
                </div>
                <InputError message={filter.errors.q} />
                <div className="divide-y">
                    {members.data.map((member) => (
                        <MemberRow
                            key={member.id + member.role}
                            member={member}
                            self={member.id === auth.user.id}
                        />
                    ))}
                    {members.data.length === 0 && (
                        <p className="py-3 text-sm text-muted-foreground">
                            Aucun compte trouvé.
                        </p>
                    )}
                </div>
                <Pager links={members.links} />
            </section>
            <div className="grid items-start gap-5 lg:grid-cols-[1fr_20rem]">
                <SeoForm seo={seo} indexable={indexable} />
                <section className="rounded-xl border bg-card p-5">
                    <h2 className="flex items-center gap-2 text-lg font-semibold">
                        <KeyRound className="size-4" /> Clés OpenAI
                    </h2>
                    <p className="mt-3 text-sm text-muted-foreground">
                        {hasEnvironmentKey
                            ? 'La clé serveur est disponible pour les administrateurs.'
                            : 'Aucune clé serveur configurée.'}{' '}
                        Chaque membre peut enregistrer sa propre clé depuis ses
                        paramètres.
                    </p>
                    <Button variant="outline" asChild className="mt-4">
                        <Link href={edit()}>Gérer ma clé</Link>
                    </Button>
                </section>
            </div>
        </div>
    );
}

function MemberRow({ member, self }: { member: Member; self: boolean }) {
    const form = useForm({ role: member.role });
    const labels = { admin: 'Administrateur', user: 'Membre', guest: 'Invité' };

    function save(event: FormEvent) {
        event.preventDefault();
        form.patch(role.url(member.id), { preserveScroll: true });
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 py-3">
            <div className="min-w-0">
                <p className="font-semibold break-words">
                    {member.name}
                    {self && (
                        <span className="ml-2 text-xs text-muted-foreground">
                            Ton compte
                        </span>
                    )}
                </p>
                <p className="text-sm break-all text-muted-foreground">
                    {member.email}
                    {!member.email_verified_at && ' · E-mail à vérifier'}
                </p>
            </div>
            {self ? (
                <span className="rounded border px-2 py-1 text-xs font-medium">
                    {labels[member.role]}
                </span>
            ) : (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={save}
                >
                    <select
                        aria-label={'Rôle de ' + member.name}
                        value={form.data.role}
                        onChange={(event) =>
                            form.setData('role', event.target.value as Role)
                        }
                        className="h-8 rounded border bg-background px-2 text-sm"
                    >
                        {Object.entries(labels).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={!form.isDirty || form.processing}
                    >
                        Enregistrer
                    </Button>
                    <InputError message={form.errors.role} />
                </form>
            )}
        </div>
    );
}

function SeoForm({ seo, indexable }: { seo: Seo; indexable: boolean }) {
    const form = useForm({
        title: seo.title ?? '',
        description: seo.description ?? '',
        robots_notes: seo.robots_notes ?? '',
        llms: seo.llms ?? '',
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put(updateSeo.url(), { preserveScroll: true });
    }

    return (
        <section className="rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="text-xl font-semibold">Référencement</h2>
            <p className="mt-1 text-sm text-muted-foreground">
                {indexable
                    ? 'Indexation de l’accueil activée.'
                    : 'Indexation désactivée. Active CV_INDEXABLE lors de la mise en ligne publique.'}
            </p>
            <div className="mt-3 flex flex-wrap gap-3 text-sm underline underline-offset-4">
                <a href={sitemap.url()}>sitemap.xml</a>
                <a href={robots.url()}>robots.txt</a>
                <a href={llms.url()}>llms.txt</a>
            </div>
            <form className="mt-5 grid gap-2" onSubmit={save}>
                <Label htmlFor="seo-title">Titre de l’accueil</Label>
                <Input
                    id="seo-title"
                    maxLength={75}
                    value={form.data.title}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                />
                <InputError message={form.errors.title} />
                <Label htmlFor="seo-description">Description</Label>
                <TextArea
                    id="seo-description"
                    rows={2}
                    maxLength={190}
                    value={form.data.description}
                    onChange={(event) =>
                        form.setData('description', event.target.value)
                    }
                />
                <InputError message={form.errors.description} />
                <Label htmlFor="robots-notes">
                    Règles supplémentaires de robots.txt
                </Label>
                <TextArea
                    id="robots-notes"
                    rows={2}
                    maxLength={1000}
                    placeholder="Disallow: /chemin ou # commentaire"
                    value={form.data.robots_notes}
                    onChange={(event) =>
                        form.setData('robots_notes', event.target.value)
                    }
                />
                <InputError message={form.errors.robots_notes} />
                <Label htmlFor="llms-content">Contenu de llms.txt</Label>
                <TextArea
                    id="llms-content"
                    rows={5}
                    maxLength={12000}
                    value={form.data.llms}
                    onChange={(event) =>
                        form.setData('llms', event.target.value)
                    }
                />
                <InputError message={form.errors.llms} />
                <div className="mt-2 flex items-center gap-3">
                    <Button
                        type="submit"
                        disabled={!form.isDirty || form.processing}
                    >
                        Enregistrer le SEO
                    </Button>
                    {form.recentlySuccessful && (
                        <span
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Enregistré
                        </span>
                    )}
                </div>
            </form>
        </section>
    );
}

Admin.layout = { breadcrumbs: [{ title: 'Administration', href: index() }] };
