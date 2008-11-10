<?php
/*
Plugin Name: Scriblio
Plugin URI: http://about.scriblio.net/
Description: Leveraging WordPress as a library OPAC.
Version: 2.6 v00
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/

/*  Copyright 2006-7  Casey Bisson & Plymouth State University

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

		wp_register_style( 'scrib-suggest', $this->path_web .'/css/suggest.css' );
		wp_enqueue_style( 'scrib-suggest' );
		add_action('wp_head', 'wp_print_styles', '9');


		// register WordPress hooks
		add_filter('posts_where', array(&$this, 'posts_where'), 10);
		add_filter('posts_request', array(&$this, 'the_query'), 10);
		add_filter('parse_query', array(&$this, 'parse_query'), 10);
		add_action('admin_menu', array(&$this, 'addmenus'));
		add_filter('bsuite_tokens', array(&$this, 'tokens_set'));
		add_filter('bsuite_suggestive_taxonomies', array(&$this, 'the_taxonomies_for_bsuite_suggestive'), 10, 2);
		add_filter('bsuite_link2me', array(&$this, 'link2me'), 10, 2);
		add_action('widgets_init', array(&$this, 'widgets_register'));

		add_shortcode('scrib_bookjacket', array(&$this, 'shortcode_bookjacket'));
		add_shortcode('scrib_availability', array(&$this, 'shortcode_availability'));
		add_shortcode('scrib_taglink', array(&$this, 'shortcode_taglink'));

		add_action('admin_menu', array( &$this, 'admin_menu_hook' ));
		
		add_action('save_post', array(&$this, 'meditor_save_post'), 2, 2);
		add_filter('pre_post_title', array(&$this, 'meditor_pre_save_filters'));
		add_filter('pre_post_excerpt', array(&$this, 'meditor_pre_save_filters'));
		add_filter('pre_post_content', array(&$this, 'meditor_pre_save_filters'));

		$this->meditor_register_defaults();

		add_filter('the_author', array(&$this, 'the_author_filter'), 1);
		add_filter('author_link', array(&$this, 'author_link_filter'), 1);
		add_filter('the_title', array(&$this, 'gbs_aggregator'), 1);

		add_action('wp_footer', array(&$this, 'wp_footer_js'));

		add_filter('template_redirect', array(&$this, 'textthis_redirect'), 11);

		register_activation_hook(__FILE__, array(&$this, 'activate'));
		add_action('init', array(&$this, 'init'));

		// end register WordPress hooks
	}

	function init(){
		global $wpdb, $wp_rewrite;

		$this->suggest_table = $wpdb->prefix . 'scrib_suggest';

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

		$this->taxonomy_name = $this->options['taxonomies'];
		$this->taxonomies = $this->taxonomies_register();
		$this->taxonomies_for_related = $this->options['taxonomies_for_related'];
		$this->taxonomies_for_suggest = $this->options['taxonomies_for_suggest'];

		$this->harvest_table = $wpdb->prefix . 'scrib_harvest';

		$this->kses_allowedposttags(); // allow more tags

	}

	public function activate() {
		global $wpdb;
		
		// setup default options
		if(!get_option('scrib'))
			update_option('scrib', array(
				'taxonomies' => array(
					's' => 'Keyword', 'auth' => 'Author', 'format' => 'Format', 'isbn' => 'ISBN', 'sourceid' => 'Source ID', 'subj' => 'Subject', 'title' => 'Title'),
				'taxonomies_for_related' => array('auth', 'subj'),
				'taxonomies_for_suggest' => array('auth', 'subj', 'title')
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
			$random_password = substr(md5(uniqid(microtime())), 0, 6);
			$user_id = wp_create_user('cataloger', $random_password);
			$user = new WP_User($user_id);
			$user->set_role('contributor');

			// set the options	
			$options['catalog_author_id'] = (int) $user_id;	
			update_option('scrib', $options);
		}
		
		// setup widget defaults, if they don't exisit
		if(!get_option('widget_scrib_searchedit'))
			update_option('widget_scrib_searchedit', array(
				'search-title' => 'Searching',
				'search-text-top' => 'Your search found [[scrib_hit_count]] items with all of the following terms:',
				'search-text-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search. <a href="http://about.scriblio.net/wiki/what-are-facets">What are facets?</a> Results sorted by keyword relevance.',

				'browse-title' => 'Browsing New Titles',
				'browse-text-top' => 'We have [[scrib_hit_count]] items with all of the following terms:',
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
		$taxonomies = get_option('scrib');
		$taxonomies = array_keys($taxonomies['taxonomies']);

		// register those taxonomies
		foreach($taxonomies as $taxonomy){
			register_taxonomy( $taxonomy, 'post', array('rewrite' => FALSE, 'query_var' => FALSE ));
			$taxonomy = sanitize_title_with_dashes( $taxonomy );
			$wp->add_query_var( $taxonomy );
			$wp_rewrite->add_rewrite_tag( "%$taxonomy%", '([^/]+)', "$taxonomy=" );
			$wp_rewrite->add_permastruct( $taxonomy, "{$this->options['browse_base']}$taxonomy/%$taxonomy%", FALSE );
		}
		return($taxonomies);
	}

	public function taxonomies_getall() {
		global $wpdb;
		return( $wpdb->get_col( "SELECT taxonomy FROM $wpdb->term_taxonomy GROUP BY taxonomy" ));
	}

	public function is_term($term, $taxonomy = '') {
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

	public function get_matching_posts(){
		global $bsuite, $wp_query, $wpdb;
		$post_ids = NULL;
		if( $this->search_terms ){
			$search_terms = $this->search_terms;

			// figure out what page of posts to show
			// $paged, $posts_per_page, and $limit are here for cases
			// where the query doesn't have an explicit LIMIT declaration
			$paged = (int) $wp_query->query_vars['paged'] ? (int) $wp_query->query_vars['paged'] : 1;
			$posts_per_page = (int) $wp_query->query_vars['posts_per_page'] ? (int) $wp_query->query_vars['posts_per_page'] : (int) get_settings('posts_per_page');
			$this->posts_per_page = $posts_per_page;
			
			$cache_key = md5( serialize( $this->search_terms ) . $paged );
			$cache = wp_cache_get( $cache_key , 'scrib_search' );

			if ( isset($cache['count']) && $cache['count'] === 0 ){
				$this->the_matching_posts = FALSE;
				$this->the_matching_post_counts = $cache['post_counts'];
				$this->the_matching_facets = FALSE;
				$this->the_matching_posts_count = 0;
				$this->the_matching_posts_ordinals = FALSE;
				return(FALSE);
			}

			if( !$cache ){
				$from = $where = array();

				$what = ' a.ID ';
				$orderby = 'ORDER BY a.post_date_gmt DESC';
				$keyword_select = '';
				$from[] = " FROM $wpdb->posts a ";
				$limit_sql = 'LIMIT '. ($paged - 1) * $posts_per_page .', 1000';

				// the keywords
				if( !empty( $search_terms['s'] )){
					$boolean = '';
					if(ereg('"|\+|\-|\(|\<|\>|\*', $this->search_terms['s']))
						$boolean = ' IN BOOLEAN MODE';

					if($this->options['sphinxsearch'] && empty($boolean)){
						// get the sphinx client library & configuration
						require_once(ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/includes/sphinxapi.php');
						require_once(ABSPATH . PLUGINDIR .'/'. plugin_basename(dirname(__FILE__)) .'/conf_sphinx.php');

						// init sphinx & apply the config
						$sphinx = new SphinxClient ();
						$sphinx->SetServer ( $sphinx_host, $sphinx_port );
						$sphinx->SetLimits ( $sphinx_recstart, $sphinx_reclimit, $sphinx_recoffset );
						$sphinx->SetWeights ( $sphinx_weights);
						$sphinx->SetMatchMode ( $sphinx_matchmode );
						$sphinx->SetSortMode ( $sphinx_sortmode, $sphinx_sortby );

						// searching the catalog or blog posts or all
						if($wp_query->query_vars['scope'] == 'catalog')
							$sphinx->SetFilter ( 'type', array ( 1 ) );
						if($wp_query->query_vars['scope'] == 'blog')
							$sphinx->SetFilter ( 'type', array ( 0 ) );
		
						// do the search
						$res = $sphinx->Query ( implode($this->search_terms['s'], ' '), $sphinx_index );
				
						if ( is_array($res['matched']) ){
//print_r(array_keys($res['matched']));
//print_r($res['matched']);		
							$keyword_post_ids = array_keys($res['matched']);
							if (SAVEQUERIES) {
								unset( $res['matches'] );
								unset( $res['matched'] );
								$wpdb->queries[] = $res;
							}
						}

						if( !count( $keyword_post_ids )){
							wp_cache_add( $cache_key , array('count' => 0), 'scrib_search' );
							$this->the_matching_posts = FALSE;
							$this->the_matching_post_counts = $matching_post_counts;
							$this->the_matching_facets = FALSE;
							$this->the_matching_posts_count = 0;
							$this->the_matching_posts_ordinals = FALSE;
							return(FALSE);
						}

						unset($search_terms['s']);
					}else{
						$what = " a.ID, MATCH (b.content, b.title) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."'$boolean) AS score ";
						$from[] = " INNER JOIN $bsuite->search_table b ON ( b.post_id = a.ID ) ";
						$where[] = " AND (MATCH (b.content, b.title) AGAINST ('". $wpdb->escape(implode($this->search_terms['s'], ' ')) ."'$boolean)) ";
						$orderby = ' ORDER BY score DESC ';
						unset($search_terms['s']);
					}
				}

				// the other facets
				if(!empty($search_terms)){
					foreach($search_terms as $taxonomy => $values){
						foreach($values as $key => $value){
							if(!$tt_ids[] = $this->is_term ( $value, $taxonomy ))
								$matching_post_counts[$taxonomy][$key] = 0;
							else
								$matching_post_counts[$taxonomy][$key] = $wpdb->get_var("SELECT COUNT(term_taxonomy_id) FROM $wpdb->term_relationships WHERE term_taxonomy_id IN (". implode($this->is_term ( $value, $taxonomy ) , ',' ) .')' );

							if($matching_post_counts[$taxonomy][$key] < 1){
								wp_cache_add( $cache_key , array( 'count' => 0, 'post_counts' => $matching_post_counts ), 'scrib_search' );
								$this->the_matching_posts = FALSE;
								$this->the_matching_post_counts = $matching_post_counts;
								$this->the_matching_facets = FALSE;
								$this->the_matching_posts_count = 0;
								$this->the_matching_posts_ordinals = FALSE;
								return(FALSE);
							}
						}
					}
				}

				$tt_ids = array_filter( $tt_ids );
				$taliases = range( 'a','z' );
				$i = 1;
				if(count($tt_ids) > 0){
					foreach($tt_ids as $tt_id){
						$from[] = " INNER JOIN $wpdb->term_relationships ". $taliases[ceil($i / 26)] . $taliases[($i % 26)] .' ON a.ID = '. $taliases[ceil($i / 26)] . $taliases[($i % 26)] .'.object_id ';
						$where[] = ' AND '. $taliases[ceil($i / 26)] . $taliases[($i % 26)] .'.term_taxonomy_id IN ('. implode($tt_id, ',') .') ';
						$i++;
					}
				}

				// it's a piece of cake to bake a pretty cake
				// bring this all together and find the right posts
				if( isset( $this->search_terms['s'] ) && $this->options['sphinxsearch'] ){
					$keyword_select = 'AND a.ID IN ('. implode($keyword_post_ids, ',') .')' ;
					$limit_sql = '';
					$orderby = '';
				}
			
				$post_ids = $wpdb->get_col('SELECT SQL_CALC_FOUND_ROWS '. $what . implode($from) .' WHERE 1=1 '. implode($where) .' '. $keyword_select .' AND (a.post_type IN ("post", "page") AND (a.post_status IN ("publish", "private"))) GROUP BY a.ID '. $orderby .' '. $limit_sql);
			
				if( count( $post_ids )){
					if( isset( $this->search_terms['s'] ) && $this->options['sphinxsearch'] ){
						$cache['ordinals'] = array_flip( $keyword_post_ids );
						foreach($post_ids as $post_id)
							$new_order[$cache['ordinals'][$post_id]] = $post_id;
						ksort( $new_order );
						$post_ids = array_values( $new_order );
					}

					$cache['count'] = $wpdb->get_var( 'SELECT FOUND_ROWS()' );
					$post_ids = array_slice( $post_ids, 0, $posts_per_page );
					$cache['facets'] = $wpdb->get_results("SELECT b.term_id, b.name, a.taxonomy, COUNT(c.term_taxonomy_id) AS `count`
						FROM (
							SELECT ". $what . implode($from) .' WHERE 1=1 '. implode($where) .' '. $keyword_select .' AND (a.post_type IN ("post", "page") AND (a.post_status IN ("publish", "private"))) GROUP BY a.ID '. $orderby .' '. $limit_sql .
						") p
						INNER JOIN $wpdb->term_relationships c ON p.ID = c.object_id
						INNER JOIN $wpdb->term_taxonomy a ON a.term_taxonomy_id = c.term_taxonomy_id
						INNER JOIN $wpdb->terms b ON a.term_id = b.term_id
						GROUP BY c.term_taxonomy_id ORDER BY `count` DESC LIMIT 1000");
					$cache['posts'] = $post_ids;
					$cache['ordinals'] = array_flip( $post_ids );
					$cache['post_counts'] = $matching_post_counts;
				}else{
					$cache = array('count' => 0);
				}

				wp_cache_add( $cache_key , $cache, 'scrib_search' );
			}

			if(is_array($cache)){
				$this->the_matching_posts = $cache['posts'];
				$this->the_matching_post_counts = $matching_post_counts;
				$this->the_matching_facets = $cache['facets'];
				$this->the_matching_posts_count = $cache['count'];
				$this->the_matching_posts_ordinals = $cache['ordinals'];
				add_filter('the_posts', array(&$this, 'sort_matching_posts'));
				return(TRUE);
			}else{
				$this->the_matching_posts = FALSE;
				$this->the_matching_post_counts = $matching_post_counts;
				$this->the_matching_facets = FALSE;
				$this->the_matching_posts_count = 0;
				$this->the_matching_posts_ordinals = FALSE;
				return(FALSE);
			}
		}
	}

	public function sort_matching_posts($the_posts){

//		$GLOBALS['wp_query']->found_posts = $GLOBALS['wp_query']->post_count = $this->the_matching_posts_count;
		$GLOBALS['wp_query']->max_num_pages = ceil( $this->the_matching_posts_count / $this->posts_per_page);

//print_r($the_posts);
//print_r($this->the_matching_posts_ordinals);

		// insert the ordinal into each post for sorting
		foreach($the_posts as $post){
//echo $post->ID . '='. $this->the_matching_posts_ordinals[$post->ID] .', ';
			$new_order[$this->the_matching_posts_ordinals[$post->ID]] = $post;
		}

		// now that the posts are re-keyed, sort them
		ksort($new_order);

		// This shuffle resets the keys on the array.
		// A function later in WP expects the array keys to be sequential,
		// and the output here might otherwise be non-sequential.
		array_unshift($new_order, 'Junk');
		array_shift($new_order);

//print_r($new_order);
		return($new_order);
	}

	public function search_terms(){
		global $wp_query;
		
		$temp = array_intersect_key($wp_query->query_vars, array_flip($this->taxonomies));

		$terms = FALSE;
		if( count( $temp )){
			$terms  = array();
			reset($temp);
			while (list($key, $val) = each($temp)) {
				$values = (explode('|', urldecode($val)));
				foreach($values as $val){
					$terms[$key][] = $val;
				}
			}
			$this->is_browse = TRUE;
			$wp_query->is_search = TRUE;
			$wp_query->is_singular = FALSE;
			$wp_query->is_page = FALSE;
			$wp_query->is_home = FALSE;
		}
		
		if(!empty($wp_query->query_vars['s']))
			$terms['s'] = explode('|', stripslashes(urldecode($wp_query->query_vars['s'])));

		$this->search_terms = $terms;
		return(array_filter($terms));
	}

	public function posts_where($query){
		// hide catalog entries from front page and rss feeds.
		global $wp_query;
		if( $wp_query->is_home )
			return(" AND post_author <> {$this->options['catalog_author_id']}". $query);

		if($wp_query->is_feed && ( count( $wp_query->query ) < 2))
			return(" AND post_author <> {$this->options['catalog_author_id']}". $query);

		return($query);
	}

	public function the_query($query, $limit = NULL){
		global $bsuite, $wp_query, $wpdb;

//error_log( $query ."\r\r". print_r( $wp_query->query_vars, TRUE ) );
//echo "<h2>$query</h2>";
//print_r($wp_query);

		if( $wp_query->is_admin )
			return($query);

//echo "<h2>$query</h2>";
//print_r($wp_query);

		// establish the query vars
		$this->search_terms();

		if( !$wp_query->is_search ) // return immediately if this is not a search/browse request
			return($query);

//print_r($this->taxonomies);
//print_r($this->search_terms);

		// figure out if we have matching posts, and which ones they are
		$this->get_matching_posts();
		if(is_array($this->the_matching_posts))
			$query = "SELECT * FROM $wpdb->posts WHERE 1=1 
				AND ID IN (" . implode($this->the_matching_posts, ', ') .
				') AND post_status IN ("publish", "private")';
		else
			$query = "SELECT * FROM $wpdb->posts WHERE 1=2";
		
//print_r($wp_query);
//echo "<h2>$query</h2>";

		$this->the_query_string = $query;
		return($query);
	}



	public function parse_query( $the_wp_query ){
		global $wp_query;

		$test_query = $the_wp_query->query;
		if( is_array( $test_query )){
			$paged = (int) $test_query['paged'] ? (int) $test_query['paged'] : 1;
			unset( $test_query['paged'] );
		}

		if( $test_query['pagename'] && count( $test_query ) == 1 && $test_query['pagename'] == $this->options['browse_name'] && !$this->parse_query_nonce){
			$this->parse_query_nonce = TRUE;
			return( query_posts( array('category' => get_cat_name( $this->options['catalog_category_id'] ) , 'paged' => $paged )));
		}

		return( $the_wp_query );
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

	public function parse_content(){
		global $post, $bsuite;

		if ( !$ret = wp_cache_get( (int) $post->ID, 'scrib_parsedcontent' )) {
			$xml = $bsuite->makeXMLTree($post->post_content);
			foreach( $xml['ul'][0]['li'] as $val ){
				switch ($val['class']) {
					case 'attribution':
						$ret['attribution'] = $val['cdata'];
					break;
			
					case 'isbn':
						$ret['isbn'] = $val['ul'][0]['li'];
						if( !isset( $ret['gbs_id'] )) 
							$ret['gbs_id'] = 'ISBN:'.$val['ul'][0]['li'][0];
					break;
			
					case 'lccn':
						$ret['lccn'] = $val['ul'][0]['li'];
						if( !isset( $ret['gbs_id'] )) 
							$ret['gbs_id'] = 'LCCN:'.$val['ul'][0]['li'][0];
					break;
				}
			}
			wp_cache_add( (int) $post->ID, $ret, 'scrib_parsedcontent' , 864000 );
		}
		return($ret); // if the cache is still warm, then we return this
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

		}else if( (int) $_GET['scrib_meditor_from'] && ( $data = get_post_meta( (int) $_GET['scrib_meditor_from'], 'scrib_meditor_content', true )) ){
			if( (string) $_GET['scrib_meditor_add'] )
				$data = apply_filters( 'scrib_meditor_add_'. eregi_replace( '[^a-z|0-9]', '', $_GET['scrib_meditor_add'] ), $data, (int) $_GET['scrib_meditor_from'] );
			foreach( $data as $handle => $val )
				if( isset( $this->meditor_forms[ $handle ] ))
					$this->meditor_form( $handle, $this->meditor_forms[ $handle ], $val );
		}
	}

	function meditor_form( $handle, &$prototype, &$data = array() ){
		echo '<ul id="scrib_meditor">';
		foreach( $prototype['_elements'] as $key => $val ){
			$val = is_array( $data[ $key ] ) ? $data[ $key ] : array( array() );
			echo '<li id="scrib_meditor-'. $handle .'-'. $key .'" class="fieldset_title">'.  ( $prototype['_elements'][ $key ]['_title'] ? '<h2>'. $prototype['_elements'][ $key ]['_title'] .'</h2>' : '' ) .'<ul  class="fieldset_title'. ( $prototype['_elements'][ $key ]['_repeatable'] ? ' sortable">' : '">' );
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

			$val = $data[ $key ] ? $data[ $key ] : $prototype['_elements'][ $key ]['_input']['_default'];

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
						call_user_func( $prototype['_elements'][ $key ]['_input']['_function'] , $val, $id, $name );
					else
						echo 'the requested function could not be called';
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

	function meditor_add_related_edlinks( $null ) {
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

	function meditor_add_parent( &$r, &$from ) {
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

	function meditor_add_child( &$r, &$from ) {
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

	function meditor_add_next( &$r, &$from ) {
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

	function meditor_add_previous( &$r, &$from ) {
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

	function meditor_add_reverse( &$r, &$from ) {
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

	function meditor_add_sibling( &$r, &$from ) {
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

	function meditor_save_post($post_id, $post) {
		if ( $post_id && is_array( $_REQUEST['scrib_meditor'] )){

			// make sure meta is added to the post, not a revision
			if ( $the_post = wp_is_post_revision( $post_id ))
				$post_id = $the_post;

			$record = is_array( get_post_meta( $post_id, 'scrib_meditor_content', true )) ? get_post_meta( $post_id, 'scrib_meditor_content', true ) : array();

			foreach( $_REQUEST['scrib_meditor'] as $this->meditor_input->form_key => $this->meditor_input->form ){
				unset( $record[ $this->meditor_input->form_key ] );
				foreach( $this->meditor_input->form as $this->meditor_input->group_key => $this->meditor_input->group )
					foreach( $this->meditor_input->group as $this->meditor_input->iteration_key => $this->meditor_input->iteration )
						foreach( $this->meditor_input->iteration as $this->meditor_input->key => $this->meditor_input->val ){
							if( is_callable( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] )){
								$filtered = FALSE;

								$filtered = call_user_func( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] , $this->meditor_input->val );

								if( !empty( $filtered ))
									$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = $filtered;
							}else{
								if( !empty( $record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] ))
									$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = $this->meditor_input->val;
							}
						}
			}

			add_post_meta( $post_id, 'scrib_meditor_content', $record, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $record );

			do_action( 'scrib_meditor_save_record', $post_id, $record );
		}
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

		//strip html entities, i.e. &#59;
		$htmlentity = '\&\#\d\d\;';
		$lead_htmlentity_pattern = '/^'.$htmlentity.'/';
		$trail_htmlentity_pattern = '/'.$htmlentity.'$/';
		$str = preg_replace($lead_htmlentity_pattern, '', preg_replace($trail_htmlentity_pattern, '', $str)); 

		//strip ASCII punctuations
		$puncts = '\s\~\!\@\#\$\%\^\&\*\_\+\`\-\=\{\}\|\[\]\\\:\"\;\'\<\>\?\,\.\/';
		$lead_puncts_pattern = '/^['.$puncts.']+/';
		$trail_puncts_pattern = '/['.$puncts.']+$/';
		$str = preg_replace($trail_puncts_pattern, '', preg_replace($lead_puncts_pattern, '', $str));

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

	function meditor_save_record( $post_id , $r ) {
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

	function meditor_pre_excerpt( $content, $r ) {
		if( $r['arc'] ){
			$result = '<ul class="summaryrecord dcimage"><li>[icon size="s" /]</li></ul>';
			return($result);
		}
		return( $content );
	}

	function meditor_pre_content( $content, $r ) {
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


	public function meditor_register_menus(){
		if( ( 'post-new.php' == basename( $_SERVER['PHP_SELF'] )) && ( isset( $_GET['posted'] ) ) && ( !isset( $_GET['scrib_meditor_add'] ) ) && ( $form = key( get_post_meta( $_GET['posted'], 'scrib_meditor_content', true )) ) ){
				$_GET['scrib_meditor_add'] = 'sibling';
				$_GET['scrib_meditor_from'] = $_GET['posted'];
				die( wp_redirect( admin_url( 'post-new.php' ) .'?'. http_build_query( $_GET ) ));
		}

		add_submenu_page('post-new.php', 'bSuite bStat Reports', 'Archive Item', 'edit_posts', 'post-new.php?scrib_meditor_form=arc' );
		add_submenu_page('post-new.php', 'bSuite bStat Reports', 'Bibliographic Record', 'edit_posts',  'post-new.php?scrib_meditor_form=marcish' );
	}

	public function meditor_register( $handle , $prototype ){
		if( isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		$this->meditor_forms[ $handle ] = $prototype;
	}

	public function meditor_unregister( $handle ){
		if( !isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		unset( $this->meditor_forms[ $handle ] );
	}

	public function meditor_register_defaults( ){
		$this->meditor_register( 'marcish', 
			array(
				'_title' => 'Bibliographic Record',
				'_elements' => array( 
					'title' => array(
						'_title' => 'Title',
						'_repeatable' => TRUE,
						'_elements' => array( 
							'a' => array(
								'_title' => '',
								'_input' => array(
									'_type' => 'text',
									'_autocomplete' => 'off',
								),
							),
						),
					),
				),
			)
		);

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

		// actions and filters for dcimage
		add_action('scrib_meditor_form_arc', array(&$this, 'meditor_form_hook'));

		// taxonomies for dcimage
		register_taxonomy( 'creator', 'post');
		register_taxonomy( 'subject', 'post');
		register_taxonomy( 'collection', 'post');
		register_taxonomy( 'lang', 'post');
		register_taxonomy( 'dy', 'post');
		register_taxonomy( 'dm', 'post');
		register_taxonomy( 'geog', 'post');
		register_taxonomy( 'format', 'post');

		// general actions and filters for all default forms
		add_action('admin_menu', array(&$this, 'meditor_register_menus'));
		add_filter( 'the_content', array(&$this, 'meditor_the_content'));
		add_filter( 'the_excerpt', array(&$this, 'meditor_the_excerpt'));

		add_action('scrib_meditor_save_record', array(&$this, 'meditor_save_record'), 1, 2);

		add_filter('scrib_meditor_pre_excerpt', array(&$this, 'meditor_pre_excerpt'), 1, 2);
		add_filter('scrib_meditor_pre_content', array(&$this, 'meditor_pre_content'), 1, 2);

		add_filter('scrib_meditor_add_parent', array(&$this, 'meditor_add_parent'), 1, 2);
		add_filter('scrib_meditor_add_child', array(&$this, 'meditor_add_child'), 1, 2);
		add_filter('scrib_meditor_add_next', array(&$this, 'meditor_add_next'), 1, 2);
		add_filter('scrib_meditor_add_previous', array(&$this, 'meditor_add_previous'), 1, 2);
		add_filter('scrib_meditor_add_reverse', array(&$this, 'meditor_add_reverse'), 1, 2);

		//add_filter('the_editor', array(&$this, 'meditor_make_content_closable'), 1);

	}

	public function meditor_unregister_defaults(){
		remove_action( 'admin_menu', array( &$this, 'meditor_register_menus'));
		remove_filter( 'the_content', array( &$this, 'meditor_the_content' ));
		remove_filter( 'the_excerpt', array( &$this, 'meditor_the_excerpt' ));

		remove_action('scrib_meditor_form_arc', array(&$this, 'meditor_form_hook'));
		remove_action('scrib_meditor_save_record', array(&$this, 'meditor_save_record'), 1, 2);

		remove_filter('scrib_meditor_pre_excerpt', array(&$this, 'meditor_pre_excerpt'), 1, 2);
		remove_filter('scrib_meditor_pre_content', array(&$this, 'meditor_pre_content'), 1, 2);

		remove_filter('scrib_meditor_add_parent', array(&$this, 'meditor_add_parent'), 1, 2);
		remove_filter('scrib_meditor_add_child', array(&$this, 'meditor_add_child'), 1, 2);
		remove_filter('scrib_meditor_add_next', array(&$this, 'meditor_add_next'), 1, 2);
		remove_filter('scrib_meditor_add_previous', array(&$this, 'meditor_add_previous'), 1, 2);
		remove_filter('scrib_meditor_add_reverse', array(&$this, 'meditor_add_reverse'), 1, 2);
	}

	public function meditor_the_content( $content ){
		global $id;
		if( $id && ( $r = get_post_meta( $id, 'scrib_meditor_content', true )) && is_array( $r['arc'] )){
			global $bsuite;
			$bigimg = $bsuite->icon_get_a( $id, 'b' );

			$result = '<ul class="fullrecord arc"><li class="image"><a href="'. $bigimg['url'] .'" title="view larger image">[icon size="l" /]</a>
				<ul class="options">
					<li><a href="#bsuite_share_embed" title="'. __( 'Link to or embed this item in your site', 'Scrib' ) .'">Embed</a></li>
					<li><a href="#bsuite_share_bookmark" title="'. __( 'Bookmark or share this item', 'Scrib' ) .'">Share</a></li>
					<li><a href="#comments" title="'. __( 'Discuss this item in the comments', 'Scrib' ) .'">Discuss</a></li>
					<li><a href="#tagthis" title="'. __( 'Recognize somebody or something? Tag them!', 'Scrib' ) .'">Tag</a></li>
				</ul><hr /></li>';

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

	public function meditor_the_excerpt( $content ){
		global $id;
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








	public function the_author_filter( $content ){
		if(!$this->is_scrib())
			return($content);

		global $id;
		return( get_post_meta( $id, 'scrib_the_author', TRUE ));
	}

	public function author_link_filter( $content ){
		if(!$this->is_scrib())
			return($content);

		global $id;

		$tag->taxonomy = 'auth';
		$tag->slug = urlencode( get_post_meta( $id, 'scrib_the_author_link', TRUE ));
		
		return( $this->get_tag_link( $tag ));
	}

	public function wp_footer_js(){
		$this->suggest_js();
		$this->gbs_link();
	}

	public function gbs_aggregator($content){
		if(!$this->is_scrib())
			return($content);

/* old way, but hurts performance
		$gbs_id = $this->parse_content();
		$this->gbs_ids[] = $gbs_id['gbs_id'];
*/
		$this->gbs_ids[] = $this->gbs_id();		
		return($content);
	}

	public function gbs_link(){
		if( count( $this->gbs_ids ))
			echo '<script src="http://books.google.com/books?bibkeys='. urlencode( implode( ',', array_unique( $this->gbs_ids ))) .'&jscmd=viewapi&callback=jQuery.GBDisplay"></script>';
	}

	public function gbs_id(){
		global $id;
		return( array_shift( unserialize( get_post_meta( $id, 'scrib_the_bibkeys', TRUE ))));
	}

	public function the_gbs_id(){
		$gbs_id = $this->gbs_id();
		if( $gbs_id )
			return( str_replace( array(':', ' '), '_', $gbs_id ));
		return( FALSE );
	}



	public function shortcode_bookjacket( $arg, $content = '' ){
		// [scrib_bookjacket]<img... />[/scrib_bookjacket]
		global $id;


		if( !is_singular() ){
			return('<a href="'. get_permalink( $id ) .'">'. $content .'</a>');
		}else{
			preg_match( '/src="([^"]+)?"/', $content, $matches );
			return( '<a href="'. $matches[1] .'" title="'. attribute_escape( strip_tags( get_the_title( $post_id ))) .'">'. $this->the_image( 'small', $id, FALSE ) .'</a>');
		}
	}
	
	public function shortcode_availability( $arg ){
		// [scrib_availability sourceid="ll1292675"]

		$arg = shortcode_atts( array(
			'sourceid' => FALSE
		), $arg );

		if( function_exists( 'scrib_availability' ) )
			return( scrib_availability( $arg['sourceid'] ));
		else
			return( '<span id="gbs_'. $this->the_gbs_id() .'" class="gbs_info"></span>' );
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

	//
	// Scriblio specific tokens (requires bsuite)
	//
	public function tokens_set($tokens){
		// setup some tokens
		$tokens['linkto'] = array(&$this, 'token_linkto');
		$tokens['scrib_hit_count'] = array(&$this, 'token_hit_count');
	
		return($tokens);
	}

	public function token_linkto($thing) {
		if( $post_id = reset( get_objects_in_term( is_term( $thing ), 'sourceid' ))){
			return( '<a href="'. get_permalink($post_id) .'">'. $this->the_image('small', $post_id) .'</a>' );
		}

	}

	public function token_hit_count($thing) {
		// [[scrib_hit_count]]
		if(999 < $this->the_matching_posts_count)
			return('more than 1000');
		else
			return($this->the_matching_posts_count);
	}

	public function suggest_init_table(){
		global $wpdb; 
	
		set_time_limit(0);
		ignore_user_abort(TRUE);
		$interval = 1000;

		if( !isset( $_GET[ 'n' ] ) ) {
			$n = 0;

			$charset_collate = '';
			if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
				if ( ! empty($wpdb->charset) )
					$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
				if ( ! empty($wpdb->collate) )
					$charset_collate .= " COLLATE $wpdb->collate";
			}

			// drop the old table
			if($wpdb->get_var("SHOW TABLES LIKE '$this->suggest_table'"))
				$wpdb->get_results("DROP TABLE $this->suggest_table");
	
			// create the table
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta("
				CREATE TABLE $this->suggest_table (
				term_id bigint(20) NOT NULL default '0',
				term_name varchar(55) NOT NULL default '',
				term_rank bigint(20) NOT NULL default '0',
				PRIMARY KEY  (term_id),
				KEY term_name (term_name(3))
				) ENGINE=MyISAM $charset_collate ");

		} else {
			$n = (int) $_GET[ 'n' ] ;
		}

		// get the terms
		$in_taxonomies = "'" . implode("', '", $this->taxonomies_for_suggest) . "'";
		$terms = $wpdb->get_results("SELECT t.term_id, t.name, tt.count FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.taxonomy IN ($in_taxonomies) ORDER BY t.term_id LIMIT $n, $interval");

		if( count( $terms ) ) {
			echo '<div class="updated"><p><strong>' . __('Rebuilding Scriblio search suggest table. Please be patient.', 'Scrib') . "</strong> Working $interval terms, starting with $n .</p></div><div class='narrow'>";
			
			// insert the terms
			foreach($terms as $term){
				$term->rank = (56 - strlen($term->name)) * $term->count;
				if(is_term($term->name, 'hint'))
					$term->rank = $term->rank * 10;
				// could also add ranking based on usage (clicks/checkouts/comment counts) of the related items
				$term->name = ereg_replace('[^a-z|0-9| ]', '', str_replace(array('-','_'), ' ', strtolower(remove_accents($term->name))));
				$values[] = "($term->term_id, '$term->name', $term->rank)";
			}

			$wpdb->get_results("INSERT DELAYED
			INTO $this->suggest_table (term_id, term_name, term_rank) VALUES
			". implode($values, ",\n") ."
			;");

			?>
			<p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="?page=<?php echo plugin_basename(dirname(__FILE__)); ?>/core.php&command=<?php _e('Rebuild Search Suggest Table.', 'Scriblio') ?>&n=<?php echo ($n + $interval) ?>"><?php _e("Next Posts"); ?></a> </p></div>
			<script language='javascript'>
			<!--

			function nextpage() {
				location.href="?page=<?php echo plugin_basename(dirname(__FILE__)); ?>/scriblio.php&command=<?php _e('Rebuild Search Suggest Table', 'Scriblio') ?>&n=<?php echo ($n + $interval) ?>";
			}
			setTimeout( "nextpage()", 250 );

			//-->
			</script>
			<?php
		} else {
			echo '<div class="updated"><p><strong>'. __('Scriblio search suggest table rebuilt.', 'bsuite') .'</strong></p></div>';
			?>
			<script language='javascript'>
			<!--

			function nextpage() {
				location.href="?page=<?php echo plugin_basename(dirname(__FILE__)); ?>/scriblio.php";
			}
			setTimeout( "nextpage()", 3000 );

			//-->
			</script>
			<?php
		}
	}

	public function suggest_js(){
?>
	<script type="text/javascript">
		jQuery(function() {
			jQuery("#s").scribsuggest("<?php echo substr( $this->path_web, strpos( $this->path_web, '/', 8 )) ?>/suggest.php");

			jQuery("#s").val("<?php _e( 'Books, Movies, etc.', 'Scrib' ) ?>")
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



	public function get_search_link( $input ) {
	
		$tags = FALSE;
		reset($input);
		while (list($key, $val) = each($input)) {
			$tags[$key] = implode('|', $val);
		}

		if (!empty($tags['s'])) {
			$keywords = $tags['s'];
			unset($tags['s']);
			$taglink = $this->options['search_url'] . urlencode($keywords) .'?'. http_build_query($tags);
		}else{
			$taglink = $this->options['browse_url'] .'?'. http_build_query($tags);
		}

		return trim($taglink, '?');
	}
	
	public function get_tag_link( $tag ) {
		global $wp_rewrite;
		$taglink = $this->options['browse_url'] . '?' . $tag->taxonomy . '=' . $tag->slug;

//		return apply_filters('tag_link', $taglink, $tag_id);
		return $taglink;
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
	
	public function &generate_tag_cloud( $tags, $args = '' ) {
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
		global $post;
		if($post->post_author == $this->options['catalog_author_id'])
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

	public function the_image( $size = 'small', $post_id = NULL, $linked = TRUE){
		if( !$post_id ){
			global $id;
			$post_id = $id;
		}

		if( $img = get_post_meta( $post_id, 'bsuite_post_icon', TRUE )){
			global $bsuite;
			
			$img = $bsuite->icon_get_a( $post_id, ( 'large' == $size ? 'l' : 's' ));

			if( $linked )
				return( '<a href="'. get_permalink( $post_id ) .'" title="'. attribute_escape( get_the_title( $post_id )) .'"><img src="'. $img['url'] .'" width="'. $img['w'] .'" height="'. $img['h'] .'" alt="'. attribute_escape( get_the_title( $post_id )) .'" /></a>' );
			else
				return( '<img src="'. $img['url'] .'" width="'. $img['w'] .'" height="'. $img['h'] .'" alt="'. attribute_escape( get_the_title( $post_id )) .'" />' );

		}else{
			if($size == 'large')
				$image_source = get_post_field( 'post_content', $post_id );
			else
				$image_source = get_post_field( 'post_excerpt', $post_id );
	
			preg_match( '/\[(scrib_bookjacket)\b(.*?)(?:(\/))?\](?:(.+?)\[\/\1\])?/', $image_source, $matches );
	
			// fallback if no image
			if( !$matches[4] )
				$matches[4] = '<img src="http://img.scriblio.net/jacket/blank_misc.png" />';
	
			if( $linked )
				return( '<a href="'. get_permalink( $post_id ) .'" title="'. attribute_escape( strip_tags( get_the_title( $post_id ))) .'">'. $matches[4] .'</a>' );
			else
				return( $matches[4] );
		}
	}

	public function the_format($return = NULL) {
			if($return){
				return strip_tags($this->get_the_tag_list('format'));
			}else{
				echo strip_tags($this->get_the_tag_list('format'));
			}
	}

	public function link2me( $things, $post_id ){
		$things[] = array('code' => $this->the_image( 'small', $post_id ), 'name' => 'Embed Small' );
		$things[] = array('code' => $this->the_image( 'large', $post_id ), 'name' => 'Embed Large' );

		return( $things );
	}

	function the_related_bookjackets($before = '<li>', $after = '</li>') {
		global $post, $bsuite;
		$report = FALSE;

		$id = (int) $post->ID;
		if ( !$id )
			return FALSE;

		$posts = array_slice($bsuite->bsuggestive_getposts($id), 0, 10);
		if($posts){
			$report = '';
			foreach($posts as $post_id){
				$url = get_permalink($post_id);
				$linktext = trim( substr( strip_tags(get_the_title($post_id)), 0, 45));
				if( $linktext <> get_the_title($post_id) )
					$linktext .= '...';
				$report .= $before . $this->the_image('small', $post_id) . "<h4><a href='$url'>$linktext</a></h4>". $after;
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
		$search_text_top = apply_filters( 'widget_text', $options['search-text-top'] );
		$search_text_bottom = apply_filters( 'widget_text', $options['search-text-bottom'] );

		$browse_title = $options['browse-title'];
		$browse_text_top = apply_filters( 'widget_text', $options['browse-text-top'] );
		$browse_text_bottom = apply_filters( 'widget_text', $options['browse-text-bottom'] );

		$default_title = $options['default-title'];
		$default_text = apply_filters( 'widget_text', $options['default-text'] );

		$search_terms = $this->search_terms;

		echo $before_widget; 
		if( $this->is_browse && empty( $search_terms )) { 
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

/*
function scrib_availability($sourceid){
	global $scrib;
	return('<span id="gbs_'. $scrib->the_gbs_id() .'" class="gbs_info"></span>');

	// this function can be used to get the current availability of an item from the ILS,
	// though, because of the differences between ILS and their local configuration,
	// it is up to the local site to develop their own code.
	//
	// Example code can be found at http://about.scriblio.net/wiki/scrib_availability 
}
}
*/

?>