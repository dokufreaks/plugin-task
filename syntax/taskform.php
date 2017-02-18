<?php
/**
 * Task Plugin, task form component: show new task form (only)
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   LarsDW223
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'syntax.php');

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
        $ns = substr($match, 12, -2);

        if (($ns == '*') || ($ns == ':')) $ns = '';
        elseif ($ns == '.') $ns = getNS($ID);
        else $ns = cleanID($ns);

        return array($ns);
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode != 'xhtml') {
            false;
        }

        $ns = $data[0];
        if ($this->helper) $renderer->doc .= $this->helper->_newTaskForm($ns);
        return true;
    }
}
// vim:et:ts=4:sw=4:enc=utf-8:
