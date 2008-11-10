<?php 
/*
Plugin Name: Scriblio flickr Importer
Plugin URI: http://about.scriblio.net/
Description: Imports flickr photos.
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

define('SCRIBFLICKR_API_KEY', 'e7d7b796dd65c9d367d6da98f794d4c6');
define('SCRIBFLICKR_API_KEY_SS', '30a2de8c6f027d27');
define('SCRIBFLICKR_IMGDIR', 'img');
define('SCRIBFLICKR_IMGDIR_PATH', ABSPATH . SCRIBFLICKR_IMGDIR .'/');

// The importer 
class ScribFlickr_import { 
	var $importer_code = 'scribimporter_flickr'; 
	var $importer_name = 'Scriblio flickr Importer'; 
	var $importer_desc = 'Imports flickr photos. <a href="http://about.scriblio.net/wiki/">Documentation here</a>.'; 
	 
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
				$this->flickr_start(); 
				break;
			case 2:
				$this->flickr_pickphotos(); 
				break; 
			case 3:
				$this->flickr_harvestphotos(); 
				break; 
			case 4:
				$this->ktnxbye(); 
				break; 
		} 

		// load the footer
		$this->footer();
	} 

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__('Scriblio flickr Importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! Start here to import photos from flickr into Scriblio.').'</p>';
		echo '<p>'.__('This has not been tested much. Mileage may vary.').'</p>';

		echo '<form action="admin.php?import='. $this->importer_code .'&amp;step=1" method="post">';
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

	function flickr_start() {
		// Inspiration and whole blocks of code for this taken from
		// Jon Baker's WP-Flickr WordPress plugin
		// used under the terms of the GPL

		echo '<div class="narrow">';

		$prefs = get_option( 'scrib_flickrmporter' );
		
		// logout
		if( isset( $_REQUEST['logout'] )){
			$prefs['token'] = FALSE;
			update_option( 'scrib_flickrmporter', $prefs );
		}
		
		if( $_REQUEST['frob'] != '' ){ 
			$params = array(
				'method'	=> 'flickr.auth.getToken',
				'frob'		=> $_REQUEST['frob']
			);
	
			if( $r = $this->flickr_api( $params, false )){
				$prefs['token'] = $r['auth']['token']['_content'];
				update_option( 'scrib_flickrmporter', $prefs );
			}
		}
	
		// check authentication token for validity
		$current_user = FALSE;
		if( $prefs['token'] ) {
			$params = array(
				'method'	=> 'flickr.auth.checkToken'
			);
	
			if( $current_user = $this->flickr_api( $params, FALSE, TRUE )){
				if ( $prefs['nsid'] != $current_user['auth']['user']['nsid'] ){
					$prefs['nsid'] = $current_user['auth']['user']['nsid'];
					update_option( 'scrib_flickrmporter', $prefs );
				}
				$params = array(
					'method'	=> 'flickr.people.getInfo',
					'user_id'	=> $prefs['nsid']
				);
				$r = $this->flickr_api( $params, false, true );
				$prefs['photopage'] = $r['person']['photosurl']['_content'];
				update_option( 'scrib_flickrmporter', $prefs );
			}
		} 
/*
	if( !$current_user ) {
			echo '<div class="error fade"><p><strong>You need to authorised this plugin with Flickr before it can be used. Please complete the steps below to authorise this plugin.<br/><br/> Please Note: You will need an account with Flickr to use this plugin.</strong></p></div>';
		}
*/
	
		if($current_user) {
?>
			<p>Currently logged in to Flickr as <a href="http://www.flickr.com/people/<?php echo $current_user['auth']['user']['nsid']; ?>" target="_new"><?php echo $current_user['auth']['user']['username']; ?></a> (<a href="admin.php?import=<?php echo $this->importer_code; ?>&amp;logout=true&amp;step=1">logout</a>)</p>
<?php

		echo '<p>'.__('All Scriblio records have a &#039;sourceid,&#039; a unique alphanumeric string that&#039;s used to avoid creating duplicate records and, in some installations, link back to the source system for current availability information.').'</p>';
		echo '<p>'.__('The sourceid is made up of two parts: the prefix that you assign, and the bib number from the Innopac. Theoretically, you chould gather records from 1,296 different systems, it&#039;s a big world.').'</p>';

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;step=2" method="post">';
?>
<p><label for="sourceprefix">The source prefix:<br /><input type="text" name="sourceprefix" id="sourceprefix" value="<?php echo attribute_escape( $prefs['sourceprefix'] ); ?>" /><br />example: bb (must be two characters, a-z and 0-9 accepted)</label></p>
<?php
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Next &raquo;').'" /></p>';
		echo '</form>';



		} else {			
			$prefs['token'] = null;

			$params = array(
				'method'	=> 'flickr.auth.getFrob'
			);

			$r = $this->flickr_api( $params, false );
			$frob = $r['frob']['_content'];

			if($frob) {
				$flickr_url = "http://www.flickr.com/services/auth/";
				$flickr_url .= "?api_key=" . SCRIBFLICKR_API_KEY;
				$flickr_url .= "&perms=read";
				$flickr_url .= "&frob=" . $frob;
				$flickr_url .= "&api_sig=" . md5(SCRIBFLICKR_API_KEY_SS . "api_key" . SCRIBFLICKR_API_KEY . "frob" . $frob . "permsread");

?>
				<h3>Authorizing the Scriblio flickr Importer is a simple, two step process:</h3>
				<ol>
					<li><a href="<?php echo $flickr_url; ?>" target="_new">Authorize this application to access flickr</a>. <em>This will open a new window. When you are finished, come back to this page.</em></li>
					<li>After authorizing this application with Flickr in the popup window, <a href="admin.php?import=<?php echo $this->importer_code ?>&amp;frob=<?php echo $frob; ?>&amp;step=1">click here to complete the authorization process</a>.</li>
				</ol>
<?php
			}
		}
		echo '</div>';
	}



	function flickr_pickphotos(){
		$prefs = get_option( 'scrib_flickrmporter' );
		
		if( isset( $_POST['sourceprefix'] ))
			$prefs['sourceprefix'] = strtolower( ereg_replace( '[^a-z|A-Z|0-9]', '', $_POST['sourceprefix'] ));

		if( strlen( ereg_replace( '[^a-z|A-Z|0-9]', '', $prefs['sourceprefix'] )) <> 2 ){
			echo '<p>'.__('Sorry, there has been an error.').'</p>';
			echo '<p><strong>Please complete all fields</strong></p>';
			return;
		}
		update_option( 'scrib_flickrmporter', $prefs );

		if( !$_REQUEST['min_date'] || ( strtotime( $_REQUEST['min_date'] ) === FALSE ))
			$min_date = '-1 month';
		else
			$min_date = $_REQUEST['min_date'];
		
		if( !$_REQUEST['page'] )
			$page = '1';
		else
			$page = (int) $_REQUEST['page'];

		if( !$_REQUEST['perpage'] || ( $_REQUEST['perpage'] > 500 ))
			$perpage = '100';
		else
			$perpage = (int) $_REQUEST['perpage'];

		$params = array(
			'method'	=> 'flickr.photos.recentlyUpdated',
			'page'		=> $page,
			'per_page'	=> $perpage,
			'min_date'	=> strtotime( $min_date )
		);

		$photos = $this->flickr_api( $params, false, true );

		echo '<div>';

		echo '<form name="myform" id="myform" action="admin.php?import='. $this->importer_code .'&amp;step=2" method="post">';

		echo '<p>'. $photos['photos']['total'] .' photos uploaded or changed since 
		<input name="min_date" id="min_date" type="text" value="'. date( 'F j, Y', strtotime( $min_date )) .'" style="width: 11em;" />. Showing page 
		<input name="page" id="page" type="text" value="'. $photos['photos']['page'] .'" style="width: 1.5em;" /> of '. $photos['photos']['pages'] .', 
		<input name="perpage" id="perpage" type="text" value="'. $photos['photos']['perpage'] .'" style="width: 2.5em;" /> photos per page. 
	
		<input type="submit" name="refresh" value="'.__('Refresh').'" /></p>';

		echo '</form>';

//print_r($photos);

		if($photos) {
//print_r($prefs);
			echo '<form name="photoselector" id="photoselector" action="admin.php?import='. $this->importer_code .'&amp;step=3" method="post">';
			foreach($photos['photos']['photo'] as $number=>$photo) {
?>

				<div style="width:75px; height: 110px; overflow: hidden; float: left; border:1px solid black; margin: 5px; padding: 5px; "><label for="scrib_flickr_photo-<?php echo $photo['id']; ?>"><input type="checkbox" name="scrib_flickr_photo-<?php echo $photo['id']; ?>" id="scrib_flickr_photo-<?php echo $photo['id']; ?>" value="1" /> Import<br />
				<img src="<?php echo $this->flickr_photourl( $photo['farm'], $photo['server'], $photo['id'], $photo['secret']); ?>_s.jpg" width="75" height="75" /></label><br />
				<a href="<?php echo $prefs['photopage'] . $photo['id']; ?>" target="_new"><?php echo substr( $photo['title'], 0, 25 ); ?></a></div>
<?php
			}
?>
			<div class="narrow" style="clear: both;">
			<br />
			<script type="text/javascript">
			// <![CDATA[
				function select_all() {
					for ( var i = 0; i < document.photoselector.length; i++ ) {
						document.photoselector[i].checked = true;
					}
				}
			
				function deselect_all() {
					for ( var i = 0; i < document.photoselector.length; i++ ) {
						document.photoselector[i].checked = false;
					}
				}
			
				document.write( '<p><a href="javascript:select_all()">Select all</a> or <a href="javascript:deselect_all()">deselect all</a> photos.</p>' );
			// ]]>
			</script>
			
			<p class="submit"><input type="submit" name="next" value="Harvest Selected Photos &raquo;" /></p>
			</div>
			</form>
<?php
		}
	}

	function flickr_harvestphotos(){
		global $scrib_import, $bsuite;
		$prefs = get_option( 'scrib_flickrmporter' );

//print_r( $prefs );
//print_r( $_POST );
		$photo_ids = FALSE;
		foreach( $_REQUEST as $k => $v){
			if( substr( $k, 0, 19 ) == 'scrib_flickr_photo-' )
				$photo_ids[] = substr( $k, 19 );
		}

//print_r( $photo_ids );
		
		echo '<div>';
		
		foreach( $photo_ids as $photo_id ){
			$params = array(
				'method'	=> 'flickr.photos.getInfo',
				'photo_id'		=> $photo_id
			);
			$photo_details = $this->flickr_api( $params, false, true );

//print_r( $photo_details );

			$atomic = FALSE;

			$atomic['the_sourceid'] = $prefs['sourceprefix'] . $photo_details['photo']['id'];

			$params = array(
				'method'	=> 'flickr.photos.getSizes',
				'photo_id'		=> $photo_id
			);
			$photo_sizes = $this->flickr_api( $params, false, true );

			$atomic['img']['thumb']['width'] = $photo_sizes['sizes']['size'][1]['width'];
			$atomic['img']['thumb']['height'] = $photo_sizes['sizes']['size'][1]['height'];
			$atomic['img']['thumb']['url'] = $this->flickr_cacheimage( $this->flickr_photourl( $photo_details['photo']['farm'], $photo_details['photo']['server'], $photo_details['photo']['id'], $photo_details['photo']['secret'])  .'_t.jpg', $photo_details['photo']['id'] .'_t' );
		
			$atomic['img']['large']['width'] = $photo_sizes['sizes']['size'][3]['width'];
			$atomic['img']['large']['height'] = $photo_sizes['sizes']['size'][3]['height'];
			$atomic['img']['large']['url'] = $this->flickr_cacheimage( $this->flickr_photourl( $photo_details['photo']['farm'], $photo_details['photo']['server'], $photo_details['photo']['id'], $photo_details['photo']['secret'])  .'.jpg', $photo_details['photo']['id'] );

			if( strlen( $photo_details['photo']['description']['_content'] ))
				$atomic['notes'][] = $photo_details['photo']['description']['_content'];

			foreach( $photo_details['photo']['tags']['tag'] as $tag){
				$parsed_tag = $bsuite->machtag_parse_tag( $tag['raw'] );
				$parsed_tag['taxonomy'] = strtolower( $parsed_tag['taxonomy'] );

				switch( $parsed_tag['taxonomy'] ){
					case 'parent' :
						preg_match('/\/photos\/[^\/]*\/([0-9]*)\//', $parsed_tag['term'], $match);
						$atomic['parent'][] = $prefs['sourceprefix'] . $match[1];
						continue;
					case 'child' :
						preg_match('/\/photos\/[^\/]*\/([0-9]*)\//', $parsed_tag['term'], $match);
						$atomic['child'][] = $prefs['sourceprefix'] . $match[1];
						continue;
					case 'reverse' :
						preg_match('/\/photos\/[^\/]*\/([0-9]*)\//', $parsed_tag['term'], $match);
						$atomic['reverse'][] = $prefs['sourceprefix'] . $match[1];
						continue;
					case 'subject' :
						$atomic['tags']['subj'][] = $parsed_tag['term'];
						continue;
					case 'year' :
						$atomic['tags']['dy'][] = $parsed_tag['term'];
						continue;
					case 'month' :
						$atomic['tags']['dm'][] = $parsed_tag['term'];
						continue;
					default:
						$atomic['tags'][$parsed_tag['taxonomy']][] = $parsed_tag['term'];
				}
			}

			$atomic['the_title'] = eregi_replace('^Img', 'Item #', eregi_replace('\.jpg$', '', $photo_details['photo']['title']['_content']));
			$atomic['tags']['title'][] = $atomic['the_title'];
			
			$atomic['the_pubdate'] = $photo_details['photo']['dates']['taken'];
			$atomic['the_acqdate'] = date('Y-m-d H:i:s', $photo_details['photo']['dates']['posted']);

			$atomic['cformatter'] = 'scribflickr_importer_content';
			$atomic['eformatter'] = 'scribflickr_importer_excerpt';

			$scrib_import->insert_harvest($atomic);
?>
			<div style="width:75px; height: 110px; overflow: hidden; float: left; border:1px solid black; margin: 5px; padding: 5px; ">Harvested<br />
			<img src="<?php echo $atomic['img']['thumb']['url']; ?>" width="75" height="75" />
			<a href="<?php echo $prefs['photopage'] . $photo_details['photo']['id']; ?>" target="_new"><?php echo substr( $atomic['the_title'], 0, 25 ); ?></a></div>
<?php
//print_r( $atomic );
//echo '<div style="clear:both;">'. $scrib_import->get_the_excerpt( $atomic ) .'</div>';
//echo '<div style="clear:both;">'. $scrib_import->get_the_content( $atomic ) .'</div>';


		}

		echo '<div class="narrow" style="clear:both;">';

		echo '<h3 id="complete">'.__('Processing complete.').'</h3>';

		echo '<p>'.__('Continue to the next step to publish those harvested catalog entries.').'</p>';

		echo '<form action="admin.php?import=scribimporter&amp;step=3" method="post">';
		echo '<p class="submit"><input type="submit" name="next" value="'.__('Publish Harvested Records &raquo;').'" /> <br />'. __('(goes to default Scriblio importer)').'</p>';
		echo '</form>';

		echo '</div>';
	}

	function the_content( $atomic ) { 
		$content = '[[bookjacket|<img class="bookjacket" src="'. $atomic['img']['large']['url'] .'" width="'. $atomic['img']['large']['width'] .'" height="'. $atomic['img']['large']['height'] .'" alt="'. str_replace(array('[',']'), array('{','}'), htmlentities($atomic['title'][0], ENT_QUOTES, UTF-8)) .'" />]]';

		if($atomic['notes'])
			foreach($atomic['notes'] as $temp)
				$content .= '<p class="desc">'. $temp .'</p>';

		if($atomic['tags']){
			$content .= '<ul class="tags">';
			foreach($atomic['tags'] as $taxonomy => $tags)
				foreach($tags as $tag)
					$content .= '<li><a href="ScribCatBase/'. $taxonomy .'/' . urlencode( $tag ) .'">'. $tag .'</a></li>';
			$content .= '</ul>';
		}
	
		if($atomic['parent']){
			$content .= '<h3 class="parent">Parent:</h3><ul class="parent">';
			foreach($atomic['parent'] as $temp){
				$content .= '<li>[[linkto|'. $temp .']]</li>';
			}
			$content .= '</ul>';
		}
	
		if($atomic['child']){
			$content .= '<h3 class="child">Child:</h3><ul class="child">';
			foreach($atomic['child'] as $temp){
				$content .= '<li>[[linkto|'. $temp .']]</li>';
			}
			$content .= '</ul>';
		}
	
		if($atomic['reverse']){
			$content .= '<h3 class="reverse">Reverse:</h3><ul class="reverse">';
			foreach($atomic['reverse'] as $temp){
				$content .= '<li>[[linkto|'. $temp .']]</li>';
			}
			$content .= '</ul>';
		}
		
		return($content);
	}
	
	function the_excerpt( $atomic ) { 
		$content = '[[bookjacket|<img class="bookjacket" src="'. $atomic['img']['thumb']['url'] .'" width="'. $atomic['img']['thumb']['width'] .'" height="'. $atomic['img']['thumb']['height'] .'" alt="'. str_replace(array('[',']'), array('{','}'), htmlentities($atomic['title'][0], ENT_QUOTES, UTF-8)) .'" />]]';

		return($content);
	}

	function flickr_cacheimage($url, $filename, $dl = TRUE){
		$dirpath = substr($filename, 4, 1);
		$extension = substr($url, strripos($url, '.'));	
		$path = $dirpath .'/'. $filename . $extension;
	
		if(!is_dir( SCRIBFLICKR_IMGDIR_PATH . $dirpath )){
			mkdir( SCRIBFLICKR_IMGDIR_PATH . $dirpath, 0775 );
		}

		if( $dl ) 
			exec( 'curl -o '. escapeshellarg( SCRIBFLICKR_IMGDIR_PATH. $path ) .' '. escapeshellarg( $url ));

		return( get_settings( 'siteurl' ) .'/'. SCRIBFLICKR_IMGDIR .'/'. $path);
	}

	function flickr_photourl( $farm, $server, $id, $secret ) {
		return $img_url = 'http://farm' . $farm . '.static.flickr.com/' . $server . '/' . $id . '_' . $secret;
	}

	function flickr_api( $params, $cache = true, $sign = true ) {
		// Inspiration and whole blocks of code for this taken from
		// Jeffrey Maki's Flickr Tag WordPress plugin
		// used under the terms of the GPL

		$prefs = get_option( 'scrib_flickrmporter' );
	
		$params['format'] = 'php_serial';
		$params['api_key'] = SCRIBFLICKR_API_KEY;
		if( $prefs['token'] )
			$params['auth_token'] = $prefs['token'];
	
		ksort($params); // important for generating the correct signature
	
		$cache_key = md5( implode( $params ));
	
		$signature_raw = '';
		$encoded_params = array();
		foreach($params as $k=>$v) {
			$encoded_params[] = urlencode($k) . '=' . urlencode($v);
			$signature_raw .= $k . $v;
		}
		if( $sign )
			array_push($encoded_params, 'api_sig=' . md5(SCRIBFLICKR_API_KEY_SS . $signature_raw));
	
//print_r( $encoded_params );
	
		@$c = curl_init();
		if( $c ){
			curl_setopt( $c, CURLOPT_URL, 'http://api.flickr.com/services/rest/' );
			curl_setopt( $c, CURLOPT_POST, 1 );
			curl_setopt( $c, CURLOPT_POSTFIELDS, implode( '&', $encoded_params ));
			curl_setopt( $c, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $c, CURLOPT_CONNECTTIMEOUT, 10 );
			$r = curl_exec($c);
		}else // no curl, try fopen... 
			$r = file_get_contents( 'http://api.flickr.com/services/rest/?' . implode( '&', $encoded_params ));
	
		if( !$r )
			die( 'Darn. It no worky. Perhaps flickr is down? Perhaps this host does not have curl or fopen wrappers?' );
	
		$o = unserialize( $r );
	
//print_r( $o );
	
		if( $o['stat'] != 'ok' )
			return(FALSE);
	
		return $o;
	}
		
	// Default constructor 
	function ScribFlickr_import() {
		// nothing
	} 
} 

// Instantiate and register the importer 
include_once(ABSPATH . 'wp-admin/includes/import.php'); 
if(function_exists('register_importer')) { 
	$scribflickr_import = new ScribFlickr_import(); 
	register_importer($scribflickr_import->importer_code, $scribflickr_import->importer_name, $scribflickr_import->importer_desc, array (&$scribflickr_import, 'dispatch')); 
} 

add_action('activate_'.plugin_basename(__FILE__), 'scribflickr_importer_activate'); 

function scribflickr_importer_content( $atomic ) { 
	global $scribflickr_import;
	return( $scribflickr_import->the_content( $atomic ));
}

function scribflickr_importer_excerpt( $atomic ) { 
	global $scribflickr_import;
	return( $scribflickr_import->the_excerpt( $atomic ));
}

function scribflickr_importer_activate() { 
	global $wp_db_version, $scribflickr_import; 
	 
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
