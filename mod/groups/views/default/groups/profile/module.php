<?php
/**
 * Group module (also called a group widget)
 *
 * @uses $vars['title']    The title of the module
 * @uses $vars['content']  The module content
 * @uses $vars['all_link'] A link to list content
 * @uses $vars['add_link'] A link to create content
 */

$group = elgg_get_page_owner_entity();

$header_text = "{$vars['all_link']}";

if ($group->canWriteToContainer() && isset($vars['add_link'])) {
	$header_text .= " | {$vars['add_link']}";
}

$header = "<span class=\"float-alt\">$header_text</span>";
$header .= '<h3>' . $vars['title'] . '</h3>';

echo '<li>';
echo elgg_view_module('info', '', $vars['content'], array(
	'header' => $header,
	'class' => 'elgg-module-group',
));
echo '</li>';
