<?php

namespace App\Services;

use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ReportPdfVerifier
{
    public function verify(string $bytes, string $html, int $remainingSeconds): void
    {
        if ($remainingSeconds < 1 || ! str_starts_with($bytes, '%PDF-') || ! str_ends_with(rtrim($bytes), '%%EOF')) {
            throw new RuntimeException('PDF verification failed.');
        }
        $configured = config('services.gotenberg.pdftotext', 'pdftotext');
        $binary = is_file($configured) ? realpath($configured) : (new ExecutableFinder)->find($configured, null,
            PHP_OS_FAMILY === 'Windows' ? [getenv('ProgramFiles').'/Git/mingw64/bin'] : []);
        if (! $binary) {
            throw new RuntimeException('PDF text extraction is unavailable.');
        }
        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('PDF verification storage is unavailable.');
        }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file)) {
                throw new RuntimeException('PDF verification storage is unavailable.');
            }
            $path = stream_get_meta_data($file)['uri'] ?? throw new RuntimeException('PDF verification storage is unavailable.');
            $process = new Process([$binary, '-raw', '-enc', 'UTF-8', $path, '-'], timeout: min(10, $remainingSeconds));
            $process->run();
        } finally {
            fclose($file);
        }
        if (! $process->isSuccessful() || trim($process->getErrorOutput()) !== '') {
            throw new RuntimeException('PDF text extraction failed.');
        }

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        foreach (iterator_to_array($dom->getElementsByTagName('nav')) as $nav) {
            $nav->parentNode?->removeChild($nav);
        }
        $main = $dom->getElementsByTagName('main')->item(0);
        if (! $main) {
            throw new RuntimeException('Report preview is missing.');
        }
        $nodes = [];
        foreach ($xpath->query('.//text()', $main) ?: [] as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node->textContent;
            }
        }
        if ($this->amounts(implode(' ', $nodes)) !== $this->amounts($process->getOutput())) {
            throw new RuntimeException('PDF amounts differ from the saved report.');
        }
        $headers = [];
        foreach ($main->getElementsByTagName('thead') as $head) {
            $headers[] = $this->normalize($head->textContent);
        }
        $expected = $this->normalize($main->textContent);
        $offset = 0;
        foreach (explode("\f", $process->getOutput()) as $page) {
            $page = $this->normalize($page);
            if ($page === '') {
                continue;
            }
            if ($offset > 0 && substr_compare($expected, $page, $offset, strlen($page)) !== 0) {
                // Chromium repeats the column headings when a table spans pages.
                foreach ($headers as $header) {
                    if ($header !== '' && str_starts_with($page, $header)) {
                        $page = substr($page, strlen($header));
                        break;
                    }
                }
            }
            if ($page === '' || substr_compare($expected, $page, $offset, strlen($page)) !== 0) {
                throw new RuntimeException('PDF content differs from the saved report.');
            }
            $offset += strlen($page);
        }
        if ($expected === '' || $offset !== strlen($expected)) {
            throw new RuntimeException('PDF content is incomplete.');
        }
    }

    private function normalize(string $text): string
    {
        // Ignore glyph spacing, line wrapping, and list bullets introduced by printing.
        return preg_replace('/[\s\p{Z}\x{00ad}\x{200b}\x{feff}•]+/u', '', $text) ?? throw new RuntimeException('Invalid report text.');
    }

    /** @return list<string> */
    private function amounts(string $text): array
    {
        preg_match_all('/(?<![\d.])-?\d[\d,]*\.\d{2}(?![\d.])/', $text, $matches);

        return $matches[0];
    }
}
