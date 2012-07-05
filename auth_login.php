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

/* set default action */
if (isset($_REQUEST["action"])) {
	$action = $_REQUEST["action"];
}else{
	$action = "";
}

/* Get the username */
if (read_config_option("auth_method") == "2") {
	/* Get the Web Basic Auth username and set action so we login right away */
	$action = "login";
	if (isset($_SERVER["PHP_AUTH_USER"])) {
		$username = str_replace("\\", "\\\\", $_SERVER["PHP_AUTH_USER"]);
	}elseif (isset($_SERVER["REMOTE_USER"])) {
		$username = str_replace("\\", "\\\\", $_SERVER["REMOTE_USER"]);
	}elseif (isset($_SERVER["REDIRECT_REMOTE_USER"])) {
		$username = str_replace("\\", "\\\\", $_SERVER["REDIRECT_REMOTE_USER"]);
	}else{
		/* No user - Bad juju! */
		$username = "";
		cacti_log("ERROR: No username passed with Web Basic Authentication enabled.", false, "AUTH");
		auth_display_custom_error_message("Web Basic Authentication configured, but no username was passed from the web server.  Please make sure you have authentication enabled on the web server.");
		exit;
	}
}else{
	if ($action == "login") {
		/* LDAP and Builtin get username from Form */
		$username = get_request_var_post("login_username");
	}else{
		$username = "";
	}
}

$username = sanitize_search_string($username);

/* modify for multi user start */
// user login after guest access, remove cookie for user tree show
if (isset($_SESSION["sess_config_array"]["guest_user"])) {
    setcookie(session_name(),"",time() - 3600,"/");
}
if (isset($_COOKIE['stay_login'])) {
    $action = "login";
    $_POST["realm"] = "ldap";
    $_POST["stay_login"] = "on";
}
/* modify for multi user end */

