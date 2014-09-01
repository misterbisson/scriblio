<?php

class Facet_Searchword implements Facet
{
	var $query_var = 's';
	var $exclude_from_widget = TRUE;

	private $cache_group = 'scriblio-searchword-to-taxonomy';
	private $cache_ttl = 604807; // ~7 days

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
		// there should only be one term since we don't break up the search
		// string from the search textbox
		$search_slug = sanitize_title_with_dashes( array_keys( $this->facets->selected_facets->searchword )[0] );

		// check if we have cached facets for $search_slug
		$facets = wp_cache_get( $search_slug, $this->cache_group );

		if ( ( FALSE !== $facets ) && empty( $facets ) )
		{
			return FALSE; // empty cached result
		}

		// not found in our cache
		if ( FALSE === $facets )
		{
			// get all terms with slug $search_slug
			$terms = $this->get_taxonomy_terms( $search_slug );

			// sort the terms by count since the counts may have changed
			// after they were loaded from the DB
			usort( $terms, array( $this, 'compare_count_desc' ) );

			if ( empty( $terms ) )
			{
				// cache negative results too
				wp_cache_set( $search_slug, array(), $this->cache_group, $this->cache_ttl );
				return FALSE;
			}

			// iterate over $terms, which are now in descending count order,
			// until we get a facet.
			foreach ( $terms as $term )
			{
				// cast the result to an array so we can check if it's empty
				$facets = (array) scriblio()->get_terms_as_facets( array( $term ) );
				if ( ! empty( $facets ) )
				{
					break;
				}
			}//END foreach

			// cache the results. $facets should contain just one term,
			// or it could be empty
			wp_cache_set( $search_slug, $facets, $this->cache_group, $this->cache_ttl );
		}//END if

		if ( empty( $facets ) )
		{
			return FALSE; // still got nothing
		}

		$new_facet_name = array_keys( $facets )[0];

		// cast back to an object so we can dereference $facets->$new_facet_name
		$facets = (object) $facets;

		// make sure the converted facet/taxonomy is selected for the search
		if ( ! isset( $this->facets->selected_facets->$new_facet_name ) )
		{
			$this->facets->selected_facets->$new_facet_name = $facets->$new_facet_name;
		}

		// merge new terms in $facets with any existing search term in the
		// converted facet/taxonomy
		$this->facets->selected_facets->$new_facet_name = array_merge(
			$this->facets->selected_facets->$new_facet_name,
			$facets->$new_facet_name
		);

		// not a keyword search any more
		unset( $this->facets->selected_facets->searchword );

		return TRUE;
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
				WHERE t.slug = %s
				ORDER BY tt.count DESC',
				$search_slug
			)
		);

		if ( empty( $terms ) )
		{
			return NULL;
		}

		// scriblio-authority is hooked to this filter
		return apply_filters( 'scriblio_searchword_to_taxonomy_terms', $terms );
	}//END get_taxonomy_terms

	/**
	 * compare two terms by their counts, in reverse order
	 */
	public function compare_count_desc( $term_a, $term_b )
	{
		return ( $term_a->count < $term_b->count );
	}//END compare_count_desc
}//END class
