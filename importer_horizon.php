<?php 
/*
Plugin Name: Scriblio Horizon Catalog Importer
Plugin URI: http://about.scriblio.net/
Description: Imports catalog content directly from a Horizon web OPAC, no MaRC export/import needed.
Version: .01
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

// The importer 
class ScribHorizon_import { 
	var $importer_code = 'scribimporter_horizon'; 
	var $importer_name = 'Scriblio Horizon Catalog Importer'; 
	var $importer_desc = 'Imports catalog content directly from a Horizon web OPAC, no MaRC export/import needed. <a href="http://about.scriblio.net/wiki/">Documentation here</a>.'; 
	 
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
				$this->horizon_start(); 
				break;
			case 2:
				$this->horizon_getrecords(); 
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
		echo '<h2>'.__('Scriblio Horizon Importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! Start here to import records from a Horizon ILS system into Scriblio.').'</p>';
		echo '<p>'.__('This has not been tested much. Mileage may vary.').'</p>';

		echo '<form action="admin.php?import='. $this->importer_code .'&amp;step=1" method="post">';
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';

		if(!function_exists('mb_convert_encoding')){
			echo '<br /><br />';	
			echo '<p>'.__('This PHP install does not support <a href="http://php.net/manual/en/ref.mbstring.php">multibyte string functions</a>, including <a href="http://php.net/mb_convert_encoding">mb_convert_encoding</a>. Without that function, this importer can&#039;t convert the character encoding from records in the ILS into UTF-8. Accented characters will likely not import correctly.').'</p>';
		}

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

	function horizon_start(){
		$prefs = get_option('scrib_horizonimporter');
		$temp['scrib_horizon-sourceprefix'] = $prefs['scrib_horizon-sourceprefix'];
		$temp['scrib_horizon-sourceipac'] = $prefs['scrib_horizon-sourceipac'];
		$temp['scrib_horizon-warnings'] = array();
		$temp['scrib_horizon-errors'] = array();
		$temp['scrib_horizon-record_start'] = $prefs['scrib_horizon-record_start'];
		$temp['scrib_horizon-record_end'] = '';
		$temp['scrib_horizon-records_harvested'] = 0;
		update_option('scrib_horizonimporter', $temp);

		$this->horizon_options();
	}

	function horizon_options(){
	
		$prefs = get_option('scrib_horizonimporter');

		echo '<div class="narrow">';

		echo '<p>'.__('All Scriblio records have a &#039;sourceid,&#039; a unique alphanumeric string that&#039;s used to avoid creating duplicate records and, in some installations, link back to the source system for current availability information.').'</p>';
		echo '<p>'.__('The sourceid is made up of two parts: the prefix that you assign, and the bib number from the iPAC. Theoretically, you chould gather records from 1,296 different systems, it&#039;s a big world.').'</p>';

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;step=2" method="post">';
?>
<p><label for="scrib_horizon-sourceipac">The iPac hostname:<br /><input type="text" name="scrib_horizon-sourceipac" id="scrib_horizon-sourceipac" value="<?php echo attribute_escape( $prefs['scrib_horizon-sourceipac'] ); ?>" /><br />example: lola.plymouth.edu</label></p>
<p><label for="scrib_horizon-sourceprefix">The source prefix:<br /><input type="text" name="scrib_horizon-sourceprefix" id="scrib_horizon-sourceprefix" value="<?php echo attribute_escape( $prefs['scrib_horizon-sourceprefix'] ); ?>" /><br />example: bb (must be two characters, a-z and 0-9 accepted)</label></p>
<p><label for="scrib_horizon-record_start">Start with bib number:<br /><input type="text" name="scrib_horizon-record_start" id="scrib_horizon-record_start" value="<?php echo attribute_escape( $prefs['scrib_horizon-record_start'] ); ?>" /></label></p>
<p><label for="scrib_horizon-record_end">End:<br /><input type="text" name="scrib_horizon-record_end" id="scrib_horizon-record_end" value="<?php echo attribute_escape( $prefs['scrib_horizon-record_end'] ); ?>" /></label></p>
<p><br /><label for="scrib_horizon-debug"><input type="checkbox" name="scrib_horizon-debug" id="scrib_horizon-debug" value="1" /> Turn on debug mode.</label></p>
<?php
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';
		echo '</div>';
	}

	function horizon_getrecords(){
		if(empty($_POST['scrib_horizon-sourceprefix']) || empty($_POST['scrib_horizon-sourceipac']) || empty($_POST['scrib_horizon-record_start'])){
			echo '<p>'.__('Sorry, there has been an error.').'</p>';
			echo '<p><strong>Please complete all fields</strong></p>';
			return;
		}

		// save these settings so we can try them again later
		$prefs = get_option('scrib_horizonimporter');
		$prefs['scrib_horizon-sourceprefix'] = strtolower(ereg_replace('[^a-z|A-Z|0-9]', '', $_POST['scrib_horizon-sourceprefix']));
		stripslashes($_POST['scrib_horizon-sourceprefix']);
		$prefs['scrib_horizon-sourceipac'] = ereg_replace('[^a-z|A-Z|0-9|-|\.]', '', $_POST['scrib_horizon-sourceipac']);
		$prefs['scrib_horizon-record_start'] = (int) $_POST['scrib_horizon-record_start'];
		$prefs['scrib_horizon-record_end'] = (int) $_POST['scrib_horizon-record_end'];
		update_option('scrib_horizonimporter', $prefs);

		$interval = 50;
		if(!$prefs['scrib_horizon-record_end'] || ($prefs['scrib_horizon-record_end'] - $prefs['scrib_horizon-record_start'] < $interval))
			$interval = $prefs['scrib_horizon-record_end'] - $prefs['scrib_horizon-record_start'];
		if($prefs['scrib_horizon-record_end'] - $prefs['scrib_horizon-record_start'] < 1)
			$interval = 1;

		ini_set('memory_limit', '1024M');
		set_time_limit(0);
		ignore_user_abort(TRUE);

		error_reporting(E_ERROR);

		if(!empty($_POST['scrib_horizon-debug'])){

			$host =  $prefs['scrib_horizon-sourceipac'];
			$bibn = (int) $prefs['scrib_horizon-record_start'];

			echo '<h3>The Horizon Record:</h3><pre>';			
			echo $this->horizon_get_record($host, $bibn, TRUE);
			echo '</pre><h3>The Tags and Display Record:</h3><pre>';

			$test_pancake = $this->horizon_parse_record($this->horizon_get_record($host, $bibn), $bibn);
			print_r($test_pancake);
			echo '</pre>';
			
			echo '<h3>The Raw Excerpt:</h3>'. $test_pancake['the_excerpt'] .'<br /><br />';
			echo '<h3>The Raw Content:</h3>'. $test_pancake['the_content'] .'<br /><br />';
			echo '<h3>The SourceID: '. $test_pancake['the_sourceid'] .'</h3>';
			
			// bring back that form
			echo '<h2>'.__('Horizon Options').'</h2>';
			$this->horizon_options();
		
		}else{
			// import with status
			$host =  ereg_replace('[^a-z|A-Z|0-9|-|\.]', '', $_POST['scrib_horizon-sourceipac']);

			$count = 0;
			echo "<p>Reading $interval records from {$prefs['scrib_horizon-sourceipac']}. Please be patient.<br /><br /></p>";
			echo '<ol>';
			for($bibn = $prefs['scrib_horizon-record_start'] ; $bibn <= ($prefs['scrib_horizon-record_start'] + $interval) ; $bibn++ ){
				if($record = $this->horizon_get_record( $host , $bibn )){
					$bibr = $this->horizon_parse_record( $record , $bibn );
					echo "<li>{$bibr['the_title']} {$bibr['the_sourceid']}</li>";
					$count++;
				}
			}
			echo '</ol>';
			
			$prefs['scrib_horizon-warnings'] = array_merge($prefs['scrib_horizon-warnings'], $this->warn);
			$prefs['scrib_horizon-errors'] = array_merge($prefs['scrib_horizon-errors'], $this->error);
			$prefs['scrib_horizon-records_harvested'] = $prefs['scrib_horizon-records_harvested'] + $count;
			update_option('scrib_horizonimporter', $prefs);

			if($bibn < $prefs['scrib_horizon-record_end']){
				$prefs['scrib_horizon-record_start'] = $prefs['scrib_horizon-record_start'] + $interval;
				update_option('scrib_horizonimporter', $prefs);

				$this->horizon_options();
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
			}else{
				$this->horizon_done();
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

	function horizon_get_record($host, $bibn, $unparsed = FALSE){
		$recordurl = 'http://'. $host .'/ipac20/ipac.jsp?term='. $bibn .'&index=BIB&fullmarc=true';

		if(function_exists('mb_convert_encoding'))
			$scrape = mb_convert_encoding(file_get_contents($recordurl), 'UTF-8');
		else
			$scrape = file_get_contents($recordurl);

		if(strpos($scrape, 'Sorry, could not find anything matching')){
			$this->warn = 'Record number '. $bibn .' is suppressed or deleted.';
			return(FALSE);
		}

		if(!empty($scrape)){
			$parts = (explode('<form', $scrape));
			foreach($parts as $part)
				if(strpos($part, '>LDR:&nbsp;<')){
					$marc_record = array();
					$record_array = (explode('<tr>', strip_tags(str_replace(array('\n', '&nbsp;', '</tr>'), '', '<form '. $part), '<tr>')));
					
					if($unparsed)
						return(implode($record_array, "\n"));

					foreach($record_array as $line){
						if($line == 'LDR:')
							break;
						reset($record_array);
						array_shift($record_array);
					}

					$data = array();				
					while( !( reset( $record_array ) === FALSE ) ){
						$tagno = str_pad( (int) array_shift( $record_array ), 3, '0', STR_PAD_LEFT );
						$data = array_shift( $record_array );
						$data_parts = preg_split('/(\$[a-z|A-Z])/', $data , -1, PREG_SPLIT_DELIM_CAPTURE);


						$subfields = array();
						while( !( reset( $data_parts ) === FALSE ) ){
							if( preg_match( '/\$[a-z|A-Z]/', reset( $data_parts ) ) ){
								$subfields[substr( array_shift( $data_parts ), 1)][] = trim( array_shift( $data_parts ) );
							}else{
								array_shift( $data_parts );
							}
						}

						$marc_record[$tagno][] = array( 'tagno' => $tagno, 'subfields' => $subfields , 'data' => $data );
					}
					return($marc_record);
				}
			return(FALSE);
		}

		$this->error = 'Host unreachable or no parsable data found for record number '. $bibn .'.';
		return(FALSE);
	}

	function horizon_done(){
		$prefs = get_option('scrib_horizonimporter');

		// click next
		echo '<div class="narrow">';

		if(count($prefs['scrib_horizon-warnings'])){
			echo '<h3 id="warnings">Warnings</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#errors">errors</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_horizon-warnings'], '</li><li>');
			echo '</li></ol>';
		}

		if(count($prefs['scrib_horizon-errors'])){
			echo '<h3 id="errors">Errors</h3>';
			echo '<a href="#complete">bottom</a> &middot; <a href="#warnings">warnings</a>';
			echo '<ol><li>';
			echo implode($prefs['scrib_horizon-errors'], '</li><li>');
			echo '</li></ol>';
		}		

		echo '<h3 id="complete">'.__('Processing complete.').'</h3>';
		echo '<p>'. $prefs['scrib_horizon-records_harvested'] .' '.__('records harvested.').' with '. count($prefs['scrib_horizon-warnings']) .' <a href="#warnings">warnings</a> and '. count($prefs['scrib_horizon-errors']) .' <a href="#errors">errors</a>.</p>';

		echo '<p>'.__('Continue to the next step to publish those harvested catalog entries.').'</p>';

		echo '<form action="admin.php?import=scribimporter&amp;step=3" method="post">';
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Publish Harvested Records &raquo;').'" /> <br />'. __('(goes to default Scriblio importer)').'</p>';
		echo '</form>';

		echo '</div>';
	}

	function horizon_implode($input){
		return(array_filter(preg_split('/(\$[a-z|A-Z])/', substr($input, strpos($input, '$')))));
	}

	function horizon_parse_record($marcrecord, $bibn){
		global $scrib_import;

		// an anonymous callback function to trim arrays
		$trimmer = create_function('&$x', 'return ( trim ( trim ( trim ( $x ) , ",.;:/" ) ) );');

		array_shift($marcrecord);

		foreach($marcrecord as $fields){
			foreach($fields as $field){

				// Authors
				if(($field['tagno'] == 100) || ($field['tagno'] == 110)){
					$temp = ereg_replace(',$', '', $field['subfields']['a'][0] .' '. $field['subfields']['d'][0]);
					$atomic['author'][] = $temp;
				}else if($field['tagno'] == 110){
					$temp = $field['subfields']['a'][0];
					$atomic['author'][] = $temp;
				}else if(($field['tagno'] > 699) && ($field['tagno'] < 721)){
					$temp = ereg_replace(',$', '', $field['subfields']['a'][0] .' '. $field['subfields']['d'][0]);
					$atomic['author'][] = $temp;
				
				//Standard Numbers
				}else if($field['tagno'] == 10){
					$temp = explode(' ', trim($field['subfields']['a'][0]));
					$atomic['lccn'][] = ereg_replace('[^0-9]', '', $temp[0]);
				
				}else if($field['tagno'] == 20){
					$temp = trim($field['subfields']['a'][0]) . ' ';
					$temp = ereg_replace('[^0-9|x|X]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					$atomic['isbn'][] = $temp;
				}else if($field['tagno'] == 22){
					$temp = trim($field['subfields']['a'][0]) . ' ';
					$temp = ereg_replace('[^0-9|x|X|\-]', '', strtolower(substr($temp, 0, strpos($temp, ' '))));
					$atomic['issn'][] = $temp;
				
				//Call Numbers
				}else if($field['tagno'] == 852){ // callnums from InfoCenter
					$temp = trim($field['subfields']['h'][0]);
					$atomic['callnumber'][] = $temp;

					$temp = trim($field['subfields']['b'][0]);
					$atomic['location'][] = $temp;

				//Titles
				}else if($field['tagno'] == 245){
					$temp = ucwords(trim(trim(trim(ereg_replace('/$', '', $field['subfields']['a'][0]) .' '. trim(ereg_replace('/$', '', $field['subfields']['b'][0]))), ',.;:/')));
					$atomic['title'][] = $temp;
					$atomic['attribution'][] = trim(trim(trim($field['subfields']['c'][0]), ',.;:/'));
				}else if($field['tagno'] == 240){
					$temp = ucwords(trim(trim(trim(ereg_replace('/$', '', $field['subfields']['a'][0]) .' '. trim(ereg_replace('/$', '', $field['subfields']['b'][0]))), ',.;:/')));
					$atomic['alttitle'][] = $temp;
				}else if(($field['tagno'] > 719) && ($field['tagno'] < 741)){
					$temp = $field['subfields']['a'][0];
					$atomic['alttitle'][] = $field['subfields']['a'][0];
				
				//Dates
				}else if($field['tagno'] == 260){
					$temp = str_pad(substr(ereg_replace('[^0-9]', '', $field['subfields']['c'][0]), 0, 4), 4 , '5');
					$atomic['pubyear'][] = $temp;
				}else if($field['tagno'] == 005){
					$atomic['catdate'][] = $field['data']{0}.$field['data']{1}.$field['data']{2}.$field['data']{3} .'-'. $field['data']{4}.$field['data']{5} .'-'. $field['data']{6}.$field['data']{7};
				}else if($field['tagno'] == 008){
					$atomic['pubyear'][] = substr($field['data'], 14, 4);
				
				//Subjects
				}else if(($field['tagno'] > 599) && ($field['tagno'] < 700)){
					$atomic['subject'][] = trim(trim(implode(' -- ', $this->horizon_implode($field['data'])), '.'));
					if($atomic['subjkey']){
						$atomic['subjkey'] = array_unique(array_merge($atomic['subjkey'], array_map($trimmer, $this->horizon_implode($field['data']))));
					}else{
						$atomic['subjkey'] = array_map($trimmer, $this->horizon_implode($field['data']));
					}	
			
				//URLs
				}else if($field['tagno'] == 856){
					unset($temp);
					$temp['href'] = $temp['title'] = str_replace(' ', '', $field['subfields']['u'][0]);
					$temp['title'] = trim(parse_url( $temp['href'] , PHP_URL_HOST), 'www.');
					if($field['subfields']['3'][0])
						$temp['title'] = $field['subfields']['3'][0];
					if($field['subfields']['z'][0])
						$temp['title'] = $field['subfields']['z'][0];
					$atomic['url'][] = '<a href="'. $temp['href'] .'">'. $temp['title'] .'</a>';
			
				//Notes
				}else if(($field['tagno'] > 299) && ($field['tagno'] < 400)){
					$atomic['physdesc'][] = implode(' ', $this->horizon_implode($field['data']));
				}else if(($field['tagno'] > 399) && ($field['tagno'] < 500)){
					$atomic['title'][] = implode("\n", $this->horizon_implode($field['data']));
				}else if(($field['tagno'] > 799) && ($field['tagno'] < 841)){
					$atomic['series'][] = implode("\n", $this->horizon_implode($field['data']));
				}else if(($field['tagno'] > 499) && ($field['tagno'] < 600)){
					$line = implode("\n", array_values($field['subfields']));
					if($field['tagno'] == 504)
						continue;
					if($field['tagno'] == 505){
						$atomic['contents'][] = str_replace(array('> ','> ','> '), '>', '<li>'. str_replace('--', "</li>\n<li>", trim(str_replace(array(' ', ' ', ' '), ' ', implode('--', $this->horizon_implode($field['data']))))) .'</li>');
						continue;
					}
					$atomic['notes'][] = implode("\n", $this->horizon_implode($field['data']));
				}
				
				//Format
				if((!$atomic['format']) && ($field['tagno'] > 239) && ($field['tagno'] < 246)){
					$temp = ucwords(strtolower(str_replace('[', '', str_replace(']', '', $field['subfields']['h'][0]))));
					
					if(eregi('^book', $temp)){
						$format = 'Book';
						$formats = 'Books';
	
					}else if(eregi('^micr', $temp)){
						$format = 'Microform';
	
					}else if(eregi('^electr', $temp)){
						$format = 'Website';
						$formats = 'Websites';
	
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
			}
		}

		if(!$atomic['format'][0]){
			$atomic['format'][0] = 'Book';
		}

		if(!$atomic['acqdate'])
			$atomic['acqdate'] = $atomic['catdate'];

		if(!$atomic['catdate'][0])
			$atomic['catdate'][0] = '1984-01-01';

		if($atomic['pubyear'][0] > (date(Y) + 5))
			$atomic['pubyear'][0] = substr($atomic['catdate'][0],0,4);

		if($atomic['pubyear'][0]){
			$atomic['pubdate'][] = $atomic['pubyear'][0].substr($atomic['catdate'][0],4);
		}
		
		if($atomic['alttitle'])
			$atomic['title'] = array_unique(array_merge($atomic['title'], $atomic['alttitle']));

		$atomic = array_filter($atomic);
		
		$atomic['the_sourceid'] = substr(ereg_replace('[^a-z|0-9]', '', strtolower($_POST['scrib_horizon-sourceprefix'])), 0, 2) . $bibn;

		if(!empty($atomic['title']) && !empty($atomic['the_sourceid'])){

			$atomic['tags']['subj'] = $atomic['subjkey'];
			$atomic['tags']['auth'] = $atomic['author'];
			$atomic['tags']['isbn'] = $atomic['isbn'];
			$atomic['tags']['issn'] = $atomic['issn'];
			$atomic['tags']['title'] = $atomic['title'];
			$atomic['tags']['format'] = $atomic['format'];

			if($sweets = $scrib_import->get_sweets($atomic['isbn'])){
				if(!empty($sweets['img']));
					$atomic['img'] = $sweets['img'];
				if(!empty($sweets['summary'])){
					$atomic['shortdescription'] = $sweets['summary'];
				}
			}
			$atomic['the_title'] = $atomic['title'][0];
			$atomic['the_pubdate'] = $atomic['pubdate'][0];
			$atomic['the_acqdate'] = $atomic['acqdate'][0];
	
			$atomic['the_excerpt'] = $scrib_import->the_excerpt($atomic);
			$atomic['the_content'] = $scrib_import->the_content($atomic);

			$scrib_import->insert_harvest($atomic);
			return($atomic);
		}else{
			$this->error = 'Record number '. $bibn .' couldn&#039;t be parsed.';
			return(FALSE);
		}

	}

	// Default constructor 
	function ScribHorizon_import() {
		// nothing
	} 
} 

// Instantiate and register the importer 
include_once(ABSPATH . 'wp-admin/includes/import.php'); 
if(function_exists('register_importer')) { 
	$scribhorizon_import = new ScribHorizon_import(); 
	register_importer($scribhorizon_import->importer_code, $scribhorizon_import->importer_name, $scribhorizon_import->importer_desc, array (&$scribhorizon_import, 'dispatch')); 
} 

add_action('activate_'.plugin_basename(__FILE__), 'scribhorizon_importer_activate'); 

function scribhorizon_importer_activate() { 
	global $wp_db_version, $scribhorizon_import; 
	 
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
