<?php

class Facets
{
	public $facets;
	public $_all_facets = array();
	public $_query_vars = array();
	public $_foundpostslimit = 1000;
	public $ttl = 600; // 10 minutes

	public function __construct()
	{
		// initialize scriblio facets once things have settled (init is too soon for some plugins)
		add_action( 'parse_query' , array( $this , 'parse_query' ), 1 );
		add_action( 'template_redirect' , array( $this, '_count_found_posts' ), 0 );

		add_shortcode( 'scrib_hit_count', array( $this, 'shortcode_hit_count' ) );
		add_shortcode( 'facets' , array( $this, 'shortcode_facets' ) );

		// initialize a standard object to collect facet data
		$this->facets = new stdClass;
	}

	public function is_browse()
	{
		return is_archive() || is_tax() || is_tag() || is_category();
	}

	public function register_facet( $facet_name , $facet_class , $args = array() )
	{
		$args = wp_parse_args( $args, array(
			'has_rewrite' => FALSE,
			'priority' => 5,
		));

		// if the class hasn't been loaded yet and it's an internal class, then load it
		if ( ! class_exists( $facet_class ) )
		{
			// format the filesystem path to try to load this class file from
			$class_path = __DIR__ . '/class-' . str_replace( '_', '-', sanitize_title_with_dashes( $facet_class ) ) . '.php';

			// check if this class file is an internal class, try to load it
			if ( file_exists( $class_path ) )
			{
				require_once $class_path;
			}
		}

		// instantiate the facet
		if ( class_exists( $facet_class ) )
		{
			$this->facets->$facet_name = new $facet_class( $facet_name , $args , $this );
		}
		else
		{
			return FALSE;
		}

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

	public function parse_query( $query )
	{
		// don't continue if `suppress_filters` is set
		if( isset( $query->query['suppress_filters'] ) && $query->query['suppress_filters'] )
		{
			return $query;
		}

		// remove the action so it only runs on the main query and the vars don't get reset
		remove_action( 'parse_query' , array( $this , 'parse_query' ) , 1 );

		// don't do a query to find all matching posts if this request is for a single post
		if( ! $query->is_singular )
		{
			// add the post_request filter so we can generate SQL for the facet/filter counts
			add_filter( 'posts_request', array( $this, 'posts_request' ), 11 );
		}

		// identify the selected search terms
		$searched = array_intersect_key( $query->query , $this->_query_vars );
		$this->selected_facets = (object) array();
		$this->selected_facets_counts = (object) array();
		foreach( $searched as $k => $v )
		{
			if ( is_array( $v ) )
			{
				continue;
			}// end if

			$this->selected_facets->{$this->_query_vars[ $k ]} = $this->facets->{$this->_query_vars[ $k ]}->parse_query( $v, $query );
			$this->selected_facets_counts->{$this->_query_vars[ $k ]} = count( (array) $this->selected_facets->{$this->_query_vars[ $k ]} );
		}

		if (
			isset ( scriblio()->options['noindex_intersection_pages'] ) &&
			TRUE == scriblio()->options['noindex_intersection_pages'] &&
			1 < array_sum( (array) $this->selected_facets_counts )
		)
		{
			add_action( 'wp_head', array( $this, 'wp_head_noindex' ) );
		}
//echo "<pre>";
//global $wp_rewrite;
//print_r( $wp_rewrite );
//print_r( $query );
//print_r( $this );
//print_r( $this->selected_facets );
//echo "</pre>";

		// detect if a keyword search could be converted to a facet search
		if (
			! is_admin() &&
			isset( scriblio()->options['redirect_searchword_to_taxonomy'] ) &&
			scriblio()->options['redirect_searchword_to_taxonomy'] &&
			isset( $this->facets->searchword ) &&
			isset( $this->selected_facets->searchword )
		)
		{
			// make sure this is not a wp endpoint request
			global $wp_rewrite;

			$endpoints = wp_list_pluck( $wp_rewrite->endpoints, 2 );

			// account for "feed" which isn't in $wp_rewrite->endpoints but
			// is still an endpoint we might see
			$endpoints[] = 'feed';

			$requested_endpoints = array_intersect( array_keys( $query->query ), $endpoints );

			if (
				empty( $requested_endpoints ) &&
				$this->facets->searchword->to_taxonomy()
			)
			{
				// we were able to convert a keyword search to a facet/taxonomy
				// search. redirect accordingly.
				wp_redirect( $this->permalink(), 301 );
				die;
			}//END if
		}//END if

		return $query;
	}//END parse_query

	/**
	 * reset filters and actions used by scriblio. this allows the user to
	 * control when the filters/actions should be run again after specific
	 * WP_Query calls.
	 */
	public function reset()
	{
		// the parse_query removes itself after being called,
		// so add it again when we reset to apply to the next WP_Query().
		add_action( 'parse_query' , array( $this , 'parse_query' ) , 1 );

		// clear out any previous matches
		$this->_matching_tax_facets = array();
		unset( $this->matching_post_ids );
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

		$this->matching_post_ids_sql = str_replace(
			$wpdb->posts .'.* ', $wpdb->posts .'.ID ',
			str_replace(
				'SQL_CALC_FOUND_ROWS', '',
				preg_replace(
					'/LIMIT[^0-9]*([0-9]*)[^0-9]*([0-9]*)/i',
					'LIMIT \1, '. $this->_foundpostslimit,
					$query
				)
			)
		) . ' /* generated in Facets::posts_request() */';

//echo "<h2>$this->matching_post_ids_sql</h2>";

		return $query;
	}

	/**
	 * output meta tags
	 */
	public function wp_head_noindex()
	{
		echo '<meta name="robots" content="noindex, follow">';
	} // end wp_head_noindex

	public function get_matching_post_ids()
	{
		if( isset( $this->matching_post_ids ) )
		{
			return $this->matching_post_ids;
		}

		// short circuit if this is a single post
		if( is_singular() )
		{
			$this->matching_post_ids = (array) get_queried_object_id();
			return $this->matching_post_ids;
		}

		scriblio()->timer( 'get_matching_post_ids' );

		$cache_key = md5( $this->matching_post_ids_sql );
		$timer_notes = 'from cache';
		if( ! $this->matching_post_ids = wp_cache_get( $cache_key , 'scrib-matching-post-ids' ))
		{
			$timer_notes = 'from filter';

			// apply a filter to allow other plugins to generate the list of
			// matching post IDs. the filter _must_ return an empty array()
			// if there are no results, otherwise execution will continue
			// against MySQL below
			$this->matching_post_ids = apply_filters( 'scriblio_pre_get_matching_post_ids', FALSE, $this->_foundpostslimit );

			// if no filters are hooked above, the result will be FALSE and
			// we'll look in MySQL to find the matching post IDs
			if ( FALSE === $this->matching_post_ids)
			{
				$timer_notes = 'from query';

				global $wpdb;
				$this->matching_post_ids = $wpdb->get_col( $this->matching_post_ids_sql );
			}
			wp_cache_set( $cache_key , $this->matching_post_ids , 'scrib-matching-post-ids' , $this->ttl );
		}

		scriblio()->timer( 'get_matching_post_ids', $timer_notes );

		return $this->matching_post_ids;
	}

	public function _count_found_posts()
	{
		global $wp_query;
		$this->count_found_posts = absint( $wp_query->found_posts );
	}

	public function shortcode_hit_count( $arg )
	{
		// [scrib_hit_count ]

		return( number_format( $this->count_found_posts, 0, _x('.', 'decimal separator'), _x(',', 'thousands separator') ) );
	}

	public function shortcode_facets( $arg )
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

		if ( ! is_object( $this->facets->{$arg['facet']} ) )
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


	public function get_queryterms( $facet , $term , $additive = -1 )
	{
		switch( (int) $additive )
		{
			case 1: // TRUE add this facet to the other facets in the previous query
				$vars = clone $this->selected_facets;
				$vars->{$facet} = $this->facets->$facet->queryterm_add( $term, isset( $vars->{$facet} ) ? $vars->{$facet} : '' );
				return $vars;
				break;

			case 0: // FALSE remove this term from the current query vars
				$vars = clone $this->selected_facets;
				$vars->$facet = $this->facets->$facet->queryterm_remove( $term, isset( $vars->{$facet} ) ? $vars->{$facet} : '' );
				if( ! count( (array) $vars->$facet ))
					unset( $vars->$facet );
				return $vars;
				break;

			case -1: // default, just create a permalink for this facet on its own
			default:
				return (object) array( $facet => $this->facets->$facet->queryterm_add( $term , FALSE ) );
		}
	}

	public function permalink( $facet = NULL, $term = NULL, $additive = -1 )
	{

		if ( isset( $facet, $term ) )
		{
			$vars = $this->get_queryterms( $facet , $term , $additive );
		}
		else
		{
			$vars = clone $this->selected_facets;
		}
		$vars = apply_filters( 'scriblio_permalink_terms', $vars );

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
			{

				if ( ! is_array( $terms ) || ! count( $terms ) )
				{
					continue;
				}

				$new_vars[ $this->facets->$facet->query_var ] = implode( '+' , array_keys( $terms ));
			}

			return add_query_arg( $new_vars , $base );
		}
	}