/* process login */
$copy_user = false;
$user_auth = false;
$user_enabled = 1;
$ldap_error = false;
$ldap_error_message = "";
$realm = 0;
if ($action == 'login') {
	switch (read_config_option("auth_method")) {
	case "0":
		/* No auth, no action, also shouldn't get here */
		exit;

		break;
	case "2":
		/* Web Basic Auth */
		$copy_user = true;
		$user_auth = true;
		$realm = 2;
		/* Locate user in database */
		$user = db_fetch_row("SELECT * FROM user_auth WHERE username = " . $cnn_id->qstr($username) . " AND realm = 2");

		break;
	case "3":
        /* modify for multi user start */
        // stay login for ldap user
        if (get_request_var_post("realm") == "ldap" && get_request_var_post("stay_login") === "on" && isset($_COOKIE['stay_login'])) {
            $username = get_stay_logon_user();
            if (!empty($username)) {
                unset($_POST["login_password"]);
                /* User ok */
                $user_auth = true;
                $copy_user = true;
                $realm = 1;
                /* Locate user in database */
                cacti_log("LOGIN: LDAP User '" . $username . "' Authenticated", false, "AUTH");
                $user = db_fetch_row("SELECT * FROM user_auth WHERE username = " . $cnn_id->qstr($username) . " AND realm = 1");
            }
        } else {
            // local user
            preg_match("/(^\')(.*?)(\@)(.*?)(\'$)/", $cnn_id->qstr($username), $matches);
            if ($matches[4] === "local") {
                $username = $matches[2];
                $_POST["realm"] = "local";
            }
        }
        /* modify for multi user end */

		/* LDAP Auth */
 		if ((get_request_var_post("realm") == "ldap") && (strlen(get_request_var_post("login_password")) > 0)) {
			/* include LDAP lib */
			include_once("./lib/ldap.php");

			/* get user DN */
			$ldap_dn_search_response = cacti_ldap_search_dn($username);
			if ($ldap_dn_search_response["error_num"] == "0") {
				$ldap_dn = $ldap_dn_search_response["dn"];
			}else{
				/* Error searching */
				cacti_log("LOGIN: LDAP Error: " . $ldap_dn_search_response["error_text"], false, "AUTH");
				$ldap_error = true;
				$ldap_error_message = "LDAP Search Error: " . $ldap_dn_search_response["error_text"];
				$user_auth = false;
				$user = array();
			}

			if (!$ldap_error) {
				/* auth user with LDAP */
				$ldap_auth_response = cacti_ldap_auth($username,stripslashes(get_request_var_post("login_password")),$ldap_dn);

				if ($ldap_auth_response["error_num"] == "0") {
					/* User ok */
					$user_auth = true;
					$copy_user = true;
					$realm = 1;
					/* Locate user in database */
					cacti_log("LOGIN: LDAP User '" . $username . "' Authenticated", false, "AUTH");
					$user = db_fetch_row("SELECT * FROM user_auth WHERE username = " . $cnn_id->qstr($username) . " AND realm = 1");
				}else{
					/* error */
					cacti_log("LOGIN: LDAP Error: " . $ldap_auth_response["error_text"], false, "AUTH");
					$ldap_error = true;
					$ldap_error_message = "LDAP Error: " . $ldap_auth_response["error_text"];
					$user_auth = false;
					$user = array();
				}
			}

		}

	default:
		if (!api_plugin_hook_function('login_process', false)) {
			/* Builtin Auth */
			if ((!$user_auth) && (!$ldap_error)) {
				/* if auth has not occured process for builtin - AKA Ldap fall through */
                /* modify for multi user start */
                $user = db_fetch_row("SELECT * FROM user_auth WHERE username = " . $cnn_id->qstr($matches[2]) . " AND password = '" . md5(get_request_var_post("login_password")) . "' AND realm = 0");
                /* modify for multi user end */
			}
		}
	}
	/* end of switch */

	/* Create user from template if requested */
	if ((!sizeof($user)) && ($copy_user) && (read_config_option("user_template") != "0") && (strlen($username) > 0)) {
		cacti_log("WARN: User '" . $username . "' does not exist, copying template user", false, "AUTH");
		/* check that template user exists */
		if (db_fetch_row("SELECT id FROM user_auth WHERE username = '" . read_config_option("user_template") . "' AND realm = 0")) {
			/* template user found */
			user_copy(read_config_option("user_template"), $username, 0, $realm);
			/* requery newly created user */
			$user = db_fetch_row("SELECT * FROM user_auth WHERE username = " . $cnn_id->qstr($username) . " AND realm = " . $realm);
            /* modify for multi user start */
            generate_user_env($user);
            /* modify for multi user end */
		}else{
			/* error */
			cacti_log("LOGIN: Template user '" . read_config_option("user_template") . "' does not exist.", false, "AUTH");
			auth_display_custom_error_message("Template user '" . read_config_option("user_template") . "' does not exist.");
			exit;
		}
	}

	/* Guest account checking - Not for builtin */
	$guest_user = false;
	if ((sizeof($user) < 1) && ($user_auth) && (read_config_option("guest_user") != "0")) {
		/* Locate guest user record */
		$user = db_fetch_row("SELECT * FROM user_auth WHERE username = '" . read_config_option("guest_user") . "'");
		if ($user) {
			cacti_log("LOGIN: Authenicated user '" . $username . "' using guest account '" . $user["username"] . "'", false, "AUTH");
			$guest_user = true;
		}else{
			/* error */
			auth_display_custom_error_message("Guest user \"" . read_config_option("guest_user") . "\" does not exist.");
			cacti_log("LOGIN: Unable to locate guest user '" . read_config_option("guest_user") . "'", false, "AUTH");
			exit;
		}
	}

	/* Process the user  */
	if (sizeof($user) > 0) {
        /* modify for multi user start */
        if (get_request_var_post("realm") == "ldap" && get_request_var_post("stay_login") === "on" && empty($_COOKIE['stay_login'])) {
            set_stay_logon_user($username);
        }
        /* modify for multi user end */

		cacti_log("LOGIN: User '" . $user["username"] . "' Authenticated", false, "AUTH");
		db_execute("INSERT INTO user_log (username,user_id,result,ip,time) VALUES (" . $cnn_id->qstr($username) . "," . $user["id"] . ",1,'" . $_SERVER["REMOTE_ADDR"] . "',NOW())");
		/* is user enabled */
		$user_enabled = $user["enabled"];
		if ($user_enabled != "on") {
			/* Display error */
			auth_display_custom_error_message("Access Denied, user account disabled.");
			exit;
		}

		/* set the php session */
		$_SESSION["sess_user_id"] = $user["id"];

		/* handle "force change password" */
		if (($user["must_change_password"] == "on") && (read_config_option("auth_method") == 1)) {
			$_SESSION["sess_change_password"] = true;
		}

		/* ok, at the point the user has been sucessfully authenticated; so we must
		decide what to do next */
		switch ($user["login_opts"]) {
			case '1': /* referer */
				/* because we use plugins, we can't redirect back to graph_view.php if they don't
				 * have console access
				 */
				if (isset($_SERVER["HTTP_REFERER"])) {
					$referer = $_SERVER["HTTP_REFERER"];
					if (basename($referer) == "logout.php") {
						$referer = $config['url_path'] . "index.php";
					}
				} else if (isset($_SERVER["REQUEST_URI"])) {
					$referer = $_SERVER["REQUEST_URI"];
					if (basename($referer) == "logout.php") {
						$referer = $config['url_path'] . "index.php";
					}
				} else {
					$referer = $config['url_path'] . "index.php";
				}

				if (substr_count($referer, "plugins")) {
					header("Location: " . $referer);
				} elseif (sizeof(db_fetch_assoc("SELECT realm_id FROM user_auth_realm WHERE realm_id = 8 AND user_id = " . $_SESSION["sess_user_id"])) == 0) {
					header("Location: graph_view.php");
				} else {
					header("Location: $referer");
				}

				break;
			case '2': /* default console page */
				header("Location: " . $config['url_path'] . "index.php");

				break;
			case '3': /* default graph page */
				header("Location: " . $config['url_path'] . "graph_view.php");

				break;
			default:
				api_plugin_hook_function('login_options_navigate', $user['login_opts']);
		}
		exit;
	}else{
		if ((!$guest_user) && ($user_auth)) {
			/* No guest account defined */
			auth_display_custom_error_message("Access Denied, please contact you Cacti Administrator.");
			cacti_log("LOGIN: Access Denied, No guest enabled or template user to copy", false, "AUTH");
			exit;
		}else{
			/* BAD username/password builtin and LDAP */
			db_execute("INSERT INTO user_log (username,user_id,result,ip,time) VALUES (" . $cnn_id->qstr($username) . ",0,0,'" . $_SERVER["REMOTE_ADDR"] . "',NOW())");
		}
	}
}

