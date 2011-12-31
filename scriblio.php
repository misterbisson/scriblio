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

// register stuff
function register_facet_test()
{
	scrib_register_facet( 'searchword' , 'Facet_Searchword' , array( 'priority' => 0 , 'has_rewrite' => TRUE ) );
	scrib_register_facet( 'tag' , 'Facet_Taxonomy' , array( 'taxonomy' => 'post_tag' , 'query_var' => 'tag' , 'has_rewrite' => TRUE , 'priority' => 5 ) );
	scrib_register_facet( 'category' , 'Facet_Taxonomy' , array( 'query_var' => 'category_name' , 'has_rewrite' => TRUE , 'priority' => 4 ) );
//	scrib_register_facet( 'post_author' , 'Facet_Post' );

//echo "<h2>Hey!</h2>";
//global $facets;
//print_r( $facets );
}
add_action( 'init' , 'register_facet_test' );
//add_action( 'scrib_register_facets' , 'register_facet_test' );


