# Third-party resources

The MIT license covers the Intelligent Intern library code. It does not replace
the licenses of bundled standards, rule sets, example invoices or native runtimes.

The bundled XRechnung resources come from the KoSIT project
`itplr-kosit/validator-configuration-xrechnung`, release `v2026-08-31`.
The release combines EN 16931 Schematron 1.3.16, XRechnung Schematron 2.6.0,
UBL 2.1 schemas and UN/CEFACT CII D16B schemas. Original copyright and licensing
headers are preserved. See `rules/xrechnung/2026-08-31/LICENSE` and `NOTICE`.
The full resource inventory, source and hashes are in `rules/manifest.json`.

Public sample invoices come from `itplr-kosit/xrechnung-testsuite`, release
`v2026-08-31`. Its LICENSE and NOTICE are in `tests/fixtures/upstream/xrechnung/`;
file hashes and provenance are in `tests/fixtures/upstream/manifest.json`.
They are upstream examples, not private customer invoices.

Factur-X/ZUGFeRD ZF_250 schemas, compiled profile rules, code-list XML and the
additional public fixtures are sourced from Mustangproject at immutable commit
`b0c60e456e63596b3ec3ac76e94374dc6e109c4f`. No Java code or executable is used.
Original resource headers, upstream LICENSE and NOTICE are retained in
`rules/factur-x/mustang-zf250/`. Their precise resource hashes are locked in
`rules/manifest.json`; fixture source paths and hashes are in
`tests/fixtures/upstream/factur-x/provenance.json`.

King and SaxonC are separate runtime dependencies and are not relicensed by this
project. Any distributed runtime image must preserve their license and notice
requirements. Optional Poppler tools used for PDF extraction are separate
operating-system programs; PDF extraction does not establish PDF/A conformance.
