<?php

use dokuwiki\Extension\Event;

/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

class helper_plugin_task extends DokuWiki_Plugin {

    public function getMethods() {
        $result = [];
        $result[] = [
                'name'   => 'th',
                'desc'   => 'returns the header of the task column for pagelist',
                'return' => ['header' => 'string'],
        ];
        $result[] = [
                'name'   => 'td',
                'desc'   => 'returns the status of the task',
                'params' => ['id' => 'string'],
                'return' => ['label' => 'string'],
        ];
        $result[] = [
                'name'   => 'getTasks',
                'desc'   => 'get task pages, sorted by priority',
                'params' => [
                    'namespace' => 'string',
                    'number (optional)' => 'integer',
                    'filter (optional)' => 'string'],
                'return' => ['pages' => 'array'],
        ];
        $result[] = [
                'name'   => 'readTask',
                'desc'   => 'get a single task metafile',
                'params' => ['id' => 'string'],
                'return' => ['task on success, else false' => 'array, (boolean)'],
        ];
        $result[] = [
                'name'   => 'writeTask',
                'desc'   => 'save task metdata in a file',
                'params' => [
                    'id' => 'string',
                    'task' => 'array'],
                'return' => ['success' => 'boolean'],
        ];
        $result[] = [
                'name'   => 'statusLabel',
                'desc'   => 'returns the status label for a given integer',
                'params' => ['status' => 'integer'],
                'return' => ['label' => 'string'],
        ];
        return $result;
    }

    /**
     * Returns the column header for the Pagelist Plugin
     *
     * @return string header text, escaped by Pagelist
     */
    public function th() {
        return $this->getLang('status');
    }

    /**
     * Returns the status of the task
     *
     * Used by pagelist plugin for filling the cells of the table
     * and in listing by the tagfilter
     *
     * @param string $id page id of row
     * @return string html with escaped values
     */
    public function td($id) {
        $task = $this->readTask($id);
        return $this->statusLabel($task['status']);
    }

    /**
     * Returns an array of task pages, sorted by priority
     *
     * @param string $ns only tasks from this namespace including its subnamespaces
     * @param int|null $num max number of returned rows, null is all
     * @param string $filter 'all', 'rejected', 'accepted', 'started', 'done', 'verified', 'my', 'new', 'due', 'overdue'
     * @param string $user show task of given user FIXME not used??
     * @return array with the rows:
     *  string sortkey => [
     *      'id'       => string page id,
     *      'date'     => int timestamp of due date,
     *      'user'     => string name of user,
     *      'status'   => string translated status
     *      'priority' => int in range 0 to 4
     *      'perm'     => int ACL permission
     *      'file'     => string .task metadata file
     *      'exists'   => true
     * ]
     */
    public function getTasks($ns, $num = null, $filter = '', $user = null) {
        global $conf, $INPUT;

        if (!$filter) {
            $filter = strtolower($INPUT->str('filter'));
        }

        // returns the list of pages in the given namespace and it's subspaces
        $items = [];
        $opts = [];
        $ns = utf8_encodeFN(str_replace(':', '/', $ns));
        search($items, $conf['datadir'], 'search_allpages', $opts, $ns);

        // add pages with comments to result
        $result = [];
        foreach ($items as $item) {
            $id = $item['id'];

            // skip pages without task
            if (!$task = $this->readTask($id)) continue;

            $date = $task['date']['due'];
            $responsible = $this->isResponsible($task['user']);

            // Check status in detail if filter is not 'all'
            if ($filter != 'all') {
                if ($filter == 'rejected') {
                    // Only show 'rejected'
                    if ($task['status'] != -1) continue;
                } else if ($filter == 'accepted') {
                    // Only show 'accepted' and 'started'
                    if ($task['status'] != 1 && $task['status'] != 2) continue;
                } else if ($filter == 'started') {
                    // Only show 'started'
                    if ($task['status'] != 2) continue;
                } else if ($filter == 'done') {
                    // Only show 'done'
                    if ($task['status'] != 3) continue;
                } else if ($filter == 'verified') {
                    // Only show 'verified'
                    if ($task['status'] != 4) continue;
                } else {
                    // No pure status filter, skip done and closed tasks
                    if ($task['status'] < 0 || $task['status'] > 2) continue;
                }
            }

            // skip other's tasks if filter is 'my'
            if ($filter == 'my' && !$responsible) continue;

            // skip assigned and not new tasks if filter is 'new'
            if ($filter == 'new' && ($task['user']['name'] || $task['status'] != 0)) continue;

            // filter is 'due' or 'overdue'
            if (in_array($filter, ['due', 'overdue'])) {
                if (!$date || $date > time() || $task['status'] > 2) continue;
                elseif ($date + 86400 < time() && $filter == 'due') continue;
                elseif ($date + 86400 > time() && $filter == 'overdue') continue;
            }
            $perm = auth_quickaclcheck($id);

            $result[$task['key']] = [
                    'id'       => $id,
                    'date'     => $date,
                    'user'     => $task['user']['name'],
                    'status'   => $this->statusLabel($task['status']),
                    'priority' => $task['priority'],
                    'perm'     => $perm,
                    'file'     => $task['file'],
                    'exists'   => true,
            ];
        }

        // finally sort by time of last comment
        krsort($result);

        if (is_numeric($num)) {
            $result = array_slice($result, 0, $num);
        }

        return $result;
    }

