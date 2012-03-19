<?php

class Facet_Taxonomy implements Facet
{

	var $ttl = 600; // 10 minutes

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
		foreach( array_filter( array_map( 'trim' , (array) preg_split( '/[,\+\|\/]/' , $query_terms ))) as $val )
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

		if( ! $this->terms_in_corpus = wp_cache_get( 'terms-in-corpus-'. $this->taxonomy , 'scrib-facet-taxonomy' ))
		{
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

			wp_cache_set( 'terms-in-corpus-'. $this->taxonomy , $this->terms_in_corpus, 'scrib-facet-taxonomy', $this->ttl );
		}

		return $this->terms_in_corpus;
	}

	function get_terms_in_found_set()
	{
		if( is_array( $this->facets->_matching_tax_facets[ $this->name ] ))
			return $this->facets->_matching_tax_facets[ $this->name ];

		$matching_post_ids = $this->facets->get_matching_post_ids();

		$cache_key = md5( serialize( $matching_post_ids ));

		if( ! $this->facets->_matching_tax_facets = wp_cache_get( $cache_key , 'scrib-facet-taxonomy' ))
		{
			global $wpdb;

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

			wp_cache_set( $cache_key, $this->facets->_matching_tax_facets , 'scrib-facet-taxonomy', $this->ttl );
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
