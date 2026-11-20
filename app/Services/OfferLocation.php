<?php

namespace App\Services;

use Illuminate\Support\Str;

class OfferLocation
{
    /** @var array<string, list<array{city: string, code: string, longitude: float, latitude: float, postcodes: list<string>}>>|null */
    private ?array $communes = null;

    /** @return array{city: string, longitude: float, latitude: float}|null */
    public function locate(?string $offer): ?array
    {
        if ($offer === null || ! preg_match_all('/(?=(?<!\pL)(?:localisation|localité|lieu(?: de travail)?|ville)\h*:\h*([^\r\n;:]{2,100}))/iu', $offer, $locations)) {
            return null;
        }

        $communes = $this->communes ??= $this->loadCommunes();
        foreach ($locations[1] as $location) {
            $normalized = $this->normalize($location);
            $words = explode(' ', $normalized);
            $postcode = preg_match('/^\d{5}$/D', $words[0]) === 1 ? array_shift($words) : null;

            for ($length = min(8, count($words)); $length > 0; $length--) {
                $name = implode(' ', array_slice($words, 0, $length));
                if (! isset($communes[$name])) {
                    continue;
                }
                $following = $words[$length] ?? '';
                $code = $postcode ?? (preg_match('/^\d{5}$/D', $following) === 1 ? $following : null);
                $matches = $communes[$name];
                if ($code !== null) {
                    $matches = array_values(array_filter($matches, fn (array $commune): bool => in_array($code, $commune['postcodes'], true)));
                }
                if (count($matches) === 1) {
                    $place = $matches[0];

                    return ['city' => $place['city'], 'longitude' => $place['longitude'], 'latitude' => $place['latitude']];
                }

                break;
            }
        }

        return null;
    }

    public function communeCode(string $place): ?string
    {
        $name = $this->normalize($place);
        preg_match('/^(?:(\d{5}) )?([a-z0-9 ]+?)(?: (\d{5}))?$/D', $name, $parts);
        $postcode = ($parts[1] ?? '') ?: ($parts[3] ?? '');
        $communes = $this->communes ??= $this->loadCommunes();
        $matches = $communes[$parts[2] ?? ''] ?? [];
        if ($postcode !== '') {
            $matches = array_values(array_filter($matches, fn (array $commune): bool => in_array($postcode, $commune['postcodes'], true)));
        }

        return count($matches) === 1 ? $matches[0]['code'] : null;
    }

    private function normalize(string $place): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($place))));
    }

    /** @return array<string, list<array{city: string, code: string, longitude: float, latitude: float, postcodes: list<string>}>> */
    private function loadCommunes(): array
    {
        $path = resource_path('communes-metropole.json.gz');
        $compressed = is_file($path) ? file_get_contents($path) : false;
        $json = $compressed !== false ? gzdecode($compressed) : false;

        return $json !== false ? (json_decode($json, true)['locations'] ?? []) : [];
    }
}
