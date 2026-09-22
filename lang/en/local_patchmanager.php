<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for local_patchmanager.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Patch manager';

// Capabilities.
$string['patchmanager:view'] = 'View the patch manager status page';
$string['patchmanager:manage'] = 'Apply, restore and verify managed customisations';

// States.
$string['state_not_applied'] = 'Not applied';
$string['state_applied'] = 'Active';
$string['state_outdated'] = 'Outdated';
$string['state_partial'] = 'Partially applied';
$string['state_conflict'] = 'Conflict';
$string['state_unknown'] = 'Unknown';

// Hunk states.
$string['hunkstate_applied'] = 'applied';
$string['hunkstate_applicable'] = 'can be applied';
$string['hunkstate_ambiguous_payload'] = 'our block appears more than once';
$string['hunkstate_anchor_missing'] = 'anchor not found';
$string['hunkstate_anchor_ambiguous'] = 'anchor found more than once';
$string['hunkstate_moved'] = 'our block is present but no longer joined to its anchor';
$string['hunkstate_conflict'] = 'conflict';

// Reasons.
$string['reasonfilemissing'] = 'Target file is missing: {$a}';
$string['reasonfileunreadable'] = 'Target file cannot be read: {$a}';
$string['reasonmixedrevisions'] = 'Several revisions of this customisation are present on disk: {$a}';
$string['reasonoldrevision'] = '{$a->file}: revision r{$a->found} is installed, current definition is r{$a->expected}';
$string['reasonmarkercount'] = '{$a->file}: found {$a->found} marker block(s), expected {$a->expected}';
$string['reasonmarkerunbalanced'] = '{$a}: BEGIN and END markers do not match';
$string['reasonhunk_applicable'] = '{$a->file}: hunk "{$a->hunk}" is not applied';
$string['reasonhunk_ambiguous_payload'] = '{$a->file}: hunk "{$a->hunk}" appears more than once';
$string['reasonhunk_anchor_missing'] = '{$a->file}: the upstream context for hunk "{$a->hunk}" no longer exists';
$string['reasonhunk_anchor_ambiguous'] = '{$a->file}: the upstream context for hunk "{$a->hunk}" is not unique';
$string['reasonhunk_moved'] = '{$a->file}: hunk "{$a->hunk}" is present but has been moved away from its anchor';
$string['reasonhunk_conflict'] = '{$a->file}: hunk "{$a->hunk}" conflicts with the current code';

// Table and attributes.
$string['target'] = 'Target';
$string['customisation'] = 'Customisation';
$string['status'] = 'Status';
$string['attributes'] = 'Attributes';
$string['actions'] = 'Actions';
$string['versiondisk'] = 'version {$a}';
$string['upgradepending'] = 'Upgrade pending (database version {$a})';
$string['required'] = 'Required';
$string['verified'] = 'verified';
$string['unverified'] = 'unverified';
$string['verifiedby'] = 'Verified ({$a})';
$string['verificationstale'] = 'A verification exists but the files have changed since';
$string['revisionondisk'] = 'Revision on disk: r{$a}';
$string['acknowledged'] = 'acknowledged';
$string['acknowledgedby'] = 'Acknowledged by {$a->user}: {$a->reason}';
$string['notwritable'] = 'Target files are not writable by this process';
$string['changedsinceapply'] = 'A target file changed after the last apply';
$string['opcachestale'] = 'OPcache still holds an older copy of a target file';
$string['nobackup'] = 'No pristine backup for the installed target version';
$string['node'] = 'Node: {$a}';
$string['reasons'] = 'Details';

// Actions.
$string['review'] = 'Review';
$string['apply'] = 'Apply';
$string['reapply'] = 'Reapply';
$string['restore'] = 'Restore';
$string['markverified'] = 'Mark verified';
$string['acknowledge'] = 'Acknowledge';
$string['checknow'] = 'Check now';
$string['action_apply'] = 'Apply customisation';
$string['action_reapply'] = 'Reapply customisation';
$string['action_restore'] = 'Restore stock files';
$string['action_verify'] = 'Mark as verified';
$string['action_acknowledge'] = 'Acknowledge current condition';
$string['actiondone'] = '{$a} completed.';
$string['actionfailed'] = '{$a} failed. Nothing was left in an unknown state without a message above.';
$string['checkdone'] = 'State recomputed from disk.';
$string['verifydone'] = 'Verification recorded for this target version and revision.';
$string['acknowledgedone'] = 'Acknowledgement recorded. It will clear automatically when the state or the target version changes.';
$string['verifynote'] = 'Optional note, for example which tests you ran:';
$string['acknowledgereason'] = 'Reason for allowing the protected behaviour to continue (required):';
$string['acknowledgewarning'] = 'Acknowledging lets the pack resume the behaviour it is protecting, even though the customisation is not active and verified.';
$string['criticalwarning'] = 'Rollback did not fully succeed. Restore the affected files manually from the backup paths listed above before using the site.';

// Review screen.
$string['filestate'] = 'State: {$a}';
$string['filehash'] = 'SHA-256: {$a}';
$string['baselinematch'] = 'matches the tested stock baseline';
$string['baselinediffer'] = 'differs from the tested stock baseline';
$string['hunkcounts'] = 'Anchor occurrences: {$a->anchor}, our block: {$a->payload}';
$string['expectedanchor'] = 'Expected upstream context:';
$string['expectedpayload'] = 'Block this customisation inserts:';
$string['planhashes'] = 'SHA-256 {$a->before} -> {$a->after}';

// Environment.
$string['environment'] = 'Environment';
$string['envclionly'] = 'Applying from the browser is disabled. Set $CFG->local_patchmanager_allowwebapply = true; in config.php to enable it, or use the CLI.';
$string['envgit'] = 'The Moodle code directory is managed by Git. Applying a patch changes the working tree, and a deployment may overwrite it.';
$string['envopcache'] = 'OPcache runs with validate_timestamps disabled. Reload PHP-FPM after applying or restoring a patch.';
$string['envnode'] = 'This check inspected the files on node {$a}.';
$string['envmultinode'] = 'On a multi-node deployment, patches must be applied on every node or through the deployment system. A check on one node proves nothing about the others.';
$string['clihint'] = 'Run this from the server instead: cd {$a->dirroot} && sudo -u www-data php local/patchmanager/cli/{$a->script}.php --patch={$a->key}';
$string['clihint_nodirroot'] = 'Run this from the Moodle root directory: sudo -u www-data php local/patchmanager/cli/{$a->script}.php --patch={$a->key}';

// Task and checks.
$string['taskcheckstate'] = 'Check managed customisation state';
$string['checknopatches'] = 'No customisations are registered.';

