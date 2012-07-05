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
 | but ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/
chdir('../../');
include_once ("./include/auth.php");
include_once ($config["library_path"] . "/functions.php");

define("MAX_DISPLAY_PAGES", 21);

if (isset ($config["rra_path"])) {
	$rra_path = $config["rra_path"];
} else {
	$rra_path = $config["base_path"] . "/rra";
}

$ds_actions = array (
	1 => "Delete",
	2 => "Backup",
	3 => "Archive"
);

/* set default action */
if (!isset ($_REQUEST["action"])) {
	$_REQUEST["action"] = "";
}

if (isset($_REQUEST["rescan"])) {
	$_REQUEST["action"] = "restart";
}

switch ($_REQUEST["action"]) {
case 'actions' :
	include_once ($config["include_path"] . "/top_header.php");
	do_rrd();
	/* show current table again */
	list_rrd();
	include_once ($config["include_path"] . "/bottom_footer.php");

	break;
case 'restart' :
	include_once ($config["include_path"] . "/top_header.php");
	/* fill files name table */
	rrdclean_fill_table();
	list_rrd();
	include_once ($config["include_path"] . "/bottom_footer.php");

	break;
default :
	include_once ($config["include_path"] . "/top_header.php");
	/* fill files name table */
	list_rrd();
	include_once ($config["include_path"] . "/bottom_footer.php");

	break;
}

/*
 * Fill RRDCleaner's table
 */
function rrdclean_fill_table() {
 	global $config, $rra_path;

 	/* suppress warnings */
 	error_reporting(0);

 	/* install the rrdclean error handler */
 	set_error_handler("rrdclean_error_handler");

	/* delete old file names table */
	rrdclean_delete_table();

	/* create new file names table */
	rrdclean_create_table();

	$files_unused = get_files();

	$i = 0;
	if (sizeof($files_unused) > 0) {
		foreach ($files_unused as $unused_file) {
			$filesize 			= filesize($rra_path . "/" . $unused_file);
			$filemtime 			= filemtime($rra_path . "/" . $unused_file);

			$last_mod 			= date("Y-m-d H:i:s", $filemtime);
			$size = round(($filesize / 1024 / 1024), 2);

			/*
			 * see, if any data source is still associated to this rrd file
			 * currently depends on a single <path_rra>
			 */
			$data_source = db_fetch_row("SELECT DISTINCT " .
						"`data_source_path`, " .
						"`name_cache`, " .
						"`local_data_id`, " .
						"`data_template_id`, " .
						"`data_template`.`name` " .
					"FROM " .
						"`data_template_data` " .
					"LEFT JOIN " .
						"`data_template` " .
					"ON " .
						"`data_template_data`.`data_template_id`=`data_template`.`id` " .
					"WHERE " .
						"`data_source_path`=" . '"<path_rra>/' . $unused_file . '"');

			if (isset ($data_source)) {
				$sql = "INSERT INTO `plugin_rrdclean` VALUES('" .
						""						 			. "', '" .
						$unused_file 						. "', '" .
						$last_mod	 						. "', '" .
						$size		 						. "', '" .
						$data_source["name_cache"] 			. "', '" .
						$data_source["local_data_id"] 		. "', '" .
						$data_source["data_template_id"]	. "', '" .
						$data_source["name"] 				.
						"')";
			} else {
			$sql = "INSERT INTO `plugin_rrdclean` VALUES('" .
						""						 			. "', '" .
					$unused_file 	. "', '" .
						$last_mod	 						. "', '" .
						$size		 						. "', '" .
						"None"					 			. "', '" .
						0							 		. "', '" .
						0									. "', '" .
						"None"				 				.
						"')";
			}
			db_execute($sql);

			$i++;
		}
		clearstatcache();
	}

	/* restore original error handler */
	restore_error_handler();
}

/*
 * Determine the last time the rrdcleaner table was updated
 */
function rrdcleaner_lastupdate() {
	$status = db_fetch_row("SHOW TABLE STATUS LIKE 'plugin_rrdclean'");

	if (sizeof($status)) {
		return $status["Update_time"];
	}
}

