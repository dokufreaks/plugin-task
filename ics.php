<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */
if (!defined('CRLF')) define('CRLF', "\r\n");
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__)).'/../../../');

require_once(DOKU_INC.'inc/init.php');

global $INPUT;
$id = cleanID($INPUT->str('id'));

$data = unserialize(io_readFile(metaFN($id, '.task'), false));
if (!$data['vtodo']) {
    msg('No VTODO data for this task.', -1);
}

$title = p_get_metadata($id, 'title');
if($title) {
    $filename = $title . '.ics';
} else {
    $filename = str_replace(':', '/', $id) . '.ics';
}

$output = 'BEGIN:VCALENDAR'.CRLF.
  'PRODID:-//Wikidesign//NONSGML Task Plugin for DokuWiki//EN'.CRLF.
  'VERSION:2.0'.CRLF.
  $data['vtodo'].
  'END:VCALENDAR'.CRLF;

header("Content-Disposition: attachment; filename='$filename'");
header('Content-Length: '.strlen($output));
header('Connection: close');
header("Content-Type: text/Calendar; name='$filename'");

echo $output;