    /**
     * Reads the .task metafile
     *
     * @param string $id page id
     * @return array|false returns data array, otherwise false,
     *  with:
     *   'date' => [
     *      'created' => int,
     *      'modified' => int,
     *      'due' => int,
     *      'completed' => int
     *   ],
     *   'user' => [
     *      'name' => ...
     *   ],
     *   'file' => string,
     *   'exists' => bool
     *   'status' => int,
     *   'priority' => string
     *   'vtodo' => string vtodo for iCal file download,
     *   'key' => string sortkey based at priority and creation date
     */
    public function readTask($id) {
        $file = metaFN($id, '.task');
        if (!@file_exists($file)) {
            //old format? reset stored metadata and replace by .task metadata file
            $data = p_get_metadata($id, 'task');
            if (is_array($data)) {
                $data['date'] = ['due' => $data['date']];
                $data['user'] = ['name' => $data['user']];
                $meta = ['task' => null];
                if ($this->writeTask($id, $data)) {
                    p_set_metadata($id, $meta);
                }
            }
        } else {
            $data = unserialize(io_readFile($file, false));
        }
        if (!is_array($data) || empty($data)) {
            return false;
        }
        $data['file']   = $file;
        $data['exists'] = true;
        return $data;
    }

    /**
     * Saves provided data to the .task metafile
     *
     * @param string $id page id
     * @param array $data with at least:
     *   'file' => string,
     *   'exists' => bool,
     *   'date' => [
     *       'created' => int,
     *       'modified' => int,
     *       'due' => int,
     *       'completed' => int
     *    ] or string due date (old?),
     *   'user' => [
     *       'name' string
     *   ] or string name (old?),
     *   'status' => int,
     *   'priority' => string
     *
     *  key created before storing:
     *   'vtodo' => string vtodo for iCal file download,
     *   'key' => string sortkey based at priority and creation date
     *
     * @return bool success?
     */
    public function writeTask($id, $data) {
        if (!is_array($data)) {
            return false;
        }
        $file = ($data['file'] ?: metaFN($id, '.task'));

        // remove file and exists keys
        unset($data['file']);
        unset($data['exists']);

        // set creation or modification time
        if (!is_array($data['date'])) {
            //old format?
            $data['date'] = ['due' => $data['date']];
        }
        if (!@file_exists($file) || !$data['date']['created']) {
            $data['date']['created'] = time();
        } else {
            $data['date']['modified'] = time();
        }

        if (!is_array($data['user'])) {
            //old format?
            $data['user'] = ['name' => $data['user']];
        }

        if (!isset($data['status'])) {    // make sure we don't overwrite status
            $currentTask = unserialize(io_readFile($file, false));
            $data['status'] = $currentTask['status'];
        } elseif ($data['status'] == 3) { // set task completion time
            $data['date']['completed'] = time();
        }

        // generate vtodo for iCal file download
        $data['vtodo'] = $this->vtodo($id, $data);

        // generate sortkey with priority and creation date
        $data['key'] = chr($data['priority'] + 97).(2000000000 - $data['date']['created']);

        // save task metadata
        $ok = io_saveFile($file, serialize($data));

        // and finally notify users
        $this->notify($data);
        return $ok;
    }

    /**
     * Returns the label of a status
     *
     * @param int $status
     * @return string
     */
    public function statusLabel($status) {
        switch ($status) {
            case -1:
                return $this->getLang('rejected');
            case 1:
                return $this->getLang('accepted');
            case 2:
                return $this->getLang('started');
            case 3:
                return $this->getLang('done');
            case 4:
                return $this->getLang('verified');
            default:
                return $this->getLang('new');
        }
    }

