<?php


/*
this code was added in Scriblio 2.9 r1 to support user-specified sort ordering, but never fully implemented
*/

	// this was invoked in the editsearch widget
	public function editsort()
	{
		global $wp_query;

		do_action( 'scrib_init_sort' );

		foreach( (array) $this->methods_sort as $handle => $method )
		{
			if( $handle == $wp_query->query_vars['sortby'] )
				$selected = 'class="selected"';

			echo '<li><a href="'. add_query_arg(array( 'sortby' => $handle , 'sort' => $method['order'])) .'" '. $selected .'>'. $method['name'] .'</a></li>';
		}
	}

	// this was invoked at the parse_query action
	// it was called from an environment that could differentiate between search and browse queries
	// $this->add_sort_filters( 'browse' );
	public function add_sort_filters( $type )
	{
		global $wp_query;

		if( isset( $wp_query->query_vars['sortby'] ))
			do_action( 'scrib_sort_'. $wp_query->query_vars['sortby'] , $wp_query->query_vars['sort'] );
		else
			do_action( 'scrib_sort_default_'. $type , $wp_query->query_vars['sort'] );
	}

/*
The idea was that thoe scrib_sort_* actions could be used to trigger functions that would add filters to the query_posts

I did, however, implement code such as the following to 
*/

// alpha sort posts by title
add_filter( 'posts_orderby', 'program_posts_orderby', 8 );
function program_posts_orderby( $sql )
{
	global $scrib, $wpdb;

	if( $scrib->is_browse )
		return $wpdb->posts .'.post_name ASC, '. $sql;

	return $sql;
}

// sort posts by a postmeta field (requires a join and orderby)
add_filter( 'posts_join', 'donors_posts_join', 8 );
function donors_posts_join( $sql )
{
	global $scrib, $wpdb;

	if( $scrib->is_browse )
		return " JOIN $wpdb->postmeta ON ( $wpdb->postmeta.meta_key = 'scrib_sort_". ( is_array( $scrib->search_terms['cy'] ) ? $scrib->search_terms['cy'][0] : 'hshld' ) ."' AND $wpdb->posts.ID = $wpdb->postmeta.post_id )". $sql;

	return $sql;
}
add_filter( 'posts_orderby', 'donors_posts_orderby', 8 );
function donors_posts_orderby( $sql )
{
	global $scrib, $wpdb;

	if( $scrib->is_browse )
		return $wpdb->postmeta .'.meta_value ASC, '. $sql;

	return $sql;
}
add_filter( 'posts_join', 'donors_posts_join', 8 );
function donors_posts_join( $sql )
{
	global $scrib, $wpdb;

	if( $scrib->is_browse )
		return " JOIN $wpdb->postmeta ON ( $wpdb->postmeta.meta_key = 'scrib_sort_". ( is_array( $scrib->search_terms['cy'] ) ? $scrib->search_terms['cy'][0] : 'hshld' ) ."' AND $wpdb->posts.ID = $wpdb->postmeta.post_id )". $sql;

	return $sql;
}


/*
The above case required different sort keys for different types of queries, a problem made more complex because the design rule was that each household in the records only be listed once if the query parameters matched multiple members of a single household. That alone wasn't hard, but properly sorting the individual members when the query didn't match multiple members of a household and mixing results that were grouped and ungrouped in the same list blah blah blah…

The sort keys were updated every time the post was saved. 
*/

if( ! empty( $field['householdsort'] )) // the sort key representing the primary name for a household
	add_post_meta( $post_id , 'scrib_sort_hshld', $field['householdsort'] , TRUE );

if( ! empty( $field['sort'] ) && absint( $field['class'] )) // the sort key representing a name in a specific year
	add_post_meta( $post_id , 'scrib_sort_'. absint( $field['class'] ) , $field['sort'] , TRUE );

