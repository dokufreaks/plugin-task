<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_task extends DokuWiki_Action_Plugin {

  /**
   * return some info
   */
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-18',
      'name'   => 'Task Plugin',
      'desc'   => 'Brings task management to DokuWiki',
      'url'    => 'http://www.wikidesign.ch/en/plugin/task/start',
    );
  }

  /**
   * register the eventhandlers
   */
  function register(&$contr){
    $contr->register_hook('ACTION_ACT_PREPROCESS',
                          'BEFORE',
                          $this,
                          'handle_act_preprocess',
                           array());
  }
    
  /**
   * Checks if 'newentry' was given as action, if so we
   * do handle the event our self and no further checking takes place
   */
  function handle_act_preprocess(&$event, $param){
    if (($event->data != 'newtask') && ($event->data != 'changetask'))
      return; // nothing to do for us
    // we can handle it -> prevent others
    $event->stopPropagation();
    $event->preventDefault();    
    
    switch ($event->data){
    case 'newtask':
      $event->data = $this->_handle_newTask();
      break;
    case 'changetask':
      $event->data = $this->_handle_changeTask();
      break;
    }
  }

  /**
   * Creates a new task page
   */
  function _handle_newTask(){
    global $ID;
    global $INFO;
    
    $ns    = cleanID($_REQUEST['ns']);
    $title = str_replace(':', '', $_REQUEST['title']);
    $back  = $ID;
    $ID    = ($ns ? $ns.':' : '').cleanID($title);
    $INFO  = pageinfo();
    
    // check if we are allowed to create this file
    if ($INFO['perm'] >= AUTH_CREATE){
            
      //check if locked by anyone - if not lock for my self      
      if ($INFO['locked']) return 'locked';
      else lock($ID);

      // prepare the new thread file with default stuff
      if (!@file_exists($INFO['filepath'])){
        global $TEXT;
        
        $user     = $_REQUEST['user'];
        $username = $_SERVER['REMOTE_USER'];
        $fullname = $INFO['userinfo']['name'];
        if ($user && (($username == $user) || ($fullname == $user))){
          $meta['task']['status'] = 1;
          p_set_metadata($ID, $meta, false, true);
        }
        
        $TEXT = pageTemplate(array(($ns ? $ns.':' : '').$title));
        if (!$TEXT){
          // prepare task specific data
          $user     = ($user ? ':'.$user : '');
          $date     = ($_REQUEST['date'] ? '?'.$_REQUEST['date'] : '');
          $priority = $_REQUEST['priority'];
          
          $TEXT = "<- [[:$back]]\n\n====== $title ======\n\n".
            "~~TASK$user$date$priority~~\n\n";
          if ((@file_exists(DOKU_PLUGIN.'tag/syntax/tag.php'))
            && (!plugin_isdisabled('tag')))
            $TEXT .= "\n\n{{tag>}}";
          if ((@file_exists(DOKU_PLUGIN.'discussion/action.php'))
            && (!plugin_isdisabled('discussion')))
            $TEXT .= "\n\n~~DISCUSSION~~";
        }
        return 'preview';
      } else {
        return 'edit';
      }
    } else {
      return 'show';
    }
  }
  
  /**
   * Changes the status of a task
   */
  function _handle_changeTask(){
    global $ID, $INFO;
        
    $status = $_REQUEST['status'];
    if (!is_numeric($status) || ($status < -1) || ($status > 4)) return 'show'; // invalid
    if ($INFO['meta']['task']['status'] == $status) return 'show'; // unchanged
    
    $user     = $INFO['meta']['task']['user'];
    $username = $_SERVER['REMOTE_USER'];
    $fullname = $INFO['userinfo']['name'];
    $responsible = (($user && (($username == $user) || ($fullname == $user))) ? 1 : 0);
    
    // some additional checks if change not performed by an admin
    if ($INFO['perm'] != AUTH_ADMIN){
    
      // responsible person can't verify her / his own tasks
      if ($responsible && ($status > 3)) return 'show';
      
      // other persons can only accept or verify tasks
      if (!$responsible && (($status != 1) || ($status != 4))) return 'show';
    }
    
    // assign task to a user
    if (!$user && ($status == 1)){
      $wiki = rawWiki($ID);
      $summary = $this->getLang('changed').$this->getLang('accepted');
      $new = preg_replace('/~~TASK:?/', '~~TASK:'.$fullname, $wiki);
      if ($new != $wiki) saveWikiText($ID, $new, $summary);
    }
    
    $meta['task']['status'] = $status;
    p_set_metadata($ID, $meta, false, true);
    
    return 'show';
  }
  
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
