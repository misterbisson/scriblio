<?php

/*
Code to do search suggestions:
*/

// in the class __construct() register this:
if ( isset( $_GET['scrib_suggest'] ) )
	add_action( 'init', array( $this, 'suggest_search' ));

// then this code
	add_action('wp_footer', array(&$this, 'wp_footer_js'));
	public function wp_footer_js(){
		$this->suggest_js();
	}

	public function suggest_js()
	{
		$searchprompt = $this->options['searchprompt'];

		if( isset( $this->search_terms['s'] ) && count( $this->search_terms['s'] ))
			$searchprompt = implode( ' ' , $this->search_terms['s'] );
?>
	<script type="text/javascript">
		jQuery(function() {
			jQuery("#s").addClass("scrib-search");

			jQuery("input.scrib-search").scribsuggest("<?php bloginfo('home'); ?>/index.php?scrib_suggest=go");

			jQuery("input.scrib-search").val("<?php echo $searchprompt; ?>")
<?php
		if( ! count( $this->search_terms['s'] )):
?>
			.focus(function(){
				if(this.value == "<?php echo $searchprompt; ?>") {
					this.value = '';
				}
			})
			.blur(function(){
				if(this.value == '') {
					this.value = "<?php echo $searchprompt; ?>";
				}
			});
<?php
		endif;

		if( count( $this->search_terms ))
		{
			foreach( $this->search_terms as $taxonomy )
				foreach( $taxonomy as $term )
					foreach( explode( ' ' , $term ) as $term_part )
						$all_terms[] = $this->meditor_sanitize_punctuation( $term_part );

			$all_terms = array_filter( $all_terms );

			if( count( $all_terms ))
			{
				echo "var scrib_search_terms = {terms:['". implode( "','" , array_map( 'htmlentities' , $all_terms )) ."']};";
				echo "jQuery(function(){bsuite_highlight(scrib_search_terms);});";
			}

		}
?>
		});
	</script>
<?php
	}

	public function suggest_search(){
		@header('Content-Type: text/html; charset=' . get_option('blog_charset'));

		$s = sanitize_title( trim( $_REQUEST['q'] ));
		if ( strlen( $s ) < 2 )
			die; // require 2 chars for matching

		if ( isset( $_GET['taxonomy'] )){
			$taxonomy = explode(',', $_GET['tax'] );
			$taxonomy = array_filter( array_map( 'sanitize_title', array_map( 'trim', $taxonomy )));
		}else{
			$taxonomy = $this->taxonomies_for_suggest;
		}

		$cachekey = md5( $s . implode( $taxonomy ));
		if(!$suggestion = wp_cache_get( $cachekey , 'scrib_suggest' )){
			global $wpdb;

			$terms = $wpdb->get_results( "SELECT t.name, tt.taxonomy, ( ( 100 - t.len ) * tt.count ) AS hits
				FROM
				(
					SELECT term_id, name, LENGTH(name) AS len
					FROM $wpdb->terms
					WHERE slug LIKE ('" . $s . "%')
					ORDER BY len ASC
					LIMIT 100
				) t
				JOIN $wpdb->term_taxonomy AS tt ON tt.term_id = t.term_id
				WHERE tt.taxonomy IN('" . implode( "','", $taxonomy ). "')
				AND tt.count > 0
				ORDER BY hits DESC
				LIMIT 25;
			");

			$posts = $wpdb->get_results( "SELECT ID, post_title
				FROM $wpdb->posts
				WHERE post_title LIKE '" . $s . "%'
				ORDER BY post_title ASC
				LIMIT 25;
			");

			$searchfor = $suggestion = $beginswith = array();
			$searchfor[] = 'Search for "<a href="'. $this->get_search_link( array( 's' => array( attribute_escape( $_REQUEST['q'] )))) .'">'. attribute_escape( $_REQUEST['q'] ) .'</a>"';
			$template = '<span class="taxonomy_name">%%taxonomy%%</span> <a href="%%link%%">%%term%%</a>';
			foreach( $terms as $term )
			{
				if('hint' == $term->taxonomy){
					$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $this->taxonomy_name['s'], $this->get_search_link(array('s' => array( $this->suggest_search_fixlong( $term->name ))))), $template);
				}else{
					$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $this->taxonomy_name[ $term->taxonomy ], $this->get_search_link(array($term->taxonomy => array( $this->suggest_search_fixlong( $term->name ))))), $template);

					$beginswith[ $term->taxonomy ] = $this->taxonomy_name[ $term->taxonomy ] .' begins with "<a href="'. $this->get_search_link( array( $term->taxonomy => array( $s .'*' ))) .'">'. attribute_escape( $_REQUEST['q'] ) .'</a>"';
 					}
			}

			foreach( $posts as $post )
			{
				$beginswith[ 'p'. $post->ID ] = 'Go to: <a href="'. get_permalink( $post->ID ) .'">'. attribute_escape( $post->post_title ) .'</a>';
			}


			$suggestion = array_merge( $searchfor, array_slice( $suggestion, 0, 10 ), $beginswith );
			wp_cache_set( $cachekey , $suggestion, 'scrib_suggest', 126000 );
		}

		echo implode($suggestion, "\n");

		die;
	}

	public function suggest_search_fixlong( $suggestion ){
		if( strlen( $suggestion )  > 54)
			return( $suggestion . '*');
		return( $suggestion );
	}