/* modify for multi user start */
// signup
if ($action == 's2') {
    /* ================= input validation ================= */
    input_validate_input_regex(get_request_var_request("login_username"), "^([A-Za-z0-9]+[\w-]{2,})$");
    input_validate_input_regex(get_request_var_request("login_password"), "^([\w\=\+\-\*\/\%\^\~\!\?\&\|\@\#\$\(\)\[\]\{\}\<\>\,\.\;\:]{6,})$");
    input_validate_input_regex(get_request_var_request("mail_address"), "^([A-Za-z0-9]+[\w-]*@[\w\.-]+\.\w{2,})$");
    /* ==================================================== */
    $username = get_request_var_request("login_username");
    for ($i=1;$i<=10;$i++) { $salt .= substr('0123456789abcdef',rand(0,15),1); }
    $password = "{SSHA}".base64_encode(pack("H*",sha1(get_request_var_request("login_password").$salt)).$salt);
    //$password = get_request_var_request("login_password");
    $mailaddr = get_request_var_request("mail_address");

    include_once("./lib/ldap.php");
    $ldap_dn_search_response = cacti_ldap_search_dn($username);
    // User found
    if ($ldap_dn_search_response["error_num"] == "0") {
        $caution_msg = "Sorry. signup username is already in use.";
        cacti_log("SIGNUP: signup username is already in use", false, "AUTH");
    // Unable to find users
    } elseif ($ldap_dn_search_response["error_num"] == "3") {
        // duplicate mail address
        $ldap_dn_search_response = cacti_ldap_search_dn("dummy", "", "", "", "", "", "", "", "", "", "mail=$mailaddr");
        if ($ldap_dn_search_response["error_num"] == "0") {
            $caution_msg = "Sorry. mail address is already in use.";
            cacti_log("SIGNUP: mail address is already in use.", false, "AUTH");
        } else {
            // hash
            $hash1 = hash_hmac("sha256", $username . $_SERVER["REMOTE_ADDR"] . $_SERVER["HTTP_USER_AGENT"], FALSE);
            $hash2 = hash_hmac("sha256", $username . time() . $password, FALSE);
            // add user to ldap
            $attrib["objectclass"][0] = "inetOrgPerson";
            $attrib["objectclass"][1] = "pwdPolicy";
            $attrib["uid"]            = $username;
            $attrib["cn"]             = $username;
            $attrib["sn"]             = $username;
            $attrib["description"]    = $hash1 . $hash2;
            $attrib["userPassword"]   = $password;
            $attrib["mail"]           = $mailaddr;
            $attrib["pwdAttribute"]   = "2.5.4.35";
            $attrib["pwdLockout"]     = "TRUE";
            $ldap_dn_add_response = cacti_ldap_mod_dn(1, $username, $attrib);
            if ($ldap_dn_add_response["error_num"] == "0") {
                // send mail
                $message = file_get_contents("./text/signup_mail.txt") . "http://" . $_SERVER["SERVER_NAME"] . "/index.php?action=s3&h=" . $hash1;
                $message = str_replace("%USERNAME%", $username, $message);
                $errors = send_mail($mailaddr, "", "Resmon account sign up", $message);
                if ($errors == "") {
                    $caution_msg = "Send mail for last step of sign up. Please read mail.";
                    cacti_log("SIGNUP: send mail for last step of sign up", false, "AUTH");
                }
            }
        }
    }
}
if ($action == 's3') {
    /* ================= input validation ================= */
    input_validate_input_regex(get_request_var_request("h"), "^([a-z0-9]{64})$");
    /* ==================================================== */
    $hash1 = get_request_var_request("h");
    
    include_once("./lib/ldap.php");
    $ldap_dn_search_response = cacti_ldap_search_dn("dummy", "", "", "", "", "", "", "", "", "", "description=$hash1*", "", "", array("uid"));
    if ($ldap_dn_search_response["error_num"] == "0") {
        $ldap_dn_add_response = cacti_ldap_mod_dn(2, $ldap_dn_search_response["uid"]["0"], array("pwdLockout" => "FALSE"));
        if ($ldap_dn_add_response["error_num"] == "0") {
            $caution_msg = "Sign up success. Please login.";
            cacti_log("SIGNUP: sign up success", false, "AUTH");
        }
    }
}
// reset password
if ($action == 'f2') {
    /* ================= input validation ================= */
    input_validate_input_regex(get_request_var_request("mail_address"), "^([A-Za-z0-9]+[\w-]*@[\w\.-]+\.\w{2,})$");
    /* ==================================================== */
    $mailaddr = get_request_var_request("mail_address");
    
    include_once("./lib/ldap.php");
    $ldap_dn_search_response = cacti_ldap_search_dn("dummy", "", "", "", "", "", "", "", "", "", "mail=$mailaddr", "", "", array("uid","description"));
    if ($ldap_dn_search_response["error_num"] == "0") {
        // send mail
        $message = file_get_contents("./text/forgot_mail.txt") . "http://" . $_SERVER["SERVER_NAME"] . "/index.php?a=f3&u=" . $ldap_dn_search_response["uid"]["0"] . "&h=" . substr($ldap_dn_search_response["description"]["0"], 64);
        $message = str_replace("%USERNAME%", $ldap_dn_search_response["uid"]["0"], $message);
        $errors = send_mail($mailaddr, "", "Resmon reset password", $message);
        if ($errors == "") {
            $caution_msg = "Send mail for reset password. Please read mail.";
            cacti_log("SIGNUP: send mail for reset password", false, "AUTH");
        }
    } else {
        auth_display_custom_error_message("Sorry. can't find your mail address.");
        cacti_log("SIGNUP: can't find your mail address.", false, "AUTH");
    }
}
if ($action == 'f4') {
    /* ================= input validation ================= */
    input_validate_input_regex(get_request_var_request("h"), "^([a-z0-9]{64})$");
    input_validate_input_regex(get_request_var_request("login_password"), "^([\w\=\+\-\*\/\%\^\~\!\?\&\|\@\#\$\(\)\[\]\{\}\<\>\,\.\;\:]{6,})$");
    /* ==================================================== */
    $hash2 = get_request_var_request("h");
    $password = get_request_var_request("login_password");
    
    include_once("./lib/ldap.php");
    $ldap_dn_search_response = cacti_ldap_search_dn("dummy", "", "", "", "", "", "", "", "", "", "description=*$hash2", "", "", array("uid"));
    if ($ldap_dn_search_response["error_num"] == "0") {
        $hash1 = hash_hmac('sha256', $ldap_dn_search_response["uid"]["0"] . $_SERVER["REMOTE_ADDR"] . $_SERVER["HTTP_USER_AGENT"], FALSE);
        $hash2 = hash_hmac("sha256", $ldap_dn_search_response["uid"]["0"] . time() . $password, FALSE);
        $ldap_dn_add_response = cacti_ldap_mod_dn(2, $ldap_dn_search_response["uid"]["0"], array("description" => $hash1 . $hash2, "userPassword" => $password));
        if ($ldap_dn_add_response["error_num"] == "0") {
            $caution_msg = "Reset user password successed.";
            cacti_log("SIGNUP: reset user password successed", false, "AUTH");
            setcookie("stay_login", "", time() - 3600,"/");
        }
    } else {
        auth_display_custom_error_message("Sorry. can't reset your password.");
        cacti_log("SIGNUP: can't reset your password.", false, "AUTH");
    }
}

