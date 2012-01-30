<?php
class Authority_Posttype {

	var $id_base = 'scrib-authority';
	var $post_type_name = 'scrib-authority';
	var $post_meta_key = 'scrib-authority';
	var $cache_ttl = 259183; // a prime number slightly less than 3 days

	function __construct()
	{
		add_action( 'init' , array( $this, 'register_post_type' ) , 11 );
		add_filter( 'template_redirect', array( $this, 'template_redirect' ) , 1 );
		add_action( 'wp_ajax_scrib_enforce_authority', array( $this, 'enforce_authority_on_corpus_ajax' ));
		add_action( 'save_post', array( $this , 'save_post_meta' ));
		add_action( 'save_post', array( $this , 'enforce_authority_on_object' ) , 9 );
	}

	// WP has no convenient method to delete a single term from an object, but this is what's used in wp-includes/taxonomy.php
	function delete_terms_from_object_id( $object_id , $delete_terms )
	{
		global $wpdb;
		$in_delete_terms = "'". implode( "', '", $delete_terms ) ."'";
		do_action( 'delete_term_relationships', $object_id, $delete_terms );
		$wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id IN ( $in_delete_terms )" , $object_id ));
		do_action( 'deleted_term_relationships', $object_id, $delete_terms );
		wp_update_term_count( $delete_terms , $taxonomy_info->name );

		update_post_cache( get_post( $object_id ));

		return;
	}

	function get_term_by_ttid( $tt_id )
	{
		global $wpdb;

		$term_id_and_tax = $wpdb->get_row( $wpdb->prepare( "SELECT term_id , taxonomy FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d LIMIT 1" , $tt_id ) , OBJECT );

		return get_term( (int) $term_id_and_tax->term_id , $term_id_and_tax->taxonomy );
	}

	function delete_term_authority_cache( $term )
	{

		// validate the input
		if( ! isset( $term->term_taxonomy_id ))
			return FALSE;

		wp_cache_delete( $term->term_taxonomy_id , 'scrib_authority_ttid' );
	}

	function get_term_authority( $term )
	{

		// validate the input
		if( ! isset( $term->term_id , $term->taxonomy , $term->term_taxonomy_id ))
			return FALSE;

		if( $return = wp_cache_get( $term->term_taxonomy_id , 'scrib_authority_ttid____' ))
			return $return;
			
		// query to find a matching authority record
		$query = array(
			'numberposts' => 1,
			'post_type' => $this->post_type_name,
			'tax_query' => array(
				array(
					'taxonomy' => $term->taxonomy,
					'field' => 'id',
					'terms' => $term->term_id,
				)
			)
		);

		// fetch the authority info
		if( $authority = get_posts( $query ))
		{
			// get the authoritative term info
			$authority_meta = $this->get_post_meta( $authority[0]->ID );

			// initialize the return value
			$return = array(
				'primary_term' => '',
				'alias_terms' => '',
				'parent_terms' => '',
				'child_terms' => '',
			);

			$return = array_intersect_key( (array) $authority_meta , $return );
			$return['post_id'] = $authority[0]->ID;

			wp_cache_set( $term->term_taxonomy_id , (object) $return , 'scrib_authority_ttid' , $this->cache_ttl );
			return (object) $return;
		}

		// no authority records
		return FALSE;
	}

	function template_redirect()
	{
		global $wp_query;

		if( ! ( $wp_query->is_tax || $wp_query->is_tag || $wp_query->is_category ))
			return;

		// get the details about the queried term
		$queried_object = $wp_query->get_queried_object();

		// if we have an authority record, possibly redirect
		if( $authority = $this->get_term_authority( $queried_object ))
		{
			// don't attempt to redirect requests for the authoritative term
			if( $queried_object->term_taxonomy_id == $authority->primary_term->term_taxonomy_id )
				return;

			// we're on an alias term, redirect
			wp_redirect( get_term_link( (int) $authority->primary_term->term_id , $authority->primary_term->taxonomy ));
			die;
		}
	}

