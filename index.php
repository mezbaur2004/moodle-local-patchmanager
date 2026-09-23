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
 * Patch manager status and management page.
 *
 * @package    local_patchmanager
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_patchmanager\api;
use local_patchmanager\local\env;
use local_patchmanager\local\registry;
use local_patchmanager\output\ui;

admin_externalpage_setup('local_patchmanager');

$action = optional_param('action', 'view', PARAM_ALPHA);
$pack = optional_param('pack', '', PARAM_COMPONENT);
$patchid = optional_param('patch', '', PARAM_ALPHANUMEXT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$note = optional_param('note', '', PARAM_TEXT);

$baseurl = new moodle_url('/local/patchmanager/index.php');
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('pluginname', 'local_patchmanager'));
$PAGE->set_heading(get_string('pluginname', 'local_patchmanager'));

$definition = null;
if ($pack !== '' && $patchid !== '') {
    $definition = registry::get_definition($pack, $patchid);
    if ($definition === null) {
        throw new moodle_exception('errunknownpatch', 'local_patchmanager', $baseurl, $pack . ':' . $patchid);
    }
}

$writeactions = ['apply', 'reapply', 'restore'];
$notifications = [];

if ($action !== 'view') {
    require_sesskey();
}

// Writing to the code directory from the browser must be switched on in config.php.
if (in_array($action, $writeactions, true) && !env::webapply_allowed()) {
    $cliscript = ($action === 'restore') ? 'restore' : 'apply';
    $verbkey = ($action === 'restore') ? 'errwebapplydisabled_restore' : 'errwebapplydisabled_apply';
    $notifications[] = [get_string($verbkey, 'local_patchmanager'), 'error'];

    if ($definition !== null) {
        $hintargs = (object) ['script' => $cliscript, 'key' => $definition->key()];
        if (!empty($CFG->dirroot)) {
            $hintargs->dirroot = $CFG->dirroot;
            $notifications[] = [get_string('clihint', 'local_patchmanager', $hintargs), 'info'];
        } else {
            $notifications[] = [get_string('clihint_nodirroot', 'local_patchmanager', $hintargs), 'info'];
        }
    }
    $action = 'view';
}

if ($action === 'check') {
    registry::reset_cache();
    api::prune_acknowledgements();
    api::notify_packs();
    redirect($baseurl, get_string('checkdone', 'local_patchmanager'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Whether the current state permits the requested code change. Checked here on
// the server, not merely by hiding a button on the status table.
if ($definition !== null && in_array($action, $writeactions, true)) {
    $currentstate = api::build_status($definition)->state;
    $allowed = [
        'apply' => \local_patchmanager\state::can_apply($currentstate),
        'reapply' => \local_patchmanager\state::can_reapply($currentstate),
        'restore' => \local_patchmanager\state::can_restore($currentstate),
    ];

    if (empty($allowed[$action])) {
        $notifications[] = [
            get_string('erractionnotallowed', 'local_patchmanager', (object) [
                'action' => get_string('action_' . $action, 'local_patchmanager'),
                'state' => \local_patchmanager\state::label($currentstate),
            ]),
            'error',
        ];
        $action = 'view';
    }
}

// Confirmation step for everything that changes code or records a decision.
if ($definition !== null && in_array($action, array_merge($writeactions, ['verify', 'acknowledge']), true) && !$confirm) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('action_' . $action, 'local_patchmanager') . ': ' . s($definition->name));

    $status = api::build_status($definition);
    echo ui::badge($status);

    if (in_array($action, $writeactions, true)) {
        $preview = ($action === 'restore')
                ? api::restore($definition, true, false)
                : (($action === 'reapply') ? api::reapply($definition, true) : api::apply($definition, true));

        foreach ($preview->messages as $message) {
            echo $OUTPUT->notification(s($message), $preview->success
                    ? \core\output\notification::NOTIFY_INFO
                    : \core\output\notification::NOTIFY_ERROR);
        }

        echo ui::plan($preview);

        if (!$preview->success) {
            echo $OUTPUT->single_button($baseurl, get_string('back'), 'get');
            echo $OUTPUT->footer();
            die;
        }
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pack', 'value' => $definition->pack]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'patch', 'value' => $definition->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);

    if (in_array($action, ['verify', 'acknowledge'], true)) {
        $label = ($action === 'verify')
                ? get_string('verifynote', 'local_patchmanager')
                : get_string('acknowledgereason', 'local_patchmanager');
        echo html_writer::tag('p', $label);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'note',
            'size' => 70,
            'maxlength' => 255,
            'required' => ($action === 'acknowledge') ? 'required' : null,
            'class' => 'form-control mb-2',
        ]);
    }

    if ($action === 'acknowledge') {
        echo $OUTPUT->notification(get_string('acknowledgewarning', 'local_patchmanager'),
                \core\output\notification::NOTIFY_WARNING);
    }

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('action_' . $action, 'local_patchmanager'),
        'class' => 'btn btn-primary',
    ]);
    echo ' ' . html_writer::link($baseurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');

    echo $OUTPUT->footer();
    die;
}