/*
 * Delete RRDCleaner's intermediate tables
 */
function rrdclean_delete_table() {
	global $config;

	/* suppress warnings */
	error_reporting(0);

	/* install the rrdclean error handler */
	set_error_handler("rrdclean_error_handler");

	$sql = "DROP TABLE IF EXISTS `plugin_rrdclean`";
	db_execute($sql);

	/* drop old plugin_rrclean_action table */
	$sql = "DROP TABLE IF EXISTS `plugin_rrdclean_action`";
	db_execute($sql);

	/* restore original error handler */
	restore_error_handler();
}

/*
 * Create intermediate tables for RRDCleaner by fetching files
 * from disk and comparing to unused files
 */
function rrdclean_create_table() {
	global $config;

	/* suppress warnings */
	error_reporting(0);

	/* install the rrdclean error handler */
	set_error_handler("rrdclean_error_handler");

	$sql =     "CREATE TABLE IF NOT EXISTS `plugin_rrdclean` (
				`id` 					mediumint(8) unsigned NOT NULL auto_increment,
				`name` 		varchar(255) NOT NULL default '',
				`last_mod` 	datetime NOT NULL default '0000-00-00 00:00:00',
				`size`		varchar(255) NOT NULL default '',
				`name_cache`			varchar(255) NOT NULL default '',
				`local_data_id`			mediumint(8) unsigned NOT NULL default '0',
				`data_template_id`		mediumint(8) unsigned NOT NULL default '0',
				`data_template_name`	varchar(150) NOT NULL default '',
				PRIMARY KEY  (`id`)
				) ENGINE=MyISAM COMMENT='RRD Cleaner File Repository'";
	db_execute($sql);

	/* create fresh plugin_rrclean_action table */
	$sql =     "CREATE TABLE IF NOT EXISTS `plugin_rrdclean_action` (
				`id` 					mediumint(8) 	unsigned NOT NULL auto_increment,
				`name`		varchar(255) 	NOT NULL default '',
				`local_data_id`			mediumint(8) 	unsigned NOT NULL default '0',
				`action`	tinyint(2)		NOT NULL default 0,
				PRIMARY KEY  (`id`)
				) ENGINE=MyISAM COMMENT='RRD Cleaner File Actions'";
	db_execute($sql);

	/* restore original error handler */
 	restore_error_handler();
}

/*
 * PHP Error Handler
 */
function rrdclean_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
	global $debug;
	if ($debug) {
		/* define all error types */
		$errortype = array (
		E_ERROR             => 'Error',
		E_WARNING           => 'Warning',
		E_PARSE             => 'Parsing Error',
		E_NOTICE            => 'Notice',
		E_CORE_ERROR        => 'Core Error',
		E_CORE_WARNING      => 'Core Warning',
		E_COMPILE_ERROR     => 'Compile Error',
		E_COMPILE_WARNING   => 'Compile Warning',
		E_USER_ERROR        => 'User Error',
		E_USER_WARNING      => 'User Warning',
		E_USER_NOTICE       => 'User Notice',
		#			E_STRICT            => 'Runtime Notice',
		#			E_RECOVERABLE_ERROR => 'Catchable Fatal Error'

		);

		/* create an error string for the log */
		$err = "ERRNO:'"  . $errno   . "' TYPE:'"    . $errortype[$errno] .
		"' MESSAGE:'" . $errmsg  . "' IN FILE:'" . $filename .
		"' LINE NO:'" . $linenum . "'";

		/* let's ignore some lesser issues */
		if (substr_count($errmsg, "date_default_timezone"))
			return;
		if (substr_count($errmsg, "Only variables"))
			return;

		/* log the error to the Cacti log */
		#		cacti_log("PROGERR: " . $err, false, "pollperf");
		print ("PROGERR: " . $err . "\n"); # print_r($vars); print("</pre>");
	}

	return;
}

/*
 * Find all unused files from Cacti tables
 * and get file system information for them
 */
