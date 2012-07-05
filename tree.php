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
include_once('./lib/api_tree.php');
include_once('./lib/tree.php');
include_once('./lib/html_tree.php');

input_validate_input_number(get_request_var('tree_id'));
input_validate_input_number(get_request_var('leaf_id'));
input_validate_input_number(get_request_var_post('graph_tree_id'));
input_validate_input_number(get_request_var_post('parent_item_id'));

/* set default action */
if (!isset($_REQUEST["action"])) { $_REQUEST["action"] = ""; }

switch ($_REQUEST["action"]) {
	case 'save':
		form_save();

		break;
	case 'item_movedown':
		item_movedown();

		header("Location: tree.php?action=edit&id=" . $_GET["tree_id"]);
		break;
	case 'item_moveup':
		item_moveup();

		header("Location: tree.php?action=edit&id=" . $_GET["tree_id"]);
		break;
	case 'item_edit':
		include_once("./include/top_header.php");

		item_edit();

		include_once("./include/bottom_footer.php");
		break;
	case 'item_remove':
		item_remove();

		header("Location: tree.php?action=edit&id=" . $_GET["tree_id"]);
		break;
	case 'remove':
		tree_remove();

		header("Location: tree.php");
		break;
	case 'edit':
		include_once("./include/top_header.php");

		tree_edit();

		include_once("./include/bottom_footer.php");
		break;
	default:
		include_once("./include/top_header.php");

		tree();

		include_once("./include/bottom_footer.php");
		break;
}

/* --------------------------
    The Save Function
   -------------------------- */
function form_save() {

	/* clear graph tree cache on save - affects current user only, other users should see changes in <5 minutes */
	if (isset($_SESSION['dhtml_tree'])) {
		unset($_SESSION['dhtml_tree']);
	}

	if (isset($_POST["save_component_tree"])) {
        /* modify for multi user start */
        if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
            if ($_POST["id"] != $_SESSION["private_tree_id"]) access_denied();
        }
        /* modify for multi user end */
		$save["id"] = $_POST["id"];
		$save["name"] = form_input_validate($_POST["name"], "name", "", false, 3);
		$save["sort_type"] = form_input_validate($_POST["sort_type"], "sort_type", "", true, 3);

		if (!is_error_message()) {
			$tree_id = sql_save($save, "graph_tree");

			if ($tree_id) {
				raise_message(1);

				/* sort the tree using the algorithm chosen by the user */
				sort_tree(SORT_TYPE_TREE, $tree_id, $_POST["sort_type"]);
			}else{
				raise_message(2);
			}
		}

		header("Location: tree.php?action=edit&id=" . (empty($tree_id) ? $_POST["id"] : $tree_id));
	}elseif (isset($_POST["save_component_tree_item"])) {
        /* modify for multi user start */
        if (!empty($_GET["id"])) {
            if (!check_tree_item($_POST["id"])) access_denied();
        }
        /* modify for multi user end */
		$tree_item_id = api_tree_item_save($_POST["id"], $_POST["graph_tree_id"], $_POST["type"], $_POST["parent_item_id"],
			(isset($_POST["title"]) ? $_POST["title"] : ""), (isset($_POST["local_graph_id"]) ? $_POST["local_graph_id"] : "0"),
			(isset($_POST["rra_id"]) ? $_POST["rra_id"] : "0"), (isset($_POST["host_id"]) ? $_POST["host_id"] : "0"),
			(isset($_POST["host_grouping_type"]) ? $_POST["host_grouping_type"] : "1"), (isset($_POST["sort_children_type"]) ? $_POST["sort_children_type"] : "1"),
			(isset($_POST["propagate_changes"]) ? true : false));

		if (is_error_message()) {
			header("Location: tree.php?action=item_edit&tree_item_id=" . (empty($tree_item_id) ? $_POST["id"] : $tree_item_id) . "&tree_id=" . $_POST["graph_tree_id"] . "&parent_id=" . $_POST["parent_item_id"]);
		}else{
            /* modify for multi user start */
            if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
                if (isset($_POST["local_graph_id"]) && $_POST["graph_tree_id"] == $_SESSION["public_tree_id"]) {
                    exec("php ./cli/add_perms.php --user-id=" . $_SESSION["sess_user_id"] . " --item-type=graph --item-id=" . $_POST["local_graph_id"]);
                }
            }
            /* modify for multi user end */
			header("Location: tree.php?action=edit&id=" . $_POST["graph_tree_id"]);
		}
	}
}

