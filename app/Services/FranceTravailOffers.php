<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FranceTravailOffers
{
    private const TOKEN_URL = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=%2Fpartenaire';

    private const SEARCH_URL = 'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search';

    private const PAGE_SIZE = 30;

    public function __construct(private OfferLocation $locations) {}

    public function configured(): bool
    {
        return filled(config('cvgenerator.france_travail_id')) && filled(config('cvgenerator.france_travail_secret'));
    }

    /** @return array{offers: list<array<string, mixed>>, next_page: ?int} */
    public function search(string $keywords, ?string $commune, int $radius, ?string $contract, int $page): array
    {
        $clientId = (string) config('cvgenerator.france_travail_id');
        $secret = (string) config('cvgenerator.france_travail_secret');
        $cacheKey = 'france-travail-token:'.hash_hmac('sha256', $clientId, $secret);
        $start = ($page - 1) * self::PAGE_SIZE;
        $end = $start + self::PAGE_SIZE - 1;
        $query = [
            'motsCles' => $keywords,
            'range' => "$start-$end",
            ...($commune === null ? [] : ['commune' => $commune, 'distance' => $radius]),
            ...($contract === null ? [] : ['typeContrat' => $contract]),
        ];

        try {
            $response = $this->http()->withToken($this->token($clientId, $secret, $cacheKey))->get(self::SEARCH_URL, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('france_travail_unavailable');
        }
        if ($response->status() === 401) {
            Cache::forget($cacheKey);
        }
        if (in_array($response->status(), [204, 416], true)) {
            return ['offers' => [], 'next_page' => null];
        }
        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'france_travail_access',
                429 => 'france_travail_quota',
                default => 'france_travail_unavailable',
            });
        }

        $results = $response->json('resultats');
        if (! is_array($results)) {
            throw new RuntimeException('france_travail_unavailable');
        }
        $offers = [];
        foreach ($results as $result) {
            if (! is_array($result) || ! is_string($result['id'] ?? null) || ! preg_match('/^[a-z0-9_-]+$/iD', $result['id']) || ! is_string($result['intitule'] ?? null)) {
                continue;
            }
            $place = is_array($result['lieuTravail'] ?? null) ? $result['lieuTravail'] : [];
            $city = is_string($place['libelle'] ?? null) ? $place['libelle'] : '';
            $company = $result['entreprise']['nom'] ?? '';
            $contractLabel = $result['typeContratLibelle'] ?? '';
            $offers[$result['id']] = [
                'id' => $result['id'],
                'title' => $result['intitule'],
                'company' => is_string($company) ? $company : '',
                'city' => $city,
                'contract' => is_string($contractLabel) ? $contractLabel : '',
                'url' => 'https://candidat.francetravail.fr/offres/recherche/detail/'.$result['id'],
                'location' => $this->mapLocation($place, $city),
            ];
        }

        $hasMore = preg_match('~/([0-9]+)$~', $response->header('Content-Range'), $range) === 1
            ? (int) $range[1] > $end + 1
            : count($results) === self::PAGE_SIZE;

        return ['offers' => array_values($offers), 'next_page' => $hasMore && $page < 100 ? $page + 1 : null];
    }

    private function token(string $clientId, #[\SensitiveParameter] string $secret, string $cacheKey): string
    {
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                Cache::forget($cacheKey);
            }
        }

        $response = $this->http()->asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $secret,
            'scope' => 'api_offresdemploiv2 o2dsoffre',
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            $oauthError = $response->json('error');
            throw new RuntimeException(match (true) {
                $response->status() === 429 => 'france_travail_quota',
                $oauthError === 'invalid_client' => 'france_travail_credentials',
                $oauthError === 'invalid_scope' => 'france_travail_access',
                in_array($response->status(), [400, 401, 403], true) => 'france_travail_authentication',
                default => 'france_travail_unavailable',
            });
        }
        $expires = $response->json('expires_in');
        if (is_numeric($expires) && (int) $expires > 30) {
            Cache::put($cacheKey, Crypt::encryptString($token), (int) $expires - 30);
        }

        return $token;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(12);
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array{city: string, longitude: float, latitude: float}|null
     */
    private function mapLocation(array $place, string $city): ?array
    {
        $longitude = $place['longitude'] ?? null;
        $latitude = $place['latitude'] ?? null;
        $city = trim((string) preg_replace('/^\d{2,3}\h*-\h*/', '', $city));
        if (! is_numeric($longitude) || ! is_numeric($latitude)) {
            return $this->locations->locate('Lieu : '.$city);
        }

        return $longitude >= -5.6 && $longitude <= 10 && $latitude >= 41 && $latitude <= 51.6
            ? ['city' => $city, 'longitude' => (float) $longitude, 'latitude' => (float) $latitude]
            : null;
    }
}
