<?php
/**
 * Task Plugin, task component: Handles individual tasks on a wiki page
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

class syntax_plugin_task_task extends DokuWiki_Syntax_Plugin {

    protected $helper = null;
    protected $task = [];

    public function getType() { return 'substition'; }
    public function getSort() { return 305; }
    public function getPType() { return 'block';}

    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~TASK.*?~~', $mode, 'plugin_task_task');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;
        global $ACT;
        global $REV;

        // strip markup and split arguments
        $match = substr($match, 6, -2);
        $priority = strspn(strstr($match, '!'), '!');
        $match = trim($match, ':!');
        list($user, $date) = explode('?', $match);

        /** @var helper_plugin_task $helper */
        if ($helper = plugin_load('helper', 'task')) {
            $date = $helper->interpretDate($date);

            $task = [
                    'user'     => ['name' => $user],
                    'date'     => ['due' => $date],
                    'priority' => $priority
            ];

            // save task meta file if changes were made
            // but only for already existing tasks, or when the page is saved
            // $REV prevents overwriting current task information with old revision ones
            if(@file_exists(metaFN($ID, '.task')) && $ACT != 'preview' && !$REV) {
                $current = $helper->readTask($ID);
                if ($current['user']['name'] != $user || $current['date']['due'] != $date || $current['priority'] != $priority) {
                    $helper->writeTask($ID, $task);
                }
            } elseif ($ACT != 'preview' && !$REV) {
                $helper->writeTask($ID, $task);
            }
        }
        return [$user, $date, $priority];
    }

    public function render($format, Doku_Renderer $renderer, $data) {
        global $ID;

        list($user, $date, $priority) = $data;

        // XHTML output
        if ($format == 'xhtml') {
            /** @var Doku_Renderer_xhtml $renderer */
            $renderer->nocache();

            // prepare data
            $this->_loadHelper();

            $task = [];
            if(@file_exists(metaFN($ID, '.task'))) {
                $task = $this->helper->readTask($ID);
            }

            $status = $this->_getStatus($user, $sn);
            $due = '';

            if ($date && $sn < 3) {
                if ($date + 86400 < time()) {
                    $due = 'overdue';
                }
                elseif ($date < time()) {
                    $due = 'due';
                }
            }

            $class = ' class="vtodo';
            if ($priority) {
                $class .= ' priority' . $priority;
            }
            if ($due) {
                $class .= ' '.$due;
                $due = ' class="'.$due.'"';
            }

            $class .= '"';

            // generate output
            $renderer->doc .= '<div class="vcalendar">'
                            . '<fieldset'.$class.'>'
                            . '<legend>'.$this->_icsDownload().$this->getLang('task').'</legend>'
                            . '<table class="blind">';

            if ($user) {
                $this->_tablerow('user', $this->_hCalUser($user), $renderer, '', 'organizer');
            } elseif ($task['user']['name']) {
                $this->_tablerow('user', $this->_hCalUser($task['user']['name']), $renderer, '', 'organizer');
            }

            if ($date) {
                $this->_tablerow('date', $this->_hCalDate($date), $renderer, $due);
            } elseif ($task['date']['due']) {
                $this->_tablerow('date', $this->_hCalDate($task['date']['due']), $renderer, $due);
            }

            // show status update form only to logged in users
            if(isset($_SERVER['REMOTE_USER'])) {
                $this->_tablerow('status', $status, $renderer);
            }

            $renderer->doc .= '</table>'.DOKU_LF;
            $renderer->doc .= '</fieldset>'.DOKU_LF.
            '</div>'.DOKU_LF;

            return true;

        // for metadata renderer
        } elseif ($format == 'metadata') {
            return true;
        }

        return false;
    }

    /**
     * Outputs a table row
     *
     * @param string $header id of header translation string
     * @param string $data content of table cell
     * @param Doku_Renderer_xhtml $renderer
     * @param string $trclass class of row
     * @param string $tdclass class of cell
     */
    protected function _tablerow($header, $data, $renderer, $trclass = '', $tdclass = '') {
        if ($tdclass) {
            $tdclass = ' class="'.$tdclass.'"';
        }

        $renderer->doc .= '<tr'.$trclass.'>';
        $renderer->tableheader_open(1, '');
        if ($header) {
            $renderer->doc .= hsc($this->getLang($header)).':';
        }
        $renderer->tableheader_close();
        $renderer->doc .= '<td'.$tdclass.'>'.$data;
        $renderer->tablecell_close();
        $renderer->tablerow_close();
    }

    /**
     * Loads the helper plugin and gets task data for current ID
     */
    protected function _loadHelper() {
        global $ID;
        $this->helper = plugin_load('helper', 'task');
        if (!is_object($this->helper)) {
            return false;
        }
        $this->task = $this->helper->readTask($ID);
        return true;
    }

    /**
     * Returns the status cell contents
     */
    protected function _getStatus($user, &$status) {
        global $INFO;

        $ret = '';
        $status = $this->task['status'];
        $responsible = $this->helper->_isResponsible($user);

        if ($INFO['perm'] == AUTH_ADMIN) {
            $ret = $this->_statusMenu([-1, 0, 1, 2, 3, 4], $status);
        } elseif ($responsible) {
            if ($status < 3) {
                $ret = $this->_statusMenu([-1, 0, 1, 2, 3], $status);
            }
        } else {
            if ($status == 0) {
                $ret = $this->_statusMenu([0, 1], $status);
            } elseif ($status == 3) {
                $ret = $this->_statusMenu([2, 3, 4], $status);
            }
        }

        if (!$ret && $this->helper) {
            $ret = $this->helper->statusLabel($status);
        }

        return '<abbr class="status" title="'.$this->helper->_vstatus($status).'">'. $ret .'</abbr>';
    }

    /**
     * Returns the XHTML for the status drop down list.
     * Just forwards call to the old or new function.
     */
    protected function _statusMenu($options, $status) {
        if (class_exists('dokuwiki\Form\Form')) {
            return $this->_statusMenuNew($options, $status);
        } else {
            return $this->_statusMenuOld($options, $status);
        }
    }

    /**
     * Returns the XHTML for the status popup menu.
     * This is the new version using class dokuwiki\Form\Form.
     *
     * @see _statusMenu
     */
    protected function _statusMenuNew($options, $status) {
        global $ID;

        $form = new dokuwiki\Form\Form(array('id' => 'task__changetask_form'));
        $pos = 1;

        $form->addHTML('<div class="no">', $pos++);

        // Set hidden fields
        $form->setHiddenField ('id', $ID);
        $form->setHiddenField ('do', 'changetask');

        // Select status from drop down list
        $dropDownOptions = [];
        $selected = null;
        foreach ($options as $option) {
            if ($status == $option) {
                $selected = $option.' ';
            }
            $dropDownOptions [$option.' '] = $this->helper->statusLabel($option);
        }
        $input = $form->addDropdown('status', $dropDownOptions, null, $pos++);
        $input->val($selected);

        // Add button
        $form->addButton(null, $this->getLang('btn_change'), $pos++);

        $form->addHTML('</div>', $pos++);

        return $form->toHTML();
    }

    /**
     * Returns the XHTML for the status popup menu.
     * Old function generating all HTML on its own.
     *
     * @see _statusMenu
     */
    protected function _statusMenuOld($options, $status) {
        global $ID;
        global $lang;

        $ret  = '<form id="task__changetask_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">';
        $ret .= '<div class="no">';
        $ret .= '<input type="hidden" name="id" value="'.$ID.'" />';
        $ret .= '<input type="hidden" name="do" value="changetask" />';
        $ret .= '<select name="status" size="1" class="edit">';

        foreach ($options as $option) {
            $ret .= '<option value="'.$option.'"';
            if ($status == $option) {
                $ret .= ' selected="selected"';
            }
            $ret .= '>'.$this->helper->statusLabel($option).'</option>';
        }

        $ret .= '</select>';
        $ret .= '<input class="button" type="submit" value="'.$this->getLang('btn_change').'" />';
        $ret .= '</div>';
        $ret .= '</form>'.DOKU_LF;

        return $ret;
    }

    /**
     * Returns the download link for the iCal file
     */
    protected function _icsDownload() {
        global $ID;
        global $INFO;

        $uid   = hsc($ID.'@'.$_SERVER['SERVER_NAME']);
        $title = hsc($INFO['meta']['title']);
        $link  = DOKU_BASE.'lib/plugins/task/ics.php?id='.$ID;
        $src   = DOKU_BASE.'lib/plugins/task/images/ics.gif';

        $out   = '<a href="'.$link.'" class="uid" title="'.$uid.'">'
               . '<img src="'.$src.'" class="summary" alt="'.$title.'" title="'.$title.'" width="16" height="16"/>'
               . '</a> ';

        return $out;
    }

    /**
     * Returns the organizer in hCalendar format as hCard
     */
    protected function _hCalUser($user) {
        return '<span class="vcard"><span class="fn">' . hsc($user) . '</span></span>';
    }

    /**
     * Returns the date in hCalendar format
     */
    protected function _hCalDate($date) {
        global $conf;

        // strip time from preferred date format
        $onlydate = preg_replace('#%[HIMprRST]|:#', '', $conf['dformat']);

        return '<abbr class="due" title="'.$this->helper->_vdate($date, true).'">' . strftime($onlydate, $date) . '</abbr>';
    }
}
