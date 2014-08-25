<?php

class Facet_Searchword implements Facet
{

	var $query_var = 's';
	var $exclude_from_widget = TRUE;

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
		$term = wp_kses( trim( urldecode( stripslashes( $query_terms ) ) ), array() );
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

	/**
	 * Convert a keyword search term to a taxonomy (embodied in a facet)
	 *
	 * @return mixed an array with two elements of [ new_facet_name, new_facet]
	 *  if the keyword search can be converted to a facet, or FALSE if not.
	 */
	public function to_taxonomy()
	{
		// there should only be one term since don't break up the search
		// terms in the search text box
		$search_slug = sanitize_title_with_dashes( array_keys( $this->facets->selected_facets->searchword )[0] );

		// check if we have cached facets for $search_slug
		$facets = wp_cache_get( $search_slug, 'scriblio-searchword-to-taxonomy' );

		if ( ( FALSE !== $facets ) && empty( $facets ) )
		{
			return FALSE; // empty cached result
		}
		elseif ( FALSE === $facets )
		{
			// get all terms with slug $search_slug
			$terms = $this->get_taxonomy_terms( $search_slug );

			if ( empty( $terms ) )
			{
				// cache negative results too
				wp_cache_set( $search_slug, array(), 'scriblio-searchword-to-taxonomy', scriblio()->options['converted_facet_cache_ttl'] );
				return FALSE;
			}

			// convert the terms back to facets
			$facets = scriblio()->get_terms_as_facets( $terms );

			// cache the results, even if they're empty
			wp_cache_set( $search_slug, $facets, 'scriblio-searchword-to-taxonomy', scriblio()->options['converted_facet_cache_ttl'] );
		}//END if

		// get facet/taxonmy name with the term with the highest count
		if ( ! $new_facet_name = $this->get_most_popular_facet( $facets ) )
		{
			return FALSE;
		}

		// merge $new_facet with existing, selected facets. 
		if ( isset( $this->facets->selected_facets->$new_facet_name ) )
		{
			// we need to cast the facet to an object so we can reference
			// its keys by a variable
			$new_facet = (object) $this->facets->selected_facets->$new_facet_name;
		}
		else
		{
			$new_facet = new stdClass;
		}

		// copy over the query terms to the new facet
		foreach ( $facets->$new_facet_name as $key => $val )
		{
			$new_facet->$key = $val;
		}//END foreach

		return array( $new_facet_name, (array) $new_facet );
	}//END to_taxonomy

	/**
	 * @param string $search_slug the keyword search term slug
	 * @return object term objects with $search_term slug in all known
	 *  taxonomies, or NULL if there're no terms matching that slug
	 */
	public function get_taxonomy_terms( $search_slug )
	{
		if ( ! term_exists( $search_slug ) )
		{
			return NULL;
		}

		// get all taxonomy terms that match $search_slug, as term objects
		global $wpdb;

		$terms = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.term_id, t.name, t.slug, t.term_group,
					tt.term_taxonomy_id, tt.taxonomy, tt.description,
					tt.parent, tt.count
				FROM ' . $wpdb->terms . ' t 
					JOIN ' . $wpdb->term_taxonomy . ' tt
						ON t.term_id = tt.term_id
				WHERE t.slug = %s',
				$search_slug
			)
		);

		if ( empty( $terms ) )
		{
			return NULL;
		}

		// scriblio-authority is hooked to this filter
		return apply_filters( 'scriblio_facet_taxonomy_terms', $terms, '', FALSE );
	}//END get_taxonomy_terms

	/**
	 * find the facet with the term that has the highest count
	 *
	 * @param array $facets a list of facets to scan through
	 * @return mixed name of the facet with the most-used term, or NULL
	 *  if there is no term to work with.
	 */
	public function get_most_popular_facet( $facets )
	{
		$winner = NULL;
		$max_count = -1;

		foreach ( $facets as $facet_name => $terms )
		{
			foreach ( $terms as $term_slug => $term )
			{
				if ( $max_count < $term->count )
				{
					$winner = $facet_name;
					$max_count = $term->count;
					continue;
				}
			}//END foreach
		}//END foreach

		return $winner;
	}//END get_most_popular_facet
}//END class
