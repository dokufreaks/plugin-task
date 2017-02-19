<?php
/**
 * Metadata for configuration manager plugin
 * Additions for the Task Plugin
 *
 * @author    Esther Brunner <wikidesign@gmail.com>
 */
$meta['datefield']          = array('onoff');
$meta['tasks_formposition'] = array(
                                'multichoice',
                                '_choices' => array('none', 'top', 'bottom')
                              );
$meta['tasks_newestfirst']  = array('onoff');
$meta['layout']             = array(
                                'multichoice',
                                '_choices' => array('built-in', 'template')
                              );

//Setup VIM: ex: et ts=2 enc=utf-8 :
