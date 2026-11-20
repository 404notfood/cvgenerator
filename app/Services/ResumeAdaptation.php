<?php

namespace App\Services;

class ResumeAdaptation
{
    /**
     * @param  list<array<string, string>>  $requirements
     * @return array{cv: string, requirements: list<array<string, string>>}
     */
    public function apply(string $cv, string $source, array $requirements): array
    {
        $requirements = $this->classifyRequirements($requirements);
        $proofs = [];
        foreach ($requirements as &$criterion) {
            if ($criterion['match'] === 'missing' || ! $this->contains($source, $criterion['evidence'])
                || $this->missingNamedCredential($criterion)) {
                $criterion['match'] = 'missing';
                $criterion['evidence'] = '';
                $criterion['cv_excerpt'] = '';

                continue;
            }

            if ($criterion['match'] === 'matched' && $criterion['importance'] === 'required'
                && $this->shorterThanRequired($criterion['quote'], $criterion['evidence'])) {
                $criterion['match'] = 'partial';
            }

            if (mb_strlen(trim($criterion['cv_excerpt'])) <= 4) {
                $criterion['cv_excerpt'] = rtrim(trim($criterion['cv_excerpt']), '.');
            }
            if ($this->contains($cv, $criterion['cv_excerpt'])) {
                continue;
            }

            $evidence = trim((string) preg_replace('/\s+/u', ' ', $criterion['evidence']));
            $criterion['cv_excerpt'] = $evidence;
            if (! $this->contains($cv, $evidence)) {
                $proofs[$this->normalize($evidence)] = str_starts_with($evidence, '- ') ? $evidence : '- '.$evidence;
            }
        }
        unset($criterion);

        if ($proofs !== []) {
            $lines = preg_split('/\R/u', trim($cv)) ?: [];
            $english = preg_match('/^\h*(?:profile|work experience|professional experience|skills|education)\h*:?\h*$/imu', $cv) === 1;
            $heading = $english ? 'Key strengths' : 'Atouts pour le poste';
            $position = count($lines);
            foreach ($lines as $index => $line) {
                $label = rtrim($this->normalize($line), ': ');
                if ($label === mb_strtolower($heading)) {
                    $heading = '';
                    $position = $index + 1;
                    break;
                }
                if (in_array($label, ['expérience professionnelle', 'expériences professionnelles', 'work experience', 'professional experience', 'compétences', 'skills', 'formation', 'education'], true)) {
                    $position = $index;
                    break;
                }
            }

            $section = $heading === '' ? array_values($proofs) : ['', $heading, ...array_values($proofs), ''];
            array_splice($lines, $position, 0, $section);
            $cv = trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
        }

        return ['cv' => $this->prioritizeEvidence($cv, $requirements), 'requirements' => $requirements];
    }

    /** @param array<string, string> $criterion */
    private function missingNamedCredential(array $criterion): bool
    {
        return $criterion['importance'] === 'required'
            && preg_match('/^(?:certification|certificat|habilitation|certificate)\h+(.+)$/iu', trim($criterion['label']), $parts) === 1
            && $this->contains($criterion['quote'], $criterion['label'])
            && ! $this->contains($criterion['evidence'], $parts[1]);
    }

    public function restoreContacts(string $cv, string $source): string
    {
        $sourceHeader = implode("\n", array_slice(preg_split('/\R/u', trim($source)) ?: [], 0, 12));
        $pattern = '/[\w.+-]+@[\w.-]+\.[a-z]{2,}\b|(?<!\d)(?:\+\d{1,3}(?:[ .-]?\d){8,12}|0[1-9](?:[ .-]?\d{2}){4})(?!\d)/iu';
        preg_match_all($pattern, $sourceHeader, $contacts);
        preg_match_all($pattern, $cv, $draftContacts);
        $contactKey = fn (string $contact): string => str_contains($contact, '@')
            ? mb_strtolower($contact)
            : (string) preg_replace('/\D/', '', $contact);
        $present = array_map($contactKey, $draftContacts[0]);
        $missing = array_values(array_filter(array_unique($contacts[0]), fn (string $contact): bool => ! in_array($contactKey($contact), $present, true)));
        if ($missing === []) {
            return $cv;
        }

        $lines = preg_split('/\R/u', $cv) ?: [];
        $position = array_search('', array_map(trim(...), $lines), true);
        array_splice($lines, $position === false ? 1 : max(1, $position), 0, [implode(' · ', $missing)]);

        return implode("\n", $lines);
    }

