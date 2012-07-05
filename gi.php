<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html><head><title></title><meta http-equiv="Content-Type" content="text/html;charset=utf-8"></head><body><?php 
    $guest_account = true;

    include("./include/auth.php");

    /* ================= input validation ================= */
    input_validate_input_number(get_request_var("g"));
    input_validate_input_number(get_request_var("r"));
    /* ==================================================== */

    if (check_graph($_GET["g"]) >= GRAPH_PUBLIC) {
        $rel_url = "http://". $_SERVER["SERVER_NAME"] . "/graph.php?action=view&local_graph_id=" . $_GET["g"] . "&rra_id=all";
        $img_url = "http://". $_SERVER["SERVER_NAME"] . "/graph_image.php?local_graph_id=" . $_GET["g"] . "&rra_id=" . $_GET["r"];
        print "<a href=\"$rel_url\" target=\"_top\"><img src=\"$img_url\" border=\"0\"></a><br>\n";
        //print "text message area\n";
    } else {
        print "no public graph ...";
    }
?></body></html>
