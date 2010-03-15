<?php
/*
Plugin Name: Scriblio
Plugin URI: http://about.scriblio.net/
Description: Leveraging WordPress as a library OPAC.
Version: 2.9a1
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/

/*  Copyright 2005-2010  Casey Bisson & Plymouth State University

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

/*

TODO

replace $this->taxonomies

*/

error_reporting(E_ERROR);
//error_reporting(E_ALL);


class Scrib {

	var $meditor_forms = array();

	public function __construct(){
		global $wpdb;

		// establish web path to this plugin's directory
		$this->path_web = plugins_url( basename( dirname( __FILE__ )));

		// register and queue javascripts
		wp_register_script( 'scrib-suggest', $this->path_web . '/js/jquery.scribsuggest.js', array('jquery'), '20081030' );
		wp_enqueue_script( 'scrib-suggest' );

		wp_register_style( 'scrib-suggest', $this->path_web .'/css/suggest.css' );
		wp_enqueue_style( 'scrib-suggest' );
		add_action('wp_head', 'wp_print_styles', '9');

		// register WordPress hooks
		register_activation_hook(__FILE__, array( &$this, 'activate' ));
		add_action('init', array(&$this, 'init'));

		add_action( 'parse_query', array(&$this, 'parse_query'), 10);
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

		add_action('wp_footer', array(&$this, 'wp_footer_js'));

		add_filter('template_redirect', array(&$this, 'textthis_redirect'), 11);
		// end register WordPress hooks
	}

	public function init()
	{
		global $wpdb, $wp_rewrite, $bsuite;

		$this->suggest_table = $wpdb->prefix . 'scrib_suggest';
		$this->harvest_table = $wpdb->prefix . 'scrib_harvest';

		$slash = $wp_rewrite->use_trailing_slashes ? '' : '/';

		$this->options = get_option('scrib_opts');

		// upgrade old versions
		if( 290 > $this->options['version'] )
			$this->upgrade( $this->options['version'] );

		$this->options['site_url'] = get_settings('siteurl') . '/';
		$this->options['search_url'] = get_settings('siteurl') .'/search/';
		$this->options['browse_url'] = get_permalink($this->options['browseid']) . $slash;
		$this->options['browse_base'] = str_replace( $this->options['site_url'] , '', $this->options['browse_url'] );
		$this->options['browse_name'] = trim( substr( get_page_uri( $this->options['browseid'] ), strrpos( get_page_uri( $this->options['browseid'] ), '/')), '/');

		$this->the_matching_posts = NULL;
		$this->the_matching_posts_ordinals = NULL;
		$this->search_terms = NULL;
		$this->the_matching_post_counts = NULL;

//		add_rewrite_endpoint( 'browse', EP_ROOT );

		$this->initial_articles = array( '/^a /i','/^an /i','/^da /i','/^de /i','/^the /i','/^ye /i' );

		$temp = get_option( 'scrib_categories' );
		$this->category_browse = $temp['browse'];
		$this->category_hide = $temp['hide'];

		$temp = get_option( 'scrib_taxonomies' );
		$this->taxonomy_name = $temp['name'];
		$this->taxonomies = $temp['search'];
		$this->taxonomies_for_related = $temp['related'];
		$this->taxonomies_for_suggest = $temp['suggest'];

		unset( $temp );

		if( $bsuite->loadavg < get_option( 'bsuite_load_max' )) // only do cron if load is low-ish
			add_filter('bsuite_interval', array( &$this, 'import_harvest_passive' ));
	}

	public function activate()
	{
		// check for and upgrade old options
		$this->options = get_option('scrib_opts');

		// upgrade old versions
		if( 290 > $this->options['version'] )
			$this->upgrade( $this->options['version'] );

	}

	public function upgrade( $version )
	{
		static $nonced = FALSE;
		if( $nonced )
			return;

		if( 290 > $version )
		{
			$old = get_option('scrib');

			if( is_array( $old ))
			{
				update_option( 'scrib_opts' , 
					array(
						'browseid' => absint( $old['browse_id'] ),
						'searchprompt' => 'Books, movies, music',
					)
				);
				
				update_option( 'scrib_categories' ,
					array(
						'browse' => array( (string) absint( $old['catalog_category_id'] )),
						'hide' => array( (string) absint( $old['catalog_category_id'] )),
				));
				
				update_option( 'scrib_taxonomies' ,
					array(
						'name' => $old['taxonomies'],
						'search' => array_keys( $old['taxonomies'] ),
						'related' => $old['taxonomies_for_related'],
						'suggest' => $old['taxonomies_for_suggest'],
				));
	
				delete_option('scrib');
			}
		}

		// reload the new options and set the updated version number
		$this->options = get_option('scrib_opts');
		$this->options['version'] = 290;
		update_option( 'scrib_opts' , $this->options );

		$nonced = TRUE;
	}

	public function addmenus(){
		// register the options page
		add_options_page('Scriblio Settings', 'Scriblio', 'manage_options', __FILE__, array(&$this, 'admin_menu'));

		// register the meditor box in the post and page editors
		add_meta_box('scrib_meditor_div', __('Scriblio Metadata Editor'), array( &$this, 'meditor_metabox' ), 'post', 'advanced', 'high');
		add_meta_box('scrib_meditor_div', __('Scriblio Metadata Editor'), array( &$this, 'meditor_metabox' ), 'page', 'advanced', 'high');


		// register the settings
		register_setting( 'Scrib' , 'scrib_taxonomies' , array( &$this , 'save_scrib_taxonomies' ));
		register_setting( 'Scrib' , 'scrib_categories' , array( &$this , 'save_scrib_categories' ));
		register_setting( 'Scrib' , 'scrib_opts' , array( &$this , 'save_scrib_opts' ));
	}