	function parse_terms_from_string( $text )
	{
		$terms = array();
		$blob = array_map( 'trim' , (array) explode( ',' , $text ));
		if( count( (array) $blob ))
		{
			foreach( (array) $blob as $blobette )
			{
				$parts = array_map( 'trim' , (array) explode( ':' , $blobette ));
	
				if( 'tag' == $parts[0] ) // parts[0] is the taxonomy
					$parts[0] = 'post_tag';

				// find or insert the term
				if( $term = get_term_by( 'slug' , $parts[1] , $parts[0] ))
				{
					$terms[] = $term;
				}
				else
				{
					// attempt to re-use existing terms when creating terms in new taxonomies
					if( $term_name = term_exists( $parts[1] ))
						$term_name = (int) $term_name;
					else
						$term_name = $parts[1];

					// insert the new term
					if(( $_new_term = wp_insert_term( $term_name , $parts[0] )) && is_array( $_new_term ))
					{
						$new_term = $this->get_term_by_ttid( $_new_term['term_taxonomy_id'] );
						$terms[] = $new_term;
					}
				}
			}
		}

		return $terms;
	}

	function nonce_field()
	{
		wp_nonce_field( plugin_basename( __FILE__ ) , $this->id_base .'-nonce' );
	}

	function verify_nonce()
	{
		return wp_verify_nonce( $_POST[ $this->id_base .'-nonce' ] , plugin_basename( __FILE__ ));
	}

	function get_field_name( $field_name )
	{
		return $this->id_base . '[' . $field_name . ']';
	}

	function get_field_id( $field_name )
	{
		return $this->id_base . '-' . $field_name;
	}

	function get_post_meta( $post_id )
	{
		$this->instance = get_post_meta( $post_id , $this->post_meta_key , TRUE );
		return $this->instance;
	}

	function save_post_meta( $post_id )
	{
		// check that this isn't an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
		  return;

		// check the nonce
		if( ! $this->verify_nonce() )
			return;

		// check the permissions
		if( ! current_user_can( 'edit_post' , $post_id ))
			return;	

		// get the old data
		$instance = $this->get_post_meta( $post_id );

		// process the new data
		$new_instance = stripslashes_deep( $_POST[ $this->id_base ] );

		$object_terms = array();

		// primary (authoritative) taxonomy term
		$primary_term = get_term_by( 'slug' , $new_instance['primary_termname'] , $new_instance['primary_tax'] );
		if( isset( $primary_term->term_taxonomy_id ))
		{
			$instance['primary_term'] = $primary_term;
			$instance['primary_tax'] = $primary_term->taxonomy;
			$instance['primary_termname'] = $primary_term->name;

			$object_terms[ $primary_term->taxonomy ][] = (int) $primary_term->term_id;

			// clear the authority cache for this term
			$this->delete_term_authority_cache( $primary_term );

			// updating the post title is a pain in the ass, just look at what happens when we try to save it
			$post = get_post( $post_id );
			$post->post_title = $primary_term->name;
			if( ! preg_match( '/^'. $primary_term->slug .'/', $post->post_name ))
				$post->post_name = $primary_term->slug;

			// remove the action before attempting to save the post, then reinstate it
			remove_action( 'save_post', array( $this , 'save_post_meta' ));
			wp_insert_post( $post );
			add_action( 'save_post', array( $this , 'save_post_meta' ));
		}

		// alias terms
		foreach( (array) $this->parse_terms_from_string( $new_instance['alias_terms'] ) as $term )
		{
				// don't insert the primary term as an alias, that's just silly
				if( $term->term_taxonomy_id == $instance['primary_term']->term_taxonomy_id )
					continue;

				$instance['alias_terms'][] = $term;
				$object_terms[ $term->taxonomy ][] = (int) $term->term_id;
				$this->delete_term_authority_cache( $term );
		}

		// parent terms
		foreach( (array) $this->parse_terms_from_string( $new_instance['parent_terms'] ) as $term )
		{
				// don't insert the primary term as a parent, that's just silly
				if( $term->term_taxonomy_id == $instance['primary_term']->term_taxonomy_id )
					continue;

				$instance['parent_terms'][] = $term;
		}

		// child terms
		foreach( (array) $this->parse_terms_from_string( $new_instance['child_terms'] ) as $term )
		{
				// don't insert the primary term as a child, that's just silly
				if( $term->term_taxonomy_id == $instance['primary_term']->term_taxonomy_id )
					continue;

				$instance['child_terms'][] = $term;
		}

//print_r( $instance );
//$this->migrate_alias_terms( $instance['alias_terms'] , $instance['primary_term'] );
//die;

		// save it
		update_post_meta( $post_id , $this->post_meta_key , $instance );

		// update the term relationships for this post (add the primary and alias terms)
		foreach( (array) $object_terms as $k => $v )
			wp_set_object_terms( $post_id , $v , $k , FALSE );
	}