// Errors.
$string['errinvaliddefinition'] = 'Invalid patch definition: {$a}';
$string['errdefinition'] = 'Patch definition {$a->key} was rejected: {$a->error}';
$string['errunknownpatch'] = 'Unknown customisation: {$a}';
$string['errfilenotwritable'] = 'File is not writable: {$a}';
$string['errdirnotwritable'] = 'Directory is not writable: {$a}';
$string['errfileunreadable'] = 'File cannot be read: {$a}';
$string['erractionnotallowed'] = '"{$a->action}" is not available while the state is "{$a->state}".';
$string['errnotapplicable'] = 'This customisation cannot be applied while its state is "{$a}".';
$string['errnothingtorestore'] = 'There is nothing to restore while the state is "{$a}".';
$string['errpayloadpresent'] = 'The block for hunk "{$a}" is already present in the file.';
$string['erranchorcount'] = 'The upstream context for hunk "{$a->hunk}" was found {$a->count} time(s); exactly one is required.';
$string['erroverlap'] = 'Hunk "{$a->hunk}" would be inserted inside a block owned by {$a->other}.';
$string['errsyntax'] = 'The result for {$a->file} is not valid PHP: {$a->error}';
$string['errnopristine'] = 'No pristine backup exists for {$a} at the installed target version. Reinstall the stock package instead.';
$string['errbackupunusable'] = 'The stored backup for {$a} failed its hash check and was not used.';
$string['errbackupwrite'] = 'The backup could not be written: {$a}';
$string['errbackupverify'] = 'The backup could not be verified after writing: {$a}';
$string['errreapplyother'] = 'Another customisation ({$a->other}) could not be reapplied to {$a->file}, so nothing was changed.';
$string['errreapplyaborted'] = 'Reapply stopped after the restore step. Nothing further was changed.';
$string['errtempwrite'] = 'A temporary file could not be written: {$a}';
$string['errrenamefailed'] = 'The replacement of {$a} failed.';
$string['errverifyfailed'] = 'The written content of {$a} did not match what was expected.';
$string['errrollbackfailed'] = 'Rollback failed for: {$a}. Restore these files manually from the backups.';
$string['errunexpectedstate'] = 'After writing, the state was "{$a}" instead of Active, so the change was rolled back.';
$string['errverifystate'] = 'Only an active customisation can be verified. The current state is "{$a}".';
$string['errnotsiteadmin'] = 'Only a site administrator may change managed code.';
$string['errwebapplydisabled'] = 'Applying from the browser is disabled on this site.';
$string['errwebapplydisabled_apply'] = 'Applying from the browser is disabled on this site.';
$string['errwebapplydisabled_restore'] = 'Restoring from the browser is disabled on this site.';
$string['errpostrequired'] = 'This action requires a form submission.';
$string['errlock'] = 'Another patch operation is running. Try again shortly.';
$string['erruninstallactive'] = 'Customisations are still on disk: {$a}. Restore them before uninstalling the patch manager.';

// Informational.
$string['dryrunok'] = 'Dry run succeeded. Nothing was written.';
$string['infonochange'] = 'No file needs to change.';
$string['inforolledback'] = 'The change was rolled back and the original files are in place.';
$string['infokeptother'] = 'Kept the customisation {$a}, which is applied to the same file.';
$string['warnowner'] = 'The owner of {$a} changed. Check file ownership before the next deployment.';

// Blocking attributes.
$string['blockednotwritable'] = 'Target files are not writable';
$string['blockedwebapply'] = 'Browser applying is disabled in config.php';
$string['blockeddep'] = 'Depends on {$a->patch}, which is "{$a->state}"';
$string['blockedmissingdep'] = 'Depends on {$a}, which is not registered';

// Privacy.
$string['privacy:metadata:audit'] = 'A record of each patch manager operation and state change.';
$string['privacy:metadata:audit:userid'] = 'The administrator who performed the operation.';
$string['privacy:metadata:audit:action'] = 'The operation performed.';
$string['privacy:metadata:audit:timecreated'] = 'When the operation was recorded.';
$string['privacy:metadata:verify'] = 'Verification records for a customisation on a target version.';
$string['privacy:metadata:verify:userid'] = 'The administrator who recorded the verification.';
$string['privacy:metadata:verify:note'] = 'The note entered with the verification.';
$string['privacy:metadata:verify:timecreated'] = 'When the verification was recorded.';
$string['privacy:metadata:ack'] = 'Acknowledgements of an unsafe or unverified condition.';
$string['privacy:metadata:ack:userid'] = 'The administrator who acknowledged the condition.';
$string['privacy:metadata:ack:reason'] = 'The reason entered with the acknowledgement.';
$string['privacy:metadata:ack:timecreated'] = 'When the acknowledgement was recorded.';
$string['privacy:metadata:backup'] = 'An index of file backups taken before a change.';
$string['privacy:metadata:backup:userid'] = 'The administrator whose operation created the backup.';
$string['privacy:metadata:backup:timecreated'] = 'When the backup was taken.';
