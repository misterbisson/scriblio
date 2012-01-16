<?php
class Scrib_Suggest
{

	function __construct()
	{
		// establish web path to this plugin's directory
		$this->path_web = plugins_url( str_replace( WP_PLUGIN_DIR , '' , dirname( __FILE__ )) );

		wp_register_script( 'scrib-suggest', $this->path_web . '/js/jquery.scribsuggest.js', array('jquery'), '20081030' );
		wp_enqueue_script( 'scrib-suggest' );

		wp_register_style( 'scrib-suggest', $this->path_web .'/css/suggest.css' );
		wp_enqueue_style( 'scrib-suggest' );

		// handle requests for suggestions
		if ( isset( $_GET['scrib_suggest'] ) )
			add_action( 'init' , array( $this , 'the_suggestions' ));

		// insert the JS in the footer
		add_action( 'wp_footer' , array( $this , 'footer_js' ));
	}



	// insert the JS that activates the suggestions
	function footer_js()
	{
		// @TODO: make this a configurable option
		$searchprompt = get_search_query() ? get_search_query() : 'Go Fish!';
?>
	<script type="text/javascript">
		jQuery(function() {
			jQuery('input[name="s"]').addClass( "scrib-search" );
			jQuery("input.scrib-search").scribsuggest( "<?php echo site_url('/index.php?scrib_suggest=go'); ?>" );
			jQuery("input.scrib-search").attr( "placeholder" , "<?php echo $searchprompt; ?>" );
		});
	</script>
<?php
		// @TODO: this piece used to insert search word highlighting JS, but that depended on code in bSuite
	}



	// output suggestions
	function the_suggestions()
	{
		$suggestion = $this->get_suggestions( $_REQUEST['q'] , $_GET['tax'] );

		@header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		echo implode( (array) $suggestion , "\n" );

		die;
	}


	// generate suggestions
	function get_suggestions( $s = '' , $taxonomy = array() )
	{
		// get and validate the search string
		$s = trim( $s );
		if ( strlen( $s ) < 1 )
			return FALSE; // require 2 chars for matching

		// identify which taxonomies we're searching
		if ( isset( $taxonomy ))
		{
			if( is_string( $taxonomy ))
				$taxonomy = explode( ',' , $_GET['tax'] );

			$taxonomy = array_filter( array_map( 'taxonomy_exists' , array_map( 'trim', $taxonomy )));
		}
		else
		{
			// @TODO: this used to be configurable in the dashboard.
			$taxonomy = get_taxonomies( array( 'public' => true ));
		}

		// generate a key we can use to cache these results
		$cachekey = md5( $s . implode( $taxonomy ));

		// get results from the cache or generate them fresh if necessary
		if( ! $suggestion = wp_cache_get( $cachekey , 'scrib_suggest' ))
		{
			global $wpdb , $facets;

			$terms = $wpdb->get_results( "
				SELECT t.term_id , t.name , tt.taxonomy , tt.count , ( ( 100 - t.len ) * tt.count ) AS hits
				FROM
				(
					SELECT term_id, name, LENGTH(name) AS len
					FROM $wpdb->terms
					WHERE slug LIKE ('" . sanitize_title( $s ) . "%')
					ORDER BY len ASC
					LIMIT 100
				) t
				JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy IN('" . implode( "','", $taxonomy ). "')
				AND tt.count > 0
				ORDER BY hits DESC
				LIMIT 25;
			");

			// get post titles beginning with the search term
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID
				FROM $wpdb->posts
				WHERE 1=1
				AND post_name LIKE %s
				AND post_status = 'publish'
				ORDER BY post_title ASC
				LIMIT 25;
			", sanitize_title( $s ) .'%' ));

			// init the result vars
			$searchfor = $suggestion = $beginswith = array();

			// create a default suggestion to do a keyword search for the term
			$searchfor[] = 'Search for "<a href="'. get_search_link( $s ) .'">'. esc_html( $s ) .'</a>"';

			// create suggestions for the matched taxonomies
			$template = '<span class="taxonomy_name">%%taxonomy%%</span> <a href="%%link%%" title="%%link_title%%">%%term%%</a>';
			foreach( (array) $terms as $term )
			{
				$suggestion[] = str_replace( 
					array( '%%term%%','%%taxonomy%%','%%link%%','%%link_title%%') , 
					array( 
						$term->name , 
						get_taxonomy( $term->taxonomy )->labels->singular_name , 
						$facets->permalink( $facets->_tax_to_facet[ $term->taxonomy ] , get_term( $term->term_id , $term->taxonomy ) ) ,
						$term->count .' items',
					) , 
					$template 
				);

			}

			// create suggestions for each matched post
			foreach( (array) $posts as $post )
			{
				$beginswith[] = 'Go to: <a href="'. get_permalink( $post->ID ) .'">'. attribute_escape( get_the_title( $post->ID )) .'</a>';
			}


			$suggestion = array_merge( $searchfor , array_slice( $suggestion, 0, 10 ) , array_slice( $beginswith , 0, 10 ));
//			wp_cache_set( $cachekey , $suggestion , 'scrib_suggest' , 126000 );
		}

		return $suggestion;
	}
}

new Scrib_Suggest;