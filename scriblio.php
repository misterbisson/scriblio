<?php
/*
Plugin Name: Scriblio
Plugin URI: http://about.scriblio.net/
Description: Leveraging WordPress as a library OPAC.
Version: 2.7 b05
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/

/*  Copyright 2005-8  Casey Bisson & Plymouth State University

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

error_reporting(E_ERROR);
//error_reporting(E_ALL);


class Scrib {

	var $meditor_forms = array();

	function __construct(){
		global $wpdb;

		// establish web path to this plugin's directory
		$this->path_web = plugins_url( basename( dirname( __FILE__ )));

		// register and queue javascripts
		wp_register_script( 'scrib-suggest', $this->path_web . '/js/jquery.scribsuggest.js', array('jquery'), '20081030' );
		wp_enqueue_script( 'scrib-suggest' );	

		wp_register_script( 'scrib-googlebook', $this->path_web . '/js/scrib.googlebook.js', array('jquery'), '20080422' );
		wp_enqueue_script( 'scrib-googlebook' );	

		wp_register_style( 'scrib-display', $this->path_web .'/css/display.css' );
		wp_enqueue_style( 'scrib-display' );
		wp_register_style( 'scrib-suggest', $this->path_web .'/css/suggest.css' );
		wp_enqueue_style( 'scrib-suggest' );
		add_action('wp_head', 'wp_print_styles', '9');

		// register WordPress hooks
		register_activation_hook(__FILE__, array(&$this, 'activate'));
		add_action('init', array(&$this, 'init'));

		add_action('parse_query', array(&$this, 'parse_query'), 10);
		add_action( 'pre_get_posts', array( &$this, 'pre_get_posts' ), 7 );
		add_filter( 'posts_orderby', array( &$this, 'posts_orderby' ), 7 );

		add_action('wp_ajax_meditor_suggest_tags', array( &$this, 'meditor_suggest_tags' ));
		if ( isset( $_GET['scrib_suggest'] ) )
			add_action( 'init', array( &$this, 'suggest_search' ));

		add_action('admin_menu', array(&$this, 'addmenus'));
		add_filter('bsuite_suggestive_taxonomies', array(&$this, 'the_taxonomies_for_bsuite_suggestive'), 10, 2);
		add_filter('bsuite_link2me', array(&$this, 'link2me'), 10, 2);
		add_action('widgets_init', array(&$this, 'widgets_register'));

		add_shortcode('scrib_bookjacket', array(&$this, 'shortcode_bookjacket'));
		add_shortcode('scrib_availability', array(&$this, 'shortcode_availability'));
		add_shortcode('scrib_taglink', array(&$this, 'shortcode_taglink'));
		add_shortcode('scrib_hitcount', array(&$this, 'shortcode_hitcount'));

		add_action('admin_menu', array( &$this, 'admin_menu_hook' ));
		
		add_action('save_post', array(&$this, 'meditor_save_post'), 2, 2);
		add_filter('pre_post_title', array(&$this, 'meditor_pre_save_filters'));
		add_filter('pre_post_excerpt', array(&$this, 'meditor_pre_save_filters'));
		add_filter('pre_post_content', array(&$this, 'meditor_pre_save_filters'));

		$this->marcish_register();
		$this->arc_register();

		add_action('wp_footer', array(&$this, 'wp_footer_js'));

		add_filter('template_redirect', array(&$this, 'textthis_redirect'), 11);
		// end register WordPress hooks
	}

	function init(){
		global $wpdb, $wp_rewrite, $bsuite;

		$this->suggest_table = $wpdb->prefix . 'scrib_suggest';
		$this->harvest_table = $wpdb->prefix . 'scrib_harvest';

		$slash = $wp_rewrite->use_trailing_slashes ? '' : '/';

		$this->options = get_option('scrib');
		$this->options['site_url'] = get_settings('siteurl') . '/';
		$this->options['search_url'] = get_settings('siteurl') .'/search/';
		$this->options['browse_url'] = get_permalink($this->options['browse_id']) . $slash;
		$this->options['browse_base'] = str_replace( $this->options['site_url'] , '', $this->options['browse_url'] );
		$this->options['browse_name'] = trim(substr(get_page_uri($this->options['browse_id']), strrpos(get_page_uri($this->options['browse_id']), '/')), '/');
		
		$this->the_matching_posts = NULL;
		$this->the_matching_posts_ordinals = NULL;
		$this->search_terms = NULL;
		$this->the_matching_post_counts = NULL;

//		add_rewrite_endpoint( 'browse', EP_ROOT );

		$this->initial_articles = array( 'a ','an ','da ','de ','the ','ye ' );

		$this->taxonomy_name = $this->options['taxonomies'];
		$this->taxonomies = $this->taxonomies_register();
		$this->taxonomies_for_related = $this->options['taxonomies_for_related'];
		$this->taxonomies_for_suggest = $this->options['taxonomies_for_suggest'];

		$this->kses_allowedposttags(); // allow more tags

		if( $bsuite->loadavg < get_option( 'bsuite_load_max' )) // only do cron if load is low-ish
			add_filter('bsuite_interval', array( &$this, 'import_harvest_passive' ));
	}

	public function activate() {
		global $wpdb;
		
		// setup default options
		if(!get_option('scrib'))
			update_option('scrib', array(
				'taxonomies' => array(
					's' => 'Keyword',
					'category' => 'Category',

					'creator' => 'Author',
					'creatorkey' => 'Author Keyword',
					'lang' => 'Language',
					'cy' => 'Year Published',
					'cm' => 'Month Published',
					'format' => 'Format',
					'subject' => 'Subject',
					'subjkey' => 'Subject Keyword',
					'genre' => 'Genre',
					'person' => 'Person',
					'place' => 'Place',
					'time' => 'Time Period',
					'time' => 'Exhibit',
					'sy' => 'Subject Year',
					'sm' => 'Subject Month',
					'sd' => 'Subject Day',
					'collection' => 'Collection',
					'sourceid' => 'Source ID',
					'isbn' => 'ISBN',
					'issn' => 'ISSN',
					'lccn' => 'LCCN',
					'asin' => 'ASIN',
					'ean' => 'ean',
					'oclc' => 'oclc',
					'olid' => 'olid',

					'olid' => 'olid',
					'title' => 'Title',
					),
				'taxonomies_for_related' => array( 'creator', 'subject', 'genre', 'person', 'place', 'time', 'exhibit' ),
				'taxonomies_for_suggest' => array( 'creator', 'creatorkey', 'subject', 'subjkey', 'genre', 'person', 'place', 'time', 'exhibit', 'title' )
				));

		$options = get_option('scrib');

		// setup the browse page, if it doesn't exist
		if(empty($options['browse_id']) || $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE ID = ". intval($options['browse_id']) .' AND post_status = "publish" AND post_type = "page" ') == FALSE){		
			// create the default browse page
			$postdata['post_title'] = 'Browse';
			$postdata['post_name'] = 'browse';
			$postdata['comment_status'] = 0;
			$postdata['ping_status'] 	= 0;
			$postdata['post_status'] 	= 'publish';
			$postdata['post_type'] 		= 'page';
			$postdata['post_content']	= 'Browse new titles.';
			$postdata['post_excerpt']	= 'Browse new titles.';
			$postdata['post_author'] = 0;
			$post_id = wp_insert_post($postdata); // insert the post
	
			// set the options with this new page	
			$options['browse_id'] = (int) $post_id;	
			update_option('scrib', $options);
		}

		// setup the catalog author, if it doesn't exist
		if(empty($options['catalog_author_id']) || get_userdata($options['catalog_author_id']) == FALSE){		
			// create the default author
			$random_password = substr( md5( uniqid( microtime() )), 0, 6 );
			$user_id = wp_create_user( 'cataloger', $random_password );
			$user = new WP_User( $user_id );
			$user->set_role( 'contributor' );

			// set the options	
			$options['catalog_author_id'] = (int) $user_id;	
			update_option('scrib', $options);
		}
		
		// setup widget defaults, if they don't exisit
		if(!get_option('widget_scrib_searchedit'))
			update_option('widget_scrib_searchedit', array(
				'search-title' => 'Searching',
				'search-text-top' => 'Your search found [scrib_hit_count] items with all of the following terms:',
				'search-text-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search. <a href="http://about.scriblio.net/wiki/what-are-facets">What are facets?</a> Results sorted by keyword relevance.',

				'browse-title' => 'Browsing New Titles',
				'browse-text-top' => 'We have [scrib_hit_count] items with all of the following terms:',
				'browse-text-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search. <a href="http://about.scriblio.net/wiki/what-are-facets">What are facets?</a> Results sorted by the date added to the collection.',

				'default-title' => 'Browsing New Titles',
				'default-text' => 'Showing new titles added to the collection. Use the facets below to explore the collection. <a href="http://about.scriblio.net/wiki/what-are-facets">What are facets?</a>'
				));
		
		if(!get_option('widget_scrib_facets'))
			update_option('widget_scrib_facets', array(
				'number' => 9, 
				1 => array(
					'title' => 'Narrow by Subject',
					'facets' => 'subj',
					'count' => '25',
					'show_search' => 'on',
					'format' => 'cloud'),
				2 => array(
					'title' => 'More in Subject',
					'facets' => 'subj',
					'count' => '0',
					'show_singular' => 'on',
					'format' => 'list'),
				3 => array(
					'title' => 'Format',
					'facets' => 'format',
					'count' => '25',
					'show_search' => 'on',
					'format' => 'list'),
				4 => array(
					'title' => 'Author',
					'facets' => 'auth',
					'count' => '9',
					'show_singular' => 'on',
					'show_browse' => 'on',
					'format' => 'list')
				));

	
		// create tables
		$charset_collate = '';
		if ( version_compare( mysql_get_server_info(), '4.1.0', '>=' )) {
			if ( ! empty( $wpdb->charset ))
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty( $wpdb->collate ))
				$charset_collate .= " COLLATE $wpdb->collate";
		}
	
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta("
			CREATE TABLE $this->harvest_table (
			source_id varchar(85) NOT NULL,
			harvest_date date NOT NULL,
			imported tinyint(1) default '0',
			content longtext NOT NULL,
			enriched tinyint(1) default '0',
			PRIMARY KEY  (source_id),
			KEY imported (imported),
			KEY enriched (enriched)
			) $charset_collate");
	}

	public function kses_allowedposttags() {
		global $allowedposttags;
		unset($allowedposttags['font']);

		$allowedposttags['ul']['class'] = array();
		$allowedposttags['ol']['class'] = array();
		$allowedposttags['li']['class'] = array();

		$allowedposttags['div']['class'] = array();
		$allowedposttags['div']['style'] = array();
		
		$allowedposttags['h1']['id'] = array();
		$allowedposttags['h1']['class'] = array();
		$allowedposttags['h2']['id'] = array();
		$allowedposttags['h2']['class'] = array();
		$allowedposttags['h3']['id'] = array();
		$allowedposttags['h3']['class'] = array();
		$allowedposttags['h4']['id'] = array();
		$allowedposttags['h4']['class'] = array();
		$allowedposttags['h5']['id'] = array();
		$allowedposttags['h5']['class'] = array();
		$allowedposttags['h6']['id'] = array();
		$allowedposttags['h6']['class'] = array();

		// tags required for YouTube embeds
		$allowedposttags['embed']['src'] = array();
		$allowedposttags['embed']['type'] = array();
		$allowedposttags['embed']['wmode'] = array();
		$allowedposttags['embed']['width'] = array();
		$allowedposttags['embed']['height'] = array();
		$allowedposttags['object']['width'] = array();
		$allowedposttags['object']['height'] = array();
		$allowedposttags['param']['name'] = array();
		$allowedposttags['param']['value'] = array();

		return(TRUE);
	}

	public function addmenus(){
		// register the options page
		add_options_page('Scriblio Settings', 'Scriblio', 'manage_options', __FILE__, array(&$this, 'admin_menu'));

		// register the meditor box in the post and page editors
		add_meta_box('scrib_meditor_div', __('Scriblio Metadata Editor'), array( &$this, 'meditor_metabox' ), 'post', 'advanced', 'high');
		add_meta_box('scrib_meditor_div', __('Scriblio Metadata Editor'), array( &$this, 'meditor_metabox' ), 'page', 'advanced', 'high');
	}

	public function admin_menu(){
		require(ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/scriblio_admin.php');
	}

	public function taxonomies_register() {
		// define the Scrib taxonomies
		global $wpdb, $wp, $wp_rewrite;

		// get the taxonomies from the config or punt and read them from the DB
		$taxonomies = get_option( 'scrib' );
		$taxonomies = array_keys( $taxonomies['taxonomies'] );

		// register those taxonomies
		foreach( $taxonomies as $taxonomy ){
			register_taxonomy( $taxonomy, 'post', array('rewrite' => FALSE, 'query_var' => FALSE ));
			$taxonomy = sanitize_title_with_dashes( $taxonomy );
			$wp->add_query_var( $taxonomy );
			$wp_rewrite->add_rewrite_tag( "%$taxonomy%", '([^/]+)', "$taxonomy=" );
			$wp_rewrite->add_permastruct( $taxonomy, "{$this->options['browse_base']}$taxonomy/%$taxonomy%", FALSE );
		}
		return( $taxonomies );
	}

	public function taxonomies_getall() {
		global $wpdb;
		return( $wpdb->get_col( "SELECT taxonomy FROM $wpdb->term_taxonomy GROUP BY taxonomy" ));
	}

	public function is_term( $term, $taxonomy = '' ){
		global $wpdb;
		
		$wild = FALSE;
		$wild = strpos($term, '*');
		
		if ( is_int($term) ) {
			if ( 0 == $term )
				return 0;
			$where = "t.term_id = '$term'";
		} else {
			if ( ! $term = sanitize_title($term) )
				return 0;

			if($wild){	
				$where = "t.slug LIKE '$term%'";
			}else{
				$where = "t.slug = '$term'";
			}
		}

		$term_id = $wpdb->get_col("SELECT term_id FROM $wpdb->terms as t WHERE $where");

		if ( empty($taxonomy) || empty($term_id) )
			return $term_id;

		return $wpdb->get_col("SELECT tt.term_taxonomy_id FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy as tt ON tt.term_id = t.term_id WHERE $where AND tt.taxonomy = '$taxonomy'");
	}

	public function parse_query( &$the_wp_query ){
//print_r( $the_wp_query );

		$terms  = array();
		if( !empty( $the_wp_query->query_vars['s'] )){
			$terms['s'] = array_filter( explode( '|', stripslashes( urldecode( $the_wp_query->query_vars['s'] ))));
			unset( $the_wp_query->query_vars['s'] );
		}

		$temp = array_intersect_key( $the_wp_query->query_vars, array_flip( $this->taxonomies ));
		if( count( $temp )){
			foreach( $temp as $key => $val ){
				$values = ( explode( '|', urldecode( $val ) ));
				foreach( $values as $val )
					$terms[ $key ][] = $val;
			}
		}

		$this->search_terms = array_filter( $terms );

		if( $the_wp_query->is_search ){
			if( !count( $temp ))
				$this->is_browse = TRUE;

			$this->add_search_filters();
			return( $the_wp_query );
		}

		if( isset( $the_wp_query->query_vars['pagename'] ) && $the_wp_query->query_vars['pagename'] == $this->options['browse_name'] ){
			$the_wp_query->query_vars['pagename'] = '';
			$the_wp_query->query_vars['page_id'] = 0;
			unset( $the_wp_query->queried_object );
			unset( $the_wp_query->queried_object_id );

			$this->is_browse = TRUE;
			$the_wp_query->is_category = TRUE;
			$the_wp_query->is_search = TRUE;
			$the_wp_query->is_page = FALSE;
			$the_wp_query->is_singular = FALSE;

			if( count( $this->search_terms )){
				$this->add_search_filters();
				return( $the_wp_query );
			}else{
				$the_wp_query->query_vars['cat'] = $this->options['catalog_category_id'];
				add_filter( 'posts_request',	array( &$this, 'posts_request' ), 11 );
				$this->search_terms = array();
				return( $the_wp_query );
			}			
		}

		return( $the_wp_query );
	}

	public function pre_get_posts( &$the_wp_query ){
/*
		// redirect requests for the catalog category page to the browse page
		if( isset( $the_wp_query->query_vars['category'] ) && $the_wp_query->query_vars['category'] == get_cat_name( $this->options['catalog_category_id'] ) && !$this->is_browse )
			die( wp_redirect( $this->options['browse_url'] ));
*/

		// hide catalog entries from the front page
		if( is_home() || is_front_page() )
			$the_wp_query->query_vars['category__not_in'] = array( $this->options['catalog_category_id'] );
		return( $the_wp_query );
	}

	public function add_search_filters(){
		global $wpdb, $bsuite;

		$search_terms = $this->search_terms;

		if( !empty( $search_terms['s'] )){
			$boolean = '';
			if(ereg('"|\+|\-|\(|\<|\>|\*', $this->search_terms['s']))
				$boolean = ' IN BOOLEAN MODE';

			$this->posts_fields[] = ", scrib_b.score ";
			$this->posts_join[] = " INNER JOIN ( 
				SELECT post_id, MATCH ( content, title ) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."') AS score 
				FROM $bsuite->search_table
				WHERE (MATCH ( content, title ) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."'$boolean))
				ORDER BY score DESC
				LIMIT 0, 1250
			) scrib_b ON ( scrib_b.post_id = $wpdb->posts.ID )";
			$this->posts_where[] = '';
			$this->posts_orderby[] = ' scrib_b.score DESC, ';

			add_filter( 'posts_fields',		array( &$this, 'posts_fields' ), 7 );

			unset( $search_terms['s'] );
		}

		if( !empty( $search_terms )){
			foreach($search_terms as $taxonomy => $values){
				foreach($values as $key => $value){
					if( !$tt_ids[] = $this->is_term ( $value, $taxonomy ))
						$matching_post_counts[$taxonomy][$key] = 0;
					else
						$matching_post_counts[$taxonomy][$key] = $wpdb->get_var("SELECT COUNT( term_taxonomy_id ) FROM $wpdb->term_relationships WHERE term_taxonomy_id IN (". implode( $this->is_term ( $value, $taxonomy ) , ',' ) .')' );
				}
			}
		}

		$tt_ids = array_filter( $tt_ids );
		$taliases = range( 'a','z' );
		$i = 1;
		if(count($tt_ids) > 0){
			foreach( $tt_ids as $tt_id ){
				$alias = $taliases[ ceil($i / 26) ] . $taliases[ ($i % 26) ];
				$this->posts_join[] = " INNER JOIN $wpdb->term_relationships scrib_$alias ON $wpdb->posts.ID = scrib_$alias.object_id ";
				$this->posts_where[] = " AND scrib_$alias.term_taxonomy_id IN (". implode( ',', $tt_id ) .') ';
				$i++;
			}
		}

		add_filter( 'posts_join',		array( &$this, 'posts_join' ), 7 );
		add_filter( 'posts_where',		array( &$this, 'posts_where' ), 7 );
		add_filter( 'posts_request',	array( &$this, 'posts_request' ), 11 );

	}

	public function posts_fields( $query ) {
		return( $query . implode( $this->posts_fields ));
	}
	public function posts_orderby( $query ) {
		global $wp_query, $wpdb;

		if( $wp_query->is_search || $this->is_browse )
			return( implode( $this->posts_orderby ) . $query );
		else
			return( str_replace( $wpdb->posts .'.post_date', $wpdb->posts .'.post_date_gmt', $query ));
	}
	public function posts_join( $query ) {
		return( $query . implode( $this->posts_join ));
	}
	public function posts_where( $query ) {
		return( $query . implode( $this->posts_where ));
	}
	public function posts_request( $query ) {
		global $wpdb;

//echo "<h2>$query</h2>";

		$this->the_matching_facets = $wpdb->get_results("SELECT b.term_id, b.name, a.taxonomy, COUNT(c.term_taxonomy_id) AS `count`
			FROM (
				". str_replace( 'SQL_CALC_FOUND_ROWS', '', preg_replace( '/LIMIT[^0-9]*([0-9]*)[^0-9]*([0-9]*)/i', 'LIMIT \1, 1000', $query )) .
			") p
			INNER JOIN $wpdb->term_relationships c ON p.ID = c.object_id
			INNER JOIN $wpdb->term_taxonomy a ON a.term_taxonomy_id = c.term_taxonomy_id
			INNER JOIN $wpdb->terms b ON a.term_id = b.term_id
			GROUP BY c.term_taxonomy_id ORDER BY `count` DESC LIMIT 1500");

		return($query);
	}

	public function editsearch() {
		global $wpdb, $wp_query, $bsuite;
		$search_terms = $this->search_terms;

		if(!empty($search_terms)){
			echo '<ul>';	
			reset($search_terms);
			while (list($key, $val) = each($search_terms)) {
				for ($i = 0; count($val) > $i; $i++){
					$q = $val[$i];
					$temp_query_vars = $search_terms;
					unset($temp_query_vars[$key][array_search($q, $search_terms[$key])]);
					$temp_query_vars = array_filter($temp_query_vars);

					// build the query that excludes this search term
					$excludesearch = '[<a href="'. $this->get_search_link($temp_query_vars) .'" title="Retry this search without this term">x</a>]';
					
					// build the URL singles out the search term
					$path = $this->get_search_link(array($key => array($q))) ;
					
					$matches = !empty($this->the_matching_post_counts[$key][$i]) ? ' ('. $this->the_matching_post_counts[$key][$i] .' matches)' : '';

					echo '<li><label>'. $this->taxonomy_name[$key] .'</label>: <a href="'. $path .'" title="Search only this term'. $matches .'">'. wp_specialchars($q) .'</a>&nbsp;'. $excludesearch .'</li>';
				}
			}
			echo '</ul>';
		}
	}

	public function admin_head_hook( $content ){
?>
<link rel='stylesheet' href='<?php echo $this->path_web ?>/css/editor.css' type='text/css' media='all' />
<?php
	
	}

	public function admin_menu_hook() {
		wp_register_script( 'scrib-editor', $this->path_web . '/js/editor.js', array('jquery-ui-sortable'), '1' );
		wp_enqueue_script( 'scrib-editor' );

		wp_register_script( 'jquery-tabindex', $this->path_web . '/js/jquery.keyboard-a11y.js', array('jquery'), '1' );
		wp_enqueue_script( 'jquery-tabindex' );
	
		add_action( 'admin_head', array(&$this, 'admin_head_hook') );
	}










	function meditor_metabox( ){
		global $post_ID;
		if( $post_ID && ( $data = get_post_meta( $post_ID, 'scrib_meditor_content', true )) ){
			if( is_string( $data ))
				$data = unserialize( $data );

			foreach( $data as $handle => $val )
				if( isset( $this->meditor_forms[ $handle ] ))
					$this->meditor_form( $handle, $this->meditor_forms[ $handle ], $val );

		}else if( isset( $this->meditor_forms[ $_GET['scrib_meditor_form'] ] )){
			$this->meditor_form( $_GET['scrib_meditor_form'], $this->meditor_forms[ $_GET['scrib_meditor_form'] ] );

		}else if( absint( $_GET['scrib_meditor_from'] ) && ( $data = get_post_meta( absint( $_GET['scrib_meditor_from'] ), 'scrib_meditor_content', true )) ){
			if( !empty( $_GET['scrib_meditor_add'] ))
				$data = apply_filters( 'scrib_meditor_add_'. preg_replace( '/[^a-z0-9]/i', '', $_GET['scrib_meditor_add'] ), $data, absint( $_GET['scrib_meditor_from'] ));
			foreach( $data as $handle => $val )
				if( isset( $this->meditor_forms[ $handle ] ))
					$this->meditor_form( $handle, $this->meditor_forms[ $handle ], $val );
		}
	}

	function meditor_form( $handle, &$prototype, &$data = array() ){
		echo '<ul id="scrib_meditor">';
		foreach( $prototype['_elements'] as $key => $val ){
			$val = is_array( $data[ $key ] ) ? $data[ $key ] : array( array() );
			echo '<li id="scrib_meditor-'. $handle .'-'. $key .'" class="fieldset_title">'.  ( $prototype['_elements'][ $key ]['_title'] ? '<h2>'. $prototype['_elements'][ $key ]['_title'] .'</h2>' : '' ) . ( $prototype['_elements'][ $key ]['_description'] ? '<div class="description">'. $prototype['_elements'][ $key ]['_description'] .'</div>' : '' ) .'<ul  class="fieldset_title'. ( $prototype['_elements'][ $key ]['_repeatable'] ? ' sortable">' : '">' );
			foreach( $val as $ordinal => $row )
				$this->meditor_form_sub( $handle, $prototype['_elements'][ $key ], $row, $key, $ordinal );
			echo '</ul></li>';
		}
		echo '</ul>';

		do_action( 'scrib_meditor_form_'. $handle );
	}

	function meditor_form_sub( $handle, $prototype, $data, $fieldset, $ordinal ){
		static $tabindex = 1;

		echo '<li class="fieldset '. ( $prototype['_repeatable'] ? 'repeatable ' : '' ) . $handle .' '. $fieldset .'"><ul class="fieldset">';
		foreach( $prototype['_elements'] as $key => $val ){
			$id =  "scrib_meditor-$handle-$fieldset-$ordinal-$key"; 
			$name =  "scrib_meditor[$handle][$fieldset][$ordinal][$key]"; 

			$val = $data[ $key ] ? stripslashes( $data[ $key ] ) : $prototype['_elements'][ $key ]['_input']['_default'];

			echo '<li class="field '. $handle .' '. $fieldset .' '. $key .'">'. ( $prototype['_elements'][ $key ]['_title'] ? '<label for="'. $id .'">'. $prototype['_elements'][ $key ]['_title'] .'</label>' : '<br />');

			switch( $prototype['_elements'][ $key ]['_input']['_type'] ){

				case 'select':
					echo '<select name="'. $name .'" id="'. $id .'" tabindex="'. $tabindex .'">';
					foreach( $prototype['_elements'][ $key ]['_input']['_values'] as $selval => $selname )
						echo '<option '. ( $selval == $val ? 'selected="selected" ' : '' ) .'value="'. $selval .'">'. $selname .'</option>';
					echo '</select>';		
					break;
	
				case 'checkbox':
					echo '<input type="checkbox" name="'. $name .'" id="'. $id .'" value="1"'. ( $val ? ' checked="checked"' : '' ) .'  tabindex="'. $tabindex .'" />';
					break;
	
				case 'textarea':
					echo '<textarea name="'. $name .'" id="'. $id .'"  tabindex="'. $tabindex .'">'. format_to_edit( $val ) .'</textarea>';		
					break;

				case '_function':
					if( is_callable( $prototype['_elements'][ $key ]['_input']['_function'] ))
						call_user_func( $prototype['_elements'][ $key ]['_input']['_function'] , $val, $handle, $id, $name );
					else
						_e( 'the requested function could not be called' );
					break;
	
				case 'text':
				default:
					echo '<input type="text" name="'. $name .'" id="'. $id .'" value="'. format_to_edit( $val ) .'"  '. ( $prototype['_elements'][ $key ]['_input']['autocomplete'] == 'off' ? 'autocomplete="off"' : '' ) .' tabindex="'. $tabindex .'" />';		

			}
			echo '</li>';

			$tabindex++;
		}
		echo '</ul></li>';
	}

	function meditor_add_related_commandlinks( $null, $handle ) {
		global $post_ID;
		if( $post_ID ){
			echo '<p id="scrib_meditor_addrelated">';
			foreach( $this->meditor_forms[ $handle ]['_relationships'] as $rkey => $relationship )
				echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add='. $rkey .'&scrib_meditor_from='. $post_ID ) .'">+ '. $relationship['_title'] .'</a> &nbsp; ';
			echo '</p>';
		}else{
			echo '<p id="scrib_meditor_addrelated_needsid">'. __( 'Save this record before attempting to add a related record.', 'scrib' ) .'</p>';
		}
	}

	function meditor_save_post($post_id, $post) {
		if ( $post_id && is_array( $_REQUEST['scrib_meditor'] )){

			// make sure meta is added to the post, not a revision
			if ( $the_post = wp_is_post_revision( $post_id ))
				$post_id = $the_post;

			$record = is_array( get_post_meta( $post_id, 'scrib_meditor_content', true )) ? get_post_meta( $post_id, 'scrib_meditor_content', true ) : array();

			if( is_array( $_REQUEST['scrib_meditor'] )){
				foreach( $_REQUEST['scrib_meditor'] as $key => &$val )
					unset( $record[ $this->meditor_input->form_key ] );

				$record = $this->meditor_merge_meta( $record, $this->meditor_sanitize_input( $_REQUEST['scrib_meditor'] ));

				add_post_meta( $post_id, 'scrib_meditor_content', $record, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $record );
	
				do_action( 'scrib_meditor_save_record', $post_id, $record );
			}
			
/*
			foreach( $_REQUEST['scrib_meditor'] as $this->meditor_input->form_key => $this->meditor_input->form ){
				unset( $record[ $this->meditor_input->form_key ] );
				foreach( $this->meditor_input->form as $this->meditor_input->group_key => $this->meditor_input->group )
					foreach( $this->meditor_input->group as $this->meditor_input->iteration_key => $this->meditor_input->iteration )
						foreach( $this->meditor_input->iteration as $this->meditor_input->key => $this->meditor_input->val ){
							if( is_callable( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] )){
								$filtered = FALSE;

								$filtered = call_user_func( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] , stripslashes( $this->meditor_input->val ));

								if( !empty( $filtered ))
									$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $filtered );
							}else{
								if( !empty( $record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] ))
									$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $this->meditor_input->val );
							}
						}
			}

			add_post_meta( $post_id, 'scrib_meditor_content', $record, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $record );

			do_action( 'scrib_meditor_save_record', $post_id, $record );
*/
		}
	}

	function meditor_merge_meta( $orig = array(), $new = array(), $nsourceid = FALSE ){
		if( $forms = array_intersect( array_keys( $orig ), array_keys( $new ))){
			$return = array();
			foreach( $forms as $form ){
				$sections = array_unique( array_merge( array_keys( $orig[ $form ] ), array_keys( $new[ $form ] )));

				foreach( $sections as $section ){
					// preserve the bits that are to be suppressed
					$suppress = array();
					foreach( $orig[ $form ][ $section ] as $key => $val )
						if( $val['suppress'] )
							$suppress[ $form ][ $section ][ $key ] = $val;

					// remove metadata that's sourced from the new sourceid
					if( $nsourceid )
						foreach( $orig[ $form ][ $section ] as $key => $val )
							if( isset( $val['src'] ) && ( $val['src'] == $nsourceid ))
								unset( $orig[ $form ][ $section ][ $key ] );

					$return[ $form ][ $section ] = $this->array_unique_deep( array_merge( count( $new[ $form ][ $section ] ) ? $new[ $form ][ $section ] : array() , count( $orig[ $form ][ $section ] ) ? $orig[ $form ][ $section ] : array() , $suppress ));
				}
			}

			if( $diff = array_diff( array_keys( $orig ), array_keys( $new ))){
				foreach( $diff as $form )
					$return[ $form ] = array_merge( is_array( $orig[ $form ] ) ? $orig[ $form ] : array(), is_array( $new[ $form ] ) ? $new[ $form ] : array() );
			}

			return( $return );

		}else{
			return( array_merge( is_array( $orig ) ? $orig : array(), is_array( $new ) ? $new : array() ));
		}
	}

	function meditor_sanitize_input( &$input ){
		$record = array();
		foreach( $input as $this->meditor_input->form_key => $this->meditor_input->form ){
			foreach( $this->meditor_input->form as $this->meditor_input->group_key => $this->meditor_input->group )
				foreach( $this->meditor_input->group as $this->meditor_input->iteration_key => $this->meditor_input->iteration )
					foreach( $this->meditor_input->iteration as $this->meditor_input->key => $this->meditor_input->val ){
						if( is_callable( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] )){
							$filtered = FALSE;

							$filtered = call_user_func( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] , stripslashes( $this->meditor_input->val ));

							if( !empty( $filtered ))
								$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $filtered );
						}else{
							if( !empty( $record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] ))
								$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $this->meditor_input->val );
						}
					}
		}
		return( $record );
	}

	function meditor_sanitize_month( $val ){
		if( !is_numeric( $val ) && !empty( $val )){
			if( strtotime( $val .' 2008' ))
				return( date( 'm', strtotime( $val .' 2008' )));
		}else{
			$val = absint( $val );
			if( $val > 0 &&  $val < 13 )
				return( $val );
		}
		return( FALSE );
	}
	
	function meditor_sanitize_day( $val ){
		$val = absint( $val );
		if( $val > 0 &&  $val < 32 )
			return( $val );
		return( FALSE );
	}

	function meditor_sanitize_selectlist( $val ){
		if( array_key_exists( $val, $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_input']['_values'] ))
			return( $val );
		return( FALSE );
	}

	function meditor_sanitize_punctuation( $str ) {
		// props to K. T. Lam of HKUST

		$str = html_entity_decode( $str );

/*
		//strip html entities, i.e. &#59;
		$htmlentity = '\&\#\d\d\;';
		$lead_htmlentity_pattern = '/^'.$htmlentity.'/';
		$trail_htmlentity_pattern = '/'.$htmlentity.'$/';
		$str = preg_replace($lead_htmlentity_pattern, '', preg_replace($trail_htmlentity_pattern, '', $str)); 
*/

		//strip ASCII punctuations
		$puncts = '\s\~\!\@\#\$\%\^\&\*\_\+\`\-\=\{\}\|\[\]\\\:\"\;\'\<\>\?\,\.\/';
		$lead_puncts_pattern = '/^['.$puncts.']+/';
		$trail_puncts_pattern = '/['.$puncts.']+$/';
		$str = preg_replace($trail_puncts_pattern, '', preg_replace($lead_puncts_pattern, '', $str));

		//strip repeated white space
		$puncts_pattern = '/[\s]+/';
		$str = preg_replace( $puncts_pattern, ' ', $str );

		//strip white space before punctuations
		$puncts_pattern = '/[\s]+([\~\!\@\#\$\%\^\&\*\_\+\`\-\=\{\}\|\[\]\\\:\"\;\'\<\>\?\,\.\/])+/';
		$str = preg_replace( $puncts_pattern, '\1', $str );

		//Strip ()
		$both_pattern = '/^[\(]([^\(|\)]+)[\)]$/';
		$trail_pattern = '/^([^\(]+)[\)]$/';
		$lead_pattern = '/^[\(]([^\)]+)$/';
		$str = preg_replace($lead_pattern, '\\1', preg_replace($trail_pattern,'\\1', preg_replace($both_pattern, '\\1', $str)));

		return( $str );
	}

	function meditor_sanitize_related( $val ){
		if( is_numeric( $val ) && get_permalink( absint( $val )) )
			return( absint( $val ) );

		if( $url = sanitize_url( $val) ){
			if( $post_id = url_to_postid( $url ) )
				return( $post_id );
			else
				return( $url );
		}

		return( FALSE );
	}

	function meditor_strip_initial_articles( $content ) {
		// TODO: add more articles, such as those from here: http://www.loc.gov/marc/bibliographic/bdapndxf.html
		return( str_ireplace( $this->initial_articles, '', $content ));
	}

	function meditor_pre_save_filters( $content ) {
		if ( is_array( $_REQUEST['scrib_meditor'] )){
			switch( current_filter() ){
				case 'pre_post_title':
					return( apply_filters( 'scrib_meditor_pre_title', $content, $_REQUEST['scrib_meditor'] ));
					break;

				case 'pre_post_excerpt':
					return( apply_filters( 'scrib_meditor_pre_excerpt', $content, $_REQUEST['scrib_meditor'] ));
					break;

				case 'pre_post_content':
				default:
					return( apply_filters( 'scrib_meditor_pre_content', $content, $_REQUEST['scrib_meditor'] ));
			}
		}
		return( $content );
	}



	public function meditor_register_menus(){
		if( ( 'post-new.php' == basename( $_SERVER['PHP_SELF'] )) && ( isset( $_GET['posted'] ) ) && ( !isset( $_GET['scrib_meditor_add'] ) ) && ( $form = key( get_post_meta( $_GET['posted'], 'scrib_meditor_content', true )) ) ){
				$_GET['scrib_meditor_add'] = 'sibling';
				$_GET['scrib_meditor_from'] = $_GET['posted'];
				die( wp_redirect( admin_url( 'post-new.php' ) .'?'. http_build_query( $_GET ) ));
		}

		add_submenu_page('post-new.php', 'Add New Bibliographic/Archive Record', 'New Catalog Record', 'edit_posts',  'post-new.php?scrib_meditor_form=marcish' );
	}

	public function meditor_register( $handle , $prototype ){
		add_action( 'admin_menu', array(&$this, 'meditor_register_menus'));

		if( isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		$this->meditor_forms[ $handle ] = $prototype;
	}

	public function meditor_unregister( $handle ){
		if( !isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		unset( $this->meditor_forms[ $handle ] );
	}

	public function meditor_form_hook(){
		add_action('admin_footer', array(&$this, 'meditor_footer_activatejs'));
	}

	public function meditor_footer_activatejs(){
?>
		<script type="text/javascript">
			scrib_meditor();
		</script>
<?php
	}

	public function meditor_make_content_closable( $content ){
		return( '<div class="inside">'. $content .'</div>');
	}

	public function meditor_suggest_tags(){
		if ( isset( $_GET['tax'] )){
			$taxonomy = explode(',', $_GET['tax'] );
			$taxonomy = array_filter( array_map( 'sanitize_title', array_map( 'trim', $taxonomy )));
		}else{
			$taxonomy = $this->taxonomies_for_suggest;
		}

		$s = sanitize_title( trim( $_REQUEST['q'] ));
		if ( strlen( $s ) < 3 )
			$s = '';

		$cachekey = md5( $s . implode( $taxonomy ));

		if( !$suggestion = wp_cache_get( $cachekey , 'scrib_suggest_meditor' )){
			if ( strlen( $s ) < 3 ){
				foreach( get_terms( $taxonomy, array( 'number' => 25, 'orderby' => 'count', 'order' => 'DESC' ) ) as $term )
					$suggestion[] = $term->name;

				$suggestion = implode( $suggestion, "\n" );
			}else{
				global $wpdb;
	
				$suggestion = implode( array_unique( $wpdb->get_col( "SELECT t.name, tt.taxonomy, LENGTH(t.name) AS len
					FROM $wpdb->term_taxonomy AS tt 
					INNER JOIN $wpdb->terms AS t ON tt.term_id = t.term_id 
					WHERE tt.taxonomy IN('" . implode( "','", $taxonomy ). "') 
					AND t.slug LIKE ('" . $s . "%')
					ORDER BY len ASC, tt.count DESC
					LIMIT 25;
				")), "\n" );
			}

			wp_cache_set( $cachekey , $suggestion, 'scrib_suggest_meditor' );
		}

		echo $suggestion;
		die;
	}



	public function marcish_register( ){
		$subject_types = array(
			'subject' => 'Topical Term',
			'genre' => 'Genre',
			'person' => 'Person/Character',
			'place' => 'Place',
			'time' => 'Time',
			'tag' => 'Tag',
			'exhibit' => 'Exhibit',
			'award' => 'Award',
			'readlevel' => 'Reading Level',
		);

		$this->meditor_register( 'marcish', 
			array(
				'_title' => 'Bibliographic and Archive Item Record',
				'_elements' => array( 
					'title' => array(
						'_title' => 'Additional Titles',
						'_description' => 'Alternate titles or additional forms of this title. Think translations, uniform, and series titles (<a href="http://about.scriblio.net/wiki/meditor/marcish/title" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'attribution' => array(
						'_title' => 'Attribution',
						'_description' => 'The statement of responsibility for this work (<a href="http://about.scriblio.net/wiki/meditor/marcish/attribution" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'creator' => array(
						'_title' => 'Creator',
						'_description' => 'Authors, editors, producers, and others that contributed to the creation of this work (<a href="http://about.scriblio.net/wiki/meditor/marcish/creator" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'name' => array(
								'_title' => 'Name',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'role' => array(
								'_title' => 'Role',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'subject' => array(
						'_title' => 'Subject',
						'_description' => 'Words and phrases that descripe the content of the work (<a href="http://about.scriblio.net/wiki/meditor/marcish/subject" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'b_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'b' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'c_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'd_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'd' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'e_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'e' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'f_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'f' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'g_type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => $subject_types,									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'g' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'dictionary' => array(
								'_title' => 'Dict.',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'subject_date' => array(
						'_title' => 'Date Coverage',
						'_description' => 'A calendar representation of the content of the work (<a href="http://about.scriblio.net/wiki/meditor/marcish/subject_date" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'y' => array(
								'_title' => 'Year',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'm' => array(
								'_title' => 'Month',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_month' ),
							),
							'd' => array(
								'_title' => 'Day',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_day' ),
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'exact' => 'Exactly',
										'approx' => 'Approximately',
										'before' => 'Before',
										'after' => 'After',
										'circa' => 'Circa',
										'decade' => 'Within Decade',
										'century' => 'Within Century',
									),
									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'subject_geo' => array(
						'_title' => 'Geographic Coverage',
						'_description' => 'A geographic coordinate representation of the content of the work (<a href="http://about.scriblio.net/wiki/meditor/marcish/subject_geo" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'point' => array(
								'_title' => 'Point',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'wp_filter_nohtml_kses' ),
							),
							'bounds' => array(
								'_title' => 'Bounds',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'wp_filter_nohtml_kses' ),
							),
							'name' => array(
								'_title' => 'Name',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'wp_filter_nohtml_kses' ),
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'callnumbers' => array(
						'_title' => 'Call Number',
						'_description' => 'The LC or Dewey call number and location for this work (<a href="http://about.scriblio.net/wiki/meditor/marcish/callnumbers" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'type' => array(
								'_title' => 'Type',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'lc' => 'LC',
										'dewey' => 'Dewey',
									),
									'_default' => 'dewey',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'number' => array(
								'_title' => 'Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'location' => array(
								'_title' => 'Location',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'text' => array(
						'_title' => 'Textual Content',
						'_description' => 'A description, transcription, translation, or other long-form textual content related to the work (<a href="http://about.scriblio.net/wiki/meditor/marcish/text" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'type' => array(
								'_title' => 'Type',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'description' => 'Description',
										'transcription' => 'Transcription',
										'translation' => 'Translation',
										'contents' => 'Contents',
										'review' => 'Review',
										'notes' => 'Notes',
										'firstwords' => 'First Words',
										'lastwords' => 'Last Words',
										'dedication' => 'Dedication',
										'quotes' => 'Notable Quotations',
										'sample' => 'Sample',
									),
									'_default' => 'description',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'lang' => array(
								'_title' => 'Language',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'content' => array(
								'_title' => 'Content',
								'_input' => array(
									'_type' => 'textarea',
								),
								'_sanitize' => 'wp_filter_kses',
							),
							'notes' => array(
								'_title' => 'Notes',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'published' => array(
						'_title' => 'Publication Info',
						'_description' => 'Publication info (<a href="http://about.scriblio.net/wiki/meditor/marcish/published" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'cy' => array(
								'_title' => 'Year',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'cm' => array(
								'_title' => 'Month',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_month' ),
							),
							'cd' => array(
								'_title' => 'Day',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_day' ),
							),
							'cc' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'nodate' => 'Undated',
										'exact' => 'Exactly',
										'approx' => 'Approximately',
										'before' => 'Before',
										'after' => 'After',
										'circa' => 'Circa',
										'decade' => 'Within Decade',
										'century' => 'Within Century',
									),
									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'edition' => array(
								'_title' => 'Edition',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'lang' => array(
								'_title' => 'Language',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'publisher' => array(
								'_title' => 'Publisher',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'copyright' => array(
								'_title' => 'Copyright',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'uc' => 'Uncertain',
										'c' => 'Copyrighted',
										'cc' => 'Creative Commons',
										'pd' => 'Public Domain',
									),
									'_default' => 'uc',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'copyright_note' => array(
								'_title' => 'Note',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'description_physical' => array(
						'_title' => 'Physical Description',
						'_description' => 'Physical description (<a href="http://about.scriblio.net/wiki/meditor/marcish/description_physical" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'dw' => array(
								'_title' => 'Width',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'dh' => array(
								'_title' => 'Height',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'dd' => array(
								'_title' => 'Depth',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'du' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'inch' => 'Inches',
										'cm' => 'Centimeters',
									),
									'_default' => 'inches',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'wv' => array(
								'_title' => 'Weight',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'wu' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'ounce' => 'Ounces',
										'pound' => 'Pounds',
										'g' => 'Grams',
										'kg' => 'Kilograms',
									),
									'_default' => 'ounce',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'duration' => array(
								'_title' => 'Length',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'duration_units' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'pages' => 'Pages',
										'minutes' => 'Minutes',
									),
									'_default' => 'pages',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'cv' => array(
								'_title' => 'Cost',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'cu' => array(
								'_title' => 'Currency',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'wp_filter_nohtml_kses' ),
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'wp_filter_nohtml_kses' ),
							),
						),
					),
					'linked_urls' => array(
						'_title' => 'Linked URL',
						'_description' => 'Web links (<a href="http://about.scriblio.net/wiki/meditor/marcish/linked_urls" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'name' => array(
								'_title' => 'Name',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'href' => array(
								'_title' => 'href',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'format' => array(
						'_title' => 'Format',
						'_description' => 'Format (<a href="http://about.scriblio.net/wiki/meditor/marcish/format" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'b' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'd' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'e' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'f' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'g' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'dictionary' => array(
								'_title' => 'Dict.',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'idnumbers' => array(
						'_title' => 'Standard Numbers',
						'_description' => 'ISBNs, ISSNs, and other numbers identifying the work (<a href="http://about.scriblio.net/wiki/meditor/marcish/idnumbers" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'type' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'id' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'suppress' => array(
								'_title' => 'Suppress',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
							'src' => array(
								'_title' => 'Source',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'source' => array(
						'_title' => 'Archival Source',
						'_description' => 'Where did this work come from (for archive records) (<a href="http://about.scriblio.net/wiki/meditor/marcish/source" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => FALSE,
						'_elements' => array( 

							'file' => array(
								'_title' => 'File Name',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'dy' => array(
								'_title' => 'Digitized Year',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'dm' => array(
								'_title' => 'Month',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_month' ),
							),
							'dd' => array(
								'_title' => 'Day',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_day' ),
							),
							'box' => array(
								'_title' => 'Box Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'folder' => array(
								'_title' => 'Folder Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'collection' => array(
								'_title' => 'Collection',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'collection_num' => array(
								'_title' => 'Collection Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'publisher' => array(
								'_title' => 'Publisher',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'related' => array(
						'_title' => 'Related Records',
						'_description' => 'The relationship of this work to other works (<a href="http://about.scriblio.net/wiki/meditor/marcish/related" title="More information at the Scriblio website.">more info</a>).',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'rel' => array(
								'_title' => 'Relationship',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'parent' => 'Parent',
										'child' => 'Child',
										'next' => 'Next In Series/Next Page',
										'previous' => 'Previous In Series/Previous Page',
										'reverse' => 'Reverse Side',
									),
									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'record' => array(
								'_title' => 'Record',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_related' ),
							),
						),
					),
					'addrecord' => array(
						'_title' => 'Add New Record',
						'_description' => '<a href="http://about.scriblio.net/wiki/meditor/marcish/addrecord" title="More information at the Scriblio website.">More info</a>.',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => '_function',
									'_function' => array( &$this, 'meditor_add_related_commandlinks' ),
								),
							),
						),
					),
				),
				'_relationships' => array(
					'parent' => array( '_title' => 'Parent' , '_rel_inverse' => 'child' ),
					'child' => array( '_title' => 'Child' , '_rel_inverse' => 'parent' ),
					'next' => array( '_title' => 'Next In Series/Next Page' , '_rel_inverse' => 'previous' ),
					'previous' => array( '_title' => 'Previous In Series/Previous Page' , '_rel_inverse' => 'next' ),
					'reverse' => array( '_title' => 'Reverse Side' , '_rel_inverse' => 'reverse' ),
					'sibling' => array( '_title' => 'Sibling' , '_rel_inverse' => FALSE ),
				),
			)
		);

		// taxonomies for all default forms
		$args = array('hierarchical' => false, 'update_count_callback' => '_update_post_term_count', 'rewrite' => false, 'query_var' => false);
		register_taxonomy( 'creator', 'post', $args ); // creator/author
		register_taxonomy( 'creatorkey', 'post', $args ); // creator/author keyword
		register_taxonomy( 'title', 'post', $args );
		register_taxonomy( 'lang', 'post', $args ); // language
		register_taxonomy( 'cy', 'post', $args ); // created/published year
		register_taxonomy( 'cm', 'post', $args ); // created/published month
		register_taxonomy( 'format', 'post', $args );
		register_taxonomy( 'subject', 'post', $args );
		register_taxonomy( 'subjkey', 'post', $args );
		register_taxonomy( 'genre', 'post', $args );
		register_taxonomy( 'person', 'post', $args );
		register_taxonomy( 'place', 'post', $args );
		register_taxonomy( 'time', 'post', $args );
		register_taxonomy( 'exhibit', 'post', $args );
		register_taxonomy( 'sy', 'post', $args ); // subject year
		register_taxonomy( 'sm', 'post', $args ); // subject month
		register_taxonomy( 'sd', 'post', $args ); // subject day
		register_taxonomy( 'collection', 'post', $args );
		register_taxonomy( 'sourceid', 'post', $args );
		register_taxonomy( 'isbn', 'post', $args );
		register_taxonomy( 'issn', 'post', $args );
		register_taxonomy( 'lccn', 'post', $args );
		register_taxonomy( 'asin', 'post', $args );
		register_taxonomy( 'ean', 'post', $args );
		register_taxonomy( 'oclc', 'post', $args );

		// actions and filters for marcish form
		add_action('scrib_meditor_form_marcish', array(&$this, 'meditor_form_hook'));

		add_filter('bsuite_post_icon', array( &$this, 'marcish_the_bsuite_post_icon' ), 5, 2);

		add_filter('scrib_meditor_pre_excerpt', array(&$this, 'marcish_pre_excerpt'), 1, 2);
		add_filter('scrib_meditor_pre_content', array(&$this, 'marcish_pre_content'), 1, 2);
		add_filter( 'the_content', array(&$this, 'marcish_the_content'));
		add_filter( 'the_excerpt', array(&$this, 'marcish_the_excerpt'));

		add_filter('scrib_availability_excerpt', array(&$this, 'marcish_availability'), 10, 3);
		add_filter('scrib_availability_content', array(&$this, 'marcish_availability'), 10, 3);
		add_filter('the_author', array( &$this, 'marcish_the_author_filter' ), 1);
		add_filter('author_link', array( &$this, 'marcish_author_link_filter' ), 1);

		add_action('scrib_meditor_save_record', array(&$this, 'marcish_save_record'), 1, 2);

		add_filter('scrib_meditor_add_parent', array(&$this, 'marcish_add_parent'), 1, 2);
		add_filter('scrib_meditor_add_child', array(&$this, 'marcish_add_child'), 1, 2);
		add_filter('scrib_meditor_add_next', array(&$this, 'marcish_add_next'), 1, 2);
		add_filter('scrib_meditor_add_previous', array(&$this, 'marcish_add_previous'), 1, 2);
		add_filter('scrib_meditor_add_reverse', array(&$this, 'marcish_add_reverse'), 1, 2);
		add_filter('scrib_meditor_add_sibling', array(&$this, 'marcish_add_sibling'), 1, 2);
	}

	public function marcish_unregister(){
		remove_action('scrib_meditor_form_marcish', array(&$this, 'meditor_form_hook'));

		remove_filter('bsuite_post_icon', array( &$this, 'marcish_the_bsuite_post_icon' ), 5, 2);

		remove_filter('scrib_meditor_pre_excerpt', array(&$this, 'marcish_pre_excerpt'), 1, 2);
		remove_filter('scrib_meditor_pre_content', array(&$this, 'marcish_pre_content'), 1, 2);
		remove_filter( 'the_content', array(&$this, 'marcish_the_content'));
		remove_filter( 'the_excerpt', array(&$this, 'marcish_the_excerpt'));

		remove_filter('scrib_availability_excerpt', array(&$this, 'marcish_availability'), 10, 3);
		remove_filter('scrib_availability_content', array(&$this, 'marcish_availability'), 10, 3);
		remove_filter('the_author', array( &$this, 'marcish_the_author_filter' ), 1);
		remove_filter('author_link', array( &$this, 'marcish_author_link_filter' ), 1);

		remove_action('scrib_meditor_save_record', array(&$this, 'marcish_save_record'), 1, 2);

		remove_filter('scrib_meditor_add_parent', array(&$this, 'marcish_add_parent'), 1, 2);
		remove_filter('scrib_meditor_add_child', array(&$this, 'marcish_add_child'), 1, 2);
		remove_filter('scrib_meditor_add_next', array(&$this, 'marcish_add_next'), 1, 2);
		remove_filter('scrib_meditor_add_previous', array(&$this, 'marcish_add_previous'), 1, 2);
		remove_filter('scrib_meditor_add_reverse', array(&$this, 'marcish_add_reverse'), 1, 2);
		remove_filter('scrib_meditor_add_sibling', array(&$this, 'marcish_add_sibling'), 1, 2);
	}

	function marcish_the_bsuite_post_icon( &$input, $id ) {
		if( is_array( $input ))
			return( $input );

		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish'] )){
			$title = trim( $r['marcish']['title'][0]['a'] );
			if( strpos( $title, ':', 5 ))
				$title = substr( $title, 0, strpos( $title, ':', 5 ));
			$attrib = trim( $r['marcish']['attribution'][0]['a'] );
			if( strpos( $attrib, ';', 5 ))
				$attrib = substr( $attrib, 0, strpos( $attrib, ';', 5 ));
			return( array( 
				't' => array( 
					'file' => dirname( __FILE__ ) .'/img/post_icon_default/s.jpg',
					'url' => 'http://api.scriblio.net/v01a/fakejacket/'. urlencode( $title ) .'?author='. urlencode( $attrib ) .'&size=1',
					'w' => '75',
					'h' => '100',
					), 
				's' => array( 
					'file' => dirname( __FILE__ ) .'/img/post_icon_default/s.jpg',
					'url' => 'http://api.scriblio.net/v01a/fakejacket/'. urlencode( $title ) .'?author='. urlencode( $attrib ) .'&size=2',
					'w' => '100',
					'h' => '132',
					), 
				'm' => array( 
					'file' => dirname( __FILE__ ) .'/img/post_icon_default/m.jpg',
					'url' => 'http://api.scriblio.net/v01a/fakejacket/'. urlencode( $title ) .'?author='. urlencode( $attrib ) .'&size=3',
					'w' => '135',
					'h' => '180',
					), 
				'l' => array( 
					'file' => dirname( __FILE__ ) .'/img/post_icon_default/l.jpg',
					'url' => 'http://api.scriblio.net/v01a/fakejacket/'. urlencode( $title ) .'?author='. urlencode( $attrib ) .'&size=4',
					'w' => '240',
					'h' => '320',
					), 
				'b' => array( 
					'file' => dirname( __FILE__ ) .'/img/post_icon_default/b.jpg',
					'url' => 'http://api.scriblio.net/v01a/fakejacket/'. urlencode( $title ) .'?author='. urlencode( $attrib ) .'&size=5',
					'w' => '500',
					'h' => '665',
					), 
				)
			);
		}

//http://api.scriblio.net/v01a/fakejacket/This+Land+Is+Their+Land?author=Barbara+Ehrenreich.&size=4&style=4

	}

	function marcish_parse_parts( &$r ){
		$parsed = array();
		foreach( $r['idnumbers'] as $temp ){
			switch( $temp['type'] ){ 
				case 'sourceid' :
					$parsed['idnumbers']['sourceid'][] = $temp['id'];
					break; 
				case 'lccn' :
					$parsed['idnumbers']['lccn'][] = $temp['id'];
					break; 
				case 'isbn' :
					$parsed['idnumbers']['isbn'][] = $temp['id'];
					break; 
				case 'issn' :
					$parsed['idnumbers']['issn'][] = $temp['id'];
					break; 
				case 'asin' :
					$parsed['idnumbers']['asin'][] = $temp['id'];
					break; 
				case 'olid' :
					$parsed['idnumbers']['olid'][] = $temp['id'];
					break; 
			} 
		}
		if ( isset( $parsed['idnumbers']['sourceid'] ))
			$parsed['idnumbers']['sourceid'] = $this->array_unique_deep( $parsed['idnumbers']['sourceid'] );
		if ( isset( $parsed['idnumbers']['lccn'] ))
			$parsed['idnumbers']['lccn'] = $this->array_unique_deep( $parsed['idnumbers']['lccn'] );
		if ( isset( $parsed['idnumbers']['isbn'] ))
			$parsed['idnumbers']['isbn'] = $this->array_unique_deep( $parsed['idnumbers']['isbn'] );
		if ( isset( $parsed['idnumbers']['issn'] ))
			$parsed['idnumbers']['issn'] = $this->array_unique_deep( $parsed['idnumbers']['issn'] );
		if ( isset( $parsed['idnumbers']['asin'] ))
			$parsed['idnumbers']['asin'] = $this->array_unique_deep( $parsed['idnumbers']['asin'] );
		if ( isset( $parsed['idnumbers']['olid'] ))
			$parsed['idnumbers']['olid'] = $this->array_unique_deep( $parsed['idnumbers']['olid'] );

		foreach( $r['text'] as $temp ){
			switch( $temp['type'] ){ 
				case 'description' :
					$parsed['description'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'transcription' :
					$parsed['transcription'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'translation' :
					$parsed['translation'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'contents' :
					$parsed['contents'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'review' :
					$parsed['review'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'notes' :
					$parsed['notes'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'firstwords' :
					$parsed['firstwords'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'lastwords' :
					$parsed['lastwords'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'dedication' :
					$parsed['dedication'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'quotes' :
					$parsed['quotes'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
				case 'sample' :
					$parsed['sample'][] = wpautop( convert_chars( wptexturize( $temp['content'] )));
					break; 
			} 
		}

		$spare_keys = array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' );
		foreach( $r['subject'] as $temp ){
			$subjline = array();
			foreach( $spare_keys as $spare_key ){
				if( isset(  $temp[ $spare_key ] )){
					switch( $temp[ $spare_key .'_type' ] ){
						case 'genre' :
							$parsed['genre'][] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
							break; 
						case 'person' :
							$parsed['person'][] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
							break; 
						case 'place' :
							$parsed['place'][] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
							break; 
						case 'time' :
							$parsed['time'][] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
							break; 
						case 'exhibit' :
							$parsed['exhibit'][] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
							break; 
					} 
					$parsed['subjkey'][] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
					$subjline[] = array( 'type' => $temp[ $spare_key .'_type' ], 'value' => $temp[ $spare_key ] );
				}
			}
			if( count( $subjline ))
				$parsed['subject'][] = $subjline;
		}
		if ( isset( $parsed['subject'] ))
			$parsed['subject'] = $this->array_unique_deep( $parsed['subject'] );
		if ( isset( $parsed['genre'] ))
			$parsed['genre'] = $this->array_unique_deep( $parsed['genre'] );
		if ( isset( $parsed['person'] ))
			$parsed['person'] = $this->array_unique_deep( $parsed['person'] );
		if ( isset( $parsed['place'] ))
			$parsed['place'] = $this->array_unique_deep( $parsed['place'] );
		if ( isset( $parsed['time'] ))
			$parsed['time'] = $this->array_unique_deep( $parsed['time'] );
		if ( isset( $parsed['exhibit'] ))
			$parsed['exhibit'] = $this->array_unique_deep( $parsed['exhibit'] );

		return( $parsed );
	}

	function marcish_pre_excerpt( $content, $r ) {
		if( isset( $r['marcish'] ))
			return( $this->marcish_parse_excerpt( $r['marcish'] ));
		return( $content );
	}

	function marcish_pre_content( $content, $r ) {
		if( isset( $r['marcish'] ))
			return( $this->marcish_parse_words( $r['marcish'] ));
		return( $content );
	}

	public function marcish_the_excerpt( $content ){
		global $id;
		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish'] ))
			return( $this->marcish_parse_excerpt( $r['marcish'] ));

		return( $content );
	}

	function marcish_parse_excerpt( &$r ){
		global $id, $bsuite;

		$parsed = $this->marcish_parse_parts( $r );
		$result = '<ul class="summaryrecord">';

		$result .= '<li class="image"><a href="'. get_permalink( $id ) .'" rel="bookmark" title="Permanent Link to '. attribute_escape( get_the_title( $id )) .'">'. $bsuite->icon_get_h( $id, 's' ) .'</a></li>';

		if( isset( $r['attribution'][0]['a'] ))
			$result .= '<li class="attribution"><h3>Attribution</h3>'. $r['attribution'][0]['a'] .'</li>';

		$pubdeets = array();
		if( isset( $r['format'][0]['a'] ))
			$pubdeets[] = '<span class="format">'. $r['format'][0]['a'] .'</span>';

		if( isset( $r['published'][0]['edition'] ))
			$pubdeets[] = '<span class="edition">'. $r['published'][0]['edition'] .'</span>';

		if( isset( $r['published'][0]['publisher'] ))
			$pubdeets[] = '<span class="publisher">'. $r['published'][0]['publisher'] .'</span>';

		if( isset( $r['published'][0]['cy'] ))
			$pubdeets[] = '<span class="pubyear">'. $r['published'][0]['cy'] .'</span>';

		if( count( $pubdeets ))
			$result .= '<li class="publication_details"><h3>Publication Details</h3>'. implode( '<span class="meta-sep">, </span>', $pubdeets ) .'</li>';

		if( isset( $r['linked_urls'][0]['href'] )){
			$result .= '<li class="linked_urls">'. ( 1 < count( $r['linked_urls'] ) ? '<h3>Links</h3>' : '<h3>Link</h3>' ) .'<ul>';
			foreach( $r['linked_urls'] as $temp )
				$result .= '<li><a href="' . $temp['href'] .'" title="go to this linked website">' . $temp['name'] .'</a></li>';
			$result .= '</ul></li>';
		}

		if( isset( $parsed['description'][0] )){
			$result .= '<li class="description"><h3>Description</h3>' . $parsed['description'][0] .'</li>';
		}

		if( isset( $parsed['subjkey'][0] )){
			$tags = array();
			foreach( $parsed['subjkey'] as $temp )
				$tags[] = '<a href="'. $this->get_tag_link( array( 'taxonomy' => $temp['type'], 'slug' => urlencode( $temp['value'] ))).'" rel="tag">' . $temp['value'] . '</a>';


			// authors or, er, creators
			if( isset( $r['creator'][0]['name'] ))
				foreach( $r['creator'] as $temp )
					$tags[] = '<a href="'. $this->get_tag_link( array( 'taxonomy' => 'creator', 'slug' => urlencode( $temp['name'] ))).'" rel="tag">' . $temp['name'] . '</a>';

			$result .= '<li class="tags"><h3>Tags</h3> '. implode( ' &middot; ', $tags ) .'</li>';
		}

		if( is_array( $parsed['idnumbers'] ))
			$result .= '<li class="availability"><h3>Availability</h3><ul>'. apply_filters( 'scrib_availability_excerpt', '', $id, $parsed['idnumbers']) .'</ul></li>';

		$result .= '</ul>';

		return($result);
	}

	public function marcish_the_content( $content ){
		global $id;
		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish'] ))
			return( $this->marcish_parse_content( $r['marcish'] ));

		return( $content );
	}

	function marcish_parse_content( &$r ){
		global $id, $bsuite;
		$parsed = $this->marcish_parse_parts( $r );

		$result = '<ul class="fullrecord">';

		$result .= '<li class="image">'. $bsuite->icon_get_h( $id, 's' ) .'</li>';

		if( isset( $r['title'][0]['a'] )){
			$result .= '<li class="title">'. ( 1 < count( $r['title'] ) ? '<h3>Titles</h3>' : '<h3>Title</h3>') .'<ul>';
			foreach( $r['title'] as $temp ){
				$result .= '<li>' . $temp['a'] . '</li>';
			}
			$result .= '</ul></li>';
		}

		if( isset( $r['attribution'][0]['a'] ))
			$result .= '<li class="attribution"><h3>Attribution</h3>'. $r['attribution'][0]['a'] .'</li>';

		$pubdeets = array();
		if( isset( $r['format'][0]['a'] ))
			$pubdeets[] = '<span class="format">'. $r['format'][0]['a'] .'</span>';

		if( isset( $r['published'][0]['edition'] ))
			$pubdeets[] = '<span class="edition">'. $r['published'][0]['edition'] .'</span>';

		if( isset( $r['published'][0]['publisher'] ))
			$pubdeets[] = '<span class="publisher">'. $r['published'][0]['publisher'] .'</span>';

		if( isset( $r['published'][0]['cy'] ))
			$pubdeets[] = '<span class="pubyear">'. $r['published'][0]['cy'] .'</span>';

		if( count( $pubdeets ))
			$result .= '<li class="publication_details"><h3>Publication Details</h3>'. implode( '<span class="meta-sep">, </span>', $pubdeets ) .'</li>';

		if( is_array( $parsed['idnumbers'] ))
			$result .= '<li class="availability"><h3>Availability</h3><ul>'. apply_filters( 'scrib_availability_content', '', $id, $parsed['idnumbers']) .'</ul></li>';

		if( isset( $r['callnumbers'][0]['number'] )){
			$result .= '<li class="callnumber">'. ( 1 < count( $r['callnumbers'] ) ? '<h3>Call Numbers</h3>' : '<h3>Call Number</h3>') .'<ul>';
			foreach( $r['callnumbers'] as $temp )
				$result .= '<li class="call-number-'. $temp['type'] .'">' . $temp['number'] .' ('. $temp['type'] .')'. ( isset( $temp['location'] ) ? ', '. $temp['location'] : '' ) .'</li>';
			$result .= '</ul></li>';
		}

		if( isset( $r['linked_urls'][0]['href'] )){
			$result .= '<li class="linked_urls">'. ( 1 < count( $r['linked_urls'] ) ? '<h3>Links</h3>' : '<h3>Link</h3>' ) .'<ul>';
			foreach( $r['linked_urls'] as $temp )
				$result .= '<li><a href="' . $temp['href'] .'" title="go to this linked website">' . $temp['name'] .'</a></li>';
			$result .= '</ul></li>';
		}

/* this was the amazon description
		if( !empty( $r['description'] ))
			$result .= '<li class="description"><h3>Description</h3>'. $r['description'] .'</li>';
		else if( !empty( $r['shortdescription'] ))
			$result .= '<li class="description"><h3>Description</h3>'. $r['shortdescription'] .'</li>';
*/

		// authors or, er, creators
		if( isset( $r['creator'][0]['name'] )){
			$result .= '<li class="creator">'. ( 1 < count( $r['creator'] ) ? '<h3>Authors</h3>' : '<h3>Author</h3>') .'<ul>';
			foreach( $r['creator'] as $temp ){
				$result .= '<li><a href="'. $this->get_tag_link( array( 'taxonomy' => 'creator', 'slug' => urlencode( $temp['name'] ))).'" rel="tag">' . $temp['name'] . '</a>' . ( 'Author' <> $temp['role'] ? ', ' . $temp['role'] : '' ) .'</li>';
			}
			$result .= '</ul></li>';
		}

		if( isset( $parsed['genre'] )){
			$result .= '<li class="genre"><h3>Genre</h3><ul>';
			foreach( $parsed['genre'] as $temp )
				$result .= '<li><a href="'. $this->get_tag_link( array( 'taxonomy' => $temp['type'], 'slug' => urlencode( $temp['value'] ))).'" rel="tag">' . $temp['value'] . '</a></li>';
			$result .= '</ul></li>';
		}
		if( isset( $parsed['place'] )){
			$result .= '<li class="place"><h3>Place</h3><ul>';
			foreach( $parsed['place'] as $temp )
				$result .= '<li><a href="'. $this->get_tag_link( array( 'taxonomy' => $temp['type'], 'slug' => urlencode( $temp['value'] ))).'" rel="tag">' . $temp['value'] . '</a></li>';
			$result .= '</ul></li>';
		}
		if( isset( $parsed['time'] )){
			$result .= '<li class="time"><h3>Time</h3><ul>';
			foreach( $parsed['time'] as $temp )
				$result .= '<li><a href="'. $this->get_tag_link( array( 'taxonomy' => $temp['type'], 'slug' => urlencode( $temp['value'] ))).'" rel="tag">' . $temp['value'] . '</a></li>';
			$result .= '</ul></li>';
		}
		if( isset( $parsed['subject'] )){
			$result .= '<li class="subject"><h3>Subject</h3><ul>';
			foreach( $parsed['subject'] as $temp ){
				$temptext = $templink = array();
				foreach( $temp as $temptoo ){
					$templink[ $temptoo['type'] ][] = $temptoo['value'];
					$temptext[] = '<span>'. $temptoo['value'] . '</span>';
				}
				$result .= '<li><a href="'. $this->get_search_link( $templink ) .'" title="Search for other items matching this subject.">'. implode( ' &mdash; ', $temptext ) .'</a></li>';
			}
			$result .= '</ul></li>';
		}

		// do the notes and contents
		if( isset( $parsed['notes'] )){
			$result .= '<li class="notes"><h3>Notes</h3><ul>';
			foreach( $parsed['notes'] as $temp )
				$result .= '<li>' . $temp . '</li>';
			$result .= '</ul></li>';
		}

		if( isset( $parsed['contents'][0] )){
			$result .= '<li class="contents"><h3>Contents</h3>' . $parsed['contents'][0] .'</li>';
		}


		// handle most of the standard numbers
		if( isset( $parsed['idnumbers']['isbn'] )){
			$result .= '<li class="isbn"><h3>ISBN</h3><ul>';
			foreach( $parsed['idnumbers']['isbn'] as $temp )
				$result .= '<li id="isbn-'. strtolower( $temp ) .'">'. strtolower( $temp ) . '</li>';
			$result .= '</ul></li>';
		}
		if( isset( $parsed['idnumbers']['issn'] )){
			$result .= '<li class="issn"><h3>ISSN</h3><ul>';
			foreach( $parsed['idnumbers']['issn'] as $temp )
				$result .= '<li id="issn-'. strtolower( $temp ) .'">'. strtolower( $temp ) . '</li>';
			$result .= '</ul></li>';
		}
		if( isset( $parsed['idnumbers']['lccn'] )){
			$result .= '<li class="lccn"><h3>LCCN</h3><ul>';
			foreach( $parsed['idnumbers']['lccn'] as $temp )
				$result .= '<li id="lccn-'. $temp .'"><a href="http://lccn.loc.gov/'. urlencode( $temp ) .'?referer=scriblio" rel="tag">'. $temp .'</a></li>';
			$result .= '</ul></li>';
		}
		if( isset( $parsed['idnumbers']['olid'] )){
			$result .= '<li class="olid"><h3>Open Library ID</h3><ul>';
			foreach( $parsed['idnumbers']['lccn'] as $temp )
				$result .= '<li id="olid-'. $temp .'" ><a href="http://openlibrary.org'. $temp .'?referer=scriblio" rel="tag">'. $temp .'</a></li>';
			$result .= '</ul></li>';
		}

		$result .= '</ul>';

		return($result);
	}

	function marcish_parse_words( &$r ){
		$parsed = $this->marcish_parse_parts( $r );

		$result = '';
		if( isset( $r['title'][0]['a'] ))
			foreach( $r['title'] as $temp )
				$result .= $temp['a'] . "\n";

		if( isset( $r['attribution'][0]['a'] ))
			$result .= $r['attribution'][0]['a'] ."\n";

		if( isset( $r['callnumbers'][0]['number'] ))
			foreach( $r['callnumbers'] as $temp )
				$result .= $temp['number'] ."\n";

		if( isset( $r['creator'][0]['name'] ))
			foreach( $r['creator'] as $temp )
				$result .= $temp['name'] ."\n";

		if( isset( $parsed['subject'] )){
			foreach( $parsed['subject'] as $temp ){
				$temptext = array();
				foreach( $temp as $temptoo )
					$temptext[] = $temptoo['value'];
				$result .= implode( ' -- ', $temptext ) ."\n";
			}
		}

		if( isset( $parsed['notes'] ))
			foreach( $parsed['notes'] as $temp )
				$result .= $temp ."\n";

		if( isset( $parsed['contents'][0] ))
			$result .= $parsed['contents'][0] ."\n";

		if( isset( $parsed['idnumbers']['isbn'] ))
			foreach( $parsed['idnumbers']['isbn'] as $temp )
				$result .= $temp ."\n";
		if( isset( $parsed['idnumbers']['issn'] ))
			foreach( $parsed['idnumbers']['issn'] as $temp )
				$result .= $temp ."\n";
		if( isset( $parsed['idnumbers']['lccn'] ))
			foreach( $parsed['idnumbers']['lccn'] as $temp )
				$result .= $temp ."\n";
		if( isset( $parsed['idnumbers']['olid'] ))
			foreach( $parsed['idnumbers']['olid'] as $temp )
				$result .= $temp ."\n";
		if( isset( $parsed['idnumbers']['sourceid'] ))
			foreach( $parsed['idnumbers']['sourceid'] as $temp )
				$result .= $temp ."\n";

		return( strip_tags( $result ));
	}

	function marcish_the_author_filter( $content ){
		global $id;

		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && isset( $r['marcish']['attribution'][0]['a'] ))
			return( $r['marcish']['attribution'][0]['a'] );
		else
			return( $content );
	}

	function marcish_author_link_filter( $content ){
		global $id;

		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish']['creator'] )){
			$terms = wp_get_object_terms( $id, 'creator' );
			foreach( $terms as $term )
				$tag['creator'][] = $term->name;

			return( $this->get_search_link( $tag ));
		}else{
			return( $content );
		}
	}


	function marcish_save_record( $post_id , $r ) {
		$stopwords = array( 'and', 'the', 'new', 'use', 'for', 'united', 'states' );

		$facets = array();
		if ( is_array( $r['marcish'] )){

			$facets['creator'] = 
			$facets['creatorkey'] = 
			$facets['lang'] = 
			$facets['cy'] = 
			$facets['cm'] = 
			$facets['format'] = 
			$facets['subject'] = 
			$facets['subjkey'] = 
			$facets['genre'] = 
			$facets['person'] = 
			$facets['place'] = 
			$facets['time'] = 
			$facets['exhibit'] = 
			$facets['sy'] = 
			$facets['sm'] = 
			$facets['sd'] = 
			$facets['collection'] = 
			$facets['sourceid'] = 
			$facets['isbn'] = 
			$facets['issn'] = 
			$facets['lccn'] = 
			$facets['asin'] = 
			$facets['ean'] = 
			$facets['olid'] = 
			$facets['oclc'] = array();


			$parsed = $this->marcish_parse_parts( $r['marcish'] );

			// creators
			if( isset( $r['marcish']['creator'][0] )){
				foreach( $r['marcish']['creator'] as $temp )
					if( !empty( $temp['name'] )){
						$facets['creator'][] = $temp['name'];
	
						if( $tempsplit = preg_split( '/[ |,|;|-]/', $temp['name'] ))
							foreach( $tempsplit as $tempsplittoo )
								if( !empty( $tempsplittoo ) && !is_numeric( $tempsplittoo ) && ( 2 < strlen( $tempsplittoo )) && ( !in_array( strtolower( $tempsplittoo ), $stopwords )))
									$facets['creatorkey'][] = $this->meditor_sanitize_punctuation( $tempsplittoo );
					}
			}

			// Title
			if( isset( $r['marcish']['title'][0] )){
				foreach( $r['marcish']['title'] as $temp ){
					$facets['title'][] = $temp['a'];
					$facets['title'][] = $this->meditor_strip_initial_articles( $temp['a'] );
				}
			}

			// Language
			if( isset( $r['marcish']['published'][0]['lang'] ))
				$facets['lang'][] = $r['marcish']['published'][0]['lang'];

			// dates
			if( isset( $r['marcish']['published'][0]['cy'] )){
				$facets['cy'][] = $r['marcish']['published'][0]['cy'];
				$facets['cy'][] = substr( $r['marcish']['published'][0]['cy'], 0, -1 ) .'0s';
				$facets['cy'][] = substr( $r['marcish']['published'][0]['cy'], 0, -2 ) .'00s';
			}
			if( isset( $r['marcish']['published'][0]['cm'] ))
				$facets['cm'][] = date( 'F', strtotime( '2008-'. $r['marcish']['published'][0]['cm'] .'-01' )); 

			if( isset( $r['marcish']['subject_date'][0] )){
				foreach( $r['marcish']['subject_date'] as $temp ){
					if( isset( $temp['y'] )){
						$facets['sy'][] = $temp['y'];
						$facets['sy'][] = substr( $temp['y'], 0, -1 ) .'0s';
						$facets['sy'][] = substr( $temp['y'], 0, -2 ) .'00s';
					}
					if( isset( $temp['m'] ))
						$facets['sm'][] = date( 'F', strtotime( '2008-'. $temp['m'] .'-01' )); 
					if( isset( $temp['d'] ))
						$facets['sd'][] = date( 'F', strtotime( '2008-'. $temp['m'] .'-01' )); 
				}
			}

			// subjects
			if( isset( $parsed['subjkey'][0] )){
				foreach( $parsed['subjkey'] as $sk => $sv ){
					$facets[ $sv['type'] ][] = $sv['value'];
					$facets['subjkey'][] = $sv['value'];

					if( $tempsplit = preg_split( '/[ |,|;|-]/', $sv['value'] ))
						foreach( $tempsplit as $tempsplittoo )
							if( !empty( $tempsplittoo ) 
								&& !is_numeric( $tempsplittoo ) 
								&& ( 2 < strlen( $tempsplittoo )) 
								&& ( !in_array( strtolower( $tempsplittoo ), $stopwords )))
									$facets['subjkey'][] = $this->meditor_sanitize_punctuation( $tempsplittoo );
				}
			}

			// standard numbers
			if ( isset( $parsed['idnumbers']['sourceid'] ))
				$facets['sourceid'] = $parsed['idnumbers']['sourceid'];
			if ( isset( $parsed['idnumbers']['lccn'] ))
				$facets['lccn'] = $parsed['idnumbers']['lccn'];
			if ( isset( $parsed['idnumbers']['isbn'] ))
				$facets['isbn'] = $parsed['idnumbers']['isbn'];
			if ( isset( $parsed['idnumbers']['issn'] ))
				$facets['issn'] = $parsed['idnumbers']['issn'];
			if ( isset( $parsed['idnumbers']['asin'] ))
				$facets['asin'] = $parsed['idnumbers']['asin'];
			if ( isset( $parsed['idnumbers']['olid'] ))
				$facets['olid'] = $parsed['idnumbers']['olid'];

			foreach( $r['marcish']['idnumbers'] as $temp ){
				switch( $temp['type'] ) {
					case 'sourceid' :
					case 'isbn' :
					case 'issn' :
					case 'lccn' :
					case 'asin' :
					case 'ean' :
					case 'oclc' :
						if( !empty( $temp['id'] ))
							$facets[ $temp['type'] ][] = $temp['id'];
						break; 
				}
			}

			// format
			if( isset( $r['marcish']['format'][0] ))
				foreach( $r['marcish']['format'] as $temp ){
					unset( $temp['src'] );
					foreach( $temp as $temptoo )
						if( !empty( $temptoo ))
							$facets['format'][] = $temptoo;
				}

			if( isset( $r['marcish']['related'][0]['record'] ))
				foreach( $r['marcish']['related'] as $temp )
					$this->marcish_update_related( $post_id, $temp );
				

			wp_set_object_terms( $post_id, (int) $this->options['catalog_category_id'], 'category', FALSE );
		}

		if ( count( $facets )){
			foreach( $facets as $taxonomy => $tags ){

				if( 'post_tag' == $taxonomy ){
					wp_set_post_tags($post_id, $tags, TRUE);
					continue;
				}
	
				wp_set_object_terms($post_id, array_unique( array_filter( $tags )), $taxonomy, FALSE);
			}
		}
	}

	function marcish_update_related( &$from_post_id, &$rel ) {
		if( absint( $rel['record'] ) && ( $r = get_post_meta( absint( $rel['record'] ), 'scrib_meditor_content', TRUE )) && ( is_array( $r['marcish'] )) ){
			if( is_string( $r ))
				$r = unserialize( $r );

			if( $this->meditor_forms['marcish']['_relationships'][ $rel['rel'] ]['_rel_inverse'] ){
				$r['marcish']['related'][] = array( 'rel' => $this->meditor_forms['marcish']['_relationships'][ $rel['rel'] ]['_rel_inverse'], 'record' => (string) $from_post_id );

				$r['marcish']['related'] = $this->array_unique_deep( $r['marcish']['related'] );

				update_post_meta( absint( $rel['record'] ), 'scrib_meditor_content', $r );
			}
		}
	}

	function marcish_add_parent( &$r, &$from ) {
		// the new record is the parent, the old record is the child
		if ( is_array( $r['marcish'] )){
			unset( $r['marcish']['title'] );
			unset( $r['marcish']['text'] );
			unset( $r['marcish']['source']['file'] );

			unset( $r['marcish']['related'] );

			$r['marcish']['related'][0] = array( 'rel' => 'child', 'record' => $from);
		}
		return( $r );
	}

	function marcish_add_child( &$r, &$from ) {
		// the new record is the child, the old record is the parent
		if ( is_array( $r['marcish'] )){
			unset( $r['marcish']['title'] );
			unset( $r['marcish']['text'] );
			unset( $r['marcish']['source']['file'] );

			unset( $r['marcish']['related'] );

			$r['marcish']['related'][0] = array( 'rel' => 'parent', 'record' => $from);
		}
		return( $r );
	}

	function marcish_add_next( &$r, &$from ) {
		// the new record is the next page in a series, the old record is the previous
		if ( is_array( $r['marcish'] )){
			unset( $r['marcish']['title'] );
			unset( $r['marcish']['text'] );
			unset( $r['marcish']['source']['file'] );

			unset( $r['marcish']['related'] );

			$r['marcish']['related'][0] = array( 'rel' => 'previous', 'record' => $from);
		}
		return( $r );
	}

	function marcish_add_previous( &$r, &$from ) {
		// the new record is the previous page in a series, the old record is the next
		if ( is_array( $r['marcish'] )){
			unset( $r['marcish']['title'] );
			unset( $r['marcish']['text'] );
			unset( $r['marcish']['source']['file'] );

			unset( $r['marcish']['related'] );

			$r['marcish']['related'][0] = array( 'rel' => 'next', 'record' => $from);
		}
		return( $r );
	}

	function marcish_add_reverse( &$r, &$from ) {
		// the new record is the reverse, the old record is the reverse
		if ( is_array( $r['marcish'] )){
			unset( $r['marcish']['title'] );
			unset( $r['marcish']['text'] );
			unset( $r['marcish']['source']['file'] );

			unset( $r['marcish']['related'] );

			$r['marcish']['related'][0] = array( 'rel' => 'reverse', 'record' => $from);
		}
		return( $r );
	}

	function marcish_add_sibling( &$r, &$from ) {
		// the new record is the reverse, the old record is the reverse
		if ( is_array( $r['marcish'] )){
			unset( $r['marcish']['title'] );
			unset( $r['marcish']['text'] );
			unset( $r['marcish']['source']['file'] );

			unset( $r['marcish']['related'] );
		}
		return( $r );
	}

	function marcish_availability( &$content, $post_id, &$idnumbers ) {
		if( isset( $idnumbers['issn'][0] ))
			$gbs_key = 'issn:'. $idnumbers['issn'][0];
		else if( isset( $idnumbers['isbn'][0] ))
			$gbs_key = 'isbn:'. $idnumbers['isbn'][0];
		else if( isset( $idnumbers['lccn'][0] ))
			$gbs_key = 'lccn:'. $idnumbers['lccn'][0];

		if( $gbs_key ){
			$this->gbs_keys[] = $gbs_key;

			return( $content . '<li id="gbs_'. str_replace( array(':', ' '), '_', $gbs_key ) .'" class="gbs_link"></li>' );
		}

		return( $content );
	}

	public function marcish_availability_gbslink(){
		if( count( $this->gbs_keys ))
			echo '<script src="http://books.google.com/books?bibkeys='. urlencode( implode( ',', array_unique( $this->gbs_keys ))) .'&jscmd=viewapi&callback=jQuery.GBDisplay"></script>';
	}

	public function arc_register( ){
		$this->meditor_register( 'arc', 
			array(
				'_title' => 'Archive Item Record',
				'_elements' => array( 
					'title' => array(
						'_title' => 'Additional Titles',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'creator' => array(
						'_title' => 'Creator',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'name' => array(
								'_title' => 'Name',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'role' => array(
								'_title' => 'Role',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'contributor' => array(
								'_title' => 'Minor contributor',
								'_input' => array(
									'_type' => 'checkbox',
								),
								'_sanitize' => 'absint',
							),
						),
					),
					'subject' => array(
						'_title' => 'Subject',
						'_description' => '',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'b' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'd' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'e' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'f' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'g' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'thesaurus' => array(
								'_title' => 'Thesaurus',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'geog' => array(
						'_title' => 'Geographic Coverage',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'b' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'd' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'e' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'f' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'g' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'thesaurus' => array(
								'_title' => 'Thesaurus',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'date_coverage' => array(
						'_title' => 'Date Coverage',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'y' => array(
								'_title' => 'Year',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'm' => array(
								'_title' => 'Month',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_month' ),
							),
							'd' => array(
								'_title' => 'Day',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_day' ),
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'exact' => 'Exactly',
										'approx' => 'Approximately',
										'before' => 'Before',
										'after' => 'After',
										'circa' => 'Circa',
										'decade' => 'Within Decade',
										'century' => 'Within Century',
									),
									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
						),
					),
					'description' => array(
						'_title' => 'Description',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'textarea',
								),
								'_sanitize' => 'wp_filter_kses',
							),
							'cy' => array(
								'_title' => 'Year',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'cm' => array(
								'_title' => 'Month',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_month' ),
							),
							'cd' => array(
								'_title' => 'Day',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_day' ),
							),
							'cc' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'nodate' => 'Undated',
										'exact' => 'Exactly',
										'approx' => 'Approximately',
										'before' => 'Before',
										'after' => 'After',
										'circa' => 'Circa',
										'decade' => 'Within Decade',
										'century' => 'Within Century',
									),
									'_default' => 'exact',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'lang' => array(
								'_title' => 'Language',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'copyright' => array(
								'_title' => 'Copyright',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'uc' => 'Uncertain',
										'c' => 'Copyrighted',
										'cc' => 'Creative Commons',
										'pd' => 'Public Domain',
									),
									'_default' => 'uc',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'copyright_note' => array(
								'_title' => 'Note',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'dimensions' => array(
						'_title' => 'Dimensions',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'dw' => array(
								'_title' => 'Width',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'dh' => array(
								'_title' => 'Height',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'dd' => array(
								'_title' => 'Depth',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'du' => array(
								'_title' => 'Units',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'inch' => 'Inches',
										'cm' => 'Centimeters',
									),
									'_default' => 'inches',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
							'wv' => array(
								'_title' => 'Weight',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'wu' => array(
								'_title' => 'Units',
								'_input' => array(
									'_type' => 'select',
									'_values' => array( 
										'ounce' => 'Ounces',
										'pounds' => 'Pounds',
										'g' => 'Grams',
										'kg' => 'Kilograms',
									),
									'_default' => 'ounce',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_selectlist' ),
							),
						),
					),
					'format' => array(
						'_title' => 'Format',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'b' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'c' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'd' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'e' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'f' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'g' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'thesaurus' => array(
								'_title' => 'Thesaurus',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'transcript' => array(
						'_title' => 'Transcription',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'textarea',
								),
								'_sanitize' => 'wp_filter_kses',
							),
						),
					),
					'translation' => array(
						'_title' => 'Translation',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'textarea',
								),
								'_sanitize' => 'wp_filter_kses',
							),
							'lang' => array(
								'_title' => 'Language',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'notes' => array(
								'_title' => 'Notes',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'source' => array(
						'_title' => 'Source',
						'_repeatable' => FALSE,
						'_elements' => array( 

							'file' => array(
								'_title' => 'File Name',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'dy' => array(
								'_title' => 'Digitized Year',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'absint',
							),
							'dm' => array(
								'_title' => 'Month',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_month' ),
							),
							'dd' => array(
								'_title' => 'Day',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_day' ),
							),
							'box' => array(
								'_title' => 'Box Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'folder' => array(
								'_title' => 'Folder Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'collection' => array(
								'_title' => 'Collection',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'collection_num' => array(
								'_title' => 'Collection Number',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
							'publisher' => array(
								'_title' => 'Publisher',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => 'wp_filter_nohtml_kses',
							),
						),
					),
					'rel_parent' => array(
						'_title' => 'Related Records',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => 'Parent',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_related' ),
							),
						),
					),
					'rel_child' => array(
						'_title' => '',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => 'Child',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_related' ),
							),
						),
					),
					'rel_next' => array(
						'_title' => '',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => 'Next Page',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_related' ),
							),
						),
					),
					'rel_previous' => array(
						'_title' => '',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => 'Previous Page',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_related' ),
							),
						),
					),
					'rel_reverse' => array(
						'_title' => '',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => 'Reverse Side',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
								'_sanitize' => array( $this, 'meditor_sanitize_related' ),
							),
						),
					),
	
					'addrecord' => array(
						'_title' => 'Add New Record',
						'_repeatable' => FALSE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => '_function',
									'_function' => array( $this, 'meditor_add_related_edlinks' ),
								),
							),
						),
					),
				),
			)
		);


		// taxonomies for all default forms
		register_taxonomy( 'creator', 'post'); // creator/author
		register_taxonomy( 'creatorkey', 'post'); // creator/author keyword
		register_taxonomy( 'title', 'post');
		register_taxonomy( 'lang', 'post'); // language
		register_taxonomy( 'cy', 'post'); // created/published year
		register_taxonomy( 'cm', 'post'); // created/published month
		register_taxonomy( 'format', 'post');
		register_taxonomy( 'subject', 'post');
		register_taxonomy( 'subjkey', 'post');
		register_taxonomy( 'genre', 'post');
		register_taxonomy( 'person', 'post');
		register_taxonomy( 'place', 'post');
		register_taxonomy( 'time', 'post');
		register_taxonomy( 'exhibit', 'post');
		register_taxonomy( 'sy', 'post'); // subject year
		register_taxonomy( 'sm', 'post'); // subject month
		register_taxonomy( 'sd', 'post'); // subject day
		register_taxonomy( 'collection', 'post');
		register_taxonomy( 'sourceid', 'post');
		register_taxonomy( 'isbn', 'post');
		register_taxonomy( 'issn', 'post');
		register_taxonomy( 'lccn', 'post');
		register_taxonomy( 'asin', 'post');
		register_taxonomy( 'ean', 'post');
		register_taxonomy( 'oclc', 'post');

		// actions and filters for arc form
		add_action('scrib_meditor_form_arc', array(&$this, 'meditor_form_hook'));

		add_filter('scrib_meditor_pre_excerpt', array(&$this, 'arc_pre_excerpt'), 1, 2);
		add_filter('scrib_meditor_pre_content', array(&$this, 'arc_pre_content'), 1, 2);
		add_filter('the_content', array(&$this, 'arc_the_content'));
		add_filter('the_excerpt', array(&$this, 'arc_the_excerpt'));

		add_action('scrib_meditor_save_record', array(&$this, 'arc_save_record'), 1, 2);

		add_filter('scrib_meditor_add_parent', array(&$this, 'arc_add_parent'), 1, 2);
		add_filter('scrib_meditor_add_child', array(&$this, 'arc_add_child'), 1, 2);
		add_filter('scrib_meditor_add_next', array(&$this, 'arc_add_next'), 1, 2);
		add_filter('scrib_meditor_add_previous', array(&$this, 'arc_add_previous'), 1, 2);
		add_filter('scrib_meditor_add_reverse', array(&$this, 'arc_add_reverse'), 1, 2);
	}

	public function arc_unregister(){
		remove_action('scrib_meditor_form_arc', array(&$this, 'meditor_form_hook'));

		remove_filter('scrib_meditor_pre_excerpt', array(&$this, 'arc_pre_excerpt'), 1, 2);
		remove_filter('scrib_meditor_pre_content', array(&$this, 'arc_pre_content'), 1, 2);
		remove_filter( 'the_content', array(&$this, 'arc_the_content'));
		remove_filter( 'the_excerpt', array(&$this, 'arc_the_excerpt'));

		remove_action('scrib_meditor_save_record', array(&$this, 'arc_save_record'), 1, 2);

		remove_filter('scrib_meditor_add_parent', array(&$this, 'arc_add_parent'), 1, 2);
		remove_filter('scrib_meditor_add_child', array(&$this, 'arc_add_child'), 1, 2);
		remove_filter('scrib_meditor_add_next', array(&$this, 'arc_add_next'), 1, 2);
		remove_filter('scrib_meditor_add_previous', array(&$this, 'arc_add_previous'), 1, 2);
		remove_filter('scrib_meditor_add_reverse', array(&$this, 'arc_add_reverse'), 1, 2);
	}

	function arc_pre_excerpt( &$content, $r ) {
		if( $r['arc'] ){
			$result = '<ul class="summaryrecord dcimage"><li>[icon size="s" /]</li></ul>';
			return($result);
		}
		return( $content );
	}

	function arc_pre_content( $content, $r ) {
		if( $r['arc'] ){
			global $bsuite;

			$result = '<ul class="fullrecord dcimage">[icon size="l" /]<pre>';
			$result .= print_r( $r, TRUE );
			$result .= print_r( $r['arc']['rel_parent'], TRUE );
			$result .='</pre>';

			if( !empty( $r['arc']['rel_parent'][0]['a'] )){
				$result .= '<li class="rel_parent"><h3>Parent</h3><ul>';
				foreach( $r['arc']['rel_parent'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';
				$result .= '</ul></li>';
			}

			if( !empty( $r['arc']['rel_child'][0]['a'] )){
				$result .= '<li class="rel_child"><h3>Children</h3><ul>';
				foreach( $r['arc']['rel_child'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';
				$result .= '</ul></li>';
			}

			if( !empty( $r['arc']['rel_previous'][0]['a'] )){
				$result .= '<li class="rel_previous"><h3>Previous Page</h3><ul>';
				foreach( $r['arc']['rel_previous'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';
				$result .= '</ul></li>';
			}

			if( !empty( $r['arc']['rel_next'][0]['a'] )){
				$result .= '<li class="rel_next"><h3>Next Page</h3><ul>';
				foreach( $r['arc']['rel_next'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';
				$result .= '</ul></li>';
			}

			if( !empty( $r['arc']['rel_reverse'][0]['a'] )){
				$result .= '<li class="rel_reverse"><h3>Reverse Side</h3><ul>';
				foreach( $r['arc']['rel_reverse'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';
				$result .= '</ul></li>';
			}

			$result .='</ul>';
			return($result);
		}
		return( $content );
	}

	public function arc_the_content( $content ){
		global $id;
		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish'] ))
			return( $this->marcish_the_content( $r['marcish'] ));

		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['arc'] )){
			global $bsuite;
			$bigimg = $bsuite->icon_get_a( $id, 'b' );

			$result = '<ul class="fullrecord arc">';
			$result .= apply_filters( 'scrib_meditor_content_icon', ('<li class="image"><a href="'. $bigimg['url'] .'" title="view larger image">[icon size="l" /]</a>
				<ul class="options">
					<li><a href="#bsuite_share_embed" title="'. __( 'Link to or embed this item in your site', 'Scrib' ) .'">Embed</a></li>
					<li><a href="#bsuite_share_bookmark" title="'. __( 'Bookmark or share this item', 'Scrib' ) .'">Share</a></li>
					<li><a href="#comments" title="'. __( 'Discuss this item in the comments', 'Scrib' ) .'">Discuss</a></li>
					<li><a href="#tagthis" title="'. __( 'Recognize somebody or something? Tag them!', 'Scrib' ) .'">Tag</a></li>
				</ul><hr /></li>' ));

			if( isset( $r['arc']['title'][0]['a'] )){
				$result .= '<li class="alt_title"><h3>'. ( isset( $r['arc']['title'][1]['a'] ) ? 'Alternate Title' : 'Alternate Titles' ) .'</h3><ul>';
				foreach( $r['arc']['title'] as $temp )
					$result .= '<li>'. $temp['a'] .'</li>';
				$result .= '</ul></li>';
			}

			if( isset( $r['arc']['creator'][0]['name'] )){
				$creators = $contributors = array();
				foreach( $r['arc']['creator'] as $temp )
					if( 1 == $temp['contributor'] )
						$contributors[] = $temp;
					else
						$creators[] = $temp;
				
				if( count( $creators )){
					$result .= '<li class="creator"><h3>'. ( 1 == count( $creators ) ? 'Creator' : 'Creators' ) .'</h3><ul>';
					foreach( $creators as $temp )
						$result .= '<li>'. $temp['name'] . ( !empty( $temp['name'] ) ? ' <span class="role">'. $temp['role'] .'</span>' : '' ) .'</li>';
					$result .= '</ul></li>';
				}
			}

			if( isset( $r['arc']['description'][0]['a'] )){
				$result .= '<li class="alt_title"><h3>Description</h3><ul><li>'. $r['arc']['description'][0]['a'] .'</li></ul></li>' ;
			}

			if( isset( $r['arc']['description'][0]['cy'] ) && isset( $r['arc']['description'][0]['cm'] ) && isset( $r['arc']['description'][0]['cd'] ))
				$result .= '<li class="date_created"><h3>Date Created</h3><ul><li>'. date( 'F j', strtotime( '2008-'. $r['arc']['description'][0]['cm'] .'-'. $r['arc']['description'][0]['cd'])) .', '. $r['arc']['description'][0]['cy'] . ( 'exact' == $r['arc']['description'][0]['cc'] ? '' : ' <span class="certainty">'. $r['arc']['description'][0]['cc'] .'</span>' ) .'</li></ul></li>';

			else if( isset( $r['arc']['description'][0]['cy'] ) && isset( $r['arc']['description'][0]['cm'] ))
				$result .= '<li class="date_created"><h3>Date Created</h3><ul><li>'. date( 'F', strtotime( '2008-'. $r['arc']['description'][0]['cm'] .'-01')) .', '. $r['arc']['description'][0]['cy'] . ( 'exact' == $r['arc']['description'][0]['cc'] ? '' : ' <span class="certainty">'. $r['arc']['description'][0]['cc'] .'</span>' ) .'</li></ul></li>';

			else if( isset( $r['arc']['description'][0]['cy'] ))
				$result .= '<li class="date_created"><h3>Date Created</h3><ul><li>'. $r['arc']['description'][0]['cy'] . ( 'exact' == $r['arc']['description'][0]['cc'] ? '' : ' <span class="certainty">'. $r['arc']['description'][0]['cc'] .'</span>' ) .'</li></ul></li>';

/*
			else if( isset( $r['arc']['description'][0]['cm'] ) && isset( $r['arc']['description'][0]['cd'] ))
				$result .= '<li class="date_created"><h3>Date Created</h3><ul><li>'. date( 'F j', strtotime( '2008-'. $r['arc']['description'][0]['cm'] .'-'. $r['arc']['description'][0]['cd'])) . ( 'exact' == $r['arc']['description'][0]['cc'] ? '' : ' <span class="certainty">'. $r['arc']['description'][0]['cc'] .'</span>' ) .'</li></ul></li>';
*/

			else if( isset( $r['arc']['description'][0]['cc'] ) && 'nodate' == $r['arc']['description'][0]['cc'])
				$result .= '<li class="date_created"><h3>Date Created</h3><ul><li><span class="certainty">Uncertain</span></li></ul></li>';

			if( isset( $r['arc']['transcript'][0]['a'] )){
				$result .= '<li class="transcript"><h3>Transcription</h3><ul><li>'. $r['arc']['transcript'][0]['a'] .'</li></ul></li>' ;
			}

			if( count( $contributors )){
				$result .= '<li class="contributor"><h3>'. ( 1 == count( $contributors ) ? 'Contributor' : 'Contributors' ) .'</h3><ul>';
				foreach( $contributors as $temp )
					$result .= '<li>'. $temp['name'] . ( !empty( $temp['name'] ) ? ' <span class="role">'. $temp['role'] .'</span>' : '' ) .'</li>';
				$result .= '</ul></li>';
			}

			if( isset( $r['arc']['subject'][0]['a'] )){
				$result .= '<li class="subject"><h3>Subject</h3><ul>';
				foreach( $r['arc']['subject'] as $temp )
					$result .= '<li>'. implode( ' -- ', $temp ) .'</li>';
				$result .= '</ul></li>';
			}

			if( isset( $r['arc']['geog'][0]['a'] )){
				$result .= '<li class="geog"><h3>Geographic Coverage</h3><ul>';
				foreach( $r['arc']['geog'] as $temp )
					$result .= '<li>'. implode( ' -- ', $temp ) .'</li>';
				$result .= '</ul></li>';
			}

			if( 1 < count( $r['arc']['date_coverage'][0] )){
				$result .= '<li class="date_coverage"><h3>Date Coverage</h3><ul>';
				foreach( $r['arc']['geog'] as $temp ){
					if( isset( $temp['y'] ) && isset( $temp['m'] ) && isset( $temp['d'] ))
						$result .= '<li>'. date( 'F j', strtotime( '2008-'. $temp['m'] .'-'. $temp['d'])) .', '. $temp['y'] . ( 'exact' == $temp['c'] ? '' : ' <span class="certainty">'. $temp['c'] .'</span>' ) .'</li>';

					else if( isset( $temp['y'] ) && isset( $temp['m'] ))
						$result .= '<li>'. date( 'F', strtotime( '2008-'. $temp['m'] .'-01')) .', '. $temp['y'] . ( 'exact' == $temp['c'] ? '' : ' <span class="certainty">'. $temp['c'] .'</span>' ) .'</li>';

					else if( isset( $temp['m'] ) && isset( $temp['d'] ))
						$result .= '<li>'. date( 'F j', strtotime( '2008-'. $temp['m'] .'-'. $temp['d'])) . ( 'exact' == $temp['c'] ? '' : ' <span class="certainty">'. $temp['c'] .'</span>' ) .'</li>';
				}

				$result .= '</ul></li>';
			}

			if( isset( $r['arc']['format'][0]['a'] )){
				$result .= '<li class="format"><h3>Format</h3><ul>';
				foreach( $r['arc']['format'] as $temp )
					$result .= '<li>'. implode( ' -- ', $temp ) .'</li>';
				$result .= '</ul></li>';
			}

			if( !empty( $r['arc']['rel_previous'][0]['a'] )){
				$result .= '<li class="rel_previous"><h3>Previous Page</h3><ul>';
				foreach( $r['arc']['rel_previous'] as $temp )
					if( get_permalink( $temp['a'] ))
						$result .= '<li><a href="'. get_permalink( $temp['a'] ) .'" class="bsuite_post_icon_link" title="'. attribute_escape( get_the_title( $temp['a'] )).'">'. $bsuite->icon_get_h( $temp['a'], 's' ) .'</a></li>';
				$result .= '</ul></li>';
			}
			if( !empty( $r['arc']['rel_next'][0]['a'] )){
				$result .= '<li class="rel_next"><h3>Next Page</h3><ul>';
				foreach( $r['arc']['rel_next'] as $temp )
					if( get_permalink( $temp['a'] ))
						$result .= '<li><a href="'. get_permalink( $temp['a'] ) .'" class="bsuite_post_icon_link" title="'. attribute_escape( get_the_title( $temp['a'] )).'">'. $bsuite->icon_get_h( $temp['a'], 's' ) .'</a></li>';
				$result .= '</ul></li>';
			}
			if( !empty( $r['arc']['rel_reverse'][0]['a'] )){
				$result .= '<li class="rel_reverse"><h3>Reverse Side</h3><ul>';
				foreach( $r['arc']['rel_reverse'] as $temp )
					if( get_permalink( $temp['a'] ))
						$result .= '<li><a href="'. get_permalink( $temp['a'] ) .'" class="bsuite_post_icon_link" title="'. attribute_escape( get_the_title( $temp['a'] )).'">'. $bsuite->icon_get_h( $temp['a'], 's' ) .'</a></li>';
				$result .= '</ul></li>';
			}


			if( !empty( $r['arc']['rel_parent'][0]['a'] )){
				$result .= '<li class="rel_parent"><h3>Part Of</h3><ul>';
				foreach( $r['arc']['rel_parent'] as $temp )
					if( get_permalink( $temp['a'] ))
						$result .= '<li><a href="'. get_permalink( $temp['a'] ) .'" class="bsuite_post_icon_link" title="'. attribute_escape( get_the_title( $temp['a'] )).'">'. $bsuite->icon_get_h( $temp['a'], 's' ) .'</a></li>';
				$result .= '</ul></li>';
			}
			if( !empty( $r['arc']['rel_child'][0]['a'] )){
				$result .= '<li class="rel_child"><h3>Additional Pieces</h3><ul>';
				foreach( $r['arc']['rel_child'] as $temp )
					if( get_permalink( $temp['a'] ))
						$result .= '<li><a href="'. get_permalink( $temp['a'] ) .'" class="bsuite_post_icon_link" title="'. attribute_escape( get_the_title( $temp['a'] )).'">'. $bsuite->icon_get_h( $temp['a'], 's' ) .'</a></li>';
				$result .= '</ul></li>';
			}

/*
			$result .= '<pre>';
			$result .= print_r( $r, TRUE );
			$result .='</pre>';
*/
			$result .='</ul>';
			return( $result );
		}
		return( $content );
	}

	public function arc_the_excerpt( $content ){
		global $id;
		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish'] ))
			return( $this->marcish_the_excerpt( $r['marcish'] ));

		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['arc'] )){
			global $bsuite;

			$result = '<ul class="fullrecord arc">'. ( !is_quickview() ? '<li><a href="'. get_permalink( $id ) .'" class="bsuite_post_icon_link" rel="bookmark" title="Permanent Link to '. attribute_escape( get_the_title( $id )) .'">[icon size="m" /]</a></li>' : '' );
			if( isset( $r['arc']['description'][0]['a'] )){
				$result .= '<li class="alt_title"><h3>Description</h3><ul><li>'. $r['arc']['description'][0]['a'] .'</li></ul></li>' ;
			}
			$result .='</ul>';

			return( $result );
		}
		return( $content );
	}

	function arc_save_record( $post_id , $r ) {
		$stopwords = array( 'and', 'the', 'new', 'use', 'for', 'hampshire', 'london', 'england', 'united', 'states' );

		$facets = array();

		if ( is_array( $r['arc'] )){
			$facets['dy'] = $facets['dm'] = $facets['creator'] = $facets['subject'] = $facets['geog'] = $facets['format'] = $facets['lang'] = $facets['collection'] = array();


			// dates
			if( isset( $r['arc']['description'][0]['cy'] )){
				$facets['dy'][] = $r['arc']['description'][0]['cy'];
				$facets['dy'][] = substr( $r['arc']['description'][0]['cy'], 0, -1 ) .'0s';
				$facets['dy'][] = substr( $r['arc']['description'][0]['cy'], 0, -2 ) .'00s';
			}
			if( isset( $r['arc']['description'][0]['cm'] ))
				$facets['dm'][] = date( 'F', strtotime( '2008-'. $r['arc']['description'][0]['cm'] .'-01' )); 

			foreach( $r['arc']['date_coverage'] as $temp ){
				if( isset( $temp['y'] )){
					$facets['dy'][] = $temp['y'];
					$facets['dy'][] = substr( $temp['y'], 0, -1 ) .'0s';
					$facets['dy'][] = substr( $temp['y'], 0, -2 ) .'00s';
				}
				if( isset( $temp['m'] ))
					$facets['dm'][] = date( 'F', strtotime( '2008-'. $temp['m'] .'-01' )); 
			}

			// creators
			foreach( $r['arc']['creator'] as $temp )
				if( !empty( $temp['name'] )){
					$facets['creator'][] = $temp['name'];

					if( $tempsplit = preg_split( '/[ |,|;|-]/', $temp['name'] ))
						foreach( $tempsplit as $tempsplittoo )
							if( !empty( $tempsplittoo ) && !is_numeric( $tempsplittoo ) && ( 2 < strlen( $tempsplittoo )) && ( !in_array( strtolower( $tempsplittoo ), $stopwords )))
								$facets['creator'][] = $this->meditor_sanitize_punctuation( $tempsplittoo );
				}

			// subjects
			foreach( $r['arc']['subject'] as $temp )
				foreach( $temp as $temptoo ){
					if( !empty( $temptoo ))
						$facets['subject'][] = $temptoo;

					if( $tempsplit = preg_split( '/[ |,|;|-]/', $temptoo ))
						foreach( $tempsplit as $tempsplittoo )
							if( !empty( $tempsplittoo ) && !is_numeric( $tempsplittoo ) && ( 2 < strlen( $tempsplittoo )) && ( !in_array( strtolower( $tempsplittoo ), $stopwords )))
								$facets['subject'][] = $this->meditor_sanitize_punctuation( $tempsplittoo );
				}

			// geography
			foreach( $r['arc']['geog'] as $temp )
				foreach( $temp as $temptoo )
					if( !empty( $temptoo ))
						$facets['geog'][] = $temptoo;

			// format
			foreach( $r['arc']['format'] as $temp )
				foreach( $temp as $temptoo )
					if( !empty( $temptoo ))
						$facets['format'][] = $temptoo;

			// language
			if( isset( $r['arc']['description'][0]['lang'] ))
				$facets['lang'][] = $r['arc']['description'][0]['lang'];

			// collection
			if( isset( $r['arc']['source'][0]['collection'] ))
				$facets['collection'][] = $r['arc']['source'][0]['collection'];

			// exhibit
			foreach( $r['arc']['exhibit'] as $temp )
				if( !empty( $temp['a'] ))
					$facets['exhibit'][] = $temp['a'];

/*
TODO: update relationships to other posts when a post is saved.
//post_id
			if( !empty( $r['arc']['rel_parent'][0]['a'] ))
				foreach( $r['arc']['rel_parent'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';

			if( !empty( $r['arc']['rel_child'][0]['a'] ))
				foreach( $r['arc']['rel_child'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';

			if( !empty( $r['arc']['rel_previous'][0]['a'] ))
				foreach( $r['arc']['rel_previous'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';

			if( !empty( $r['arc']['rel_next'][0]['a'] ))
				foreach( $r['arc']['rel_next'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';

			if( !empty( $r['arc']['rel_reverse'][0]['a'] ))
				foreach( $r['arc']['rel_reverse'] as $temp )
					$result .= '<li>' . $bsuite->icon_get_h( $temp['a'], 's' ) . '</li>';
*/

//			$facets['category'] = (int) $this->options['catalog_category_id'];
			wp_set_object_terms( $post_id, (int) $this->options['catalog_category_id'], 'category', FALSE );
		}
	}

	function arc_add_related_edlinks( $null ) {
		global $post_ID;
		if( $post_ID ){
			echo '<p id="scrib_meditor_addrelated">';
			echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add=parent&scrib_meditor_from='. $post_ID ) .'">'. __( '+ add parent', 'scrib' ) .'</a> &nbsp; ';
			echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add=child&scrib_meditor_from='. $post_ID ) .'">'. __( '+ add child', 'scrib' ) .'</a> &nbsp; ';
			echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add=next&scrib_meditor_from='. $post_ID ) .'">'. __( '+ add next page', 'scrib' ) .'</a> &nbsp; ';
			echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add=previous&scrib_meditor_from='. $post_ID ) .'">'. __( '+ add previous page', 'scrib' ) .'</a> &nbsp; ';
			echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add=reverse&scrib_meditor_from='. $post_ID ) .'">'. __( '+ add reverse', 'scrib' ) .'</a> &nbsp; ';
			echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add=sibling&scrib_meditor_from='. $post_ID ) .'">'. __( '+ add sibling', 'scrib' ) .'</a> &nbsp; ';
			echo '</p';
		}else{
			echo '<p id="scrib_meditor_addrelated_needsid">'. __( 'Save this record before attempting to add a related record.', 'scrib' ) .'</p>';
		}
	}

	function arc_add_parent( &$r, &$from ) {
		// the new record is the parent, the old record is the child
		if ( is_array( $r['arc'] )){
			unset( $r['arc']['title'] );
			unset( $r['arc']['creator'] );
			unset( $r['arc']['subject'] );
			unset( $r['arc']['geog'] );
			unset( $r['arc']['date_coverage'] );
			unset( $r['arc']['description'] );
			unset( $r['arc']['dimensions'] );
			unset( $r['arc']['format'] );
			unset( $r['arc']['transcript'] );
			unset( $r['arc']['translation'] );
			unset( $r['arc']['source']['file'] );

			unset( $r['arc']['rel_parent'] );
			unset( $r['arc']['rel_child'] );
			unset( $r['arc']['rel_previous'] );
			unset( $r['arc']['rel_next'] );
			unset( $r['arc']['rel_reverse'] );

			$r['arc']['rel_child'][0]['a'] = $from;
		}
		return( $r );
	}

	function arc_add_child( &$r, &$from ) {
		// the new record is the child, the old record is the parent
		if ( is_array( $r['arc'] )){
			unset( $r['arc']['title'] );
			unset( $r['arc']['creator'] );
			unset( $r['arc']['subject'] );
			unset( $r['arc']['geog'] );
			unset( $r['arc']['date_coverage'] );
			unset( $r['arc']['description'] );
			unset( $r['arc']['dimensions'] );
			unset( $r['arc']['format'] );
			unset( $r['arc']['transcript'] );
			unset( $r['arc']['translation'] );
			unset( $r['arc']['source']['file'] );

			unset( $r['arc']['rel_parent'] );
			unset( $r['arc']['rel_child'] );
			unset( $r['arc']['rel_previous'] );
			unset( $r['arc']['rel_next'] );
			unset( $r['arc']['rel_reverse'] );

			$r['arc']['rel_parent'][0]['a'] = $from;
		}
		return( $r );
	}

	function arc_add_next( &$r, &$from ) {
		// the new record is the next page in a series, the old record is the previous
		if ( is_array( $r['arc'] )){
			unset( $r['arc']['title'] );
			unset( $r['arc']['transcript'] );
			unset( $r['arc']['translation'] );
			unset( $r['arc']['source']['file'] );

			unset( $r['arc']['rel_child'] );
			unset( $r['arc']['rel_previous'] );
			unset( $r['arc']['rel_next'] );
			unset( $r['arc']['rel_reverse'] );

			$r['arc']['rel_previous'][0]['a'] = $from;
		}
		return( $r );
	}

	function arc_add_previous( &$r, &$from ) {
		// the new record is the previous page in a series, the old record is the next
		if ( is_array( $r['arc'] )){
			unset( $r['arc']['title'] );
			unset( $r['arc']['transcript'] );
			unset( $r['arc']['translation'] );
			unset( $r['arc']['source']['file'] );

			unset( $r['arc']['rel_child'] );
			unset( $r['arc']['rel_previous'] );
			unset( $r['arc']['rel_next'] );
			unset( $r['arc']['rel_reverse'] );

			$r['arc']['rel_next'][0]['a'] = $from;
		}
		return( $r );
	}

	function arc_add_reverse( &$r, &$from ) {
		// the new record is the reverse, the old record is the reverse
		if ( is_array( $r['arc'] )){
			unset( $r['arc']['transcript'] );
			unset( $r['arc']['translation'] );
			unset( $r['arc']['source']['file'] );

			unset( $r['arc']['rel_reverse'] );

			$r['arc']['rel_reverse'][0]['a'] = $from;
		}
		return( $r );
	}

	function arc_add_sibling( &$r, &$from ) {
		// the new record is the reverse, the old record is the reverse
		if ( is_array( $r['arc'] )){
			unset( $r['arc']['title'] );
			unset( $r['arc']['creator'] );
			unset( $r['arc']['subject'] );
			unset( $r['arc']['geog'] );
			unset( $r['arc']['date_coverage'] );
			unset( $r['arc']['description'] );
			unset( $r['arc']['dimensions'] );
			unset( $r['arc']['format'] );
			unset( $r['arc']['transcript'] );
			unset( $r['arc']['translation'] );
			unset( $r['arc']['source']['file'] );

			unset( $r['arc']['rel_child'] );
			unset( $r['arc']['rel_previous'] );
			unset( $r['arc']['rel_next'] );
			unset( $r['arc']['rel_reverse'] );
		}
		return( $r );
	}

	function import_insert_harvest( &$bibr, $enriched = 0 ){
		global $wpdb;

		$wpdb->get_results("REPLACE INTO $this->harvest_table
			( source_id, harvest_date, imported, content, enriched ) 
			VALUES ( '". $wpdb->escape( $bibr['_sourceid'] ) ."', NOW(), 0, '". $wpdb->escape( serialize( $bibr )) ."', ". absint( $enriched ) ." )" );

		wp_cache_set( $bibr['_sourceid'], time() + 2500000, 'scrib_harvested', time() + 2500000 );
	}

	function import_post_exists( &$idnumbers ) {
		global $wpdb;

		$post_id = FALSE;
		$post_ids = $tt_ids = array();

		foreach( $idnumbers as $idnum )
			$tt_ids[] = get_term( is_term( $idnum['id'] ), $idnum['type'] );

		if( count( $tt_ids )){
			foreach( $tt_ids as $k => $tt_id )
				if( isset( $tt_id->term_taxonomy_id ))
					$tt_ids[ $k ] = (int) $tt_id->term_taxonomy_id;
				else
					unset( $tt_ids[ $k ] );

			if( !count( $tt_ids ))
				return( FALSE );

			$post_ids = $wpdb->get_col( "SELECT object_id, COUNT(*) AS hits
				FROM $wpdb->term_relationships
				WHERE term_taxonomy_id IN ('". implode( '\',\'', $tt_ids ) ."')
				GROUP BY object_id
				ORDER BY hits DESC
				LIMIT 100" );

			if( 1 < count( $post_ids )){
				// de-index the duplicate posts
				// TODO: what if they have comments? What if others have linked to them?
				$this->import_deindex_post( $post_ids ); 

				sleep( 2 ); // give the database a moment to settle
			}

			foreach( $post_ids as $post_id )
				if( get_post( $post_id ))
					return( $post_id );
		}

		return( FALSE );
	}

	function import_deindex_post( $post_ids ){
		// sets a post's status to draft so that it no longer appears in searches
		// TODO: need to find a better status to hide it from searches, 
		// but not invalidate incoming links or remove comments
		global $wpdb;

		foreach( (array) $post_ids as $post_id ){
			$post_id = absint( $post_id );
			if( !$post_id )
				continue;
	
			// set the post to draft (TODO: use a WP function instead of writing to DB)
			$wpdb->get_results( "UPDATE $wpdb->posts SET post_status = 'draft' WHERE ID = $post_id" );

			// clear the post/page cache
			clean_page_cache( $post_id );
			clean_post_cache( $post_id );

			// do the post transition
			wp_transition_post_status( 'draft', 'publish', $post_id );
		}
	}

	function import_insert_post( $bibr ){
//		return(1);
		global $wpdb, $bsuite;

		if( !defined( 'DOING_AUTOSAVE' ) )
			define( 'DOING_AUTOSAVE', TRUE ); // prevents revision tracking
		wp_defer_term_counting( TRUE ); // may improve performance
		remove_filter( 'content_save_pre', array( &$bsuite, 'innerindex_nametags' )); // don't build an inner index for catalog records
		remove_filter( 'publish_post', '_publish_post_hook', 5, 1 ); // avoids pinging links in catalog records
		remove_filter( 'save_post', '_save_post_hook', 5, 2 ); // don't bother
		kses_remove_filters(); // don't kses filter catalog records
		define( 'WP_IMPORTING', TRUE ); // may improve performance by preventing exection of some unknown hooks

		$postdata = array();
		if( $this->import_post_exists( $bibr['_idnumbers'] )){
			$postdata['ID'] = $this->import_post_exists( $bibr['_idnumbers'] );

			$oldrecord = get_post_meta( $postdata['ID'], 'scrib_meditor_content', true );

			$postdata['post_title'] = strlen( get_post_field( 'post_title', $postdata['ID'] )) ? get_post_field( 'post_title', $postdata['ID'] ) : $bibr['_title'];

			if( isset( $bibr['_acqdate'] ))
				$postdata['post_date'] = 
				$postdata['post_date_gmt'] = 
				$postdata['post_modified'] = 
				$postdata['post_modified_gmt'] = strlen( get_post_field( 'post_date', $postdata['ID'] )) ? get_post_field( 'post_date', $postdata['ID'] ) : $bibr['_acqdate'];

		}else{
			$postdata['post_title'] = $wpdb->escape( str_replace( '\"', '"', $bibr['_title'] ));
			
			if( isset( $bibr['_acqdate'] ))
				$postdata['post_date'] = 
				$postdata['post_date_gmt'] = 
				$postdata['post_modified'] = 
				$postdata['post_modified_gmt'] = $bibr['_acqdate'];
		}

		$postdata['comment_status'] = get_option('default_comment_status');
		$postdata['ping_status'] 	= get_option('default_ping_status');
		$postdata['post_status'] 	= 'publish';
		$postdata['post_type'] 		= 'post';
		$postdata['post_author'] 	= $this->options['catalog_author_id'];

		if( isset( $bibr['_icon'] ))
			$the_icon = $bibr['_icon'];

		$nsourceid = $bibr['_sourceid'];

		unset( $bibr['_title'] );
		unset( $bibr['_acqdate'] );
		unset( $bibr['_idnumbers'] );
		unset( $bibr['_sourceid'] );
		unset( $bibr['_icon'] );

		$postdata['post_content'] = $this->marcish_parse_words( $bibr );
		$postdata['post_excerpt'] = '';

		if( empty( $postdata['post_title'] ))
			return( FALSE );

		// sanitize the input record
		$bibr = $this->meditor_sanitize_input( $bibr );

		// merge it with the old record
		if( is_array( $oldrecord ))
			$bibr = $this->meditor_merge_meta( $oldrecord, $bibr, $nsourceid );

		$post_id = wp_insert_post( $postdata ); // insert the post
		if($post_id){
			add_post_meta( $post_id, 'scrib_meditor_content', $bibr, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $bibr );
			do_action( 'scrib_meditor_save_record', $post_id, $bibr );

			if( isset( $the_icon ))
				add_post_meta( $post_id, 'bsuite_post_icon', $the_icon, TRUE ) or update_post_meta( $post_id, 'bsuite_post_icon', $the_icon );

			return( $post_id );
		}
		return(FALSE);
	}

	function import_harvest_tobepublished_count() {
		global $wpdb; 
		return( $wpdb->get_var( 'SELECT COUNT(*) FROM '. $this->harvest_table .' WHERE imported = 0' ));
	}

	function import_harvest_publish() { 
		global $wpdb; 

		$interval = 25;

		if( isset( $_GET[ 'n' ] ) == false ) {
			$n = 0;
		} else {
			$n = absint( $_GET[ 'n' ] );
		}

		$posts = $wpdb->get_results('SELECT * FROM '. $this->harvest_table .' WHERE imported = 0 ORDER BY enriched DESC LIMIT 0,'. $interval, ARRAY_A);

		if( is_array( $posts )) {
			echo "<p>Fetching records in batches of $interval...publishing them...making coffee. Please be patient.<br /><br /></p>";
			echo '<ol>';
			foreach( $posts as $post ) {
				set_time_limit( 900 );

				$r = array();
				$post_id = FALSE;

				$r = unserialize( $post['content'] );
				if( !count( $r ))
					continue;

				if( !array_intersect_key( $r, $this->meditor_forms ))
					$r = $this->import_harvest_upgradeoldarray( $r );

				$post_id = $this->import_insert_post( $r );

				if( $post_id ){
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = 1 WHERE source_id = "'. $post['source_id'] .'"' );
					echo '<li><a href="'. get_permalink( $post_id ) .'" target="_blank">'. get_the_title( $post_id ) .'</a> from '. $post['source_id'] .'</li>';
					flush();
				}else{
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post['source_id'] .'"' );
					echo '<li>Failed to publish '. $post['source_id'] .'</li>';
				}
			}
			echo '</ol>';

			wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier

			?>
			<p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="?page=<?php echo plugin_basename( dirname( __FILE__ )); ?>/scriblio.php&command=<?php _e('Publish Harvested Records', 'Scriblio') ?>&n=<?php echo ( $n + $interval) ?>"><?php _e("Next Posts"); ?></a> </p>
			<script language='javascript'>
			<!--

			function nextpage() {
				location.href="?page=<?php echo plugin_basename( dirname( __FILE__ )); ?>/scriblio.php&command=<?php _e('Publish Harvested Records', 'Scriblio') ?>&n=<?php echo ( $n + $interval) ?>";
			}
			setTimeout( "nextpage()", 1250 );

			//-->
			</script>
			<?php
			echo '<p>'. $this->import_harvest_tobepublished_count() .' records remain to be published.</p>';
		} else {
			echo '<p>That&#039;s all folks. kthnxbye.</p>';
		}

		echo '<pre>';
		print_r( $wpdb->queries );
		echo '</pre>';
		?><?php echo get_num_queries(); ?> queries. <?php timer_stop(1); ?> seconds. <?php
	} 

	function import_harvest_upgradeoldarray( &$r ){ 
		$r = array( 'marcish' => $r );
		$r['_title'] = $r['marcish']['title'][0]['a'];
		$r['_acqdate'] = $r['marcish']['_acqdate'];
		unset( $r['marcish']['_acqdate'] );
		$r['_sourceid'] = $r['marcish']['_sourceid'];
		unset( $r['marcish']['_sourceid'] );
		$r['_idnumbers'] = $r['marcish']['idnumbers'];
		
		return( $r );
	}

	function import_harvest_passive(){ 
		global $wpdb, $bsuite; 

		if( !$bsuite->get_lock( 'scrib_harvest_passive' ))
			return( FALSE );

		$posts = $wpdb->get_results('SELECT * FROM '. $this->harvest_table .' WHERE imported = 0 ORDER BY enriched DESC LIMIT 25', ARRAY_A);

		if( is_array( $posts )) {
			foreach( $posts as $post ) {
				set_time_limit( 900 );

				$r = unserialize( $post['content'] );
				if( !is_array( $r ))
					continue;

				if( !array_intersect_key( $r, $this->meditor_forms ))
					$r = $this->import_harvest_upgradeoldarray( $r );

				$post_id = $this->import_insert_post( $r );

				if( $post_id ){
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = 1 WHERE source_id = "'. $post['source_id'] .'"' );
				}else{
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post['source_id'] .'"' );
				}
			}

			wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier

		}

		wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier
	} 








	public function wp_footer_js(){
		$this->suggest_js();
		$this->marcish_availability_gbslink();
	}

	public function shortcode_bookjacket( $arg, $content = '' ){
		// [scrib_bookjacket]<img... />[/scrib_bookjacket]
		global $id, $bsuite;

return('');

		if( !is_singular() ){
			return('<a href="'. get_permalink( $id ) .'">'. $content .'</a>');
		}else{
			preg_match( '/src="([^"]+)?"/', $content, $matches );
			return( '<a href="'. $matches[1] .'" title="'. attribute_escape( strip_tags( get_the_title( $post_id ))) .'">'. $bsuite->icon_get_h( $id, 's' ) .'</a>');
		}
	}
	
	public function shortcode_availability( $arg ){
		// [scrib_availability sourceid="ll1292675"]

		$arg = shortcode_atts( array(
			'sourceid' => FALSE
		), $arg );

global $id, $scribiii_import;
return( $scribiii_import->iii_availability( $id, $arg['sourceid'] ));

		if( function_exists( 'scrib_availability' ) )
			return( scrib_availability( $arg['sourceid'] ));
		else
			return( '<span id="gbs_'. $this->the_gbs_id() .'" class="gbs_link"></span>' );
	}

	public function shortcode_taglink( $arg ){
		// [scrib_taglink taxonomy="subj" value="stuff and things"]

		$arg = shortcode_atts( array(
			'taxonomy' => FALSE,
			'value' => FALSE
		), $arg );

		$tag->taxonomy = $arg['taxonomy'];
		$tag->slug = urlencode( $arg['value'] );

		return( $this->get_tag_link( $tag ));
	}

	public function shortcode_hitcount( $arg ){
		// [scrib_hit_count]

		global $wp_query;

		if( is_array( $this->search_terms['s'] ) && 999 < $wp_query->found_posts )
			return( __( 'more than 1000' ));
		else
			return( number_format( $wp_query->found_posts, 0, _c('.|decimal separator'), _c(',|thousands separator') ));
	}

	public function suggest_js(){
?>
	<script type="text/javascript">
		jQuery(function() {
			jQuery("#s").addClass("scrib-search");

			jQuery("input.scrib-search").scribsuggest("<?php bloginfo('home'); ?>/index.php?scrib_suggest=go");

			jQuery("input.scrib-search").val("<?php _e( 'Books, Movies, etc.', 'Scrib' ) ?>")
			.focus(function(){
				if(this.value == "<?php _e( 'Books, Movies, etc.', 'Scrib' ) ?>") {
					this.value = '';
				} 
			})
			.blur(function(){
				if(this.value == '') {
					this.value = "<?php _e( 'Books, Movies, etc.', 'Scrib' ) ?>";
				} 
			});
		});
	</script>
<?php
	}

	public function suggest_search(){
		@header('Content-Type: text/html; charset=' . get_option('blog_charset'));

		$s = sanitize_title( trim( $_REQUEST['q'] ));
		if ( strlen( $s ) < 2 )
			die; // require 2 chars for matching
		
		if ( isset( $_GET['taxonomy'] )){
			$taxonomy = explode(',', $_GET['tax'] );
			$taxonomy = array_filter( array_map( 'sanitize_title', array_map( 'trim', $taxonomy )));
		}else{
			$taxonomy = $this->taxonomies_for_suggest;
		}

		$cachekey = md5( $s . implode( $taxonomy ));
		if(!$suggestion = wp_cache_get( $cachekey , 'scrib_suggest' )){
			global $wpdb;

/* old, innefficient way:
			$results = $wpdb->get_results( "SELECT t.name, tt.taxonomy, LENGTH(t.name) AS len
				FROM $wpdb->term_taxonomy AS tt 
				INNER JOIN $wpdb->terms AS t ON tt.term_id = t.term_id 
				WHERE tt.taxonomy IN('" . implode( "','", $taxonomy ). "') 
				AND t.slug LIKE ('" . $s . "%')
				ORDER BY len ASC, tt.count DESC
				LIMIT 50;
			");
*/

			$results = $wpdb->get_results( "SELECT t.name, tt.taxonomy, t.len
				FROM 
				(
					SELECT term_id, name, LENGTH(name) AS len
					FROM $wpdb->terms 
					WHERE slug LIKE ('" . $s . "%')
					ORDER BY len ASC
					LIMIT 100
				) t
				JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id 
				WHERE tt.taxonomy IN('" . implode( "','", $taxonomy ). "')
				ORDER BY len ASC, tt.count DESC
				LIMIT 25;
			");



			$searchfor = $suggestion = $beginswith = array();
			$searchfor[] = 'Search for "<a href="'. $this->get_search_link( array( 's' => array( attribute_escape( $_REQUEST['q'] )))) .'">'. attribute_escape( $_REQUEST['q'] ) .'</a>"';
			$template = '<span class="taxonomy_name">%%taxonomy%%</span> <a href="%%link%%">%%term%%</a>';
			foreach($results as $term){
				if('hint' == $term->taxonomy){
					$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $this->taxonomy_name['s'], $this->get_search_link(array('s' => array( $this->suggest_search_fixlong( $term->name ))))), $template);
				}else{
					$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $this->taxonomy_name[ $term->taxonomy ], $this->get_search_link(array($term->taxonomy => array( $this->suggest_search_fixlong( $term->name ))))), $template);

					$beginswith[ $term->taxonomy ] = $this->taxonomy_name[ $term->taxonomy ] .' begins with "<a href="'. $this->get_search_link( array( $term->taxonomy => array( $s .'*' ))) .'">'. attribute_escape( $_REQUEST['q'] ) .'</a>"';
 					}
			}
			$suggestion = array_merge( $searchfor, array_slice( $suggestion, 0, 10 ), $beginswith );
			wp_cache_set( $cachekey , $suggestion, 'scrib_suggest' );
		}

		echo implode($suggestion, "\n");
/*
		print_r( $wpdb->queries );
		echo get_num_queries(); ?> queries. <?php timer_stop(1); ?> seconds. <?php
*/
		die;
	}

	public function suggest_search_fixlong( $suggestion ){
		if( strlen( $suggestion )  > 54)
			return( $suggestion . '*');
		return( $suggestion );
	}

	public function get_search_link( $input ) {
	
		$tags = array();
		foreach( $input as $key => $val )
			$tags[ $key ] = implode( '|', $val );

//		if( $this->forced_browse_category )


		if ( !empty( $tags['s'] )) {
			$keywords = $tags['s'];
			unset( $tags['s'] );
			$taglink = $this->options['search_url'] . urlencode( $keywords ) .'?'. http_build_query($tags);
		}else{
			$taglink = $this->options['browse_url'] .'?'. http_build_query( $tags );
		}

		return trim($taglink, '?');
	}
	
	public function get_tag_link( $tag ) {
		global $wp_rewrite;

		if( is_object( $tag ))
			$tag = get_object_vars( $tag );

		$taglink = $this->options['browse_url'] . '?' . $tag['taxonomy'] . '=' . $tag['slug'];

		return $taglink;

/*
		global $wp_rewrite;
		$taglink = $this->options['browse_url'] . '?' . $tag->taxonomy . '=' . $tag->slug;

//		return apply_filters('tag_link', $taglink, $tag_id);
		return $taglink;
*/
	}
	
	public function the_taxonomies_for_bsuite_suggestive( $taxonomies ) {
		if( $this->taxonomies_for_related )
			return( $this->taxonomies_for_related );
		else
			return( $taxonomies );
	}

	public function get_the_tags( $id = 0 ) {
		global $post, $wp_taxonomies;

		$id = (int) $id;
		if ( !$id )
			$id = (int) $post->ID;

		$terms = wp_get_object_terms( $id, array_intersect( array_keys( $wp_taxonomies ), $this->taxonomies ));
		foreach ( $terms as $term )
			$tags[$term->taxonomy][$term->term_id] = $term;

//		$tags = apply_filters( 'get_the_tags', $tags );
		if ( empty( $tags ) )
			return false;
		return $tags;
	}
	
	public function get_the_tag_list( $facets = FALSE, $before = '', $sep = '', $after = '' ) {
		$tags = $this->get_the_tags();
	
		if ( empty( $tags ) )
			return false;
			
		if ( $facets === FALSE )
			$facets = $this->taxonomies;

		if ( !is_array($facets) )
			$facets = explode(',', $facets);

		$tag_list = $before;
		foreach ( $tags as $taxonomy ){
			foreach ( $taxonomy as $tag ){
				if(in_array($tag->taxonomy, $facets))
					$tag_links[] = '<a href="' . $this->get_tag_link($tag) . '" rel="tag">' . $tag->name . '</a>';
			}
		}

		if(empty($tag_links))
			return(FALSE);
		
		$tag_links = join( $sep, $tag_links );
//		$tag_links = apply_filters( 'the_tags', $tag_links );
		$tag_list .= $tag_links;
	
		$tag_list .= $after;
	
		return $tag_list;
	}
	
	public function the_tags( $facets = FALSE, $before = 'Tags: ', $sep = ', ', $after = '' ) {
		echo $this->get_the_tag_list($facets, $before, $sep, $after);
	}
	







	public function tag_cloud( $args = '' ) {
		$defaults = array(
			'smallest' => 8, 'largest' => 33, 'unit' => 'pt', 'number' => 45,
			'format' => 'flat', 'orderby' => 'name', 'order' => 'ASC',
			'exclude' => '', 'include' => ''
		);
		$args = wp_parse_args( $args, $defaults );

		if ( empty($this->the_matching_facets) )
			return;

		$return = $this->generate_tag_cloud( $this->the_matching_facets, $args ); // Here's where those top tags get sorted according to $args
		//echo apply_filters( 'wp_tag_cloud', $return, $args );
		return $return;
	}
	
	public function generate_tag_cloud( &$tags, &$args = '' ) {
		global $wp_rewrite;
		$defaults = array(
			'smallest' => 8, 'largest' => 22, 'unit' => 'pt', 'number' => 45,
			'format' => 'flat', 'orderby' => 'name', 'order' => 'ASC', 'facets' => $this->taxonomies
		);
		$args = wp_parse_args( $args, $defaults );
		extract($args);

		if(!is_array($facets))
			$facets = explode(',', $facets);
			
		if ( !$tags )
			return;
		$counts = $tag_links = $selected = array();
		foreach ( (array) $tags as $tag ) {
			if(!in_array($tag->taxonomy, $facets))
				continue;
			$counts[$tag->name] = $tag->count;

			if(in_array($tag->name, $this->search_terms[$tag->taxonomy])){
				$selected[$tag->name] = ' selected';
				$tag_links[$tag->name] = $this->get_search_link( $this->search_terms );
			}else{
				$selected[$tag->name] = '';
				$tag_links[$tag->name] = $this->get_search_link( array_merge_recursive($this->search_terms, array($tag->taxonomy => array($tag->name))) );
			}

			$tag_ids[$tag->name] = $tag->term_id;
		}
	
		if ( !$counts )
			return;

		asort($counts);
		if($number > 0)
			$counts = array_slice($counts, -$number, $number, TRUE);

		$min_count = min($counts);
		$spread = max($counts) - $min_count;
		if ( $spread <= 0 )
			$spread = 1;
		$font_spread = $largest - $smallest;
		if ( $font_spread <= 0 )
			$font_spread = 1;
		$font_step = $font_spread / $spread;
	
		// SQL cannot save you; this is a second (potentially different) sort on a subset of data.
		if ( 'name' == $orderby )
			uksort($counts, 'strnatcasecmp');
		else
			asort($counts);
	
		if ( 'DESC' == $order )
			$counts = array_reverse( $counts, true );
		
	
		$a = array();
	
		$rel = ( is_object($wp_rewrite) && $wp_rewrite->using_permalinks() ) ? ' rel="tag"' : '';
	
		foreach ( $counts as $tag => $count ) {
			$tag_id = $tag_ids[$tag];
			$tag_link = clean_url($tag_links[$tag]);
			$tag_link = $tag_links[$tag];
			// $tag = str_replace(' ', '&nbsp;', wp_specialchars( $tag ));
			$tag = wp_specialchars( $tag );
			$a[] = "<a href='$tag_link' class='tag-link-$tag_id". $selected[$tag] ."' title='" . attribute_escape( sprintf( __('%d topics'), $count ) ) . "'$rel style='font-size: " .
				( $smallest + ( ( $count - $min_count ) * $font_step ) )
				. "$unit;'>$tag</a>" ;
		}
	
		switch ( $format ) :
		case 'array' :
			$return =& $a;
			break;
		case 'list' :
			$return = "<ul class='wp-tag-cloud'>\n\t<li>";
			$return .= join("</li>\n\t<li>", $a);
			$return .= "</li>\n</ul>\n";
			break;
		default :
			$return = join("\n", $a);
			break;
		endswitch;

		return $return;
//		return apply_filters( 'wp_generate_tag_cloud', $return, $tags, $args );
	}

	public function is_scrib(){
		global $id;
		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['marcish'] ))
			return(TRUE);
		else
			return(FALSE);
	}

	public function spellcheck(){
		// using Y! Spellcheck Service

		if(empty($this->search_terms['s'])) //short circuit if there's no keyword search
			return(FALSE);

		$cache_key = md5( implode( ' ', $this->search_terms['s'] ) );
		$cache = wp_cache_get( $cache_key , 'scrib_spellcheck' );

		if( is_array( $cache['ResultSet'] ) && empty( $cache['ResultSet']['Result'] ))
			return( FALSE );

		if( !$cache ){
			// The POST URL and parameters
			$request = 'http://search.yahooapis.com/WebSearchService/V1/spellingSuggestion';
			$postargs = http_build_query(array(
				'appid' => 'ArwZj6XV34Gifv47B08dHuxjnSHlaEIdGNdM50aIUemwvo_Nmj4_UpqqlTCqHzdngqws',
				'output' => 'php',
				'query' => implode( ' ', $this->search_terms['s'] )
				));
			
			// Get the curl session object
			$session = curl_init($request);
			
			// Set the POST options.
			curl_setopt($session, CURLOPT_POST, true);
			curl_setopt($session, CURLOPT_POSTFIELDS, $postargs);
			curl_setopt($session, CURLOPT_HEADER, FALSE);
			curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
			
			// Do the POST and then close the session
			$cache = array();
			$cache = unserialize( curl_exec( $session ));
			curl_close( $session );

			wp_cache_add( $cache_key , $cache, 'scrib_spellcheck' );

		}
		if( !isset( $cache['ResultSet']['Result'] ))
			return( FALSE );

		return( '<a href="'. $this->options['search_url'] . urlencode( $cache['ResultSet']['Result'] ) .'">'. $cache['ResultSet']['Result'] .'</a>'  );

	}

	public function link2me( $things, $post_id ){
		global $bsuite;

		$things[] = array('code' => $bsuite->icon_get_h( $post_id, 's', TRUE ), 'name' => 'Embed Small' );
		$things[] = array('code' => $bsuite->icon_get_h( $post_id, 'l', TRUE ), 'name' => 'Embed Large' );

		return( $things );
	}

	function the_related_bookjackets($before = '<li>', $after = '</li>') {
		global $post, $bsuite;
		$report = FALSE;

		$id = (int) $post->ID;
		if ( !$id )
			return FALSE;

		$posts = array_slice( $bsuite->bsuggestive_getposts( $id ), 0, 10 );
		if($posts){
			$report = '';
			foreach($posts as $post_id){
				$url = get_permalink($post_id);
				$linktext = trim( substr( strip_tags(get_the_title($post_id)), 0, 45));
				if( $linktext <> get_the_title($post_id) )
					$linktext .= __('...');
				$report .= $before ."<a href='$url'>". $bsuite->icon_get_h( $post_id, 's' ) . "</a><h4><a href='$url'>$linktext</a></h4>". $after;
			}
		}
		return($report);
	}

	public function textthis(){
		global $post;

		/* get the SMS config */
		require_once(ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/conf_sms.php');

		/* prepare the SMS message */
		$sms[] = $scribsms_content_pre . "\n";
		$sms[] = strlen( $post->post_title ) > 30 ? trim( substr( $post->post_title, 0, 30 )) . '...' : trim( substr( $post->post_title, 0, 30 ));
		if( ( $sourceid = wp_get_object_terms( $post->ID, 'sourceid' )) && ( count( wp_get_object_terms( $post->ID, 'sourceid' ))));
			$sms[] = scrib_availability( $sourceid[0]->name ) . "\n";
		$sms[] = get_permalink( $post->ID ) . "\n";
		$sms = substr( implode( array_filter( array_map( 'trim', $sms )), "\n" ), 0, 450 );

		/* create the replacement post content */
		$content = '<br />';

		/* send the message if we have a destination phone number */
		if( isset( $_POST['textthis_smsto'] ) && strlen( ereg_replace( '[^0-9]', '', $_POST['textthis_smsto'] )) == 11 ){
			$mysms = new bSuite_sms( $scribsms_api_id, $scribsms_user, $scribsms_pass );
			if( $mysms->send( $sms, ereg_replace( '[^0-9]', '', $_POST['textthis_smsto'] )))
				$content .= '<h3 class="notice">Success! Your message was sent to '. ereg_replace( '[^0-9]', '', $_REQUEST['textthis_smsto'] ) .'.</h3>';
			else
				$content .= '<h3 class="error">Error: there was an error sending the message.</h3>';

//print_r( $mysms );
//echo $mysms->querymsg( $mysms->last_id );
		}else if( isset( $_REQUEST['textthis_smsto'] )){
			$content .= '<h3 class="error">Error: please enter a complete phone number.</h3>';
		}

		/* create the form to input the destination number */
		$content .= '
<h3>Send information about this item as an SMS text message.</h3>
<form id="textthis_form" name="textthis_form" action="'. add_query_arg( 'textthis', '1', get_permalink( $post->ID )) .'" method="post">
<p><label for="textthis_smsto">Your cell phone number: <input id="textthis_smsto" name="textthis_smsto" type="text" value="1-XXX-XXX-XXXX" /></label>
<input id="textthis_submit" name="textthis_submit" type="submit" value="Send it!" /></p>
</form>

<h3>Message Preview</h3>
<blockquote><pre>'. $sms .'</pre></blockquote>

<h3>Please Note</h3>
<p>Sending messages is free, but your mobile service provider may charge you to receive the messages. Please check your plan details before continuing.</p>
<p>Unfortunately, you cannot reply to any <a href="http://en.wikipedia.org/wiki/Short_message_service">SMS text messages</a> you receive using this service.</p>
<p>Messaging services are provided by <a href="http://www.clickatell.com/">Clickatell</a> and are subject to their <a href="http://www.clickatell.com/company/privacy.php">privacy policy</a>.</p>
		';
		return( $content );
	}

	function textthis_redirect(){
		global $wp_query;

		if( !empty( $_REQUEST['textthis'] ) && !empty( $wp_query->query_vars['p'] )){
			if( !$textthis = $this->textthis() )
				return( FALSE );

			if(!ereg( '^'.__('Text This', 'Scrib'), $wp_query->post->post_title ))
				$wp_query->post->post_title = $wp_query->posts[0]->post_title = __('Text This', 'Scrib') .': '. $wp_query->post->post_title;

			$wp_query->post->post_content = $textthis;
			$wp_query->posts[0]->post_content = $textthis;

			$wp_query->post->comment_status = 'closed';
			$wp_query->posts[0]->comment_status = 'closed';
		}
	}
	// end sharelinks related functions

	public function widget_editsearch($args) {
		if(!is_search())
			return;

		global $wp_query;
		extract($args);
		$options = get_option('widget_scrib_searchedit');

		$search_title = $options['search-title'];
		$search_text_top = str_replace( '[scrib_hit_count]', $this->shortcode_hitcount(), apply_filters( 'widget_text', $options['search-text-top'] ));
		$search_text_bottom = str_replace( '[scrib_hit_count]', $this->shortcode_hitcount(), apply_filters( 'widget_text', $options['search-text-bottom'] ));

		$browse_title = $options['browse-title'];
		$browse_text_top = str_replace( '[scrib_hit_count]', $this->shortcode_hitcount(), apply_filters( 'widget_text', $options['browse-text-top'] ));
		$browse_text_bottom = str_replace( '[scrib_hit_count]', $this->shortcode_hitcount(), apply_filters( 'widget_text', $options['browse-text-bottom'] ));

		$default_title = $options['default-title'];
		$default_text = str_replace( '[scrib_hit_count]', $this->shortcode_hitcount(), apply_filters( 'widget_text', $options['default-text'] ));

		echo $before_widget; 
		if( $this->is_browse && empty( $this->search_terms )) { 
			if ( !empty( $default_title ) )
				echo $before_title . $default_title . $after_title;
			if ( !empty( $default_text ) ) 
				echo '<div class="textwidget scrib_search_edit">' . $default_text . '</div>';
		}else if( $this->is_browse ) {
			if ( !empty( $browse_title ) )
				echo $before_title . $browse_title . $after_title; 
			if ( !empty( $browse_text_top ) )
				echo '<div class="textwidget scrib_search_edit">' . $browse_text_top . '</div>';
			$this->editsearch();
			if ( !empty( $browse_text_bottom ) )
				echo '<div class="textwidget scrib_search_edit">' . $browse_text_bottom . '</div>';
		}else{
			if ( !empty( $search_title ) )
				echo $before_title . $search_title . $after_title; 
			if ( !empty( $search_text_top ) )
				echo '<div class="textwidget scrib_search_edit">' . $search_text_top . '</div>';
			$this->editsearch();
			if ( !empty( $search_text_bottom ) )
				echo '<div class="textwidget scrib_search_edit">' . $search_text_bottom . '</div>';
		}			
		echo $after_widget;
	}
	
	public function widget_editsearch_control() {
		$options = $newoptions = get_option('widget_scrib_searchedit');
	
		if ( $_POST['widget_scrib_searchedit-submit'] ) {
			$newoptions['search-title'] = strip_tags(stripslashes($_POST['widget_scrib_searchedit-search-title']));
			$newoptions['search-text-top'] = stripslashes($_POST["widget_scrib_searchedit-search-text-top"]);
			$newoptions['search-text-bottom'] = stripslashes($_POST["widget_scrib_searchedit-search-text-bottom"]);

			$newoptions['browse-title'] = strip_tags(stripslashes($_POST['widget_scrib_searchedit-browse-title']));
			$newoptions['browse-text-top'] = stripslashes($_POST["widget_scrib_searchedit-browse-text-top"]);
			$newoptions['browse-text-bottom'] = stripslashes($_POST["widget_scrib_searchedit-browse-text-bottom"]);

			$newoptions['default-title'] = strip_tags(stripslashes($_POST['widget_scrib_searchedit-default-title']));
			$newoptions['default-text'] = stripslashes($_POST["widget_scrib_searchedit-default-text"]);

			if ( !current_user_can('unfiltered_html') ){
				$newoptions['search-text-top'] = stripslashes(wp_filter_post_kses($newoptions['search-text-top']));
				$newoptions['search-text-bottom'] = stripslashes(wp_filter_post_kses($newoptions['search-text-bottom']));

				$newoptions['browse-text-top'] = stripslashes(wp_filter_post_kses($newoptions['browse-text-top']));
				$newoptions['browse-text-bottom'] = stripslashes(wp_filter_post_kses($newoptions['browse-text-bottom']));

				$newoptions['default-text'] = stripslashes(wp_filter_post_kses($newoptions['default-text']));
			}
		}
	
		if ( $options != $newoptions ) {
			$options = $newoptions;
			update_option('widget_scrib_searchedit', $options);
		}
	
		$search_title = attribute_escape( $options['search-title'] );
		$search_text_top = format_to_edit($options['search-text-top']);
		$search_text_bottom = format_to_edit($options['search-text-bottom']);
		$browse_title = attribute_escape( $options['browse-title'] );
		$browse_text_top = format_to_edit($options['browse-text-top']);
		$browse_text_bottom = format_to_edit($options['browse-text-bottom']);
		$default_title = attribute_escape( $options['default-title'] );
		$default_text = format_to_edit($options['default-text']);
	?>
		<h4>Search display:</h4>

		<p><label for="widget_scrib_searchedit-search-title">
		<?php _e('Title:') ?> <input type="text" style="width:300px" id="widget_scrib_searchedit-search-title" name="widget_scrib_searchedit-search-title" value="<?php echo $search_title ?>" /></label>
		</p>
		
		<p><label for="widget_scrib_searchedit-search-text-top">
		<?php _e('Text above:') ?>
		<textarea style="width: 450px; height: 35px;" id="widget_scrib_searchedit-search-text-top" name="widget_scrib_searchedit-search-text-top"><?php echo $search_text_top; ?></textarea>
		</label></p>
		
		<p><label for="widget_scrib_searchedit-search-text-bottom">
		<?php _e('Text below:') ?>
		<textarea style="width: 450px; height: 35px;" id="widget_scrib_searchedit-search-text-bottom" name="widget_scrib_searchedit-search-text-bottom"><?php echo $search_text_bottom; ?></textarea>
		</label></p>

		<h4>Browse display (no keywords):</h4>

		<p><label for="widget_scrib_searchedit-browse-title">
		<?php _e('Title:') ?> <input type="text" style="width:300px" id="widget_scrib_searchedit-browse-title" name="widget_scrib_searchedit-browse-title" value="<?php echo $browse_title ?>" /></label>
		</p>
		
		<p><label for="widget_scrib_searchedit-browse-text-top">
		<?php _e('Text above:') ?>
		<textarea style="width: 450px; height: 35px;" id="widget_scrib_searchedit-browse-text-top" name="widget_scrib_searchedit-browse-text-top"><?php echo $browse_text_top; ?></textarea>
		</label></p>
		
		<p><label for="widget_scrib_searchedit-browse-text-bottom">
		<?php _e('Text below:') ?>
		<textarea style="width: 450px; height: 35px;" id="widget_scrib_searchedit-browse-text-bottom" name="widget_scrib_searchedit-browse-text-bottom"><?php echo $browse_text_bottom; ?></textarea>
		</label></p>

		<h4>Default display (no terms):</h4>

		<p><label for="widget_scrib_searchedit-default-title">
		<?php _e('Title:') ?> <input type="text" style="width:300px" id="widget_scrib_searchedit-default-title" name="widget_scrib_searchedit-default-title" value="<?php echo $default_title ?>" /></label>
		</p>
		
		<p><label for="widget_scrib_searchedit-default-text">
		<?php _e('Text:') ?>
		<textarea style="width: 450px; height: 35px;" id="widget_scrib_searchedit-default-text" name="widget_scrib_searchedit-default-text"><?php echo $default_text; ?></textarea>
		</label></p>
		
		<input type="hidden" name="widget_scrib_searchedit-submit" id="widget_scrib_searchedit-submit" value="1" />
	<?php
	}

	public function widget_facets($args, $number = 1) {
		extract($args);
		$options = get_option('widget_scrib_facets');

		if('list' == $options[$number]['format']){
			$single_before = '<ul class="wp-tag-cloud"><li>';
			$single_between = '</li><li>';
			$single_after = '</li></ul>';

			$search_before = '';
			$search_after = '';
			$search_options = array(
				'smallest' => .9998, 
				'largest' => .9999, 
				'unit' => 'em', 
				'number' => $options[$number]['count'],
				'format' => 'list', 
				'orderby' => 'count', 
				'order' => 'DESC', 
				'facets' => $options[$number]['facets']);
		}else{
			$single_before = '<div class="wp-tag-cloud">';
			$single_between = ', ';
			$single_after = '</div>';

			$search_before = '<div class="wp-tag-cloud">';
			$search_after = '</div>';
			$search_options = array(
				'smallest' => 1, 
				'largest' => 2.15, 
				'unit' => 'em', 
				'number' => $options[$number]['count'],
				'format' => 'flat', 
				'orderby' => 'name', 
				'order' => 'ASC', 
				'facets' => $options[$number]['facets']);
		}

		if(is_singular() && $options[$number]['show_singular'] && $facets = $this->get_the_tag_list($options[$number]['facets'], $single_before, $single_between, $single_after)){
			// actually, it's all done here, just display it below
		}else if(is_search() && $options[$number]['show_search'] && $facets = $this->tag_cloud($search_options)){
			$facets = $search_before . $facets . $search_after;
		}else{
			return;
		}

	?>
			<?php echo $before_widget; ?>
				<?php if ( !empty( $options[$number]['title'] ) ) { echo $before_title . $options[$number]['title'] . $after_title; } ?>
				<?php echo $facets; ?>
			<?php echo $after_widget; ?>
	<?php
	}
	
	public function widget_facets_control($number) {
		$options = $newoptions = get_option('widget_scrib_facets');
		if ( !is_array($options) )
			$options = $newoptions = array();
		if ( $_POST["widget_scrib_facets-submit-$number"] ) {
			$newoptions[$number]['title'] = strip_tags(stripslashes($_POST["widget_scrib_facets-title-$number"]));
			$newoptions[$number]['facets'] = stripslashes($_POST["widget_scrib_facets-facets-$number"]);
			$newoptions[$number]['count'] = (int) $_POST["widget_scrib_facets-count-$number"];
			$newoptions[$number]['show_search'] = stripslashes($_POST["widget_scrib_facets-showsearch-$number"]);
			$newoptions[$number]['show_singular'] = stripslashes($_POST["widget_scrib_facets-showsingular-$number"]);
			$newoptions[$number]['format'] = stripslashes($_POST["widget_scrib_facets-format-$number"]);
		}
		if ( $options != $newoptions ) {
			$options = $newoptions;
			update_option('widget_scrib_facets', $options);
		}
		$title = attribute_escape($options[$number]['title']);
		$facets = format_to_edit($options[$number]['facets']);
		$count = (int) $options[$number]['count'];
		$show_search = $options[$number]['show_search'] ? 'checked="checked"' : '';
		$show_singular = $options[$number]['show_singular'] ? 'checked="checked"' : '';
		$format = attribute_escape($options[$number]['format']);
	?>
				
				<p><label for="widget_scrib_facets-title-<?php echo $number; ?>"><?php _e('Title:'); ?> <input style="width: 250px;" id="widget_scrib_facets-title-<?php echo $number; ?>" name="widget_scrib_facets-title-<?php echo $number; ?>" type="text" value="<?php echo $title; ?>" /></label></p>
				<p><label for="widget_scrib_facets-facets-<?php echo $number; ?>"><?php _e('Facets:'); ?> <input style="width: 250px;" id="widget_scrib_facets-facets-<?php echo $number; ?>" name="widget_scrib_facets-facets-<?php echo $number; ?>" type="text" value="<?php echo $facets; ?>" /></label></p>
				<p><label for="widget_scrib_facets-count-<?php echo $number; ?>"><?php _e('Number of entries to show:'); ?> <input style="width: 25px; text-align: center;" id="widget_scrib_facets-count-<?php echo $number; ?>" name="widget_scrib_facets-count-<?php echo $number; ?>" type="text" value="<?php echo $count; ?>" /></label></p>
				<p><label for="widget_scrib_facets-showsearch-<?php echo $number; ?>"><?php _e('Show in search/browse'); ?> <input class="checkbox" type="checkbox" <?php echo $show_search; ?> id="widget_scrib_facets-showsearch-<?php echo $number; ?>" name="widget_scrib_facets-showsearch-<?php echo $number; ?>" /></label></p>
				<p><label for="widget_scrib_facets-showsingular-<?php echo $number; ?>"><?php _e('Show in single'); ?> <input class="checkbox" type="checkbox" <?php echo $show_singular; ?> id="widget_scrib_facets-showsingular-<?php echo $number; ?>" name="widget_scrib_facets-showsingular-<?php echo $number; ?>" /></label></p>
				<p><label for="widget_scrib_facets-format-<?php echo $number; ?>"><?php _e( 'Format:' ); ?>
					<select name="widget_scrib_facets-format-<?php echo $number; ?>" id="widget_scrib_facets-format-<?php echo $number; ?>">
						<option value="list"<?php selected( $options[$number]['format'], 'list' ); ?>><?php _e('List'); ?></option>
						<option value="cloud"<?php selected( $options[$number]['format'], 'cloud' ); ?>><?php _e('Cloud'); ?></option>
					</select></label></p>
				<input type="hidden" id="widget_scrib_facets-submit-<?php echo "$number"; ?>" name="widget_scrib_facets-submit-<?php echo "$number"; ?>" value="1" />
	<?php
	}
	
	public function widget_facets_setup() {
		$options = $newoptions = get_option('widget_scrib_facets');
		if ( isset($_POST['widget_scrib_facets-number-submit']) ) {
			$number = (int) $_POST['widget_scrib_facets-number'];
			if ( $number > 29 ) $number = 29;
			if ( $number < 1 ) $number = 1;
			$newoptions['number'] = $number;
		}
		if ( $options != $newoptions ) {
			$options = $newoptions;
			update_option('widget_scrib_facets', $options);
			$this->widget_facets_register($options['number']);
		}
	}
	
	function widget_facets_page() {
		$options = $newoptions = get_option('widget_scrib_facets');
	?>
		<div class="wrap">
			<form method="POST">
				<h2><?php _e('Scriblio Facets'); ?></h2>
				<p style="line-height: 30px;"><?php _e('How many facets widgets would you like?'); ?>
				<select id="widget_scrib_facets-number" name="widget_scrib_facets-number" value="<?php echo $options['number']; ?>">
	<?php for ( $i = 1; $i < 30; ++$i ) echo "<option value='$i' ".($options['number']==$i ? "selected='selected'" : '').">$i</option>"; ?>
				</select>
				<span class="submit"><input type="submit" name="widget_scrib_facets-number-submit" id="widget_scrib_facets-number-submit" value="<?php echo attribute_escape(__('Save')); ?>" /></span></p>
			</form>
		</div>
	<?php
	}
	
	public function widget_facets_register() {
		$options = get_option('widget_scrib_facets');
		$number = $options['number'];
		if ( $number < 1 ) $number = 1;
		if ( $number > 29 ) $number = 29;
		$dims = array('width' => 460, 'height' => 350);
		$class = array('classname' => 'widget_scrib_facets');
		for ($i = 1; $i <= 29; $i++) {
			$name = sprintf(__('Scrib Facets %d'), $i);
			$id = "widget_scrib_facets-$i"; // Never never never translate an id
			wp_register_sidebar_widget($id, $name, $i <= $number ? array(&$this, 'widget_facets') : /* unregister */ '', $class, $i);
			wp_register_widget_control($id, $name, $i <= $number ? array(&$this, 'widget_facets_control') : /* unregister */ '', $dims, $i);
		}
		add_action('sidebar_admin_setup', array(&$this, 'widget_facets_setup'));
		add_action('sidebar_admin_page', array(&$this, 'widget_facets_page'));
	}
	
	public function widgets_register(){
		$class['classname'] = 'widget_scrib_searchedit';
		wp_register_sidebar_widget('widget_scrib_searchedit', __('Scrib Search Editor'), array(&$this, 'widget_editsearch'), $class);
		wp_register_widget_control('widget_scrib_searchedit', __('Scrib Search Editor'), array(&$this, 'widget_editsearch_control'), 'width=460&height=600');

		$this->widget_facets_register();
	}

	public function array_unique_deep( $array ) {
		$uniquer = array();
		foreach( $array as $val ){
			$key = $val;
			if( is_array( $val )){
				if( isset( $key['src'] ))
					unset( $key['src'] );
				if( isset( $key['suppress'] ))
					unset( $key['suppress'] );
			}

			$uniquer[ md5( strtolower( serialize( $key ))) ] = $val;
		}
		return( array_values( $uniquer ));
	}

	public function make_utf8( $text ){
		if( function_exists( 'mb_convert_encoding' ))
			return( mb_convert_encoding( $text, 'UTF-8', 'LATIN1, ASCII, ISO-8859-1, UTF-8'));
	
		return( $text );
	}

}


// now instantiate this object
$scrib = & new Scrib;


// some template functions...
function is_browse() {
	global $scrib;
	return( $scrib->is_browse );
}

function is_scrib( $post_id = '' ) {
	global $scrib;
	return( $scrib->is_scrib( $post_id ) );
}

function scrib_the_related(){
	global $scrib;
	echo $scrib->the_related_bookjackets();
}