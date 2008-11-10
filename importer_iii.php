<?php 
/*
Plugin Name: Scriblio III Catalog Importer
Plugin URI: http://about.scriblio.net/
Description: Imports catalog content directly from a III web OPAC, no MaRC export/import needed.
Version: .02
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/
/* Copyright 2007 Casey Bisson & Plymouth State University

	This program is free software; you can redistribute it and/or modify 
	it under the terms of the GNU General Public License as published by 
	the Free Software Foundation; either version 2 of the License, or 
	(at your option) any later version. 

	This program is distributed in the hope that it will be useful, 
	but WITHOUT ANY WARRANTY; without even the implied warranty of 
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the 
	GNU General Public License for more details. 

	You should have received a copy of the GNU General Public License 
	along with this program; if not, write to the Free Software 
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA 
*/ 


/*
	Revised by K.T. Lam (lblkt@ust.hk), Head of Library Systems, The Hong Kong University of Science and Technology Library
	Purpose: to enhance Scriblio's CJK support and to make it works with HKUST's INNOPPAC.
	Date: 13 November 2007; 22 November 2007; 17 December 2007; 29 December 2007; 14 January 2008; 13 May 2008;

*/



// The importer 
class ScribIII_import { 
	var $importer_code = 'scribimporter_iii'; 
	var $importer_name = 'Scriblio III Catalog Importer'; 
	var $importer_desc = 'Imports catalog content directly from a III web OPAC, no MaRC export/import needed. <a href="http://about.scriblio.net/wiki/">Documentation here</a>.'; 
	 
	// Function that will handle the wizard-like behaviour 
	function dispatch() { 
		if (empty ($_GET['step'])) 
			$step = 0; 
		else 
			$step = (int) $_GET['step']; 

		// load the header
		$this->header();

		switch ($step) { 
			case 0 :
				$this->greet();
				break;
			case 1 : 
				$this->iii_start(); 
				break;
			case 2:
				$this->iii_getrecords(); 
				break; 
			case 3:
				$this->ktnxbye(); 
				break; 
		} 

		// load the footer
		$this->footer();
	} 

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Scriblio III Importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		$prefs = get_option('scrib_iiiimporter');
		$prefs['scrib_iii-warnings'] = array();
		$prefs['scrib_iii-errors'] = array();
		$prefs['scrib_iii-record_end'] = '';
		$prefs['scrib_iii-records_harvested'] = 0;
		update_option('scrib_iiiimporter', $prefs);

		$prefs = get_option('scrib_iiiimporter');

		echo '<p>'.__('Howdy! Start here to import records from a Innovative Interfaces (III) ILS system into Scriblio.').'</p>';

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;step=1" method="post">';
?>

<table class="form-table">
<tr valign="top">
<th scope="row"><?php _e('The Innopac base hostname', 'scrib') ?></th>
<td>
<input name="scrib_iii-sourceinnopac" type="text" id="scrib_iii-sourceinnopac" value="<?php echo attribute_escape( $prefs['scrib_iii-sourceinnopac'] ); ?>" /><br />
example: lola.plymouth.edu
</td>
</tr>

<tr valign="top">
<th scope="row"><?php _e('The source prefix', 'scrib') ?></th>
<td>
<input name="scrib_iii-sourceprefix" type="text" id="scrib_iii-sourceprefix" value="<?php echo attribute_escape( $prefs['scrib_iii-sourceprefix'] ); ?>" /><br />
example: bb (must be two characters, a-z and 0-9 accepted)
</td>
</tr>

</table>
<?php

		echo '<p>'.__('All Scriblio records have a &#039;sourceid,&#039; a unique alphanumeric string that&#039;s used to avoid creating duplicate records and, in some installations, link back to the source system for current availability information.').'</p>';
		echo '<p>'.__('The sourceid is made up of two parts: the prefix that you assign, and the bib number from the Innopac. Theoretically, you chould gather records from 1,296 different systems, it&#039;s a big world.').'</p>';

?>

<table class="form-table">
<tr valign="top">
<th scope="row"><?php _e('Harvest records with', 'scrib') ?></th>
<td>
<input name="scrib_iii-require" type="text" id="scrib_iii-require" value="<?php echo attribute_escape( $prefs['scrib_iii-require'] ); ?>" /><br />
example: My Library Location Name (optional; leave blank to harvest any record)<br />
uses <a href="http://php.net/strpos">strpos</a> matching rules
</td>
</tr>

<tr valign="top">
<th scope="row"><?php _e('Ignore records with', 'scrib') ?></th>
<td>
<input name="scrib_iii-reject" type="text" id="scrib_iii-reject" value="<?php echo attribute_escape( $prefs['scrib_iii-reject'] ); ?>" /><br />
example: No Such Record<br />
uses <a href="http://php.net/strpos">strpos</a> matching rules 
</td>
</tr>
</table>

<table class="form-table">
<tr>
<th scope="row" class="th-full">
<label for="scrib_iii-capitalize_titles"><input type="checkbox" name="scrib_iii-capitalize_titles" id="scrib_iii-capitalize_titles" value="1" <?php if( !empty( $prefs['scrib_iii-capitalize_titles'] )) echo 'CHECKED'; ?> /> Capitalize titles</label>
</th>
</tr>
<tr>

<tr>
<th scope="row" class="th-full">
<label for="scrib_iii-convert_encoding"><input type="checkbox" name="scrib_iii-convert_encoding" id="scrib_iii-convert_encoding" value="1" <?php if( !empty( $prefs['scrib_iii-convert_encoding'] )) echo 'CHECKED'; ?> /> Convert character encoding to UTF8</label>
</th>
</tr>
</table>
<?php
		echo '<p>'.__('Many III web OPACs use encodings other than <a href="http://en.wikipedia.org/wiki/UTF-8">UTF8</a>. This option will attempt to convert the characters to UTF8 so that accented and non-latin characters are properly represented. However, do not use this option if your web OPAC is configured to output UTF8 characters.').'</p>';

