<?php

class Scriblio
{
	var $options = array(
		'facets' => array(
			'searchword'  => TRUE,
			'taxonomy'    => TRUE,
			'post-author' => TRUE,
			'post-type'   => TRUE,
		),
		'widgets'       => TRUE,
		'scrib-suggest' => TRUE,
	);
	
	public function __construct()
	{
		add_action( 'scrib_register_facets' , array( $this, 'register_default_facets' ) );
		
		// Activate the sub-components
		$this->activate();			
	} // END __construct

	/**
	 * Activate Scriblio
	 */
	public function activate()
	{
		// Load main facets class
		require_once( dirname( __FILE__ ) .'/class-facets.php' );

		// get options with defaults
		$this->options = apply_filters( 
			'go_config', 
			$this->options, 
			'scriblio'
		);

		// Activate facets
		foreach ( $this->options['facets'] as $facet => $activate )
		{
			if ( $activate )
			{
				require_once( dirname( __FILE__ ) . '/class-facet-' . $facet . '.php' );
			} // END if
		} // END foreach

		if ( $this->options['widgets'] )
		{
			require_once( dirname( __FILE__ ) .'/widgets.php' );
		} // END if
		
		if ( $this->options['scrib-suggest'] )
		{
			require_once( dirname( __FILE__ ) .'/class-scrib-suggest.php' );
		} // END if
	} // END activate
	
	/**
	 * Register default facets
	 */
	function register_default_facets()
	{
		// register keyword search facet
		scrib_register_facet( 'searchword' , 'Facet_Searchword' , array( 'priority' => 0 , 'has_rewrite' => TRUE ) );

		// register public taxonomies as facets
		foreach( (array) get_taxonomies( array( 'public' => true )) as $taxonomy )
		{
			$taxonomy = get_taxonomy( $taxonomy );

			scrib_register_facet(
				( empty( $taxonomy->label ) ? $taxonomy->name : sanitize_title_with_dashes( $taxonomy->label ) ),
				'Facet_Taxonomy' ,
				array(
					'taxonomy' => $taxonomy->name ,
					'query_var' => $taxonomy->query_var ,
					'has_rewrite' => is_array( $taxonomy->rewrite ),
					'priority' => 5,
				)
			);
		} // END foreach

		// register facets from the posts table
		scrib_register_facet( 'post_author' , 'Facet_Post_Author' , array( 'priority' => 3 , 'has_rewrite' => TRUE ) );
		scrib_register_facet( 'post_type' , 'Facet_Post_Type' , array( 'priority' => 3 , 'has_rewrite' => TRUE ) );
	} // END register_default_facets
} // END Scriblio

function scriblio()
{
	global $scriblio;

	if( ! $scriblio )
	{
		$scriblio = new Scriblio;
	}

	return $scriblio;
} // END scriblio