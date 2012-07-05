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
/* modify for multi user start */
if (isset($_REQUEST["action"])) {
    if ($_REQUEST["action"] === "login_password") {
        input_validate_input_regex(get_request_var_request("login_password"), "^([\w\=\+\-\*\/\%\^\~\!\?\&\|\@\#\$\(\)\[\]\{\}\<\>\,\.\;\:]{6,})$");
        $username = db_fetch_cell("SELECT user_auth.username FROM user_auth WHERE user_auth.id = '" . $_SESSION["sess_user_id"] . "'");
        if ($username) {
            for ($i=1;$i<=10;$i++) { $salt .= substr('0123456789abcdef',rand(0,15),1); }
            $password = "{SSHA}".base64_encode(pack("H*",sha1(get_request_var_request("login_password").$salt)).$salt);
            //$password = get_request_var_request("login_password");
            include_once("./lib/ldap.php");
            $ldap_dn_add_response = cacti_ldap_mod_dn(2, $username, array("userPassword" => $password));
            if ($ldap_dn_add_response["error_num"] == "0") {
                $message = "Change user password successed.";
            }
        }
    } elseif ($_REQUEST["action"] === "mail_address") {
        input_validate_input_regex(get_request_var_request("mail_address"), "^([A-Za-z0-9]+[\w-]*@[\w\.-]+\.\w{2,})$");
        $username = db_fetch_cell("SELECT user_auth.username FROM user_auth WHERE user_auth.id = '" . $_SESSION["sess_user_id"] . "'");
        if ($username) {
            $mailaddr = get_request_var_request("mail_address");
            include_once("./lib/ldap.php");
            $ldap_dn_search_response = cacti_ldap_search_dn("dummy", "", "", "", "", "", "", "", "", "", "mail=$mailaddr");
            if ($ldap_dn_search_response["error_num"] == "0") {
                $message = "mail address is already in use.";
            } else {
                $ldap_dn_add_response = cacti_ldap_mod_dn(2, $username, array("mail" => $mailaddr));
                if ($ldap_dn_add_response["error_num"] == "0") {
                    $message = "Change mail address successed.";
                }
            }
        }
    } elseif ($_REQUEST["action"] === "deactivate") {
        $username = db_fetch_cell("SELECT user_auth.username FROM user_auth WHERE user_auth.id = '" . $_SESSION["sess_user_id"] . "'");
        if ($username) {
            include_once("./lib/ldap.php");
            // send mail
            $ldap_dn_search_response = cacti_ldap_search_dn($username, "", "", "", "", "", "", "", "", "", "", "", "", array("mail"));
            if ($ldap_dn_search_response["error_num"] == "0") {
                $message = file_get_contents("./text/deactivate_mail.txt");
                $message = str_replace("%USERNAME%", $username, $message);
                $errors = send_mail($ldap_dn_search_response["mail"]["0"], "", "Resmon acount deactivated", $message);
                if ($errors == "") {
                    $caution_msg = "Send mail for account deactivated. Please read mail.";
                    cacti_log("SIGNUP: send mail for account deactivated", false, "AUTH");
                }
            }
            // modify ldap, delete sql
            $rnd_hash = hash_hmac("sha256", time() . mt_rand(), FALSE);
            $ldap_dn_add_response = cacti_ldap_mod_dn(2, $username, array(description => "deactivate " . date("c"), mail => $rnd_hash, userPassword => $rnd_hash, pwdLockout => "TRUE"));
            if ($ldap_dn_add_response["error_num"] == "0") {
                $ldap_dn_add_response = cacti_ldap_mod_dn(3, $username);
                if ($ldap_dn_add_response["error_num"] == "0") {
                    // notification lists
                    db_execute("
                        DELETE plugin_notification_lists FROM plugin_notification_lists 
                            INNER JOIN user_auth ON user_auth.id = '" . $_SESSION["sess_user_id"] . "' 
                        WHERE plugin_notification_lists.name = CONCAT(user_auth.username,'_alert') OR plugin_notification_lists.name = CONCAT(user_auth.username,'_warning')");
                    // thold_data
                    db_execute("
                        DELETE thold_data FROM thold_data
                            INNER JOIN host ON thold_data.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                    // plugin_thold_log
                    db_execute("
                        DELETE plugin_thold_log FROM plugin_thold_log
                            INNER JOIN host ON plugin_thold_log.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                    // user resource
                    define("TITLE", "|host_description| - User Resources ID:");
                    $graphs[] = db_fetch_cell("SELECT local_graph_id FROM graph_templates_graph WHERE title LIKE CONCAT('" . TITLE . "','" . $_SESSION["sess_user_id"] . "')");
                    $data_sources[] = db_fetch_cell("SELECT local_data_id FROM data_template_data WHERE name LIKE CONCAT('" . TITLE . "','" . $_SESSION["sess_user_id"]. "')");
                    // graph
                    $rows = db_fetch_assoc("
                        SELECT graph_local.id FROM graph_local 
                            INNER JOIN host ON graph_local.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                    foreach ($rows as $row) {
                        $graphs[] = $row["id"];
                    }
                    // data_source
                    $rows = db_fetch_assoc("
                        SELECT data_local.id FROM data_local 
                            INNER JOIN host ON data_local.host_id = host.id
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                    foreach ($rows as $row) {
                        $data_sources[] = $row["id"];
                    }
                    // device
                    $rows = db_fetch_assoc("
                        SELECT host.id FROM host
                            INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.user_id = '" . $_SESSION["sess_user_id"] ."' AND user_auth_perms.type = '3'");
                    foreach ($rows as $row) {
                        $hosts[] = $row["id"];
                    }
                    if (sizeof($graphs) > 0) {
                        include_once("./lib/api_graph.php");
                        api_graph_remove_multi($graphs);
                    }
                    if (sizeof($data_sources) > 0) {
                        include_once("./lib/api_data_source.php");
                        api_data_source_remove_multi($data_sources);
                    }
                    if (sizeof($hosts) > 0) {
                        include_once("./lib/api_device.php");
                        api_device_remove_multi($hosts);
                    }
                    // tree, tree_item
                    db_execute("DELETE FROM graph_tree WHERE id = '" . $_SESSION["private_tree_id"]  . "'");
                    db_execute("DELETE FROM graph_tree_items WHERE graph_tree_id = '" . $_SESSION["private_tree_id"]  . "'");
                    // user_auth
                    user_remove($_SESSION["sess_user_id"]);
                    // logout
                    header("Location: logout.php");
                    exit;
                }
            }
        }
    }
}
/* modify for multi user end */
include("./include/top_header.php");

api_plugin_hook('console_before');

?>
<table width="100%" align="center">
	<tr>
		<td class="textArea">
			<strong>You are now logged into <a href="about.php">Resmon</a>. You can follow these basic steps to get
			started.</strong>

			<ul>
				<li><a href="host.php">Create devices</a> for network</li>
				<li><a href="graphs_new.php">Create graphs</a> for your new devices</li>
				<li><a href="graph_view.php">View</a> your new graphs</li>
			</ul>
		</td>
		<td class="textArea" align="right" valign="top">
			<!-- <strong>Version <?php // print $config["cacti_version"];?></strong> -->
		</td>
	</tr>
    <?php /* modify for multi user start */ if ($_SESSION["permission"] < ACCESS_ADMINISTRATOR) { ?>
    <script type="text/javascript">
    <!--
    function checkInput(i) {
        var param = [
            ['login_password', 6, /^([\w\=\+\-\*\/\%\^\~\!\?\&\|\@\#\$\(\)\[\]\{\}\<\>\,\.\;\:]{6,})$/, 'Password', 'A-Za-z0-9_-=+-*/%^~!?&|@#$()[]{}<>,.;:'],
            ['mail_address', 6, /^([A-Za-z0-9]+[\w-]*@[\w\.-]+\.\w{2,})$/, 'Mail Address','A-Za-z0-9_@.'],
            ['deactivate', 0],
        ];
        if (param[i][1] > 0) {
            if (!document.getElementsByName(param[i][0]).item(0).value.match(param[i][2])) {
                document.getElementById('setting_row').innerHTML='Please enter '+param[i][3]+' at least '+param[i][1]+' characters. <br>Allow character: '+param[i][4]+'';
                return;
            }
        }
        document.getElementsByName('action').item(0).value=param[i][0];
        document.getElementsByName('settings').item(0).submit();
    }
    -->
    </script>
    <form name="settings" method="post" action="<?php print basename($_SERVER["REQUEST_URI"]);?>">
	<input type="hidden" name="action">
    <tr>
        <td class="textArea">
			<strong>User Settings</strong>

			<ul>
                <!-- <li><a href="">Change user resource</a></li> -->
                <!-- <li><a href="">Send mail if user resource is over</a></li> -->
				<li><a href="./index.php?s=p1">Change user password</a></li>
                <?php if ($_GET["s"] === "p1") {
                    print "New password: <input type=\"password\" name=\"login_password\" value=\"\">&nbsp;<input type=\"button\" value=\"Save\" onClick=\"checkInput(0);\"><br><font id=\"setting_row\" color=\"#FF0000\">$message</font><br>";
                } ?>
                <li><a href="./index.php?s=m1">Change mail address</a></li>
                <?php if ($_GET["s"] === "m1") {
                    $username = db_fetch_cell("SELECT user_auth.username FROM user_auth WHERE user_auth.id = '" . $_SESSION["sess_user_id"] . "'");
                    if ($username) {
                        include_once("./lib/ldap.php");
                        $ldap_dn_search_response = cacti_ldap_search_dn($username, "", "", "", "", "", "", "", "", "", "", "", "", array("mail"));
                        if ($ldap_dn_search_response["error_num"] == "0") {
                            print "New mail address: <input type=\"text\" name=\"mail_address\" value=\"" . $ldap_dn_search_response["mail"]["0"] . "\">&nbsp;<input type=\"button\" value=\"Save\" onClick=\"checkInput(1);\"><br><font id=\"setting_row\" color=\"#FF0000\">$message</font><br>";
                        }
                    }
                } ?>
                <li><a href="./index.php?s=d1">Deactivate</a></li>
                <?php if ($_GET["s"] === "d1" || $_GET["s"] === "d2") {
                    $username = db_fetch_cell("SELECT user_auth.username FROM user_auth WHERE user_auth.id = '" . $_SESSION["sess_user_id"] . "'");
                    if ($username) {
                        //$message = file_get_contents("./text/deactivate.txt");
                        //print str_replace("%USERNAME%", $username, $message);
                        print "Before you deactivate <b><font color=\"#FF0000\">$username</font></b>, know this:
                            <ul>
                                <li>You don't need to deactivate your account to change your email address or password.</li>
                                <li>Until the user data is permanently deleted, that information won't be available for use.</li>
                                <li>Your account should be removed from this system within a few minutes, <br>but some content may be viewable on this system for a few days after deactivation.</li>
                                <li>We have no control over content indexed by search engines like Google.</li>
                            </ul>";
                    }
                }
                if ($_GET["s"] === "d1") {
                    print "<a href=\"./index.php?s=d2\">Continue ?</a><br>";
                }elseif ($_GET["s"] === "d2") {
                    print "Deactivate your account really ?&nbsp;<input type=\"button\" value=\"OK\" onClick=\"checkInput(2);\"><br>";
                } ?>
			</ul>
		</td>
    </tr>
    </form>
    <?php } else { ?>
    <tr>
        <td class="textArea">
			<strong>Admin Settings</strong>

			<ul>
                <li><a href="./user_admin2.php">User Management2</a></li>
			</ul>
		</td>
    </tr>
    <?php /* modify for multi user end */ } ?>
</table>

<?php

api_plugin_hook('console_after');

include("./include/bottom_footer.php");

?>