/* -----------------------
    Tree Item Functions
   ----------------------- */

function item_edit() {
	global $colors, $tree_sort_types;
	global $tree_item_types, $host_group_types;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("tree_id"));
	/* ==================================================== */

    /* modify for multi user start */
    $public_tree = FALSE;
    if ($_SESSION["permission"] <= ACCESS_ADMINISTRATOR) {
        if ($_GET["tree_id"] == $_SESSION["public_tree_id"]) {
            $public_tree = TRUE;
        } else {
            if (!empty($_GET["id"])) {
                if (!check_tree_item($_GET["id"])) access_denied();
            }
        }
    }
    /* modify for multi user end */
	if (!empty($_GET["id"])) {
		$tree_item = db_fetch_row("select * from graph_tree_items where id=" . get_request_var("id"));

		if ($tree_item["local_graph_id"] > 0) { $db_type = TREE_ITEM_TYPE_GRAPH; }
		if ($tree_item["title"] != "") { $db_type = TREE_ITEM_TYPE_HEADER; }
		if ($tree_item["host_id"] > 0) { $db_type = TREE_ITEM_TYPE_HOST; }
	}

	if (isset($_GET["type_select"])) {
		$current_type = $_GET["type_select"];
	}elseif (isset($db_type)) {
		$current_type = $db_type;
	}else{
		$current_type = TREE_ITEM_TYPE_HEADER;
	}

	$tree_sort_type = db_fetch_cell("select sort_type from graph_tree where id='" . get_request_var("tree_id") . "'");

	print "<form method='post' action='tree.php' name='form_tree'>\n";

	html_start_box("<strong>Tree Items</strong>", "100%", $colors["header"], "3", "center", "");

	form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],0); ?>
		<td width="50%">
			<font class="textEditTitle">Parent Item</font><br>
			Choose the parent for this header/graph.
		</td>
		<td>
			<?php grow_dropdown_tree($_GET["tree_id"], "parent_item_id", (isset($_GET["parent_id"]) ? $_GET["parent_id"] : get_parent_id($tree_item["id"], "graph_tree_items", "graph_tree_id=" . $_GET["tree_id"])));?>
		</td>
	</tr>
	<?php form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],1); ?>
		<td width="50%">
			<font class="textEditTitle">Tree Item Type</font><br>
			Choose what type of tree item this is.
		</td>
		<td>
			<select name="type_select" onChange="window.location=document.form_tree.type_select.options[document.form_tree.type_select.selectedIndex].value">
				<?php
                /* modify for multi user start */
                if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
                    if (!db_fetch_cell("
                        SELECT COUNT(graph_local.id) FROM graph_local
                            INNER JOIN host ON graph_local.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'")) {
                        unset($tree_item_types[2]); // graph

                    }
                    if (!db_fetch_cell("
                        SELECT COUNT(host.id) FROM host
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'")) {
                        unset($tree_item_types[3]); // host
                    }
                    if ($public_tree == TRUE) {
                        unset($tree_item_types[1]); // header
                        unset($tree_item_types[3]); // host
                        $current_type = 2;
                    }
                }
                /* modify for multi user end */
				while (list($var, $val) = each($tree_item_types)) {
					print "<option value='tree.php?action=item_edit" . (isset($_GET["id"]) ? "&id=" . $_GET["id"] : "") . (isset($_GET["parent_id"]) ? "&parent_id=" . $_GET["parent_id"] : "") . "&tree_id=" . $_GET["tree_id"] . "&type_select=$var'"; if ($var == $current_type) { print " selected"; } print ">$val</option>\n";
				}
				?>
			</select>
		</td>
	</tr>
	<tr bgcolor='#<?php print $colors["header_panel"];?>'>
		<td colspan="2" class='textSubHeaderDark'>Tree Item Value</td>
	</tr>
	<?php
	switch ($current_type) {
	case TREE_ITEM_TYPE_HEADER:
		$i = 0;

		/* it's nice to default to the parent sorting style for new items */
		if (empty($_GET["id"])) {
			$default_sorting_type = db_fetch_cell("select sort_children_type from graph_tree_items where id=" . $_GET["parent_id"]);
		}else{
			$default_sorting_type = TREE_ORDERING_NONE;
		}

		form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],$i); $i++; ?>
			<td width="50%">
				<font class="textEditTitle">Title</font><br>
				If this item is a header, enter a title here.
			</td>
			<td>
				<?php form_text_box("title", (isset($tree_item["title"]) ? $tree_item["title"] : ""), "", "255", 30, "text", (isset($_GET["id"]) ? $_GET["id"] : "0"));?>
			</td>
		</tr>
		<?php
		/* don't allow the user to change the tree item ordering if a tree order has been specified */
		if ($tree_sort_type == TREE_ORDERING_NONE) {
			form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],$i); $i++; ?>
				<td width="50%">
					<font class="textEditTitle">Sorting Type</font><br>
					Choose how children of this branch will be sorted.
				</td>
				<td>
					<?php form_dropdown("sort_children_type", $tree_sort_types, "", "", (isset($tree_item["sort_children_type"]) ? $tree_item["sort_children_type"] : $default_sorting_type), "", "");?>
				</td>
			</tr>
			<?php
		}

		if ((!empty($_GET["id"])) && ($tree_sort_type == TREE_ORDERING_NONE)) {
			form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],$i); $i++; ?>
				<td width="50%">
					<font class="textEditTitle">Propagate Changes</font><br>
					Propagate all options on this form (except for 'Title') to all child 'Header' items.
				</td>
				<td>
					<?php form_checkbox("propagate_changes", "", "Propagate Changes", "", "", "", 0);?>
				</td>
			</tr>
			<?php
		}
		break;
	case TREE_ITEM_TYPE_GRAPH:
		form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],0); ?>
			<td width="50%">
				<font class="textEditTitle">Graph</font><br>
				Choose a graph from this list to add it to the tree.
			</td>
			<td>
				<?php 
                /* modify for multi user start */
                if ($_SESSION["permission"] <= ACCESS_ADMINISTRATOR) {
                	form_dropdown("local_graph_id", db_fetch_assoc("
                    	SELECT graph_templates_graph.local_graph_id as id,graph_templates_graph.title_cache as name FROM graph_templates_graph 
                            INNER JOIN graph_local ON graph_templates_graph.local_graph_id = graph_local.id
                            INNER JOIN host ON graph_local.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
                        WHERE graph_templates_graph.local_graph_id != 0 
                        ORDER BY graph_templates_graph.title_cache"), "name", "id", (isset($tree_item["local_graph_id"]) ? $tree_item["local_graph_id"] : ""), "", "");
                } else {
					form_dropdown("local_graph_id", db_fetch_assoc("select graph_templates_graph.local_graph_id as id,graph_templates_graph.title_cache as name from (graph_templates_graph,graph_local) where graph_local.id=graph_templates_graph.local_graph_id and local_graph_id != 0 order by title_cache"), "name", "id", (isset($tree_item["local_graph_id"]) ? $tree_item["local_graph_id"] : ""), "", "");
                }
                /* modify for multi user end */
                ?>
			</td>
		</tr>
		<?php form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],1); ?>
			<td width="50%">
				<font class="textEditTitle">Round Robin Archive</font><br>
				Choose a round robin archive to control how the Graph Thumbnail is displayed when using Tree Export.
			</td>
			<td>
				<?php form_dropdown("rra_id", db_fetch_assoc("select id,name from rra order by timespan"), "name", "id", (isset($tree_item["rra_id"]) ? $tree_item["rra_id"] : ""), "", "");?>
			</td>
		</tr>
		<?php
		break;
	case TREE_ITEM_TYPE_HOST:
		form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],0); ?>
			<td width="50%">
				<font class="textEditTitle">Host</font><br>
				Choose a host here to add it to the tree.
			</td>
			<td>
				<?php 
                /* modify for multi user start */
                if ($_SESSION["permission"] <= ACCESS_ADMINISTRATOR) {
                	form_dropdown("host_id", db_fetch_assoc("
						SELECT host.id,CONCAT_WS('',host.description,' (',host.hostname,')') as name FROM host
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
                        ORDER BY host.description,host.hostname"), "name", "id", (isset($tree_item["host_id"]) ? $tree_item["host_id"] : ""), "", "");
                } else {
                	form_dropdown("host_id", db_fetch_assoc("select id,CONCAT_WS('',description,' (',hostname,')') as name from host order by description,hostname"), "name", "id", (isset($tree_item["host_id"]) ? $tree_item["host_id"] : ""), "", "");
                }
                /* modify for multi user end */
                ?>
			</td>
		</tr>
		<?php form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],1); ?>
			<td width="50%">
				<font class="textEditTitle">Graph Grouping Style</font><br>
				Choose how graphs are grouped when drawn for this particular host on the tree.
			</td>
			<td>
				<?php form_dropdown("host_grouping_type", $host_group_types, "", "", (isset($tree_item["host_grouping_type"]) ? $tree_item["host_grouping_type"] : "1"), "", "");?>
			</td>
		</tr>
		<?php form_alternate_row_color($colors["form_alternate1"],$colors["form_alternate2"],1); ?>
			<td width="50%">
				<font class="textEditTitle">Round Robin Archive</font><br>
				Choose a round robin archive to control how Graph Thumbnails are displayed when using Tree Export.
			</td>
			<td>
				<?php form_dropdown("rra_id", db_fetch_assoc("select id,name from rra order by timespan"), "name", "id", (isset($tree_item["rra_id"]) ? $tree_item["rra_id"] : ""), "", "");?>
			</td>
		</tr>
		<?php
		break;
	}
	?>
	</tr>
	<?php

	form_hidden_box("id", (isset($_GET["id"]) ? $_GET["id"] : "0"), "");
	form_hidden_box("graph_tree_id", $_GET["tree_id"], "");
	form_hidden_box("type", $current_type, "");
	form_hidden_box("save_component_tree_item", "1", "");

	html_end_box();

	form_save_button("tree.php?action=edit&id=" . $_GET["tree_id"]);
}

