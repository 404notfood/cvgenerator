import { ArrowUpRight, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import OfferMap, {
    groupOfferLocations,
    locationKey,
} from '@/components/offer-map';
import type { OfferLocation } from '@/components/offer-map';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { search } from '@/routes/offers';

type Offer = {
    id: string;
    title: string;
    company: string;
    city: string;
    contract: string;
    url: string;
    location: OfferLocation | null;
};
type Results = { offers: Offer[]; next_page: number | null };
type SearchFilters = {
    keywords: string;
    city: string;
    radius: string;
    contract: string;
};

const selectStyle = 'h-9 w-full rounded-md border bg-background px-2 text-sm';

export default function FranceTravailOffers({
    configured,
    onUseOffer,
}: {
    configured: boolean;
    onUseOffer: (url: string) => void;
}) {
    const [city, setCity] = useState('');
    const [results, setResults] = useState<Results | null>(null);
    const [submitted, setSubmitted] = useState<SearchFilters | null>(null);
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const request = useRef<AbortController | null>(null);

    useEffect(() => () => request.current?.abort(), []);

    async function load(filters: SearchFilters, nextPage: number) {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setError('');
        try {
            const response = await fetch(
                search.url({ query: { ...filters, page: nextPage } }),
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            );
            const data = await response.json();
            if (!response.ok) {
                setError(
                    typeof data.message === 'string'
                        ? data.message
                        : 'La recherche a échoué.',
                );
                return;
            }
            setResults(data as Results);
            setSubmitted(filters);
            setSelected(null);
            setPage(nextPage);
        } catch {
            if (!controller.signal.aborted) {
                setError(
                    'Impossible de charger les annonces. Réessaie dans quelques instants.',
                );
            }
        } finally {
            if (!controller.signal.aborted) {
                setLoading(false);
            }
        }
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const values = new FormData(event.currentTarget);
        const get = (name: string) => String(values.get(name) ?? '').trim();
        void load(
            {
                keywords: get('keywords'),
                city: get('city'),
                radius: get('radius'),
                contract: get('contract'),
            },
            1,
        );
    }

    const entries = results?.offers ?? [];
    const points = groupOfferLocations(entries);
    const visible = entries.filter(
        (entry) =>
            selected === null ||
            (entry.location && locationKey(entry.location) === selected),
    );

    return (
        <div className="grid gap-4">
            {configured ? (
                <form
                    className="grid items-end gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_7rem_9rem_auto]"
                    onSubmit={submit}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="offer-keywords">Poste recherché</Label>
                        <Input
                            id="offer-keywords"
                            name="keywords"
                            placeholder="Ex. développeur web"
                            minLength={2}
                            maxLength={100}
                            required
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="offer-city">Commune</Label>
                        <Input
                            id="offer-city"
                            name="city"
                            placeholder="Ex. Laval 53000"
                            value={city}
                            maxLength={100}
                            onChange={(event) => setCity(event.target.value)}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="offer-radius">Rayon</Label>
                        <select
                            className={selectStyle}
                            id="offer-radius"
                            name="radius"
                            disabled={!city.trim()}
                            defaultValue="30"
                        >
                            <option value="0">Commune</option>
                            <option value="10">10 km</option>
                            <option value="30">30 km</option>
                            <option value="50">50 km</option>
                            <option value="100">100 km</option>
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="offer-contract">Contrat</Label>
                        <select
                            className={selectStyle}
                            id="offer-contract"
                            name="contract"
                        >
                            <option value="">Tous</option>
                            <option value="CDI">CDI</option>
                            <option value="CDD">CDD</option>
                            <option value="MIS">Intérim</option>
                        </select>
                    </div>
                    <Button disabled={loading} type="submit">
                        <Search /> Rechercher
                    </Button>
                </form>
            ) : (
                <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                    La recherche France Travail nécessite des identifiants
                    configurés côté serveur et un compte membre.
                </p>
            )}
            {error && (
                <p
                    role="alert"
                    className="rounded-xl border border-destructive/35 bg-card p-4 text-sm text-destructive"
                >
                    {error}
                </p>
            )}
            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(18rem,2fr)]">
                <OfferMap
                    points={Array.from(points.values())}
                    selected={selected}
                    onSelect={(point) =>
                        setSelected(selected === point.id ? null : point.id)
                    }
                    locationNote="Emplacements issus des annonces France Travail."
                />
                <section
                    className="min-w-0 rounded-2xl border bg-card"
                    aria-labelledby="france-travail-result-title"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                        <div>
                            <h2
                                id="france-travail-result-title"
                                className="text-sm font-semibold"
                            >
                                {(selected && points.get(selected)?.city) ||
                                    'Offres France Travail'}
                            </h2>
                            <p
                                role="status"
                                className="mt-1 text-xs text-muted-foreground"
                            >
                                {loading
                                    ? 'Recherche en cours…'
                                    : results
                                      ? `${visible.length} ${visible.length === 1 ? 'offre' : 'offres'} sur cette page`
                                      : 'Lance une recherche par poste.'}
                            </p>
                        </div>
                        {selected && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setSelected(null)}
                            >
                                Toute la page
                            </Button>
                        )}
                    </div>
                    {results && visible.length === 0 && (
                        <p className="px-4 py-4 text-sm text-muted-foreground">
                            Aucune offre pour ces critères.
                        </p>
                    )}
                    <ul className="max-h-[36rem] divide-y overflow-y-auto px-4">
                        {visible.map((offer) => (
                            <li key={offer.id} className="grid gap-2 py-3">
                                <a
                                    href={offer.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-start justify-between gap-2 text-sm font-semibold hover:underline"
                                >
                                    {offer.title}
                                    <ArrowUpRight className="mt-0.5 size-4 shrink-0" />
                                </a>
                                <p className="text-xs text-muted-foreground">
                                    {[offer.company, offer.city, offer.contract]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                <Button
                                    className="w-fit"
                                    size="sm"
                                    variant="outline"
                                    type="button"
                                    onClick={() => onUseOffer(offer.url)}
                                >
                                    Préparer ma candidature
                                </Button>
                            </li>
                        ))}
                    </ul>
                    {results &&
                        submitted &&
                        (page > 1 || results.next_page !== null) && (
                            <nav
                                aria-label="Pages France Travail"
                                className="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-3 text-xs"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={loading || page === 1}
                                    onClick={() =>
                                        void load(submitted, page - 1)
                                    }
                                >
                                    Précédente
                                </Button>
                                {page}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        loading || results.next_page === null
                                    }
                                    onClick={() =>
                                        void load(submitted, page + 1)
                                    }
                                >
                                    Suivante
                                </Button>
                            </nav>
                        )}
                </section>
            </div>
        </div>
    );
}
