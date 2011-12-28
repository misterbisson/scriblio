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
		add_action( 'parse_query' , array( $this , 'parse_query' ));
		add_filter( 'posts_request',	array( $this, 'posts_request' ), 11 );
	}

	function init()
	{
		do_action( 'scrib_register_facets' );
	}

	function register_facet( $facet_name , $facet_type , $args = array() )
	{
		$defaults = array(
			'query_var' => $facet_name,
			'labels' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		// instantiate the facet
		$facet_type = 'Facet_'. $facet_type;
		$this->$facet_name = new $facet_type( $facet_name , $args , $this );

		// register the query var and associate it with this facet
		$query_var = $this->$facet_name->register_query_var();
		$this->_query_vars[ $query_var ] = $facet_name;
	}

	function parse_query( $query )
	{
		$searched = array_intersect_key( $query->query , $this->_query_vars );
		foreach( $searched as $k => $v )
			$this->{$this->_query_vars[ $k ]}->parse_query_var( $v , $query );

echo "<pre>";
//print_r( $query );
//print_r( $this );
echo "</pre>";
	}

	public function posts_request( $query )
	{
//echo "<h2>$query</h2>";

		global $wpdb;

		// deregister this filter after it's run on the first/default query
		remove_filter( 'posts_request', array( $this , 'posts_request' ), 11 );

		$this->matching_post_ids_sql = str_replace( $wpdb->posts .'.* ', $wpdb->posts .'.ID ', str_replace( 'SQL_CALC_FOUND_ROWS', '', preg_replace( '/LIMIT[^0-9]*([0-9]*)[^0-9]*([0-9]*)/i', 'LIMIT \1, '. $this->_foundpostslimit , $query )));

//echo "<h2>$this->matching_post_ids_sql</h2>";

print_r( $this->tag->get_matching_facets( TRUE ));

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

	function facet_permalink( $term , $additive = -1 )
	{
//print_r( $term );
		switch( (int) $additive )
		{
			case 1: // TRUE add this facet to the other facets in the previous query
				break;
			case 0: // FALSE remove this facet from the other facets in the previous query
				break;
			case -1: // default, just create a permalink for this facet on its own
			default:
				return $term->name;
		}
	}

}
$facets = new Facets;



class Facet
{
	function __construct( $name , $args , $facets_object )
	{
		$this->name = $name;
		$this->args = $args;
		$this->facets = $facets_object;

		$this->label = $args['label'];
		$this->labels = $args['labels'];
		$this->query_var = $args['query_var'];
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

	function parse_query_var( $query_val , $query_object )
	{
		$this->query_vals = array_filter( array_map( 'trim' , (array) explode( ',' , $query_val )));
	}
}



class Facet_taxonomy extends Facet
{
	function __construct( $name , $args , $facets_object )
	{
		parent::__construct( $name , $args , $facets_object );

		$this->taxonomy = $args['taxonomy'] ? $args['taxonomy'] : $this->name;

		$this->facets->_tax_to_facet[ $this->taxonomy ] = $this->name;
		$this->facets->_facet_to_tax[ $this->name ] = $this->taxonomy;

		$taxonomy = get_taxonomy( $this->taxonomy );
		$this->label = $taxonomy->label;
		$this->labels = $taxonomy->labels;
		if( $taxonomy->query_var )
			$this->query_var = $taxonomy->query_var;

	}

	function parse_query_var( $query_val , $query_object )
	{
		parent::parse_query_var( $query_val , $query_object );

		foreach( $this->query_vals as $val )
			$this->terms[ $val ] = get_term_by( 'slug' , $val , $this->taxonomy );
	}

	function get_matching_facets()
	{
		if( is_array( $this->facets->_matching_tax_facets ))
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
				'description' => $term->description,
				'term_id' => $term->term_id,
				'term_taxonomy_id' => $term->term_taxonomy_id,
				'count' => $term->count,
			);
		}

		return $this->facets->_matching_tax_facets[ $this->name ];
	}

}



class Facet_post extends Facet
{
	function __construct( $name , $args , $facets_object )
	{
		parent::__construct( $name , $args , $facets_object );

		$this->post_field = $args['post_field'] ? $args['post_field'] : $this->name;
	}
}



function scrib_register_facet( $name , $type , $args = array() )
{
	global $facets;
	$facets->register_facet( $name , $type , $args );
}



function register_facet_test()
{
	scrib_register_facet( 'tag' , 'taxonomy' , array( 'taxonomy' => 'post_tag' , 'query_var' => 'tag' ) );
	scrib_register_facet( 'category' , 'taxonomy' , array( 'query_var' => 'category_name' ) );
	scrib_register_facet( 'post_author' , 'post' );

//echo "<h2>Hey!</h2>";
//global $facets;
//print_r( $facets );
}
add_action( 'init' , 'register_facet_test' );
//add_action( 'scrib_register_facets' , 'register_facet_test' );


