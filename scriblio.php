<?php
/*
Plugin Name: Scriblio Search
Plugin URI: http://about.scriblio.net/
Version: 3 alpha 0
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/

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
		$this->priority[ $facet_name ] = (int) $args['priority'];
		
	}

	function parse_query( $query )
	{
		$searched = array_intersect_key( $query->query , $this->_query_vars );
		$this->selected_facets = (object) array();
		foreach( $searched as $k => $v )
			$this->selected_facets->{$this->_query_vars[ $k ]} = $this->facets->{$this->_query_vars[ $k ]}->parse_query( $v , $query );

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
			$count_of_facets = count( (array) $this->selected_facets );

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



class Facet_Taxonomy implements Facet
{
	function __construct( $name , $args , $facets_object )
	{
		$this->name = $name;
		$this->args = $args;
		$this->facets = $facets_object;

		$this->taxonomy = $args['taxonomy'] ? $args['taxonomy'] : $this->name;

		$this->facets->_tax_to_facet[ $this->taxonomy ] = $this->name;
		$this->facets->_facet_to_tax[ $this->name ] = $this->taxonomy;

		$taxonomy = get_taxonomy( $this->taxonomy );
		$this->label = $taxonomy->label;
		$this->labels = $taxonomy->labels;
		if( $taxonomy->query_var )
			$this->query_var = $taxonomy->query_var;

	}

	function register_query_var()
	{
		global $wp;

		if ( TRUE === $this->query_var )
			$this->query_var = $this->name;

		// @ TODO: check to see if the query var is registered before adding it again
		$this->query_var = sanitize_title_with_dashes( $this->query_var );
		$wp->add_query_var( $this->query_var );

		return $this->query_var;
	}

	function parse_query( $query_terms , $wp_query )
	{

		// identify the terms in this query
		foreach( array_filter( array_map( 'trim' , (array) preg_split( '/[,\+\|]/' , $query_terms ))) as $val )
		{
			if( $term = get_term_by( 'slug' , $val , $this->taxonomy ))
				$this->selected_terms[ $term->slug ] = $term;
		}

		return $this->selected_terms;
	}

	function get_terms_in_corpus()
	{
		if( isset( $this->terms_in_corpus ))
			return $this->terms_in_corpus;

		$terms = get_terms( $this->taxonomy , array( 'number' => 1000 , 'orderby' => 'count' , 'order' => 'DESC' ));

		$this->terms_in_corpus = array();
		foreach( $terms as $term )
		{
			$this->terms_in_corpus[] = (object) array(
				'facet' => $this->facets->_tax_to_facet[ $term->taxonomy ],
				'slug' => $term->slug,
				'name' => $term->name,
				'description' => $term->description,
				'term_id' => $term->term_id,
				'term_taxonomy_id' => $term->term_taxonomy_id,
				'count' => $term->count,
			);
		}

		return $this->terms_in_corpus;
	}

	function get_terms_in_found_set()
	{
		if( is_array( $this->facets->_matching_tax_facets[ $this->name ] ))
			return $this->facets->_matching_tax_facets[ $this->name ];

		global $wpdb;

		$matching_post_ids = $this->facets->get_matching_post_ids();

		$facets_query = "SELECT b.term_id, c.term_taxonomy_id, b.slug, b.name, a.taxonomy, a.description, COUNT(c.term_taxonomy_id) AS `count`
			FROM $wpdb->term_relationships c
			INNER JOIN $wpdb->term_taxonomy a ON a.term_taxonomy_id = c.term_taxonomy_id
			INNER JOIN $wpdb->terms b ON a.term_id = b.term_id
			WHERE c.object_id IN (". implode( ',' , $matching_post_ids ) .")
			GROUP BY c.term_taxonomy_id ORDER BY count DESC LIMIT 2000";

		$terms = $wpdb->get_results( $facets_query );
		$this->facets->_matching_tax_facets = array();
		foreach( $terms as $term )
		{

			$this->facets->_matching_tax_facets[ $this->facets->_tax_to_facet[ $term->taxonomy ]][] = (object) array(
				'facet' => $this->facets->_tax_to_facet[ $term->taxonomy ],
				'slug' => $term->slug,
				'name' => $term->name,
				'count' => $term->count,
				'description' => $term->description,
				'term_id' => $term->term_id,
				'term_taxonomy_id' => $term->term_taxonomy_id,
			);
		}

		return $this->facets->_matching_tax_facets[ $this->name ];
	}

	function get_terms_in_post( $post_id = FALSE )
	{
		if( ! $post_id )
			$post_id = get_the_ID();

		if( ! $post_id )
			return FALSE;

		$terms = wp_get_object_terms( $post_id , $this->taxonomy );
		$terms_in_post = array();
		foreach( $terms as $term )
		{
			$terms_in_post[] = (object) array(
				'facet' => $this->facets->_tax_to_facet[ $term->taxonomy ],
				'slug' => $term->slug,
				'name' => $term->name,
				'description' => $term->description,
				'term_id' => $term->term_id,
				'term_taxonomy_id' => $term->term_taxonomy_id,
				'count' => $term->count,
			);
		}

		return $terms_in_post;
	}

	function selected( $term )
	{
		return( isset( $this->selected_terms[ ( is_object( $term ) ? $term->slug : $term ) ] ));
	}

	function queryterm_add( $term , $current )
	{
		$current[ $term->slug ] = $term;
		return $current;
	}

	function queryterm_remove( $term , $current )
	{
		unset( $current[ $term->slug ] );
		return $current;
	}

	function permalink( $terms )
	{
		if( 1 === count( $terms ))
		{
			return get_term_link( (int) current( $terms )->term_id , $this->taxonomy );
		}
		else
		{
			// much of this section comes from get_term_link() in /wp-includes/taxonomy.php, 
			// but that code can't handle multiple terms in a single taxonomy

			global $wp_rewrite;
			$termlink = $wp_rewrite->get_extra_permastruct( $this->taxonomy );

			if ( empty($termlink) ) // dang, we're not using pretty permalinks
			{
				$t = get_taxonomy( $this->taxonomy );
				$termlink = "?$t->query_var=". implode( '+' , array_keys( $terms ));
			}
			else
			{
				$termlink = str_replace( "%$this->taxonomy%" , implode( '+' , array_keys( $terms )) , $termlink );
			}

			$termlink = home_url( user_trailingslashit( $termlink , 'category' ));

			return $termlink;
		}
	}
}



class Facet_Searchword implements Facet
{

	var $query_var = 's';

	function __construct( $name , $args , $facets_object )
	{
		$this->name = $name;
		$this->args = $args;
		$this->facets = $facets_object;

		$this->label = __( 'Search Keyword' );
		$this->labels = (object) array(
			'name' => 'Search Keyword',
			'singular_name' => 'Search Keyword',
			'search_items' => 'Search Keywords',
			'popular_items' => 'Popular Keywords',
			'all_items' => 'All Keywords',
			'parent_item' => '',
			'parent_item_colon' => '',
			'edit_item' => 'Edit Keyword',
			'view_item' => 'View Keyword',
			'update_item' => 'Update Keyword',
			'add_new_item' => 'Add New Keyword',
			'new_item_name' => 'New Keyword Name',
			'separate_items_with_commas' => 'Separate keyword with commas',
			'add_or_remove_items' => 'Add or remove keyword',
			'choose_from_most_used' => 'Choose from the most used keyword',
			'menu_name' => 'Keywords',
			'name_admin_bar' => 's',
		);
	}

	function register_query_var()
	{
		return $this->query_var;
	}

	function parse_query( $query_terms , $wp_query )
	{
		$term = trim( urldecode( $query_terms ));
		$this->selected_terms[ $term ] = (object) array(
			'facet' => $this->name,
			'slug' => urlencode( $term ),
			'name' => $term,
		);

		return $this->selected_terms;
	}

	function get_terms_in_corpus()
	{
		return array();
	}

	function get_terms_in_found_set()
	{
		return array();
	}

	function get_terms_in_post( $post_id = FALSE )
	{
		return array();
	}

	function selected( $term )
	{
		return( isset( $this->selected_terms[ $term->name ] ));
	}

	function queryterm_add( $term , $current )
	{
		$current[ $term->name ] = $term;
		return $current;
	}

	function queryterm_remove( $term , $current )
	{
		unset( $current[ $term->name ] );
		return $current;
	}

	function permalink( $terms )
	{
		if( is_array( $terms ))
			$terms = implode( ' ' , array_keys( $terms ));

		return get_search_link( $terms );
	}
}

/*
class Facet_Post implements Facet
{
	function __construct( $name , $args , $facets_object )
	{
		parent::__construct( $name , $args , $facets_object );

		$this->post_field = $args['post_field'] ? $args['post_field'] : $this->name;
	}

	function register_query_var()
	{
		global $wp;

		if ( TRUE === $this->query_var )
			$this->query_var = $this->name;

		// @ TODO: check to see if the query var is registered before adding it again
		$this->query_var = sanitize_title_with_dashes( $this->query_var );
		$wp->add_query_var( $this->query_var );

		return $this->query_var;
	}

}
*/

