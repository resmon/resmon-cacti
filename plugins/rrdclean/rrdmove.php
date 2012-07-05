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
 | but ANY WARRANTY; without even the implied warranty of                  |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset ($_SERVER["argv"][0]) || isset ($_SERVER['REQUEST_METHOD']) || isset ($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}

/* We are not talking to the browser */
$no_http_headers = true;

/* let PHP run just as long as it has to */
ini_set("max_execution_time", "0");

error_reporting(E_ALL);
$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}

if (file_exists("./include/global.php")) {
	include ("./include/global.php");
} else {
	include ("./include/config.php");
}
global $config, $database_default;
include_once($config["library_path"] . "/database.php");

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

$debug    = FALSE;
$force = FALSE;

foreach ($parms as $parameter) {
	@ list ($arg, $value) = @ explode('=', $parameter);

	switch ($arg) {
		case "-h" :
			display_help();
			exit;
		case "-v" :
			display_help();
			exit;
		case "--version" :
			display_help();
			exit;
		case "--help" :
			display_help();
			exit;
		case "--force" :
			$force = true;
			break;
		case "--debug" :
			$debug = true;
			break;
		default :
			print "ERROR: Invalid Parameter " . $parameter . "\n\n";
			display_help();
			exit;
	}
}


/* are my tables already present? */
$sql	= "show tables from `" . $database_default . "` like 'plugin_rrdclean_action'";
#$result = db_fetch_assoc($sql) or die (mysql_error());
$result = db_fetch_assoc($sql);

/* if the table that holds the actions is present, work on it */
if (sizeof($result)) {
	/* Get all files to act on */
	$file_array = db_fetch_assoc("SELECT " .
									"plugin_rrdclean_action.id, " .
									"plugin_rrdclean_action.name, " .
									"plugin_rrdclean_action.local_data_id, " .
									"plugin_rrdclean_action.action " .
								"FROM " .
									"plugin_rrdclean_action");
	
	if ((sizeof($file_array) > 0) || $force) {
		/* there's something to do for us now */
		remove_files($file_array, $debug);
		if ($force) {
			cleanup_ds_and_graphs($debug);
		}
	}
}

/*
 * remove_files
 * remove all unwanted files; the list is given by table plugin_rrdclean_action
 */
function remove_files($file_array, $debug) {
	global $config;
	include_once ($config["library_path"] . "/api_graph.php");
	include_once ($config["library_path"] . "/api_data_source.php");

	cacti_log("RRDClean is now running on " . sizeof($file_array) . " items", true, "RRDCLEAN");

	/* determine the location of the RRA files */
	if (isset ($config["rra_path"])) {
		$rra_path = $config["rra_path"];
	} else {
		$rra_path = $config["base_path"] . "/rra";
	}

	/* let's prepare the directories */
	$rrd_backup  = read_config_option("rrd_backup",  TRUE);
	$rrd_archive = read_config_option("rrd_archive", TRUE);

	if ($rrd_backup == "")
		$rrd_backup = $rra_path . "/backup";
	if ($rrd_archive == "")
		$rrd_archive = $rra_path . "/archive";

	rrdclean_create_path($rrd_backup);
	rrdclean_create_path($rrd_archive);

	/* now scan the files */
	foreach ($file_array as $file) {
		$source_file = $rra_path . "/" . $file["name"];
		switch ($file['action']) {
		case "1" :
			if (unlink($source_file)) {
				cacti_log("Deleted: " . $file["name"], true, "RRDCLEAN");
			} else {
				cacti_log($file["name"] . " Error: unable to delete from $rra_path!", true, "RRDCLEAN");
			}
			break;
		case "2" :
			$target_file = $rrd_backup . "/" . $file["name"];
			$target_dir = dirname($target_file);
			if (!is_dir($target_dir)) {
				rrdclean_create_path($target_dir);
			}

			if (copy($source_file, $target_file)) {
				cacti_log("Copied: " . $file["name"] . " to: " . $rrd_backup, true, "RRDCLEAN");
			} else {
				cacti_log($file["name"] . " Error: unable to save to $rrd_backup!", true, "RRDCLEAN");
			}
			break;
		case "3" :
			$target_file = $rrd_archive . "/" . $file["name"];
			$target_dir = dirname($target_file);
			if (!is_dir($target_dir)) {
				rrdclean_create_path($target_dir);
			}

			if (rename($source_file, $target_file)) {
				cacti_log("Moved: " . $file["name"] . " to: " . $rrd_archive, true, "RRDCLEAN");
			} else {
				cacti_log($file["name"] . " Error: unable to move to $rrd_archive!", true, "RRDCLEAN");
			}
			break;
		}

		/* drop from plugin_rrdclean_action table */
		$sql = "DELETE FROM `plugin_rrdclean_action` WHERE name = '" . $file["name"] . "'";
		db_execute($sql);

		if (read_config_option("log_verbosity", TRUE) == POLLER_VERBOSITY_DEBUG) {
			cacti_log("delete from plugin_rrdclean_action: " . $file["name"], true, "RRDCLEAN");
		}

		//fetch all local_graph_id's according to this data source
		$lgis = db_fetch_assoc("SELECT DISTINCT " .
									"graph_local.id " .
								"FROM " .
									"graph_local " .
								"INNER JOIN " .
									"( " .
										"( data_template_rrd " .
											"INNER JOIN graph_templates_item " .
												"ON data_template_rrd.id=graph_templates_item.task_item_id " .
										") " .
									"INNER JOIN " .
										"data_local " .
										"ON data_template_rrd.local_data_id=data_local.id " .
									") " .
									"ON graph_local.id = graph_templates_item.local_graph_id " .
								"WHERE ( " .
									"local_data_id=" . $file["local_data_id"] .	")");
		if (sizeof($lgis)) {
			/* anything found? */
			cacti_log("Processing " . sizeof($lgis) . " Graphs for data source id: " . $file["local_data_id"], true, "RRDCLEAN");

			/* get them all */
			foreach ($lgis as $item) {
				$remove_lgis[] = $item['id'];
				cacti_log("remove local_graph_id=" . $item['id'], true, "RRDCLEAN");
			}

			/* and remove them in a single run */
			if (!empty ($remove_lgis)) {
				api_graph_remove_multi($remove_lgis);
	}
		}

		/* remove related data source if any */
		if ($file["local_data_id"] > 0) {
			cacti_log("removing data source: " . $file["local_data_id"], true, "RRDCLEAN");
			api_data_source_remove($file["local_data_id"]);
		}
	}

	cacti_log("RRDClean has finished " . sizeof($file_array) . " items", true, "RRDCLEAN");
}