	public function save_scrib_taxonomies( $input )
	{
		global $wp_taxonomies;

		$all_taxonomies = (array) array_flip( array_keys( (array) $wp_taxonomies ));
		$all_taxonomies['s'] = TRUE;

		// do some hokey stuff to set the name for the keyword search query var 's'
		$input['search']['s'] = TRUE; 
		unset( $input['related']['s'] ); 
		unset( $input['suggest']['s'] ); 

		$r = array();

		$r['name'] = array_map( 'wp_filter_nohtml_kses' , array_intersect_key( $input['name'] , $all_taxonomies ));
		$r['search'] = array_keys( array_intersect_key( $input['search'] , $all_taxonomies ));
		$r['related'] = array_keys( array_intersect_key( $input['related'] , $all_taxonomies ));
		$r['suggest'] = array_keys( array_intersect_key( $input['suggest'] , $all_taxonomies ));

		return $r;
	}

	public function save_scrib_categories( $input )
	{

		$r = array();

		foreach( $input['browse'] as $category => $v )
		{
			if(( $temp = is_term( absint( $category ) , 'category' )) && is_array( $temp ))
				$r['browse'][] = $temp['term_id'];
		}

		foreach( $input['hide'] as $category => $v )
		{
			if(( $temp = is_term( absint( $category ) , 'category' )) && is_array( $temp ))
				$r['hide'][] = $temp['term_id'];
		}

		return $r;
	}

	public function save_scrib_opts( $input )
	{
		$r['browseid'] = absint( $input['browseid'] );
		$r['searchprompt'] = wp_filter_nohtml_kses( $input['searchprompt'] );
		$r['facetfound'] = absint( $input['facetfound'] );

		return $r;
	}

	public function admin_menu(){
		require( ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/scriblio_admin.php' );
	}

	public function taxonomies_register() {
		// define the Scrib taxonomies
		global $wpdb, $wp, $wp_rewrite;

		// get the taxonomies from the config or punt and read them from the DB
		$taxonomies = get_option( 'scrib' );
		$taxonomies = array_keys( $taxonomies['taxonomies'] );
		return( $taxonomies );

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
				$values = array_filter( explode( '|', urldecode( $val ) ));
				foreach( $values as $val )
					$terms[ $key ][] = $val;
			}
		}

		// set the search terms array
		$this->search_terms = array_filter( $terms );

//print_r( $this->search_terms );
//die;

		if( 
			1 == count( $this->search_terms ) &&
			( key( $this->search_terms ) <> 's' ) &&
			1 == count( current( $this->search_terms )) &&
			! ( strpos( get_term_link( current( current( $this->search_terms )), key( $this->search_terms )) , $_SERVER['REQUEST_URI'] ))
		)
		{
			die( wp_redirect( get_term_link( current( current( $this->search_terms )), key( $this->search_terms )) , 301 ));
		}

