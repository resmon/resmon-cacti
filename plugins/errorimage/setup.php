<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2011 The Cacti Group                                      |
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


function plugin_errorimage_install () {
	api_plugin_register_hook('errorimage', 'graph_image', 'errorimage_check_graphs', 'setup.php');
}

function errorimage_check_graphs () {
	global $config;

	$local_graph_id = $_GET['local_graph_id'];
	$graph_items = db_fetch_assoc("select
		data_template_rrd.local_data_id
		from graph_templates_item
		left join data_template_rrd on (graph_templates_item.task_item_id=data_template_rrd.id)
		where graph_templates_item.local_graph_id=$local_graph_id
		order by graph_templates_item.sequence");

	$ids = array();
	foreach($graph_items as $graph) {
		if ($graph['local_data_id'] != '')
			$ids[] = $graph['local_data_id'];
	}
	$ids = array_unique($ids);

	if (!empty($_GET["graph_nolegend"])) {
		$height = read_graph_config_option("default_height") + 62;
		$width  = read_graph_config_option("default_width") + 95;
	}else{
		$hw = db_fetch_row("SELECT width, height 
			FROM graph_templates_graph 
			WHERE local_graph_id=" . $_GET['local_graph_id']);
		$hr = db_fetch_cell("SELECT count(*) FROM graph_templates_item WHERE local_graph_id=" . $_GET['local_graph_id'] . " AND hard_return='on'");

		$height = $hw['height'] + (16 * $hr) + 90; // # hard rules, plus room for date
		$width  = $hw['width'] + 95;
	}

	foreach ($ids as $id => $local_data_id) {
		$data_source_path = get_data_source_path($local_data_id, true);
		if (!file_exists($data_source_path)) {
			$filename = $config['base_path'] . '/plugins/errorimage/images/no-datasource.png';
			if (function_exists("imagecreatefrompng")) {
				echo errorimage_resize_png($filename,$width,$height);
			}else{
				$file = fopen($filename, 'rb');
				echo fread($file, filesize($filename));
				fclose($file);
			}
			exit;
		}
	}
}

function plugin_errorimage_uninstall () {
	// Do any extra Uninstall stuff here
}

function plugin_errorimage_check_config () {
	// Here we will check to ensure everything is configured
	return true;
}

function plugin_errorimage_upgrade () {
	// Here we will upgrade to the newest version
	return false;
}

function errorimage_version () {
	return plugin_errorimage_version();
}

function plugin_errorimage_version () {
	return array(
		'name' 		=> 'errorimage',
		'version' 	=> '0.2',
		'longname'	=> 'Error Images',
		'author'	=> 'Jimmy Conner',
		'homepage'	=> 'http://cacti.net',
		'email'		=> 'jimmy@sqmail.org',
		'url'		=> 'http://cactiusers.org/cacti/versions.php'
	);
}

function errorimage_resize_png($src,$dstw,$dsth,$dst='') {
    list($width, $height, $type, $attr) = getimagesize($src);
    $im  = imagecreatefrompng($src);
    $tim = imagecreatetruecolor($dstw,$dsth);
    imagecopyresampled($tim,$im,0,0,0,0,$dstw,$dsth,$width,$height);
    $tim = errorimage_ImageTrueColorToPalette2($tim,false,255);
	if ($dst == '') {
    	imagepng($tim);
	}else{
    	imagepng($tim,$dst);
	}
}

//zmorris at zsculpt dot com function, a bit completed
function errorimage_ImageTrueColorToPalette2($image, $dither, $ncolors) {
    $width  = imagesx( $image );
    $height = imagesy( $image );
    $colors_handle = ImageCreateTrueColor( $width, $height );
    ImageCopyMerge($colors_handle, $image, 0, 0, 0, 0, $width, $height, 100);
    ImageTrueColorToPalette($image, $dither, $ncolors);
    ImageColorMatch($colors_handle, $image);
    ImageDestroy($colors_handle);
    return $image;
}
