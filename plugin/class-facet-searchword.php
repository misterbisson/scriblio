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
	 * @param string $search_term the keyword search term
	 * @return object authoritative term objects from $facets, or NULL if
	 *  there're no authoritative terms in $facets
	 */
	public function get_authoritative_terms( $search_term )
	{
		if ( ! term_exists( $search_term ) )
		{
			return NULL;
		}
		
		// get all taxonomy terms that match $search_term, as term objects
		$terms = $this->get_taxonomy_terms( $search_term );

		if ( empty( $terms ) )
		{
			return NULL;
		}

		// scriblio-authority is hooked to this filter 
		return apply_filters( 'scriblio_facet_taxonomy_terms', $terms );
	}//END get_authoritative_terms

	/**
	 * find all term objects (with taxonomy) having the slug $term_slug
	 *
	 * @param string $term_slug the term to find taxonomies for
	 * @return array list of taxonomies $term is in. this could be empty.
	 */
	public function get_taxonomy_terms( $term_slug )
	{
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.term_id, t.name, t.slug, t.term_group,
					tt.term_taxonomy_id, tt.taxonomy, tt.description,
					tt.parent, tt.count
				FROM ' . $wpdb->terms . ' t JOIN ' . $wpdb->term_taxonomy . ' tt
					ON t.term_id = tt.term_id
				WHERE t.slug = %s',
				$term_slug
			)
		);
	}//END get_term_taxonomies
}//END class
