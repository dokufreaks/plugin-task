<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

class action_plugin_task extends DokuWiki_Action_Plugin {

    /**
     * register the eventhandlers
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleTaskActions', []);
    }

    /**
     * Checks if 'newentry' was given as action, if so we
     * do handle the event our self and no further checking takes place
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handleTaskActions($event, $param) {
        if ($event->data != 'newtask' && $event->data != 'changetask') return;

        // we can handle it -> prevent others
        $event->stopPropagation();

        switch($event->data) {
            case 'newtask':
                $event->data = $this->newTask();
                break;
            case 'changetask':
                $event->data = $this->changeTask();
                break;
        }
    }

    /**
     * Creates a new task page
     */
    protected function newTask() {
        global $ID, $INFO, $INPUT;

        $ns    = cleanID($INPUT->post->str('ns'));
        $title = str_replace(':', '', $INPUT->post->str('title'));
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

                $user     = $INPUT->post->str('user');
                $date     = $INPUT->post->str('date');
                $priority = $INPUT->post->str('priority');

                // create wiki page
                $data = [
                        'id'       => $ID,
                        'ns'       => $ns,
                        'title'    => $title,
                        'back'     => $back,
                        'priority' => $priority,
                        'user'     => $user,
                        'date'     => $date,
                ];

                $TEXT = $this->_pageTemplate($data);
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
     *
     * @param array $data with:
     *  'id' => string page id,
     *  'ns' => string namespace,
     *  'user' => string user id,
     *  'date' => string date Y-m-d,
     *  'back' => string page id of page to go back to,
     *  'title' => string page title,
     *  'priority' => string zero to three exclamation marks(!)
     *
     * @return string raw wiki text
     */
    function _pageTemplate($data) {
        global $INFO;

        $id   = $data['id'];
        $tpl  = io_readFile(DOKU_PLUGIN.'task/_template.txt');

        // standard replacements
        $replace = [
                '@ID@'   => $id,
                '@NS@'   => $data['ns'],
                '@PAGE@' => strtr(noNS($id),'_',' '),
                '@USER@' => $data['user'],
                '@NAME@' => $INFO['userinfo']['name'],
                '@MAIL@' => $INFO['userinfo']['mail'],
                '@DATE@' => $data['date'],
        ];

        // additional replacements
        $replace['@BACK@']     = $data['back'];
        $replace['@TITLE@']    = $data['title'];
        $replace['@PRIORITY@'] = $data['priority'];

        // tag if tag plugin is available
        if (!plugin_isdisabled('tag')) {
            $replace['@TAG@'] = "\n\n{{tag>}}";
        } else {
            $replace['@TAG@'] = '';
        }

        // discussion if discussion plugin is available
        if (!plugin_isdisabled('discussion')) {
            $replace['@DISCUSSION@'] = "~~DISCUSSION~~";
        } else {
            $replace['@DISCUSSION@'] = '';
        }

        // do the replace
        return str_replace(array_keys($replace), array_values($replace), $tpl);
    }

    /**
     * Changes the status of a task
     */
    protected function changeTask() {
        global $ID, $INFO, $INPUT;

        $status = $INPUT->post->int('status'); //TODO check if other default then 0?
        $status = trim($status);
        if (!is_numeric($status) || $status < -1 || $status > 4) { //FIXME is_numeric not needed
            if ($this->getConf('show_error_msg')) {
                $message = $this->getLang('msg_rcvd_invalid_status');
                $message = str_replace('%status%', $status, $message);
                msg($message, -1);
            }
            return 'show';
        }

        // load task data
        /** @var helper_plugin_task $helper */
        if ($helper = $this->loadHelper('task', false)) {
            $task = $helper->readTask($ID);
        } else {
            if ($this->getConf('show_error_msg')) {
                msg($this->getLang('msg_load_helper_failed'), -1);
            }
            return 'show';
        }

        if ($task['status'] == $status) {
            // unchanged
            if ($this->getConf('show_info_msg')) {
                msg($this->getLang('msg_nothing_changed'));
            }
            return 'show';
        }

        $responsible = $helper->isResponsible($task['user']['name']);

        // some additional checks if change not performed by an admin
        // FIXME error messages?
        if ($INFO['perm'] != AUTH_ADMIN) {

            // responsible person can't verify her / his own tasks
            if ($responsible && $status == 4) {
                if ($this->getConf('show_info_msg')) {
                    msg ($this->getLang('msg_responsible_no_verify'));
                }
                return 'show';
            }

            // other persons can only accept or verify tasks
            if (!$responsible && $status != 1 && $status != 4) {
                if ($this->getConf('show_info_msg')) {
                    msg ($this->getLang('msg_other_accept_or_verify'));
                }
                return 'show';
            }
        }

        // assign task to a user
        if (!$task['user']['name']) {
            // FIXME error message?
            if (!$INPUT->server->has('REMOTE_USER')) {
                // no logged in user
                if ($this->getConf('show_info_msg')) {
                    msg($this->getLang('msg_not_logged_in'));
                }
                return 'show';
            }

            $wiki = rawWiki($ID);
            $summary = $this->getLang('mail_changedtask').': '.$this->getLang('accepted');
            $new = preg_replace('/~~TASK:?/', '~~TASK:'.$INFO['userinfo']['name'], $wiki);

            if ($new != $wiki) {
                saveWikiText($ID, $new, $summary, true); // save as minor edit
            }

            $task['user'] = [
                    'id'   => $INPUT->server->str('REMOTE_USER'),
                    'name' => $INFO['userinfo']['name'],
                    'mail' => $INFO['userinfo']['mail'],
            ];
        }

        // save .task meta file and clear xhtml cache
        $oldstatus = $task['status'];
        $task['status'] = $status;
        $helper->writeTask($ID, $task);
        $INPUT->set('purge', true);

        if ($this->getConf('show_success_msg')) {
            $message = $this->getLang('msg_status_changed');
            $message = str_replace('%status%', $helper->statusLabel($status), $message);
            $message = str_replace('%oldstatus%', $helper->statusLabel($oldstatus), $message);
            msg($message, 1);
        }

        return 'show';
    }
}