	function metab_primary_term( $post )
	{
		$this->nonce_field();

		$this->get_post_meta( $post->ID );
		$this->control_taxonomies( 'primary_tax' );
?>
		<label class="screen-reader-text" for="<?php echo $this->get_field_id( 'primary_termname' ); ?>">Primary term</label><input type="text" name="<?php echo $this->get_field_name( 'primary_termname' ); ?>" tabindex="x" id="<?php echo $this->get_field_id( 'primary_termname' ); ?>" placeholder="Authoritative term" value="<?php echo $this->instance['primary_termname']; ?>"/>

		<p>@TODO: in addition to automatically suggesting terms (and their taxonomy), we'll have to check that the term is not already associated with another authority record.</p>
<?php
	}

	function metab_alias_terms( $post )
	{

		$aliases = array();
		foreach( (array) $this->instance['alias_terms'] as $term )
			$aliases[ $term->term_taxonomy_id ] = $term->taxonomy .':'. $term->slug;
?>
		<label class="screen-reader-text" for="<?php echo $this->get_field_id( 'alias_terms' ); ?>">Alias terms</label><textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'alias_terms' ); ?>" id="<?php echo $this->get_field_id( 'alias_terms' ); ?>"><?php echo implode( ', ' , (array) $aliases ); ?></textarea>

<p>There's supposed to be a neat term entry area here that supports all taxonomies with predictive entry.</p>
<p>An example set of alias terms for the term company:Apple Inc. might include company:Apple Computer, company:AAPL, company:Apple, tag:Apple Computer</p>
<p>Tech requirement: in addition to automatically suggesting terms (and their taxonomy), we'll have to check that the term is not already associated with another authority record.</p>
<?php
	}

	function metab_family_terms( $post )
	{
		$parents = array();
		foreach( (array) $this->instance['parent_terms'] as $term )
			$parents[ $term->term_taxonomy_id ] = $term->taxonomy .':'. $term->slug;

		$children = array();
		foreach( (array) $this->instance['child_terms'] as $term )
			$children[ $term->term_taxonomy_id ] = $term->taxonomy .':'. $term->slug;

?>
		<label for="<?php echo $this->get_field_id( 'parent_terms' ); ?>">Parent terms</label><textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'parent_terms' ); ?>" id="<?php echo $this->get_field_id( 'parent_terms' ); ?>"><?php echo implode( ', ' , (array) $parents ); ?></textarea>

		<label for="<?php echo $this->get_field_id( 'child_terms' ); ?>">Child terms</label><textarea rows="3" cols="50" name="<?php echo $this->get_field_name( 'child_terms' ); ?>" id="<?php echo $this->get_field_id( 'child_terms' ); ?>"><?php echo implode( ', ' , (array) $children ); ?></textarea>

