<?php
/**
 * Generate a new site secret
 */

init_site_secret();
elgg_reset_system_cache();

system_message(elgg_echo('admin:site:secret_regenerated'));

if (elgg_is_xhr()) {
	$ts = time();
	echo json_encode(array(
		'__elgg_ts' => $ts,
		'__elgg_token' => generate_action_token($ts)
	));
}

forward(REFERER);