    /**
     * Returns the label of a priority
     *
     * @param int $priority
     * @return string
     */
    public function priorityLabel($priority) {
        switch ($priority) {
            case 1:
                return $this->getLang('medium');
            case 2:
                return $this->getLang('high');
            case 3:
                return $this->getLang('critical');
            default:
                return $this->getLang('low');
        }
    }

    /**
     * Is the given task user assigned to the current logged-in user?
     *
     * @param string|array $user string name of user, or array with:
     *  'id' => string,
     *  'name' => string
     *
     * @return bool
     */
    public function isResponsible($user) {
        global $INFO, $INPUT;

        if (!$user) {
            return false;
        }

        if (
            isset($user['id']) && $user['id'] == $INPUT->server->str('REMOTE_USER')
            || isset($user['name']) && $user['name'] == $INFO['userinfo']['name']
            || $user == $INFO['userinfo']['name'] //was old format?
        ) {
            return true;
        }

        return false;
    }

    /**
     * Interpret date with strtotime()
     *
     * @param string $str
     * @return false|int|null timestamp or null
     */
    public function interpretDate($str) {
        if (!$str) {
            return null;
        }

        // only year given -> time till end of year
        if (preg_match("/^\d{4}$/", $str)) {
            $str .= '-12-31';

            // only month given -> time till last of month
        } elseif (preg_match("/^\d{4}-(\d{2})$/", $str, $month)) {
            switch ($month[1]) {
                case '01': case '03': case '05': case '07': case '08': case '10': case '12':
                    $str .= '-31';
                    break;
                case '04': case '06': case '09': case '11':
                    $str .= '-30';
                    break;
                case '02': // leap year isn't handled here
                    $str .= '-28';
                    break;
            }
        }

        // convert to UNIX time
        $date = strtotime($str);
        if ($date === -1) {
            $date = null;
        }
        return $date;
    }

    /**
     * Sends a notify mail on new or changed task
     *
     * @param array $task data array of the task
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    protected function notify($task) {
        global $conf;
        global $ID;

        //subscribers enabled?
        if (!$conf['subscribers'] && !$conf['notify']) {
            return;
        }
        $data = ['id' => $ID, 'addresslist' => '', 'self' => false];
        Event::createAndTrigger('COMMON_NOTIFY_ADDRESSLIST', $data, 'subscription_addresslist');
        $bcc = $data['addresslist'];
        if (empty($bcc) && !$conf['notify']) {
            return;
        }
        $to   = $conf['notify'];
        $text = io_readFile($this->localFN('subscribermail'));

        $text = str_replace('@PAGE@', $ID, $text);
        $text = str_replace('@TITLE@', $conf['title'], $text);
        if(!empty($task['date']['due'])) {
            $dformat = preg_replace('#%[HIMprRST]|:#', '', $conf['dformat']);
            $text    = str_replace('@DATE@', strftime($dformat, $task['date']['due']), $text);
        } else {
            $text = str_replace('@DATE@', '', $text);
        }
        $text = str_replace('@NAME@', $task['user']['name'], $text);
        $text = str_replace('@STATUS@', $this->statusLabel($task['status']), $text);
        $text = str_replace('@PRIORITY@', $this->priorityLabel($task['priority']), $text);
        $text = str_replace('@UNSUBSCRIBE@', wl($ID, 'do=unsubscribe', true, '&'), $text);
        $text = str_replace('@DOKUWIKIURL@', DOKU_URL, $text);

        $subject = '['.$conf['title'].'] ';
        if ($task['status'] == 0) {
            $subject .= $this->getLang('mail_newtask');
        } else {
            $subject .= $this->getLang('mail_changedtask');
        }

        mail_send($to, $subject, $text, $conf['mailfrom'], '', $bcc); //FIXME replace
    }

    /**
     * Generates a VTODO section for iCal file download
     *
     * @param string $id page id
     * @param array $task data array of the task
     * @return string
     */
    protected function vtodo($id, $task) {
        if (!defined('CRLF')) define('CRLF', "\r\n");

        $meta = p_get_metadata($id);

        $ret = 'BEGIN:VTODO'.CRLF.
            'UID:'.$id.'@'.$_SERVER['SERVER_NAME'].CRLF.
            'URL:'.wl($id, '', true, '&').CRLF.
            'SUMMARY:'.$this->vsc($meta['title']).CRLF;
        if ($meta['description']['abstract']) {
            $ret .= 'DESCRIPTION:' . $this->vsc($meta['description']['abstract']) . CRLF;
        }
        if ($meta['subject']) {
            $ret .= 'CATEGORIES:' . $this->vcategories($meta['subject']) . CRLF;
        }
        if ($task['date']['created']) {
            $ret .= 'CREATED:' . $this->vdate($task['date']['created']) . CRLF;
        }
        if ($task['date']['modified']) {
            $ret .= 'LAST-MODIFIED:' . $this->vdate($task['date']['modified']) . CRLF;
        }
        if ($task['date']['due']) {
            $ret .= 'DUE:' . $this->vdate($task['date']['due']) . CRLF;
        }
        if ($task['date']['completed']) {
            $ret .= 'COMPLETED:' . $this->vdate($task['date']['completed']) . CRLF;
        }
        if ($task['user']) {
            $ret .= 'ORGANIZER;CN="' . $this->vsc($task['user']['name']) . '":' .
                'MAILTO:' . $task['user']['mail'] . CRLF;
        }
        $ret .= 'STATUS:'.$this->vstatus($task['status']).CRLF;
        if (is_numeric($task['priority'])) {
            $ret .= 'PRIORITY:' . (7 - ($task['priority'] * 2)) . CRLF;
        }
        $ret .= 'CLASS:'.$this->vclass($id).CRLF.
            'END:VTODO'.CRLF;
        return $ret;
    }

