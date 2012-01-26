<?php

class Facet_Post_Author implements Facet
{

	var $_post_to_label = array( // pretty names
		'post_author' 	=> 'Author',
		'post_status' 	=> 'Status',
		'post_parent' 	=> 'Parent',
		'post_type' 	=> 'Type',
		'post_mime_type' => 'MIME Type',
	);

	var $_post_to_queryvar = array(
		'post_author' 	=> 'author_name',
		'post_status' 	=> 'post_status',
		'post_parent' 	=> 'post_parent',
		'post_type' 	=> 'post_type',
		'post_mime_type' => 'post_mime_type',
	);

	var $_queryvar_to_post = array(
		'author_name' 	=> 'post_author',
		'post_status' 	=> 'post_status',
		'post_parent' 	=> 'post_parent',
		'post_type' 	=> 'post_type',
		'post_mime_type' => 'post_mime_type',
	);

	var $_permastructs = array(
		'post_author' 	=> TRUE,
		'post_status' 	=> FALSE,
		'post_parent' 	=> FALSE,
		'post_type' 	=> FALSE,
		'post_mime_type' => FALSE,
	);

	function __construct( $name , $args , $facets_object )
	{
		$this->name = $name; // name should be exactly the name of the post field
		$this->args = $args;
		$this->facets = $facets_object;

		$this->label = $this->_post_to_label[ $name ];
//		$this->labels = $taxonomy->labels;
		$this->query_var = $this->_post_to_queryvar[ $name ];

		add_action( 'init' , array( $this , 'update_permastructs' ) , 10 );
	}

	function update_permastructs()
	{
		global $wp_rewrite;
		$this->_permastructs['post_author'] = $wp_rewrite->get_author_permastruct();
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
			if( $userdata = get_user_by( 'slug' , $val ))
			{
				$this->selected_terms[ $userdata->user_nicename ] = (object) array(
					'facet' => $this->name,
					'slug' => $userdata->user_nicename,
					'name' => $userdata->display_name,
					'description' => $userdata->user_description,
					'term_id' => $userdata->ID,
				);
			}
		}

		return $this->selected_terms;
	}

	function get_terms_in_corpus()
	{
		if( isset( $this->terms_in_corpus ))
			return $this->terms_in_corpus;

		global $wpdb;

		$terms = $wpdb->get_results('SELECT post_author , COUNT(*) AS hits FROM '. $wpdb->posts .' WHERE post_status = "publish" GROUP BY post_author LIMIT 1000' );

		$this->terms_in_corpus = array();
		foreach( $terms as $term )
		{
			$userdata = get_userdata( $term->post_author );
			if( empty( $userdata->display_name ))
				continue;

			$this->terms_in_corpus[] = (object) array(
				'facet' => $this->name,
				'slug' => $userdata->user_nicename,
				'name' => $userdata->display_name,
				'description' => $userdata->user_description,
				'term_id' => $term->post_author,
				'count' => $term->hits,
			);
		}

		return $this->terms_in_corpus;
	}

	function get_terms_in_found_set()
	{
		if( isset( $this->terms_in_found_set ))
			return $this->terms_in_found_set;

		$matching_post_ids = $this->facets->get_matching_post_ids();

		global $wpdb;

		$terms = $wpdb->get_results('SELECT post_author , COUNT(*) AS hits FROM '. $wpdb->posts .' WHERE ID IN ('. implode( ',' , $matching_post_ids ) .') GROUP BY post_author LIMIT 1000' );

		$this->terms_in_found_set = array();
		foreach( $terms as $term )
		{
			$userdata = get_userdata( $term->post_author );
			if( empty( $userdata->display_name ))
				continue;

			$this->terms_in_found_set[] = (object) array(
				'facet' => $this->name,
				'slug' => $userdata->user_nicename,
				'name' => $userdata->display_name,
				'description' => $userdata->user_description,
				'term_id' => $term->post_author,
				'count' => $term->hits,
			);
		}

		return $this->terms_in_found_set;

	}

	function get_terms_in_post( $post_id = FALSE )
	{
		if( ! $post_id )
			$post_id = get_the_ID();

		if( ! $post_id )
			return FALSE;

		$userdata = get_userdata( get_post( $post_id )->post_author );

		$this->terms_in_post[] = (object) array(
			'facet' => $this->name,
			'slug' => $userdata->user_nicename,
			'name' => $userdata->display_name,
			'description' => $userdata->user_description,
			'term_id' => $userdata->ID,
			'count' => count_user_posts( $userdata->ID ),
		);

		return $this->terms_in_post;
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
		if( empty( $terms ))
			return;

		// This only works for the first author 
		return get_author_posts_url( (int) current( $terms )->term_id );
	}
}
