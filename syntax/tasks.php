<?php
/**
 * Task Plugin, tasks component: lists tasks of a given namespace
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Esther Brunner <wikidesign@gmail.com>
 */

class syntax_plugin_task_tasks extends DokuWiki_Syntax_Plugin {
    /** @var helper_plugin_task */
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
        $this->Lexer->addSpecialPattern('\{\{tasks>.+?\}\}', $mode, 'plugin_task_tasks');
    }

    /**
     * @param   string $match The text matched by the patterns
     * @param   int $state The lexer state for the match
     * @param   int $pos The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render, false don't add an instruction
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        $match = substr($match, 8, -2); // strip {{topic> from start and }} from end
        list($match, $flags) = array_pad(explode('&', $match, 2),2,'');
        $flags = explode('&', $flags);
        list($match, $refine) = array_pad(explode(' ', $match, 2),2,'');
        list($ns, $filter) = array_pad(explode('?', $match, 2),2,'');

        if ($ns == '*' || $ns == ':') {
            $ns = '';
        } elseif ($ns == '.') {
            $ns = getNS($ID);
        } else {
            $ns = cleanID($ns);
        }

        return [$ns, $filter, $flags, $refine];
    }

    /**
     * @param string $format output format being rendered
     * @param Doku_Renderer $renderer the current renderer object
     * @param array $data data created by handler()
     * @return bool rendered correctly? (however, returned value is not used at the moment)
     */
    public function render($format, Doku_Renderer $renderer, $data) {
        global $conf, $INPUT;

        list($ns, $filter, $flags, $refine) = $data;

        $select = false;
        if (!$filter || $filter == 'select') {
            $select = true;
            $filter = trim($INPUT->str('filter'));
        }
        $filter = strtolower($filter);
        $filters = $this->viewFilters();
        if (!in_array($filter, $filters)) {
            $filter = 'open';
        }
        $user = ''; //FIXME getTasks() does not use $user...
        if($INPUT->has('view_user')) {
            $user = $INPUT->str('view_user');
        }

        $pages = [];
        if ($this->helper) {
            $pages = $this->helper->getTasks($ns, null, $filter, $user);
        }

        // use tag refinements?
        if ($refine) {
            /** @var helper_plugin_tag $tag */
            if (!$tag = $this->loadHelper('tag', false)) {
                msg('The Tag Plugin must be installed to use tag refinements.', -1);
            } else {
                $pages = $tag->tagRefine($pages, $refine);
            }
        }

        if(!$pages) {
            if($format != 'xhtml') {
                return true;
            }
            $renderer->nocache();
            if($select) {
                $renderer->doc .= $this->viewMenu($filter);
            }
            if(auth_quickaclcheck($ns.':*') >= AUTH_CREATE) {
                if(!in_array('noform', $flags)) {
                    if ($this->helper) {
                        $renderer->doc .= $this->helper->newTaskForm($ns);
                    }
                }
            }
            return true; // nothing to display
        }

        // prepare pagination
        $c = count($pages);
        $perpage = ($conf['recent'] != 0) ? $conf['recent'] : 20; // prevent division by zero
        $numOfPages = $currentPage = 1;
        if ($c > $perpage) {
            $numOfPages = ceil($c / $perpage);
            $first = $INPUT->int('first');
            $currentPage = round($first / $perpage) + 1; //TODO check 14/20=0.7==>1, 1+1=2?
            $pages = array_slice($pages, $first, $perpage);
        }

        if ($format == 'xhtml') {

            // prevent caching to ensure content is always fresh
            $renderer->nocache();

            // show form to create a new task?
            $hasCreatePermission = auth_quickaclcheck($ns.':*') >= AUTH_CREATE;
            if($hasCreatePermission && $this->getConf('tasks_formposition') == 'top') {
                if(!in_array('noform', $flags)) {
                    if ($this->helper) {
                        $renderer->doc .= $this->helper->newTaskForm($ns);
                    }
                }
            }

            // let Pagelist Plugin do the work for us
            /** @var helper_plugin_pagelist $pagelist */
            if (!$pagelist = $this->loadHelper('pagelist', false)) {
                msg('The Pagelist Plugin must be installed for task lists.', -1);
                return false;
            }

            // show view filter popup if not
            if ($select) {
                $renderer->doc .= $this->viewMenu($filter);
            }

            // prepare pagelist columns
            /* @deprecated 2022-10-17 */
            if(method_exists($pagelist, 'setHeader')) {
                //since 2022-10-17 some new methods are introduced
                $pagelist->setHeader([
                    'page' => $this->getLang('task'),
                    'date' => str_replace(' ', '&nbsp;', $this->getLang('date')),
                    'user' => str_replace(' ', '&nbsp;', $this->getLang('user'))
                ]);
                $pagelist->modifyColumn('date', $this->getConf('datefield'));
                $pagelist->modifyColumn('user', true);
                $pagelist->addColumn('task', 'status');
                $pagelist->setFlags(['header']); //allow override via user provided flags
                $pagelist->setFlags($flags);
            } else {
                //before 2022-10-17
                $pagelist->header['page'] = $this->getLang('task');
                $pagelist->header['date'] = str_replace(' ', '&nbsp;', $this->getLang('date'));
                $pagelist->header['user'] = str_replace(' ', '&nbsp;', $this->getLang('user'));
                $pagelist->column['date'] = $this->getConf('datefield');
                $pagelist->column['user'] = true;
                $pagelist->setFlags(['header']); //allow override via user provided flags
                $pagelist->setFlags($flags);
                $pagelist->addColumn('task', 'status');
            }


            // output list
            $pagelist->startList();
            if($this->getConf('tasks_newestfirst')) {
                $pages = array_reverse($pages);
            }
            foreach ($pages as $page) {
                $pagelist->addPage($page);
            }
            $renderer->doc .= $pagelist->finishList();
            $renderer->doc .= $this->paginationLinks($numOfPages, $currentPage, $filter);

            // show form to create a new task?
            if($hasCreatePermission && ($this->getConf('tasks_formposition') == 'bottom')) {
                if(!in_array('noform', $flags)) {
                    if ($this->helper) $renderer->doc .= $this->helper->newTaskForm($ns);
                }
            }

            return true;

            // for metadata renderer
        } elseif ($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */
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
    protected function viewMenu($filter) {
        if (class_exists('dokuwiki\Form\Form')) {
            return $this->viewMenuNew($filter);
        } else {
            return $this->viewMenuOld($filter);
        }
    }

    /**
     * Show a popup to select the task view filter.
     * This is the new version using class dokuwiki\Form\Form.
     *
     * @see viewMenu
     */
    protected function viewMenuNew($filter) {
        global $ID, $INPUT;

        $options = $this->viewFilters();

        $form = new dokuwiki\Form\Form(['id' => 'task__changeview_form']);
        $pos = 1;

        $form->addHTML('<label class="simple">', $pos++);
        $form->addHTML('<span>'.$this->getLang('view').'</span>', $pos++);

        // Set hidden fields
        $form->setHiddenField ('id', $ID);
        $form->setHiddenField ('do', 'show');

        // Select status from drop down list
        $dropDownOptions = [];
        $selected = null;
        foreach ($options as $option) {
            if ($filter == $option) {
                $selected = $option.' ';
            }
            $dropDownOptions [$option.' '] = $this->getLang('view_'.$option);
        }
        $input = $form->addDropdown('filter', $dropDownOptions, null, $pos++);
        $input->val($selected);

        $form->addHTML('</label>', $pos++);

        if($INPUT->server->has('REMOTE_USER')) {
            $form->addHTML('<label class="simple"><span>'.$this->getLang('view_user').':</span>', $pos++);
            $input = $form->addCheckbox('view_user', null, $pos++);
            $input->attr('value', $INPUT->server->str('REMOTE_USER'));
            if ($INPUT->str('view_user')) {
                $input->attr('checked', 'checked');
            }
            $form->addHTML('</label>', $pos++);
        }

        // Add button
        $form->addButton(null, $this->getLang('btn_refresh'), $pos++);

        $ret  = '<div class="task_viewmenu">';
        $ret .= $form->toHTML();
        $ret .= '</div>';

        return $ret;
    }

    /**
     * Show a popup to select the task view filter.
     * Old function generating all HTML on its own.
     *
     * @see viewMenu
     */
    protected function viewMenuOld($filter) {
        global $ID, $lang, $INPUT;

        $options = $this->viewFilters();

        $ret  = '<div class="task_viewmenu">';
        $ret .= '<form id="task__changeview_form" method="post" action="'.script().'" accept-charset="'.$lang['encoding'].'">';
        $ret .= '<label class="simple">';
        $ret .= '<span>'.$this->getLang('view').'</span>';
        $ret .= '<input type="hidden" name="id" value="'.$ID.'" />';
        $ret .= '<input type="hidden" name="do" value="show" />';
        $ret .= '<select name="filter" size="1" class="edit">';

        foreach ($options as $option) {
            $ret .= '<option value="'.$option.'"';
            if ($filter == $option) {
                $ret .= ' selected="selected"';
            }
            $ret .= '>'.$this->getLang('view_'.$option).'</option>';
        }

        $ret .= '</select>';
        $ret .= '</label>';

        if($INPUT->server->has('REMOTE_USER')) {
            $ret .= '<label class="simple"><span>'.$this->getLang('view_user').':</span>';
            $ret .= '<input type="checkbox" name="view_user" value="' . $INPUT->server->str('REMOTE_USER') . '"';
            $ret .= $INPUT->str('view_user') ? ' checked="checked"' : '';
            $ret .= '/></label>';
        }

        $ret .= '<input class="button" type="submit" value="'.$this->getLang('btn_refresh').'" />';
        $ret .= '</form></div>';

        return $ret;
    }

   /**
    * Returns an array of available view filters for the task list
    */
    protected function viewFilters() {
        global $INPUT;
        if ($INPUT->server->has('REMOTE_USER')) {
            $filters = ['all', 'open', 'new', 'done', 'my', 'rejected', 'started', 'accepted', 'verified'];
        } else {
            $filters = ['all', 'open', 'done'];
        }
        if ($this->getConf('datefield')) {
            $filters[] = 'due';
            $filters[] = 'overdue';
        }
        return $filters;
    }

    /**
     * Returns html of pagination links if more than one page
     *
     * @param int $num number of pagination pages
     * @param int $cur current pagination page no
     * @param string $filter current active filter
     * @return string html of pagination links
     */
    protected function paginationLinks($num, $cur, $filter) {
        global $ID, $conf, $INPUT;

        if (!is_numeric($num) || $num < 2) {
            return '';
        }
        $perpage = ($conf['recent'] != 0) ? $conf['recent'] : 20; // prevent division by zero

        $ret = [];
        for ($i = 1; $i <= $num; $i++) {
            if ($i == $cur) {
                $ret[] = '<strong>'.$i.'</strong>';
            } else {
                $param = [];
                $param['first']  = $perpage * ($i - 1);
                $param['filter'] = $filter;
                if($INPUT->has('view_user')) {
                    $user = [];
                    $user['id'] = $INPUT->str('view_user');
                    if($this->helper->isResponsible($user)) {
                        $param['view_user'] = $INPUT->str('view_user');
                    }
                }
                $ret[] = '<a href="'.wl($ID, $param).'" class="wikilink1" title="'.$i.'">'.$i.'</a>';
            }
        }
        return '<div class="centeralign">'.
            join(' | ', $ret).
            '</div>';
    }
}
