<?php

class Facet_Post_Type implements Facet
{

	var $_post_to_label = array( // pretty names
		'post_author' 	 => 'Author',
		'post_status' 	 => 'Status',
		'post_parent' 	 => 'Parent',
		'post_type' 	 => 'Post Type',
		'post_mime_type' => 'MIME Type',
	);

	var $_post_to_queryvar = array(
		'post_author' 	 => 'author_name',
		'post_status' 	 => 'post_status',
		'post_parent' 	 => 'post_parent',
		'post_type' 	 => 'post_type',
		'post_mime_type' => 'post_mime_type',
	);

	var $_queryvar_to_post = array(
		'author_name' 	 => 'post_author',
		'post_status' 	 => 'post_status',
		'post_parent' 	 => 'post_parent',
		'post_type' 	 => 'post_type',
		'post_mime_type' => 'post_mime_type',
	);

	var $_permastructs = array(
		'post_author' 	 => FALSE,
		'post_status' 	 => FALSE,
		'post_parent' 	 => FALSE,
		'post_type' 	 => TRUE,
		'post_mime_type' => FALSE,
	);

	var $ttl = 600; // 10 minutes

	function __construct( $name , $args , $facets_object )
	{
		$post_type = get_post_type_object( 'post' );

		$this->name = $name; // name should be exactly the name of the post field
		$this->args = $args;
		$this->facets = $facets_object;

		$this->label = $this->_post_to_label[ $name ];
		$this->labels = $this->facets->build_labels( __('Post Type') , __('Post Types') );
		$this->query_var = $this->_post_to_queryvar[ $name ];
	}

	function register_query_var()
	{
		if ( TRUE === $this->query_var )
		{
			$this->query_var = $this->name;
		}

		$this->query_var = sanitize_title_with_dashes( $this->query_var );

		return $this->query_var;
	}

	function parse_query( $query_terms , $wp_query )
	{
		// identify the terms in this query
		foreach( array_filter( array_map( 'trim' , (array) preg_split( '/[,\+\|]/' , $query_terms ) ) ) as $val )
		{
			if( $post_type = get_post_type_object( $val ) )
			{
				$count = wp_count_posts( $term->post_type );
				
				$this->selected_terms[] = (object) array(
					'facet'       => $this->name,
					'slug'        => $val,
					'name'        => $post_type->labels->singular_name,
					'description' => $post_type->description,
					'term_id'     => $val,
				);
			}
		}

		return $this->selected_terms;
	}

	function get_terms_in_corpus()
	{
		if( isset( $this->terms_in_corpus ) )
		{
			return $this->terms_in_corpus;
		}

		if( ! $this->terms_in_corpus = wp_cache_get( 'terms-in-corpus' , 'scrib-facet-post-type' ) )
		{
			global $wpdb;
	
			$terms = $wpdb->get_results( 'SELECT post_type, COUNT(*) AS hits FROM ' . $wpdb->posts . ' WHERE post_status = "publish" GROUP BY post_type LIMIT 1000' );
	
			$this->terms_in_corpus = array();
			foreach( $terms as $term )
			{
				$post_type = get_post_type_object( $term->post_type );
				
				if( empty( $post_type ) )
				{
					continue;
				}

				$this->terms_in_corpus[] = (object) array(
					'facet'       => $this->name,
					'slug'        => $term->post_type,
					'name'        => $post_type->labels->singular_name,
					'description' => $post_type->description,
					'term_id'     => $term->post_type,
					'count'       => $terms->hits,
				);
			}

			wp_cache_set( 'terms-in-corpus', $this->terms_in_corpus, 'scrib-facet-post-type', $this->ttl );
		}

		return $this->terms_in_corpus;
	}

	function get_terms_in_found_set()
	{
		if( isset( $this->terms_in_found_set ) )
		{
			return $this->terms_in_found_set;
		}

		$matching_post_ids = $this->facets->get_matching_post_ids();

		$cache_key = md5( serialize( $matching_post_ids ) );

		if( ! $this->terms_in_found_set = wp_cache_get( $cache_key , 'scrib-facet-post-type' ) )
		{
			global $wpdb;

			$terms = $wpdb->get_results( 'SELECT post_type , COUNT(*) AS hits FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',' , $matching_post_ids ) . ') GROUP BY post_type LIMIT 1000' );

			$this->terms_in_found_set = array();
			
			foreach( $terms as $term )
			{
				$post_type = get_post_type_object( $term->post_type );
								
				if( empty( $post_type ))
				{
					continue;
				}
								
				$this->terms_in_found_set[] = (object) array(
					'facet'       => $this->name,
					'slug'        => $term->post_type,
					'name'        => $post_type->labels->singular_name,
					'description' => $post_type->description,
					'term_id'     => $term->post_type,
					'count'       => $terms->hits,
				);
			}

			wp_cache_set( $cache_key, $this->terms_in_found_set , 'scrib-facet-post-type', $this->ttl );
		}

		return $this->terms_in_found_set;
	}

	function get_terms_in_post( $post_id = FALSE )
	{
		if( ! $post_id )
		{
			$post_id = get_the_ID();
		}

		if( ! $post_id )
		{
			return FALSE;
		}

		$post_type = get_post_type_object( get_post( $post_id )->post_type );
		
		$count = wp_count_posts( $post_type );

		$this->terms_in_post[] = (object) array(
			'facet'       => $this->name,
			'slug'        => $post_type,
			'name'        => $post_type->labels->singular_name,
			'description' => $post_type->description,
			'term_id'     => $post_type,
			'count'       => $count->publish,
		);

		return $this->terms_in_post;
	}

	function selected( $term )
	{
		return( isset( $this->selected_terms[ ( is_object( $term ) ? $term->slug : $term ) ] ) );
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
		if( empty( $terms ))
		{
			return;
		}
				
		return get_post_type_archive_link( current( $terms )->term_id );
	}
}
