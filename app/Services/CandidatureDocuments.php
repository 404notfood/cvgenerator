<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Frame;
use Dompdf\Options;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use RuntimeException;
use Throwable;

class CandidatureDocuments
{
    public const ASSESSMENT_VERSION = 4;

    public const DEFAULT_PDF_TEMPLATE = 'classique';

    public const PDF_TEMPLATES = [
        self::DEFAULT_PDF_TEMPLATE => 'Classique',
        'editorial' => 'Éditorial',
        'minimal' => 'Minimal',
        'marine' => 'Marine',
        'sauge' => 'Sauge',
        'terracotta' => 'Terracotta',
        'signature' => 'Signature',
        'chronologie' => 'Chronologie',
        'ardoise' => 'Ardoise',
        'studio' => 'Studio',
    ];

    public function __construct(private ResumeAdaptation $adaptation) {}

    /** @var list<string> */
    private const HEADINGS = [
        'Profil', 'Résumé', 'Expérience professionnelle', 'Expériences professionnelles',
        'Compétences', 'Compétences clés', 'Atouts pour le poste', 'Formation', 'Formations', 'Langues', 'Certifications', 'Projets',
        'Profile', 'Summary', 'Work Experience', 'Professional Experience', 'Skills', 'Education', 'Languages', 'Projects',
        'Key strengths', 'Key skills',
    ];

    public function export(string $text, string $kind, string $format, string $template = self::DEFAULT_PDF_TEMPLATE): string
    {
        $lines = $this->lines($text, $kind === 'cv');
        if ($format === 'pdf') {
            $pdf = $this->pdf($lines, $kind === 'cv', $template)['document'];
            if ($kind === 'cv' && $pdf->getCanvas()->get_page_count() !== 1) {
                throw new CvPageOverflow;
            }

            return $pdf->output();
        }

        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $cv = $kind === 'cv';
        $word->setDefaultFontSize(10);
        $section = $word->addSection(['paperSize' => 'A4', 'marginTop' => 740, 'marginBottom' => 740, 'marginLeft' => 850, 'marginRight' => 850]);
        foreach ($lines as $line) {
            if ($line['type'] === 'space') {
                if (! $cv) {
                    $section->addTextBreak();
                }

                continue;
            }
            $type = $line['type'];
            $emphasized = in_array($type, ['name', 'heading', 'headline', 'position'], true);
            $size = match ($type) {
                'name' => 27,
                'headline' => 13,
                'heading', 'position' => 11,
                'contact' => 9,
                default => 10,
            };
            $section->addText($line['text'],
                ['bold' => $emphasized, 'size' => $size, 'color' => $type === 'heading' ? '216158' : '253345'],
                [
                    'spaceBefore' => match ($type) {
                        'heading' => 145,
                        'position' => 75,
                        default => 0,
                    },
                    'spaceAfter' => $cv ? 45 : 65,
                    'keepNext' => $emphasized,
                    'indentation' => $type === 'bullet' ? ['left' => 160, 'hanging' => 140] : [],
                ]);
        }

        return $this->temporary(function (string $path) use ($word): string {
            IOFactory::createWriter($word, 'Word2007')->save($path);

            return file_get_contents($path) ?: throw new RuntimeException('Export Word indisponible.');
        });
    }

    /**
     * @param  list<array{text: string, type: string}>  $lines
     * @return array{document: Dompdf, compact: bool, sidebarFrom: ?int}
     */
    private function pdf(array $lines, bool $cv, string $template = self::DEFAULT_PDF_TEMPLATE): array
    {
        $render = function (bool $compact, ?int $sidebarFrom) use ($lines, $cv, $template): array {
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]);
            $pdf = new Dompdf($options);
            $pdf->setPaper('A4');
            $fits = $sidebarFrom === null;
            if ($sidebarFrom !== null) {
                $pdf->setCallbacks([['event' => 'end_frame', 'f' => function (Frame $frame) use ($pdf, $compact, &$fits): void {
                    $node = $frame->get_node();
                    if (! $node instanceof \DOMElement || $node->getAttribute('id') !== 'cv-columns') {
                        return;
                    }
                    [, $top, , $height] = $frame->get_border_box();
                    $fits = $top + $height <= $pdf->getCanvas()->get_height() - ($compact ? 29 : 40);
                }]]);
            }
            $pdf->loadHtml(view('documents.candidature', [...compact('lines', 'cv', 'compact', 'template', 'sidebarFrom'), 'preview' => false])->render(), 'UTF-8');
            $pdf->render();

