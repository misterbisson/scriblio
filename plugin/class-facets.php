<?php

class Facets
{
	var $_all_facets = array();
	var $_query_vars = array();
	var $_foundpostslimit = 1000;

	function __construct()
	{
		add_action( 'init' , array( $this , 'init' ));
		add_action( 'parse_query' , array( $this , 'parse_query' ) , 1 );
		add_filter( 'posts_request',	array( $this, 'posts_request' ), 11 );
	}

	function init()
	{
		do_action( 'scrib_register_facets' );
	}

	function is_browse()
	{
		return is_tax() || is_tag() || is_category();
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
		$searched = array_intersect_key( $query->query , $this->_query_vars );
		$this->selected_facets = (object) array();
		foreach( $searched as $k => $v )
		{
			$this->selected_facets->{$this->_query_vars[ $k ]} = $this->facets->{$this->_query_vars[ $k ]}->parse_query( $v , $query );
			$this->selected_facets_counts->{$this->_query_vars[ $k ]} = count( (array) $this->selected_facets->{$this->_query_vars[ $k ]} );
		}

//echo "<pre>";
//print_r( $query );
//print_r( $this );
//print_r( $this->selected_facets );
//echo "</pre>";

		return $query;
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

		global $wpdb;

		$this->matching_post_ids = $wpdb->get_col( $this->matching_post_ids_sql );
		return $this->matching_post_ids;
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
		$vars = $this->get_queryterms( $facet , $term , $additive );

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

			// generate the additional query vars
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
				'" title="'. attribute_escape( sprintf( __('%d topics') , $count )) .'"'.
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
				$return = "<ul class='wp-tag-cloud'>\n". join( "\n", $a ) ."\n</ul>\n";
		}

		return $return;
	}

	function editsearch()
	{
		global $wpdb, $wp_query, $bsuite;
		$search_terms = $this->search_terms;

		$return_string = '';
		if( ! empty( $this->selected_facets ))
		{

			// how many facets are currently selected?
			$count_of_facets = array_product( (array) $this->selected_facets_counts );

			// display the search terms in priority order
			$facet_priority = array_intersect_key( $this->priority , (array) $this->selected_facets );
			asort( $facet_priority );

			foreach( (array) array_keys( $facet_priority ) as $facet )
			{
				foreach( $this->selected_facets->$facet as $k => $term )
				{
					// build the query that excludes this search term
					$exclude_url = $this->permalink( $facet , $term , 0 );
					$exclude_link = '[<a href="'. $exclude_url .'" title="Retry this search without this term">x</a>]';

					// build a query for this search term alone
					$solo_url = $this->permalink( $facet , $term );
					$solo_link = '<a href="'. $solo_url .'" title="Search only this term">'. convert_chars( wptexturize( $term->name )) .'</a>';

					// put it all together
					$return_string .= '<li><label>'. $this->facets->$facet->labels->singular_name .'</label>: '. $solo_link . ( ( 1 < $count_of_facets ) ? '&nbsp;'. $exclude_link : '' ) .'</li>';
				}
			}

			return $return_string;
		}

		return FALSE;
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