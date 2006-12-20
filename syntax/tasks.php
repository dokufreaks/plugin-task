<?php
/**
 * Task Plugin, tasks component: lists tasks of a given namespace
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

class syntax_plugin_task_tasks extends DokuWiki_Syntax_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-20',
      'name'   => 'Task Plugin (tasks component)',
      'desc'   => 'Lists tasks of a given namespace',
      'url'    => 'http://www.wikidesign.ch/en/plugin/task/start',
    );
  }

  function getType(){ return 'substition'; }
  function getPType(){ return 'block'; }
  function getSort(){ return 306; }
  
  function connectTo($mode){
    $this->Lexer->addSpecialPattern('\{\{tasks>.+?\}\}', $mode, 'plugin_task_tasks');
  }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    $match = substr($match, 8, -2); // strip {{topic> from start and }} from end
    list($ns, $filter) = explode('?', $match);
    return array(cleanID($ns), $filter);
  }

  /**
   * Create output
   */
  function render($mode, &$renderer, $data){
    global $ID, $conf;
    
    list($ns, $filter) = $data;
    
    if (($ns == '*') || ($ns == ':')) $ns = '';
    elseif ($ns == '.') $ns = getNS($ID);
    
    if (!$filter || ($filter == 'select')){
      $select = true;
      $filter = $_REQUEST['filter'];
    }
    $filter = strtolower($filter);
    $filters = $this->_viewFilters();
    if (!in_array($filter, $filters)) $filter = 'open';
    
    if ($my =& plugin_load('helper', 'task')) $pages = $my->getTasks($ns, NULL, $filter);
    if (!$pages){
      if ($select) $renderer->doc .= $this->_viewMenu($filter);
      if ((auth_quickaclcheck($ns.':*') >= AUTH_CREATE) && ($mode == 'xhtml')){
        $renderer->info['cache'] = false;
        $renderer->doc .= $this->_newTaskForm($ns);
      }
      return true; // nothing to display
    }
    
    // prepare pagination
    $c = count($pages);
    if ($c > $conf['recent']){
      $numOfPages = ceil($c / $conf['recent']);
      $first = $_REQUEST['first'];
      if (!is_numeric($first)) $first = 0;
      $currentPage = round($first / $conf['recent']) + 1;
      $pages = array_slice($pages, $first, $conf['recent']);
    }
    
    if ($mode == 'xhtml'){
      
      // prevent caching to ensure content is always fresh
      $renderer->info['cache'] = false;
      
      // show form to create a new task?
      $perm_create = (auth_quickaclcheck($ns.':*') >= AUTH_CREATE);
      if ($perm_create && ($this->getConf('tasks_formposition') == 'top'))
        $renderer->doc .= $this->_newTaskForm($ns);
      
      // let Pagelist Plugin do the work for us
      if (plugin_isdisabled('pagelist')
        || (!$pagelist = plugin_load('helper', 'pagelist'))){
        msg('The Pagelist Plugin must be installed for task lists.', -1);
        return false;
      }
      
      // show view filter popup if not 
      if ($select) $renderer->doc .= $this->_viewMenu($filter);
      
      // prepare pagelist columns
      $pagelist->header['page'] = $this->getLang('task');
      $pagelist->header['date'] = str_replace(' ', '&nbsp;', $this->getLang('date'));
      $pagelist->header['user'] = str_replace(' ', '&nbsp;', $this->getLang('user'));
      $pagelist->column['date'] = true;
      $pagelist->column['user'] = true;
      $pagelist->addColumn('task', 'status');
      
      // output list
      $pagelist->startList();
      foreach ($pages as $page){
        $pagelist->addPage($page);
      }
      $renderer->doc .= $pagelist->finishList();
      $renderer->doc .= $this->_paginationLinks($numOfPages, $currentPage, $filter);      
      
      // show form to create a new task?
      if ($perm_create && ($this->getConf('tasks_formposition') == 'bottom'))
        $renderer->doc .= $this->_newTaskForm($ns);
      
      return true;
      
    // for metadata renderer
    } elseif ($mode == 'metadata'){
      foreach ($pages as $page){
        $renderer->meta['relation']['references'][$page['id']] = true;
      }
      
      return true;
    }
    return false;
  }
  
