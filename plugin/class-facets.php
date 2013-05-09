<?php

class Facets
{
	public $facets;
	var $_all_facets = array();
	var $_query_vars = array();
	var $_foundpostslimit = 1000;
	var $ttl = 600; // 10 minutes

	function __construct()
	{
		add_action( 'init' , array( $this , 'init' ));
		add_action( 'parse_query' , array( $this , 'parse_query' ) , 1 );
		add_filter( 'posts_request', array( $this, 'posts_request' ), 11 );

		add_action( 'template_redirect' , array( $this, '_count_found_posts' ), 0 );
		add_shortcode( 'scrib_hit_count', array( $this, 'shortcode_hit_count' ));
		add_shortcode( 'facets' , array( $this, 'shortcode_facets' ));

		// initialize a standard object to collect facet data
		$this->facets = new stdClass;
	}

	function init()
	{
		do_action( 'scrib_register_facets' );
	}

	function is_browse()
	{
		return is_archive() || is_tax() || is_tag() || is_category();
	}

	function register_facet( $facet_name , $facet_class , $args = array() )
	{
		$args = wp_parse_args( $args, array(
			'has_rewrite' => FALSE, 
			'priority' => 5, 
		));

		// instantiate the facet
		if( class_exists( $facet_class ))
			$this->facets->$facet_name = new $facet_class( $facet_name , $args , $this );
		else
			return FALSE;

		// register the query var and associate it with this facet
		$query_var = $this->facets->$facet_name->register_query_var();
		$this->_query_vars[ $query_var ] = $facet_name;

		// set the priority to determine how to generate the permalink when there are two or more active facets
		// as with WP hook priority, this should be 1-10
		// facets without pretty permalink rewrite rules get no priority
		if( ( 9 > $args['priority'] ) && ( ! $args['has_rewrite'] ) )
			$args['priority'] = 9;
		$this->priority[ $facet_name ] = (int) $args['priority'];
		
	}

	function parse_query( $query )
	{

		// remove the action so it only runs on the main query and the vars don't get reset
		remove_action( 'parse_query' , array( $this , 'parse_query' ) , 1 );

		// identify the selected search terms
		$searched = array_intersect_key( $query->query , $this->_query_vars );
		$this->selected_facets = (object) array();
		foreach( $searched as $k => $v )
		{
			$this->selected_facets->{$this->_query_vars[ $k ]} = $this->facets->{$this->_query_vars[ $k ]}->parse_query( $v , $query );
			$this->selected_facets_counts->{$this->_query_vars[ $k ]} = count( (array) $this->selected_facets->{$this->_query_vars[ $k ]} );
		}

//echo "<pre>";
//global $wp_rewrite;
//print_r( $wp_rewrite );
//print_r( $query );
//print_r( $this );
//print_r( $this->selected_facets );
//echo "</pre>";

		return $query;
	}

	/**
	 * reset filters and actions used by scriblio. this allows the user to
	 * control when the filters/actions should be run again after specific
	 * WP_Query calls.
	 */
	public function reset()
	{
		// the parse_query action and posts_request filter
		// remove themselves after they're called, so we need
		// to add them again when we reset to apply them to the
		// next WP_Query().
		add_action( 'parse_query' , array( $this , 'parse_query' ) , 1 );
		add_filter( 'posts_request', array( $this, 'posts_request' ), 11 );

		// clear out any previous matches
		global $facets;
		$facets->_matching_tax_facets = array();
		unset( $facets->matching_post_ids );
	}

	public function posts_request( $query )
	{

//global $wp_query;
//echo "<pre>";
//print_r( $wp_query );
//echo "</pre>";
//echo "<h2>$query</h2>";

		global $wpdb;

		// deregister this filter after it's run on the first/default query
		remove_filter( 'posts_request', array( $this , 'posts_request' ), 11 );

		$this->matching_post_ids_sql = str_replace( $wpdb->posts .'.* ', $wpdb->posts .'.ID ', str_replace( 'SQL_CALC_FOUND_ROWS', '', preg_replace( '/LIMIT[^0-9]*([0-9]*)[^0-9]*([0-9]*)/i', 'LIMIT \1, '. $this->_foundpostslimit , $query )));

//echo "<h2>$this->matching_post_ids_sql</h2>";

		return $query;
	}

	function get_matching_post_ids()
	{
		if( is_array( $this->matching_post_ids ))
			return $this->matching_post_ids;

		$cache_key = md5( $this->matching_post_ids_sql );

		if( ! $this->matching_post_ids = wp_cache_get( $cache_key , 'scrib-matching-post-ids' ))
		{
			global $wpdb;
	
			$this->matching_post_ids = $wpdb->get_col( $this->matching_post_ids_sql );

			wp_cache_set( $cache_key , $this->matching_post_ids , 'scrib-matching-post-ids' , $this->ttl );
		}

		return $this->matching_post_ids;
	}

