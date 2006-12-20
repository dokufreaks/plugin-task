<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");

class helper_plugin_task extends DokuWiki_Plugin {

  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-20',
      'name'   => 'Task Plugin (helper class)',
      'desc'   => 'Functions to get info about tasks',
      'url'    => 'http://www.wikidesign/en/plugin/task/start',
    );
  }
  
  function getMethods(){
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
      'desc'   => 'returns task pages, sorted by priority',
      'params' => array(
        'namespace' => 'string',
        'number (optional)' => 'integer',
        'filter (optional)' => 'string'),
      'return' => array('pages' => 'array'),
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
  function th(){
    return $this->getLang('status');
  }
    
  /**
   * Returns the status of the task
   */
  function td($id){
    return $this->statusLabel(p_get_metadata($id, 'task status'));
  }
    
  /**
   * Returns an array of task pages, sorted by priority
   */
  function getTasks($ns, $num = NULL, $filter = ''){
    global $conf;
    
    if (!$filter) $filter = strtolower($_REQUEST['filter']);
        
    require_once(DOKU_INC.'inc/search.php');
    
    $dir = $conf['datadir'].($ns ? '/'.str_replace(':', '/', $ns): '');
        
    // returns the list of pages in the given namespace and it's subspaces
    $items = array();
    search($items, $dir, 'search_allpages', '');
            
    // add pages with comments to result
    $result = array();
    foreach ($items as $item){
      $id   = ($ns ? $ns.':' : '').$item['id'];
      
      // skip if no permission
      $perm = auth_quickaclcheck($id);
      if ($perm < AUTH_READ) continue; 
      
      // skip pages without task
      $meta = p_get_metadata($id);
      if (!is_array($meta['task']) || empty($meta['task'])) continue;
      
      $date   = $meta['task']['date'];
      $user   = $meta['task']['user'];
      $status = $meta['task']['status'];
      $resp   = $this->_isResponsible($user);
      
      // skip closed tasks unless filter is 'all'
      if ($filter != 'all'){
        if (($status < 0) || ($status > 3)) continue;
        if ($resp && ($status == 3)) continue;
      }
      
      // skip other's tasks if filter is 'my'
      if (($filter == 'my') && !$resp) continue;
      
      // skip assigned and not new tasks if filter is 'new'
      if (($filter == 'new') && ($user || ($status != 0))) continue;
      
      // skip not done and my tasks if filter is 'done'
      if (($filter == 'done') && ($resp || ($status != 3))) continue;
      
      // filter is 'due' or 'overdue' 
      if (in_array($filter, array('due', 'overdue'))){
        if (!$date || ($date > time()) || ($status > 2)) continue;
        elseif (($date + 86400 < time()) && ($filter == 'due')) continue;
        elseif (($date + 86400 > time()) && ($filter == 'overdue')) continue;
      } 

      $prior = $meta['task']['priority'];
      $key = chr($prior + 97).(2000000000 - $meta['date']['created']);
      $result[$key] = array(
        'id'       => $id,
        'title'    => $meta['title'],
        'date'     => $date,
        'user'     => $user,
        'status'   => $this->statusLabel($status),
        'priority' => $prior,
        'desc'     => $meta['description']['abstract'],
        'perm'     => $perm,
        'exists'   => true,
      );
    }
    
    // finally sort by time of last comment
    krsort($result);
    
    if (is_numeric($num)) $result = array_slice($result, 0, $num);
      
    return $result;
  }
  
  /**
   * Returns the label of a status
   */
  function statusLabel($status){
    switch ($status){
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
   * Is the given task assigned to the current user?
   */
  function _isResponsible($user){
    global $INFO;
    
    if (!$user) return false;
    if (($user == $_SERVER['REMOTE_USER']) || ($user == $INFO['userinfo']['name']))
      return true;
    return false;
  }
        
}
  
//Setup VIM: ex: et ts=4 enc=utf-8 :