		if(!function_exists('mb_convert_encoding')){
			echo '<br /><br />';	
			echo '<p>'.__('This PHP install does not support <a href="http://php.net/manual/en/ref.mbstring.php">multibyte string functions</a>, including <a href="http://php.net/mb_convert_encoding">mb_convert_encoding</a>. Without that function, this importer can&#039;t convert the character encoding from records in the ILS into UTF-8. Accented characters may not import correctly.').'</p>';
		}


		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';


		echo '<br /><br />';	
		echo '<form action="admin.php?import=scribimporter&amp;step=3" method="post">';
		echo '<p class="submit">or jump immediately to <input type="submit" name="next" value="'.__('Publish Harvested Records &raquo;').'" /> <br />'. __('(goes to default Scriblio importer)').'</p>';
		echo '</form>';
		echo '</div>';
	}

	function ktnxbye() {
		echo '<div class="narrow">';
		echo '<p>'.__('All done.').'</p>';
		echo '</div>';
	}

	function iii_start(){
//note to HKUST: changed from $_POST to $_REQUEST so the script accepts either post or get variables.
		if(empty( $_REQUEST['scrib_iii-sourceprefix'] ) || empty( $_REQUEST['scrib_iii-sourceinnopac'] )){
			echo '<h3>'.__('Sorry, there has been an error.').'</h3>';
			echo '<p>'.__('Please complete all fields.').'</p>';
			return;
		}

		if( 2 <> strlen( ereg_replace('[^a-z|A-Z|0-9]', '', $_REQUEST['scrib_iii-sourceprefix'] ))){
			echo '<h3>'.__('Sorry, there has been an error.').'</h3>';
			echo '<p>'.__('The source prefix must be exactly two characters, a-z and 0-9 accepted.').'</p>';
			return;
		}

		// save these settings so we can try them again later
		$prefs = get_option('scrib_iiiimporter');
		$prefs['scrib_iii-sourceprefix'] = strtolower(ereg_replace('[^a-z|A-Z|0-9]', '', $_REQUEST['scrib_iii-sourceprefix']));
		stripslashes($_REQUEST['scrib_iii-sourceprefix']);
		$prefs['scrib_iii-sourceinnopac'] = ereg_replace('[^a-z|A-Z|0-9|-|\.]', '', $_REQUEST['scrib_iii-sourceinnopac']);
		$prefs['scrib_iii-convert_encoding'] = isset( $_REQUEST['scrib_iii-convert_encoding'] );

		$prefs['scrib_iii-require'] = $_REQUEST['scrib_iii-require'];
		$prefs['scrib_iii-reject'] = $_REQUEST['scrib_iii-reject'];
		$prefs['scrib_iii-capitalize_titles'] = isset( $_REQUEST['scrib_iii-capitalize_titles'] );
		update_option('scrib_iiiimporter', $prefs);


		$this->iii_options();
	}

	function iii_options( $record_start = FALSE, $record_end = FALSE ){
		global $wpdb, $scrib_import;

		$prefs = get_option('scrib_iiiimporter');

		if( !$record_start )
			$record_start = ( 100 * round( $wpdb->get_var( 'SELECT SUBSTRING( source_id, 3 ) FROM '. $scrib_import->harvest_table .' WHERE source_id LIKE "'. $prefs['scrib_iii-sourceprefix'] .'%" ORDER BY source_id DESC LIMIT 1' ) / 100 ));

		if( !$record_end )
			$record_end = $record_start + 1000;

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;step=2" method="post">';
?>
<table class="form-table">

<tr valign="top">
<th scope="row"><?php _e('Start with bib number', 'scrib') ?></th>
<td>
<input type="text" name="scrib_iii-record_start" id="scrib_iii-record_start" value="<?php echo attribute_escape( $record_start ); ?>" /><br />
</td>
</tr>

<tr valign="top">
<th scope="row"><?php _e('End', 'scrib') ?></th>
<td>
<input type="text" name="scrib_iii-record_end" id="scrib_iii-record_end" value="<?php echo attribute_escape( $record_end ); ?>" />
</td>
</tr>

</table>
<table class="form-table">

<tr>
<th scope="row" class="th-full">
<label for="scrib_iii-debug"><input type="checkbox" name="scrib_iii-debug" id="scrib_iii-debug" value="1" = /> Debug mode</label>
</th>
</tr>
<tr>
</table>

<input type="hidden" name="scrib_iii-sourceprefix" id="scrib_iii-sourceprefix" value="<?php echo attribute_escape( $prefs['scrib_iii-sourceprefix'] ); ?>" />
<input type="hidden" name="scrib_iii-sourceinnopac" id="scrib_iii-sourceinnopac" value="<?php echo attribute_escape( $prefs['scrib_iii-sourceinnopac'] ); ?>" />
<?php
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';

//exit;
	}

	function iii_getrecords(){
		global $wpdb, $scrib_import;
//note to HKUST: changed from $_POST to $_REQUEST so the script accepts either post or get variables.

		if(empty($_REQUEST['scrib_iii-sourceprefix']) || empty($_REQUEST['scrib_iii-sourceinnopac']) || empty($_REQUEST['scrib_iii-record_start'])){
			echo '<p>'.__('Sorry, there has been an error.').'</p>';
			echo '<p><strong>Please complete all fields</strong></p>';
			return;
		}

		// save these settings so we can try them again later
		$prefs = get_option('scrib_iiiimporter');
		$prefs['scrib_iii-record_start'] = (int) $_REQUEST['scrib_iii-record_start'];
		$prefs['scrib_iii-record_end'] = (int) $_REQUEST['scrib_iii-record_end'];
		update_option('scrib_iiiimporter', $prefs);

		$interval = 25;
		if( !$prefs['scrib_iii-record_end'] || ( $prefs['scrib_iii-record_end'] == $prefs['scrib_iii-record_start'] ))
			$_REQUEST['scrib_iii-debug'] = TRUE;
		if( !$prefs['scrib_iii-record_end'] || ( $prefs['scrib_iii-record_end'] - $prefs['scrib_iii-record_start'] < $interval ))
			$interval = $prefs['scrib_iii-record_end'] - $prefs['scrib_iii-record_start'];
		if( $prefs['scrib_iii-record_end'] - $prefs['scrib_iii-record_start'] < 1 )
			$interval = 0;

		ini_set('memory_limit', '1024M');
		set_time_limit(0);
		ignore_user_abort(TRUE);
		error_reporting(E_ERROR);

		if( !empty( $_REQUEST['scrib_iii-debug'] )){

			$host =  $prefs['scrib_iii-sourceinnopac'];
			$bibn = (int) $prefs['scrib_iii-record_start'];

			echo '<h3>The III Record:</h3><pre>';			
			echo $this->iii_get_record($host, $bibn);
			echo '</pre><h3>The Tags and Display Record:</h3><pre>';

			$test_pancake = $this->iii_parse_record($this->iii_get_record($host, $bibn), $bibn);
			print_r($test_pancake);
			echo '</pre>';
			
			echo '<h3>The Raw Excerpt:</h3>'. $scrib_import->the_excerpt( $test_pancake ) .'<br /><br />';
			echo '<h3>The Raw Content:</h3>'. $scrib_import->the_content( $test_pancake ) .'<br /><br />';
			echo '<h3>The SourceID: '. $test_pancake['the_sourceid'] .'</h3>';
			
			// bring back that form
			echo '<h2>'.__('III Options').'</h2>';
			$this->iii_options();
		
		}else{
			// import with status
			$host =  ereg_replace('[^a-z|A-Z|0-9|-|\.]', '', $_REQUEST['scrib_iii-sourceinnopac']);

			$count = 0;
			echo "<p>Reading a batch of $interval records from {$prefs['scrib_iii-sourceinnopac']}. Please be patient.<br /><br /></p>";
			echo '<ol>';
			for($bibn = $prefs['scrib_iii-record_start'] ; $bibn < ($prefs['scrib_iii-record_start'] + $interval) ; $bibn++ ){
				if($record = $this->iii_get_record( $host , $bibn )){
					$bibr = $this->iii_parse_record( $record , $bibn );
					echo "<li>{$bibr['the_title']} {$bibr['the_sourceid']}</li>";
					$count++;
				}
			}
			echo '</ol>';
			
			$prefs['scrib_iii-warnings'] = array_merge($prefs['scrib_iii-warnings'], $this->warn);
			$prefs['scrib_iii-errors'] = array_merge($prefs['scrib_iii-errors'], $this->error);
			$prefs['scrib_iii-records_harvested'] = $prefs['scrib_iii-records_harvested'] + $count;
			update_option('scrib_iiiimporter', $prefs);

			if( $bibn < $prefs['scrib_iii-record_end'] ){
				$prefs['scrib_iii-record_start'] = $prefs['scrib_iii-record_start'] + $interval;
				update_option('scrib_iiiimporter', $prefs);

				$this->iii_options( $prefs['scrib_iii-record_start'], $prefs['scrib_iii-record_end'] );
				?>
				<div class="narrow"><p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="javascript:nextpage()"><?php _e("Next Records"); ?></a> </p>
				<script language='javascript'>
				<!--
	
				function nextpage() {
					document.getElementById('myform').submit();
				}
				setTimeout( "nextpage()", 1250 );
	
				//-->
				</script>
				</div>
<?php
				echo '<pre>';
				print_r( $wpdb->queries );
				echo '<br /><br />';
				print_r( $scrib_import->queries );
				echo '</pre>';
				?><?php echo get_num_queries(); ?> queries. <?php timer_stop(1); ?> seconds. <?php
			}else{
				$this->iii_done();
				?>
				<script language='javascript'>
				<!--
					window.location='#complete';
				//-->
				</script>
				</div>
				<?php
			}
		}
	}

	function iii_get_record($host, $bibn){
		$prefs = get_option('scrib_iiiimporter');


		// get the regular web-view of the record and 
		// see if it matches the require/reject preferences
		$test_record = file_get_contents('http://'. $host .'/record=b'. $bibn);

		if( $prefs['scrib_iii-require'] && !strpos( $test_record, $prefs['scrib_iii-require'] ))
			return(FALSE);

		if( $prefs['scrib_iii-reject'] && strpos( $test_record, $prefs['scrib_iii-reject'] ))
			return(FALSE);


		// now get the MARC view of the record
		$recordurl = 'http://'. $host .'/search/.b'. $bibn .'/.b'. $bibn .'/1%2C1%2C1%2CB/marc~b'. $bibn;

//note to HKUST: Added an option to enabled utf8 encoding
		if( $prefs['scrib_iii-convert_encoding'] && function_exists( 'mb_convert_encoding' ))
			$record = mb_convert_encoding( file_get_contents( $recordurl ), 'UTF-8', 'LATIN1, ASCII, ISO-8859-1, UTF-8');
		else
			$record = file_get_contents($recordurl);

		if(!empty($record)){
			preg_match('/<pre>([^<]*)/', $record, $stuff);
//Start HKUST Customization
			//Create Tag 999
			$strline = '';

			//Check exists of ERM resources
			$matchcount=preg_match('/<!-- BEGIN ERM RESOURCE TABLE -->/', $record, $stuffdummy1);
			if ($matchcount>0) {
				$strline .= "|fE-Resource|lONLINE RESOURCE";
			}

			//Capture Item Locations
			//e.g. "<!-- field 1 -->&nbsp; <a href="http://library.ust.hk/info/maps/blink/1f-archive.html">UNIVERSITY ARCHIVES</a>"
			$matchcount = preg_match_all( '/<!-- field 1 -->.*>(.+)</', $record, $matches, PREG_SET_ORDER );
			if ( 0 < $matchcount ) {
				foreach( $matches as $match ){
					$strline .= '|l'.strtoupper( $match[1] );
				}
			}

			if ( strlen( $strline ))
				return( $stuff[1].'999    '. $strline ."\n");
			else
				return( $stuff[1] );
//End HKUST Customization
		}
		$this->error = 'Host unreachable or no parsable data found for record number '. $bibn .'.';
		return( FALSE );
	}

	function iii_done(){
		$prefs = get_option('scrib_iiiimporter');

		// click next
		echo '<div class="narrow">';

		if(count($prefs['scrib_iii-warnings'])){
			echo '<h3 id="warnings">Warnings</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#errors">errors</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_iii-warnings'], '</li><li>');
			echo '</li></ol>';
		}

		if(count($prefs['scrib_iii-errors'])){
			echo '<h3 id="errors">Errors</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#warnings">warnings</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_iii-errors'], '</li><li>');
			echo '</li></ol>';
		}		

		echo '<h3 id="complete">'.__('Processing complete.').'</h3>';
		echo '<p>'. $prefs['scrib_iii-records_harvested'] .' '.__('records harvested.').' with '. count($prefs['scrib_iii-warnings']) .' <a href="#warnings">warnings</a> and '. count($prefs['scrib_iii-errors']) .' <a href="#errors">errors</a>.</p>';

		echo '<p>'.__('Continue to the next step to publish those harvested catalog entries.').'</p>';

		echo '<form action="admin.php?import=scribimporter&amp;step=3" method="post">';
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Publish Harvested Records &raquo;').'" /> <br />'. __('(goes to default Scriblio importer)').'</p>';
		echo '</form>';

		echo '</div>';
	}


	function iii_clean($input){
//$str = preg_replace('/\s\s+/', ' ', $str);
//Start HKUST Customization
//disable this str_replace, as it drastically removed the two
//indicators if they both are blank.
//		while(strpos($input, '  '))
//			$input = str_replace('  ', ' ', $input);
//End HKUST Customization
		return($input);
	}

	function iii_parse_row($lineray){
		$marcrow = array();
		unset($lineray[0]);
		foreach($lineray as $element){
			$count[$element{0}]++;
			$elementname = $element{0}.$count[$element{0}];
			$marcrow[$elementname] = trim($this->iii_clean(substr($element, 1)));
		}
		return($marcrow);
	}

	function iii_parse_record($marcrecord, $bibn){
		global $scrib, $scrib_import;
		$prefs = get_option('scrib_iiiimporter');
	
		$atomic = array();
		
		$marcrecord = str_replace("\n       ", ' ', $marcrecord);
		
		$details = explode("\n", $marcrecord);
		array_pop($details);
		array_shift($details);

		$details[0] = str_replace('LEADER ', '000    ', $details[0]);
		foreach($details as $line){		
			unset($lineray);
			unset($marc);

			$line = trim($line);

//Start HKUST Customization
//			$lineray = trim($this->iii_clean(substr($line, 0, 3) . '|' . substr($line, 4, 2) . '|a' . substr($line, 7)));

			//handle romanized tags with subfield 6 - to avoid using it as the main entry, so that 880 data is used instead
			$line = preg_replace('/^245(.*?\|6880-)/', '246\\1', $line);
			$line = preg_replace('/^1(\d\d.*?\|6880-)/', '7\\1', $line);
			$line = preg_replace('/^250(.*?\|6880-)/', '950\\1', $line);
			$line = preg_replace('/^260(.*?\|6880-)/', '960\\1', $line);

			//handle 880 tags with subfield 6
			$line = preg_replace('/^880(.*?)\|6(\d\d\d)-/', '\\2\\1|6880-', $line);

			//Remove subfield 6 containing "880-.."
			$line = preg_replace('/\|6880-.*?\|/', '|', $line);

			//Remove the extra space in $line in front of the first subfield delimiter
			$line = preg_replace('/^.{7} /', '\\1', $line);
                        //Insert subfield delimiter and subfield code "a" if it is not present - for non-00X tags
			$line = preg_replace('/^([0][1-9]\d.{4})([^\|])/', '\\1|a\\2', $line);
			$line = preg_replace('/^([1-9]\d{2}.{4})([^\|])/', '\\1|a\\2', $line);

                        //Construct $lineray
			if (substr($line,7,1)=="|") {
				$lineray = substr($line, 0, 3) . '|' . substr($line, 4, 2) . substr($line, 7);
                        }else{
				$lineray = substr($line, 0, 3) . '|' . substr($line, 4, 2) . '|a' . substr($line, 7);
			}
//End HKUST Customization

			$lineray = explode('|', ereg_replace('\.$', '', $lineray));
			unset($lineray[1]);

			if($lineray[0] > 99)
//Start HKUST Customization
//				$line = trim($this->iii_clean(substr($line, 7)));
				$line = trim($this->iii_clean($line));
//End HKUST Customization
			
			// Authors
//Start HKUST Customization
/*
			if(($lineray[0] == 100) || ($lineray[0] == 110)){
				$marc = $this->iii_parse_row($lineray);
				$temp = ereg_replace(',$', '', $marc['a1'] .' '. $marc['d1']);
				$atomic['author'][] = $temp;
			}else if($lineray[0] == 110){
				$marc = $this->iii_parse_row($lineray);
				$temp = $marc['a1'];
				$atomic['author'][] = $temp;
			}else if(($lineray[0] > 699) && ($lineray[0] < 721)){
				$marc = $this->iii_parse_row($lineray);
				$temp = ereg_replace(',$', '', $marc['a1'] .' '. $marc['d1']);
				$atomic['author'][] = $temp;
*/
			if(($lineray[0] == 100) || ($lineray[0] == 700) ||
			   ($lineray[0] == 110) || ($lineray[0] == 710) ||
			   ($lineray[0] == 111) || ($lineray[0] == 711)){
				$marc = $this->iii_parse_row($lineray);
				$temp = $marc['a1'];
				if(($lineray[0] == 100) || ($lineray[0] == 700)){
					if ($marc['d1']) {
						$temp .= ' ' . $marc['d1'];
					}
				}else if(($lineray[0] == 110) || ($lineray[0] == 710)){
					if ($marc['b1']) {
						$temp .= ' ' . $marc['b1'];
					}
				}else if(($lineray[0] == 111) || ($lineray[0] == 711)){
					if ($marc['n1']) {
						$temp .= ' ' . $marc['n1'];
					}
					if ($marc['d1']) {
						$temp .= ' ' . $marc['d1'];
					}
					if ($marc['c1']) {
						$temp .= ' ' . $marc['c1'];
					}
				}
				$temp = ereg_replace('[,|\.]$', '', $temp);
				$atomic['author'][] = $temp;

				//handle title in name
				$temp = '';
				if ($marc['t1']) {
					$temp .= ' ' . $marc['t1'];
				}
				if ($marc['n1']) {
					$temp .= ' ' . $marc['n1'];
				}
				if ($marc['p1']) {
					$temp .= ' ' . $marc['p1'];
				}
				if ($marc['l1']) {
					$temp .= ' ' . $marc['l1'];
				}
				if ($marc['k1']) {
					$temp .= ' ' . $marc['k1'];
				}
				if ($marc['f1']) {
					$temp .= ' ' . $marc['f1'];
				}
				$temp = ereg_replace('[,|\.]$', '', $temp);
				if (strlen($temp) >0) {
					$atomic['alttitle'][] = $temp;
				}
//End HKUST Customization

			//Standard Numbers
			}else if($lineray[0] == 10){
				$marc = $this->iii_parse_row($lineray);
				$atomic['lccn'][] = $marc['a1'];
				$atomic['bibkeys'][] = array( 'lccn' => $marc['a1'] );
			}else if($lineray[0] == 20){
				$marc = $this->iii_parse_row($lineray);
				$temp = trim($marc['a1']) . ' ';
				$temp = ereg_replace('[^0-9|x|X]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
				$atomic['isbn'][] = $temp;
				$atomic['bibkeys'][] = array( 'isbn' => $temp );
			}else if($lineray[0] == 22){
				$marc = $this->iii_parse_row($lineray);
				$temp = trim($marc['a1']) . ' ';
				$temp = ereg_replace('[^0-9|x|X|\-]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
				$atomic['issn'][] = $temp;
				$temp = trim($marc['y1']) . ' ';
				$temp = ereg_replace('[^0-9|x|X|\-]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
				$atomic['issn'][] = $temp;
				$temp = trim($marc['z1']) . ' ';
				$temp = ereg_replace('[^0-9|x|X|\-]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
				$atomic['issn'][] = $temp;
				$atomic['issn'] = array_filter( $atomic['issn'] );
			
			//Titles
			}else if($lineray[0] == 245){
				$marc = $this->iii_parse_row($lineray);
//Start HKUST Customization
//				$temp = ucwords(trim(ereg_replace('/$', '', $marc['a1']) .' '. trim(ereg_replace('/$', '', $marc['b1']))));
				$temp = trim(ereg_replace('/$', '', $marc['a1']) .' '. trim(ereg_replace('/$', '', $marc['b1']) .' '. trim(ereg_replace('/$', '', $marc['n1']) .' '. trim(ereg_replace('/$', '', $marc['p1'])))));
//End HKUST Customization
				$atomic['title'][] = $temp;
				$atomic['attribution'][] = $marc['c1'];
			}else if($lineray[0] == 240){
				$marc = $this->iii_parse_row($lineray);
//Start HKUST Customization
//				$temp = trim(ereg_replace('/$', '', $marc['a1'] .' '. $marc['b1']));
//				$atomic['alttitle'][] = $temp;
				$atomic['alttitle'][] = implode(' ', array_values($marc));
//End HKUST Customization
			}else if($lineray[0] == 246){
				$marc = $this->iii_parse_row($lineray);
//Start HKUST Customization
//				$temp = trim(ereg_replace('/$', '', $marc['a1'] .' '. $marc['b1']));
				$temp = trim(ereg_replace('/$', '', $marc['a1']) .' '. trim(ereg_replace('/$', '', $marc['b1']) .' '. trim(ereg_replace('/$', '', $marc['n1']) .' '. trim(ereg_replace('/$', '', $marc['p1'])))));
//End HKUST Customization
				$atomic['alttitle'][] = $temp;
			}else if(($lineray[0] > 719) && ($lineray[0] < 741)){
				$marc = $this->iii_parse_row($lineray);
				$temp = $marc['a1'];
//Start HKUST Customization
//				$atomic['alttitle'][] = $marc['a1'];
				if ($marc['n1']) {
					$temp .= ' ' .$marc['n1'];
				}
				if ($marc['p1']) {
					$temp .= ' ' . $marc['p1'];
				}
				$temp = ereg_replace('[,|\.|;]$', '', $temp);
				if (strlen($temp) >0) {
                                        $atomic['alttitle'][] = $temp;
                                }
//End HKUST Customization

//Start HKUST Customization
				//Edition
				}else if($lineray[0] == 250){
					$marc = $this->iii_parse_row($lineray);
				$atomic['edition'][] = implode(' ', $marc);
//End HKUST Customization

			//Dates and Publisher
			}else if($lineray[0] == 260){
				$marc = $this->iii_parse_row($lineray);
//Start HKUST Customization
				if($marc['b1']){
					$atomic['publisher'][] = $scrib_import->strip_punct($marc['b1']);
				}
//End HKUST Customization

				if($marc['c1']){
//Start HKUST Customization
//					$temp = str_pad(substr(ereg_replace('[^0-9]', '', $marc['c1']), 0, 4), 4 , '5');
//					if( $temp < date('Y') + 2 )
//						$atomic['pubyear'][] = $temp;

					$temp ="";
					//match for year pattern, such as "1997"
					$matchcount=preg_match('/(\d\d\d\d)/',$marc['c1'], $matches);
					if ($matchcount>0) {
						$temp = $matches[1];
					}else {
						//match for mingguo year pattern  (in traditional chinese character)
						$matchcount=preg_match('/\xE6\xB0\x91\xE5\x9C\x8B(\d{2})/',$marc['c1'], $matches);
						if ($matchcount>0) {
							$temp = strval(intval($matches[1])+1911);
						} else {
							//match for mingguo year pattern (in simplified chinese character)
							$matchcount=preg_match('/\xE6\xB0\x91\xE5\x9B\xBD(\d{2})/',$marc['c1'], $matches);
							if ($matchcount>0) {
								$temp = strval(intval($matches[1])+1911);
							}
						}
					}
					if ($temp) {
						$atomic['pubyear'][] = $temp;
						if(!$atomic['pyear'][0])
							$atomic['pyear'][] = $temp;
					}
//End HKUST Customization
				}
			}else if($lineray[0] == 5){
				$atomic['acqdate'][] = $line{7}.$line{8}.$line{9}.$line{10} .'-'. $line{11}.$line{12} .'-'. $line{13}.$line{14};
			}else if($lineray[0] == 8){
				$temp = intval(substr($line, 14, 4));
				if($temp)
//Start HKUST Customization
//					$atomic['pubyear'][] = substr($line, 14, 4);
					$atomic['pubyear'][] = preg_replace('/[^\d]/', '0' ,substr($line, 14, 4));
//End HKUST Customization
			
			//Subjects
			// tag 655 - Genre
			}else if($lineray[0] == '655'){
				$marc = $this->iii_parse_row($lineray);
				$atomic['genre'][] = $marc['a1'];
			// everything else
			}else if(($lineray[0] > 599) && ($lineray[0] < 700)){
				$marc = $this->iii_parse_row($lineray);
				$atomic['subject'][] = implode(' -- ', $marc);
				if($atomic['subjkey']){
					$atomic['subjkey'] = array_unique(array_merge($atomic['subjkey'], array_values($marc)));
				}else{
					$atomic['subjkey'] = array_values($marc);
				}	
		
			//URLs
			}else if($lineray[0] == 856){
				$marc = $this->iii_parse_row($lineray);
				unset($temp);
				$temp['href'] = $temp['title'] = str_replace(' ', '', $marc['u1']);
				$temp['title'] = trim(parse_url( $temp['href'] , PHP_URL_HOST), 'www.');
				if($marc['31'])
					$temp['title'] = $marc['31'];
				if($marc['z1'])
					$temp['title'] = $marc['z1'];
				$atomic['url'][] = '<a href="'. $temp['href'] .'">'. $temp['title'] .'</a>';
		
			//Notes
			}else if(($lineray[0] > 299) && ($lineray[0] < 400)){
				$marc = $this->iii_parse_row($lineray);
				$atomic['physdesc'][] = implode(' ', array_values($marc));
//Start HKUST Customization
//			}else if(($lineray[0] > 399) && ($lineray[0] < 500)){
			}else if(($lineray[0] > 399) && ($lineray[0] < 490)){
//End HKUST Customization
				$marc = $this->iii_parse_row($lineray);
//Start HKUST Customization
//				$atomic['title'][] = implode("\n", array_values($marc));
				$atomic['series'][] = implode(" ", array_values($marc));
//End HKUST Customization
			}else if(($lineray[0] > 799) && ($lineray[0] < 841)){
				$marc = $this->iii_parse_row($lineray);
				$atomic['series'][] = implode(" ", array_values($marc));
			}else if(($lineray[0] > 499) && ($lineray[0] < 600)){
//Start HKUST Customization
//				$line = substr($line, 7);
				$line = substr($line, 9);
//End HKUST Customization
				if($lineray[0] == 504)
					continue;
				if($lineray[0] == 505){
					$atomic['contents'][] = str_replace(array('> ','>  ','>   '), '>', '<li>'. str_replace('--', "</li>\n<li>", trim(str_replace(array('|t', '|r'), '', $line))) .'</li>');
					continue;
				}
//Start HKUST Customization
				//strip the subfield delimiter and codes
				$line = preg_replace('/\|[0-9|a-z]/', ' ', $line);
//End HKUST Customization
				$atomic['notes'][] = $line;
			}
			
			//Format
			if((!$atomic['format']) && ($lineray[0] > 239) && ($lineray[0] < 246)){
				$marc = $this->iii_parse_row($lineray);
				$temp = ucwords(strtolower(str_replace('[', '', str_replace(']', '', $marc['h1']))));
				
				if(eregi('^book', $temp)){
					$format = 'Book';
					$formats = 'Books';

				}else if(eregi('^micr', $temp)){
					$format = 'Microform';

				}else if(eregi('^electr', $temp)){
//Start HKUST Customization
//					$format = 'Website';
//					$formats = 'Websites';
					$format = 'E-Resource';
					$formats = 'E-Resources';
//End HKUST Customization

				}else if(eregi('^vid', $temp)){
					$format = 'Video';
				}else if(eregi('^motion', $temp)){
					$format = 'Video';

				}else if(eregi('^audi', $temp)){
					$format = 'Audio';
				}else if(eregi('^cass', $temp)){
					$format = 'Audio';
				}else if(eregi('^phono', $temp)){
					$format = 'Audio';
				}else if(eregi('^record', $temp)){
					$format = 'Audio';
				}else if(eregi('^sound', $temp)){
					$format = 'Audio';

				}else if(eregi('^carto', $temp)){
					$format = 'Map';
					$formats = 'Maps';
				}else if(eregi('^map', $temp)){
					$format = 'Map';
					$formats = 'Maps';
				}else if(eregi('^globe', $temp)){
					$format = 'Map';
					$formats = 'Maps';

				}else if($temp){
					$format = 'Classroom Material';
					//$format = $temp;
				}

				if(!$formats)
					$formats = $format;
				
				if($format){
					$atomic['format'][] = $format;
					$atomic['formats'][] = $formats;
				}
			}

//Start HKUST Customization
//			if($lineray[0] == '008' && substr($lineray[2], 22,1) == 'p'){
			if($lineray[0] == '008' && (substr($lineray[2], 22,1) == 'p' || substr($lineray[2], 22,1) == 'n')){
//End HKUST Customization
				$atomic['format'][] = 'Journal';
				$atomic['formats'][] = 'Journals';
			}

//Start HKUST Customization
			// Handle tag 999 - for locations and formats
			if ($lineray[0] == '999'){
                                $marc = $this->iii_parse_row($lineray);
				foreach($marc as $key=>$subfield){
					if (substr($key,0,1)=='l') {
						$atomic['loc'][] = $subfield;
					} else if (substr($key,0,1)=='f') {
						if(!$atomic['format'][0]){
							$atomic['format'][0] = 'Book';
							$atomic['formats'][0] = 'Book';
						}
                                               	$atomic['format'][] = $subfield;
                                                $atomic['formats'][] = $subfield;
                                        }
                                }
				$atomic['loc']=array_unique($atomic['loc']);
				$atomic['format']=array_unique($atomic['format']);
				$atomic['formats']=array_unique($atomic['formats']);
			}
//End HKUST Customization

		}
		// end the big loop



		// Records without acqdates are reserves by course/professor
		// we _can_ import them, but they don't have enough info
		// to be findable or display well.
		if(!$atomic['acqdate'][0] && !$atomic['author'][0]){
			$this->warn = 'Record number '. $bibn .' contains no catalog date or author info, skipped.';
			return(FALSE);
		}
		if(count($atomic) < 4){
			$this->warn = 'Record number '. $bibn .' has too little cataloging data, skipped.';
			return(FALSE);
		}

		// sanity check the acqdate
		$atomic['acqdate'] = array_unique($atomic['acqdate']);
		foreach( $atomic['acqdate'] as $key => $temp )
			if( strtotime( $temp ) > strtotime( date('Y') + 2 ))
				unset( $atomic['acqdate'][$key] );
		$atomic['acqdate'] = array_values( $atomic['acqdate'] );

		// sanity check the pubyear
		$atomic['pubyear'] = array_unique($atomic['pubyear']);
		foreach( $atomic['pubyear'] as $key => $temp )
			if( $temp > date('Y') + 2 )
				unset( $atomic['pubyear'][$key] );
		$atomic['pubyear'] = array_values( $atomic['pubyear'] );

		if( empty( $atomic['pubyear'][0] ))
			if( !empty( $atomic['acqdate'][0] ))
				$atomic['pubyear'][0] = substr( $atomic['acqdate'][0], 0, 4 );
			else
				$atomic['pubyear'][0] = date('Y') - 1;


//Start HKUST Customization
//		if(empty($atomic['pubyear'][0]))
//			$atomic['pubyear'][0] = '1990';

		if(!$atomic['acqdate'][0])
			$atomic['acqdate'][0] = $atomic['pubyear'][0].'-01-01';

		if(!$atomic['catdate'][0])
			$atomic['catdate'][0] = $atomic['pubyear'][0].'-01-01';
//End HKUST Customization


		$atomic = array_filter($atomic);

		if(!$atomic['format'][0])
			$atomic['format'][0] = 'Book';
	
		if($atomic['pubyear'][0])
			$atomic['pubdate'][0] = $atomic['pubyear'][0].substr($atomic['acqdate'][0],4);

		if($atomic['alttitle'])
			$atomic['title'] = array_unique(array_merge($atomic['title'], $atomic['alttitle']));

//Start HKUST Customization
		if($atomic['series'])
			$atomic['title'] = array_unique( array_merge( $atomic['title'], $atomic['series'] ));
//End HKUST Customization
		
		$atomic['the_sourceid'] = substr(ereg_replace('[^a-z|0-9]', '', strtolower($_REQUEST['scrib_iii-sourceprefix'])), 0, 2) . $bibn;

		if( $sweets = $scrib_import->get_sweets( $atomic['bibkeys'], $atomic['title'][0], $atomic['attribution'][0], $atomic['the_sourceid'] )){

//print_r( $sweets );

			foreach( $sweets->isbn as $temp)
				$atomic['isbn'][] = $temp;
			$atomic['isbn'] = array_unique( $atomic['isbn'] );

			foreach( $sweets->lccn as $temp)
				$atomic['lccn'][] = $temp;
			$atomic['lccn'] = array_unique( $atomic['lccn'] );

			foreach( $sweets->gbsid as $temp)
				$atomic['gbsid'][] = $temp;

			foreach( $sweets->olid as $temp)
				$atomic['olid'][] = $temp;


			foreach( $sweets->olrecord as $olrecord)
				foreach( $olrecord->subject_place as $subject_place)
					$atomic['place'][] = $subject_place;

			foreach( $sweets->geotag_places as $temp)
				$atomic['place'][] = $temp;
			array_unique( $atomic['place'] );

			foreach( $sweets->geotag_countries as $temp)
				$atomic['country'][] = $temp;

			if( !empty($sweets->image->thumb ))
				$atomic['img'] = $sweets->image;
			if( !empty($sweets->summary ))
				$atomic['shortdescription'] = $sweets->summary[0] ;
		}

		if( $prefs['scrib_iii-capitalize_titles'] )
			$atomic['title'] = array_map( 'ucwords', $atomic['title'] );

		if(!empty($atomic['title']) && !empty($atomic['the_sourceid'])){
			$atomic['tags']['subj'] = $atomic['subjkey'];
			$atomic['tags']['genre'] = $atomic['genre'];
			$atomic['tags']['place'] = $atomic['place'];
			$atomic['tags']['country'] = $atomic['country'];
			$atomic['tags']['auth'] = $atomic['author'];

			$atomic['tags']['isbn'] = $atomic['isbn'];
			$atomic['tags']['issn'] = $atomic['issn'];
			$atomic['tags']['loc'] = $atomic['loc'];
			$atomic['tags']['gbsid'] = $atomic['gbsid'];

			$atomic['tags']['title'] = $atomic['title'];
			$atomic['tags']['format'] = $atomic['format'];
			$atomic['tags']['pubyear'] = $atomic['pubyear'];

			$atomic['the_title'] = $atomic['title'][0];
			$atomic['the_pubdate'] = $atomic['pubdate'][0];
			$atomic['the_acqdate'] = $atomic['acqdate'][0];
			$atomic['the_category'] = (int) $scrib->options['catalog_category_id'];


//Start HKUST Customization
// note to HKUST: nicely done.
			//strip leading and trailing punctuations of values of facets
			foreach ( $atomic['tags'] as $ak => $av )
				foreach ( $av as $bk => $bv )
					$atomic['tags'][$ak][$bk] = $scrib_import->strip_punct( $bv );
//End HKUST Customization

	
//			$atomic['the_excerpt'] = $scrib_import->the_excerpt($atomic);
//			$atomic['the_content'] = $scrib_import->the_content($atomic);

			$scrib_import->insert_harvest($atomic);
			return($atomic);
		}else{
			$this->error = 'Record number '. $bibn .' couldn&#039;t be parsed.';
			return(FALSE);
		}

	}

//Start HKUST Customization
	//strip leading and trailing ascii punctuations
	//function moved to $scrib_import->strip_punct() -- Casey
//End HKUST Customization

	// Default constructor 
	function ScribIII_import() {
		// nothing
	} 
} 

// Instantiate and register the importer 
include_once(ABSPATH . 'wp-admin/includes/import.php'); 
if(function_exists('register_importer')) { 
	$scribiii_import = new ScribIII_import(); 
	register_importer($scribiii_import->importer_code, $scribiii_import->importer_name, $scribiii_import->importer_desc, array (&$scribiii_import, 'dispatch')); 
} 

add_action('activate_'.plugin_basename(__FILE__), 'scribiii_importer_activate'); 

function scribiii_importer_activate() { 
	global $wp_db_version, $scribiii_import; 
	 
	// Deactivate on pre 2.3 blogs 
	if($wp_db_version<6075) { 
		$current = get_settings('active_plugins'); 
		array_splice($current, array_search( plugin_basename(__FILE__), $current), 1 ); 
		update_option('active_plugins', $current); 
		do_action('deactivate_'.plugin_basename(__FILE__));		 
		return(FALSE);
	}
} 

?>