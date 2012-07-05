function getHtml(url) {
	xmlhttp = createXMLHttp();
	if (xmlhttp) {
		xmlhttp.onreadystatechange = setHtml;
		xmlhttp.open('GET', url);
		xmlhttp.send(null);
	}
}
function createXMLHttp() {
	try {
		return new ActiveXObject ("Microsoft.XMLHTTP");
	}catch(e){
		try {
			return new XMLHttpRequest();
		}catch(e) {
			return null;
		}
	}
	return null;
}
function setHtml() {
	if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
		var reg = /.*graph_image\.php\?local_graph_id\=([0-9]+)\&rra_id\=([0-9]+).*/
		var prm = xmlhttp.responseText.match(reg);
		document.getElementById(prm[1]+"_"+prm[2]).innerHTML = xmlhttp.responseText;
	}
}
function cactiWidget(g,r) {
	getHtml("http://"+location.hostname+"/gi.php?g="+g+"&r="+r);
}