class Scrib_Facets_Widget extends WP_Widget
{

	function Scrib_Facets_Widget()
	{
		$this->WP_Widget( 'scriblio_facets', 'Scriblio Facets', array( 'description' => 'Displays facets related to the displayed set of posts' ));
	}

	function widget( $args, $instance )
	{
		global $facets;
		extract( $args );

		$title = apply_filters( 'widget_title' , empty( $instance['title'] ) ? '' : $instance['title'] );
		$orderby = ( in_array( $instance['orderby'], array( 'count', 'name', 'custom' )) ? $instance['orderby'] : 'name' );
		$order = ( in_array( $instance['order'], array( 'ASC', 'DESC' )) ? $instance['order'] : 'ASC' );

		// configure how it's displayed
		$display_options = array(
			'smallest' => floatval( $instance['format_font_small'] ), 
			'largest' => floatval( $instance['format_font_large'] ),
			'unit' => 'em',
			'orderby' => $orderby,
			'order' => $order,
			'order_custom' => $instance['order_custom'],
		);

		// list and cloud specific display options
		if( 'list' == $instance['format'] )
		{
			$display_options['format'] = 'list';
		}
		else
		{
			$display_options['format'] = 'flat';
		}

		// select what's displayed
		if( 'corpus' == $instance['format_font_large'] )
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_corpus();
		}
		else if( is_singular() )
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_post( get_the_ID() );
		}
		else if( is_search() || $facets->is_browse() )
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_found_set();
			if( empty( $facet_list ))
				$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_corpus();
		}
		else
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_corpus();
		}

		echo $before_widget;
		if( ! empty( $title ))
		{
			echo $before_title . $title . $after_title;
		}
		echo convert_chars( wptexturize( $facets->generate_tag_cloud( $facet_list , $display_options )));
		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{
		global $facets;

		$instance = $old_instance;
		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['facet'] = in_array( $new_instance['facet'] , array_keys( (array) $facets->facets )) ? $new_instance['facet'] : FALSE;
		$instance['format'] = in_array( $new_instance['format'], array( 'list', 'cloud' )) ? $new_instance['format']: '';
		$instance['format_font_small'] = floatval( '1' );
		$instance['format_font_large'] = floatval( '2.25' );
		$instance['count'] = absint( $new_instance['count'] );
		$instance['orderby'] = in_array( $new_instance['orderby'], array( 'count', 'name', 'custom' )) ? $new_instance['orderby']: '';
		$instance['order'] = ( 'count' == $instance['orderby'] ? 'DESC' : 'ASC' );

		return $instance;
	}

	function form( $instance )
	{
		//Defaults
		$instance = wp_parse_args( (array) $instance, 
			array( 
				'title' => '', 
				'facet' => FALSE,
				'format' => 'cloud',
				'count' => 25,
				'orderby' => 'name',
			)
		);

		$title = esc_attr( $instance['title'] );
?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('facet'); ?>"><?php _e( 'Facet:' ); ?></label>
			<select name="<?php echo $this->get_field_name('facet'); ?>" id="<?php echo $this->get_field_id('facet'); ?>" class="widefat">
				<?php $this->control_facets( $instance['facet'] ); ?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('format'); ?>"><?php _e( 'Format:' ); ?></label>
			<select name="<?php echo $this->get_field_name('format'); ?>" id="<?php echo $this->get_field_id('format'); ?>" class="widefat">
				<option value="list" <?php selected( $instance['format'], 'list' ); ?>><?php _e('List'); ?></option>
				<option value="cloud" <?php selected( $instance['format'], 'cloud' ); ?>><?php _e('Cloud'); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Number of terms to show:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo absint( $instance['count'] ); ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('orderby'); ?>"><?php _e( 'Order By:' ); ?></label>
			<select name="<?php echo $this->get_field_name('orderby'); ?>" id="<?php echo $this->get_field_id('orderby'); ?>" class="widefat">
				<option value="count" <?php selected( $instance['orderby'], 'count' ); ?>><?php _e('Count'); ?></option>
				<option value="name" <?php selected( $instance['orderby'], 'name' ); ?>><?php _e('Name'); ?></option>
				<!-- <option value="custom" <?php selected( $instance['orderby'], 'custom' ); ?>><?php _e('Custom (see below)'); ?></option> -->
			</select>
		</p>

<?php
	}

	function control_facets( $default = '' )
	{
		global $facets;

		$facet_list = array_keys( (array) $facets->facets );

		// Sort templates by name  
		$names = array();
		foreach( $facet_list as $info )
			$names[] = $info['name']; 
		array_multisort( $facet_list , $names );

		foreach ( $facet_list as $facet )
			echo "\n\t<option value=\"". $facet .'" '. selected( $default , $facet , FALSE ) .'>'. ( isset( $facets->facets->{$facet}->label ) ? $facets->facets->{$facet}->label : $facet ) .'</option>';
	}

}// end Scrib_Facets_Widget