function item_moveup() {
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("tree_id"));
	/* ==================================================== */

    /* modify for multi user start */
    if (!check_tree_item($_GET["id"])) access_denied();
    /* modify for multi user end */
	$order_key = db_fetch_cell("SELECT order_key FROM graph_tree_items WHERE id=" . $_GET["id"]);
	if ($order_key > 0) { branch_up($order_key, 'graph_tree_items', 'order_key', 'graph_tree_id=' . $_GET["tree_id"]); }

	/* clear graph tree cache on save - affects current user only, other users should see changes in <5 minutes */
	if (isset($_SESSION['dhtml_tree'])) {
		unset($_SESSION['dhtml_tree']);
	}

}

function item_movedown() {
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("tree_id"));
	/* ==================================================== */

    /* modify for multi user start */
    if (!check_tree_item($_GET["id"])) access_denied();
    /* modify for multi user end */
	$order_key = db_fetch_cell("SELECT order_key FROM graph_tree_items WHERE id=" . $_GET["id"]);
	if ($order_key > 0) { branch_down($order_key, 'graph_tree_items', 'order_key', 'graph_tree_id=' . $_GET["tree_id"]); }

	/* clear graph tree cache on save - affects current user only, other users should see changes in <5 minutes */
	if (isset($_SESSION['dhtml_tree'])) {
		unset($_SESSION['dhtml_tree']);
	}

}

function item_remove() {
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	input_validate_input_number(get_request_var("tree_id"));
	/* ==================================================== */

    /* modify for multi user start */
    if (!check_tree_item($_GET["id"])) access_denied();
    /* modify for multi user end */
	if ((read_config_option("deletion_verification") == "on") && (!isset($_GET["confirm"]))) {
		$graph_tree_item = db_fetch_row("select title,local_graph_id,host_id from graph_tree_items where id=" . $_GET["id"]);

		if (!empty($graph_tree_item["local_graph_id"])) {
			$text = "Are you sure you want to delete the graph item <strong>'" . db_fetch_cell("select title_cache from graph_templates_graph where local_graph_id=" . $graph_tree_item["local_graph_id"]) . "'</strong>?";
		}elseif ($graph_tree_item["title"] != "") {
			$text = "Are you sure you want to delete the header item <strong>'" . $graph_tree_item["title"] . "'</strong>?";
		}elseif (!empty($graph_tree_item["host_id"])) {
			$text = "Are you sure you want to delete the host item <strong>'" . db_fetch_cell("select CONCAT_WS('',description,' (',hostname,')') as hostname from host where id=" . $graph_tree_item["host_id"]) . "'</strong>?";
		}

		include("./include/top_header.php");
		form_confirm("Are You Sure?", $text, htmlspecialchars("tree.php?action=edit&id=" . $_GET["tree_id"]), htmlspecialchars("tree.php?action=item_remove&id=" . $_GET["id"] . "&tree_id=" . $_GET["tree_id"]));
		include("./include/bottom_footer.php");
		exit;
	}

	if ((read_config_option("deletion_verification") == "") || (isset($_GET["confirm"]))) {
        /* modify for multi user start */
        if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
            $tree_item = db_fetch_row("SELECT graph_tree_id,local_graph_id,title,host_id,order_key FROM graph_tree_items WHERE id = '" . $_GET["id"]. "'");
            // public graph
            if ($tree_item["graph_tree_id"] == $_SESSION["public_tree_id"] && $tree_item["local_graph_id"] > 0) {
                db_execute("DELETE FROM user_auth_perms WHERE user_id = '" . $_SESSION["sess_user_id"] . "' AND item_id = '" . $tree_item["local_graph_id"] . "' AND type = '1'");
                // remove all reference favorites graph
                $rows = db_fetch_assoc("SELECT graph_tree_items.id FROM graph_tree_items WHERE graph_tree_id != '" . $_SESSION["private_tree_id"] . "' AND local_graph_id = '" . $tree_item["local_graph_id"] . "'");
                foreach ($rows as $row) {
                    delete_branch($row["id"]);
                }
            // private device (re-entry host tree_item)
            } elseif ($tree_item["graph_tree_id"] == $_SESSION["private_tree_id"] && $tree_item["host_id"] > 0) {
                exec("php ./cli/add_tree.php --type=node --node-type=host --tree-id=" . $_SESSION["private_tree_id"] . " --host-id=" . $tree_item["host_id"] . " --host-group-style=1");
            // private header (re-entry host tree_item)
            } elseif ($tree_item["graph_tree_id"] == $_SESSION["private_tree_id"] && $tree_item["title"] != "") {
                $tier = tree_tier($tree_item["order_key"]);
                $order_key = substr($tree_item["order_key"], 0, ($tier*CHARS_PER_TIER));
                $rows = db_fetch_assoc("SELECT host_id FROM graph_tree_items WHERE graph_tree_id = '" . $_SESSION["private_tree_id"] . "' AND host_id > 0 AND order_key LIKE '" . $order_key . "%'");
                foreach ($rows as $row) {
                    exec("php ./cli/add_tree.php --type=node --node-type=host --tree-id=" . $_SESSION["private_tree_id"] . " --host-id=" . $row["host_id"] . " --host-group-style=1");
                }
            }
        }
        /* modify for multi user end */
		delete_branch($_GET["id"]);
	}

	/* clear graph tree cache on save - affects current user only, other users should see changes in <5 minutes */
	if (isset($_SESSION['dhtml_tree'])) {
		unset($_SESSION['dhtml_tree']);
	}

	header("Location: tree.php?action=edit&id=" . $_GET["tree_id"]); exit;
}


/* ---------------------
    Tree Functions
   --------------------- */

function tree_remove() {
	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

    /* modify for multi user start */
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) access_denied();
    /* modify for multi user end */
	if ((read_config_option("deletion_verification") == "on") && (!isset($_GET["confirm"]))) {
		include("./include/top_header.php");
		form_confirm("Are You Sure?", "Are you sure you want to delete the tree <strong>'" . db_fetch_cell("select name from graph_tree where id=" . $_GET["id"]) . "'</strong>?", htmlspecialchars("tree.php"), htmlspecialchars("tree.php?action=remove&id=" . $_GET["id"]));
		include("./include/bottom_footer.php");
		exit;
	}

	if ((read_config_option("deletion_verification") == "") || (isset($_GET["confirm"]))) {
		db_execute("delete from graph_tree where id=" . $_GET["id"]);
		db_execute("delete from graph_tree_items where graph_tree_id=" . $_GET["id"]);
	}

	/* clear graph tree cache on save - affects current user only, other users should see changes in <5 minutes */
	if (isset($_SESSION['dhtml_tree'])) {
		unset($_SESSION['dhtml_tree']);
	}

}

function tree_edit() {
	global $colors, $fields_tree_edit;

	/* ================= input validation ================= */
	input_validate_input_number(get_request_var("id"));
	/* ==================================================== */

	/* clean up subaction */
	if (isset($_REQUEST["subaction"])) {
		$_REQUEST["subaction"] = sanitize_search_string(get_request_var("subaction"));
	}

    /* modify for multi user start */
    $public_tree = FALSE;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        if ($_GET["id"] == $_SESSION["public_tree_id"]) {
            $public_tree = TRUE;
            $fields_tree_edit["sort_type"]["method"] = "hidden";
        } else {
            $_GET["id"] = $_SESSION["private_tree_id"];
        }
        $tree = db_fetch_row("select * from graph_tree where id=" . $_GET["id"]);
        $header_label = "[edit: " . htmlspecialchars($tree["name"]) . "]";

        $fields_tree_edit["name"]["method"] = "";
    } else {
	if (!empty($_GET["id"])) {
		$tree = db_fetch_row("select * from graph_tree where id=" . $_GET["id"]);
		$header_label = "[edit: " . htmlspecialchars($tree["name"]) . "]";
	}else{
		$header_label = "[new]";
	}
    }
    /* modify for multi user end */

	html_start_box("<strong>Graph Trees</strong> $header_label", "100%", $colors["header"], "3", "center", "");

	draw_edit_form(array(
		"config" => array(),
		"fields" => inject_form_variables($fields_tree_edit, (isset($tree) ? $tree : array()))
		));

	html_end_box();

	if (!empty($_GET["id"])) {
        /* modify for multi user start */
        if ((check_resource_count(RESOURCE_GRAPH,TRUE) == TRUE && $_GET["id"] == $_SESSION["private_tree_id"]) || $_SESSION["permission"] == ACCESS_ADMINISTRATOR) {
		html_start_box("<strong>Tree Items</strong>", "100%", $colors["header"], "3", "center", "tree.php?action=item_edit&tree_id=" . htmlspecialchars($tree["id"]) . "&parent_id=0");
        } else {
            html_start_box("<strong>Tree Items</strong>", "100%", $colors["header"], "3", "center", "");
        }
        /* modify for multi user end */
		?>
		<td>
		<input type='button' onClick='return document.location="tree.php?action=edit&id=<?php print htmlspecialchars(get_request_var("id"));?>&subaction=expand_all"' value='Expand All' title='Expand All Trees'>
		<input type='button' onClick='return document.location="tree.php?action=edit&id=<?php print htmlspecialchars(get_request_var("id"));?>&subaction=collapse_all"' value='Collapse All' title='Collapse All Trees'></a>
		</td>
		<?php

		print "<tr bgcolor='#" . $colors["header_panel"] . "'>";
			DrawMatrixHeaderItem("Item",$colors["header_text"],1);
			DrawMatrixHeaderItem("Value",$colors["header_text"],1);
			DrawMatrixHeaderItem("&nbsp;",$colors["header_text"],2);
		print "</tr>";

		grow_edit_graph_tree($_GET["id"], "", "");
		html_end_box();
	}

    /* modify for multi user start */
    if ($_SESSION["permission"] == ACCESS_ADMINISTRATOR || $public_tree == FALSE) {
	form_save_button("tree.php", "return");
    }
    /* modify for multi user end */
}