<p>This area is where we'll relate this term to others that are broader or narrower.</p>
<p>Broader terms for product:iPhone might include company:Apple Inc., product:iOS Devices, product:smartphones.</p>
<p>Narrower terms for product:iPhone might include product:iPhone 4, product:iPhone 4S.</p>
<?php
	}

	function metaboxes()
	{
		// add metaboxes
		add_meta_box( 'scrib-authority-primary' , 'Authoritive Term' , array( $this , 'metab_primary_term' ) , 'scrib-authority' , 'normal', 'high' );
		add_meta_box( 'scrib-authority-alias' , 'Alias Terms' , array( $this , 'metab_alias_terms' ) , 'scrib-authority' , 'normal', 'high' );
		add_meta_box( 'scrib-authority-family' , 'Family Terms' , array( $this , 'metab_family_terms' ) , 'scrib-authority' , 'normal', 'high' );
		// @TODO: need metaboxes for links and arbitrary values (ticker symbol, etc)

		// remove the taxonomy metaboxes so we don't get confused
		$taxonomies = get_taxonomies( array( 'public' => true ) , 'objects' );
		foreach( $taxonomies as $taxomoy )
		{
			if( $taxomoy->hierarchical )
				remove_meta_box( $taxomoy->name .'div' , 'scrib-authority' , FALSE );
			else
				remove_meta_box( 'tagsdiv-'. $taxomoy->name , 'scrib-authority' , FALSE );
		}
	}

	function control_taxonomies( $field_name )
	{
		$taxonomies = get_taxonomies( array( 'public' => true ) , 'objects' );
		ksort( $taxonomies );
?>
		<label class="screen-reader-text" for="<?php echo $this->get_field_id( $field_name ); ?>">Select taxonomy</label>
		<select name="<?php echo $this->get_field_name( $field_name ); ?>" id="<?php echo $this->get_field_id( $field_name ); ?>" class="widefat">
<?php
		foreach ( $taxonomies as $taxonomy )
			echo "\n\t<option value=\"". $taxonomy->name .'" '. selected( $this->instance[ $field_name ] , $taxonomy->name , FALSE ) .'>'. $taxonomy->labels->singular_name .'</option>';
?>
		</select>
<?php
	}

	function register_post_type()
	{
		$taxonomies = get_taxonomies( array( 'public' => true ));

		register_post_type( $this->post_type_name,
			array(
				'labels' => array(
					'name' => __( 'Authority Records' ),
					'singular_name' => __( 'Authority Record' ),
				),
				'supports' => array( 
					'title', 
					'excerpt', 
//					'editor',
					'thumbnail',
				),
				'register_meta_box_cb' => array( $this , 'metaboxes' ),
				'public' => TRUE,
				'taxonomies' => $taxonomies,
			)
		);
	}

	function enforce_authority_on_object( $object_id )
	{
		// nobody wants to set terms on a revision
		if( $actual_post = wp_is_post_revision( $object_id ))
			$object_id = $actual_post;

		if( ! $object_id )
			return;

		// get and check the post
		$post = get_post( $object_id );

		// don't mess with authority posts
		if( ! isset( $post->post_type ) || $this->post_type_name == $post->post_type )
			return;

		// get the terms to work with
		$terms = wp_get_object_terms( $object_id , get_taxonomies( array( 'public' => true )));

		$new_object_terms = $terms_to_delete = array();
		foreach( $terms as $term )
		{
			if( $authority = $this->get_term_authority( $term ))
			{
				// add the preferred term to list of terms to add to the object
				$new_object_terms[ $authority->primary_term->taxonomy ][] = (int) $authority->primary_term->term_id;

				// if the current term is not in the same taxonomy as the preferred term, list it for removal from the object
				if( $authority->primary_term->taxonomy != $term->taxonomy )
					$delete_terms[] = $term->term_taxonomy_id;

				// add any parent terms to the list as well
				foreach( (array) $authority->parent_terms as $parent )
					$new_object_terms[ $parent->taxonomy ][] = (int) $parent->term_id;
			}
		}

		// remove the alias terms that are not in primary taxonomy
		if( count( $delete_terms ))
			$this->delete_terms_from_object_id( $object_id , $delete_terms );

		// add the alias and parent terms to the object
		if( count( $new_object_terms ))
		{
			foreach( (array) $new_object_terms as $k => $v )
			{
				wp_set_object_terms( $object_id , $v , $k , TRUE );
			}

			update_post_cache( $post );

		}
	}

	function enforce_authority_on_corpus_ajax()
	{
		if( $_REQUEST['authority_post_id'] && $this->get_post_meta( (int) $_REQUEST['authority_post_id'] ))
			$this->enforce_authority_on_corpus( (int) $_REQUEST['authority_post_id'] );

		die;
	}

	function enforce_authority_on_corpus( $authority_post_id )
	{
		$authority = $this->get_post_meta( $authority_post_id );

		// section of terms to add to each post
		// create a list of terms to add to each post
		$add_terms = array();

		// add the primary term to all posts (yes, it's likely already attached to some posts)
		$add_terms[ $authority['primary_term']->taxonomy ][] = (int) $authority['primary_term']->term_id;

		// add parent terms to all posts (yes, they may already be attached to some posts)
		foreach( $authority['parent_terms'] as $term )
			$add_terms[ $term->taxonomy ][] = (int) $term->term_id;



		// section of terms to delete from each post
		// create a list of terms to delete from each post
		$delete_terms = array();

		// delete alias terms that are not in the same taxonomy as the primary term
		foreach( $authority['alias_terms'] as $term )
		{
			if( $term->taxonomy != $authority['primary_term']->taxonomy )
			{
				$delete_taxs[ $term->taxonomy ] = $term->taxonomy;
				$delete_tt_ids[] = (int) $term->term_taxonomy_id;
			}
		}



		// Section of terms to search by
		// create a list of terms to search for posts by
		$search_terms = array();

		// include the primary term among those used to fetch posts
		$search_terms[ $authority['primary_term']->taxonomy ][] = (int) $authority['primary_term']->term_id;

		// add alias terms in the list
		foreach( $authority['alias_terms'] as $term )
			$search_terms[ $term->taxonomy ][] = (int) $term->term_id;

		// construct the partial taxonomy query for each named taxonomy
		$tax_query = array( 'relation' => 'OR' );
		foreach( $search_terms as $k => $v )
		{
			$tax_query[] = array(
				'taxonomy' => $k,
				'field' => 'id',
				'terms' => $v,
				'operator' => 'IN',
			);
		}

		$post_types = get_post_types( array( 'public' => TRUE ));
		unset( $post_types[ $this->post_type_name ] );

		// construct a complete query
		$query = array(
			'posts_per_page' => -1,
			'post_type' => $post_types,
			'tax_query' => $tax_query,
			'fields' => 'ids',
		);

		// get all the posts
		$post_ids = get_posts( $query );

		if( ! count( $post_ids ))
			return FALSE;

		echo "<ol>";
		foreach( (array) $post_ids as $post_id )
		{

			// add all the terms, one taxonomy at a time
			foreach( (array) $add_terms as $k => $v )
				wp_set_object_terms( $post_id , $v , $k , TRUE );

			// get currently attached terms in preparation for deleting some of them
			$new_object_tt_ids = $delete_object_tt_ids = array();
			$new_object_terms = wp_get_object_terms( $post_id , $delete_taxs );
			foreach( $new_object_terms as $new_object_term )
				$new_object_tt_ids[] = $new_object_term->term_taxonomy_id;

			// actually delete any conflicting terms
			if( $delete_object_tt_ids = array_intersect( $new_object_tt_ids , $delete_tt_ids ))
				$this->delete_terms_from_object_id( $post_id , $delete_object_tt_ids );

			// rudimentary logging
			echo "<li>Updated <a href='". get_edit_post_link( $post_id ) ."'>". get_the_title( $post_id ) ."</a></li>";
		}
		echo "</ol>";
	}

	// find terms that exist in two named taxonomies, update posts that have the old terms to have the new terms, then delete the old term
	function migrate_parallel_terms( $old_tax , $new_tax )
	{

		/* 
		@TODO: this needs to create authority records for the terms it migrates to prevent the problem from continuing 
		*/

		global $wpdb;

		if( ! ( is_taxonomy( $old_tax ) && is_taxonomy( $new_tax )))
			return FALSE;

		$new_terms = $wpdb->get_col('SELECT term_id
			FROM wp_7_term_taxonomy
			WHERE taxonomy = "'. $new_tax .'"
			ORDER BY term_id
			'
		);
		
		$old_terms = $wpdb->get_col('SELECT term_id
			FROM wp_7_term_taxonomy
			WHERE taxonomy = "'. $old_tax .'"
			ORDER BY term_id
			'
		);

		$intersection = array_intersect( $new_terms , $old_terms );

		foreach( $intersection as $term_id )
		{
			echo "<ol>";
			foreach( (array) get_objects_in_term( $term_id , $old_tax ) as $object_id )
			{
				wp_set_object_terms( $object_id , $new_tax , (int) $term_id , FALSE );
				echo "<li>Updated <a href='". get_edit_post_link( $object_id ) ."'>$object_id</a> with $term_id</li>";
			}
			wp_delete_term( (int) $term_id , $old_tax );
			echo "<li>Deleted $term_id from $old_tax</li></ol>";
		}
	}

}//end Authority_Posttype class
new Authority_Posttype;
