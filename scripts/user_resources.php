<?php

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR'])) {
    die("<br><strong>This script is only meant to run at the command line.</strong>");
}

if (!isset($_SERVER["argv"][1]) || !is_numeric($_SERVER["argv"][1])) {
        print "\nIt is highly recommended that you use the web interface to copy users as this script will only copy Local Cacti users.\n\n";
        print "Syntax:\n php user_resources.php <graph_tree_id>\n\n";
        exit;
}

$no_http_headers = true;

/* display no errors */
error_reporting(E_ERROR);

/* get access to the database and open a session */
include(dirname(__FILE__) . "/../include/global.php");

if ($_SERVER["argv"][1] > 0) {
    $row = db_fetch_row("
        SELECT
		    COUNT(DISTINCT(host.id)) AS host,
		    COUNT(DISTINCT(graph_local.id)) AS graph,
		    COUNT(DISTINCT(data_local.id)) AS data,
		    COUNT(DISTINCT(thold_data.id)) AS thold,
		    TRUNCATE(pt.cur_time,2) AS proc_time
		FROM host
		    INNER JOIN user_auth_perms ON host.id = user_auth_perms.item_id AND user_auth_perms.type = 3
		    INNER JOIN user_auth ON user_auth_perms.user_id = user_auth.id
		    LEFT JOIN graph_local ON host.id = graph_local.host_id
		    LEFT JOIN data_local ON host.id = data_local.host_id
		    LEFT JOIN thold_data ON host.id = thold_data.host_id
		    LEFT JOIN (SELECT user_id, SUM(processed_time.cur_time) AS cur_time FROM processed_time WHERE time > subdate(NOW(), interval 1 hour) AND disabled = '' AND cur_time > 0 GROUP BY user_id) AS pt ON user_auth.id = pt.user_id 
		WHERE user_auth.id = '" . $_SERVER["argv"][1] . "'
		GROUP BY user_auth.id");
} else {
    $row = db_fetch_row("
        SELECT
            (SELECT COUNT(id) FROM host) AS host,
            (SELECT COUNT(id) FROM graph_local) AS graph,
            (SELECT COUNT(id) FROM data_local) AS data,
            (SELECT COUNT(id) FROM thold_data) AS thold,
            (SELECT SUM(processed_time.cur_time) FROM processed_time WHERE time > subdate(NOW(), interval 1 hour) AND disabled = '' AND cur_time > 0) AS proc_time");
}

if (isset($row)) {
    print "host:" . $row["host"] . " graph:" . $row["graph"]. " data:" . $row["data"] . " thold:" . $row["thold"] . " proc_time:" . $row["proc_time"] . "\n";
} else {
    print "host:U graph:U data:U thold:U proc_time:U\n";
}

?>
