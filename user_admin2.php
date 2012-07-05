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

include("./include/auth.php");

define("MAX_DISPLAY_PAGES", 21);

/* set default action */
if (!isset($_REQUEST["action"])) { $_REQUEST["action"] = ""; }

switch ($_REQUEST["action"]) {
	default:
		include_once("./include/top_header.php");
		lists();
		include_once("./include/bottom_footer.php");
		break;
}

function lists() {
	global $colors, $actions;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var_request("page"));
	/* ==================================================== */

	/* clean up search string */
	if (isset($_REQUEST["filter"])) {
		$_REQUEST["filter"] = sanitize_search_string(get_request_var("filter"));
	}

	/* clean up sort_column string */
	if (isset($_REQUEST["sort_column"])) {
		$_REQUEST["sort_column"] = sanitize_search_string(get_request_var("sort_column"));
	}

	/* clean up sort_direction string */
	if (isset($_REQUEST["sort_direction"])) {
		$_REQUEST["sort_direction"] = sanitize_search_string(get_request_var("sort_direction"));
	}

	/* if the user pushed the 'clear' button */
	if (isset($_REQUEST["clear"])) {
		kill_session_var("sess_lists_current_page");
		kill_session_var("sess_lists_filter");
		kill_session_var("sess_lists_sort_column");
		kill_session_var("sess_lists_sort_direction");

		unset($_REQUEST["page"]);
		unset($_REQUEST["filter"]);
		unset($_REQUEST["sort_column"]);
		unset($_REQUEST["sort_direction"]);

	}

	/* remember these search fields in session vars so we don't have to keep passing them around */
	load_current_session_value("page", "sess_lists_current_page", "1");
	load_current_session_value("filter", "sess_lists_filter", "");
	load_current_session_value("sort_column", "sess_lists_sort_column", "name");
	load_current_session_value("sort_direction", "sess_lists_sort_direction", "ASC");

    html_start_box("<strong>User Management2</strong>", "100%", $colors["header"], "3", "center", "");

    ?>
    <tr bgcolor="#<?php print $colors["panel"];?>">
        <td>
        <form name="lists" action="user_admin2.php">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td nowrap style='white-space: nowrap;' width="50">
                        Search:&nbsp;
                    </td>
                    <td width="1">
                        <input type="text" name="filter" size="40" value="<?php print htmlspecialchars(get_request_var_request("filter"));?>">
                    </td>
                    <td nowrap style='white-space: nowrap;'>
                        &nbsp;<input type="submit" value="Go" title="Set/Refresh Filters">
                        <input type="submit" name="clear" value="Clear" title="Clear Filters">
                    </td>
                </tr>
            </table>
            <input type='hidden' name='page' value='1'>
        </form>
        </td>
    </tr>
    <?php
    
	html_end_box();

	/* form the 'where' clause for our main sql query */
	if (strlen($_REQUEST['filter'])) {
		$sql_where = "WHERE (user_auth.username LIKE '%%" . get_request_var_request("filter") . "%%')";
	} else {
		$sql_where = '';
	}

	/* print checkbox form for validation */
	print "<form name='chk' method='post' action='user_admin2.php?action=edit&tab=tholds&id=" . get_request_var_request("id") . "'>\n";

	html_start_box("", "100%", $colors["header"], "3", "center", "");

	$lists = db_fetch_assoc("
        SELECT 
            user_auth.id AS id, 
            user_auth.username AS name, 
            user_auth.enabled AS enabled, 
            uar.realm AS realm, 
            ul.time AS last_login, 
            ul.ip AS ip, 
            ul.success AS success, 
            ul.failed AS failed, 
            COUNT(DISTINCT(host.id)) AS host, 
            COUNT(DISTINCT(graph_local.id)) AS graph, 
            COUNT(DISTINCT(data_local.id)) AS data, 
            COUNT(DISTINCT(thold_data.id)) AS thold, 
            TRUNCATE(pt.cur_time,2) AS proc_time, 
            SUM(DISTINCT(host.total_polls)) AS total_polls, 
            SUM(DISTINCT(graph_access_counter.count)) AS graph_acs,
            SUM(DISTINCT(gti.count)) AS graph_sum
        FROM (user_auth,host) 
            LEFT JOIN graph_local ON host.id = graph_local.host_id 
            LEFT JOIN data_local ON host.id = data_local.host_id 
            LEFT JOIN thold_data ON host.id = thold_data.host_id 
            INNER JOIN user_auth_perms ON user_auth.id = user_auth_perms.user_id AND host.id = user_auth_perms.item_id AND user_auth_perms.type = '3'
            LEFT JOIN (SELECT user_auth_realm.user_id AS user_id, SUM(DISTINCT(user_auth_realm.realm_id)) AS realm FROM user_auth_realm GROUP BY user_auth_realm.user_id) AS uar ON user_auth.id = uar.user_id 
            LEFT JOIN (SELECT user_log.username AS username, MAX(user_log.time) AS time,COUNT(DISTINCT(user_log.ip)) AS ip,SUM(CASE WHEN result = '1' THEN 1 END) AS success,SUM(CASE WHEN result = '0' THEN 1 END) AS failed FROM user_log GROUP BY user_log.username) AS ul ON user_auth.username = ul.username 
            LEFT JOIN (SELECT user_id, SUM(processed_time.cur_time) AS cur_time FROM processed_time WHERE time > subdate(NOW(), interval 1 hour) AND disabled = '' AND cur_time > 0 GROUP BY user_id) AS pt ON user_auth.id = pt.user_id 
            LEFT JOIN graph_access_counter ON graph_local.id = graph_access_counter.local_graph_id 
            LEFT JOIN (SELECT local_graph_id ,COUNT(local_graph_id) AS count FROM graph_tree_items WHERE local_graph_id != '0' GROUP BY local_graph_id) AS gti ON graph_local.id = gti.local_graph_id
            $sql_where
        GROUP BY user_auth.id
		ORDER BY " . get_request_var_request("sort_column") . " " . get_request_var_request("sort_direction") .
		" LIMIT " . (read_config_option("num_rows_device")*(get_request_var_request("page")-1)) . "," . read_config_option("num_rows_device"));
    $total_rows = count($lists);

	if ($total_rows > 0) {
		/* generate page list */
		$url_page_select = get_page_list(get_request_var_request("page"), MAX_DISPLAY_PAGES, read_config_option("num_rows_device"), $total_rows, "user_admin2.php?filter=" . get_request_var_request("filter"));

		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='left' class='textHeaderDark'>
							<strong>&lt;&lt; "; if (get_request_var_request("page") > 1) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("user_admin2.php?filter=" . get_request_var_request("filter") . "&page=" . (get_request_var_request("page")-1)) . "'>"; } $nav .= "Previous"; if (get_request_var_request("page") > 1) { $nav .= "</a>"; } $nav .= "</strong>
						</td>\n
						<td align='center' class='textHeaderDark'>
							Showing Rows " . ((read_config_option("num_rows_device")*(get_request_var_request("page")-1))+1) . " to " . ((($total_rows < read_config_option("num_rows_device")) || ($total_rows < (read_config_option("num_rows_device")*get_request_var_request("page")))) ? $total_rows : (read_config_option("num_rows_device")*get_request_var_request("page"))) . " of $total_rows [$url_page_select]
						</td>\n
						<td align='right' class='textHeaderDark'>
							<strong>"; if ((get_request_var_request("page") * read_config_option("num_rows_device")) < $total_rows) { $nav .= "<a class='linkOverDark' href='" . htmlspecialchars("user_admin2.php?filter=" . get_request_var_request("filter") . "&page=" . (get_request_var_request("page")+1)) . "'>"; } $nav .= "Next"; if ((get_request_var_request("page") * read_config_option("num_rows_device")) < $total_rows) { $nav .= "</a>"; } $nav .= " &gt;&gt;</strong>
						</td>\n
					</tr>
				</table>
			</td>
			</tr>\n";
	} else {
		$nav = "<tr bgcolor='#" . $colors["header"] . "'>
			<td colspan='16'>
				<table width='100%' cellspacing='0' cellpadding='0' border='0'>
					<tr>
						<td align='center' class='textHeaderDark'>
							No Rows Found
						</td>\n
					</tr>
				</table>
			</td>
			</tr>\n";
	}

	print $nav;

	$display_text = array(
		"name" => array("User Name", "ASC"),
		"enabled" => array("Enabled", "ASC"),
		"realm" => array("Realm", "ASC"),
		"last_login" => array("Last Login", "ASC"),
		"ip" => array("IP", "ASC"),
		"success" => array("Success", "ASC"),
		"failed" => array("Failed", "ASC"),
		"host" => array("Host", "ASC"),
		"graph" => array("Graph", "ASC"),
		"data" => array("Data", "ASC"),
		"thold" => array("Thold", "ASC"),
        "proc_time" => array("ProcTime", "ASC"),
        "total_polls" => array("TotalPolls", "ASC"),
        "graph_acs" => array("GraphAcs", "ASC"),
        "graph_sum" => array("GraphSum", "ASC")
        );

	html_header_sort_checkbox($display_text, get_request_var_request("sort_column"), get_request_var_request("sort_direction"), false);

	$i = 0;
	if (sizeof($lists)) {
		foreach ($lists as $item) {
			form_alternate_row_color($colors["alternate"], $colors["light"], $i, 'line' . $item["id"]);$i++;
			form_selectable_cell("<a class='linkEditMain' href='" . htmlspecialchars("user_admin.php?action=user_edit&tab=user_realms_edit&id=" . $item["id"]) . "'>" . (strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", htmlspecialchars($item["name"])) : htmlspecialchars($item["name"])) . "</a>", $item["id"], "25%");
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["enabled"]) : $item["enabled"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["realm"]) : $item["realm"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["last_login"]) : $item["last_login"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["ip"]) : $item["ip"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["success"]) : $item["success"]) . "</a>", $item["success"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["failed"]) : $item["failed"]) . "</a>", $item["failed"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["host"]) : $item["host"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["graph"]) : $item["graph"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["data"]) : $item["data"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["thold"]) : $item["thold"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["proc_time"]) : $item["proc_time"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["total_polls"]) : $item["total_polls"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["graph_acs"]) : $item["graph_acs"]) . "</a>", $item["id"]);
			form_selectable_cell((strlen(get_request_var_request("filter")) ? preg_replace("/(" . preg_quote(get_request_var_request("filter")) . ")/i", "<span style='background-color: #F8D93D;'>\\1</span>", $item["graph_sum"]) : $item["graph_sum"]) . "</a>", $item["id"]);
            form_checkbox_cell($item["name"], $item["id"]);
			form_end_row();
		}
		print $nav;
	} else {
		print "<tr><td><em>No User Lists</em></td></tr>\n";
	}
	html_end_box(false);
    
	print "</form>\n";
}

?>

