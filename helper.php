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
      'date'   => '2007-01-03',
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
  function th(){
    return $this->getLang('status');
  }
    
  /**
   * Returns the status of the task
   */
  function td($id){
    $task = $this->readTask($id);
    return $this->statusLabel($task['status']);
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
      if (!$task = $this->readTask($id)) continue;
      
      $date = $task['date']['due'];
      $responsible = $this->_isResponsible($task['user']);
      
      // skip closed tasks unless filter is 'all'
      if ($filter != 'all'){
        if (($task['status'] < 0) || ($task['status'] > 3)) continue;
        if ($responsible && ($task['status'] == 3)) continue;
      }
      
      // skip other's tasks if filter is 'my'
      if (($filter == 'my') && !$responsible) continue;
      
      // skip assigned and not new tasks if filter is 'new'
      if (($filter == 'new') && ($task['user'] || ($task['status'] != 0))) continue;
      
      // skip not done and my tasks if filter is 'done'
      if (($filter == 'done') && ($responsible || ($task['status'] != 3))) continue;
      
      // filter is 'due' or 'overdue' 
      if (in_array($filter, array('due', 'overdue'))){
        if (!$date || ($date > time()) || ($task['status'] > 2)) continue;
        elseif (($date + 86400 < time()) && ($filter == 'due')) continue;
        elseif (($date + 86400 > time()) && ($filter == 'overdue')) continue;
      } 

      $result[$task['key']] = array(
        'id'       => $id,
        'date'     => $date,
        'user'     => $task['user'],
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
  function readTask($id){
    $file = metaFN($id, '.task');
    if (!@file_exists($file)){ //@remove
      $data = p_get_metadata($id, 'task');
      if (is_array($data)){
        $data['date'] = array('due' => $data['date']);
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
  function writeTask($id, $data){
    if (!is_array($data)) return false;
    $file = ($data['file'] ? $data['file'] : metaFN($id, '.task'));
    
    // remove file and exists keys
    unset($data['file']);
    unset($data['exists']);
    
    // set creation or modification time
    if (!is_array($data['date'])) $data['date'] = array('due' => $data['date']); //@remove
    if (!@file_exists($file) || !$data['date']['created']){
      $data['date']['created'] = time();
    } else {
      $data['date']['modified'] = time();
    }
    
    if (!isset($data['status'])){    // make sure we don't overwrite status
      $current = unserialize(io_readFile($file, false));
      $data['status'] = $current['status'];
    } elseif ($data['status'] == 3){ // set task completion time
      $data['date']['completed'] = time();
    }
    
    // generate vtodo for iCal file download
    // $data['vtodo'] = $this->_vtodo($data);
    
    // generate sortkey with priority and creation date
    $data['key'] = chr($data['priority'] + 97).(2000000000 - $data['date']['created']);
    
    // save task metadata
    $ok = io_saveFile($file, serialize($data));
    
    // and finally notify users
    $this->_notify($task);
    return $ok;
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
   * Returns the label of a priority
   */
  function priorityLabel($priority){
    switch ($priority){
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
  function _isResponsible($user){
    global $INFO;
    
    if (!$user) return false;
    if (($user == $_SERVER['REMOTE_USER']) || ($user == $INFO['userinfo']['name']))
      return true;
    return false;
  }
  
  /**
   * Interpret date with strtotime()
   */
  function _interpretDate($str){
    if (!$str) return NULL;
  
    // only year given -> time till end of year
    if (preg_match("/^\d{4}$/", $str)){
      $str .= '-12-31';
    
    // only month given -> time till last of month
    } elseif (preg_match("/^\d{4}-(\d{2})$/", $str, $month)){
      switch ($month[1]){
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
  function _notify($task){
    global $conf;
    global $ID;
  
    if ((!$conf['subscribers']) && (!$conf['notify'])) return; //subscribers enabled?
    $bcc  = subscriber_addresslist($ID);
    if ((empty($bcc)) && (!$conf['notify'])) return;
    $to   = $conf['notify'];
    $text = io_readFile($this->localFN('subscribermail'));
  
    $text = str_replace('@PAGE@', $ID, $text);
    $text = str_replace('@TITLE@', $conf['title'], $text);
    $text = str_replace('@DATE@', date($conf['dformat'], $task['date']), $text);
    $text = str_replace('@NAME@', $task['name'], $text);
    $text = str_replace('@STATUS@', $this->statusLabel($task['status']), $text);
    $text = str_replace('@PRIORITY@', $this->priorityLabel($task['priority']), $text);
    $text = str_replace('@UNSUBSCRIBE@', wl($ID, 'do=unsubscribe', true, '&'), $text);
    $text = str_replace('@DOKUWIKIURL@', DOKU_URL, $text);
  
    $subject = '['.$conf['title'].'] ';
    if ($task['status'] == 0) $subject .= $this->getLang('mail_newtask');
    else $subject .= $this->getLang('mail_changedtask');
  
    mail_send($to, $subject, $text, $conf['mailfrom'], '', $bcc);
  }

}
  
//Setup VIM: ex: et ts=4 enc=utf-8 :