            return ['document' => $pdf, 'fits' => $fits && $pdf->getCanvas()->get_page_count() === 1];
        };

        $sidebarFrom = in_array($template, ['marine', 'sauge', 'ardoise'], true) ? $this->sidebarStart($lines) : null;
        foreach ([false, true] as $compact) {
            $layout = $render($compact, $sidebarFrom);
            if (! $cv || $layout['fits']) {
                return ['document' => $layout['document'], 'compact' => $compact, 'sidebarFrom' => $sidebarFrom];
            }
        }

        return ['document' => $sidebarFrom === null ? $layout['document'] : $render(true, null)['document'], 'compact' => true, 'sidebarFrom' => null];
    }

    public function preview(string $text, string $kind, string $template = self::DEFAULT_PDF_TEMPLATE): string
    {
        $cv = $kind === 'cv';
        $lines = $this->lines($text, $cv);
        $compact = false;
        $sidebarFrom = null;
        if ($cv) {
            $layout = $this->pdf($lines, true, $template);
            if ($layout['document']->getCanvas()->get_page_count() !== 1) {
                throw new CvPageOverflow;
            }
            $compact = $layout['compact'];
            $sidebarFrom = $layout['sidebarFrom'];
        }

        return view('documents.candidature', [...compact('lines', 'cv', 'compact', 'template', 'sidebarFrom'), 'preview' => true])->render();
    }

    /** @param list<array{text: string, type: string}> $lines */
    private function sidebarStart(array $lines): ?int
    {
        $start = null;
        $mainHeading = false;
        foreach ($lines as $index => $line) {
            if ($line['type'] !== 'heading') {
                continue;
            }
            $label = rtrim($this->adaptation->normalize($line['text']), ': ');
            if (in_array($label, ['compétences', 'compétences clés', 'formation', 'formations', 'langues', 'certifications', 'skills', 'key skills', 'education', 'languages'], true)) {
                $start ??= $mainHeading ? $index : null;
            } else {
                $mainHeading = true;
                $start = null;
            }
        }

        return $start;
    }

    /**
     * @param  array<int, array<string, string>>  $requirements
     * @return array{version: int, score: ?int, grade: ?string, readability: int, checks: list<array{label: string, earned: int, max: int}>, coverage: ?int, pages: ?int, blockers: list<array{label: string, quote: string, match: string}>}
     */
    public function assess(string $cv, array $requirements, string $format, ResumeText $reader): array
    {
        $pdf = $format === 'pdf' ? $this->pdf($this->lines($cv, true), true)['document'] : null;
        $pages = $pdf?->getCanvas()->get_page_count();
        $file = $pdf ? $pdf->output() : $this->export($cv, 'cv', $format);
        $extracted = $this->temporary(function (string $path) use ($file, $format, $reader): string {
            file_put_contents($path, $file);

            return $format === 'pdf' ? $reader->pdf($path) : $reader->docx($path);
        });

        $rendered = $this->adaptation->normalize($extracted);
        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $cv) ?: [])));
        $position = 0;
        $retained = 0;
        foreach ($lines as $line) {
            $line = $this->adaptation->normalize($line);
            $found = $line === '' ? false : mb_strpos($rendered, $line, $position);
            if ($found !== false) {
                $retained++;
                $position = $found + mb_strlen($line);
            }
        }

        $readable = mb_strlen($rendered) >= 60;
        $contacts = preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $rendered) === 1
            || preg_match('/(?:\+?\d[\s().-]*){9,}/', $rendered) === 1;
        $headingCount = count(array_filter($lines, fn (string $line): bool => $this->isHeading($line)
            && str_contains($rendered, $this->adaptation->normalize($line))));

        $total = $covered = $profileMatch = 0;
        $blockers = [];
        foreach ($this->adaptation->classifyRequirements(array_values($requirements)) as $requirement) {
            $weight = $requirement['importance'] === 'required' ? 2 : 1;
            $total += $weight;
            $present = $this->adaptation->contains($rendered, $requirement['cv_excerpt']);
            $match = $requirement['match'];
            $credit = match ($match) {
                'matched' => $weight,
                'partial' => $weight / 2,
                default => 0,
            };
            $profileMatch += $credit;
            $covered += $present ? $credit : 0;
            if ($requirement['importance'] === 'required' && ($match !== 'matched' || ! $present)) {
                $blockers[] = [
                    'label' => $requirement['label'],
                    'quote' => $requirement['quote'],
                    'match' => $match === 'matched' ? 'missing_from_export' : $match,
                ];
            }
        }

        $checks = [
            ['label' => 'Texte et ordre de lecture', 'earned' => $readable ? (int) round(60 * $retained / max(1, count($lines))) : 0, 'max' => 60],
            ['label' => 'Rubriques identifiables', 'earned' => min(25, $headingCount * 9), 'max' => 25],
            ['label' => 'Coordonnées lisibles', 'earned' => $contacts ? 15 : 0, 'max' => 15],
        ];
        $readability = array_sum(array_column($checks, 'earned'));
        if (! $readable || ! $contacts || $headingCount < 2 || $retained < count($lines) * 0.85) {
            $readability = min(89, $readability);
        }
        $exportCoverage = $total > 0 ? (int) round(100 * $covered / $total) : null;
        $score = $exportCoverage === null ? null : min($readability, $exportCoverage, $blockers ? 79 : 100, $pages !== null && $pages > 1 ? 79 : 100);

        return [
            'version' => self::ASSESSMENT_VERSION,
            'score' => $score,
            'readability' => $readability,
            'pages' => $pages,
            'blockers' => $blockers,
            'grade' => match (true) {
                $score === null => null,
                $score >= 90 => 'A',
                $score >= 75 => 'B',
                $score >= 60 => 'C',
                default => 'D',
            },
            'checks' => $checks,
            'coverage' => $total > 0 ? (int) round(100 * $profileMatch / $total) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function withAssessments(array $result, ResumeText $reader): array
    {
        $result['requirements'] = $this->adaptation->classifyRequirements($result['requirements']);
        foreach (['pdf', 'docx'] as $format) {
            try {
                $result['assessments'][$format] = $this->assess($result['cv'], $result['requirements'], $format, $reader);
            } catch (Throwable) {
                $result['assessments'][$format] = null;
            }
        }
        $result['assessment_version'] = self::ASSESSMENT_VERSION;

        return $result;
    }

    private function isHeading(string $line): bool
    {
        return in_array(rtrim($this->adaptation->normalize($line), ': '), array_map(mb_strtolower(...), self::HEADINGS), true);
    }

    /** @return list<array{text: string, type: string}> */
    private function lines(string $text, bool $cv): array
    {
        $header = $cv;
        $section = '';
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $index => $line) {
            $line = trim($line);
            $heading = $cv && $this->isHeading($line);
            if ($index > 0 && ($line === '' || $heading)) {
                $header = false;
            }
            if ($heading) {
                $section = rtrim($this->adaptation->normalize($line), ': ');
            }
            $bullet = preg_match('/^[-–•]\h/u', $line) === 1;
            $lines[] = ['text' => $line, 'type' => match (true) {
                $line === '' => 'space',
                $cv && $index === 0 => 'name',
                $heading => 'heading',
                $header && preg_match('~@|https?://|www\.|(?:\+?\d[ .()-]*){9,}~i', $line) === 1 => 'contact',
                $header => 'headline',
                $bullet => 'bullet',
                in_array($section, ['expérience professionnelle', 'expériences professionnelles', 'work experience', 'professional experience'], true)
                    && preg_match('/\h\|\h/u', $line) === 1 => 'position',
                default => 'body',
            }];
        }

        return $lines;
    }

    /** @template T
     * @param  callable(string): T  $callback
     * @return T
     */
    private function temporary(callable $callback): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'cvg-');
        if ($path === false) {
            throw new RuntimeException('Fichier temporaire indisponible.');
        }

        try {
            return $callback($path);
        } finally {
            unlink($path);
        }
    }
}