function get_files() {
	global $config, $rra_path;

	/* suppress warnings */
	error_reporting(0);

	/* install the rrdclean error handler */
	set_error_handler("rrdclean_error_handler");

	$files_unused = array ();

	/* fetch all files that are not referred by any graph item */
	$result = db_fetch_assoc("SELECT " .
				"data_template_data.data_source_path " .
			"FROM " .
				"data_template_rrd, " .
				"data_template_data, " .
				"graph_templates_item " .
			"WHERE " .
				"graph_templates_item.task_item_id=data_template_rrd.id " .
				"AND data_template_rrd.local_data_id=data_template_data.local_data_id " .
				"AND data_template_data.local_data_id > 0 " .
			"GROUP BY " .
				"data_template_data.data_source_path"
				);

	foreach ($result as $entry) {
		$files_db[] = substr(strchr($entry["data_source_path"], "/"),1);
	}

	if(function_exists('glob')) { //needed because this function is not available on all systems
		chdir($rra_path);
		/* get all rrdfiles in two passes */
		$files_on_hd      = glob("*/*.rrd"); //simply pull all .rrd files out of the directory..
		$files_on_hd      = array_merge($files_on_hd, glob("*.rrd")); //simply pull all .rrd files out of the directory..

		/* remove archive rrdfiles from the mix */
		$files_on_archive = glob("archive/*.rrd");
		$files_on_archive = array_merge($files_on_archive, glob("backup/*.rrd"));

		/* take the difference and now we have all non-arhive rrdfiles */
		$files_on_hd      = array_diff($files_on_hd, $files_on_archive);

		chdir($config["base_path"]);
	} else {
		echo "FATAL: PHP 4.3.0 or greater required";
	}
	$files_unused = array_diff($files_on_hd, $files_db); //.. and run one single diff

	if (!isset ($files_unused))
		$files_unused = "";
	return $files_unused;

	/* restore original error handler */
	restore_error_handler();
}

/*
 * Display all rrd file entries
 */
