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
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');
 
class syntax_plugin_task_task extends DokuWiki_Syntax_Plugin {

  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2007-01-03',
      'name'   => 'Task Plugin (task component)',
      'desc'   => 'Handles indivudual tasks on a wiki page',
      'url'    => 'http://www.wikidesign.ch/en/plugin/task/start',
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
    
    // strip markup and split arguments
    $match = substr($match, 6, -2);
    $priority = strspn(strstr($match, '!'), '!');
    $match = trim($match, ':!');
    list($user, $date) = explode('?', $match);
    
    // save task meta file if changes were made
    if ($my =& plugin_load('helper', 'task')){
      $date = $my->_interpretDate($date);
      
      $current = $my->readTask($ID);
      if (($current['user'] != $user)
        || ($current['date']['due'] != $date)
        || ($current['priority'] != $priority)){
        $task = array(
          'user'     => $user,
          'date'     => $date,
          'priority' => $priority,
        );
        $my->writeTask($ID, $task);
      }
    }   
    return array($user, $date, $priority);
  }      
 
  function render($mode, &$renderer, $data){
    global $conf;
  
    list($user, $date, $priority) = $data;
    
    // XHTML output
    if ($mode == 'xhtml'){
      
      // strip time from preferred date format
      $onlydate = trim($conf['dformat'], 'AaBGgHhiOTZ:');
      
      // prepare data
      $status = $this->_getStatus($user, $sn);
      $due = '';
      if ($date && ($sn < 3)){
        if ($date + 86400 < time()) $due = 'overdue';
        elseif ($date < time()) $due = 'due';
      }
      if ($priority) $class = ' class="priority'.$priority.($due ? ' '.$due : '').'"';
      if ($due) $due = ' class="'.$due.'"';
      
      // generate output
      $renderer->doc .= '<div class="task">'.DOKU_LF.
        '<fieldset'.$class.'>'.DOKU_LF.
        DOKU_TAB.'<legend>'.$this->getLang('task').'</legend>'.DOKU_LF.
        '<table class="blind">'.DOKU_LF;
      if ($user) $this->_tablerow('user', hsc($user), $renderer);
      if ($date) $this->_tablerow('date', date($onlydate, $date), $renderer, $due);
      $this->_tablerow('status', $status, $renderer);
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
  function _tablerow($header, $data, &$renderer, $class = ''){
    $renderer->doc .= DOKU_TAB.'<tr'.$class.'>'.DOKU_LF.DOKU_TAB.DOKU_TAB;
    $renderer->tableheader_open(1, '');
    $renderer->doc .= hsc($this->getLang($header)).':';
    $renderer->tableheader_close();
    $renderer->tablecell_open();
    $renderer->doc .= $data;
    $renderer->tablecell_close();
    $renderer->tablerow_close();
  }
  
  /**
   * Returns the status cell contents
   */
  function _getStatus($user, &$status){
    global $INFO, $ID;
    
    $my =& plugin_load('helper', 'task');
    
    $ret = '';
    $task = $my->readTask($ID);
    $status = $task['status'];
    $responsible = $my->_isResponsible($user);
    
    if ($INFO['perm'] == AUTH_ADMIN){
      $ret = $this->_statusMenu(array(-1, 0, 1, 2, 3, 4), $status, $my);
    } elseif ($responsible){
      if ($status < 3) $ret = $this->_statusMenu(array(-1, 0, 1, 2, 3), $status, $my);
    } else {
      if ($status == 0) $ret = $this->_statusMenu(array(0, 1), $status, $my);
      elseif ($status == 3) $ret = $this->_statusMenu(array(2, 3, 4), $status, $my);
    }
       
    if (!$ret && $my) $ret = $my->statusLabel($status);
    
    return $ret;
  }
    
  /**
   * Returns the XHTML for the status popup menu
   */
  function _statusMenu($options, $status, &$my){
    global $ID, $lang;
        
    $ret = '<form id="task__changetask_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">'.DOKU_LF.
      '<div class="no">'.DOKU_LF.
      DOKU_TAB.'<input type="hidden" name="id" value="'.$ID.'" />'.DOKU_LF.
      DOKU_TAB.'<input type="hidden" name="do" value="changetask" />'.DOKU_LF.
      DOKU_TAB.'<select name="status" size="1" class="edit">'.DOKU_LF;
    foreach ($options as $option){
      $ret .= DOKU_TAB.DOKU_TAB.'<option value="'.$option.'"';
      if ($status == $option) $ret .= ' selected="selected"';
      $ret .= '>'.$my->statusLabel($option).'</option>'.DOKU_LF;
    }
    $ret .= DOKU_TAB.'</select>'.DOKU_LF.
      DOKU_TAB.'<input class="button" type="submit" value="'.$this->getLang('btn_change').'" />'.DOKU_LF.
      '</div>'.DOKU_LF.
      '</form>';
    return $ret;
  }
   
}
 
//Setup VIM: ex: et ts=4 enc=utf-8 :