    /**
     * Encodes vCard / iCal special characters
     *
     * @param string|array $string
     * @return array|string|string[]
     */
    protected function vsc($string) {
        $search = ["\\", ",", ";", "\n", "\r"];
        $replace = ["\\\\", "\\,", "\\;", "\\n", "\\n"];
        return str_replace($search, $replace, $string);
    }

    /**
     * Generates YYYYMMDD"T"hhmmss"Z" UTC time date format (ISO 8601 / RFC 3339)
     *
     * @param int $date timestamp
     * @param bool $extended other date format
     * @return false|string
     */
    public function vdate($date, $extended = false) {
        if ($extended) {
            return strftime('%Y-%m-%dT%H:%M:%SZ', $date);
        } else {
            return strftime('%Y%m%dT%H%M%SZ', $date);
        }
    }

    /**
     * Returns VTODO status
     *
     * @param int $status
     * @return string
     */
    public function vstatus($status) {
        switch ($status) {
            case -1:
                return 'CANCELLED';
            case 1:
            case 2:
                return 'IN-PROCESS';
            case 3:
            case 4:
                return 'COMPLETED';
            default:
                return 'NEEDS-ACTION';
        }
    }

    /**
     * Returns VTODO categories
     *
     * @param string|array $cat
     * @return string
     */
    protected function vcategories($cat) {
        if (!is_array($cat)) {
            $cat = explode(' ', $cat);
        }
        return join(',', $this->vsc($cat));
    }

    /**
     * Returns access classification for VTODO
     *
     * @param string $id page id
     * @return string
     */
    protected function vclass($id) {
        if (auth_quickaclcheck($id) >= AUTH_READ) {
            return 'PUBLIC';
        } else {
            return 'PRIVATE';
        }
    }

    /**
     * Show the form to create a new task.
     * The function just forwards the call to the old or new function.
     *
     * @param string $ns              The DokuWiki namespace in which the new task
     *                                page shall be created
     * @param bool   $selectUser      If false then create a simple input line for the user field.
     *                                If true then create a drop down list.
     * @param bool   $selectUserGroup If not null and if $selectUser==true then the drop down list
     *                                for the user field will only show users who are members of
     *                                the user group given in $selectUserGroup.
     */
    public function newTaskForm($ns, $selectUser=false, $selectUserGroup=null) {
        if (class_exists('dokuwiki\Form\Form')) {
            return $this->newTaskFormNew($ns, $selectUser, $selectUserGroup);
        } else {
            return $this->newTaskFormOld($ns, $selectUser, $selectUserGroup);
        }
    }

