<?php
class Authority_Posttype {

	var $id_base = 'scrib-authority';
	var $post_type_name = 'scrib-authority';
	var $post_meta_key = 'scrib-authority';

	function __construct()
	{
		add_action( 'init' , array( $this, 'register_post_type' ) , 11 );
		add_filter( 'template_redirect', array( $this, 'template_redirect' ) , 1 );
		add_action( 'save_post', array( $this , 'save_post_meta' ));
	}
	
	function template_redirect()
	{
		global $wp_query;

		if( ! ( $wp_query->is_tax || $wp_query->is_tag || $wp_query->is_category ))
			return;

		// get the details about the queried term
		$queried_object = $wp_query->get_queried_object();

		// query to find a matching authority record
		$query = array(
			'numberposts' => 1,
			'post_type' => $this->post_type_name,
			'tax_query' => array(
				array(
					'taxonomy' => $queried_object->taxonomy,
					'field' => 'id',
					'terms' => $queried_object->term_id,
				)
			)
		);

		// if we have an authority record, possibly redirect
		if( $authority = get_posts( $query ))
		{
			// get the authoritative term info
			$authority_meta = $this->get_post_meta( $authority[0]->ID );

			// don't attempt to redirect requests for the authoritative term
			if( $queried_object->term_taxonomy_id == $authority_meta['primary-term']->term_taxonomy_id )
				return;

			// we're on an alias term, redirect
			wp_redirect( get_term_link( (int) $authority_meta['primary-term']->term_id , $authority_meta['primary-term']->taxonomy ));
			die;
		}
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
		$primary_term = get_term_by( 'slug' , $new_instance['primary-termname'] , $new_instance['primary-tax'] );
		if( isset( $primary_term->term_taxonomy_id ))
		{
			$instance['primary-term'] = $primary_term;
			$instance['primary-tax'] = $primary_term->taxonomy;
			$instance['primary-termname'] = $primary_term->name;

			$object_terms[ $primary_term->taxonomy ][] = $primary_term->slug;

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
		$aliases_blob = array_map( 'trim' , (array) explode( ',' , $new_instance['alias-terms'] ));
		if( count( (array) $aliases_blob ))
			$instance['alias-terms'] = array();
		foreach( (array) $aliases_blob as $alias )
		{
			$parts = array_map( 'trim' , (array) explode( ':' , $alias ));

			if( 'tag' == $parts[0] )
				$parts[0] = 'post_tag';

			if( $alias_term = get_term_by( 'slug' , $parts[1] , $parts[0] ))
			{
				$instance['alias-terms'][] = $alias_term;
				$object_terms[ $alias_term->taxonomy ][] = $alias_term->slug;
			}
			else
			{
				if(( $new_term = wp_insert_term( $parts[1] , $parts[0] )) && is_array( $new_term ))
				{
					$new_term = get_term_by( 'id' , $parts[1] , $new_term['term_id'] );
					$instance['alias-terms'][] = $new_term;
					$object_terms[ $alias_term->taxonomy ][] = $new_term->slug;
				}
			}
		}

print_r( $instance );
//$this->migrate_alias_terms( $instance['alias-terms'] , $instance['primary-term'] );
//die;

		// save it
		update_post_meta( $post_id , $this->post_meta_key , $instance );

		foreach( (array) $object_terms as $k => $v )
			wp_set_object_terms( $post_id , $v , $k , FALSE );
	}

	function metab_primary_term( $post )
	{
		$this->nonce_field();

		$this->get_post_meta( $post->ID );
		$this->control_taxonomies( 'primary-tax' );
?>
		<label class="screen-reader-text" for="<?php echo $this->get_field_id( 'primary-termname' ); ?>">Primary term</label><input type="text" name="<?php echo $this->get_field_name( 'primary-termname' ); ?>" tabindex="x" id="<?php echo $this->get_field_id( 'primary-termname' ); ?>" placeholder="Authoritative term" value="<?php echo $this->instance['primary-termname']; ?>"/>

		<p>@TODO: in addition to automatically suggesting terms (and their taxonomy), we'll have to check that the term is not already associated with another authority record.</p>
<?php
	}

	function metab_alias_terms( $post )
	{

		$aliases = array();
		foreach( $this->instance['alias-terms'] as $term )
			$aliases[ $term->term_taxonomy_id ] = $term->taxonomy .':'. $term->slug;
?>
		<label class="screen-reader-text" for="<?php echo $this->get_field_id( 'alias-terms' ); ?>">Alias terms</label><textarea rows="1" cols="40" name="<?php echo $this->get_field_name( 'alias-terms' ); ?>" id="<?php echo $this->get_field_id( 'alias-terms' ); ?>"><?php echo implode( ', ' , (array) $aliases ); ?></textarea>

<p>There's supposed to be a neat term entry area here that supports all taxonomies with predictive entry.</p>
<p>An example set of alias terms for the term company:Apple Inc. might include company:Apple Computer, company:AAPL, company:Apple, tag:Apple Computer</p>
<p>Tech requirement: in addition to automatically suggesting terms (and their taxonomy), we'll have to check that the term is not already associated with another authority record.</p>
<?php
	}

	function metab_family_terms( $post )
	{
?>
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


	function migrate_parallel_terms( $old_tax , $new_tax )
	{
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

	function migrate_alias_terms( $_old_terms , $new_term )
	{

		// just confirm that the authority term exists
		if( ! term_exists( (int) $new_term->term_id , $new_term->taxonomy ))
			return FALSE;

		// check that the terms exist, and they're not the same as the authority term
		foreach( $_old_terms as $term )
		{
			if( term_exists( (int) $term->term_id , $term->taxonomy ) && ! ( $term->term_taxonomy_id == $new_term->term_taxonomy_id ))
				$old_terms[ $term->taxonomy ][] = (int) $term->term_id;
		}

		// iterate over each term and get the matching post IDs
		foreach( (array) $old_terms as $old_tax => $old_term_ids )
			$post_ids = array_merge( (array) $post_ids , (array) get_objects_in_term( $old_term_ids , $old_tax ));

		echo "<ol>";
		foreach( (array) $post_ids as $post_id )
		{
			wp_set_object_terms( $post_id , $new_term->taxonomy , (int) $new_term->term_id , TRUE );
			echo "<li>Updated <a href='". get_edit_post_link( $post_id ) ."'>$post_id</a> with $new_term->term_id</li>";
		}
		echo "</ol>";
	}


}//end Authority_Posttype class
new Authority_Posttype;