function list_rrd() {
	global $config, $item_rows, $ds_actions, $rra_path, $colors, $hash_version_codes;

	/* suppress warnings */
	error_reporting(0);

	/* install the rrdclean error handler */
	set_error_handler("rrdclean_error_handler");

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("page"));
	input_validate_input_number(get_request_var_request("rows"));
	/* ==================================================== */

	/* clean up search string */
	if (isset ($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	/* clean up sort_column string */
	if (isset ($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction string */
	if (isset ($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* if the user pushed the 'clear' button */
	if (isset ($_REQUEST["clear_x"])) {
		kill_session_var("sess_rrdclean_current_page");
		kill_session_var("sess_rrdclean_rows");
		kill_session_var("sess_rrdclean_filter");
		kill_session_var("sess_rrdclean_sort_column");
		kill_session_var("sess_rrdclean_sort_direction");

		unset ($_REQUEST["page"]);
		unset ($_REQUEST["rows"]);
		unset ($_REQUEST["filter"]);
		unset ($_REQUEST["sort_column"]);
		unset ($_REQUEST["sort_direction"]);
	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_rrdclean_current_page", "1");
	load_current_session_value("rows", "sess_rrdclean_rows", read_config_option("num_rows_device"));
	load_current_session_value("filter", "sess_rrdclean_filter", "");
	load_current_session_value("sort_column", "sess_rrdclean_sort_column", "name");
	load_current_session_value("sort_direction", "sess_rrdclean_sort_direction", "ASC");

	$width = "100%";
	if (isset ($hash_version_codes[$config["cacti_version"]])) {
		if ($hash_version_codes[$config["cacti_version"]] > 13) {
			$width = "100%";
		}
	}
	html_start_box("<strong>RRD Cleaner</strong>", $width, $colors["header"], "8", "center", "");

	include ($config["base_path"] . "/plugins/rrdclean/inc_rrdclean_filter_table.php");

	html_end_box();

	/* form the 'where' clause for our main sql query */
	$sql_where = "WHERE " .
					"(plugin_rrdclean.name like '%%" . $_REQUEST["filter"] . "%%') OR " .
					"(plugin_rrdclean.name_cache like '%%" . $_REQUEST["filter"] . "%%') OR " .
					"(plugin_rrdclean.data_template_name like '%%" . $_REQUEST["filter"] . "%%')";


	?>
	<script type="text/javascript">
	<!--

	function applyFilterChange(objForm) {
		strURL = '?rows=' + objForm.rows.value;
		document.location = strURL;
	}

	-->
	</script>
	<?php

	html_start_box("", $width, $colors["header"], "3", "center", "");

	$total_rows = db_fetch_cell("SELECT COUNT(plugin_rrdclean.name) FROM plugin_rrdclean $sql_where");

	$total_size = db_fetch_cell("SELECT	ROUND(SUM(plugin_rrdclean.size),2) FROM plugin_rrdclean $sql_where");

	$file_list = db_fetch_assoc("SELECT " .
									"plugin_rrdclean.id, " .
									"plugin_rrdclean.name, " .
									"plugin_rrdclean.last_mod, " .
									"plugin_rrdclean.size, " .
									"plugin_rrdclean.name_cache, " .
									"plugin_rrdclean.local_data_id, " .
									"plugin_rrdclean.data_template_id, " .
									"plugin_rrdclean.data_template_name " .
								"FROM (plugin_rrdclean) " .
									"$sql_where " .
								"ORDER BY " . $_REQUEST['sort_column'] . " " . $_REQUEST['sort_direction'] .
								" LIMIT " . ($_REQUEST["rows"] * ($_REQUEST["page"] - 1)) . "," . $_REQUEST["rows"]);

	/* generate page list */
	$url_page_select = get_page_list($_REQUEST["page"], MAX_DISPLAY_PAGES, $_REQUEST["rows"], $total_rows, "rrdcleaner.php?filter=" . $_REQUEST["filter"]);

	$nav = "<tr bgcolor='#" . $colors["header"] . "'>

	<td colspan='8'>
		<table width='100%' cellspacing='0' cellpadding='0' border='0'>
			<tr>
				<td align='left' class='textHeaderDark'>
						<strong>&lt;&lt; ";
	if ($_REQUEST["page"] > 1) {
		$nav .= "<a class='linkOverDark' href='rrdcleaner.php?filter=" . $_REQUEST["filter"] . "&page=" . ($_REQUEST["page"] - 1) . "'>";
	}
	$nav .= "Previous";
	if ($_REQUEST["page"] > 1) {
		$nav .= "</a>";
	}
	$nav .= "</strong>
				</td>\n
				<td align='center' class='textHeaderDark'>
						Showing Rows " . (($_REQUEST["rows"] * ($_REQUEST["page"] - 1)) + 1) . " to " . ((($total_rows < $_REQUEST["rows"]) || ($total_rows < ($_REQUEST["rows"] * $_REQUEST["page"]))) ? $total_rows : ($_REQUEST["rows"] * $_REQUEST["page"])) . " of $total_rows [$url_page_select]
				</td>\n
				<td align='right' class='textHeaderDark'>
						<strong>";
	if (($_REQUEST["page"] * $_REQUEST["rows"]) < $total_rows) {
		$nav .= "<a class='linkOverDark' href='rrdcleaner.php?filter=" . $_REQUEST["filter"] . "&page=" . ($_REQUEST["page"] + 1) . "'>";
	}
	$nav .= "Next";
	if (($_REQUEST["page"] * $_REQUEST["rows"]) < $total_rows) {
		$nav .= "</a>";
	}
	$nav .= " &gt;&gt;</strong>
				</td>\n
			</tr>
		</table>
	</td>
	</tr>\n";

	print $nav;

	$display_text = array(
		"name"               => array("RRD File Name<br>[" . $rra_path . "]   ", "ASC"),
		"name_cache"         => array("DS Name", "ASC"),
		"local_data_id"      => array("DS ID", "ASC"),
		"data_template_id"   => array("Template ID", "ASC"),
		"data_template_name" => array("Template Name", "ASC"),
		"last_mod"           => array("Last Modified<br>[YYYY-MM-DD HH:MM:SS]", "ASC"),
		"size"               => array("Size<br>[MB]", "ASC")
	);

	html_header_sort_checkbox($display_text, $_REQUEST["sort_column"], $_REQUEST["sort_direction"]);

	$i = 0;
	if (sizeof($file_list) > 0) {
		foreach($file_list as $file) {
			$data_template_name = ((empty($file["data_template_name"])) ? "<em>None</em>" : $file["data_template_name"]);
			form_alternate_row_color($colors["alternate"], $colors["light"], $i, 'line' . $file['id']); $i++;
			form_selectable_cell((($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $file['name']) : $file['name']) . "</a>", $file['id']);
			form_selectable_cell(($file["local_data_id"] != 0) ? "<a class='linkEditMain' href='../../data_sources.php?action=ds_edit&id=" . $file['local_data_id'] . "'>" . (($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", title_trim(htmlentities($file["name_cache"]), read_config_option("max_title_data_source"))) : title_trim(htmlentities($file["name_cache"]), read_config_option("max_title_data_source"))) . "</a>" : $file["name_cache"], $file['id']);
			form_selectable_cell($file['local_data_id'], $file['id']);
			form_selectable_cell($file['data_template_id'], $file['id']);
			form_selectable_cell((($_REQUEST["filter"] != "") ? eregi_replace("(" . preg_quote($_REQUEST["filter"]) . ")", "<span style='background-color: #F8D93D;'>\\1</span>", $file['data_template_name']) : $file['data_template_name']) . "</a>", $file['id']);
			form_selectable_cell($file['last_mod'], $file['id']);
			form_selectable_cell($file['size'], $file['id']);
			form_checkbox_cell($file["id"], $file['id']);
			form_end_row();
			$i++;
		}

		/* put the nav bar on the bottom as well */
		print $nav;
	} else {
		print "<tr><td><em>No unused RRD Files</em></td></tr>\n";
	}
	html_end_box(false);

	rrdcleaner_legend($total_size);

	draw_actions_dropdown($ds_actions);

	print "</form>\n";

	/* restore original error handler */
	restore_error_handler();
}

function rrdcleaner_legend($total_size) {
	global $colors;

	html_start_box("", "100%", $colors["header"], "3", "center", "");
	print "<tr>";
	print "<td><b>Total Size [mb]:</b> " . round($total_size,2) . "</td>";
	print "</tr><tr>";
	print "<td><b>Last Scan:</b> " . rrdcleaner_lastupdate() . "</td>";
	print "</tr>";
	html_end_box(false);
}

/*
 * Read all checked list items and put them into
 * a temporary table for the poller
 */
function do_rrd() {
	global $config, $rra_path, $colors;

	/* suppress warnings */
	error_reporting(0);

	/* install the rrdclean error handler */
	set_error_handler("rrdclean_error_handler");

	while (list ($var, $val) = each($_POST)) {
		if (ereg("^chk_(.*)$", $var, $matches)) {
			/* recreate the file name */
			$unused_file = db_fetch_row("SELECT " .
									"name, " .
									"local_data_id " .
								"FROM " .
									"`plugin_rrdclean` " .
								"WHERE " .
											"id=" . $matches[1]);

			/* add to plugin_rrdclean_action table */
			$sql = "INSERT INTO `plugin_rrdclean_action` VALUES('" .
					""					 			. "', '" .
					$unused_file["name"]			. "', '" .
					$unused_file["local_data_id"]	. "', '" .
					$_POST['drp_action'] 			. "')";
			db_execute($sql);

			/* drop from plugin_rrdclean table */
			$sql = 	"DELETE FROM `plugin_rrdclean` WHERE id =" . $matches[1];
			db_execute($sql);
		}
	}

	/* restore original error handler */
	restore_error_handler();
}

?>