function generate_user_env($user) {
    $hash = hash_hmac("sha256", $username . time() . get_request_var_post("login_password"), FALSE);

    // private tree
    system("php ./cli/add_tree.php --type=tree --name='" . $hash . "' --sort-method=alpha", $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:1");
        exit;
    }
    $tree_id = db_fetch_cell("SELECT id FROM graph_tree WHERE name = '" . $hash . "'");
    if (!isset($tree_id)) {
        error_generate_user_env($user['id'], "error_generate_user_env code:2");
        exit;
    }
    db_execute("UPDATE graph_tree SET name='Private' WHERE id = '" . $tree_id ."'");
    // private tree -> favorite header
    system("php ./cli/add_tree.php --type=node --node-type=header --tree-id=" . $tree_id . " --name='Favorites'", $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:4");
        exit;
    }
    system("php ./cli/add_perms.php --user-id=" . $user['id'] . " --item-type=tree --item-id=" . $tree_id, $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:3");
        exit;
    }
    // user resource
    define("GRAPH_TEMPLATE_ID", 35);
    define("HOST_ID"          , 3);
    define("TITLE"            , "|host_description| - User Resources ID:");
    define("DATA_TEMPLATE_ID" , 49);
    system("php ./cli/add_graphs.php --graph-type=cg --graph-template-id=" . GRAPH_TEMPLATE_ID . " --host-id=" . HOST_ID . " --graph-title='" . TITLE . $user['id'] . "' --input-fields='" . DATA_TEMPLATE_ID . ":user_id=" . $user['id'] . "' --data-title='" . TITLE . $user['id'] . "' --force", $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:5");
        exit;
    }
    $graph_id = db_fetch_cell("SELECT graph_templates_graph.local_graph_id FROM graph_templates_graph WHERE graph_template_id = '" . GRAPH_TEMPLATE_ID . "' AND title = '" . TITLE . $user['id'] . "'");
    if (!isset($graph_id)) {
        error_generate_user_env($user['id'], "error_generate_user_env code:6");
        exit;
    }
    system("php ./cli/add_tree.php --type=node --node-type=graph --tree-id=" . $tree_id . " --graph-id=" . $graph_id, $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:7");
        exit;
    }
    system("php ./cli/add_perms.php --user-id=" . $user['id'] . " --item-type=graph --item-id=" . $graph_id, $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:8");
        exit;
    }
    // none device
    system("php ./cli/add_device.php --description='" . $hash ."' --ip='" . $hash . "' --template=0 --avail=none --version=0", $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:9");
        exit;
    }
    $host_id = db_fetch_cell("SELECT id FROM host WHERE description = '". $hash . "'");
    if (!isset($host_id)) {
        error_generate_user_env($user['id'], "error_generate_user_env code:10");
        exit;
    }
    db_execute("UPDATE host SET description='None Host',hostname='localhost' WHERE id = '" . $host_id ."'");
    system("php ./cli/add_tree.php --type=node --node-type=host --tree-id=" . $tree_id . " --host-id=" . $host_id . " --host-group-style=1", $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:11");
        exit;
    }
    system("php ./cli/add_perms.php --user-id=" . $user['id'] . " --item-type=host --item-id=" . $host_id, $return);
    if ($return != 0) {
        error_generate_user_env($user['id'], "error_generate_user_env code:12");
        exit;
    }
    // thold notification list
    $ldap_dn_search_response = cacti_ldap_search_dn($user['username'], "", "", "", "", "", "", "", "", "", "", "", "", array("mail"));
    if ($ldap_dn_search_response["error_num"] != "0") {
        error_generate_user_env($user['id'], "error_generate_user_env code:13");
        exit;
    }
    foreach (array("alert", "warning") as $priority) {
        unset($save);
        $save["id"]          = 0;
        $save["name"]        = $user['username'] . "_" . $priority;
        $save["description"] = " ";
        $save["emails"]      = $ldap_dn_search_response["mail"]["0"];
        if (!is_error_message()) {
            $id = sql_save($save, "plugin_notification_lists");
            if ($id) {
                raise_message(1);
            } else {
                raise_message(2);
            }
        }
    }
}

