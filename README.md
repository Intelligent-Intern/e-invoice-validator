# E-Invoice Validator

PHP library and CLI for structured electronic invoices, using the native
King/SaxonC runtime for Schematron. XSD validation and business-rule findings
are combined into a versioned JSON report. No Java process or remote validation
API is used.

Package: `intelligent-intern/e-invoice-validator` · PHP 8.2+ · MIT for library code.
Bundled third-party resources retain their own licenses and notices.

## Supported checks

| Document / profile | Checks |
|---|---|
| XRechnung 3.0.2 UBL Invoice | XML, UBL 2.1 XSD, EN 16931 and XRechnung Schematron |
| XRechnung 3.0.2 UBL CreditNote | XML, CreditNote XSD, EN 16931 and XRechnung Schematron |
| XRechnung 3.0.2 CII | XML, CII XSD, EN 16931 and XRechnung Schematron |
| Generic EN 16931 UBL Invoice / CreditNote / CII | XML, matching XSD and EN 16931 Schematron |
| Factur-X / ZUGFeRD BASIC, EN16931, EXTENDED | Matching pinned ZF_250 XML schemas and profile Schematron |
| PDF with recognised embedded invoice XML | Optional bounded extraction and XML checks; **scope is `embedded-xml-only`** |

XRechnung rules are pinned to the official `v2026-08-31` release, including
its severity overrides. Factur-X profile artifacts are pinned to Mustangproject
source commit `b0c60e456e63596b3ec3ac76e94374dc6e109c4f`, directory `ZF_250`.
This is not a claim to support every historical or future version. XRechnung
Extension/CVD, ZUGFeRD 1, MINIMUM, BASIC-WL and unknown guidelines are not enabled.

For PDF input, this release **does not validate PDF/A conformance, attachment
relationships, XMP metadata or agreement between visible PDF content and XML**.
`valid` and `isValid()` always refer to the report's declared scope; a valid
embedded XML report is not a complete hybrid-PDF conformance certificate.
An ordinary PDF or photograph is not an XML invoice and is not OCR-processed.

## Install and runtime

```sh
composer install
```

XML parsing needs PHP DOM/libxml. Actual Schematron validation additionally
needs a King build exposing `King\XSLT\Processor` and a compatible SaxonC
shared library. Missing runtime is reported as `error`, never as a valid invoice.
The source is available in the sibling King checkout; its isolated wrapper
does not overwrite the system PHP installation:

```sh
KING_SOURCE=/absolute/path/to/king-ext
"$KING_SOURCE/bin/king-xslt-php" bin/e-invoice-validator invoice.xml
```

The King checkout includes `infra/scripts/bootstrap-saxonc.sh` for the pinned
Linux x86_64 SaxonC-HE runtime. Build King with the matching PHP toolchain first.
Optional PDF extraction uses Poppler's `pdfdetach` executable.

Alternatively, build the self-contained Linux x86_64 Docker image from the
local committed King checkout. This leaves the host PHP installation unchanged:

```sh
make image KING_SOURCE=/absolute/path/to/king-ext
make test-container
mkdir -p invoices
# Put the invoice to validate in invoices/invoice.xml, then run:
docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges --memory 768m --cpus 2 --pids-limit 128 \
  --tmpfs /tmp:rw,noexec,nosuid,size=128m,mode=1777 \
  -v "$PWD/invoices:/invoices:ro" \
  e-invoice-validator:local /invoices/invoice.xml
```

The image runs as UID 10001. Input files must be readable by that user. It
includes Poppler, pinned SaxonC and their notices; no Java runtime is used.
Use an external job timeout in production, as the container test target does.

## PHP API

```php
<?php
require 'vendor/autoload.php';

use IntelligentIntern\EInvoiceValidator\Validator;

$report = Validator::fromBundledRules()->validateFile('/private/invoice.xml');
echo $report->toJson();

foreach ($report->findings as $finding) {
    // Rule identifier, severity, message, stage and location.
}

// Archive the original input and report together in your own private storage.
// Exact generated SVRL reports are exposed through $report->svrlReports.
```

`validateString($bytes)` accepts an in-memory document. `validateFile($path,
$profileId)` can select an explicit supported profile. PDFs are opt-in:

```php
$report = Validator::fromBundledRules(extractPdf: true)
    ->validateFile('/private/invoice.pdf', 'factur-x-2.5-en16931');
```

Generic CII EN16931 and the Factur-X EN16931 profile share a guideline ID;
select `factur-x-2.5-en16931` explicitly to apply that profile's artifacts.

## CLI

```sh
bin/e-invoice-validator invoice.xml
bin/e-invoice-validator --profile=factur-x-2.5-basic invoice.xml
bin/e-invoice-validator --extract-pdf --profile=factur-x-2.5-en16931 invoice.pdf
```

The output is JSON. `--json` is accepted explicitly. Exit codes:

| Code | Status | Meaning |
|---|---|---|
| 0 | `valid` | The configured checks passed for the declared scope |
| 1 | `invalid` | Parsing, schema or business-rule errors were found |
| 2 | `unsupported` | No enabled profile or supported extraction path |
| 3 | `error` | Engine, configuration or execution did not complete |

The report includes original SHA-256, embedded-XML SHA-256 where relevant,
selected profile, rule provenance/hashes, stages, findings and exact SVRL.
Warnings and information remain visible. A successful transformation alone
never proves invoice validity. Technical validity does not authorize payment
or establish the economic correctness of a transaction.

## Rules and reproducibility

`rules/manifest.json` lists every bundled package and resource hash. It selects
exact guidelines and syntaxes, and records upstream severity adjustments.
The library verifies resource hashes and local dependency paths before use.
Rules are supplied with the package; validation does not download them.
See [third-party notices](THIRD_PARTY_NOTICES.md).

```sh
python3 tools/verify-resources.py
```

Rule updates are explicit source changes. Verify positive and negative fixtures
under the new rule release before changing the manifest. Keep original reports
when revalidating under a different release.

## Tests and deployment

```sh
composer test
make test-native KING_SOURCE=/absolute/path/to/king-ext
```

The normal test command reports a missing native runtime as a skipped integration
suite. `REQUIRE_KING=1` makes that a failure; the native target requires King.
Tests use attributed public upstream fixtures and targeted invalid mutations.

Run the native validator in a separate worker/container with input/output
directories restricted to its job, no credentials and no network access. King
permits local stylesheet resources; local-file confinement is still required.
Only trusted, pinned rules may be executed. Do not expose arbitrary XSLT, paths
or URL fetching to API clients. Bound memory, input size, parallelism and wall time
at the worker boundary. Reports contain invoice data and must remain private.

## Project status

This is the initial local implementation, including the tested native Docker
image. It is not connected to Allinvoice's OCR/accounting workflow or deployed
as a public validation endpoint. Publishing remains a separate step. Repository
creation does not publish this checkout's contents.
