<?php

class Scriblio
{
	// init the timer vars
	public $timer = array();
	public $timer_start = array();
	public $profiler = FALSE;
	public $cachebuster = FALSE;

	// the default options. The facets portion is empty until the `wp_loaded` action is run.
	public $options = array(
		'components' => array(
			'facets' => TRUE, // making this false does nothing, as it's required for the others
			'suggest' => TRUE,
			'widgets' => TRUE,
		),
		'register_default_facets' => TRUE,
		'searchword_to_taxonomy_cache_ttl' => 604807, // ~7 days
	);

	// default facets (excluding taxonomy facets, which are identified on the `wp_loaded` action)
	// these are only loaded if the `register_default_facets` option is TRUE.
	public $default_facets = array(
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
		'date_range' => array(
			'class' => 'Facet_Publish_Date',
			'args' => array(
				'has_rewrite' => TRUE,
				'priority' => 9,
			),
		),
	);

	public $facets = FALSE;

	public function __construct()
	{
		add_action( 'wp_loaded', array( $this, 'wp_loaded' ), 1 );
		add_action( 'parse_query', array( $this, 'parse_query' ), 25 );
		add_action( 'wp_footer', array( $this, 'wp_footer' ), 1 );

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
		} // end if

		// The type-ahead suggest class
		if ( $this->options['components']['suggest'] )
		{
			require_once __DIR__ . '/class-scrib-suggest.php';
		} // end if

	} // end __construct

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
		// turn on performance profiling if requested
		if ( isset( $_REQUEST['scriblio-profiler'] ) && current_user_can( 'manage_options' ) )
		{
			$this->profiler = TRUE;
		}

		// cachebuster
		if ( isset( $_REQUEST['scriblio-cachebuster'] ) && current_user_can( 'manage_options' ) )
		{
			$this->cachebuster = TRUE;
		}

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
				scriblio()->register_facet(
					$facet,
					$options['class'],
					$options['args']
				);
			}

		}// end if

		// call the trigger so facets defined in other plugins can load
		do_action( 'scrib_register_facets' );
	}//end wp_loaded

	/**
	 * Get default facets
	 */
	public function get_default_facets()
	{
		$facets = $this->default_facets;

		// register public taxonomies as facets
		foreach ( (array) get_taxonomies( array( 'public' => TRUE ) ) as $taxonomy )
		{
			$taxonomy = get_taxonomy( $taxonomy );

			$facets[ ( empty( $taxonomy->label ) ? $taxonomy->name : sanitize_title_with_dashes( $taxonomy->label ) ) ] = array(
				'class' => 'Facet_Taxonomy',
				'args' => array(
					'taxonomy' => $taxonomy->name,
					'query_var' => $taxonomy->query_var,
					'has_rewrite' => is_array( $taxonomy->rewrite ),
					'priority' => 5,
				),
			);
		}// end foreach

		return $facets;
	}// end get_default_facets

	/**
	 * Register a single facet
	 */
	public function register_facet( $name, $type, $args = array() )
	{
		$this->facets()->register_facet( $name, $type, $args );
	}// end register_facet


	/**
	 * Hooked to the parse_request action
	 * Multi faceted searches can be heavy you might want to restrict it to only certain users
	 */
	public function parse_query( $query )
	{
		// Check if we should restrict this user's ability to do multi faceted searches
		$restrict_faceted_search = apply_filters( 'scriblio_restrict_faceted_search', FALSE );

		if ( $restrict_faceted_search )
		{
			$facet_count = array_sum( (array) $this->facets()->selected_facets_counts );

			// if we get in here, the user can't do faceted searching
			if ( $facet_count > 1 )
			{
				auth_redirect();
			}//end if
		}//end if
	}// end parse_query

	public function wp_footer()
	{

		if ( ! $this->profiler )
		{
			return;
		}

		echo '<h2>Scriblio performance profile</h2>';
		printf(
			'<table><tr>
				<td>%1$s</td>
				<td>%2$s</td>
				<td>%3$s</td>
			</tr>',
			'Event',
			'Seconds',
			'Notes'
		);

		foreach ( $this->timer as $group => $v )
		{
			foreach ( $v as $item )
			{
				printf(
					'<tr>
						<td>%1$s</td>
						<td>%2$s</td>
						<td><pre>%3$s</pre></td>
					</tr>',
					$group,
					number_format( $item['time'], 4 ),
					print_r( $item['notes'], TRUE )
				);
			}
		}

		echo '</table>';
	}

	public function timer( $name, $notes = '' )
	{
		// only run if the performance profiler class var is TRUE
		if ( ! $this->profiler )
		{
			return;
		}

		// start the timer the timer
		if ( ! isset( $this->timer_start[ $name ] ) )
		{
			$this->timer_start[ $name ] = microtime( TRUE );
			return;
		}

		// end the timer
		$this->timer[ $name ][] = array(
			'time' => microtime( TRUE ) - $this->timer_start[ $name ],
			'notes' => $notes,
		);

		// clear the old timer start
		unset( $this->timer_start[ $name ] );
	}

	/**
	 * generate a permalink given an array of terms
	 *
	 * @param $terms array Array of WP term objects
	 */
	public function get_terms_link( $terms )
	{
		if ( ! $terms )
		{
			return home_url();
		}//end if

		$selected_terms = $this->get_terms_as_facets( $terms );

		$this->facets()->selected_facets = $selected_terms;

		return $this->facets()->permalink();
	}//end get_terms_link

	/**
	 * generate a collection of facets based on a collection of terms
	 *
	 * @param $terms array Array of WP term objects
	 */
	public function get_terms_as_facets( $terms )
	{
		if ( ! $terms )
		{
			return array();
		}//end if

		$facets = array();

		foreach ( $terms as $term )
		{
			// if the facet for this taxonomy doesn't exist, let's skip it
			if ( ! isset( $this->facets()->_tax_to_facet[ $term->taxonomy ] ) )
			{
				continue;
			}//end if

			$facet_name = $this->facets()->_tax_to_facet[ $term->taxonomy ];

			if ( ! isset( $facets[ $facet_name ] ) )
			{
				$facets[ $facet_name ] = array();
			}//end if

			$facets[ $facet_name ][ $term->slug ] = $term;
		}//end foreach

		return (object) $facets;
	}//end get_terms_as_facets
}// end class



function scriblio()
{
	global $scriblio;

	if( ! $scriblio )
	{
		$scriblio = new Scriblio;
	}

	return $scriblio;
} // end scriblio



function facets()
{
	_deprecated_function( __FUNCTION__, '3.2', 'scriblio()->facets()' );

	global $facets;

	if( ! $facets )
	{
		$facets = scriblio()->facets();
	}

	return $facets;
} // end facets



function scrib_register_facet( $name, $type, $args = array() )
{
	_deprecated_function( __FUNCTION__, '3.2', 'scriblio()->register_facet( $name, $type, $args )' );

	scriblio()->register_facet( $name, $type, $args );
}