function error_generate_user_env($user_id, $message) {
	db_execute("UPDATE user_auth SET enabled = '' WHERE id = '$user_id'");
	cacti_log($message, false, "AUTH");
	auth_display_custom_error_message($message);
}

function get_stay_logon_user() {
    $cookie = explode(":", $_COOKIE['stay_login']);
    $hash1 = hash_hmac("sha256", $cookie[0] . $_SERVER["REMOTE_ADDR"] . $_SERVER["HTTP_USER_AGENT"], FALSE);

    include_once("./lib/ldap.php");
    $ldap_dn_search_response = cacti_ldap_search_dn("dummy", "", "", "", "", "", "", "", "", "", "description=$hash1*", "", "", array("uid","description"));
    if ($ldap_dn_search_response["error_num"] == "0") {
        if($ldap_dn_search_response["uid"]["0"] === $cookie[0] && substr($ldap_dn_search_response["description"]["0"], 64) === $cookie[1]) {
            $username = $ldap_dn_search_response["uid"]["0"];
        } else {
            setcookie("stay_login", "", time() - 3600,"/");
        }
    }
    return $username;
}

function set_stay_logon_user($username) {
    include_once("./lib/ldap.php");
    $ldap_dn_search_response = cacti_ldap_search_dn($username, "", "", "", "", "", "", "", "", "", "", "", "", array("description"));
    if ($ldap_dn_search_response["error_num"] == "0") {
        $hash1 = hash_hmac('sha256', $username . $_SERVER["REMOTE_ADDR"] . $_SERVER["HTTP_USER_AGENT"], FALSE);
        $hash2 = substr($ldap_dn_search_response["description"]["0"], 64);
        $ldap_dn_add_response = cacti_ldap_mod_dn(2, $username, array("description" => $hash1 . $hash2));
        if ($ldap_dn_add_response["error_num"] == "0") {
            setcookie("stay_login", $username . ":" . $hash2, time() + 30 * 24 * 60 * 60,"/");   // 30d * 24h * 60m * 60s
        }
    }
}
/* modify for multi user end */

