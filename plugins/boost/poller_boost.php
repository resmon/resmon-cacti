#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2011 The Cacti Group                                 |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* tick use required as of PHP 4.3.0 to accomodate signal handling */
declare(ticks = 1);

/*	display_help - displays the usage of the function */
function display_help () {
	$boost_info = plugin_boost_version();
	print "Boost RRD Updater Process Control Version " . $boost_info["version"] . ", Copyright 2005-2010 - Larry Adams\n\n";
	print "usage: poller_boost.php [-f | --force] [-d | --debug] [-h | -H | --help] [-v | -V | --version]\n\n";
	print "-f | --force   - Force the execution of a update process\n";
	print "-v | --verbose - Show details logs at the command line\n";
	print "-d | --debug   - Display verbose output during execution\n";
	print "-V | --version - Display this help message\n";
	print "-h -H --help   - display this help message\n";
}

function sig_handler($signo) {
	switch ($signo) {
		case SIGTERM:
		case SIGINT:
			cacti_log("WARNING: Boost Poller terminated by user", FALSE, "BOOST");

			/* tell the main poller that we are done */
			db_execute("REPLACE INTO settings (name, value) VALUES ('boost_poller_status', 'terminated - end time:" . date("Y-m-d G:i:s") ."')");

			exit;
			break;
		default:
			/* ignore all other signals */
	}

}