		// check if this is a browse page
		if( isset( $the_wp_query->query_vars['pagename'] ) && $the_wp_query->query_vars['pagename'] == $this->options['browse_name'] ){
			$the_wp_query->query_vars['post_type'] = 'post';
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
				$this->add_browse_filter();
//				$the_wp_query->query_vars['cat'] = $this->options['catalog_category_id'];
//				add_filter( 'posts_request',	array( &$this, 'posts_request' ), 11 );
				$this->search_terms = array();
				return( $the_wp_query );
			}
		}

		// it's not a browse page, but the query contains Scrib search terms
		if( count( $this->search_terms ))
		{
			$this->is_browse = TRUE;

			$this->add_search_filters();
			return( $the_wp_query );
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
			$the_wp_query->query_vars['category__not_in'] = $this->options['category_hide'];
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
				SELECT post_id, ( MATCH ( content, title ) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."') * MATCH ( content, title ) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."' IN BOOLEAN MODE) * MATCH ( title ) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."' IN BOOLEAN MODE)) AS score
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

	public function add_browse_filter(){
		global $wpdb, $bsuite;

		if( count( $this->category_browse ))
			$search_terms = array( 'category' => array_map( 'get_cat_name' , (array) $this->category_browse ));

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

		if( ! $this->options['facetfound'] )
			$this->options['facetfound'] = 1000;
//echo "<h2>$query</h2>";

		$facets_query = "SELECT b.term_id, b.name, a.taxonomy, COUNT(c.term_taxonomy_id) AS `count`
			FROM ("
				. str_replace( $wpdb->posts .'.* ', $wpdb->posts .'.ID ', str_replace( 'SQL_CALC_FOUND_ROWS', '', preg_replace( '/LIMIT[^0-9]*([0-9]*)[^0-9]*([0-9]*)/i', 'LIMIT \1, '. $this->options['facetfound'], $query ))) .
			") p
			INNER JOIN $wpdb->term_relationships c ON p.ID = c.object_id
			INNER JOIN $wpdb->term_taxonomy a ON a.term_taxonomy_id = c.term_taxonomy_id
			INNER JOIN $wpdb->terms b ON a.term_id = b.term_id
			GROUP BY c.term_taxonomy_id ORDER BY count DESC LIMIT 1500";

//echo $facets_query;

		$cachekey = md5( $facets_query );
		if( !$this->the_matching_facets = wp_cache_get( $cachekey , 'scrib_facets' )){
			$this->the_matching_facets = $wpdb->get_results( $facets_query );
			wp_cache_set( $cachekey , $this->the_matching_facets, 'scrib_facets', 126000 );
		}

//print_r( $this->the_matching_facets );

		return $query;
	}

	public function editsearch() {
		global $wpdb, $wp_query, $bsuite;
		$search_terms = $this->search_terms;

		if(!empty($search_terms)){
			echo '<ul>';
			reset($search_terms);
			foreach( $search_terms as $key => $vals ){
				foreach( $vals as $i => $q ){
					$q = stripslashes( $q );

					$temp_query_vars = $search_terms;
					unset( $temp_query_vars[ $key ][ array_search( $q, $search_terms[ $key ] ) ] );
					$temp_query_vars = array_filter( $temp_query_vars );

					// build the query that excludes this search term
					$excludesearch = '[<a href="'. $this->get_search_link( $temp_query_vars ) .'" title="Retry this search without this term">x</a>]';

					// build the URL singles out the search term
					$path = $this->get_search_link( array( $key => array( $q ))) ;

					$matches = !empty( $this->the_matching_post_counts[ $key ][ $i ] ) ? ' ('. $this->the_matching_post_counts[ $key ][ $i ] .' matches)' : '';

					if( strpos( ' '.$q, '-' ))
					{
						$q = get_term_by( 'slug' , $q , $key );
						$q = $q->name;
						$this->search_terms[ $key ][ $i ] = $q;
					}

					echo '<li><label>'. $this->taxonomy_name[ $key ] .'</label>: <a href="'. $path .'" title="Search only this term'. $matches .'">'. convert_chars( wptexturize( $q )) .'</a>&nbsp;'. $excludesearch .'</li>';
				}
			}
			echo '</ul>';
		}
	}

	public function admin_menu_hook() {
		wp_register_style( 'scrib-editor', $this->path_web .'/css/editor.css' );
		wp_enqueue_style( 'scrib-editor' );

		wp_register_script( 'scrib-editor', $this->path_web . '/js/editor.js', array('jquery-ui-sortable'), '1' );
		wp_enqueue_script( 'scrib-editor' );

		wp_register_script( 'jquery-tabindex', $this->path_web . '/js/jquery.keyboard-a11y.js', array('jquery'), '1' );
		wp_enqueue_script( 'jquery-tabindex' );
	}










	public function meditor_metabox( ){
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

	public function meditor_form( $handle, &$prototype, &$data = array() ) {
		add_action( 'admin_footer', array( &$this, 'meditor_footer_activatejs' ));

		echo '<ul id="scrib_meditor">';
		foreach( $prototype['_elements'] as $key => $val ){
			$val = is_array( $data[ $key ] ) ? $data[ $key ] : array( array() );
			echo '<li id="scrib_meditor-'. $handle .'-'. $key .'" class="fieldset_title">'.  ( $prototype['_elements'][ $key ]['_title'] ? '<h2>'. $prototype['_elements'][ $key ]['_title'] .'</h2>' : '' ) . ( $prototype['_elements'][ $key ]['_description'] ? '<div class="description">'. $prototype['_elements'][ $key ]['_description'] .'</div>' : '' ) .'<ul  class="fieldset_title'. ( $prototype['_elements'][ $key ]['_repeatable'] ? ' sortable">' : '">' );
			foreach( $val as $ordinal => $row )
				$this->meditor_form_sub( $handle, $prototype['_elements'][ $key ], $row, $key, $ordinal );
			echo '</ul></li>';
		}
		echo '</ul><p class="scrib_meditor_end" />';

		do_action( 'scrib_meditor_form_'. $handle );
	}

	public function meditor_form_sub( $handle, $prototype, $data, $fieldset, $ordinal ){
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

			if( isset( $prototype['_elements'][ $key ]['_suggest'] ) )
				$this->meditor_suggest_js[ $handle .'-'. $fieldset .'-'. $key ] = 'jQuery("#scrib_meditor-'. $handle .'-'. $fieldset .' li.'. $key .' input").suggest( "admin-ajax.php?action=meditor_suggest_tags&tax='. $prototype['_elements'][ $key ]['_suggest'] .'", { delay: 500, minchars: 2 } );';

			$tabindex++;
		}
		echo '</ul></li>';

	}

	public function meditor_add_related_commandlinks( $null, $handle ) {
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

	public function meditor_save_post($post_id, $post) {
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

	public function meditor_merge_meta( $orig = array(), $new = array(), $nsourceid = FALSE ){

		$orig = apply_filters( 'scrib_meditor_premerge_old', $orig , $nsourceid );
		$new = apply_filters( 'scrib_meditor_premerge_new', $new , $nsourceid );

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

	public function meditor_sanitize_input( &$input ){
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

		return apply_filters( 'scrib_meditor_sanitize_record', $record );
	}

	public function meditor_sanitize_month( $val ){
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

	public function meditor_sanitize_day( $val ){
		$val = absint( $val );
		if( $val > 0 &&  $val < 32 )
			return( $val );
		return( FALSE );
	}

	public function meditor_sanitize_selectlist( $val ){
		if( array_key_exists( $val, $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_input']['_values'] ))
			return( $val );
		return( FALSE );
	}

	public function meditor_sanitize_punctuation( $str )
	{
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

		return $str;
	}

	public function meditor_sanitize_related( $val ){
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

	public function meditor_strip_initial_articles( $content ) {
		// TODO: add more articles, such as those from here: http://www.loc.gov/marc/bibliographic/bdapndxf.html
		return( preg_replace( $this->initial_articles, '', $content ));
	}

	public function meditor_pre_save_filters( $content ) {
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
	}

	public function meditor_register( $handle , $prototype ){
		add_action( 'admin_menu', array( &$this, 'meditor_register_menus' ));

		if( isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		$this->meditor_forms[ $handle ] = $prototype;
	}

	public function meditor_unregister( $handle ){
		if( !isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		unset( $this->meditor_forms[ $handle ] );
	}

	public function meditor_footer_activatejs(){
?>
		<script type="text/javascript">
			scrib_meditor();
		</script>

		<script type="text/javascript">
			jQuery(function() {
				<?php echo implode( "\n\t\t\t\t", $this->meditor_suggest_js ) ."\n"; ?>
			});
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
		if ( strlen( $s ) < 2 )
			$s = '';

		$cachekey = md5( $s . implode( $taxonomy ));

		if( !$suggestion = wp_cache_get( $cachekey , 'scrib_suggest_meditor' )){
			if ( empty( $s ) ){
				foreach( get_terms( $taxonomy, array( 'number' => 25, 'orderby' => 'count', 'order' => 'DESC' ) ) as $term )
					$suggestion[] = $term->name;

				$suggestion = implode( $suggestion, "\n" );
			}else{
				global $wpdb;

				$suggestion = implode( array_unique( $wpdb->get_col( "SELECT t.name, ((( 100 - t.len ) + 1 ) * tt.count ) AS hits
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
					AND tt.count > 0
					ORDER BY hits DESC
					LIMIT 11;
				")), "\n" );
			}

			wp_cache_set( $cachekey , $suggestion, 'scrib_suggest_meditor', 1800 );
		}

		echo $suggestion;
		die;
	}

	public function import_post_exists( $idnumbers ) {
		global $wpdb;

		$post_id = FALSE;
		$post_ids = $tt_ids = array();

		foreach( $idnumbers as $idnum )
			$tt_ids[] = get_term( (int) is_term( (string) $idnum['id'] ), $idnum['type'] );

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

//				usleep( 250000 ); // give the database a moment to settle
			}

			foreach( $post_ids as $post_id )
				if( get_post( $post_id ))
					return( $post_id );
		}

		return( FALSE );
	}

	public function import_deindex_post( $post_ids ){
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

	public function import_insert_post( $bibr ){
//		return(1);
		global $wpdb, $bsuite;

		if( !defined( 'DOING_AUTOSAVE' ) )
			define( 'DOING_AUTOSAVE', TRUE ); // prevents revision tracking
		wp_defer_term_counting( TRUE ); // may improve performance
		remove_filter( 'content_save_pre', array( &$bsuite, 'innerindex_nametags' )); // don't build an inner index for catalog records
		remove_filter( 'publish_post', '_publish_post_hook', 5, 1 ); // avoids pinging links in catalog records
		remove_filter( 'save_post', '_save_post_hook', 5, 2 ); // don't bother
//		kses_remove_filters(); // don't kses filter catalog records
		define( 'WP_IMPORTING', TRUE ); // may improve performance by preventing exection of some unknown hooks

		$postdata = array();
		if( $this->import_post_exists( $bibr['_idnumbers'] )){
			$postdata['ID'] = $this->import_post_exists( $bibr['_idnumbers'] );

			$oldrecord = get_post_meta( $postdata['ID'], 'scrib_meditor_content', true );


//TODO: setting post title and content at this point works, but it ignores the opportunity to merge data from the existing record.

			$postdata['post_title'] = apply_filters( 'scrib_meditor_pre_title', strlen( get_post_field( 'post_title', $postdata['ID'] )) ? get_post_field( 'post_title', $postdata['ID'] ) : $bibr['_title'], $bibr );

			$postdata['post_content'] = apply_filters( 'scrib_meditor_pre_content', strlen( get_post_field( 'post_content', $postdata['ID'] )) ? get_post_field( 'post_content', $postdata['ID'] ) : $bibr['_body'], $bibr );

			if( isset( $bibr['_acqdate'] ))
				$postdata['post_date'] =
				$postdata['post_date_gmt'] =
				$postdata['post_modified'] =
				$postdata['post_modified_gmt'] = strlen( get_post_field( 'post_date', $postdata['ID'] )) ? get_post_field( 'post_date', $postdata['ID'] ) : $bibr['_acqdate'];

		}else{

			$postdata['post_title'] = apply_filters( 'scrib_meditor_pre_title', $bibr['_title'], $bibr );

			$postdata['post_content'] = apply_filters( 'scrib_meditor_pre_content', $bibr['_body'], $bibr );

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

		$postdata['post_excerpt'] = '';

		if( empty( $postdata['post_title'] ))
			return( FALSE );

//echo "<h2>Pre</h2>";
//print_r( $bibr );
//die;

		// sanitize the input record
		$bibr = $this->meditor_sanitize_input( $bibr );

//echo "<h2>Sanitized</h2>";
//print_r( $bibr );

		// merge it with the old record
		if( is_array( $oldrecord ))
			$bibr = $this->meditor_merge_meta( $oldrecord, $bibr, $nsourceid );

//echo "<h2>Merged</h2>";
//print_r( $bibr );

		$post_id = wp_insert_post( $postdata ); // insert the post
		if($post_id){
			add_post_meta( $post_id, 'scrib_meditor_content', $bibr, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $bibr );
			do_action( 'scrib_meditor_save_record', $post_id, $bibr );

			if( isset( $the_icon )){
				if( is_array( $the_icon ))
					add_post_meta( $post_id, 'bsuite_post_icon', $the_icon, TRUE ) or update_post_meta( $post_id, 'bsuite_post_icon', $the_icon );
				else if( is_string( $the_icon ))
					$bsuite->icon_resize( $the_icon, $post_id, TRUE );
			}

			return( $post_id );
		}
		return(FALSE);
	}

	public function import_harvest_tobepublished_count() {
		global $wpdb;
		return( $wpdb->get_var( 'SELECT COUNT(*) FROM '. $this->harvest_table .' WHERE imported = 0' ));
	}

	public function import_harvest_publish() {
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

				$post_id = $this->import_insert_post( $r );
				if( $post_id ){
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = 1, content = "" WHERE source_id = "'. $post['source_id'] .'"' );
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

	public function import_harvest_passive(){
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

				$post_id = $this->import_insert_post( $r );

				if( $post_id ){
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = 1, content = "" WHERE source_id = "'. $post['source_id'] .'"' );
				}else{
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post['source_id'] .'"' );
				}
			}

			wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier

		}

		wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier
	}

	public function import_create_harvest_table()
	{
		global $wpdb, $bsuite;

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
			) $charset_collate
		");

	}








	public function wp_footer_js(){
		$this->suggest_js();
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

	public function suggest_js()
	{
		$searchprompt = $this->options['searchprompt'];

		if( isset( $this->search_terms['s'] ) && count( $this->search_terms['s'] ))
			$searchprompt = implode( ' ' , $this->search_terms['s'] );
?>
	<script type="text/javascript">
		jQuery(function() {
			jQuery("#s").addClass("scrib-search");

			jQuery("input.scrib-search").scribsuggest("<?php bloginfo('home'); ?>/index.php?scrib_suggest=go");

			jQuery("input.scrib-search").val("<?php echo $searchprompt; ?>")
<?php
		if( ! count( $this->search_terms['s'] )):
?>
			.focus(function(){
				if(this.value == "<?php echo $searchprompt; ?>") {
					this.value = '';
				}
			})
			.blur(function(){
				if(this.value == '') {
					this.value = "<?php echo $searchprompt; ?>";
				}
			});
<?php
		endif;

		if( count( $this->search_terms ))
		{
			foreach( $this->search_terms as $taxonomy )
				foreach( $taxonomy as $term )
					foreach( explode( ' ' , $term ) as $term_part )
						$all_terms[] = $this->meditor_sanitize_punctuation( $term_part );

			$all_terms = array_filter( $all_terms );

			if( count( $all_terms ))
			{
				echo "var scrib_search_terms = {terms:['". implode( "','" , array_map( 'htmlentities' , $all_terms )) ."']};";
				echo "jQuery(function(){bsuite_highlight(scrib_search_terms);});";
			}

		}
?>
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

			$results = $wpdb->get_results( "SELECT t.name, tt.taxonomy, ( ( 100 - t.len ) * tt.count ) AS hits
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
				AND tt.count > 0
				ORDER BY hits DESC
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
			wp_cache_set( $cachekey , $suggestion, 'scrib_suggest', 126000 );
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

	public function get_tag_link( $tag )
	{
		global $wp_rewrite;

		if( is_object( $tag ))
			$tag = get_object_vars( $tag );

		return get_term_link( urldecode( $tag['slug'] ), $tag['taxonomy'] );

/*
		$taglink = $this->options['browse_url'] . '?' . $tag['taxonomy'] . '=' . $tag['slug'];

		return $taglink;

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
		foreach ( $tags as $taxonomy )
		{
			foreach ( $taxonomy as $tag )
			{
				if( in_array( $tag->taxonomy, $facets ))
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
			'scope' => 'query', 
			'smallest' => 8, 
			'largest' => 33, 
			'unit' => 'pt', 
			'number' => 45,
			'format' => 'flat', 
			'orderby' => 'name', 
			'order' => 'ASC',
			'exclude' => '', 
			'include' => '',
		);
		$args = wp_parse_args( $args, $defaults );

		if( $args['scope'] == 'query' )
		{
			if( empty( $this->the_matching_facets ))
				return;

			$tags = $this->the_matching_facets;
		}
		else
		{
			$tags = get_terms( $args['facets'], array_merge( $args, array( 'orderby' => 'count', 'order' => 'DESC' )));

			if ( empty( $tags ))
				return;
		}

		$return = $this->generate_tag_cloud( $tags, $args ); // Here's where those top tags get sorted according to $args
		//echo apply_filters( 'wp_tag_cloud', $return, $args );
		return $return;
	}

	public function generate_tag_cloud( &$tags, &$args = '' ) {
		global $wp_rewrite;

		$args = wp_parse_args( $args, array(
			'smallest' => 8, 
			'largest' => 22, 
			'unit' => 'pt', 
			'number' => 45,
			'format' => 'flat', 
			'orderby' => 'name', 
			'order' => 'ASC', 
			'facets' => $this->taxonomies
		));
		extract( $args );

		if(!is_array($facets))
			$facets = explode(',', $facets);

		if ( !$tags )
			return;
		$counts = $tag_links = $selected = array();
		foreach ( (array) $tags as $tag )
		{
			if( !in_array( $tag->taxonomy, $facets ))
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

//print_r( $counts );

		asort( $counts );
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
		if( 'name' == $orderby )
		{
			uksort( $counts, 'strnatcasecmp' );
		}
		else if( 'custom' == $orderby && is_array( $order_custom ) && count( $order_custom ))
		{
			$newcounts = array();
			foreach( $order_custom as $arb_facet )
			{
				if( isset( $counts[ $arb_facet ] ))
					$newcounts[ $arb_facet ] = $counts[ $arb_facet ];
			}

			$counts = $newcounts;
			unset( $newcounts );
		}
		else
		{
			asort( $counts );
		}

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
			$return .= join( "</li>\n\t<li>", $a );
			$return .= "</li>\n</ul>\n";
			break;
		default :
			$return = join("\n", $a);
			break;
		endswitch;

		return $return;
//		return apply_filters( 'wp_generate_tag_cloud', $return, $tags, $args );
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

			wp_cache_set( $cache_key , $cache, 'scrib_spellcheck', time() + 2500000 );

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

	public function the_related_bookjackets($before = '<li>', $after = '</li>') {
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

	public function textthis_redirect(){
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

	public function strip_cdata( $input ){
		return trim( preg_replace( '/<!\[CDATA\[(.+?)\]\]>/is', '\1', (string) $input ));
	}

}


// now instantiate this object
$scrib = & new Scrib;


/*
Facets widget class
*/
class Scrib_Widget_Facets extends WP_Widget {

	function Scrib_Widget_Facets() {
		$widget_ops = array( 'classname' => 'widget_facets', 'description' => __( 'Explore your blog with facets' ) );
		$this->WP_Widget( 'scrib_facets', __( 'Scriblio Facets' ), $widget_ops );
	}

	function widget( $args, $instance ) {
		extract( $args );
		global $scrib;

		//Defaults
		$instance = wp_parse_args( (array) $instance, 
			array( 
				'title' => '', 
				'format_font_small' => 1, 
				'format_font_large' => 2.15, 
			)
		);

		if( $instance['format_font_small'] == $instance['format_font_large'] )
		{
			$instance['format_font_small'] = $instance['format_font_small'] - .002;
			$instance['format_font_large'] = $instance['format_font_large'] - .001;
		}


		if( 'list' == $instance['format'] )
		{
			$orderby = 'count';
			if( in_array( $instance['orderby'], array( 'count', 'name', 'custom' )))
				$orderby = $instance['orderby'];
	
			$order = 'DESC';
			if( in_array( $instance['order'], array( 'ASC', 'DESC' )))
				$order = $instance['order'];

			$single_before = '<ul class="wp-tag-cloud"><li>';
			$single_between = '</li><li>';
			$single_after = '</li></ul>';

			$search_before = '';
			$search_after = '';
			$search_options = array(
				'smallest' => floatval( $instance['format_font_small'] ), 
				'largest' => floatval( $instance['format_font_large'] ),
				'unit' => 'em',
				'scope' => $instance['scope'],
				'number' => $instance['count'],
				'format' => 'list',
				'orderby' => $orderby,
				'order' => $order,
				'facets' => array_keys( $instance['facets'] ),
				'order_custom' => $instance['order_custom'],
			);
		}
		else
		{
			$orderby = 'name';
			if( in_array( $instance['orderby'], array( 'count', 'name', 'custom' )))
				$orderby = $instance['orderby'];
	
			$order = 'ASC';
			if( in_array( $instance['order'], array( 'ASC', 'DESC' )))
				$order = $instance['order'];

			$single_before = '<div class="wp-tag-cloud">';
			$single_between = ', ';
			$single_after = '</div>';

			$search_before = '<div class="wp-tag-cloud">';
			$search_after = '</div>';
			$search_options = array(
				'smallest' => floatval( $instance['format_font_small'] ), 
				'largest' => floatval( $instance['format_font_large'] ),
				'unit' => 'em',
				'scope' => $instance['scope'],
				'number' => $instance['count'],
				'format' => 'flat',
				'orderby' => $orderby,
				'order' => $order,
				'facets' => array_keys( $instance['facets'] ),
				'order_custom' => $instance['order_custom'],
			);
		}

		if( 
			is_singular() 
			&& $instance['show_singular'] 
			&& $facets = $scrib->get_the_tag_list( array_keys( $instance['facets'] ) , $single_before , $single_between , $single_after )
		)
		{
			// actually, it's all done here, just display it below
		}

		else if( 
			( is_search() || $scrib->is_browse )
			&& $instance['show_search'] 
			&& $facets = $scrib->tag_cloud( $search_options )
		)
		{
			$facets = $search_before . $facets . $search_after;
		}

		else
		{
			return;
		}

		echo $before_widget;
		if( ! empty( $instance['title'] ))
		{
			echo $before_title . $instance['title'] . $after_title;
		}
		echo convert_chars( wptexturize( $facets ));
		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{

		$instance = $old_instance;
		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['facets'] = array_filter( array_map( 'wp_filter_nohtml_kses', $new_instance['facets'] ));
		$instance['scope'] = in_array( $new_instance['scope'], array( 'query', 'global' )) ? $new_instance['scope']: '';
		$instance['count'] = absint( $new_instance['count'] );
		$instance['show_search'] = absint( $new_instance['show_search'] );
		$instance['show_singular'] = absint( $new_instance['show_singular'] );
		$instance['format'] = in_array( $new_instance['format'], array( 'list', 'cloud' )) ? $new_instance['format']: '';
		$instance['format_font_small'] = floatval( $new_instance['format_font_small'] );
		$instance['format_font_large'] = floatval( $new_instance['format_font_large'] );
		$instance['orderby'] = in_array( $new_instance['orderby'], array( 'count', 'name', 'custom' )) ? $new_instance['orderby']: '';
		$instance['order'] = in_array( $new_instance['order'], array( 'ASC', 'DESC', 'ARB' )) ? $new_instance['order']: '';
		$instance['order_custom'] = array_filter( array_map( 'trim', (array) preg_split( '/[\n\r]/', wp_filter_post_kses( $new_instance['order_custom'] ))));

		return $instance;
	}

	function form( $instance )
	{

		//Defaults
		$instance = wp_parse_args( (array) $instance, 
			array( 
				'title' => '', 
				'format_font_small' => 1, 
				'format_font_large' => 2.25, 
			)
		);
?>

		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>

		<p>
			<?php _e( 'Facets:' ); ?>
			<ul><?php echo $this->control_facets( $instance , 'facets' ); ?></ul>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('scope'); ?>"><?php _e( 'Show facets from:' ); ?></label>
			<select name="<?php echo $this->get_field_name('scope'); ?>" id="<?php echo $this->get_field_id('scope'); ?>" class="widefat">
				<option value="query" <?php selected( $instance['scope'], 'query' ); ?>><?php _e('The found results'); ?></option>
				<option value="global" <?php selected( $instance['scope'], 'global' ); ?>><?php _e('The entire collection'); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Number of entries to show:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo absint( $instance['count'] ); ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('show_search'); ?>"><?php _e('Show on search pages:'); ?> <input id="<?php echo $this->get_field_id('show_search'); ?>" name="<?php echo $this->get_field_name('show_search'); ?>" type="checkbox" value="1" <?php if ( $instance['show_search'] ) echo 'checked="checked"'; ?>/></label>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('show_singular'); ?>"><?php _e('Show on single item pages:'); ?> <input id="<?php echo $this->get_field_id('show_singular'); ?>" name="<?php echo $this->get_field_name('show_singular'); ?>" type="checkbox" value="1" <?php if ( $instance['show_singular'] ) echo 'checked="checked"'; ?>/></label>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('format'); ?>"><?php _e( 'Format:' ); ?></label>
			<select name="<?php echo $this->get_field_name('format'); ?>" id="<?php echo $this->get_field_id('format'); ?>" class="widefat">
				<option value="list" <?php selected( $instance['format'], 'list' ); ?>><?php _e('List'); ?></option>
				<option value="cloud" <?php selected( $instance['format'], 'cloud' ); ?>><?php _e('Cloud'); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('format_font_small'); ?>"><?php _e( 'Smallest:' ); ?></label>
			<select name="<?php echo $this->get_field_name('format_font_small'); ?>" id="<?php echo $this->get_field_id('format_font_small'); ?>" class="widefat">
<?php 
				for( $i = .25 ; $i < 5.1 ; $i = $i + .25 )
				{
?>
					<option value="<?php echo $i; ?>" <?php selected( $instance['format_font_small'], $i ); ?>><?php printf(__('%s em'), number_format( $i , 2 )); ?></option>
<?php 
				}
?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('format_font_large'); ?>"><?php _e( 'Largest:' ); ?></label>
			<select name="<?php echo $this->get_field_name('format_font_large'); ?>" id="<?php echo $this->get_field_id('format_font_large'); ?>" class="widefat">
<?php 
				for( $i = .25 ; $i < 5.1 ; $i = $i + .25 )
				{
?>
					<option value="<?php echo $i; ?>" <?php selected( $instance['format_font_large'], $i ); ?>><?php printf(__('%s em'), number_format( $i , 2 )); ?></option>
<?php 
				}
?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('orderby'); ?>"><?php _e( 'Order By:' ); ?></label>
			<select name="<?php echo $this->get_field_name('orderby'); ?>" id="<?php echo $this->get_field_id('orderby'); ?>" class="widefat">
				<option value="count" <?php selected( $instance['orderby'], 'count' ); ?>><?php _e('Count'); ?></option>
				<option value="name" <?php selected( $instance['orderby'], 'name' ); ?>><?php _e('Name'); ?></option>
				<option value="custom" <?php selected( $instance['orderby'], 'custom' ); ?>><?php _e('Custom (see below)'); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('order'); ?>"><?php _e( 'Order:' ); ?></label>
			<select name="<?php echo $this->get_field_name('order'); ?>" id="<?php echo $this->get_field_id('order'); ?>" class="widefat">
				<option value="ASC" <?php selected( $instance['order'], 'ASC' ); ?>><?php _e('Ascending A-Z & 0-9'); ?></option>
				<option value="DESC" <?php selected( $instance['order'], 'DESC' ); ?>><?php _e('Descending Z-A & 9-0'); ?></option>
				<option value="ARB" <?php selected( $instance['order'], 'ARB' ); ?>><?php _e('Arbitrary (enter facet order below)'); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('order_custom'); ?>"><?php _e('Display facets in the following order:'); ?></label>
			<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('order_custom'); ?>" name="<?php echo $this->get_field_name('order_custom'); ?>"><?php echo format_to_edit( implode( "\n" , $instance['order_custom'] )); ?></textarea>
		</p>

<?php

	}

	function control_facets( $instance , $whichfield = 'facets' )
	{
		global $scrib;

		foreach( $scrib->taxonomies as $item ){
			$list[] = '<li>
				<label for="'. $this->get_field_id( $whichfield .'-'. $item ) .'"><input id="'. $this->get_field_id( $whichfield .'-'. $item) .'" name="'. $this->get_field_name( $whichfield ) .'['. $item .']" type="checkbox" value="1" '. ( isset( $instance[ $whichfield ][ $item ] ) ? 'checked="checked"' : '' ) .'/> '. $item .'</label>
			</li>';
		}
	
		return implode( "\n", $list );
	}

}// end Scrib_Widget_Facets

/*
Searcheditor widget class
*/
class Scrib_Widget_Searcheditor extends WP_Widget {

	function Scrib_Widget_Searcheditor() {
		$widget_ops = array( 'classname' => 'widget_searcheditor', 'description' => __( 'An editor for the search and browse criteria' ) );
		$this->WP_Widget( 'scrib_searcheditor', __( 'Scriblio Search Editor' ), $widget_ops );
	}

	function widget( $args, $instance ) {

		extract( $args );

		global $wp_query, $scrib;

		if(
			is_singular() ||
			! ( is_search() || $scrib->is_browse )
		)
			return;

		$subsmatch = array(
			'[scrib_hit_count]',
			'[scrib_search_suggestions]',
		);

		$subsreplace = array(
			$scrib->shortcode_hitcount(),
			$scrib->spellcheck(),
		);

		$search_title = $instance['search-title'];
		$search_text_top = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['search-text-top'] ));
		$search_text_bottom = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['search-text-bottom'] ));

		$browse_title = $instance['browse-title'];
		$browse_text_top = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['browse-text-top'] ));
		$browse_text_bottom = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['browse-text-bottom'] ));

		$default_title = $instance['default-title'];
		$default_text = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['default-text'] ));

		echo $before_widget;
		if( $scrib->is_browse && empty( $scrib->search_terms )) {
			if ( !empty( $default_title ) )
				echo $before_title . $default_title . $after_title;
			if ( !empty( $default_text ) )
				echo '<div class="textwidget scrib_search_edit">' . $default_text . '</div>';
		}else if( $scrib->is_browse ) {
			if ( !empty( $browse_title ) )
				echo $before_title . $browse_title . $after_title;
			if ( !empty( $browse_text_top ) )
				echo '<div class="textwidget scrib_search_edit">' . $browse_text_top . '</div>';
			$scrib->editsearch();
			if ( !empty( $browse_text_bottom ) )
				echo '<div class="textwidget scrib_search_edit">' . $browse_text_bottom . '</div>';
		}else{
			if ( !empty( $search_title ) )
				echo $before_title . $search_title . $after_title;
			if ( !empty( $search_text_top ) )
				echo '<div class="textwidget scrib_search_edit">' . $search_text_top . '</div>';
			$scrib->editsearch();
			if ( !empty( $search_text_bottom ) )
				echo '<div class="textwidget scrib_search_edit">' . $search_text_bottom . '</div>';
		}
		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{
		$instance = $old_instance;

		$instance['search-title'] = wp_filter_nohtml_kses( $new_instance['search-title'] );
		$instance['search-text-top'] = wp_filter_post_kses( $new_instance['search-text-top'] );
		$instance['search-text-bottom'] = wp_filter_post_kses( $new_instance['search-text-bottom'] );

		$instance['browse-title'] = wp_filter_nohtml_kses( $new_instance['browse-title'] );
		$instance['browse-text-top'] = wp_filter_post_kses( $new_instance['browse-text-top'] );
		$instance['browse-text-bottom'] = wp_filter_post_kses( $new_instance['browse-text-bottom'] );

		$instance['default-title'] = wp_filter_nohtml_kses( $new_instance['default-title'] );
		$instance['default-text'] = wp_filter_post_kses( $new_instance['default-text'] );

		return $instance;
	}

	function form( $instance )
	{

		//Defaults
		$instance = wp_parse_args( (array) $instance, 
			array( 
				'search-title' => 'Searching Our Collection',
				'search-text-top' => 'Your search found [scrib_hit_count] items with all of the following terms:',
				'search-text-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search. <a href="http://about.scriblio.net/wiki/what-are-facets">What are facets?</a> Results sorted by keyword relevance.',

				'browse-title' => 'Browsing Our Collection',
				'browse-text-top' => 'We have [scrib_hit_count] items with all of the following terms:',
				'browse-text-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search. <a href="http://about.scriblio.net/wiki/what-are-facets">What are facets?</a> Results sorted by the date added to the collection.',

				'default-title' => 'Browsing Our Collection',
				'default-text' => 'We have [scrib_hit_count] books, CDs, DVDs, and other materials in our collection. You can click through the pages to see every last one of them, or click the links on the right to narrow it down.'
			)
		);
?>

		<div>
			<h3>Search display</h3>
			<p>
				<label for="<?php echo $this->get_field_id('search-title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('search-title'); ?>" name="<?php echo $this->get_field_name('search-title'); ?>" type="text" value="<?php echo esc_attr( $instance['search-title'] ); ?>" />
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('search-text-top'); ?>"><?php _e('Text above:'); ?></label>
				<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('search-text-top'); ?>" name="<?php echo $this->get_field_name('search-text-top'); ?>"><?php echo format_to_edit( $instance['search-text-top'] ); ?></textarea>
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('search-text-bottom'); ?>"><?php _e('Text below:'); ?></label>
				<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('search-text-bottom'); ?>" name="<?php echo $this->get_field_name('search-text-bottom'); ?>"><?php echo format_to_edit( $instance['search-text-bottom'] ); ?></textarea>
			</p>

		</div>

		<div>
			<h3>Browse display (no keywords)</h3>
			<p>
				<label for="<?php echo $this->get_field_id('browse-title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('browse-title'); ?>" name="<?php echo $this->get_field_name('browse-title'); ?>" type="text" value="<?php echo esc_attr( $instance['browse-title'] ); ?>" />
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('browse-text-top'); ?>"><?php _e('Text above:'); ?></label>
				<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('search-text-top'); ?>" name="<?php echo $this->get_field_name('browse-text-top'); ?>"><?php echo format_to_edit( $instance['browse-text-top'] ); ?></textarea>
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('search-text-bottom'); ?>"><?php _e('Text below:'); ?></label>
				<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('browse-text-bottom'); ?>" name="<?php echo $this->get_field_name('browse-text-bottom'); ?>"><?php echo format_to_edit( $instance['browse-text-bottom'] ); ?></textarea>
			</p>

		</div>

		<div>
			<h3>Default display (no terms)</h3>
			<p>
				<label for="<?php echo $this->get_field_id('default-title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('default-title'); ?>" name="<?php echo $this->get_field_name('default-title'); ?>" type="text" value="<?php echo esc_attr( $instance['default-title'] ); ?>" />
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('default-text'); ?>"><?php _e('Text:'); ?></label>
				<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('default-text'); ?>" name="<?php echo $this->get_field_name('default-text'); ?>"><?php echo format_to_edit( $instance['default-text'] ); ?></textarea>
			</p>

		</div>
<?php

	}
}// end Scrib_Widget_Searcheditor


// register these widgets
function scrib_widgets_init()
{
	register_widget( 'Scrib_Widget_Facets' );
	register_widget( 'Scrib_Widget_Searcheditor' );
}
add_action( 'widgets_init', 'scrib_widgets_init', 1 );









// some template functions...
function is_browse() {
	global $scrib;
	return( $scrib->is_browse );
}