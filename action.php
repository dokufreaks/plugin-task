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
                'author' => 'Gina Häußge, Michael Klier, Esther Brunner',
                'email'  => 'dokuwiki@chimeric.de',
                'date'   => '2008-05-24',
                'name'   => 'Task Plugin',
                'desc'   => 'Brings task management to DokuWiki',
                'url'    => 'http://wiki.splitbrain.org/plugin:task',
                );
    }

    /**
     * register the eventhandlers
     */
    function register(&$contr) {
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
        if ($event->data != 'newtask' && $event->data != 'changetask') return;

        // we can handle it -> prevent others
        $event->stopPropagation();
        $event->preventDefault();    

        switch($event->data) {
            case 'newtask':
                $event->data = $this->_newTask();
                break;
            case 'changetask':
                $event->data = $this->_changeTask();
                break;
        }
    }

    /**
     * Creates a new task page
     */
    function _newTask(){
        global $ID;
        global $INFO;

        $ns    = cleanID($_REQUEST['ns']);
        $title = str_replace(':', '', $_REQUEST['title']);
        $back  = $ID;
        $ID    = ($ns ? $ns.':' : '').cleanID($title);
        $INFO  = pageinfo();

        // check if we are allowed to create this file
        if ($INFO['perm'] >= AUTH_CREATE) {

            //check if locked by anyone - if not lock for my self      
            if ($INFO['locked']) {
                return 'locked';
            } else {
                lock($ID);
            }

            // prepare the new thread file with default stuff
            if (!@file_exists($INFO['filepath'])) {
                global $TEXT;

                $user     = $_REQUEST['user'];
                $date     = $_REQUEST['date'];
                $priority = $_REQUEST['priority'];

                // create wiki page
                $TEXT = pageTemplate(array(($ns ? $ns.':' : '').$title));
                if (!$TEXT) {
                    $data = array(
                            'id'       => $ID,
                            'ns'       => $ns,
                            'title'    => $title,
                            'back'     => $back,
                            'priority' => $priority,
                            'user'     => $user,
                            'date'     => $date,
                            );

                    $TEXT = $this->_pageTemplate($data);
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
     * Adapted version of pageTemplate() function
     */
    function _pageTemplate($data){
        global $INFO;

        $id   = $data['id'];
        $tpl  = io_readFile(DOKU_PLUGIN.'task/_template.txt');

        // standard replacements
        $replace = array(
                '@ID@'   => $id,
                '@NS@'   => $data['ns'],
                '@PAGE@' => strtr(noNS($id),'_',' '),
                '@USER@' => $data['user'],
                '@NAME@' => $INFO['userinfo']['name'],
                '@MAIL@' => $INFO['userinfo']['mail'],
                '@DATE@' => $data['date'],
                );

        // additional replacements
        $replace['@BACK@']     = $data['back'];
        $replace['@TITLE@']    = $data['title'];
        $replace['@PRIORITY@'] = $data['priority'];

        // tag if tag plugin is available
        if ((@file_exists(DOKU_PLUGIN.'tag/syntax/tag.php')) && (!plugin_isdisabled('tag'))) {
            $replace['@TAG@'] = "\n\n{{tag>}}";
        } else {
            $replace['@TAG@'] = '';
        }

        // discussion if discussion plugin is available
        if ((@file_exists(DOKU_PLUGIN.'discussion/syntax/comments.php')) && (!plugin_isdisabled('discussion'))) {
            $replace['@DISCUSSION@'] = "~~DISCUSSION~~";
        } else {
            $replace['@DISCUSSION@'] = '';
        }

        // do the replace
        $tpl = str_replace(array_keys($replace), array_values($replace), $tpl);
        return $tpl;
    }
  
    /**
     * Changes the status of a task
     */
    function _changeTask(){
        global $ID;
        global $INFO;

        $status = $_REQUEST['status'];
        if (!is_numeric($status) || ($status < -1) || ($status > 4)) return 'show'; // invalid

        // load task data
        if ($my =& plugin_load('helper', 'task')) {
            $task = $my->readTask($ID);
        } else {
            return 'show';
        }

        if ($task['status'] == $status) return 'show'; // unchanged

        $responsible = $my->_isResponsible($task['user']['name']);

        // some additional checks if change not performed by an admin
        // FIXME error messages?
        if ($INFO['perm'] != AUTH_ADMIN){

            // responsible person can't verify her / his own tasks
            if ($responsible && ($status == 4)) return 'show';

            // other persons can only accept or verify tasks
            if (!$responsible && $status != 1 && $status != 4) return 'show';
        }

        // assign task to a user
        if (!$task['user']['name']) {
            // FIXME error message?
            if (!$_SERVER['REMOTE_USER']) return 'show'; // no logged in user

            $wiki = rawWiki($ID);
            $summary = $this->getLang('mail_changedtask').': '.$this->getLang('accepted');
            $new = preg_replace('/~~TASK:?/', '~~TASK:'.$INFO['userinfo']['name'], $wiki);

            if ($new != $wiki) {
                saveWikiText($ID, $new, $summary, true); // save as minor edit
            }

            $task['user'] = array(
                    'id'   => $_SERVER['REMOTE_USER'],
                    'name' => $INFO['userinfo']['name'],
                    'mail' => $INFO['userinfo']['mail'],
                    );
        }

        // save .task meta file and clear xhtml cache
        $task['status'] = $status;
        $my->writeTask($ID, $task);
        $_REQUEST['purge'] = true;
        return 'show';
    }
}

//vim:ex:et:ts=4:enc=utf-8:
