<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

class OfferReader
{
    private const MAX_BYTES = 2_000_000;

    public function read(string $url): string
    {
        $uri = new Uri($url);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            [$status, $location, $body] = $this->fetch($uri);

            if ($status >= 300 && $status < 400 && $location) {
                $uri = UriResolver::resolve($uri, new Uri($location));

                continue;
            }

            if ($status !== 200) {
                break;
            }

            try {
                return $this->readHtml($body);
            } catch (RuntimeException) {
                return $this->readRendered($uri);
            }
        }

        throw new RuntimeException('offer_unavailable');
    }

    private function readRendered(UriInterface $uri): string
    {
        $binary = config('cvgenerator.browser_executable');
        if (! $binary && PHP_OS_FAMILY === 'Windows') {
            $path = getenv('ProgramFiles(x86)') ?: 'C:/Program Files (x86)';
            $binary = $path.'/Microsoft/Edge/Application/msedge.exe';
        }
        if (! is_string($binary) || ! is_file($binary)) {
            throw new RuntimeException('offer_unavailable');
        }
        $process = new Process([
            (string) config('cvgenerator.browser_node'),
            resource_path('js/offer-browser.mjs'),
            (string) $uri->withFragment(''),
            $binary,
        ], base_path());
        $process->setTimeout(16);
        $process->run();

        return $this->readHtml($process->isSuccessful() ? $process->getOutput() : '');
    }

    /** @return array{int, ?string, string} */
    private function fetch(UriInterface $uri): array
    {
        $host = $uri->getHost();
        $scheme = $uri->getScheme();
        $port = $scheme === 'https' ? 443 : 80;

        if (! in_array($scheme, ['http', 'https'], true) || $uri->getUserInfo() !== '' || $uri->getPort() !== null
            || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/iD', $host)) {
            throw new RuntimeException('offer_unavailable');
        }

        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === [] || array_filter($addresses, fn (string $ip): bool => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE))) {
            throw new RuntimeException('offer_unavailable');
        }

        $body = '';
        $location = null;
        $curl = curl_init((string) $uri->withFragment(''));
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS_STR => 'HTTP,HTTPS',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.$addresses[0]],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => 'CVGenerator/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        if (curl_exec($curl) === false) {
            throw new RuntimeException('offer_unavailable');
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        if ($status === 200 && ! preg_match('~^(text/html|application/xhtml\+xml)(;|$)~i', $type)) {
            throw new RuntimeException('offer_unavailable');
        }

        return [$status, $location, $body];
    }

    public function readHtml(string $html): string
    {
        $document = new DOMDocument;
        if (! @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('offer_unavailable');
        }

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $posting = $this->findPosting(json_decode($node->textContent, true, 48));
            if (! $posting || ! is_string($posting['description'] ?? null)) {
                continue;
            }

            $description = $this->plainText($posting['description']);
            if (mb_strlen($description) >= 100) {
                $title = is_string($posting['title'] ?? null) ? $posting['title'] : '';
                $organization = $posting['hiringOrganization']['name'] ?? '';
                $company = is_string($organization) ? $organization : '';
                $location = $posting['jobLocation'] ?? null;
                if (is_array($location) && array_is_list($location)) {
                    $location = count($location) === 1 ? $location[0] : null;
                }
                $address = $location['address'] ?? null;
                $city = $address['addressLocality'] ?? '';
                $postcode = $address['postalCode'] ?? '';
                $country = $address['addressCountry'] ?? '';
                $place = is_string($city) && filled($city) && in_array($country, ['', 'FR', 'FRA', 'France'], true)
                    ? "\nLieu : ".(is_string($postcode) ? trim($postcode).' ' : '').trim($city)
                    : '';

                return mb_substr("Poste : $title\nEntreprise : $company$place\n\n$description", 0, 30000);
            }
        }

        foreach ($xpath->query('//script | //style | //nav | //footer | //noscript | //iframe | //*[@hidden]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->parentNode?->removeChild($node);
            }
        }

        $description = $this->firstElement($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " show-more-less-html__markup ")]');
        if ($description !== null) {
            $text = $this->plainText((string) $document->saveHTML($description));
            if (mb_strlen($text) >= 100) {
                $jobTitle = $this->firstElement($xpath, '//h1[contains(concat(" ", normalize-space(@class), " "), " top-card-layout__title ")]');
                $organization = $this->firstElement($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " topcard__org-name-link ")]');
                $title = trim($jobTitle->textContent ?? '');
                $company = trim($organization->textContent ?? '');

                return mb_substr("Poste : $title\nEntreprise : $company\n\n$text", 0, 30000);
            }
        }

        $content = $this->firstElement($xpath, '//main//article | //article')
            ?? $this->firstElement($xpath, '//main | //*[@role="main"]')
            ?? $this->firstElement($xpath, '//body');
        $title = $this->firstElement($xpath, '//title')->textContent ?? '';
        $text = $this->plainText($content ? (string) $document->saveHTML($content) : '');
        if (mb_strlen($text) < 180) {
            throw new RuntimeException('offer_unavailable');
        }

        return mb_substr("Page : $title\n\n$text", 0, 30000);
    }

    private function firstElement(DOMXPath $xpath, string $query): ?DOMElement
    {
        foreach ($xpath->query($query) ?: [] as $element) {
            if ($element instanceof DOMElement) {
                return $element;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function findPosting(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        if (in_array('JobPosting', (array) ($data['@type'] ?? []), true)) {
            $expires = $data['validThrough'] ?? null;
            $expiration = is_string($expires) ? strtotime($expires) : false;
            if ($expiration && gmdate('Y-m-d', $expiration) < gmdate('Y-m-d')) {
                throw new RuntimeException('offer_unavailable');
            }

            return $data;
        }

        foreach ($data as $value) {
            if ($posting = $this->findPosting($value)) {
                return $posting;
            }
        }

        return null;
    }

    private function plainText(string $html): string
    {
        $html = (string) preg_replace('~</(?:p|h[1-6]|div|li|section|article)>|<br\s*/?>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
