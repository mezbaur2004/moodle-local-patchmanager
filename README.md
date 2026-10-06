# Patch Manager for Moodle (local_patchmanager)

Apply, verify and roll back source patches to third-party Moodle plugins on a production
site, without hand-editing files on the server and without losing track of what was changed.

Sometimes a third-party plugin has a bug you can't wait for upstream to fix. Editing its
files directly works until the next plugin upgrade silently overwrites the fix, or a partial
edit leaves the site in a state nobody can describe. This plugin turns each fix into a
declared, versioned patch, detects its state from the files on disk, and makes apply and
restore safe operations with backups and an audit trail.

The engine knows nothing about any particular plugin. Patches ship in separate *packs*:
the first is [local_zoomcustom](https://github.com/mezbaur2004/moodle-local-zoomcustom),
which fixes mod_zoom's duration-based grading. A read-only Dashboard view is in
[block_patchmanager](https://github.com/mezbaur2004/moodle-block_patchmanager).

Designed, reviewed and tested by Mezbaur Are Rafi; the implementation was written with AI
assistance (Claude).

## How it works

- **Patches are anchored hunks, not diffs.** Each hunk names an exact anchor string that must
  occur once in the target file and a marked block to insert. No fuzzy matching.
- **State is always recomputed from disk**, never trusted from the database. Every patch is
  in one of six states: `NOT_APPLIED`, `APPLIED`, `OUTDATED`, `PARTIAL`, `CONFLICT` or
  `UNKNOWN`. The database holds history only.
- **Apply is all-or-nothing.** Content is built in memory, syntax-checked with the PHP
  tokenizer, every target file is backed up and the backups verified, then temporary files
  are renamed over the originals. Any failure before the first rename leaves the code
  untouched; a failure after it restores the backups taken in the same operation.
- **Restore writes back a hash-verified pristine copy** taken at apply time instead of
  reverse-applying the patch, and reapplies any other patch on the same file.
- **Verification is explicit.** A patch is *verified* for a specific target version,
  revision and set of file hashes; an upgrade or an edited file invalidates it.
- **Everything is audited**: every check, apply, restore, verification and acknowledgement.
- **One lock** covers cron, the CLI and the admin page, so they can't race.

[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) describes the design and data flow;
[docs/OPERATIONS.md](docs/OPERATIONS.md) is the step-by-step guide for installing, applying,
verifying and rolling back on a live site, including OPcache and multi-node caveats.

## Safety model

- **CLI first.** By default, code changes can only be made from the server's command line,
  so the web server never needs write access to the code directory. A browser *Apply*
  button exists only if `$CFG->local_patchmanager_allowwebapply = true` is set in
  `config.php`.
- Every write requires a site administrator, a POST and a valid sesskey.
- Scheduled tasks only *detect*; nothing applies a patch automatically.
- The engine refuses patches that touch a plugin's `db/` directory or `version.php`.
- Patch state is reported through Moodle's Check API (*Reports → System status*), so
  external monitoring can alert on it.

## Requirements

- Moodle 4.1 or later
- Shell access to the server for applying and restoring patches (recommended)

## Installation

Install into `local/patchmanager`, then run the upgrade:

```bash
php admin/cli/upgrade.php --non-interactive
```

## Command line

```bash
php local/patchmanager/cli/status.php            # exit 1 if a required patch is not current
php local/patchmanager/cli/status.php --json
php local/patchmanager/cli/check.php             # recompute state and notify packs
php local/patchmanager/cli/apply.php --all --dry-run
php local/patchmanager/cli/apply.php --patch=<pack>:<patch-id>
php local/patchmanager/cli/apply.php --patch=<pack>:<patch-id> --reapply
php local/patchmanager/cli/restore.php --patch=<pack>:<patch-id>
php local/patchmanager/cli/verify.php --patch=<pack>:<patch-id>
```

The admin page is under *Site administration → Plugins → Local plugins → Patch manager*.

## Tests

```bash
vendor/bin/phpunit local/patchmanager/tests/
```

The tests cover the three hunk types, CRLF files, the syntax gate, every state aggregation
rule, and the CLI hints shown when web apply is disabled.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
