# Admin Tools integration validation — 0.1.42

Validated on 2026-11-05. Client identifiers, private backup paths and configuration values are omitted from this public report.

## Failure and change

Applying the server snippet failed with `Could not read the current Admin Tools .htaccess footer.` Admin Tools loaded a configuration form inside a separate Joomla CLI application. JCE Pro 3.0.4 handled the form event and called `ConsoleApplication::getDocument()` before checking editor profiles or media-field settings. Those settings therefore could not prevent the failure.

`AdminToolsManager` now reads saved configuration with `loadConfiguration(false)`. Explicit changes still run through Admin Tools' own validated commands and authorised save/write call chain, using the current Joomla administrator application. Inputs are structured and command output remains buffered. The Admin Tools forms directory is registered explicitly because the current component remains `com_plugins`.

Backups must be written and verified before configuration changes. Repeated applies use distinct backup names. Apply and removal verify the managed block after rebuilding. The status field also stops referencing two obsolete variables.

## Local checks

`php tests/run.php` passes 419 assertions: 153 helpers, 226 runtime, 31 Admin Tools and 9 JavaScript. `bash tests/route-report.sh`, PHP syntax checks and `git diff --check` pass.

`tests/admintools.php` uses Joomla/Admin Tools doubles and a temporary test filesystem. It covers an empty footer, console status without HTML form events, denied console writes, apply/reapply/removal, preservation of unrelated rules and directory exceptions, verified backups, failed saves/rebuilds and a rebuild that incorrectly reports success. These doubles do not establish vendor compatibility by themselves.

## Live validation

The maintainer authorised a bounded test on a Joomla 6.2.0 site with Admin Tools 7.9.3, JCE Pro 3.0.4 and PHP 8.4.27. A verified server-side backup was created outside the document root before replacing the two changed PHP files.

The real plugin action successfully saved the footer and rebuilt `.htaccess`. The administrator showed `Applied`; expected, stored-footer and `.htaccess` managed-block hashes matched. Only `custfoot` changed in the Admin Tools configuration. Existing directory exceptions and MAx Cache settings were preserved. Outside the managed block, `.htaccess` changes were limited to generator comments.

HTTPS checks returned HTTP 200 for the homepage, an unknown-query bypass and both account-recovery forms. The homepage response matched the static artifact byte for byte and retained its modification timestamp. Unknown-query requests and recovery forms remained dynamic with `no-store`; recovery-form tokens differed between visits without cookies.

JCE Pro remained enabled. A status-field warning discovered during the test was fixed; subsequent administrator reloads added no entries to either PHP error log. Installed files retained the account owner/group and mode `0644`. No forms or mail were submitted.

## Limits and update instructions

The live check replaced the two PHP files on 0.1.41; it was not a package-installation test. Joomla 5 and other Admin Tools versions were not tested live. Removal/reapply failure paths are covered by doubles; removal was not exercised on the production site. The installer's separate uninstall cleanup path is unchanged.

0.1.42 does not change the generated snippet or existing cache artifacts. Updating from 0.1.41 needs no purge. If an earlier Admin Tools apply failed, run the explicit Apply snippet action after updating and verify the status and public responses.

Full client-specific evidence and rollback details remain in the private project records.
