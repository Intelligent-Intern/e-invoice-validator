<?php
declare(strict_types=1);
namespace IntelligentIntern\EInvoiceValidator;

use IntelligentIntern\EInvoiceValidator\Engine\KingEngine;
use IntelligentIntern\EInvoiceValidator\Engine\TransformEngine;
use IntelligentIntern\EInvoiceValidator\Rules\RuleRegistry;
use IntelligentIntern\EInvoiceValidator\Pdf\EmbeddedInvoiceExtractor;

final class Validator
{
    public function __construct(
        private readonly RuleRegistry $rules,
        private readonly TransformEngine $engine = new KingEngine(),
        private readonly ?EmbeddedInvoiceExtractor $pdfExtractor = null,
        private readonly int $maxInputBytes = 26214400,
    ) {
        if ($maxInputBytes < 1) throw new \InvalidArgumentException('Input size limit must be positive');
    }

    public static function fromBundledRules(bool $extractPdf = false): self
    {
        return new self(new RuleRegistry(dirname(__DIR__) . '/rules'), new KingEngine(),
            $extractPdf ? new EmbeddedInvoiceExtractor() : null);
    }

    public function validateFile(string $path, ?string $profile = null): ValidationReport
    {
        if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Input must be a readable local file');
        // Read once: every stage sees the same immutable input snapshot even if the caller replaces the original.
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \InvalidArgumentException('Cannot open input');
        $hash = hash_init('sha256'); $input = ''; $size = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) throw new \RuntimeException('Input read failed');
                hash_update($hash, $chunk); $size += strlen($chunk);
                if (strlen($input) <= $this->maxInputBytes) {
                    $input .= substr($chunk, 0, max(0, $this->maxInputBytes + 1 - strlen($input)));
                }
            }
        } finally { fclose($handle); }
        $inputHash = hash_final($hash);
        if ($size > $this->maxInputBytes) {
            return new ValidationReport('invalid', $inputHash, null, null, null,
                [new Finding('error', 'INPUT_TOO_LARGE', 'Input exceeds configured byte limit', 'input')], []);
        }
        return $this->validateString($input, $profile);
    }

    public function validateString(string $input, ?string $profile = null): ValidationReport
    {
        $sha = hash('sha256', $input); $syntax = null; $selectedProfile = null; $metadata = null;
        $findings = []; $stages = []; $svrlReports = []; $scope = 'xml-invoice'; $embeddedSha = null; $directory = null;
        $report = static function (string $status) use (&$sha, &$syntax, &$selectedProfile, &$metadata, &$findings, &$stages, &$scope, &$embeddedSha, &$svrlReports): ValidationReport {
            return new ValidationReport($status, $sha, $syntax, $selectedProfile, $metadata, $findings, $stages, $scope, $embeddedSha, $svrlReports);
        };
        if (strlen($input) > $this->maxInputBytes) {
            $findings[] = new Finding('error', 'INPUT_TOO_LARGE', 'Input exceeds configured byte limit', 'input');
            return $report('invalid');
        }
        try {
            $directory = sys_get_temp_dir() . '/eiv-' . bin2hex(random_bytes(16));
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create private validation directory');
            if (str_starts_with($input, '%PDF-')) {
                $scope = 'embedded-xml-only';
                if ($this->pdfExtractor === null) {
                    $findings[] = new Finding('error', 'PDF_EXTRACTION_DISABLED', 'Enable a PDF attachment extractor to validate the embedded invoice XML', 'pdf');
                    return $report('unsupported');
                }
                file_put_contents($directory . '/original.pdf', $input);
                try { $input = $this->pdfExtractor->extract($directory . '/original.pdf', $directory, $this->maxInputBytes); }
                catch (\UnexpectedValueException $exception) {
                    $findings[] = new Finding('error', 'PDF_INVOICE_ATTACHMENT', $exception->getMessage(), 'pdf');
                    return $report('unsupported');
                }
                $embeddedSha = hash('sha256', $input);
                $stages[] = ['id' => 'pdf-extraction', 'status' => 'passed'];
                $findings[] = new Finding('warning', 'PDF_CONTAINER_NOT_VALIDATED',
                    'Only embedded invoice XML is validated. PDF/A, attachment relationships, metadata and visual/XML consistency are not certified.', 'pdf');
            }
            try { $document = Xml::parse($input); }
            catch (\InvalidArgumentException $exception) {
                $findings[] = new Finding('error', 'XML_PARSE', $exception->getMessage(), 'xml');
                $stages[] = ['id' => 'xml', 'status' => 'failed'];
                return $report('invalid');
            }
            $stages[] = ['id' => 'xml', 'status' => 'passed'];
            [$syntax, $guideline] = Xml::identify($document);
            $set = $this->rules->select($syntax, $guideline, $profile);
            if ($set === null) {
                $findings[] = new Finding('error', 'UNSUPPORTED_PROFILE',
                    'No pinned rule package for syntax ' . $syntax . ' and guideline ' . ($guideline ?: '(missing or ambiguous)'), 'profile');
                return $report('unsupported');
            }
            $metadata = $set->metadata(); $selectedProfile = $set->profile['id'];
            $stages[] = ['id' => 'profile', 'status' => 'passed', 'guideline' => $guideline];
            $schemaFindings = $this->validateSchema($document, $set->path($set->profile['schema']));
            array_push($findings, ...$schemaFindings);
            $stages[] = ['id' => 'xsd', 'status' => $schemaFindings === [] ? 'passed' : 'failed'];
            if ($schemaFindings !== []) return $report('invalid');
            $engine = $this->engine->status();
            if (!($engine['available'] ?? false)) {
                $findings[] = new Finding('error', 'ENGINE_UNAVAILABLE', $engine['error'] ?? 'King/SaxonC unavailable', 'engine');
                $stages[] = ['id' => 'engine', 'status' => 'error'];
                return $report('error');
            }
            $xmlPath = $directory . '/invoice.xml';
            if (file_put_contents($xmlPath, $document->saveXML()) === false) throw new \RuntimeException('Cannot write XML snapshot');
            $parser = new SvrlParser();
            foreach ($set->profile['schematron'] as $relative) {
                $stage = 'schematron:' . basename($relative);
                $svrl = $this->engine->transform($xmlPath, $set->path($relative));
                $svrlReports[$relative] = $svrl;
                $stageFindings = $parser->parse($svrl, $stage, $set->profile['severityOverrides'] ?? []);
                array_push($findings, ...$stageFindings);
                $failed = count(array_filter($stageFindings, static fn (Finding $f): bool => $f->severity === 'error')) > 0;
                $stages[] = ['id' => $stage, 'status' => $failed ? 'failed' : 'passed', 'svrlSha256' => hash('sha256', $svrl),
                    'engine' => $engine['engine'] ?? 'king-saxonc'];
            }
            return $report(count(array_filter($findings, static fn (Finding $f): bool => $f->severity === 'error')) > 0 ? 'invalid' : 'valid');
        } catch (\Throwable $exception) {
            $findings[] = new Finding('error', 'VALIDATION_ERROR', $exception->getMessage(), 'runtime');
            $stages[] = ['id' => 'runtime', 'status' => 'error'];
            return $report('error');
        } finally {
            if ($directory !== null && is_dir($directory)) {
                foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
                rmdir($directory);
            }
        }
    }

    /** @return list<Finding> */
    private function validateSchema(\DOMDocument $document, string $schema): array
    {
        $previous = libxml_use_internal_errors(true); libxml_clear_errors();
        try {
            $passed = $document->schemaValidate($schema, LIBXML_SCHEMA_CREATE);
            if ($passed) return [];
            $findings = [];
            foreach (libxml_get_errors() as $error) {
                $findings[] = new Finding('error', 'XSD_' . $error->code, trim($error->message), 'xsd', 'line ' . $error->line);
            }
            return $findings ?: [new Finding('error', 'XSD_FAILURE', 'XML schema validation failed', 'xsd')];
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
}