function output_rrd_data($start_time, $force = FALSE) {
	global $start, $max_run_duration, $config, $debug, $get_memory, $memory_used;
	global $rrdtool_pipe, $rrdtool_read_pipe;

	include_once($config["base_path"] . "/lib/rrd.php");

	$boost_poller_status = read_config_option("boost_poller_status");
	$rrd_updates = 0;

	/* implement process lock control for boost */
	if (!db_fetch_cell("SELECT GET_LOCK('poller_boost', 1)")) {
		if ($debug){
			cacti_log("DEBUG: Found lock, so another boost process is running");
		}
		return -1;
	}

	/* detect a process that has overrun it's warning time */
	if (substr_count($boost_poller_status, "running")) {
		$status_array = explode(":", $boost_poller_status);

		if (!empty($status_array[1])) {
			$previous_start_time = strtotime($status_array[1]);

			/* if the runtime was exceeded, allow the next process to run */
			if ($previous_start_time + $max_run_duration < $start_time) {
				cacti_log("WARNING: Detected Poller Boost Overrun, Possible Boost Poller Crash", FALSE, "BOOST SVR");
			}
		}
	}

	/* if the poller is not running, or has never run, start */
	/* mark the boost server as running */
	db_execute("REPLACE INTO settings (name, value) VALUES ('boost_poller_status', 'running - start time:" . date("Y-m-d G:i:s") ."')");

	$current_time      = date("Y-m-d G:i:s", $start_time);
	$rrdtool_pipe      = rrd_init();
	$rrdtool_read_pipe = rrd_init();
	$runtime_exceeded  = false;

	/* let's set and track memory usage will we */
	if (!function_exists("memory_get_peak_usage")) {
		$get_memory   = true;
		$memory_used  = memory_get_usage();
	}else{
		$get_memory   = false;
	}

	$delayed_inserts = db_fetch_row("SHOW STATUS LIKE 'Not_flushed_delayed_rows'");
	while($delayed_inserts["Value"]){
		cacti_log("BOOST WAIT: Waiting 1s for delayed inserts are made" , true, "SYSTEM");
		usleep(1000000);
		$delayed_inserts = db_fetch_row("SHOW STATUS LIKE 'Not_flushed_delayed_rows'");
	}

	/* split poller_output_boost */
	$archive_table = "poller_output_boost_arch_" . time();
	db_execute("RENAME TABLE poller_output_boost TO $archive_table");
	db_execute("CREATE TABLE poller_output_boost LIKE $archive_table");
	$more_arch_tables = db_fetch_assoc("SELECT table_name AS name
		FROM information_schema.tables
		WHERE table_schema=SCHEMA()
		AND table_name LIKE 'poller_output_boost_arch_%'
		AND table_name!='$archive_table'
		AND table_rows>0;");

	if(count($more_arch_tables)) {
	foreach($more_arch_tables as $table) {
		$table_name = $table["name"];
		db_execute("INSERT INTO $archive_table SELECT * FROM $table_name");
		db_execute("TRUNCATE TABLE $table_name");
	}
	}

	if (!strlen($archive_table)) {
		cacti_log("ERROR: Failed to retrieve archive table name");
		return -1;
	}

	while (1) {
		$rows = db_fetch_cell("SELECT count(*) FROM $archive_table");

		if ($rows > 0) {
			$rrd_updates += boost_process_poller_output(FALSE, "", $current_time);

			if ($get_memory) {
				$cur_memory    = memory_get_usage();
				if ($cur_memory > $memory_used) {
					$memory_used = $cur_memory;
				}
			}
		}else{
			break;
		}

		if (((time()-$start) > $max_run_duration) && (!$runtime_exceeded)) {
			cacti_log("WARNING: RRD On Demand Updater Exceeded Runtime Limits. Continuing to Process!!!");
			$runtime_exceeded = true;
		}
	}

	/* tell the main poller that we are done */
	db_execute("REPLACE INTO settings (name, value) VALUES ('boost_poller_status', 'complete - end time:" . date("Y-m-d G:i:s") ."')");

	/* log memory usage */
	if (function_exists("memory_get_peak_usage")) {
		db_execute("REPLACE INTO settings (name, value) VALUES ('boost_peak_memory', '" . memory_get_peak_usage() . "')");
	}else{
		db_execute("REPLACE INTO settings (name, value) VALUES ('boost_peak_memory', '" . $memory_used . "')");
	}

	rrd_close($rrdtool_pipe);
	rrd_close($rrdtool_read_pipe);

	/* cleanup  - remove empty arch tables */
	$tables = db_fetch_assoc("SELECT table_name AS name
		FROM information_schema.tables
		WHERE table_schema=SCHEMA()
		AND table_name LIKE 'poller_output_boost_arch_%'
		AND table_rows=0;");

	if (count($tables)) {
	foreach($tables as $table) {
		db_execute("DROP TABLE " . $table["name"]);
	}
	}
	db_execute("SELECT RELEASE_LOCK('poller_boost');");

	return $rrd_updates;
}

function log_boost_statistics($rrd_updates) {
	global $start, $boost_stats_log, $verbose;

	/* take time and log performance data */
	list($micro,$seconds) = explode(" ", microtime());
	$end = $seconds + $micro;

	$cacti_stats = sprintf(
		"Time:%01.4f " .
		"RRDUpdates:%s",
		round($end-$start,2),
		$rrd_updates);

	/* log to the database */
	db_execute("REPLACE INTO settings (name,value) VALUES ('stats_boost', '" . $cacti_stats . "')");

	/* log to the logfile */
	cacti_log("BOOST STATS: " . $cacti_stats , TRUE, "SYSTEM");

	if (isset($boost_stats_log)) {
		$overhead = boost_timer_get_overhead();
		$outstr = "";
		$timer_cycles = 0;
		foreach($boost_stats_log as $area => $entry) {
			if (isset($entry[BOOST_TIMER_TOTAL])) {
				$outstr .= (strlen($outstr) ? ", ":"") . $area . ":" . round($entry[BOOST_TIMER_TOTAL] - (($overhead * $entry[BOOST_TIMER_CYCLES])/BOOST_TIMER_OVERHEAD_MULTIPLIER), 2);
			}
			$timer_cycles += $entry[BOOST_TIMER_CYCLES];
		}

		if (strlen($outstr)) {
			$outstr = "RRDUpdates:$rrd_updates, TotalTime:" . round($end - $start, 0) . ", " . $outstr;
			$timer_overhead = round((($overhead * $timer_cycles)/BOOST_TIMER_OVERHEAD_MULTIPLIER), 0);
			if ($timer_overhead > 0) {
				$outstr .= ", timer_overhead:~$timer_overhead";
			}
			/* log to the database */
			db_execute("REPLACE INTO settings (name,value) VALUES ('stats_detail_boost', '" . str_replace(",", "", $outstr) . "')");

			/* log to the logfile */
			if ($verbose) {
				cacti_log("BOOST DETAIL STATS: " . $outstr, TRUE, "SYSTEM");
			}
		}
	}
}

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}

/* We are not talking to the browser */
$no_http_headers = TRUE;

$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'boost') !== FALSE) {
	chdir('../../');
}

/* include important functions */
include_once("./include/global.php");
include_once($config["base_path"] . "/lib/poller.php");

/* get the boost polling cycle */
$max_run_duration = read_config_option("boost_rrd_update_max_runtime");

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

$debug          = FALSE;
$forcerun       = FALSE;
$verbose        = FALSE;

foreach($parms as $parameter) {
	@list($arg, $value) = @explode("=", $parameter);

	switch ($arg) {
	case "-d":
	case "--debug":
		$debug = TRUE;
		break;
	case "-f":
	case "--force":
		$forcerun = TRUE;
		break;
	case "-v":
	case "--verbose":
		$verbose = TRUE;
		break;
	case "--version":
	case "-V":
	case "--help":
	case "-h":
	case "-H":
		display_help();
		exit;
	default:
		print "ERROR: Invalid Parameter " . $parameter . "\n\n";
		display_help();
		exit;
	}
}

