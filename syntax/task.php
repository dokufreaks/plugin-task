<?php
/**
 * Task Plugin, task component: Handles individual tasks on a wiki page
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */
 
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
 
class syntax_plugin_task_task extends DokuWiki_Syntax_Plugin {

    var $my   = NULL;
    var $task = array();

    function getType() { return 'substition'; }
    function getSort() { return 305; }
    function getPType() { return 'block';}
  
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~TASK.*?~~', $mode, 'plugin_task_task');
    }
  
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;
        global $INFO;
        global $ACT;
        global $REV;
     
        // strip markup and split arguments
        $match = substr($match, 6, -2);
        $priority = strspn(strstr($match, '!'), '!');
        $match = trim($match, ':!');
        list($user, $date) = explode('?', $match);
    
        if ($my =& plugin_load('helper', 'task')) {
            $date = $my->_interpretDate($date);
      
            $task = array(
                    'user'     => array('name' => $user),
                    'date'     => array('due' => $date),
                    'priority' => $priority
                    );

            // save task meta file if changes were made 
            // but only for already existing tasks, or when the page is saved 
            // $REV prevents overwriting current task information with old revision ones
            if(@file_exists(metaFN($ID, '.task')) && $ACT != 'preview' && !$REV) {
                $current = $my->readTask($ID);
                if (($current['user']['name'] != $user) || ($current['date']['due'] != $date) || ($current['priority'] != $priority)) {
                    $my->writeTask($ID, $task);
                }
            } elseif ($ACT != 'preview' && !$REV) {
                $my->writeTask($ID, $task);
            }
        }
        return array($user, $date, $priority);
    }      
 
    function render($mode, Doku_Renderer $renderer, $data) {  
        global $ID;

        list($user, $date, $priority) = $data;
    
        // XHTML output
        if ($mode == 'xhtml') {
            $renderer->info['cache'] = false;
      
            // prepare data
            $this->_loadHelper();

            $task = array();
            if(@file_exists(metaFN($ID, '.task'))) {
                $task = $this->my->readTask($ID);
            }

            $status = $this->_getStatus($user, $sn);
            $due = '';

            if ($date && ($sn < 3)) {
                if ($date + 86400 < time()) $due = 'overdue';
                elseif ($date < time()) $due = 'due';
            }

            $class = ' class="vtodo';
            if ($priority) $class .= ' priority' . $priority;
            if ($due) {
                $class .= ' '.$due;
                $due = ' class="'.$due.'"';
            }

            $class .= '"';
          
            // generate output
            $renderer->doc .= '<div class="vcalendar">'.DOKU_LF
                            . '<fieldset'.$class.'>'.DOKU_LF
                            . '<legend>'.$this->_icsDownload().$this->getLang('task').'</legend>'.DOKU_LF
                            . '<table class="blind">'.DOKU_LF;

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
        } elseif ($mode == 'metadata') {
            return true;
        }

        return false;
    }
  
    /**
     * Outputs a table row
     */
    function _tablerow($header, $data, &$renderer, $trclass = '', $tdclass = '') {
        if ($tdclass) $tdclass = ' class="'.$tdclass.'"';

        $renderer->doc .= '<tr'.$trclass.'>'.DOKU_LF;
        $renderer->tableheader_open(1, '');
        if ($header) $renderer->doc .= hsc($this->getLang($header)).':';
        $renderer->tableheader_close();
        $renderer->doc .= '<td'.$tdclass.'>'.$data;
        $renderer->tablecell_close();
        $renderer->tablerow_close();
    }
  
    /**
     * Loads the helper plugin and gets task data for current ID
     */
    function _loadHelper() {
        global $ID;
        $this->my =& plugin_load('helper', 'task');
        if (!is_object($this->my)) return false;
        $this->task = $this->my->readTask($ID);
        return $true;
    }

    /**
     * Returns the status cell contents
     */
    function _getStatus($user, &$status) {
        global $INFO;

        $ret = '';
        $status = $this->task['status'];
        $responsible = $this->my->_isResponsible($user);

        if ($INFO['perm'] == AUTH_ADMIN) {
            $ret = $this->_statusMenu(array(-1, 0, 1, 2, 3, 4), $status);
        } elseif ($responsible) {
            if ($status < 3) $ret = $this->_statusMenu(array(-1, 0, 1, 2, 3), $status);
        } else {
            if ($status == 0) {
                $ret = $this->_statusMenu(array(0, 1), $status);
            } elseif ($status == 3) {
                $ret = $this->_statusMenu(array(2, 3, 4), $status);
            }
        }

        if (!$ret && $this->my) $ret = $this->my->statusLabel($status);

        return '<abbr class="status" title="'.$this->my->_vstatus($status).'">'. $ret .'</abbr>';
    }

    /**
     * Returns the XHTML for the status drop down list.
     * Just forwards call to the old or new function.
     */
    function _statusMenu($options, $status) {
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
    function _statusMenuNew($options, $status) {
        global $ID, $lang;

        $form = new dokuwiki\Form\Form(array('id' => 'task__changetask_form'));
        $pos = 1;

        $form->addHTML('<div class="no">', $pos++);

        // Set hidden fields
        $form->setHiddenField ('id', $ID);
        $form->setHiddenField ('do', 'changetask');

        // Select status from drop down list
        $dropDownOptions = array();
        $selected = NULL;
        $value = 0;
        foreach ($options as $option) {
            if ($status == $option) {
                $selected = $option.' ';
            }
            $dropDownOptions [$option.' '] = $this->my->statusLabel($option);
        }
        $input = $form->addDropdown('status', $dropDownOptions, NULL, $pos++);
        $input->val($selected);

        // Add button
        $form->addButton(NULL, $this->getLang('btn_change'), $pos++);

        $form->addHTML('</div>', $pos++);

        return $form->toHTML();
    }

    /**
     * Returns the XHTML for the status popup menu.
     * Old function generating all HTML on its own.
     * 
     * @see _statusMenu
     */
    function _statusMenuOld($options, $status) {
        global $ID;
        global $lang;

        $ret  = '<form id="task__changetask_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">';
        $ret .= '<div class="no">';
        $ret .= '<input type="hidden" name="id" value="'.$ID.'" />';
        $ret .= '<input type="hidden" name="do" value="changetask" />';
        $ret .= '<select name="status" size="1" class="edit">';

        foreach ($options as $option) {
            $ret .= '<option value="'.$option.'"';
            if ($status == $option) $ret .= ' selected="selected"';
            $ret .= '>'.$this->my->statusLabel($option).'</option>';
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
    function _icsDownload() {
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
    function _hCalUser($user) {
        return '<span class="vcard"><span class="fn">' . hsc($user) . '</span></span>';
    }

    /**
     * Returns the date in hCalendar format
     */
    function _hCalDate($date) {
        global $conf;

        // strip time from preferred date format
        $onlydate = preg_replace('#%[HIMprRST]|:#', '', ($conf['dformat']));

        return '<abbr class="due" title="'.$this->my->_vdate($date, true).'">' . strftime($onlydate, $date) . '</abbr>';
    }
}
// vim:ts=4:sw=4:et:enc=utf-8: 