// Execution.
if ($definition !== null && $confirm) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('errpostrequired', 'local_patchmanager', $baseurl);
    }
    // Action-aware: apply, reapply and restore additionally require the
    // web-apply switch, because they write code. Verify and acknowledge record
    // a decision and write no file, so they need only site admin plus the
    // manage capability, alongside the POST, sesskey and confirmation above.
    api::require_manage_action($action, true);

    switch ($action) {
        case 'apply':
            $result = api::apply($definition);
            break;
        case 'reapply':
            $result = api::reapply($definition);
            break;
        case 'restore':
            // can_restore() has already gated this, so no force override.
            $result = api::restore($definition, false, false);
            break;
        case 'verify':
            api::verify($definition, $note);
            redirect($baseurl, get_string('verifydone', 'local_patchmanager'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            break;
        case 'acknowledge':
            api::acknowledge($definition, $note);
            redirect($baseurl, get_string('acknowledgedone', 'local_patchmanager'), null,
                    \core\output\notification::NOTIFY_WARNING);
            break;
        default:
            redirect($baseurl);
    }

    foreach ($result->messages as $message) {
        $notifications[] = [$message, $result->success ? 'info' : 'error'];
    }
    if (!empty($result->critical)) {
        $notifications[] = [get_string('criticalwarning', 'local_patchmanager'), 'error'];
    }
    $notifications[] = [
        get_string($result->success ? 'actiondone' : 'actionfailed', 'local_patchmanager',
                get_string('action_' . $action, 'local_patchmanager')),
        $result->success ? 'success' : 'error',
    ];
    if ($result->success && $action !== 'restore') {
        $hint = api::build_status($definition)->verify_hint();
        if ($hint !== null) {
            $notifications[] = [$hint, 'warning'];
        }
    }
}

// Review screen.
if ($definition !== null && $action === 'review') {
    $status = api::build_status($definition);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(s($definition->name));
    echo ui::badge($status);
    echo ui::review($status);
    echo $OUTPUT->single_button($baseurl, get_string('back'), 'get');
    echo $OUTPUT->footer();
    die;
}

// Default: the status list.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_patchmanager'));

foreach ($notifications as [$message, $type]) {
    echo $OUTPUT->notification(s($message), $type);
}

$errors = registry::get_errors();
foreach ($errors as $key => $message) {
    echo $OUTPUT->notification(get_string('errdefinition', 'local_patchmanager',
            (object) ['key' => $key, 'error' => $message]), \core\output\notification::NOTIFY_ERROR);
}

$statuses = api::get_statuses();
if (empty($statuses)) {
    echo $OUTPUT->notification(get_string('checknopatches', 'local_patchmanager'),
            \core\output\notification::NOTIFY_INFO);
} else {
    echo ui::table($statuses, $baseurl);
}

// Environment notes: these are facts the administrator needs, not blockers.
$environment = [];
if (!env::webapply_allowed()) {
    $environment[] = get_string('envclionly', 'local_patchmanager');
}
if (env::is_git_managed()) {
    $environment[] = get_string('envgit', 'local_patchmanager');
}
$opcache = env::opcache_info();
if ($opcache->enabled && !$opcache->validatetimestamps) {
    $environment[] = get_string('envopcache', 'local_patchmanager');
}
$environment[] = get_string('envnode', 'local_patchmanager', env::hostname());
$environment[] = get_string('envmultinode', 'local_patchmanager');

echo $OUTPUT->heading(get_string('environment', 'local_patchmanager'), 3);
echo html_writer::alist($environment);

echo $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'check', 'sesskey' => sesskey()]),
    get_string('checknow', 'local_patchmanager'),
    'get'
);

echo $OUTPUT->footer();
