<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class helper_plugin_task extends DokuWiki_Plugin {
  
    function getMethods() {
        $result = array();
        $result[] = array(
                'name'   => 'th',
                'desc'   => 'returns the header of the task column for pagelist',
                'return' => array('header' => 'string'),
                );
        $result[] = array(
                'name'   => 'td',
                'desc'   => 'returns the status of the task',
                'params' => array('id' => 'string'),
                'return' => array('label' => 'string'),
                );
        $result[] = array(
                'name'   => 'getTasks',
                'desc'   => 'get task pages, sorted by priority',
                'params' => array(
                    'namespace' => 'string',
                    'number (optional)' => 'integer',
                    'filter (optional)' => 'string'),
                'return' => array('pages' => 'array'),
                );
        $result[] = array(
                'name'   => 'readTask',
                'desc'   => 'get a single task metafile',
                'params' => array('id' => 'string'),
                'return' => array('task on success, else false' => 'array, (boolean)'),
                );
        $result[] = array(
                'name'   => 'writeTask',
                'desc'   => 'save task metdata in a file',
                'params' => array(
                    'id' => 'string',
                    'task' => 'array'),
                'return' => array('success' => 'boolean'),
                );
        $result[] = array(
                'name'   => 'statusLabel',
                'desc'   => 'returns the status label for a given integer',
                'params' => array('status' => 'integer'),
                'return' => array('label' => 'string'),
                );
        return $result;
    }
  
    /**
     * Returns the column header for the Pagelist Plugin
     */
    function th() {
        return $this->getLang('status');
    }

    /**
     * Returns the status of the task
     */
    function td($id) {
        $task = $this->readTask($id);
        return $this->statusLabel($task['status']);
    }

    /**
     * Returns an array of task pages, sorted by priority
     */
    function getTasks($ns, $num = NULL, $filter = '', $user = NULL) {
        global $conf;

        if (!$filter) $filter = strtolower($_REQUEST['filter']);

        require_once(DOKU_INC.'inc/search.php');

        $dir = $conf['datadir'].($ns ? '/'.str_replace(':', '/', $ns): '');

        // returns the list of pages in the given namespace and it's subspaces
        $items = array();
        $opts = array();
        $ns = utf8_encodeFN(str_replace(':', '/', $ns));
        search($items, $conf['datadir'], 'search_allpages', $opts, $ns);

        // add pages with comments to result
        $result = array();
        foreach ($items as $item) {
            $id = $item['id'];

            // skip pages without task
            if (!$task = $this->readTask($id)) continue;

            $date = $task['date']['due'];
            $responsible = $this->_isResponsible($task['user']);

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
                    if (($task['status'] < 0) || ($task['status'] > 2)) continue;
                }
            }

            // skip other's tasks if filter is 'my'
            if (($filter == 'my') && (!$responsible)) continue;

            // skip assigned and not new tasks if filter is 'new'
            if (($filter == 'new') && ($task['user']['name'] || ($task['status'] != 0))) continue;

            // filter is 'due' or 'overdue' 
            if (in_array($filter, array('due', 'overdue'))) {
                if (!$date || ($date > time()) || ($task['status'] > 2)) continue;
                elseif (($date + 86400 < time()) && ($filter == 'due')) continue;
                elseif (($date + 86400 > time()) && ($filter == 'overdue')) continue;
            } 

            $result[$task['key']] = array(
                    'id'       => $id,
                    'date'     => $date,
                    'user'     => $task['user']['name'],
                    'status'   => $this->statusLabel($task['status']),
                    'priority' => $task['priority'],
                    'perm'     => $perm,
                    'file'     => $task['file'],
                    'exists'   => true,
                    );
        }

        // finally sort by time of last comment
        krsort($result);

        if (is_numeric($num)) $result = array_slice($result, 0, $num);

        return $result;
    }

    /**
     * Reads the .task metafile
     */
    function readTask($id) {
        $file = metaFN($id, '.task');
        if (!@file_exists($file)) {
            $data = p_get_metadata($id, 'task');
            if (is_array($data)) {
                $data['date'] = array('due' => $data['date']);
                $data['user'] = array('name' => $data['user']);
                $meta = array('task' => NULL);
                if ($this->writeTask($id, $data)) p_set_metadata($id, $meta);
            }
        } else {
            $data = unserialize(io_readFile($file, false));
        }
        if (!is_array($data) || empty($data)) return false;
        $data['file']   = $file;
        $data['exists'] = true;
        return $data;
    }

    /**
     * Saves the .task metafile
     */
    function writeTask($id, $data) {
        if (!is_array($data)) return false;
        $file = ($data['file'] ? $data['file'] : metaFN($id, '.task'));

        // remove file and exists keys
        unset($data['file']);
        unset($data['exists']);

        // set creation or modification time
        if (!is_array($data['date'])) $data['date'] = array('due' => $data['date']);
        if (!@file_exists($file) || !$data['date']['created']) {
            $data['date']['created'] = time();
        } else {
            $data['date']['modified'] = time();
        }

        if (!is_array($data['user'])) $data['user'] = array('name' => $data['user']);

        if (!isset($data['status'])) {    // make sure we don't overwrite status
            $current = unserialize(io_readFile($file, false));
            $data['status'] = $current['status'];
        } elseif ($data['status'] == 3) { // set task completion time
            $data['date']['completed'] = time();
        }

        // generate vtodo for iCal file download
        $data['vtodo'] = $this->_vtodo($id, $data);

        // generate sortkey with priority and creation date
        $data['key'] = chr($data['priority'] + 97).(2000000000 - $data['date']['created']);

        // save task metadata
        $ok = io_saveFile($file, serialize($data));

        // and finally notify users
        $this->_notify($data);
        return $ok;
    }

    /**
     * Returns the label of a status
     */
    function statusLabel($status) {
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
     */
    function priorityLabel($priority) {
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
     * Is the given task assigned to the current user?
     */
    function _isResponsible($user) {
        global $INFO;

        if (!$user) return false;

        if (isset($user['id']) && $user['id'] == $_SERVER['REMOTE_USER'] || isset($user['name']) && $user['name'] == $INFO['userinfo']['name'] || $user == $INFO['userinfo']['name']) {
            return true;
        }

        return false;
    }

    /**
     * Interpret date with strtotime()
     */
    function _interpretDate($str) {
        if (!$str) return NULL;

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
        if ($date === -1) $date = NULL;
        return $date;
    }

    /**
     * Sends a notify mail on new or changed task
     *
     * @param  array  $task  data array of the task
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    function _notify($task) {
        global $conf;
        global $ID;

        if ((!$conf['subscribers']) && (!$conf['notify'])) return; //subscribers enabled?
        $data = array('id' => $ID, 'addresslist' => '', 'self' => false);
        trigger_event('COMMON_NOTIFY_ADDRESSLIST', $data, 'subscription_addresslist');
        $bcc = $data['addresslist'];
        if ((empty($bcc)) && (!$conf['notify'])) return;
        $to   = $conf['notify'];
        $text = io_readFile($this->localFN('subscribermail'));

        $text = str_replace('@PAGE@', $ID, $text);
        $text = str_replace('@TITLE@', $conf['title'], $text);
        if(!empty($task['date']['due'])) {
            $dformat = preg_replace('#%[HIMprRST]|:#', '', ($conf['dformat']));
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
        if ($task['status'] == 0) $subject .= $this->getLang('mail_newtask');
        else $subject .= $this->getLang('mail_changedtask');

        mail_send($to, $subject, $text, $conf['mailfrom'], '', $bcc);
    }

    /**
     * Generates a VTODO section for iCal file download
     */
    function _vtodo($id, $task) {
        if (!defined('CRLF')) define('CRLF', "\r\n");

        $meta = p_get_metadata($id);

        $ret = 'BEGIN:VTODO'.CRLF.
            'UID:'.$id.'@'.$_SERVER['SERVER_NAME'].CRLF.
            'URL:'.wl($id, '', true, '&').CRLF.
            'SUMMARY:'.$this->_vsc($meta['title']).CRLF;
        if ($meta['description']['abstract'])
            $ret .= 'DESCRIPTION:'.$this->_vsc($meta['description']['abstract']).CRLF;
        if ($meta['subject'])
            $ret .= 'CATEGORIES:'.$this->_vcategories($meta['subject']).CRLF;
        if ($task['date']['created'])
            $ret .= 'CREATED:'.$this->_vdate($task['date']['created']).CRLF;
        if ($task['date']['modified'])
            $ret .= 'LAST-MODIFIED:'.$this->_vdate($task['date']['modified']).CRLF;
        if ($task['date']['due'])
            $ret .= 'DUE:'.$this->_vdate($task['date']['due']).CRLF;
        if ($task['date']['completed'])
            $ret .= 'COMPLETED:'.$this->_vdate($task['date']['completed']).CRLF;
        if ($task['user']) $ret .= 'ORGANIZER;CN="'.$this->_vsc($task['user']['name']).'":'.
            'MAILTO:'.$task['user']['mail'].CRLF;
        $ret .= 'STATUS:'.$this->_vstatus($task['status']).CRLF;
        if (is_numeric($task['priority']))
            $ret .= 'PRIORITY:'.(7 - ($task['priority'] * 2)).CRLF;
        $ret .= 'CLASS:'.$this->_vclass($id).CRLF.
            'END:VTODO'.CRLF;
        return $ret;
    }

    /**
     * Encodes vCard / iCal special characters
     */
    function _vsc($string) {
        $search = array("\\", ",", ";", "\n", "\r");
        $replace = array("\\\\", "\\,", "\\;", "\\n", "\\n");
        return str_replace($search, $replace, $string);
    }

    /**
     * Generates YYYYMMDD"T"hhmmss"Z" UTC time date format (ISO 8601 / RFC 3339)
     */
    function _vdate($date, $extended = false) {
        if ($extended) return strftime('%Y-%m-%dT%H:%M:%SZ', $date);
        else return strftime('%Y%m%dT%H%M%SZ', $date);
    }

    /**
     * Returns VTODO status
     */
    function _vstatus($status) {
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
     */
    function _vcategories($cat) {
        if (!is_array($cat)) $cat = explode(' ', $cat);
        return join(',', $this->_vsc($cat));
    }

    /**
     * Returns access classification for VTODO
     */
    function _vclass($id) {
        global $USERINFO; // checks access rights for anonymous user
        if (auth_aclcheck($id, '', $USERINFO['grps'])) return 'PUBLIC';
        else return 'PRIVATE';
    }

    /**
     * Show the form to create a new task.
     * The function just forwards the call to the old or new function.
     * 
     * @param string $ns              The DokuWiki namespace in which the new task
     *                                page shall be created
     * @param bool   $selectUser      If false then create a simple input line for the user field.
     *                                If true then create a drop down list.
     * @param bool   $selectUserGroup If not NULL and if $selectUser==true then the drop down list
     *                                for the user field will only show users who are members of
     *                                the user group given in $selectUserGroup.
     */
    function _newTaskForm($ns, $selectUser=false, $selectUserGroup=NULL) {
        if (class_exists('dokuwiki\Form\Form')) {
            return $this->_newTaskFormNew($ns, $selectUser, $selectUserGroup);
        } else {
            return $this->_newTaskFormOld($ns, $selectUser, $selectUserGroup);
        }
    }

    /**
     * Show the form to create a new task.
     * This is the new version using class dokuwiki\Form\Form.
     * 
     * @see _newTaskForm
     */
    protected function _newTaskFormNew($ns, $selectUser=false, $selectUserGroup=NULL) {
        global $ID, $lang, $INFO, $auth;

        $form = new dokuwiki\Form\Form(array('id' => 'task__newtask_form'));
        $pos = 1;

        // Open fieldset
        $form->addFieldsetOpen($this->getLang('newtask'), $pos++);

        // Set hidden fields
        $form->setHiddenField ('id', $ID);
        $form->setHiddenField ('do', 'newtask');
        $form->setHiddenField ('ns', $ns);

        // Set input filed for task title
        $input = $form->addTextInput('title', NULL, $pos++);
        $input->attr('id', 'task__newtask_title');
        $input->attr('size', '40');

        // Set input field for user (either text field or drop down box)
        $form->addHTML('<table class="blind"><tr><th>'.$this->getLang('user').':</th><td>', $pos++);
        if(!$selectUser) {
            // Old way input field
            $input = $form->addTextInput('user', NULL, $pos++);
            $input->attr('value', hsc($INFO['userinfo']['name']));
        } else {
            // Select user from drop down list
            $filter = array();
            $filter['grps'] = $selectUserGroup;
            $options = array();
            foreach ($auth->retrieveUsers(0, 0, $filter) as $curr_user) {
                $options [] = $curr_user['name'];
            }
            $input = $form->addDropdown('user', $options, NULL, $pos++);
            $input->val($INFO['userinfo']['name']);
        }
        $form->addHTML('</td></tr>', $pos++);

        // Field for due date
        if ($this->getConf('datefield')) {
            $form->addHTML('<tr><th>'.$this->getLang('date').':</th><td>', $pos++);
            $input = $form->addTextInput('date', NULL, $pos++);
            $input->attr('value', date('Y-m-d'));
            $form->addHTML('</td></tr>', $pos++);
        }

        // Select priority from drop down list
        $form->addHTML('<tr><th>'.$this->getLang('priority').':</th><td>');
        $filter = array();
        $filter['grps'] = $selectUserGroup;
        $options = array();
        $options [''] = $this->getLang('low');
        $options ['!'] = $this->getLang('medium');
        $options ['!!'] = $this->getLang('high');
        $options ['!!!'] = $this->getLang('critical');
        $input = $form->addDropdown('priority', $options, NULL, $pos++);
        $input->attr('size', '1');
        $input->val($this->getLang('low'));
        $form->addHTML('</td></tr>', $pos++);

        $form->addHTML('</table>', $pos++);

        // Add button
        $form->addButton(NULL, $lang['btn_create'], $pos++);

        // Close fieldset
        $form->addFieldsetClose($pos++);

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
     * @see _newTaskForm
     */
    protected function _newTaskFormOld($ns, $selectUser=false, $selectUserGroup=NULL) {
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

            $filter = array();
            $filter['grps'] = $selectUserGroup;
            foreach ($auth->retrieveUsers(0, 0, $filter) as $curr_user) {
                $ret .= '<option' . ($curr_user['name'] == $INFO['userinfo']['name'] ? ' selected="selected"' : '') . '>' . $curr_user['name'] . '</option>';
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
// vim:ts=4:sw=4:et:enc=utf-8:
