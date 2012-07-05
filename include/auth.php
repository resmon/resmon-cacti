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

include("./include/global.php");

/* check to see if this is a new installation */
if (db_fetch_cell("select cacti from version") != $config["cacti_version"]) {
	header ("Location: " . $config['url_path'] . "install/");
	exit;
}

if (read_config_option("auth_method") != 0) {
	/* handle alternate authentication realms */
	api_plugin_hook_function('auth_alternate_realms');

	/* handle change password dialog */
	if ((isset($_SESSION['sess_change_password'])) && (read_config_option("webbasic_enabled") != "on")) {
		header ("Location: " . $config['url_path'] . "auth_changepassword.php?ref=" . (isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : "index.php"));
		exit;
	}

    /* modify for multi user start */
    if (empty($_COOKIE['stay_login'])) {
	/* don't even bother with the guest code if we're already logged in */
	if ((isset($guest_account)) && (empty($_SESSION["sess_user_id"]))) {
		$guest_user_id = db_fetch_cell("select id from user_auth where username='" . read_config_option("guest_user") . "' and realm = 0 and enabled = 'on'");

		/* cannot find guest user */
		if (!empty($guest_user_id)) {
			$_SESSION["sess_user_id"] = $guest_user_id;
		}
	}
    }
    /* modify for multi user end */

	/* if we are a guest user in a non-guest area, wipe credentials */
	if (!empty($_SESSION["sess_user_id"])) {
		if ((!isset($guest_account)) && (db_fetch_cell("select id from user_auth where username='" . read_config_option("guest_user") . "'") == $_SESSION["sess_user_id"])) {
			kill_session_var("sess_user_id");
		}
	}

	if (empty($_SESSION["sess_user_id"])) {
		include("./auth_login.php");
		exit;
	}elseif (!empty($_SESSION["sess_user_id"])) {
		$realm_id = 0;

		if (isset($user_auth_realm_filenames{basename($_SERVER["PHP_SELF"])})) {
			$realm_id = $user_auth_realm_filenames{basename($_SERVER["PHP_SELF"])};
		}

		/* modify for multi user start */
        define("ACCESS_ADMINISTRATOR", 100);
        define("ACCESS_PREMIUM_USER", 10);
        define("ACCESS_NORMAL_USER", 1);
		set_permission(&$realm_id);
        /* modify for multi user end */
		if ($realm_id != -1 && ((!db_fetch_assoc("select
			user_auth_realm.realm_id
			from
			user_auth_realm
			where user_auth_realm.user_id='" . $_SESSION["sess_user_id"] . "'
			and user_auth_realm.realm_id='$realm_id'")) || (empty($realm_id)))) {
			/* modify for multi user start */
			access_denied();
			/* modify for multi user end */
		}
	}
}

function access_denied() {
    // plugin path
    if (empty($config['url_path'])) {
        $config['url_path'] = str_repeat("../", substr_count($_SERVER["DOCUMENT_URI"], "/") - 1);
    }

			if (isset($_SERVER["HTTP_REFERER"])) {
				$goBack = "<td class='textArea' colspan='2' align='center'>( <a href='" . htmlspecialchars($_SERVER["HTTP_REFERER"]) . "'>Return</a> | <a href='" . $config['url_path'] . "logout.php'>Login Again</a> )</td>";
			}else{
				$goBack = "<td class='textArea' colspan='2' align='center'>( <a href='" . $config['url_path'] . "logout.php'>Login Again</a> )</td>";
			}

			?>
			<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
			<html>
			<head>
				<title>Cacti</title>
				<meta http-equiv="Content-Type" content="text/html;charset=utf-8">
				<link href="<?php echo $config['url_path']; ?>include/main.css" type="text/css" rel="stylesheet">
			</head>
			<body>
			<br><br>

			<table width="450" align='center'>
				<tr>
					<td colspan='2'><img src='<?php echo $config['url_path']; ?>images/auth_deny.gif' border='0' alt='Access Denied'></td>
				</tr>
				<tr style='height:10px;'><td></td></tr>
				<tr>
					<td class='textArea' colspan='2'>You are not permitted to access this section of Cacti. If you feel that you
					need access to this particular section, please contact the Cacti administrator.</td>
				</tr>
				<tr>
					<?php print $goBack;?>
				</tr>
			</table>

			</body>
			</html>
			<?php
			exit;
}

/* modify for multi user start */
function set_permission($realm_id) {
    // user access permission & tree_id
    if (empty($_SESSION["permission"])) {
        // administrator
        if (db_fetch_cell("
            SELECT user_auth_realm.realm_id FROM user_auth_realm
            WHERE user_auth_realm.user_id = '" . $_SESSION["sess_user_id"] . "' AND user_auth_realm.realm_id = '1'")) {
            $_SESSION["permission"] = ACCESS_ADMINISTRATOR;
        // premium user
        } elseif (db_fetch_cell("
            SELECT user_auth_realm.realm_id FROM user_auth_realm 
                INNER JOIN plugin_realms ON user_auth_realm.realm_id = (plugin_realms.id  + 100) AND plugin_realms.plugin = 'thold'
            WHERE user_auth_realm.user_id = '" . $_SESSION["sess_user_id"] . "'")){
            $_SESSION["permission"] = ACCESS_PREMIUM_USER;
        // normal user
        } else {
            $_SESSION["permission"] = ACCESS_NORMAL_USER;
        }
        // graph_tree_id
        $_SESSION["public_tree_id"] = 2;
        $_SESSION["private_tree_id"] = db_fetch_cell("
            SELECT graph_tree.id FROM graph_tree 
                INNER JOIN user_auth_perms ON graph_tree.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] . "' AND user_auth_perms.type = '2'
            WHERE graph_tree.id != '" . $_SESSION["public_tree_id"] . "'");
    }
    // file access permission
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        $files = array("color.php","gprint_presets.php");
        if (in_array(basename($_SERVER["PHP_SELF"]), $files)) {
            $realm_id = 0;
        }
        $files = array("gi.php");
        if (in_array(basename($_SERVER["PHP_SELF"]), $files)) {
            $realm_id = -1;
        }
	} else {
        $files = array("user_admin2.php");
        if (in_array(basename($_SERVER["PHP_SELF"]), $files)) {
            $realm_id = -1;
        }
    }
}

define("TREE_ITEM_PUBLIC" , 4);
define("TREE_ITEM_OTHER"  , 2);
define("TREE_ITEM_PRIVATE", 1);
define("TREE_ITEM_NG"     , 0);
function check_tree_item($tree_item_id) {
    $permission = TREE_ITEM_NG;
    if ($_SESSION["permission"] <= ACCESS_ADMINISTRATOR) {
        input_validate_input_number($tree_item_id);
        $tree_item = db_fetch_row("SELECT graph_tree_id,local_graph_id,title,host_id,order_key FROM graph_tree_items WHERE id = '" . $tree_item_id . "'");
        if (isset($tree_item)) {
            if ($tree_item["graph_tree_id"] == $_SESSION["public_tree_id"]) {
                // public graph
                if ($tree_item["local_graph_id"] > 0) {
                    if (db_fetch_cell("
                        SELECT graph_local.id FROM graph_local
                            INNER JOIN host ON graph_local.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
                        WHERE graph_local.id = '" . $tree_item["local_graph_id"] ."'")) {
                        $permission = TREE_ITEM_PUBLIC;
                    }
                // header
                } elseif ($tree_item["title"] != "") {
                    $tier = tree_tier($tree_item["order_key"]);
                    $order_key = substr($tree_item["order_key"], 0, ($tier*CHARS_PER_TIER));
                    if (db_fetch_cell("SELECT COUNT(id) FROM graph_tree_items WHERE graph_tree_id = '" . $_SESSION["public_tree_id"] . "' AND local_graph_id > 0 AND order_key LIKE '" . $order_key . "%'")) {
                        $permission = TREE_ITEM_PUBLIC;
                    }
                }
            } elseif ($tree_item["graph_tree_id"] == $_SESSION["private_tree_id"]) {
                $permission = TREE_ITEM_PRIVATE;
                if ($tree_item["local_graph_id"] > 0) {
                    // other graph
                    if (!db_fetch_cell("
                        SELECT graph_local.id FROM graph_local
                            INNER JOIN host ON graph_local.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
                        WHERE graph_local.id = '" . $tree_item["local_graph_id"] ."'")) {
                        $permission = TREE_ITEM_OTHER;
                    }
                // favorites header
                } elseif ($tree_item["title"] === "Favorites") {
                    $permission = TREE_ITEM_PUBLIC;
                }
            }
        }
    } else {
        $permission = TREE_ITEM_PRIVATE;
    }
    return $permission;
}

function check_host($host_id) {
    $permission = FALSE;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        input_validate_input_number($host_id);
        if (db_fetch_cell("SELECT item_id FROM user_auth_perms WHERE user_id = '" . $_SESSION["sess_user_id"] ."' AND item_id = '" . $host_id . "' AND type = '3'")) {
            $permission = TRUE;
        }
    } else {
        $permission = TRUE;
    }
    return $permission;
}

define("GRAPH_PUBLIC" , 4);
define("GRAPH_OTHER"  , 2);
define("GRAPH_PRIVATE", 1);
define("GRAPH_NG"     , 0);
function check_graph($graph_local_id) {
    $permission = GRAPH_NG;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        // private
        if (db_fetch_cell("
            SELECT graph_local.id FROM graph_local 
                INNER JOIN host ON graph_local.host_id = host.id
                INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
            WHERE graph_local.id = '" . $graph_local_id . "'")) {
            $permission = GRAPH_PRIVATE;
        // other graph by add by [add_tree.php] cli ... user resource, favorites
        } elseif (db_fetch_cell("SELECT id FROM graph_tree_items WHERE local_graph_id = '" . $graph_local_id . "' AND graph_tree_id = '" . $_SESSION["private_tree_id"] . "'")) {
            if (!db_fetch_cell("
                SELECT graph_local.id FROM graph_local
                    INNER JOIN host ON graph_local.host_id = host.id
                    INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
                WHERE graph_local.id = '" . $tree_item["local_graph_id"] ."'")) {
                $permission = GRAPH_OTHER;
            }
        }
        // public
        if (db_fetch_cell("SELECT item_id FROM user_auth_perms WHERE item_id = '$graph_local_id' AND type = '1'")) {
            $permission += GRAPH_PUBLIC;
        }
    } else {
        $permission = GRAPH_PRIVATE;
    }
    return $permission;
}

function check_data($data_local_id) {
    $permission = FALSE;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        if (db_fetch_cell("
            SELECT data_local.id FROM data_local
                INNER JOIN host ON data_local.host_id = host.id
                INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
            WHERE data_local.id='" . $data_local_id . "'")) {
            $permission = TRUE;
        }
    } else {
        $permission = TRUE;
    }
    return $permission;
}

function check_thold($thold_data_id) {
    $permission = FALSE;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        if (db_fetch_cell("
            SELECT thold_data.id FROM thold_data
                INNER JOIN user_auth_perms ON thold_data.host_id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'
            WHERE thold_data.id = '" . $thold_data_id . "'")) {
            $permission = TRUE;
        }
    } else {
        $permission = TRUE;
    }
    return $permission;
}

function check_notification($notification_id) {
    $permission = FALSE;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        if (db_fetch_cell("
            SELECT plugin_notification_lists.id FROM plugin_notification_lists 
                INNER JOIN user_auth ON (plugin_notification_lists.name = CONCAT(user_auth.username,'_alert') OR plugin_notification_lists.name = CONCAT(user_auth.username,'_warning')) AND user_auth.id = '" . $_SESSION["sess_user_id"] . "'
                WHERE plugin_notification_lists.id = '" . $notification_id . "'")) {
            $permission = TRUE;
        }
    } else {
        $permission = TRUE;
    }
    return $permission;
}

define("RESOURCE_HOST"  , 0);
define("RESOURCE_GRAPH" , 1);
define("RESOURCE_DATA"  , 2);
define("RESOURCE_THOLD" , 3);
function check_resource_count($resource_type, $zero_check=FALSE) {
    $permission = FALSE;
    if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) {
        $full_name = db_fetch_cell("SELECT user_auth.full_name FROM user_auth WHERE user_auth.id = '" . $_SESSION["sess_user_id"] . "'");
        preg_match_all("/(\w+)\=(\w+)/u", $full_name, $matches);
        $user_param = array_combine($matches[1], $matches[2]);

        $count = 0;
        if (isset($user_param)) {
            $host_count = db_fetch_cell("SELECT COUNT(item_id) FROM user_auth_perms WHERE user_id = '" . $_SESSION["sess_user_id"] ."' AND type = '3'");
            if($resource_type == RESOURCE_HOST) {
                if (is_numeric($user_param["host"]) && $user_param["host"] != 0) {
                    if ($host_count < $user_param["host"]) {
                        $permission = TRUE;
                    }
                    if ($zero_check == TRUE && $host_count > 0) {
                        $permission = TRUE;
                    }
                }
            } else if ($resource_type == RESOURCE_GRAPH) {
                $graph_count = db_fetch_cell("
                    SELECT COUNT(graph_local.id) FROM graph_local 
                        INNER JOIN host ON graph_local.host_id = host.id
                        INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                if (is_numeric($user_param["graph"]) && $user_param["graph"] != 0) {
                    if ($host_count > 0 && $graph_count < $user_param["graph"]) {
                        $permission = TRUE;
                    }
                    if ($zero_check == TRUE && $graph_count > 0) {
                        $permission = TRUE;
                    }
                }
            } else if ($resource_type == RESOURCE_DATA) {
                $data_count = db_fetch_cell("
                    SELECT COUNT(data_local.id) FROM data_local 
                        INNER JOIN host ON data_local.host_id = host.id
                        INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                if (is_numeric($user_param["data"]) && $user_param["data"] != 0) {
                    if ($host_count > 0 && $data_count < $user_param["data"]) {
                        $permission = TRUE;
                    }
                    if ($zero_check == TRUE && $data_count > 0) {
                        $permission = TRUE;
                    }
                }
            } else if ($resource_type == RESOURCE_THOLD) {
                $thold_count = db_fetch_cell("
                    SELECT COUNT(thold_data.id) FROM thold_data
                        INNER JOIN user_auth_perms ON thold_data.host_id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                if (is_numeric($user_param["thold"]) && $user_param["thold"] != 0) {
                    if ($host_count > 0 && $thold_count < $user_param["thold"]) {
                        $permission = TRUE;
                    }
                    if ($zero_check == TRUE && $thold_count > 0) {
                        $permission = TRUE;
                    }
                }
            }
        }
    } else {
        $permission = TRUE;
    }
    return $permission;
}
/* modify for multi user end */
?>