class Scrib_Searcheditor_Widget extends WP_Widget {

	function Scrib_Searcheditor_Widget()
	{
		$this->WP_Widget( 'scrib_searcheditor', 'Scriblio Search Editor', array( 'description' => 'Edit search and browse criteria' ));
	}

	function widget( $args, $instance )
	{
		extract( $args );

		global $wp_query, $facets;

		if( ! ( is_search() || $facets->is_browse() ))
			return;

		$subsmatch = array(
			'[scrib_hit_count]',
			'[scrib_search_suggestions]',
		);

		$subsreplace = array(
			'',
			'',
		);

		$title = $instance['title'];
		$context_top = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['context-top'] ));
		$context_bottom = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['context-bottom'] ));

		echo $before_widget;

		if ( ! empty( $title ) )
			echo $before_title . $title . $after_title;
		if ( ! empty( $context_top ) )
			echo '<div class="textwidget scrib_search_edit">' . $context_top . '</div>';
		echo '<ul>'. $facets->editsearch() .'</ul>';
		if ( ! empty( $context_bottom ) )
			echo '<div class="textwidget scrib_search_edit">' . $context_bottom . '</div>';

		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{
		$instance = $old_instance;

		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['context-top'] = wp_filter_post_kses( $new_instance['context-top'] );
		$instance['context-bottom'] = wp_filter_post_kses( $new_instance['context-bottom'] );

		return $instance;
	}

	function form( $instance )
	{

		//Defaults
		$instance = wp_parse_args( (array) $instance, 
			array( 
				'title' => 'Searching Our Collection',
				'context-top' => 'Your search found [scrib_hit_count] items with all of the following terms:',
				'context-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search.',
			)
		);
?>

		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('context-top'); ?>"><?php _e('Text above:'); ?></label>
			<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('context-top'); ?>" name="<?php echo $this->get_field_name('context-top'); ?>"><?php echo format_to_edit( $instance['context-top'] ); ?></textarea>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('context-bottom'); ?>"><?php _e('Text below:'); ?></label>
			<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('context-bottom'); ?>" name="<?php echo $this->get_field_name('context-bottom'); ?>"><?php echo format_to_edit( $instance['context-bottom'] ); ?></textarea>
		</p>

<?php

	}
}// end Scrib_Searcheditor_Widget



function scrib_widgets_init()
{
	register_widget( 'Scrib_Facets_Widget' );
	register_widget( 'Scrib_Searcheditor_Widget' );
}
add_action( 'widgets_init' , 'scrib_widgets_init' , 1 );



function scrib_register_facet( $name , $type , $args = array() )
{
	global $facets;
	$facets->register_facet( $name , $type , $args );
}



function register_facet_test()
{
	scrib_register_facet( 'searchword' , 'Facet_Searchword' , array( 'priority' => 0 ) );
	scrib_register_facet( 'tag' , 'Facet_Taxonomy' , array( 'taxonomy' => 'post_tag' , 'query_var' => 'tag' , 'priority' => 5 ) );
	scrib_register_facet( 'category' , 'Facet_Taxonomy' , array( 'query_var' => 'category_name' , 'priority' => 4 ) );
//	scrib_register_facet( 'post_author' , 'Facet_Post' );

//echo "<h2>Hey!</h2>";
//global $facets;
//print_r( $facets );
}
add_action( 'init' , 'register_facet_test' );
//add_action( 'scrib_register_facets' , 'register_facet_test' );