function tree() {
	global $colors;

    /* modify for multi user start */
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        html_start_box("<strong>Graph Trees</strong>", "100%", $colors["header"], "3", "center", "");
    } else {
	html_start_box("<strong>Graph Trees</strong>", "100%", $colors["header"], "3", "center", "tree.php?action=edit");
    }
    /* modify for multi user end */

	print "<tr bgcolor='#" . $colors["header_panel"] . "'>";
		DrawMatrixHeaderItem("Name",$colors["header_text"],1);
		DrawMatrixHeaderItem("&nbsp;",$colors["header_text"],1);
	print "</tr>";

    /* modify for multi user start */
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        $trees = db_fetch_assoc("SELECT * FROM graph_tree WHERE id = '" . $_SESSION["public_tree_id"] . "' OR id = '" . $_SESSION["private_tree_id"] . "' ORDER BY name");
    } else {
		$tree_id = db_fetch_cell("
            SELECT graph_tree.id FROM graph_tree 
	            INNER JOIN user_auth_perms ON graph_tree.id = user_auth_perms.item_id AND user_auth_perms.type = '2'
	            INNER JOIN user_auth ON user_auth_perms.user_id = user_auth.id AND user_auth.username = '" . $_POST["user_name"] . "'
	        WHERE graph_tree.id != '" . $_SESSION["public_tree_id"] . "'");
        $trees = db_fetch_assoc("SELECT * FROM graph_tree WHERE id = '" . $_SESSION["public_tree_id"] . "' OR id = '" . $_SESSION["private_tree_id"] . "' OR id = '$tree_id' ORDER BY name");
    }
    /* modify for multi user end */

	$i = 0;
	if (sizeof($trees) > 0) {
	foreach ($trees as $tree) {
		form_alternate_row_color($colors["alternate"],$colors["light"],$i); $i++;
			?>
			<td>
				<a class="linkEditMain" href="<?php print htmlspecialchars("tree.php?action=edit&id=" . $tree["id"]);?>"><?php print htmlspecialchars($tree["name"]);?></a>
			</td>
			<td align="right">
                <?php /* modify for multi user end */ if ($_SESSION["permission"] == ACCESS_ADMINISTRATOR) { ?>
				<a href="<?php print htmlspecialchars("tree.php?action=remove&id=" . $tree["id"]);?>"><img src="images/delete_icon.gif" style="height:10px;width:10px;" border="0" alt="Delete"></a>
                <?php } /* modify for multi user end */?>
			</td>
		</tr>
	<?php
	}
    /* modify for multi user start */
    if ($_SESSION["permission"] == ACCESS_ADMINISTRATOR) {
        form_alternate_row_color($colors["alternate"],$colors["light"],$i); $i++;
        print "<td><form method='post' autocomplete='off' action='tree.php'><input id='user_name' name='user_name' type ='text' size='8'><input type='submit' value='SEARCH' title='SEARCH'></form></td><td align='right'></td></tr>";
    }
    /* modify for multi user end */
	}else{
		print "<tr><td><em>No Graphs Trees</em></td></tr>\n";
	}
	html_end_box();
}
 ?>
