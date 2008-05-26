<?php
/**
 * Task Plugin, task component: Handles individual tasks on a wiki page
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */
 
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');
 
class syntax_plugin_task_task extends DokuWiki_Syntax_Plugin {

    var $my   = NULL;
    var $task = array();

    /**
     * return some information about the plugin
     */
    function getInfo(){
        return array(
            'author' => 'Gina Häußge, Michael Klier, Esther Brunner',
            'email'  => 'dokuwiki@chimeric.de',
            'date'   => '2008-05-24',
            'name'   => 'Task Plugin (task component)',
            'desc'   => 'Handles indivudual tasks on a wiki page',
            'url'    => 'http://wiki.splitbrain.org/plugin:task',
        );
    }

    function getType(){ return 'substition'; }
    function getSort(){ return 305; }
    function getPType(){ return 'block';}
  
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~TASK.*?~~', $mode, 'plugin_task_task');
    }
  
    function handle($match, $state, $pos, &$handler){
        global $ID;
        global $INFO;
        global $ACT;
     
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
            // but only for already existing tasks or when the page is saved
            if(@file_exists(metaFN($ID, '.task')) && $ACT == 'save') {
                $current = $my->readTask($ID);
                if (($current['user']['name'] != $user) || ($current['date']['due'] != $date) || ($current['priority'] != $priority)) {
                    $my->writeTask($ID, $task);
                }
            } elseif ($ACT == 'save') {
                $my->writeTask($ID, $task);
            }
        }
        return array($user, $date, $priority);
    }      
 
    function render($mode, &$renderer, $data){  
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
            if ($due){
                $class .= ' '.$due;
                $due = ' class="'.$due.'"';
            }

            $class .= '"';
          
            // generate output
            $renderer->doc .= '<div class="vcalendar">'.DOKU_LF
                            . '<fieldset'.$class.'>'.DOKU_LF.DOKU_TAB
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

            $renderer->table_close();
            $renderer->doc .= '</fieldset>'.DOKU_LF.
            '</div>'.DOKU_LF;

            return true;
      
        // for metadata renderer
        } elseif ($mode == 'metadata'){
            return true;
        }

        return false;
    }
  
    /**
     * Outputs a table row
     */
    function _tablerow($header, $data, &$renderer, $trclass = '', $tdclass = ''){
        if ($tdclass) $tdclass = ' class="'.$tdclass.'"';

        $renderer->doc .= DOKU_TAB.'<tr'.$trclass.'>'.DOKU_LF.DOKU_TAB.DOKU_TAB;
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
    function _loadHelper(){
        global $ID;
        $this->my =& plugin_load('helper', 'task');
        if (!is_object($this->my)) return false;
        $this->task = $this->my->readTask($ID);
        return $true;
    }

    /**
     * Returns the status cell contents
     */
    function _getStatus($user, &$status){
        global $INFO;

        $ret = '';
        $status = $this->task['status'];
        $responsible = $this->my->_isResponsible($user);

        if ($INFO['perm'] == AUTH_ADMIN){
            $ret = $this->_statusMenu(array(-1, 0, 1, 2, 3, 4), $status);
        } elseif ($responsible){
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
     * Returns the XHTML for the status popup menu
     */
    function _statusMenu($options, $status){
        global $ID;
        global $lang;

        $ret = '<form id="task__changetask_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">'.DOKU_LF.
            '<div class="no">'.DOKU_LF.
            DOKU_TAB.'<input type="hidden" name="id" value="'.$ID.'" />'.DOKU_LF.
            DOKU_TAB.'<input type="hidden" name="do" value="changetask" />'.DOKU_LF.
            DOKU_TAB.'<select name="status" size="1" class="edit">'.DOKU_LF;

        foreach ($options as $option){
            $ret .= DOKU_TAB.DOKU_TAB.'<option value="'.$option.'"';
            if ($status == $option) $ret .= ' selected="selected"';
            $ret .= '>'.$this->my->statusLabel($option).'</option>'.DOKU_LF;
        }

        $ret .= DOKU_TAB.'</select>'.DOKU_LF.
            DOKU_TAB.'<input class="button" type="submit" value="'.$this->getLang('btn_change').'" />'.DOKU_LF.
            '</div>'.DOKU_LF.
            '</form>';
        return $ret;
    }

    /**
     * Returns the download link for the iCal file
     */
    function _icsDownload(){
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
    function _hCalUser($user){
        return '<span class="vcard"><span class="fn">' . hsc($user) . '</span></span>';
    }

    /**
     * Returns the date in hCalendar format
     */
    function _hCalDate($date){
        global $conf;

        // strip time from preferred date format
        $onlydate = preg_replace('#%[HIMprRST]|:#', '', ($conf['dformat']));

        return '<abbr class="due" title="'.$this->my->_vdate($date, true).'">' . strftime($onlydate, $date) . '</abbr>';
    }
}
 
//Setup VIM: ex: et ts=4 enc=utf-8 :