    /**
     * Show the form to create a new task.
     * This is the new version using class dokuwiki\Form\Form.
     *
     * @see newTaskForm
     */
    protected function newTaskFormNew($ns, $selectUser=false, $selectUserGroup=null) {
        global $ID, $lang, $INFO, $auth;

        $form = new dokuwiki\Form\Form(['id' => 'task__newtask_form']);

        // Open fieldset
        $form->addFieldsetOpen($this->getLang('newtask'));

        // Set hidden fields
        $form->setHiddenField ('id', $ID);
        $form->setHiddenField ('do', 'newtask');
        $form->setHiddenField ('ns', $ns);

        // Set input filed for task title
        $input = $form->addTextInput('title', null);
        $input->attr('id', 'task__newtask_title');
        $input->attr('size', '40');

        // Set input field for user (either text field or drop down box)
        $form->addHTML('<table class="blind"><tr><th>'.$this->getLang('user').':</th><td>');
        if(!$selectUser) {
            // Old way input field
            $input = $form->addTextInput('user', null);
            $input->attr('value', hsc($INFO['userinfo']['name']));
        } else {
            // Select user from drop down list
            $filter = [];
            $filter['grps'] = $selectUserGroup;
            $options = [];
            if ($auth) {
                foreach ($auth->retrieveUsers(0, 0, $filter) as $curr_user) {
                    $options [] = $curr_user['name'];
                }
            }
            $input = $form->addDropdown('user', $options, null);
            $input->val($INFO['userinfo']['name']);
        }
        $form->addHTML('</td></tr>');

        // Field for due date
        if ($this->getConf('datefield')) {
            $form->addHTML('<tr><th>'.$this->getLang('date').':</th><td>');
            $input = $form->addTextInput('date', null);
            $input->attr('value', date('Y-m-d'));
            $form->addHTML('</td></tr>');
        }

        // Select priority from drop down list
        $form->addHTML('<tr><th>'.$this->getLang('priority').':</th><td>');
        $options = [];
        $options [''] = $this->getLang('low');
        $options ['!'] = $this->getLang('medium');
        $options ['!!'] = $this->getLang('high');
        $options ['!!!'] = $this->getLang('critical');
        $input = $form->addDropdown('priority', $options, null);
        $input->attr('size', '1');
        $input->val($this->getLang('low'));
        $form->addHTML('</td></tr>');

        $form->addHTML('</table>');

        // Add button
        $form->addButton(null, $lang['btn_create']);

        // Close fieldset
        $form->addFieldsetClose();

        // Generate the HTML-Representation of the form
        $ret = '<div class="newtask_form">';
        $ret .= $form->toHTML();
        $ret .= '</div>';

        return $ret;
    }

    /**
     * Show the form to create a new task.
     * This is the old version, creating all HTML code on its own.
     *
     * @see newTaskForm
     * @deprecated 2017-03-30
     */
    protected function newTaskFormOld($ns, $selectUser=false, $selectUserGroup=null) {
        global $ID, $lang, $INFO, $auth;

        $ret =  '<div class="newtask_form">';
        $ret .= '<form id="task__newtask_form"  method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">';
        $ret .= '<fieldset>';
        $ret .= '<legend> '.$this->getLang('newtask').': </legend>';
        $ret .= '<input type="hidden" name="id" value="'.$ID.'" />';
        $ret .= '<input type="hidden" name="do" value="newtask" />';
        $ret .= '<input type="hidden" name="ns" value="'.$ns.'" />';
        $ret .= '<input class="edit" type="text" name="title" id="task__newtask_title" size="40" tabindex="1" />';
        $ret .= '<table class="blind"><tr>';

        if(!$selectUser) {
            // Old way input field
            $ret .= '<th>'.$this->getLang('user').':</th>';
            $ret .= '<td><input type="text" name="user" value="'.hsc($INFO['userinfo']['name']).'" class="edit" tabindex="2" /></td>';
        } else {
            // Select user from drop down list
            $ret .= '<th>'.$this->getLang('user').':</th>';
            $ret .= '<td><select name="user">';

            $filter = [];
            $filter['grps'] = $selectUserGroup;
            if ($auth) {
                foreach ($auth->retrieveUsers(0, 0, $filter) as $curr_user) {
                    $ret .= '<option' . ($curr_user['name'] == $INFO['userinfo']['name'] ? ' selected="selected"' : '') . '>' . $curr_user['name'] . '</option>';
                }
            }
            $ret .= '</select></td>';
        }

        $ret .= '</tr>';
        if ($this->getConf('datefield')) { // field for due date
            $ret .= '<tr><th>'.$this->getLang('date').':</th>';
            $ret .= '<td><input type="text" name="date" value="'.date('Y-m-d').'" class="edit" tabindex="3" /></td></tr>';
        }
        $ret .= '<tr><th>'.$this->getLang('priority').':</th><td>';
        $ret .= '<select name="priority" size="1" tabindex="4" class="edit">';
        $ret .= '<option value="" selected="selected">'.$this->getLang('low').'</option>';
        $ret .= '<option value="!">'.$this->getLang('medium').'</option>';
        $ret .= '<option value="!!">'.$this->getLang('high').'</option>';
        $ret .= '<option value="!!!">'.$this->getLang('critical').'</option>';
        $ret .= '</select>';
        $ret .= '</td></tr></table>';
        $ret .= '<input class="button" type="submit" value="'.$lang['btn_create'].'" tabindex="5" />';
        $ret .= '</fieldset></form></div>'.DOKU_LF;
        return $ret;
    }
}
