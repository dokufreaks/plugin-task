<?php
/**
 * Task Plugin, task form component: show new task form (only)
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   LarsDW223
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_task_taskform extends DokuWiki_Syntax_Plugin {
    protected $helper = NULL;

    /**
     * Constructor. Loads helper plugin.
     */
    public function __construct() {
        $this->helper = plugin_load('helper', 'task');
    }

    function getType() { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort() { return 306; }
  
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{task>form>.+?\}\}', $mode, 'plugin_task_taskform');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        // strip {{task>form> from start and }} from end
        $match = substr($match, 12, -2);
        list($ns, $flags) = explode('&', $match, 2);
        $flags = explode('&', $flags);

        if (($ns == '*') || ($ns == ':')) $ns = '';
        elseif ($ns == '.') $ns = getNS($ID);
        else $ns = cleanID($ns);

        $selectUserGroup = NULL;
        foreach ($flags as $flag) {
            if (substr($flag, 0, 16) == 'selectUserGroup=') {
                $selectUserGroup = substr($flag, 16);
                $selectUserGroup = trim($selectUserGroup, '"');
            }
        }
        return array($ns, $flags, $selectUserGroup);
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode != 'xhtml') {
            false;
        }

        list($ns, $flags, $selectUserGroup) = $data;

        $selectUser = in_array('selectUser', $flags);
        if ($this->helper) $renderer->doc .= $this->helper->_newTaskForm($ns, $selectUser, $selectUserGroup);
        return true;
    }
}
// vim:et:ts=4:sw=4:enc=utf-8:
