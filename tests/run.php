<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use IntelligentIntern\EInvoiceValidator\{Validator, SvrlParser, Xml, Finding};
use IntelligentIntern\EInvoiceValidator\Engine\{KingEngine, TransformEngine};
use IntelligentIntern\EInvoiceValidator\Rules\RuleRegistry;

$checks = 0; $skipped = [];
function check(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}
function codes($report): array { return array_map(static fn(Finding $f): string => $f->code, $report->findings); }
require __DIR__.'/runtime-policy.php';
$validator = Validator::fromBundledRules();
$fixtureRoot = __DIR__ . '/fixtures/upstream/xrechnung/instances/standard';
$fixture = file_get_contents($fixtureRoot . '/01.01a-INVOICE_ubl.xml');
$empty = $validator->validateString('');
check($empty->status === 'invalid' && in_array('XML_PARSE', codes($empty), true)
    && $empty->stages === [['id' => 'xml', 'status' => 'failed']], 'empty input is malformed XML rather than a runtime failure');
check($validator->validateString('<Invoice>')->status === 'invalid', 'malformed XML must fail');
$xxe = '<!DOCTYPE Invoice [<!ENTITY secret SYSTEM "file:///etc/passwd">]><Invoice>&secret;</Invoice>';
$unsafe = $validator->validateString($xxe);
check($unsafe->status === 'invalid' && in_array('XML_PARSE', codes($unsafe), true), 'external entity must be rejected');
check(!str_contains($unsafe->toJson(), 'root:x:'), 'entity content must never leak');
$utf16 = "\xff\xfe" . implode("\0", str_split('<?xml version="1.0" encoding="UTF-16"?>' . $xxe)) . "\0";
check($validator->validateString($utf16)->status === 'invalid', 'UTF-16 DTD must also be rejected');
$previousErrors = libxml_use_internal_errors(true);
libxml_clear_errors();
foreach ([$xxe, '<!DOCTYPE Invoice [<!ENTITY % bad "<!ENTITY"> %bad;]><Invoice/>', $utf16,
          '<?xml version="1.0" encoding="UTF-7"?>+ADw-+ACE-DOCTYPE Invoice+AD4-+ADw-Invoice/+AD4-',
          '<?xml version="1.0" encoding="ISO-8859-1"?><Invoice/>',
          "<Invoice>\x00</Invoice>", "<Invoice>\xff</Invoice>"] as $prohibited) {
    try { Xml::parse($prohibited); check(false, 'prohibited input must fail before libxml'); }
    catch (InvalidArgumentException $expected) {
        check(count(libxml_get_errors()) === 0, 'prohibited input must never enter the native XML parser');
    }
}
libxml_use_internal_errors($previousErrors);
check(Xml::parse("\xef\xbb\xbf<Invoice>Grüße &amp; Gebühren</Invoice>")->documentElement->textContent === 'Grüße & Gebühren',
    'UTF-8 BOM, Unicode invoice text and predefined XML entities remain supported');
check($validator->validateString('<Invoice xmlns="urn:evil"/>')->status === 'unsupported', 'unrecognised namespace must not pass');
check($validator->validateString(str_replace('xrechnung_3.0', 'xrechnung_99.0', $fixture))->status === 'unsupported', 'unsupported guideline must not inherit newer or older rules');
check($validator->validateString($fixture, 'missing-profile')->status === 'unsupported', 'explicit profile must match actual guideline');
$missingId = Xml::parse($fixture); $xp = new DOMXPath($missingId);
$xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
$node = $xp->query('/*/cbc:ID')->item(0); $node->parentNode->removeChild($node);
$schemaFailure = $validator->validateString($missingId->saveXML());
check($schemaFailure->status === 'invalid', 'missing mandatory invoice ID must fail XSD');
check(count(array_filter($schemaFailure->findings, static fn(Finding $f): bool => $f->stage === 'xsd')) > 0, 'XSD failure must be identified');
$small = new Validator(new RuleRegistry(__DIR__ . '/../rules'), maxInputBytes: 8);
check(in_array('INPUT_TOO_LARGE', codes($small->validateString('123456789')), true), 'bounded input size');
check($validator->validateString('%PDF-1.7 example')->status === 'unsupported', 'PDF must not silently pass as fully validated');
$unavailable = new class implements TransformEngine {
    public function status(): array { return ['available' => false, 'error' => 'test engine unavailable']; }
    public function transform(string $xmlPath, string $stylesheetPath): string { throw new RuntimeException('must not run'); }
};
$missingEngine = (new Validator(new RuleRegistry(__DIR__ . '/../rules'), $unavailable))->validateString($fixture);
check($missingEngine->status === 'error' && in_array('ENGINE_UNAVAILABLE', codes($missingEngine), true), 'missing engine cannot produce valid');
$parser = new SvrlParser();
$svrl = '<svrl:schematron-output xmlns:svrl="http://purl.oclc.org/dsdl/svrl"><svrl:active-pattern/><svrl:fired-rule/><svrl:failed-assert id="BR-TEST" flag="fatal" location="/Invoice" test="false()"><svrl:text>Bad amount</svrl:text><svrl:diagnostic-reference>Expected 42</svrl:diagnostic-reference></svrl:failed-assert><svrl:failed-assert id="WARN" flag="warning"><svrl:text>Check me</svrl:text></svrl:failed-assert><svrl:successful-report id="REPORT" role="error"><svrl:text>Reported error</svrl:text></svrl:successful-report></svrl:schematron-output>';
$findings = $parser->parse($svrl, 'test');
check(count($findings) === 3 && $findings[0]->severity === 'error' && $findings[1]->severity === 'warning' && $findings[2]->severity === 'error', 'all SVRL result types and severities must be parsed');
check($findings[0]->message === 'Bad amount Expected 42' && $findings[0]->test === 'false()', 'SVRL diagnostics, location and test retained');
check($parser->parse($svrl, 'test', ['BR-TEST' => 'information'])[0]->severity === 'info', 'official scenario severity customisation applied');
try { $parser->parse('<ok/>', 'test'); check(false, 'wrong result must be rejected'); }
catch (RuntimeException $expected) { check(true, 'wrong result rejected'); }
try { $parser->parse('<svrl:schematron-output xmlns:svrl="http://purl.oclc.org/dsdl/svrl"/>', 'test'); check(false, 'unexecuted rules must not pass'); }
catch (RuntimeException $expected) { check(true, 'empty SVRL does not establish validation coverage'); }
$badTransform = new class implements TransformEngine {
    public function status(): array { return ['available' => true]; }
    public function transform(string $xmlPath, string $stylesheetPath): string { return '<not-svrl/>'; }
};
check((new Validator(new RuleRegistry(__DIR__ . '/../rules'), $badTransform))->validateString($fixture)->status === 'error', 'successful transformation is not proof of invoice validity');
$tempRules = sys_get_temp_dir() . '/eiv-rule-test-' . bin2hex(random_bytes(8));
mkdir($tempRules, 0700);
try {
    $resource = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>';
    file_put_contents($tempRules . '/schema.xsd', $resource);
    file_put_contents($tempRules . '/rules.xsl', '<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="3.0"/>');
    $files = ['schema.xsd' => hash_file('sha256', $tempRules . '/schema.xsd'), 'rules.xsl' => hash_file('sha256', $tempRules . '/rules.xsl')];
    $manifest = ['version' => 1, 'packages' => [['id' => 'test', 'version' => '1', 'source' => 'test', 'files' => $files,
        'profiles' => [['id' => 'test', 'syntax' => 'cii', 'guidelineIds' => ['test'], 'schema' => 'schema.xsd', 'schematron' => ['rules.xsl']]]]]];
    file_put_contents($tempRules . '/manifest.json', json_encode($manifest));
    check((new RuleRegistry($tempRules))->select('cii', 'test') !== null, 'pinned local rule package accepted');
    file_put_contents($tempRules . '/schema.xsd', $resource . ' ');
    try { (new RuleRegistry($tempRules))->select('cii', 'test'); check(false, 'tampered rules must fail'); }
    catch (RuntimeException $expected) { check(str_contains($expected->getMessage(), 'hash mismatch'), 'rule hash change detected'); }
} finally { foreach (glob($tempRules . '/*') as $file) unlink($file); rmdir($tempRules); }
$engine = new KingEngine();
if (($engine->status()['available'] ?? false) === true) {
    $fixtures = [...glob($fixtureRoot . '/*.xml'), __DIR__ . '/fixtures/derived-creditnote.xml'];
    $syntaxes = [];
    foreach ($fixtures as $file) {
        $report = $validator->validateFile($file);
        check($report->status === 'valid', basename($file) . ' official positive: ' . $report->toJson());
        check($report->inputSha256 === hash_file('sha256', $file), 'immutable input hash');
        $syntaxes[$report->syntax] = true;
    }
    check(isset($syntaxes['ubl-invoice'], $syntaxes['ubl-creditnote'], $syntaxes['cii']), 'positive suite covers all three syntaxes');
    foreach ([$fixtureRoot . '/01.01a-INVOICE_ubl.xml', $fixtureRoot . '/01.01a-INVOICE_uncefact.xml', __DIR__ . '/fixtures/derived-creditnote.xml'] as $file) {
        $plainEn16931 = str_replace('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', 'urn:cen.eu:en16931:2017', file_get_contents($file));
        $enReport = $validator->validateString($plainEn16931);
        check($enReport->status === 'valid' && str_starts_with($enReport->profile, 'en16931-'), 'EN16931 profile validation for ' . basename($file));
    }

    $facturRoot = __DIR__ . '/fixtures/upstream/factur-x';
    foreach (['Factur-X_basic.xml' => 'factur-x-2.5-basic', 'factur-x.xml' => 'factur-x-2.5-en16931', 'factur-x-extended.xml' => 'factur-x-2.5-extended'] as $name => $profile) {
        $fx = $validator->validateFile($facturRoot . '/' . $name, $profile);
        check($fx->status === 'valid' && $fx->profile === $profile, 'real Factur-X profile: ' . $profile);
        check(count($fx->svrlReports) > 0, 'archive complete raw SVRL for ' . $profile);
    }
    $pdfBinary = null;
    foreach (['/usr/bin/pdfdetach', '/usr/local/bin/pdfdetach'] as $candidate) if (is_executable($candidate)) { $pdfBinary = $candidate; break; }
    if ($pdfBinary !== null) {
        $pdfValidator = new Validator(new RuleRegistry(__DIR__ . '/../rules'), new KingEngine(), new IntelligentIntern\EInvoiceValidator\Pdf\EmbeddedInvoiceExtractor($pdfBinary));
        $pdfFile = $facturRoot . '/attributeBasedXMP_zugferd_2p0_EN16931_Einfach.pdf';
        $pdf = $pdfValidator->validateFile($pdfFile, 'factur-x-2.5-en16931');
        check($pdf->isValid() && $pdf->scope === 'embedded-xml-only', 'real PDF extraction validates XML scope only');
        check($pdf->inputSha256 === hash_file('sha256', $pdfFile) && $pdf->embeddedXmlSha256 !== null, 'separate original PDF and extracted XML hashes');
        check(in_array('PDF_CONTAINER_NOT_VALIDATED', codes($pdf), true), 'PDF/A and visual consistency must remain explicitly unverified');
    } else { $skipped[] = 'Poppler PDF extraction: pdfdetach not found'; }
    // Keep XSD-valid data but corrupt the amount: this must fail business rules, not merely schema checks.
    $doc = Xml::parse($fixture); $xp = new DOMXPath($doc);
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $amount = $xp->query('//*[local-name()="LegalMonetaryTotal"]/cbc:PayableAmount')->item(0);
    $amount->nodeValue = (string) ((float) $amount->textContent + 1);
    $invalid = $validator->validateString($doc->saveXML());
    check($invalid->status === 'invalid', 'incorrect total rejected by real Schematron');
    check(count(array_filter($invalid->findings, static fn(Finding $f): bool => $f->stage !== 'xsd' && $f->severity === 'error')) > 0, 'real business rule diagnostic retained');
    check(count(array_filter($invalid->stages, static fn(array $s): bool => $s['id'] === 'xsd' && $s['status'] === 'passed')) === 1, 'bad total was XSD-valid');
} else {
    $skipped[] = 'Native King/SaxonC integration suite: runtime unavailable. Set REQUIRE_KING=1 to make this a failure.';
    if (getenv('REQUIRE_KING') === '1') throw new RuntimeException('REQUIRE_KING=1 but King/SaxonC unavailable');
}
echo json_encode(['checks' => $checks, 'skipped' => $skipped, 'nativeEngine' => $engine->status()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