function rrdclean_create_path($path) {
	global $config;

	if (!is_dir($path)) {
		if (mkdir($path, 0775)) {
			if ($config["cacti_server_os"] != "win32") {
				$owner_id      = fileowner($config["rra_path"]);
				$group_id      = filegroup($config["rra_path"]);

				// NOTE: chown/chgrp fails for non-root users, checking their
				// result is therefore irrevelevant
				@chown($path, $owner_id);
				@chgrp($path, $group_id);
			}
		}else{
			cacti_log("ERROR: Unable to create directory '" . $path . "'", FALSE);
		}
	}

	// if path existed, we can return true
	return is_dir($path) && is_writable($path);
}

/*
 * cleanup_ds_and_graphs - courtesy John Rembo
 */
function cleanup_ds_and_graphs() {
	global $config;

	include_once ($config["library_path"] . "/rrd.php");
	include_once ($config["library_path"] . "/utility.php");
	include_once ($config["library_path"] . "/api_graph.php");
	include_once ($config["library_path"] . "/api_data_source.php");
	include_once ($config["library_path"] . "/functions.php");

	$remove_ldis = array ();
	$remove_lgis = array ();

	cacti_log("RRDClean now cleans up all data sources and graphs", true, "RRDCLEAN");
	//fetch all local_data_id's which have appropriate data-sources
	$rrds = db_fetch_assoc("SELECT " .
								"local_data_id, " .
								"name_cache, " .
								"data_source_path " .
							"FROM " .
								"data_template_data " .
							"WHERE " .
								"name_cache > '' ");

	//filter those whose rrd files doesn't exist
	foreach ($rrds as $item) {
		$ldi = $item['local_data_id'];
		$name = $item['name_cache'];
		$ds_pth = $item['data_source_path'];
		$real_pth = str_replace('<path_rra>', $config['rra_path'], $ds_pth);
		if (!file_exists($real_pth)) {
			if (!in_array($ldi, $remove_ldis)) {
				$remove_ldis[] = $ldi;
				cacti_log("RRD file is missing for data source name: $name (local_data_id=$ldi)", true, "RRDCLEAN");
			}
		}
	}

	if (empty ($remove_ldis)) {
		cacti_log("No missing rrd files found", true, "RRDCLEAN");
		return 0;
	}

	cacti_log("Processing Graphs", true, "RRDCLEAN");
	//fetch all local_graph_id's according to filtered rrds
	$lgis = db_fetch_assoc("SELECT DISTINCT " .
								"graph_local.id " .
						"FROM " .
								"graph_local " .
							"INNER JOIN " .
								"( " .
									"( data_template_rrd " .
									"INNER JOIN graph_templates_item " .
									"ON data_template_rrd.id=graph_templates_item.task_item_id " .
									") " .
								"INNER JOIN " .
								"data_local " .
								"ON data_template_rrd.local_data_id=data_local.id " .
							") " .
								"ON graph_local.id = graph_templates_item.local_graph_id " .
							"WHERE ( " .
								array_to_sql_or($remove_ldis, 'local_data_id') . ")");

	foreach ($lgis as $item) {
		$remove_lgis[] = $item['id'];
		cacti_log("RRD file missing for local_graph_id=" . $item['id'], true, "RRDCLEAN");
	}

	if (!empty ($remove_lgis)) {
		cacti_log("removing graphs", true, "RRDCLEAN");
		api_graph_remove_multi($remove_lgis);
	}

	cacti_log("removing data sources", true, "RRDCLEAN");
	api_data_source_remove_multi($remove_ldis);

	cacti_log("removed graphs:" . count($remove_lgis) . " removed data-sources:" . count($remove_ldis), true, "RRDCLEAN");
}

/*
 * display_help
 * displays the usage of the function
 */
function display_help() {
	print "RRDCleaner v0.37, Copyright 2007,2008 - Reinhard Scheck\n\n";
	print "usage: rrdmove.php [-h] [--help] [-v] [--version] [--force] [--debug]\n\n";
	print "-v --version  - Display this help message\n";
	print "-h --help     - display this help message\n";
	print "--force       - force execution, e.g. for testing\n";
	print "--debug       - debug execution, e.g. for testing\n";
}
?>
