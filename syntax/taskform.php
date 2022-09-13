<?php
/**
 * Task Plugin, task form component: show new task form (only)
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   LarsDW223
 */

class syntax_plugin_task_taskform extends DokuWiki_Syntax_Plugin {
    /**
     * @var helper_plugin_task
     */
    protected $helper = null;

    /**
     * Constructor. Loads helper plugin.
     */
    public function __construct() {
        $this->helper = plugin_load('helper', 'task');
    }

    public function getType() { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort() { return 306; }

    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('\{\{task>form>.+?\}\}', $mode, 'plugin_task_taskform');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        // strip {{task>form> from start and }} from end
        $match = substr($match, 12, -2);
        list($ns, $flags) = array_pad(explode('&', $match, 2), 2,'');
        $flags = explode('&', $flags);

        if ($ns == '*' || $ns == ':') {
            $ns = '';
        } elseif ($ns == '.') {
            $ns = getNS($ID);
        } else {
            $ns = cleanID($ns);
        }

        $selectUserGroup = null;
        foreach ($flags as $flag) {
            if (substr($flag, 0, 16) == 'selectUserGroup=') {
                $selectUserGroup = substr($flag, 16);
                $selectUserGroup = trim($selectUserGroup, '"');
            }
        }
        return [$ns, $flags, $selectUserGroup];
    }

    public function render($format, Doku_Renderer $renderer, $data) {
        if ($format != 'xhtml') {
            return false;
        }

        list($ns, $flags, $selectUserGroup) = $data;

        $selectUser = in_array('selectUser', $flags);
        if ($this->helper) {
            $renderer->doc .= $this->helper->newTaskForm($ns, $selectUser, $selectUserGroup);
        }
        return true;
    }
}