	public function generate_tag_cloud( $tags, $args = '' )
	{
		scriblio()->timer( 'generate_tag_cloud' );

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
		) );

		if ( ! $tags )
			return;

		$counts = array();
		foreach ( (array) $tags as $tag_key => $tag_val )
		{
			$counts[ $tag_val->slug . ':' . $tag_val->facet ] = $tag_val->count;
			$tag_info[ $tag_val->slug . ':' . $tag_val->facet ] = $tag_val;
			// preserve the original ordering in case orderby is 'none'
			// $tag_key is just the numeric array index of $tag_val
			$orig_order[ $tag_val->slug . ':' . $tag_val->facet ] = $tag_key;
		}//END foreach

		if ( ! $counts )
			return;

		asort( $counts );

		if ( $args['number'] > 0 )
		{
			$counts = array_slice( $counts, -$args['number'], $args['number'], TRUE );
		}//end if

		$min_count = min( $counts );
		$spread = max( $counts ) - $min_count;
		if ( $spread <= 0 )
			$spread = 1;
		$font_spread = $args['largest'] - $args['smallest'];
		if ( $font_spread <= 0 )
			$font_spread = 1;
		$font_step = $font_spread / $spread;

		// SQL cannot save you; this is a second (potentially different) sort on a subset of data.
		if ( 'name' == $args['orderby'] ) // name sort
		{
			uksort( $counts, 'strnatcasecmp' );
		}
		elseif ( 'none' == $args['orderby'] ) // restore back to the same order as $tags
		{
			// copy values of $counts to $orig_order
			$orig_order = array_replace( $orig_order, $counts );
			// and then filter out any key not in $counts
			$counts = array_intersect_key( $orig_order, $counts );
		}
		else // sort by term count
		{
			asort( $counts );
		}

		if ( 'DESC' == $args['order'] )
			$counts = array_reverse( $counts, true );

		$a = array();
		foreach ( $counts as $tag => $count )
		{
			$is_selected = $this->facets->{ $tag_info[ $tag ]->facet }->selected( $tag_info[ $tag ] );

			$term_name = apply_filters(
				'scriblio_facets_facet_description',
				trim( $args['name'] == 'description' ? $tag_info[ $tag ]->description : $tag_info[ $tag ]->name ),
				$tag_info[ $tag ]->facet
			);

			$before_link = apply_filters( 'scriblio_facets_tag_cloud_pre_link', '', $tag_info[ $tag ]->facet, $count, $this->count_found_posts );

			if ( ! is_string( $before_link ) )
			{
				$before_link = '';
				trigger_error( __FILE__ . ':' . __LINE__ .' filter expected a string, but got something else '. var_export( $before_link, TRUE ) .' referrer:' . $_SERVER['HTTP_REFERER'], E_USER_NOTICE );
			}

			$a[] = sprintf(
				'<%1$s class="%2$s" data-term="%3$s" data-taxonomy="%4$s" data-term-url="%5$s">%11$s<a href="%6$s" class="term-link" title="%7$s"%8$s>%9$s%10$s</a></%1$s>',
				( 'list' == $args['format'] ? 'li' : 'span' ),
				( $is_selected ? 'selected' : '' ),
				esc_attr( $term_name ),
				esc_attr( $this->facets->{ $tag_info[ $tag ]->facet }->label ),
				$this->permalink( $tag_info[ $tag ]->facet, $tag_info[ $tag ], -1 ),
				$this->permalink( $tag_info[ $tag ]->facet, $tag_info[ $tag ], (int) ! $is_selected ),
				esc_attr( sprintf( __( '%d topics' ), $count ) ),
				( 'list' == $args['format'] ? '' : 'style="font-size: ' . ( $args['smallest'] + ( ( $count - $min_count ) * $font_step ) ) . $args['unit'] .';"' ),
				esc_html( $term_name ),
				( 'list' == $args['format'] ? '<span class="count"><span class="meta-sep">&nbsp;</span>' . number_format( $count ) . '</span>' : '' ),
				$before_link
			);
		}//end foreach

		switch ( $args['format'] )
		{
			case 'array' :
				$return = &$a;
				break;

			case 'list' :
				$return = "<ul class='wp-tag-cloud'>\n\t". convert_chars( wptexturize( join( "\n\t", $a ) ) ) ."\n</ul>\n";
				break;

			case 'flat' :
			case 'cloud' :
			default :
				$return = "<div class='wp-tag-cloud'>\n". convert_chars( wptexturize( join( "\n", $a ) ) ) ."\n</div>\n";
		}//end switch

		scriblio()->timer( 'generate_tag_cloud', $args );

		return $return;
	}//end generate_tag_cloud

	public function editsearch()
	{
		global $wpdb, $wp_query, $bsuite;

		$return_string = '';
		if( ! empty( $this->selected_facets ) )
		{

			// how many facets are currently selected?
			$count_of_facets = array_sum( (array) $this->selected_facets_counts );

			// display the search terms in priority order
			$facet_priority = array_intersect_key( $this->priority , (array) $this->selected_facets );
			asort( $facet_priority );

			$current_taxonomy = null;

			foreach( (array) array_keys( $facet_priority ) as $facet )
			{

				if ( ! isset( $this->selected_facets->$facet ) )
				{
					continue;
				}

				foreach( $this->selected_facets->$facet as $k => $term )
				{
					$facet_classes = array();

					if ( $current_taxonomy != $this->facets->$facet->labels->singular_name )
					{
						$current_taxonomy = $this->facets->$facet->labels->singular_name;

						$facet_classes[] = 'first';
						$return_string .= '<li class="facet-taxonomy">' . $current_taxonomy . '</li>';
					}//end if

					// build the query that excludes this search term
					if ( $count_of_facets > 1 )
					{
						$exclude_url = $this->permalink( $facet , $term , 0 );
					}//end if
					else
					{
						$exclude_url = home_url();
					}//end else

					$exclude_link = '<span class="close"><span class="close-wrapper">[</span><a href="'. $exclude_url .'" title="Retry this search without this term">x</a><span class="close-wrapper">]</span></span>';

					// build a query for this search term alone
					$solo_url = $this->permalink( $facet , $term );
					$solo_link = '<a href="'. $solo_url .'" class="term" title="Search only this term">'. esc_html( convert_chars( wptexturize( apply_filters( 'scriblio_facets_facet_description', $term->name, $facet ) ) ) ) .'</a>';

					// put it all together
					$return_string .= '<li class="facet-container ' . implode( ' ', $facet_classes ) . '"><label>'. $this->facets->$facet->labels->singular_name .'</label><span class="separator">:</span><span class="facet">'. $solo_link . $exclude_link .'</span></li>';
				}
			}

			return $return_string;
		}

		return FALSE;
	}

	public function build_labels( $sing , $plur , $singcap = '' , $plurcap = '' )
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
}//END interface