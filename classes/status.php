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

namespace local_patchmanager;

use local_patchmanager\local\definition;

/**
 * The computed condition of one customisation: its state plus its attributes.
 *
 * Packs consume this object. It is rebuilt from disk on every call.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class status {

    /** @var definition */
    public $definition;

    /** @var string One of the six states. */
    public $state;

    /** @var \stdClass[] Per file detail, keyed by relative path. */
    public $files = [];

    /** @var string[] Human readable reasons for the current state. */
    public $reasons = [];

    /** @var int|null Revision found on disk. */
    public $appliedrevision = null;

    /** @var bool */
    public $verified = false;

    /** @var string|null pack or site. */
    public $verifiedsource = null;

    /** @var \stdClass|null Site verification record. */
    public $verificationrecord = null;

    /** @var bool True when a site verification exists but the files have changed since. */
    public $verificationstale = false;

    /** @var bool */
    public $required = false;

    /** @var \stdClass|null Active acknowledgement record. */
    public $acknowledgement = null;

    /** @var string[] Reasons the patch may not be applied right now. */
    public $blockedby = [];

    /** @var bool */
    public $writable = false;

    /** @var string[] */
    public $writablereasons = [];

    /** @var bool */
    public $backupavailable = false;

    /** @var \stdClass Target component version facts. */
    public $componentversion;

    /** @var \stdClass|null Last successful apply audit record. */
    public $lastapply = null;

    /** @var bool True when a target file changed after the last apply. */
    public $changedsinceapply = false;

    /** @var \stdClass OPcache facts for the target files. */
    public $opcache;

    /** @var string */
    public $hostname = '';

    /** @var bool */
    public $gitmanaged = false;

    /**
     * Patch key.
     *
     * @return string
     */
    public function key(): string {
        return $this->definition->key();
    }

    /**
     * Label of the current state.
     *
     * @return string
     */
    public function state_label(): string {
        return state::label($this->state);
    }

    /**
     * ok, warning or error, taking attributes into account.
     *
     * @return string
     */
    public function severity(): string {
        $severity = state::severity($this->state);
        if ($severity === 'ok' && !$this->verified) {
            return 'warning';
        }
        return $severity;
    }

    /**
     * Is this customisation fully healthy: applied at the current revision and verified?
     *
     * @return bool
     */
    public function is_current(): bool {
        return $this->state === state::APPLIED && $this->verified;
    }

    /**
     * What to do next when the code is in place but not verified.
     *
     * Verification is tied to the installed target version, so after the target
     * plugin is upgraded a successful apply lands here rather than in a current
     * state. Packs may hold back behaviour until it is verified, and nothing on
     * the apply path records that, so the administrator is told how to.
     *
     * @return string|null plain text, null when there is nothing to verify
     */
    public function verify_hint(): ?string {
        global $CFG;

        if ($this->state !== state::APPLIED || $this->verified) {
            return null;
        }

        $args = (object) [
            'key' => $this->key(),
            'component' => $this->definition->component,
            'version' => $this->componentversion->versiondisk ?? '?',
        ];

        if (!empty($CFG->dirroot)) {
            $args->dirroot = $CFG->dirroot;
            return get_string('verifyhint', 'local_patchmanager', $args);
        }

        return get_string('verifyhint_nodirroot', 'local_patchmanager', $args);
    }

    /**
     * May the administrator apply it now?
     *
     * @return bool
     */
    public function can_apply(): bool {
        return $this->state === state::NOT_APPLIED && $this->writable && empty($this->blockedby);
    }

    /**
     * May the administrator reapply it now?
     *
     * @return bool
     */
    public function can_reapply(): bool {
        return $this->state === state::OUTDATED && $this->writable && $this->backupavailable
                && empty($this->blockedby);
    }

    /**
     * May the administrator restore the pristine files?
     *
     * @return bool
     */
    public function can_restore(): bool {
        // CONFLICT excluded to match state::can_restore(): none of our markers
        // are on disk in that state, so there is nothing of ours to remove.
        $restorable = [state::APPLIED, state::OUTDATED, state::PARTIAL];
        return in_array($this->state, $restorable, true) && $this->writable && $this->backupavailable;
    }

    /**
     * May the administrator record a verification?
     *
     * @return bool
     */
    public function can_verify(): bool {
        return $this->state === state::APPLIED && !$this->verified;
    }

    /**
     * May the administrator acknowledge the current condition?
     *
     * @return bool
     */
    public function can_acknowledge(): bool {
        return $this->required && !$this->is_current() && $this->acknowledgement === null;
    }
}