/* ---------- (X)HTML Output Functions ---------- */

  /**
   * Show a popup to select the task view filter
   */
  function _viewMenu($filter){
    global $ID, $lang;
    
    $options = $this->_viewFilters();
        
    $ret = '<form id="task__changeview_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">'.DOKU_LF.
      '<label class="simple">'.DOKU_LF.
      DOKU_TAB.'<span>'.$this->getLang('view').'</span>'.DOKU_LF.
      DOKU_TAB.'<input type="hidden" name="id" value="'.$ID.'" />'.DOKU_LF.
      DOKU_TAB.'<input type="hidden" name="do" value="show" />'.DOKU_LF.
      DOKU_TAB.'<select name="filter" size="1" class="edit">'.DOKU_LF;
    foreach ($options as $option){
      $ret .= DOKU_TAB.DOKU_TAB.'<option value="'.$option.'"';
      if ($filter == $option) $ret .= ' selected="selected"';
      $ret .= '>'.$this->getLang('view_'.$option).'</option>'.DOKU_LF;
    }
    $ret .= DOKU_TAB.'</select>'.DOKU_LF.
      DOKU_TAB.'<input class="button" type="submit" value="'.$this->getLang('refresh').'" />'.DOKU_LF.
      '</label>'.DOKU_LF.
      '</form>';
    return $ret;
  }
  
  /**
   * Returns an array of available view filters for the task list
   */
  function _viewFilters(){
    if (!$_SERVER['REMOTE_USER']) return array('all', 'open', 'done', 'due', 'overdue');
    else return array('all', 'open', 'my', 'new', 'done', 'due', 'overdue');
  }
  
  /**
   * Returns pagination links if more than one page
   */
  function _paginationLinks($num, $cur, $filter){
    global $ID, $conf;
    
    if (!is_numeric($num) || ($num < 2)) return '';
    
    $ret = array();
    for ($i = 1; $i <= $num; $i++){
      if ($i == $cur) $ret[] = '<strong>'.$i.'</strong>';
      else $ret[] = '<a href="'.wl($ID, array('first' => $conf['recent'] * ($i - 1),
        'filter' => $filter)).'" class="wikilink1" alt="'.$i.'">'.$i.'</a>';
    }
    return '<div class="centeralign">'.DOKU_LF.
      DOKU_TAB.join(' | ', $ret).DOKU_LF.
      '</div>'.DOKU_LF;
  }
    
  /**
   * Show the form to start a new discussion thread
   */
  function _newTaskForm($ns){
    global $ID, $lang, $INFO;
    
    return '<div class="newtask_form">'.DOKU_LF.
      '<form id="task__newtask_form"  method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">'.DOKU_LF.
      DOKU_TAB.'<fieldset>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<legend> '.$this->getLang('newtask').': </legend>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<input type="hidden" name="id" value="'.$ID.'" />'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<input type="hidden" name="do" value="newtask" />'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<input type="hidden" name="ns" value="'.$ns.'" />'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<input class="edit" type="text" name="title" id="task__newtask_title" size="40" tabindex="1" />'.DOKU_LF.
      '<table class="blind">'.DOKU_LF.
      DOKU_TAB.'<tr>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<th>'.$this->getLang('user').':</th><td><input type="text" name="user" value="'.hsc($INFO['userinfo']['name']).'" class="edit" tabindex="2" /></td>'.DOKU_LF.
      DOKU_TAB.'</tr>'.DOKU_LF.
      DOKU_TAB.'<tr>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<th>'.$this->getLang('date').':</th><td><input type="text" name="date" value="'.date('Y-m-d').'" class="edit" tabindex="3" /></td>'.DOKU_LF.
      DOKU_TAB.'</tr>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<th>'.$this->getLang('priority').':</th><td>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.'<select name="priority" size="1" tabindex="4" class="edit">'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.DOKU_TAB.'<option value="" selected="selected">'.$this->getLang('low').'</option>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.DOKU_TAB.'<option value="!">'.$this->getLang('medium').'</option>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.DOKU_TAB.'<option value="!!">'.$this->getLang('high').'</option>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.DOKU_TAB.'<option value="!!!">'.$this->getLang('critical').'</option>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.DOKU_TAB.'</select>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'</td>'.DOKU_LF.
      DOKU_TAB.'</tr>'.DOKU_LF.
      '</table>'.DOKU_LF.
      DOKU_TAB.DOKU_TAB.'<input class="button" type="submit" value="'.$lang['btn_create'].'" tabindex="5" />'.DOKU_LF.
      DOKU_TAB.'</fieldset>'.DOKU_LF.
      '</form>'.DOKU_LF.
      '</div>'.DOKU_LF;
  }
        
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