/* auth_display_custom_error_message - displays a custom error message to the browser that looks like
     the pre-defined error messages
   @arg $message - the actual text of the error message to display */
function auth_display_custom_error_message($message) {
	/* kill the session */
	setcookie(session_name(),"",time() - 3600,"/");
	/* print error */
	print "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01 Transitional//EN\" \"http://www.w3.org/TR/html4/loose.dtd\">";
	print "<html>\n<head>\n";
	print "     <title>" . "Cacti" . "</title>\n";
	print "     <meta http-equiv='Content-Type' content='text/html;charset=utf-8'>";
	print "     <link href=\"include/main.css\" type=\"text/css\" rel=\"stylesheet\">";
	print "</head>\n";
	print "<body>\n<br><br>\n";
	display_custom_error_message($message);
	print "</body>\n</html>\n";
}

if (api_plugin_hook_function('custom_login', OPER_MODE_NATIVE) == OPER_MODE_RESKIN) {
	return;
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
	<title><?php print api_plugin_hook_function("login_title", "Login to Resmon");?></title>
	<meta http-equiv="Content-Type" content="text/html;charset=utf-8">
	<STYLE TYPE="text/css">
	<!--
		BODY, TABLE, TR, TD {font-family: Verdana, Arial, Helvetica, sans-serif; font-size: 12px;}
		A {text-decoration: none;}
		A:active { text-decoration: none;}
		A:hover {text-decoration: underline; color: #333333;}
		A:visited {color: Blue;}
                #foot{position:absolute; bottom:0px; height:30px; width:99%; text-align:center;}
	-->
	</style>
        <script type="text/javascript">

        var _gaq = _gaq || [];
        _gaq.push(['_setAccount', 'UA-33140835-1']);
        _gaq.push(['_trackPageview']);

        (function() {
            var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
            ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
            var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
        })();

        </script>
</head>
<body bgcolor="#FFFFFF" onload="document.login.login_username.focus()">
	<form name="login" method="post" action="<?php print basename($_SERVER["REQUEST_URI"]);?>">
	<input type="hidden" name="action" value="login">
<?php

api_plugin_hook_function("login_before", array('ldap_error' => $ldap_error, 'ldap_error_message' => $ldap_error_message, 'username' => $username, 'user_enabled' => $user_enabled, 'action' => $action));

$cacti_logo = $config['url_path'] . 'images/auth_login.gif';
$cacti_logo = api_plugin_hook_function('cacti_image', $cacti_logo);

?>
	<table id="login" align="center">
		<tr>
			<td colspan="2"><center><?php if ($cacti_logo != '') { ?><img src="<?php echo $cacti_logo; ?>" border="0" alt=""><?php } ?></center></td>
		</tr>
		<?php

		if ($ldap_error) {?>
		<tr style="height:10px;"><td></td></tr>
		<tr>
			<td id="error" colspan="2"><font color="#FF0000"><strong><?php print $ldap_error_message; ?></strong></font></td>
		</tr>
		<?php }else{
		if ($action == "login") {?>
		<tr style="height:10px;"><td></td></tr>
		<tr>
			<td id="error" colspan="2"><font color="#FF0000"><strong>Invalid User Name/Password Please Retype</strong></font></td>
		</tr>
		<?php }
		if ($user_enabled == "0") {?>
		<tr style="height:10px;"><td></td></tr>
		<tr>
			<td id="error" colspan="2"><font color="#FF0000"><strong>User Account Disabled</strong></font></td>
		</tr>
		<?php } } ?>

		<tr style="height:10px;"><td></td></tr>
		<tr id="login_row">
			<td colspan="2"><div id="message">Please enter your Resmon user name and password below:</div></td>
		</tr>
		<tr style="height:10px;"><td></td></tr>
        <?php /* modify for multi user start */ 
        if (empty($_GET["a"]) || $_GET["a"] === "s1" || $_GET["a"] === "f3") { ?>
		<tr id="user_row">
			<td>User Name:</td>
			<td><input type="text" name="login_username" size="40" style="width: 295px;" value="<?php print htmlspecialchars($username); ?>"></td>
		</tr>
		<tr id="password_row">
			<td>Password:</td>
			<td><input type="password" name="login_password" size="40" style="width: 295px;"></td>
		</tr>
        <?php } if ($_GET["a"] === "s1" || $_GET["a"] === "f1") { ?>
        <tr id="mail_row">
            <td>Mail Address:</td>
            <td><input type="text" name="mail_address" size="40" style="width: 295px;"></td>
        </tr>
        <?php } ?>
        <script type="text/javascript">
        <!--
        window.onload = function() {
            var caution_msg = '<?php if (isset($caution_msg)) print "$caution_msg"; ?>';
            if (caution_msg != '') {
                outputMsg(true,caution_msg);
            } else {
                if(window.location.search.substring(1).match(/a=(\w+)/)){
                    switch (RegExp.$1) {
                        case 's1' : outputMsg(false,'Please enter user name, password and mail below:'); break;
                        case 'f1' : outputMsg(false,'Please enter your registered mail address below:'); break;
                        case 'f3' : outputMsg(false,'Please enter new password below:'); break;
                    }
                }
            }
            if(window.location.search.substring(1).match(/u=(\w+)/)){
                document.getElementsByName('login_username').item(0).value=RegExp.$1;
            }
        }
        function checkInput(action) {
            var param = [
                ['login_username', 3, /^([A-Za-z0-9]+[\w-]{2,})$/, 'User Name', 'A-Za-z0-9_-'],
                ['login_password', 6, /^([\w\=\+\-\*\/\%\^\~\!\?\&\|\@\#\$\(\)\[\]\{\}\<\>\,\.\;\:]{6,})$/, 'Password', 'A-Za-z0-9_-=+-*/%^~!?&|@#$()[]{}<>,.;:'],
                ['mail_address', 6, /^([A-Za-z0-9]+[\w-]*@[\w\.-]+\.\w{2,})$/, 'Mail Address','A-Za-z0-9_@.']
            ];
            if (action == 's1') {
                if (document.getElementsByName('agree').item(0).checked == false) {
                    outputMsg(true,'Please agree to the terms of service.');
                    document.getElementsByName('agree').item(0).focus();
                    return;
                }
            } else if (action == 'f1') {
                param.splice(0, 2); // mail address
            } else if (action == 'f3') {
                param.splice(2, 1); // username, password
            }
            for (var i in param) {
                if (!document.getElementsByName(param[i][0]).item(0).value.match(param[i][2])) {
                    outputMsg(true,'Please enter '+param[i][3]+' at least '+param[i][1]+' characters.<br>Allow character: '+param[i][4]);
                    document.getElementsByName(param[i][0]).item(0).focus();
                    return;
                }
            }
            document.getElementsByName('action').item(0).value=action.slice(0,1)+(parseInt(action.slice(1))+1);
            document.getElementsByName('login').item(0).submit();
        }
        function outputMsg(caution,msg) {
            if(caution) {
                msg='<strong><font color="#FF0000">'+msg+'</font><strong>';
            }
            document.getElementById('message').innerHTML=msg;
        }
        -->
        </script>
		<?php
		if (read_config_option("auth_method") == "3" || api_plugin_hook_function('login_realms_exist')) {
			$realms = api_plugin_hook_function('login_realms', array("local" => array("name" => "Local", "selected" => false), "ldap" => array("name" => "LDAP", "selected" => true)));
            $ldap = FALSE;
            foreach($realms as $name => $realm) {
                if ($name === "ldap") {
                    print "\t<input type=\"hidden\" name=\"realm\" value=\"ldap\">\n";
                    $ldap = TRUE;
                    break;
                }
            }
            if ($ldap == FALSE) {
            ?>
		<tr id="realm_row">
			<td>Realm:</td>
			<td>
				<select name="realm" style="width: 295px;"><?php
				if (sizeof($realms)) {
				foreach($realms as $name => $realm) {
					print "\t\t\t\t\t<option value='" . $name . "'" . ($realm["selected"] ? " selected":"") . ">" . htmlspecialchars($realm["name"]) . "</option>\n";
				}
				}
				?>
				</select>
			</td>
		</tr>
		<?php }} ?>
		<tr style="height:10px;"><td style="width:90px;"></td></tr>
		<tr>
            <?php if (empty($_GET["a"])) { ?>
                <td colspan="2"><input type="submit" value="Login">&nbsp;<input type="checkbox" name="stay_login"<?php ($_COOKIE['stay_login'] ? print " checked" : "") ?>>stay login&nbsp;&nbsp;&nbsp;&nbsp;
                [ <a href="./graph_view.php">Guest</a> | <a href="./index.php?a=s1">Signup</a> | <a href="./index.php?a=f1">Forgot Password</a> ]</td>
            <?php } elseif ($_GET["a"] === "s1") { ?>
                <td colspan="2"><input type="button" value="Create" onClick="checkInput('s1');">&nbsp;<input type="checkbox" name="agree"> I agree to <a href="./text/terms.html">terms</a>&nbsp;&nbsp;&nbsp;&nbsp;
                [ <a href="./index.php">Login</a> ]</td>
            <?php } elseif ($_GET["a"] === "f1" || $_GET["a"] === "f3") { ?>
                <td colspan="2"><input type="button" value="Submit" onClick="checkInput('<?php print $_GET["a"] ?>');">&nbsp;&nbsp;&nbsp;&nbsp;
                [ <a href="./index.php">Login</a> ]</td>
            <?php } /* modify for multi user end */ ?>
		</tr>
	</table>
<?php api_plugin_hook('login_after'); ?>
	</form>
        <div id="foot" style="position: absolute;">Resmon is a fork of <a href="http://www.cacti.net/">Cacti</a> and is backward compatible.</div>
</body>
</html>
