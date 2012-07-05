<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2010 Reinhard Scheck aka gandalf                     |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/
function plugin_init_rrdclean() {
	global $plugin_hooks;
	$plugin_hooks['config_arrays']['rrdclean'] 			= 'rrdclean_config_arrays';
	$plugin_hooks['draw_navigation_text']['rrdclean'] 	= 'rrdclean_draw_navigation_text';
	$plugin_hooks['config_settings']['rrdclean'] 		= 'rrdclean_config_settings';
	$plugin_hooks['poller_bottom']['rrdclean'] 			= 'rrdclean_poller_bottom';
}

function plugin_rrdclean_install () {
	api_plugin_register_hook('rrdclean', 'config_arrays', 'rrdclean_config_arrays', 'setup.php');
	api_plugin_register_hook('rrdclean', 'draw_navigation_text', 'rrdclean_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('rrdclean', 'config_settings', 'rrdclean_config_settings', 'setup.php');
	api_plugin_register_hook('rrdclean', 'poller_bottom', 'rrdclean_poller_bottom', 'setup.php');

	rrdclean_setup_table_new ();
}

function plugin_rrdclean_uninstall () {
	// Do any extra Uninstall stuff here
}

function plugin_rrdclean_check_config () {
	// Here we will check to ensure everything is configured
	rrdclean_check_upgrade ();

	return true;
}

function plugin_rrdclean_upgrade () {
	// Here we will upgrade to the newest version
	rrdclean_check_upgrade ();

	return false;
}

function plugin_rrdclean_version () {
	return rrdclean_version();
}

function rrdclean_check_upgrade () {
	global $config;

	$files = array('index.php', 'plugins.php', 'rrdcleaner.php');
	if (isset($_SERVER['PHP_SELF']) && !in_array(basename($_SERVER['PHP_SELF']), $files)) {
		return;
	}

	$current = plugin_rrdclean_version();
	$current = $current['version'];
	$old     = db_fetch_row("SELECT * FROM plugin_config WHERE directory='rrdclean'");
	if (sizeof($old) && $current != $old["version"]) {
		/* if the plugin is installed and/or active */
		if ($old["status"] == 1 || $old["status"] == 4) {
			/* re-register the hooks */
			plugin_rrdclean_install();

			/* perform a database upgrade */
			rrdclean_database_upgrade();
		}

		# stub for updating tables
		#$_columns = array_rekey(db_fetch_assoc("SHOW COLUMNS FROM <table>"), "Field", "Field");
		#if (!in_array("<new column>", $_columns)) {
		#	db_execute("ALTER TABLE <table> ADD COLUMN <new column> VARCHAR(40) NOT NULL DEFAULT '' AFTER <old column>");
		#}

		# new hooks
		#api_plugin_register_hook('rrdclean', 'config_settings',       'rrdclean_config_settings', 'setup.php');
		#if (api_plugin_is_enabled('rrdclean')) {
			# may sound ridiculous, but enables new hooks
		#	api_plugin_enable_hooks('rrdclean');
		#}
		# register new version
		$info = plugin_rrdclean_version();
		$id   = db_fetch_cell("SELECT id FROM plugin_config WHERE directory='rrdclean'");
		db_execute("UPDATE plugin_config
			SET name='" . $info["longname"] . "',
			author='"   . $info["author"]   . "',
			webpage='"  . $info["homepage"] . "',
			version='"  . $info["version"]  . "'
			WHERE id='$id'");
	}
}

function rrdclean_database_upgrade () {
}

function rrdclean_check_dependencies() {
	global $plugins, $config;

	return true;
}

function rrdclean_setup_table_new () {
/* nothing to do, yet
 * we will create our tables on demand,
 * see: rrdcleaner.php
 */
}

function rrdclean_version () {
	return array('name' => 'RRD Cleaner',
			'version' 	=> '0.41',
			'longname'	=> 'RRD File Cleaner',
			'author'	=> 'Reinhard Scheck',
			'homepage'	=> 'http://docs.cacti.net/plugin:rrdclean',
			'email'		=> 'gandalf@cacti.net',
			'url'		=> 'http://docs.cacti.net/plugin:rrdclean'
			);
}

function rrdclean_config_settings () {
	global $tabs, $settings, $config;

	/* check for an upgrade */
	plugin_rrdclean_check_config();

	if (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) != 'settings.php')
		return;

	$temp = array(
		"rrdclean_header" => array(
			"friendly_name" => "RRD Cleaner",
			"method" => "spacer",
		),
		"rrd_backup" => array(
			"friendly_name" => "Backup directory",
			"description" => "This is the directory where rrd files are <strong>copied</strong> for <strong>Backup</strong>",
			"method" => "dirpath",
			"default" => $config["base_path"] . "/rra/backup/",
			"max_length" => 255,
		),
		"rrd_archive" => array(
			"friendly_name" => "Archive directory",
			"description" => "This is the directory where rrd files are <strong>moved</strong> for <strong>Archive</strong>",
			"method" => "dirpath",
			"default" => $config["base_path"] . "/rra/archive/",
			"max_length" => 255,
		)
		);

	/* create a new Settings Tab, if not already in place */
	if (!isset($tabs["misc"])) {
		$tabs["misc"] = "Misc";
	}

	/* and merge own settings into it */
	if (isset($settings["misc"]))
		$settings["misc"] = array_merge($settings["misc"], $temp);
	else
		$settings["misc"] = $temp;
}

function rrdclean_config_arrays () {
	global $user_auth_realms, $user_auth_realm_filenames, $menu;

	if (function_exists('api_plugin_register_realm')) {
		# register all php modules required for this plugin
		api_plugin_register_realm('rrdclean', 'rrdcleaner.php,rrdmove.php', 'RRD Cleaner', 1);
	} else {
		# realms
		$user_auth_realms[36]										= 'RRD Cleaner';
		# these are the files protected by our realm id
		$user_auth_realm_filenames['rrdcleaner.php']				= 36;
		$user_auth_realm_filenames['rrdcleaner.php?action=restart']	= 36;
	}

	$temp = $menu["Utilities"]['logout.php'];
	unset($menu["Utilities"]['logout.php']);
	$menu["Utilities"]['plugins/rrdclean/rrdcleaner.php'] = "RRD Cleaner";
	$menu["Utilities"]['logout.php'] = $temp;
}

function rrdclean_draw_navigation_text ($nav) {
	$nav["rrdcleaner.php:"] 		= array("title" => "RRD Cleaner", "mapping" => "index.php:", "url" => "rrdcleaner.php", "level" => "1");
	$nav["rrdcleaner.php:actions"] 	= array("title" => "Actions", "mapping" => "index.php:,rrdcleaner.php:", "url" => "rrdcleaner.php?action=actions", "level" => "2");
	$nav["rrdcleaner.php:restart"] 	= array("title" => "List unused Files", "mapping" => "rrdcleaner.php:", "url" => "rrdcleaner.php?action=restart", "level" => "2");

	return $nav;
}

function rrdclean_poller_bottom () {
	global $config;

	$command_string = trim(read_config_option("path_php_binary"));

	// If its not set, just assume its in the path
	if (trim($command_string) == '')
		$command_string = "php";
	$extra_args = 	' -q ' .
					$config['base_path'] .
					'/plugins/rrdclean/rrdmove.php';

	exec_background($command_string, $extra_args);
}
?>