	function _count_found_posts()
	{
		global $wp_query;
		$this->count_found_posts = absint( $wp_query->found_posts );
	}

	function shortcode_hit_count( $arg )
	{
		// [scrib_hit_count ]

		return( number_format( $this->count_found_posts, 0, _c('.|decimal separator'), _c(',|thousands separator') ));

	}

	function shortcode_facets( $arg )
	{
		// [facets ]

		$arg = shortcode_atts( array(
			'facet' => FALSE,
			'font_small' => 1,
			'font_large' => 1,
			'number' => 25,
			'name' => 'name',
			'orderby' => 'name',
			'order' => 'ASC',
		), $arg );

		if( ! is_object( $this->facets->{$arg['facet']} ))
			return '';

		$orderby = ( in_array( $arg['orderby'], array( 'count', 'name', 'custom' )) ? $arg['orderby'] : 'name' );
		$order = ( in_array( $arg['order'], array( 'ASC', 'DESC' )) ? $arg['order'] : 'ASC' );

		// configure how it's displayed
		$display_options = array(
			'format' => 'list', 
			'smallest' => floatval( $arg['format_font_small'] ) ? floatval( $arg['format_font_small'] ) : 1, 
			'largest' => floatval( $arg['format_font_large'] ) ? floatval( $arg['format_font_large'] ) : 1,
			'number' => absint( $arg['number'] ) ? absint( $arg['number'] ) : 25,
			'unit' => 'em',
			'name' => ( in_array( $arg['name'], array( 'name', 'description' )) ? $arg['name'] : 'name' ),
			'orderby' => $orderby,
			'order' => $order,
			'order_custom' => $arg['order_custom'],
		);

		$facet_list = $this->facets->{$arg['facet']}->get_terms_in_corpus();

		// and now we wrap it all up for echo later
		return $this->generate_tag_cloud( $facet_list , $display_options );
	}


	function get_queryterms( $facet , $term , $additive = -1 )
	{
		switch( (int) $additive )
		{
			case 1: // TRUE add this facet to the other facets in the previous query
				$vars = clone $this->selected_facets;
				$vars->{$facet} = $this->facets->$facet->queryterm_add( $term , $vars->{$facet} );
				return $vars;
				break;

			case 0: // FALSE remove this term from the current query vars
				$vars = clone $this->selected_facets;
				$vars->$facet = $this->facets->$facet->queryterm_remove( $term , $vars->{$facet} );
				if( ! count( (array) $vars->$facet ))
					unset( $vars->$facet );
				return $vars;
				break;

			case -1: // default, just create a permalink for this facet on its own
			default:
				return (object) array( $facet => $this->facets->$facet->queryterm_add( $term , FALSE ) );
		}
	}

	function permalink( $facet , $term , $additive = -1 )
	{
		$vars = apply_filters( 'scriblio_permalink_terms', $this->get_queryterms( $facet , $term , $additive ) );

		$count_of_facets = count( (array) $vars );

		if( ! $count_of_facets ) // oops, there are no query vars
		{
			return;
		}
		else if( 1 === $count_of_facets ) // there's just one facet (with any number of terms)
		{
			$facet = key( $vars ); // because the one remaining facet isn't always the facet that called the function
			return $this->facets->$facet->permalink( $vars->$facet );
		}
		else // more than one facet
		{
			// get the top priority facet to generate the URL base from
			$facet_priority = array_intersect_key( $this->priority , (array) $vars );
			asort( $facet_priority );
			$priority_facet = key( $facet_priority );
			$base = $this->facets->$priority_facet->permalink( $vars->$priority_facet );

			// unset the priority facet from the vars so we don't get duplicate entries
			unset( $vars->$priority_facet );

			// generate the remaining query vars
			// @TODO: we should pass this off to each taxonomy object to handle (so we can vary the glue and query vals as needed)
			$new_vars = array();
			foreach( (array) $vars as $facet => $terms )
				$new_vars[ $this->facets->$facet->query_var ] = implode( '+' , array_keys( $terms ));

			return add_query_arg( $new_vars , $base );
		}
	}


