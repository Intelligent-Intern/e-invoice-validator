<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator\Pdf;

/** Poppler is used only to extract a named attachment; no PDF/A conformance is inferred. */
final class EmbeddedInvoiceExtractor
{
    public function __construct(private readonly string $binary = 'pdfdetach', private readonly int $timeoutSeconds = 15) {}

    public function extract(string $path, string $directory, int $maxBytes): string
    {
        $listing = $this->run([$this->binary, '-list', $path]);
        $candidates = [];
        foreach (explode("\n", $listing) as $line) {
            if (preg_match('/^\s*(\d+):\s*(.*?)\s*$/', $line, $match)
                && in_array(strtolower($match[2]), ['factur-x.xml', 'zugferd-invoice.xml', 'xrechnung.xml', 'zugferd.xml'], true)) {
                $candidates[] = (int) $match[1];
            }
        }
        if (count($candidates) !== 1) throw new \UnexpectedValueException('Expected exactly one recognised embedded invoice XML attachment');
        $target = $directory . '/embedded-invoice.xml';
        $this->run([$this->binary, '-save', (string) $candidates[0], '-o', $target, $path]);
        if (!is_file($target) || filesize($target) > $maxBytes) throw new \UnexpectedValueException('Embedded invoice missing or exceeds size limit');
        $content = file_get_contents($target);
        if ($content === false || $content === '') throw new \UnexpectedValueException('Embedded invoice is empty');
        return $content;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start Poppler pdfdetach');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true); $out = ''; $err = ''; $exit = null;
        try {
            while (true) {
                $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
                if (strlen($out) + strlen($err) > 1048576) throw new \RuntimeException('PDF extraction output exceeds limit');
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                if (microtime(true) - $start > $this->timeoutSeconds) throw new \RuntimeException('PDF extraction timed out');
                usleep(10000);
            }
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
            if ($exit !== 0) throw new \RuntimeException('PDF extraction failed: ' . trim(substr($err, 0, 1000)));
            return $out;
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) proc_terminate($process, 9);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
    }
}
