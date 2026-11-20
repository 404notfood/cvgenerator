import { MapPin } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type OfferLocation = {
    city: string;
    latitude: number;
    longitude: number;
};
export type MapPoint = OfferLocation & { id: string; count: number };

export function locationKey(location: OfferLocation) {
    return `${location.longitude},${location.latitude}`;
}

export function groupOfferLocations(
    entries: { location: OfferLocation | null }[],
) {
    const locations = new Map<string, MapPoint>();
    for (const { location } of entries) {
        if (location === null) {
            continue;
        }
        const id = locationKey(location);
        const point = locations.get(id);
        if (point) {
            point.count++;
        } else {
            locations.set(id, { ...location, id, count: 1 });
        }
    }

    return locations;
}

type Coordinate = [longitude: number, latitude: number];
type Region = Coordinate[][][];

const width = 760;
const height = 600;
const referenceCities = [
    { city: 'Paris', longitude: 2.35, latitude: 48.86 },
    { city: 'Brest', longitude: -4.49, latitude: 48.39 },
    { city: 'Nantes', longitude: -1.55, latitude: 47.22 },
    { city: 'Lille', longitude: 3.06, latitude: 50.63 },
    { city: 'Strasbourg', longitude: 7.75, latitude: 48.57 },
    { city: 'Lyon', longitude: 4.84, latitude: 45.76 },
    { city: 'Bordeaux', longitude: -0.58, latitude: 44.84 },
    { city: 'Toulouse', longitude: 1.44, latitude: 43.6 },
    { city: 'Marseille', longitude: 5.37, latitude: 43.3 },
];

function project(longitude: number, latitude: number) {
    return { x: (longitude + 5.8) / 16.2, y: (52 - latitude) / 11.5 };
}

export default function OfferMap({
    points,
    selected,
    onSelect,
    locationNote = 'Points placés au centre des communes.',
}: {
    points: MapPoint[];
    selected: string | null;
    onSelect: (point: MapPoint) => void;
    locationNote?: string;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [mapState, setMapState] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );

    useEffect(() => {
        const controller = new AbortController();
        const context = canvasRef.current?.getContext('2d');
        if (!context) {
            return;
        }

        async function draw() {
            const response = await fetch('/france-regions.json', {
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error('Missing map');
            }
            const { regions } = (await response.json()) as {
                regions: Region[];
            };
            if (!context || controller.signal.aborted) {
                return;
            }

            context.clearRect(0, 0, width, height);
            context.fillStyle = '#eff5e9';
            context.strokeStyle = '#8ba6a0';
            context.lineWidth = 1;
            for (const region of regions) {
                context.beginPath();
                for (const polygon of region) {
                    for (const ring of polygon) {
                        ring.forEach(([longitude, latitude], index) => {
                            const { x, y } = project(longitude, latitude);
                            if (index === 0) {
                                context.moveTo(x * width, y * height);
                            } else {
                                context.lineTo(x * width, y * height);
                            }
                        });
                        context.closePath();
                    }
                }
                context.fill('evenodd');
                context.stroke();
            }

            context.font = '12px system-ui';
            context.fillStyle = '#46635c';
            for (const place of referenceCities) {
                const { x, y } = project(place.longitude, place.latitude);
                context.fillRect(x * width - 1, y * height - 1, 2, 2);
                context.fillText(place.city, x * width + 5, y * height - 4);
            }
            setMapState('ready');
        }

        draw().catch(() => {
            if (!controller.signal.aborted) {
                setMapState('failed');
            }
        });

        return () => controller.abort();
    }, []);

    return (
        <figure className="overflow-hidden rounded-2xl border bg-card">
            <figcaption className="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold">
                <MapPin className="size-4 text-brand-ink" aria-hidden /> France
                métropolitaine
            </figcaption>
            <div className="relative aspect-[760/600] overflow-hidden bg-[#dcebf0]">
                <canvas
                    ref={canvasRef}
                    width={width}
                    height={height}
                    className="absolute inset-0 size-full"
                    aria-hidden
                />
                {mapState === 'ready' &&
                    points.map((point) => {
                        const { x, y } = project(
                            point.longitude,
                            point.latitude,
                        );
                        return (
                            <button
                                key={point.id}
                                type="button"
                                title={point.city}
                                aria-label={`${point.city}, ${point.count} ${point.count === 1 ? 'offre' : 'offres'}`}
                                aria-pressed={selected === point.id}
                                style={{
                                    left: `${x * 100}%`,
                                    top: `${y * 100}%`,
                                }}
                                className={cn(
                                    'absolute grid size-7 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full border-2 border-white text-xs font-semibold text-white shadow-md ring-offset-1 hover:z-10 hover:scale-110 focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-blue-900 sm:size-8',
                                    selected === point.id
                                        ? 'z-20 bg-blue-950 ring-2 ring-blue-400'
                                        : 'bg-blue-700',
                                )}
                                onClick={() => onSelect(point)}
                            >
                                {point.count}
                            </button>
                        );
                    })}
                {mapState === 'failed' && (
                    <p className="absolute inset-0 grid place-items-center p-4 text-center text-sm text-slate-800">
                        Le fond de carte est indisponible.
                    </p>
                )}
            </div>
            <p className="px-4 py-2 text-xs text-muted-foreground">
                {locationNote}{' '}
                <a
                    href="https://www.data.gouv.fr/datasets/contours-administratifs"
                    target="_blank"
                    rel="noreferrer"
                    className="underline"
                >
                    Contours IGN/Etalab 2026 (ODbL)
                </a>
                {' · '}
                <a
                    href="https://geo.api.gouv.fr/decoupage-administratif/communes"
                    target="_blank"
                    rel="noreferrer"
                    className="underline"
                >
                    API Géo
                </a>
            </p>
        </figure>
    );
}