/* install signal handlers for UNIX only */
if (function_exists("pcntl_signal")) {
	pcntl_signal(SIGTERM, "sig_handler");
	pcntl_signal(SIGINT, "sig_handler");
}

/* take time and log performance data */
list($micro,$seconds) = explode(" ", microtime());
$start = $seconds + $micro;

/* let's give this script lot of time to run for ever */
ini_set("max_execution_time", "0");
boost_memory_limit();

if ((read_config_option("boost_rrd_update_enable") == "on") || $forcerun) {
	/* turn on the system level updates as that is what dictates "on/off" */
	if ((!$forcerun) && (read_config_option("boost_rrd_update_system_enable") != "on")) {
		db_execute("REPLACE INTO settings (name,value)
			VALUES ('boost_rrd_update_system_enable','on')");
	}

	$seconds_offset = read_config_option("boost_rrd_update_interval") * 60;

	/* find out if it's time to collect device information */
	$last_run_time = strtotime(read_config_option("boost_last_run_time"));
	$next_run_time = strtotime(read_config_option("boost_next_run_time"));

	/* determine the next start time */
	$current_time = time();
	if (empty($last_run_time)) {
		/* since the poller has never run before, let's fake it out */
		$next_run_time = $current_time + $seconds_offset;
		db_execute("REPLACE INTO settings (name, value) VALUES ('boost_last_run_time', '" . date("Y-m-d G:i:s", $current_time) . "')");
		$last_run_time = $current_time;
	}else{
		$next_run_time = $last_run_time + $seconds_offset;
	}
	$time_till_next_run = $next_run_time - $current_time;

	/* determine if you must output boost table now */
	$max_records        = read_config_option("boost_rrd_update_max_records");
	$current_records    = boost_get_total_rows();

	if (($time_till_next_run <= 0) ||
		($forcerun) ||
		($current_records >= $max_records) ||
		($next_run_time <= $current_time)) {
		db_execute("REPLACE INTO settings (name, value) VALUES ('boost_last_run_time', '" . date("Y-m-d G:i:s", $current_time) . "')");

		/* output all the rrd data to the rrd files */
		$rrd_updates = output_rrd_data($current_time, $forcerun);

		if ($rrd_updates != "-1") {
			log_boost_statistics($rrd_updates);
			$next_run_time = $current_time + $seconds_offset;
		} else { /* rollback last run time */
			db_execute("REPLACE INTO settings (name, value) VALUES ('boost_last_run_time', '" . date("Y-m-d G:i:s", $last_run_time) . "')");
		}

		api_plugin_hook("boost_poller_bottom");
	}

	/* store the next run time so that people understand */
	db_execute("REPLACE INTO settings (name, value) VALUES ('boost_next_run_time', '" . date("Y-m-d G:i:s", $next_run_time) . "')");
}else{
	/* turn off the system level updates */
	if (read_config_option("boost_rrd_update_system_enable") == "on") {
		db_execute("REPLACE INTO settings (name,value)
			VALUES ('boost_rrd_update_system_enable','')");
	}

	$rows =  boost_get_total_rows();

	if ($rows > 0) {
		/* determine the time to clear the table */
		$current_time = time();

		/* output all the rrd data to the rrd files */
		$rrd_updates = output_rrd_data($current_time, $forcerun);

		if ($rrd_updates != "-1") {
			log_boost_statistics($rrd_updates);
		}
	}
}

/* remove stale png's from the cache.  I consider png's stale afer 1 hour */
if ((read_config_option("boost_png_cache_enable") == "on") || $forcerun) {
	$cache_directory = read_config_option("boost_png_cache_directory");
	$remove_time = time() - 3600;

	$directory_contents = array();

	if ($handle = opendir($cache_directory)) {
		/* This is the correct way to loop over the directory. */
		while (FALSE !== ($file = readdir($handle))) {
			$directory_contents[] = $file;
		}

		closedir($handle);
	}

	/* remove age old files */
	if (sizeof($directory_contents)) {
		/* goto the cache directory */
		chdir($cache_directory);

		/* check and fry as applicable */
		foreach($directory_contents as $file) {
			if (is_writable($file)) {
				$modify_time = filemtime($file);
				if ($modify_time < $remove_time) {
					/* only remove jpeg's and png's */
					if ((substr_count(strtolower($file), ".png")) ||
						(substr_count(strtolower($file), ".jpg"))) {
						unlink($file);
					}
				}
			}
		}
	}
}

?>