    /** @param list<array<string, string>> $requirements */
    private function prioritizeEvidence(string $cv, array $requirements): string
    {
        $weights = [];
        foreach ($requirements as $criterion) {
            if ($criterion['match'] === 'missing' || $criterion['cv_excerpt'] === '') {
                continue;
            }
            $weight = match ($criterion['importance']) {
                'required' => 6,
                'mission' => 4,
                default => 2,
            };
            $excerpt = $this->normalize($criterion['cv_excerpt']);
            $weights[$excerpt] = max($weights[$excerpt] ?? 0, $criterion['match'] === 'matched' ? $weight : intdiv($weight, 2));
        }

        if ($weights === []) {
            return $cv;
        }

        $lines = preg_split('/\R/u', $cv) ?: [];
        $start = 0;
        $group = [];
        foreach ([...$lines, ''] as $index => $line) {
            if (preg_match('/^\h*[-–•]\h+/u', $line) === 1) {
                if ($group === []) {
                    $start = $index;
                }
                $normalized = $this->normalize($line);
                $score = 0;
                foreach ($weights as $excerpt => $weight) {
                    if ($this->containsNormalized($normalized, (string) $excerpt)) {
                        $score = max($score, $weight);
                    }
                }
                $group[] = ['text' => $line, 'score' => $score];

                continue;
            }

            if (count($group) > 1) {
                usort($group, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
                array_splice($lines, $start, count($group), array_column($group, 'text'));
            }
            $group = [];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, string>>  $requirements
     * @return list<array<string, string>>
     */
    public function classifyRequirements(array $requirements): array
    {
        foreach ($requirements as &$criterion) {
            if ($criterion['importance'] !== 'required') {
                continue;
            }
            $quote = $this->normalize($criterion['quote']);
            if (preg_match('/\b(?:non\s+(?:requis|exigé|obligatoire)|souhaité(?:e|es|s)?|serait un plus|preferred|nice to have|not required)\b/u', $quote) === 1) {
                $criterion['importance'] = 'preferred';

                continue;
            }
            if (preg_match('/\b(?:obligatoire(?:s|ment)?|impérati(?:f|ve)s?|exig(?:é(?:e|es|s)?|ence(?:s)?)|requis(?:e|es)?|prérequis|indispensable(?:s)?|minimum|au moins|vous justifiez|must have|required|essential|at least)\b/u', $quote) !== 1) {
                $criterion['importance'] = 'mission';
            }
        }
        unset($criterion);

        return $requirements;
    }

    public function contains(string $text, string $quote): bool
    {
        return $this->containsNormalized($this->normalize($text), $this->normalize($quote));
    }

    private function containsNormalized(string $text, string $quote): bool
    {
        if (mb_strlen($quote) >= 5) {
            return str_contains($text, $quote);
        }

        $quote = rtrim($quote, '.');

        return preg_match('/^(?=.*[a-z])[a-z0-9+#.]{2,4}$/D', $quote) === 1
            && preg_match('/(?<![\pL\pN_+#])'.preg_quote($quote, '/').'(?![\pL\pN_+#])/u', $text) === 1;
    }

    private function shorterThanRequired(string $offerQuote, string $evidence): bool
    {
        $pattern = '/(?<![\d.,-])(\d{1,2})\s*\+?\s*(?:ans?|années?|years?)\b/iu';
        if (preg_match_all($pattern, $offerQuote, $required) !== 1 || preg_match_all($pattern, $evidence, $provided) !== 1) {
            return false;
        }

        return (int) $provided[1][0] < (int) $required[1][0];
    }

    public function normalize(string $text): string
    {
        $text = str_replace(["\u{00AD}", "\u{200B}", '’', '‘', 'ﬁ', 'ﬂ'], ['', '', "'", "'", 'fi', 'fl'], $text);

        return trim(mb_strtolower((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
