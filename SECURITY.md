# PHP runtime security policy

Reviewed 2026-11-04. E-Invoice Validator supports these patched PHP branches:

| Branch | Minimum patch |
| --- | --- |
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | 8.4.27 |
| 8.5 | 8.5.12 |

Composer excludes earlier versions. A Composer bootstrap also checks the actual
interpreter, covering copied vendor directories or overridden Composer platform
settings. Standalone CLI use applies the same check. Unreviewed branches fail.
This guard does not patch PHP or protect request parsing before application code.

The Docker runtime is PHP 8.4.27 CLI, pinned by digest. Its build runs
`tools/check-runtime.php`, failing for unsupported PHP or enabled session upload
progress. `make check-runtime` checks the current interpreter/startup settings.
`make test` requires that check; use `make test-container` on an older host.

## Advisories addressed

- **CVE-2026-109618 / CVE-2026-109619 (PHP2Shell):** use the patched versions above.
  [PHP advisory](https://www.php.net/archive/2026.php#2026-10-21-5),
  [OOM advisory](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w),
  [stale-state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).
- **CVE-2026-115560:** the follow-up session upload-progress issue is handled by
  setting `session.upload_progress.enabled=0` before request parsing. It is included
  in `docker/php-security.ini`; PHP 8.4.27 alone is insufficient for this follow-up.
  [PHP follow-up advisory](https://www.php.net/archive/2026.php#2026-10-23-1).

For an application embedding this library, apply the INI setting to every actual
FPM/Apache/other web runtime and restart/recreate its workers. Setting it using
`ini_set()` in application code is too late. Verify the effective web SAPI, not
only the CLI configuration. Do not treat Composer's `config.platform.php` or
`--ignore-platform-reqs` as security fixes. Vendor backports below these version
floors are conservatively rejected; use an accepted upstream version.

Windows deployments additionally need the updated `-1` distribution packages
for the Brotli dependency fix (CVE-2026-111497); PHP_VERSION is unchanged by that
package rebuild. The provided Docker runtime is Linux.
[PHP Windows build notice](https://www.php.net/archive/2026.php#2026-10-26-1).

Keep applying subsequent security updates. These checks address the named
advisories and do not certify absence of other vulnerabilities. Library updates
do not upgrade the developer host or a consuming application's PHP server.
