<?php
/**
 * Task Plugin, tasks component: lists tasks of a given namespace
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_task_tasks extends DokuWiki_Syntax_Plugin {
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
        $this->Lexer->addSpecialPattern('\{\{tasks>.+?\}\}', $mode, 'plugin_task_tasks');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        $match = substr($match, 8, -2); // strip {{topic> from start and }} from end
        list($match, $flags) = explode('&', $match, 2);
        $flags = explode('&', $flags);
        list($match, $refine) = explode(' ', $match, 2);
        list($ns, $filter) = explode('?', $match, 2);

        if (($ns == '*') || ($ns == ':')) $ns = '';
        elseif ($ns == '.') $ns = getNS($ID);
        else $ns = cleanID($ns);

        return array($ns, $filter, $flags, $refine);
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        global $conf;

        list($ns, $filter, $flags, $refine) = $data;

        if (!$filter || ($filter == 'select')) {
            $select = true;
            $filter = trim($_REQUEST['filter']);
        }
        $filter = strtolower($filter);
        $filters = $this->_viewFilters();
        if (!in_array($filter, $filters)) $filter = 'open';
        if(isset($_REQUEST['view_user'])) $user = $_REQUEST['view_user'];

        if ($this->helper) $pages = $this->helper->getTasks($ns, NULL, $filter, $user);

        // use tag refinements?
        if ($refine) {
            if (plugin_isdisabled('tag') || (!$tag = plugin_load('helper', 'tag'))) {
                msg('The Tag Plugin must be installed to use tag refinements.', -1);
            } else {
                $pages = $tag->tagRefine($pages, $refine);
            }
        }

        if(!$pages) {
            if($mode != 'xhtml') return true;
            $renderer->info['cache'] = false;
            if($select) $renderer->doc .= $this->_viewMenu($filter);
            if(auth_quickaclcheck($ns.':*') >= AUTH_CREATE) {
                if(!in_array('noform', $flags)) {
                    if ($this->helper) $renderer->doc .= $this->helper->_newTaskForm($ns);
                }
            }
            return true; // nothing to display
        }

        // prepare pagination
        $c = count($pages);
        $perpage = ($conf['recent'] != 0) ? $conf['recent'] : 20; // prevent division by zero
        if ($c > $perpage) {
            $numOfPages = ceil($c / $perpage);
            $first = $_REQUEST['first'];
            if (!is_numeric($first)) $first = 0;
            $currentPage = round($first / $perpage) + 1;
            $pages = array_slice($pages, $first, $perpage);
        }

        if ($mode == 'xhtml') {

            // prevent caching to ensure content is always fresh
            $renderer->info['cache'] = false;

            // show form to create a new task?
            $perm_create = (auth_quickaclcheck($ns.':*') >= AUTH_CREATE);
            if($perm_create && ($this->getConf('tasks_formposition') == 'top')) {
                if(!in_array('noform', $flags)) {
                    if ($this->helper) $renderer->doc .= $this->helper->_newTaskForm($ns);
                }
            }

            // let Pagelist Plugin do the work for us
            if (plugin_isdisabled('pagelist')
                    || (!$pagelist = plugin_load('helper', 'pagelist'))) {
                msg('The Pagelist Plugin must be installed for task lists.', -1);
                return false;
            }

            // show view filter popup if not 
            if ($select) $renderer->doc .= $this->_viewMenu($filter);

            // prepare pagelist columns
            $pagelist->header['page'] = $this->getLang('task');
            $pagelist->header['date'] = str_replace(' ', '&nbsp;', $this->getLang('date'));
            $pagelist->header['user'] = str_replace(' ', '&nbsp;', $this->getLang('user'));
            $pagelist->column['date'] = $this->getConf('datefield');
            $pagelist->column['user'] = true;
            $pagelist->setFlags($flags);
            $pagelist->addColumn('task', 'status');

            // output list
            $pagelist->startList();
            if($this->getConf('tasks_newestfirst')) {
                $pages = array_reverse($pages);
            }
            foreach ($pages as $page) {
                $pagelist->addPage($page);
            }
            $renderer->doc .= $pagelist->finishList();
            $renderer->doc .= $this->_paginationLinks($numOfPages, $currentPage, $filter);      

            // show form to create a new task?
            if($perm_create && ($this->getConf('tasks_formposition') == 'bottom')) {
                if(!in_array('noform', $flags)) {
                    if ($this->helper) $renderer->doc .= $this->helper->_newTaskForm($ns);
                }
            }

            return true;

            // for metadata renderer
        } elseif ($mode == 'metadata') {
            foreach ($pages as $page) {
                $renderer->meta['relation']['references'][$page['id']] = true;
            }

            return true;
        }
        return false;
    }
  
/* ---------- (X)HTML Output Functions ---------- */

    /**
     * Show a popup to select the task view filter.
     * Just forwards call to the old or new function.
     */
    function _viewMenu($filter) {
        if (class_exists('dokuwiki\Form\Form')) {
            return $this->_viewMenuNew($filter);
        } else {
            return $this->_viewMenuOld($filter);
        }
    }

    /**
     * Show a popup to select the task view filter.
     * This is the new version using class dokuwiki\Form\Form.
     * 
     * @see _viewMenu
     */
    function _viewMenuNew($filter) {
        global $ID, $lang;

        $options = $this->_viewFilters();

        $form = new dokuwiki\Form\Form(array('id' => 'task__changeview_form'));
        $pos = 1;

        $form->addHTML('<label class="simple">', $pos++);
        $form->addHTML('<span>'.$this->getLang('view').'</span>', $pos++);

        // Set hidden fields
        $form->setHiddenField ('id', $ID);
        $form->setHiddenField ('do', 'show');

        // Select status from drop down list
        $dropDownOptions = array();
        $selected = NULL;
        $value = 0;
        foreach ($options as $option) {
            if ($filter == $option) {
                $selected = $option.' ';
            }
            $dropDownOptions [$option.' '] = $this->getLang('view_'.$option);
        }
        $input = $form->addDropdown('filter', $dropDownOptions, NULL, $pos++);
        $input->val($selected);

        $form->addHTML('</label>', $pos++);

        if(isset($_SERVER['REMOTE_USER'])) {
            $form->addHTML('<label class="simple"><span>'.$this->getLang('view_user').':</span>', $pos++);
            $input = $form->addCheckbox('view_user', NULL, $pos++);
            $input->attr('value', $_SERVER['REMOTE_USER']);
            if ($_REQUEST['view_user']) {
                $input->attr('checked', 'checked');
            }
            $form->addHTML('</label>', $pos++);
        }

        // Add button
        $form->addButton(NULL, $this->getLang('btn_refresh'), $pos++);

        $ret  = '<div class="task_viewmenu">';
        $ret .= $form->toHTML();
        $ret .= '</div>';

        return $ret;
    }

    /**
     * Show a popup to select the task view filter.
     * Old function generating all HTML on its own.
     * 
     * @see _viewMenu
     */
    function _viewMenuOld($filter) {
        global $ID, $lang;

        $options = $this->_viewFilters();

        $ret  = '<div class="task_viewmenu">';
        $ret .= '<form id="task__changeview_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">';
        $ret .= '<label class="simple">';
        $ret .= '<span>'.$this->getLang('view').'</span>';
        $ret .= '<input type="hidden" name="id" value="'.$ID.'" />';
        $ret .= '<input type="hidden" name="do" value="show" />';
        $ret .= '<select name="filter" size="1" class="edit">';

        foreach ($options as $option) {
            $ret .= '<option value="'.$option.'"';
            if ($filter == $option) $ret .= ' selected="selected"';
            $ret .= '>'.$this->getLang('view_'.$option).'</option>';
        }

        $ret .= '</select>';
        $ret .= '</label>';

        if(isset($_SERVER['REMOTE_USER'])) {
            $ret .= '<label class="simple"><span>'.$this->getLang('view_user').':</span>';
            $ret .= '<input type="checkbox" name="view_user" value="' . $_SERVER['REMOTE_USER'] . '"';
            $ret .= ($_REQUEST['view_user']) ? ' checked="checked"' : '';
            $ret .= '/></label>';
        }

        $ret .= '<input class="button" type="submit" value="'.$this->getLang('btn_refresh').'" />';
        $ret .= '</form></div>';

        return $ret;
    }
  
   /**
    * Returns an array of available view filters for the task list
    */
    function _viewFilters() {
        if (!$_SERVER['REMOTE_USER']) $filters = array('all', 'open', 'done');
        else $filters = array('all', 'open', 'new', 'done', 'my', 'rejected', 'started', 'accepted', 'verified');
        if ($this->getConf('datefield')) {
            $filters[] = 'due';
            $filters[] = 'overdue';
        }
        return $filters;
    }
  
    /**
    * Returns pagination links if more than one page
    */
    function _paginationLinks($num, $cur, $filter) {
        global $ID, $conf;

        if (!is_numeric($num) || ($num < 2)) return '';
        $perpage = ($conf['recent'] != 0) ? $conf['recent'] : 20; // prevent division by zero

        $ret = array();
        for ($i = 1; $i <= $num; $i++) {
            if ($i == $cur) {
                $ret[] = '<strong>'.$i.'</strong>';
            } else {
                $opt = array();
                $opt['first']  = $perpage * ($i - 1);
                $opt['filter'] = $filer;
                if(isset($_REQUEST['view_user'])) {
                    $user = array();
                    $user['id'] = $_REQUEST['view_user'];
                    if($this->helper->_isResponsible($user)) {
                        $opt['view_user'] = $_REQUEST['view_user'];
                    }
                }
                $ret[] = '<a href="'.wl($ID, $opt).'" class="wikilink1" title="'.$i.'">'.$i.'</a>';
            }
        }
        return '<div class="centeralign">'.DOKU_LF.
            join(' | ', $ret).DOKU_LF.
            '</div>'.DOKU_LF;
    }
}
// vim:et:ts=4:sw=4:enc=utf-8:
