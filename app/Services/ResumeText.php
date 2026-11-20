<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use ZipArchive;

class ResumeText
{
    public function extract(UploadedFile $file): string
    {
        $path = $file->getPathname();
        $text = match (strtolower($file->getClientOriginalExtension())) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'txt' => file_get_contents($path),
            default => throw new RuntimeException('Format de CV non pris en charge.'),
        };

        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('Le texte du CV est illisible.');
        }

        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text));

        if (mb_strlen($text) < 60 || mb_strlen($text) > 30000) {
            throw new RuntimeException('Le CV doit contenir entre 60 et 30 000 caractères lisibles. Pour un scan, collez le texte du CV.');
        }

        return $text;
    }

    public function pdf(string $path): string
    {
        if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            throw new RuntimeException('Ce fichier ne contient pas un PDF valide.');
        }

        $script = 'require $argv[1]; $pdf = (new \Smalot\PdfParser\Parser)->parseFile($argv[2]); echo mb_substr($pdf->getText(), 0, 60000);';
        $binary = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY).'/php.exe' : PHP_BINDIR.'/php';
        $process = new Process([$binary, '-d', 'memory_limit=128M', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $script, base_path('vendor/autoload.php'), $path]);
        $process->setTimeout(12);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('L’extraction du PDF a dépassé le délai autorisé.');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Ce PDF est endommagé, protégé ou illisible.');
        }

        return $process->getOutput();
    }

    public function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Ce fichier ne contient pas un DOCX valide.');
        }

        try {
            $entry = $zip->statName('word/document.xml');
            if (! $entry || $entry['size'] > 2_000_000) {
                throw new RuntimeException('Le document Word est trop complexe ou illisible.');
            }

            $xml = $zip->getFromName('word/document.xml', 2_000_001);
        } finally {
            $zip->close();
        }

        $document = new DOMDocument;
        if (! is_string($xml) || stripos($xml, '<!DOCTYPE') !== false || ! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $document->doctype) {
            throw new RuntimeException('Le document Word est illisible.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];

        foreach ($xpath->query('//w:body//w:p') ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }
            $line = '';
            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $line .= $node->textContent;
                }
            }
            $paragraphs[] = $line;
        }

        return implode("\n", $paragraphs);
    }
}
