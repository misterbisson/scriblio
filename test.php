<?php
/**
* A simple test for Scriblio 
*
* This file is released under the GNU Public License
* @author Casey Bisson <casey@scriblio.net>
*
*/
echo "<h2>Testing Scriblio. Look for any errors reported below (and cross your fingers that we don't have any).</h2>";

ini_set('display_errors', 'on');
error_reporting(E_ERROR);

require_once('../../../wp-config.php');

ini_set('display_errors', 'on');
error_reporting(E_ERROR);

if($scrib){
	echo "<h2>The Scriblio plugin registered in WordPress and active.</h2>";
}else{
	require_once('scriblio.php');
	echo "<h2>The Scriblio plugin <em>isn't</em> registered in WordPress, but I was able to manually test it. If any errors appear above, please copy them in their entirety and send them to the Scriblio mail list.</h2>";
}

//phpinfo();
?>