	function generate_tag_cloud( $tags , $args = '' )
	{
		global $wp_rewrite;

		$args = wp_parse_args( $args, array(
			'smallest' => 8, 
			'largest' => 22, 
			'unit' => 'pt', 
			'number' => 45,
			'name' => 'name', 
			'format' => 'flat', 
			'orderby' => 'name', 
			'order' => 'ASC', 
		));
		extract( $args );

		if ( ! $tags )
			return;

		$counts = array();
		foreach ( (array) $tags as $tag )
		{
			$counts[ $tag->facet .':'. $tag->slug ] = $tag->count;
			$tag_info[ $tag->facet .':'. $tag->slug ] = $tag;
		}

		if ( ! $counts )
			return;

		asort( $counts );
		if( $number > 0 )
			$counts = array_slice( $counts , -$number , $number , TRUE );

		$min_count = min( $counts );
		$spread = max( $counts ) - $min_count;
		if ( $spread <= 0 )
			$spread = 1;
		$font_spread = $largest - $smallest;
		if ( $font_spread <= 0 )
			$font_spread = 1;
		$font_step = $font_spread / $spread;

		// SQL cannot save you; this is a second (potentially different) sort on a subset of data.
		if( 'name' == $orderby ) // name sort
		{
			uksort( $counts, 'strnatcasecmp' );
		}
		else // sort by term count
		{
			asort( $counts );
		}

		if ( 'DESC' == $order )
			$counts = array_reverse( $counts, true );

		$a = array();
		foreach ( $counts as $tag => $count )
		{
			$a[] = '<a href="'. $this->permalink( $tag_info[ $tag ]->facet , $tag_info[ $tag ] , 1 ) .'" class="tag-link'. ( $this->facets->{$tag_info[ $tag ]->facet}->selected( $tag_info[ $tag ] ) ? ' selected' : '' ) .
				'" title="'. esc_attr( sprintf( __('%d topics') , $count )) .'"'.
				( in_array( $format , array( 'array' , 'list' )) ? '' : ' style="font-size: ' . ( $smallest + ( ( $count - $min_count ) * $font_step ) ) . $unit .';"' ) .
				'>'. wp_specialchars( $name == 'description' ? $tag_info[ $tag ]->description : $tag_info[ $tag ]->name ) .'</a>' ;
		}

		switch( $format )
		{
			case 'array' :
				$return = &$a;
				break;

			case 'list' :
				$return = "<ul class='wp-tag-cloud'>\n\t<li>". join( "</li>\n\t<li>", $a ) ."</li>\n</ul>\n";
				break;

			default :
				$return = "<ul class='wp-tag-cloud'>\n". convert_chars( wptexturize( join( "\n", $a ))) ."\n</ul>\n";
		}

		return $return;
	}

	function editsearch()
	{
		global $wpdb, $wp_query, $bsuite;

		$return_string = '';
		if( ! empty( $this->selected_facets ))
		{

			// how many facets are currently selected?
			$count_of_facets = array_sum( (array) $this->selected_facets_counts );

			// display the search terms in priority order
			$facet_priority = array_intersect_key( $this->priority , (array) $this->selected_facets );
			asort( $facet_priority );

			foreach( (array) array_keys( $facet_priority ) as $facet )
			{
				foreach( $this->selected_facets->$facet as $k => $term )
				{
					// build the query that excludes this search term
					$exclude_url = $this->permalink( $facet , $term , 0 );
					$exclude_link = '<span class="close"><span class="close-wrapper">[</span><a href="'. $exclude_url .'" title="Retry this search without this term">x</a><span class="close-wrapper">]</span></span>';

					// build a query for this search term alone
					$solo_url = $this->permalink( $facet , $term );
					$solo_link = '<a href="'. $solo_url .'" class="term" title="Search only this term">'. convert_chars( wptexturize( $term->name )) .'</a>';

					// put it all together
					$return_string .= '<li class="facet-container"><label>'. $this->facets->$facet->labels->singular_name .'</label><span class="separator">:</span><span class="facet">'. $solo_link . ( ( 1 < $count_of_facets ) ? $exclude_link : '' ) .'</span></li>';
				}
			}

			return $return_string;
		}

		return FALSE;
	}
	
	function build_labels( $sing , $plur , $singcap = '' , $plurcap = '' )
	{
		if( empty( $singcap ))
			$singcap = ucwords( $sing );
	
		if( empty( $plurcap ))
			$plurcap = ucwords( $plur );
	
		$labels = array(
			'name' => $plurcap,
			'singular_name' => $singcap,
			'search_items' => 'Search '. $plur,
			'popular_items' => 'Popular '. $plur,
			'all_items' => 'All '. $plur,
			'parent_item' => 'Parent '. $sing,
			'parent_item_colon' => 'Parent '. $sing .':',
			'edit_item' => 'Edit '. $sing,
			'update_item' => 'Update '. $sing,
			'add_new_item' => 'Add New '. $sing,
			'new_item_name' => 'New '. $sing .' Name',
			'separate_items_with_commas' => 'Separate '. $sing .' with commas',
			'add_or_remove_items' => 'Add or remove '. $sing,
			'choose_from_most_used' => 'Choose from the most used '. $sing,
		);
	
		return (object) $labels;
	}
}
$facets = new Facets;



interface Facet
{

	function register_query_var();

	function parse_query( $query_terms , $wp_query );

	function get_terms_in_corpus();

	function get_terms_in_found_set();

	function get_terms_in_post( $post_id = FALSE );

	function selected( $term );

	function queryterm_add( $term , $current );

	function queryterm_remove( $term , $current );

	function permalink( $terms );
}



function scrib_register_facet( $name , $type , $args = array() )
{
	global $facets;
	$facets->register_facet( $name , $type , $args );
}
