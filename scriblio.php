<?php
/*
Plugin Name: Scriblio Search
Plugin URI: http://about.scriblio.net/
Version: 3 alpha 1
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
*/


// include required components
require_once( __DIR__ .'/plugin/class-facets.php');
require_once( __DIR__ .'/plugin/class-facet-searchword.php');
require_once( __DIR__ .'/plugin/class-facet-taxonomy.php');
require_once( __DIR__ .'/plugin/widgets.php');
require_once( __DIR__ .'/plugin/class-scrib-suggest.php');

// register default facets
function scrib_register_default_facets()
{

	// register keyword search facet
	scrib_register_facet( 'searchword' , 'Facet_Searchword' , array( 'priority' => 0 , 'has_rewrite' => TRUE ) );

	// register public taxonomies as facets
	foreach( (array) get_taxonomies( array( 'public' => true )) as $taxonomy )
	{
		$taxonomy = get_taxonomy( $taxonomy );

		scrib_register_facet( 
			( empty( $taxonomy->label ) ? $taxonomy->name : sanitize_title_with_dashes( $taxonomy->label )),
			'Facet_Taxonomy' ,
			array( 
				'taxonomy' => $taxonomy->name ,
				'query_var' => $taxonomy->query_var ,
				'has_rewrite' => ( is_array( $taxonomy->rewrite ) ? TRUE : FALSE ),
				'priority' => 5,
			)
		);
	}

	// register facets from the posts table
//	scrib_register_facet( 'post_author' , 'Facet_Post' );
}
add_action( 'scrib_register_facets' , 'scrib_register_default_facets' );
