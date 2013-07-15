<?php

class Scriblio
{
	// the default options. The facets portion is empty until the `wp_loaded` action is run.
	var $options = array(
		'components' => array(
			'facets' => TRUE, // making this false does nothing, as it's required for the others
			'suggest' => TRUE,
			'widgets' => TRUE,
		),
		'register_default_facets' => TRUE,
	);

	// default facets (excluding taxonomy facets, which are identified on the `wp_loaded` action)
	// these are only loaded if the `register_default_facets` option is TRUE.
	var $default_facets = array(
		'searchword' => array(
			'class' => 'Facet_Searchword',
			'args' => array(
				'has_rewrite' => TRUE,
				'priority' => 0,
			),
		),
		'post_author' => array(
			'class' => 'Facet_Post_Author',
			'args' => array(
				'has_rewrite' => TRUE,
				'priority' => 3,
			),
		),
		'post_type' => array(
			'class' => 'Facet_Post_Type',
			'args' => array(
				'has_rewrite' => TRUE,
				'priority' => 3,
			),
		),
	);

	public function __construct()
	{
		add_action( 'wp_loaded' , array( $this , 'wp_loaded' ), 1 );

		// get options with defaults to figure out what components to activate
		$this->options = apply_filters(
			'go_config',
			$this->options,
			'scriblio'
		);

		/*
		** Load all the components based on the config
		*/

		// Load main facets class
		$this->facets();

		// The widgets
		if ( $this->options['components']['widgets'] )
		{
			require_once __DIR__ . '/widgets.php';
		} // END if

		// The type-ahead suggest class
		if ( $this->options['components']['suggest'] )
		{
			require_once __DIR__ . '/class-scrib-suggest.php';
		} // END if

	} // END activate

	/**
	 * Singleton for the facets class
	 */
	public function facets()
	{
		if( ! $this->facets )
		{
			require_once __DIR__ . '/class-facets.php';

			$this->facets = new Facets;
		}

		return $this->facets;
	}//end facets

	/**
	 * Register our known facets, then do the scrib_register_facets action
	 */
	public function wp_loaded()
	{

		// if we're loading default facets, then figure out what they are
		if ( $this->options['register_default_facets'] )
		{
			$this->options['facets'] = $this->get_default_facets();
		}
		else
		{
			$this->options['facets'] = array();
		}

		// Filter the default facets
		$this->options['facets'] = apply_filters(
			'go_config',
			$this->options['facets'],
			'scriblio-facets'
		);

		// load the facets we know about
		if ( is_array( $this->options['facets'] ) )
		{
			foreach ( $this->options['facets'] as $facet => $options )
			{
				scrib_register_facet(
					$facet,
					$options['class'],
					$options['args']
				);
			}

		}

		// call the trigger so facets defined in other plugins can load
		do_action( 'scrib_register_facets' );
	}//end wp_loaded

	/**
	 * Get default facets
	 */
	function get_default_facets()
	{
		$facets = $this->default_facets;

		// register public taxonomies as facets
		foreach ( (array) get_taxonomies( array( 'public' => TRUE ) ) as $taxonomy )
		{
			$taxonomy = get_taxonomy( $taxonomy );

			$facets[ ( empty( $taxonomy->label ) ? $taxonomy->name : sanitize_title_with_dashes( $taxonomy->label ) ) ] = array(
				'class' => 'Facet_Taxonomy',
				'args' => array(
					'taxonomy' => $taxonomy->name ,
					'query_var' => $taxonomy->query_var ,
					'has_rewrite' => is_array( $taxonomy->rewrite ),
					'priority' => 5,
				),
			);
		}

		return $facets;
	}// END get_default_facets

	/**
	 * Register a single facet
	 */
	function register_facet( $name , $type , $args = array() )
	{
		$this->facets()->register_facet( $name , $type , $args );
	}// END register_facet

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



function facets()
{
	_deprecated_function( __FUNCTION__, '3.2', 'scriblio()->facets()' );

	global $facets;

	if( ! $facets )
	{
		$facets = scriblio()->facets();
	}

	return $facets;
} // END facets



function scrib_register_facet( $name , $type , $args = array() )
{
	_deprecated_function( __FUNCTION__, '3.2', 'scriblio()->register_facet( $name , $type , $args )' );

	scriblio()->register_facet( $name , $type , $args );
}
