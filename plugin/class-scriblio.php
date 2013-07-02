<?php

class Scriblio
{
	var $options = array(
		'facet-components' => array(
			'searchword'  => TRUE,
			'taxonomy'    => TRUE,
			'post-author' => TRUE,
			'post-type'   => TRUE,
		),
		'widgets'       => TRUE,
		'scrib-suggest' => TRUE,
		'facets'        => array(
			'searchword' => array(
				'class'       => 'Facet_Searchword',
				'priority'    => 0, 
				'has_rewrite' => TRUE,
			),
			// This is a special facet
			// If set to TRUE will register all public taxonomies
			// If set to FALSE no taxonomy facets will be registered
			// If an array of taxonomy names with priorities only indicated taxonomies will be registered:
			// 'taxonomy' => array(
			//     'post_tag' => 5,
			//     'category' => 5,
			// )
			'taxonomy' => TRUE,
			'taxonomy' => FALSE,
			'post_author' => array(
				'class'       => 'Facet_Post_Author',
				'priority'    => 3, 
				'has_rewrite' => TRUE,
			),
			'post_type' => array(
				'class'       => 'Facet_Post_Type',
				'priority'    => 3, 
				'has_rewrite' => TRUE,
			),
		),
	);
	
	public function __construct()
	{
		add_action( 'scrib_register_facets' , array( $this, 'register_facets' ) );
		
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
		foreach ( $this->options['facet-components'] as $facet => $activate )
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
	 * Register facets
	 */
	function register_facets()
	{	
		foreach ( $this->options['facets'] as $facet => $options )
		{
			if ( 'taxonomy' != $facet )
			{
				scrib_register_facet(
					$facet, 
					$options['class'], 
					array( 
						'priority' => $options['priority'],
						'has_rewrite' => $options['has_rewrite'],
					) 
				);
			} // END if
			else 
			{
				if ( is_array( $options ) )
				{
					foreach ( $options as $taxonomy_name => $priority )
					{
						$taxonomy = get_taxonomy( $taxonomy_name );
						
						if ( ! $taxonomy )
						{
							continue;
						} // END if
						
						scrib_register_facet(
							( empty( $taxonomy->label ) ? $taxonomy->name : sanitize_title_with_dashes( $taxonomy->label ) ),
							'Facet_Taxonomy',
							array(
								'taxonomy'    => $taxonomy->name ,
								'query_var'   => $taxonomy->query_var ,
								'has_rewrite' => is_array( $taxonomy->rewrite ),
								'priority'    => absint( $priority ),
							)
						);
					} // END foreach
				} // END if
				elseif ( TRUE == $options )
				{
					$this->register_public_taxonomies();
				} // END elseif
			} // END else
		} // END foreach
	} // END register_facets
	
	public function register_public_taxonomies()
	{
		foreach( (array) get_taxonomies( array( 'public' => TRUE ) ) as $taxonomy )
		{
			$taxonomy = get_taxonomy( $taxonomy );

			scrib_register_facet(
				( empty( $taxonomy->label ) ? $taxonomy->name : sanitize_title_with_dashes( $taxonomy->label ) ),
				'Facet_Taxonomy',
				array(
					'taxonomy'    => $taxonomy->name ,
					'query_var'   => $taxonomy->query_var ,
					'has_rewrite' => is_array( $taxonomy->rewrite ),
					'priority'    => 5,
				)
			);
		} // END foreach
	} // END register_public_taxonomies
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