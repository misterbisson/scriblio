<?php
class Scrib
{
	var $meditor_forms = array();

	public function __construct()
	{
		global $wpdb;

		// establish web path to this plugin's directory
		$this->path_web = plugins_url( plugin_basename( dirname( __FILE__ )));

		// register WordPress hooks
		register_activation_hook(__FILE__, array( &$this, 'activate' ));
		add_action('init', array(&$this, 'init'));

		add_action('wp_ajax_meditor_suggest_tags', array( &$this, 'meditor_suggest_tags' ));
		if ( isset( $_GET['scrib_suggest'] ) )
			add_action( 'init', array( &$this, 'suggest_search' ));

		add_action( 'admin_menu', array( $this, 'addmenus' ));

		add_shortcode('scrib_availability', array(&$this, 'shortcode_availability'));

		add_action( 'admin_menu' , array( $this, 'admin_menu_hook' ));

		add_action('save_post', array(&$this, 'meditor_save_post'), 2, 2);
		add_filter('pre_post_title', array(&$this, 'meditor_pre_save_filters'));
		add_filter('pre_post_excerpt', array(&$this, 'meditor_pre_save_filters'));
		add_filter('pre_post_content', array(&$this, 'meditor_pre_save_filters'));
	}

	public function get_tag_link( $tag )
	{
		if( $term = get_term_by( 'slug' , $tag['slug'] , $tag['taxonomy'] ))
			return scriblio()->facets()->permalink( scriblio()->facets()->_tax_to_facet[ $term->taxonomy ], $term );
		
		return '';
	}

	public function init()
	{
		global $wpdb;

		$this->harvest_table = $wpdb->prefix . 'scrib_harvest';

		$this->initial_articles = array( '/^a /i','/^an /i','/^da /i','/^de /i','/^the /i','/^ye /i' );
	}

	public function activate()
	{
		// check for and upgrade old options
		$this->options = get_option('scrib_opts');

		// upgrade old versions
		if( 290 > $this->options['version'] )
			$this->upgrade( $this->options['version'] );

	}

	public function upgrade( $version )
	{
		static $nonced = FALSE;
		if( $nonced )
			return;

		if( 290 > $version )
		{
			$old = get_option('scrib');

			if( is_array( $old ))
			{
				update_option( 'scrib_opts' , 
					array(
						'browseid' => absint( $old['browse_id'] ),
						'searchprompt' => 'Books, movies, music',
						'facetfound' => 1000,
					)
				);
				
				update_option( 'scrib_categories' ,
					array(
						'browse' => array( (string) absint( $old['catalog_category_id'] )),
						'hide' => array( (string) absint( $old['catalog_category_id'] )),
				));
				
				update_option( 'scrib_taxonomies' ,
					array(
						'name' => $old['taxonomies'],
						'search' => array_keys( $old['taxonomies'] ),
						'related' => $old['taxonomies_for_related'],
						'suggest' => $old['taxonomies_for_suggest'],
				));
	
				delete_option('scrib');
			}
		}

		// reload the new options and set the updated version number
		$this->options = get_option('scrib_opts');
		$this->options['version'] = 290;
		update_option( 'scrib_opts' , $this->options );

		$nonced = TRUE;
	}

	public function addmenus()
	{
		// register the meditor box in the post and page editors
		add_meta_box('scrib_meditor_div', __('Scriblio Metadata Editor'), array( $this, 'meditor_metabox' ), 'post', 'advanced', 'high');
		add_meta_box('scrib_meditor_div', __('Scriblio Metadata Editor'), array( $this, 'meditor_metabox' ), 'page', 'advanced', 'high');

		// register the settings
		register_setting( 'Scrib' , 'scrib_opts' , array( &$this , 'save_scrib_opts' ));
	}

	public function save_scrib_opts( $input )
	{
		$r['browseid'] = absint( $input['browseid'] );
		$r['searchprompt'] = wp_filter_nohtml_kses( $input['searchprompt'] );
		$r['facetfound'] = absint( $input['facetfound'] );

		return $r;
	}

	public function register_sort( $handle , $opts )
	{
		$this->methods_sort[ $handle ] = $opts;
	}

	public function sort_qvars( $vars )
	{
	    array_push( $vars, 'sort' , 'sortby' );
	    return $vars;
	}

	public function sort_defaults()
	{
		if( !empty( $this->search_terms['s'] ))
			$this->register_sort( 'relevance' , array( 'name' => 'Relevance' , 'order' => 'DESC' ));
		$this->register_sort( 'title' , array( 'name' => 'Title' , 'order' => 'ASC' ));
		$this->register_sort( 'date' , array( 'name' => 'Date' , 'order' => 'DESC' ));
	}

	public function sort_relevance( $order )
	{
		if( !empty( $this->search_terms['s'] ))
			array_unshift( $this->posts_orderby , 'scrib_b.score '. ( in_array( $order , array( 'ASC' , 'DESC' ) ) ? $order : 'DESC' ));
	}

	public function sort_title( $order )
	{
		global $wpdb;

		array_unshift( $this->posts_orderby , $wpdb->posts .'.post_name '. ( in_array( $order , array( 'ASC' , 'DESC' ) ) ? $order : 'ASC' ));
	}

	public function sort_date( $order )
	{
		global $wpdb;

		array_unshift( $this->posts_orderby , $wpdb->posts .'.post_date '. ( in_array( $order , array( 'ASC' , 'DESC' ) ) ? $order : 'ASC' ));
	}

	public function editsort()
	{
		global $wp_query;
//print_r( $wp_query );

		do_action( 'scrib_init_sort' );

		foreach( (array) $this->methods_sort as $handle => $method )
		{
			if( $handle == $wp_query->query_vars['sortby'] )
				$selected = 'class="selected"';

			echo '<li><a href="'. add_query_arg(array( 'sortby' => $handle , 'sort' => $method['order'])) .'" '. $selected .'>'. $method['name'] .'</a></li>';
		}
	}

	public function admin_menu_hook() {
		wp_register_style( 'scrib-editor', $this->path_web .'/css/editor.css' );
		wp_enqueue_style( 'scrib-editor' );

		wp_register_script( 'scrib-editor', $this->path_web . '/js/editor.js', array('jquery-ui-sortable'), '1' );
		wp_enqueue_script( 'scrib-editor' );

		wp_register_script( 'jquery-tabindex', $this->path_web . '/js/jquery.keyboard-a11y.js', array('jquery'), '1' );
		wp_enqueue_script( 'jquery-tabindex' );
	}










	public function meditor_metabox( )
	{
		global $post_ID;
		if( $post_ID && ( $data = get_post_meta( $post_ID, 'scrib_meditor_content', true )) ){
			if( is_string( $data ))
				$data = unserialize( $data );

			foreach( $data as $handle => $val )
				if( isset( $this->meditor_forms[ $handle ] ))
					$this->meditor_form( $handle, $this->meditor_forms[ $handle ], $val );

		}else if( isset( $this->meditor_forms[ $_GET['scrib_meditor_form'] ] )){
			$this->meditor_form( $_GET['scrib_meditor_form'], $this->meditor_forms[ $_GET['scrib_meditor_form'] ] );

		}else if( absint( $_GET['scrib_meditor_from'] ) && ( $data = get_post_meta( absint( $_GET['scrib_meditor_from'] ), 'scrib_meditor_content', true )) ){
			if( !empty( $_GET['scrib_meditor_add'] ))
				$data = apply_filters( 'scrib_meditor_add_'. preg_replace( '/[^a-z0-9]/i', '', $_GET['scrib_meditor_add'] ), $data, absint( $_GET['scrib_meditor_from'] ));
			foreach( $data as $handle => $val )
				if( isset( $this->meditor_forms[ $handle ] ))
					$this->meditor_form( $handle, $this->meditor_forms[ $handle ], $val );
		}
	}

	public function meditor_form( $handle, &$prototype, &$data = array() ) {
		add_action( 'admin_footer', array( &$this, 'meditor_footer_activatejs' ));

		echo '<ul id="scrib_meditor">';
		foreach( $prototype['_elements'] as $key => $val ){
			$val = is_array( $data[ $key ] ) ? $data[ $key ] : array( array() );
			echo '<li id="scrib_meditor-'. $handle .'-'. $key .'" class="fieldset_title">'.  ( $prototype['_elements'][ $key ]['_title'] ? '<h2>'. $prototype['_elements'][ $key ]['_title'] .'</h2>' : '' ) . ( $prototype['_elements'][ $key ]['_description'] ? '<div class="description">'. $prototype['_elements'][ $key ]['_description'] .'</div>' : '' ) .'<ul  class="fieldset_title'. ( $prototype['_elements'][ $key ]['_repeatable'] ? ' sortable">' : '">' );
			foreach( $val as $ordinal => $row )
				$this->meditor_form_sub( $handle, $prototype['_elements'][ $key ], $row, $key, $ordinal );
			echo '</ul></li>';
		}
		echo '</ul><p class="scrib_meditor_end" />';

		do_action( 'scrib_meditor_form_'. $handle );
	}

	public function meditor_form_sub( $handle, $prototype, $data, $fieldset, $ordinal ){
		static $tabindex = 1;

		echo '<li class="fieldset '. ( $prototype['_repeatable'] ? 'repeatable ' : '' ) . $handle .' '. $fieldset .'"><ul class="fieldset">';
		foreach( $prototype['_elements'] as $key => $val ){
			$id =  "scrib_meditor-$handle-$fieldset-$ordinal-$key";
			$name =  "scrib_meditor[$handle][$fieldset][$ordinal][$key]";

			$val = $data[ $key ] ? stripslashes( $data[ $key ] ) : $prototype['_elements'][ $key ]['_input']['_default'];

			echo '<li class="field '. $handle .' '. $fieldset .' '. $key .'">'. ( $prototype['_elements'][ $key ]['_title'] ? '<label for="'. $id .'">'. $prototype['_elements'][ $key ]['_title'] .'</label>' : '<br />');

			switch( $prototype['_elements'][ $key ]['_input']['_type'] ){

				case 'select':
					echo '<select name="'. $name .'" id="'. $id .'" tabindex="'. $tabindex .'">';
					foreach( $prototype['_elements'][ $key ]['_input']['_values'] as $selval => $selname )
						echo '<option '. ( $selval == $val ? 'selected="selected" ' : '' ) .'value="'. $selval .'">'. $selname .'</option>';
					echo '</select>';
					break;

				case 'checkbox':
					echo '<input type="checkbox" name="'. $name .'" id="'. $id .'" value="1"'. ( $val ? ' checked="checked"' : '' ) .'  tabindex="'. $tabindex .'" />';
					break;

				case 'textarea':
					echo '<textarea name="'. $name .'" id="'. $id .'"  tabindex="'. $tabindex .'">'. format_to_edit( $val ) .'</textarea>';
					break;

				case '_function':
					if( is_callable( $prototype['_elements'][ $key ]['_input']['_function'] ))
						call_user_func( $prototype['_elements'][ $key ]['_input']['_function'] , $val, $handle, $id, $name );
					else
						_e( 'the requested function could not be called' );
					break;

				case 'text':
				default:
					echo '<input type="text" name="'. $name .'" id="'. $id .'" value="'. format_to_edit( $val ) .'"  '. ( $prototype['_elements'][ $key ]['_input']['autocomplete'] == 'off' ? 'autocomplete="off"' : '' ) .' tabindex="'. $tabindex .'" />';

			}
			echo '</li>';

			if( isset( $prototype['_elements'][ $key ]['_suggest'] ) )
				$this->meditor_suggest_js[ $handle .'-'. $fieldset .'-'. $key ] = 'jQuery("#scrib_meditor-'. $handle .'-'. $fieldset .' li.'. $key .' input").suggest( "admin-ajax.php?action=meditor_suggest_tags&tax='. $prototype['_elements'][ $key ]['_suggest'] .'", { delay: 500, minchars: 2 } );';

			$tabindex++;
		}
		echo '</ul></li>';

	}

	public function meditor_add_related_commandlinks( $null, $handle ) {
		global $post_ID;
		if( $post_ID ){
			echo '<p id="scrib_meditor_addrelated">';
			foreach( $this->meditor_forms[ $handle ]['_relationships'] as $rkey => $relationship )
				echo '<a href="'. admin_url( 'post-new.php?scrib_meditor_add='. $rkey .'&scrib_meditor_from='. $post_ID ) .'">+ '. $relationship['_title'] .'</a> &nbsp; ';
			echo '</p>';
		}else{
			echo '<p id="scrib_meditor_addrelated_needsid">'. __( 'Save this record before attempting to add a related record.', 'scrib' ) .'</p>';
		}
	}

	public function meditor_save_post($post_id, $post) {
		if ( $post_id && is_array( $_REQUEST['scrib_meditor'] )){

			// make sure meta is added to the post, not a revision
			if ( $the_post = wp_is_post_revision( $post_id ))
				$post_id = $the_post;

			$record = is_array( get_post_meta( $post_id, 'scrib_meditor_content', true )) ? get_post_meta( $post_id, 'scrib_meditor_content', true ) : array();

			if( is_array( $_REQUEST['scrib_meditor'] )){
				foreach( $_REQUEST['scrib_meditor'] as $key => &$val )
					unset( $record[ $this->meditor_input->form_key ] );

				$record = $this->meditor_merge_meta( $record, $this->meditor_sanitize_input( $_REQUEST['scrib_meditor'] ));

				add_post_meta( $post_id, 'scrib_meditor_content', $record, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $record );

				do_action( 'scrib_meditor_save_record', $post_id, $record );
			}

/*
			foreach( $_REQUEST['scrib_meditor'] as $this->meditor_input->form_key => $this->meditor_input->form ){
				unset( $record[ $this->meditor_input->form_key ] );
				foreach( $this->meditor_input->form as $this->meditor_input->group_key => $this->meditor_input->group )
					foreach( $this->meditor_input->group as $this->meditor_input->iteration_key => $this->meditor_input->iteration )
						foreach( $this->meditor_input->iteration as $this->meditor_input->key => $this->meditor_input->val ){
							if( is_callable( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] )){
								$filtered = FALSE;

								$filtered = call_user_func( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] , stripslashes( $this->meditor_input->val ));

								if( !empty( $filtered ))
									$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $filtered );
							}else{
								if( !empty( $record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] ))
									$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $this->meditor_input->val );
							}
						}
			}

			add_post_meta( $post_id, 'scrib_meditor_content', $record, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $record );

			do_action( 'scrib_meditor_save_record', $post_id, $record );
*/
		}
	}

	public function meditor_merge_meta( $orig = array(), $new = array(), $nsourceid = FALSE ){

		$orig = apply_filters( 'scrib_meditor_premerge_old', $orig , $nsourceid );
		$new = apply_filters( 'scrib_meditor_premerge_new', $new , $nsourceid );

		if( $forms = array_intersect( array_keys( $orig ), array_keys( $new ))){
			$return = array();
			foreach( $forms as $form ){
				$sections = array_unique( array_merge( array_keys( $orig[ $form ] ), array_keys( $new[ $form ] )));

				foreach( $sections as $section ){
					// preserve the bits that are to be suppressed
					$suppress = array();
					foreach( $orig[ $form ][ $section ] as $key => $val )
						if( $val['suppress'] )
							$suppress[ $form ][ $section ][ $key ] = $val;

					// remove metadata that's sourced from the new sourceid
					if( $nsourceid )
						foreach( $orig[ $form ][ $section ] as $key => $val )
							if( isset( $val['src'] ) && ( $val['src'] == $nsourceid ))
								unset( $orig[ $form ][ $section ][ $key ] );

					$return[ $form ][ $section ] = $this->array_unique_deep( array_merge( count( $new[ $form ][ $section ] ) ? $new[ $form ][ $section ] : array() , count( $orig[ $form ][ $section ] ) ? $orig[ $form ][ $section ] : array() , $suppress ));
				}
			}

			if( $diff = array_diff( array_keys( $orig ), array_keys( $new ))){
				foreach( $diff as $form )
					$return[ $form ] = array_merge( is_array( $orig[ $form ] ) ? $orig[ $form ] : array(), is_array( $new[ $form ] ) ? $new[ $form ] : array() );
			}

			return( $return );

		}else{
			return( array_merge( is_array( $orig ) ? $orig : array(), is_array( $new ) ? $new : array() ));
		}
	}

	public function meditor_sanitize_input( &$input ){
		$record = array();
		foreach( $input as $this->meditor_input->form_key => $this->meditor_input->form ){
			foreach( $this->meditor_input->form as $this->meditor_input->group_key => $this->meditor_input->group )
				foreach( $this->meditor_input->group as $this->meditor_input->iteration_key => $this->meditor_input->iteration )
					foreach( $this->meditor_input->iteration as $this->meditor_input->key => $this->meditor_input->val ){
						if( is_callable( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] )){
							$filtered = FALSE;

							$filtered = call_user_func( $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_sanitize'] , stripslashes( $this->meditor_input->val ));

							if( !empty( $filtered ))
								$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $filtered );
						}else{
							if( !empty( $record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] ))
								$record[ $this->meditor_input->form_key ][ $this->meditor_input->group_key ][ $this->meditor_input->key ][ $this->meditor_input->iteration_key ][ $this->meditor_input->key ] = stripslashes( $this->meditor_input->val );
						}
					}
		}

		return apply_filters( 'scrib_meditor_sanitize_record', $record );
	}

	public function meditor_sanitize_month( $val ){
		if( !is_numeric( $val ) && !empty( $val )){
			if( strtotime( $val .' 2008' ))
				return( date( 'm', strtotime( $val .' 2008' )));
		}else{
			$val = absint( $val );
			if( $val > 0 &&  $val < 13 )
				return( $val );
		}
		return( FALSE );
	}

	public function meditor_sanitize_day( $val ){
		$val = absint( $val );
		if( $val > 0 &&  $val < 32 )
			return( $val );
		return( FALSE );
	}

	public function meditor_sanitize_selectlist( $val ){
		if( array_key_exists( $val, $this->meditor_forms[ $this->meditor_input->form_key ]['_elements'][ $this->meditor_input->group_key ]['_elements'][ $this->meditor_input->key ]['_input']['_values'] ))
			return( $val );
		return( FALSE );
	}

	public function meditor_sanitize_punctuation( $str )
	{
		// props to K. T. Lam of HKUST

		$str = html_entity_decode( $str );

/*
		//strip html entities, i.e. &#59;
		$htmlentity = '\&\#\d\d\;';
		$lead_htmlentity_pattern = '/^'.$htmlentity.'/';
		$trail_htmlentity_pattern = '/'.$htmlentity.'$/';
		$str = preg_replace($lead_htmlentity_pattern, '', preg_replace($trail_htmlentity_pattern, '', $str));
*/

		//strip ASCII punctuations
		$puncts = '\s\~\!\@\#\$\%\^\&\*\_\+\`\-\=\{\}\|\[\]\\\:\"\;\'\<\>\?\,\.\/';
		$lead_puncts_pattern = '/^['.$puncts.']+/';
		$trail_puncts_pattern = '/['.$puncts.']+$/';
		$str = preg_replace($trail_puncts_pattern, '', preg_replace($lead_puncts_pattern, '', $str));

		//strip repeated white space
		$puncts_pattern = '/[\s]+/';
		$str = preg_replace( $puncts_pattern, ' ', $str );

		//strip white space before punctuations
		$puncts_pattern = '/[\s]+([\~\!\@\#\$\%\^\&\*\_\+\`\-\=\{\}\|\[\]\\\:\"\;\'\<\>\?\,\.\/])+/';
		$str = preg_replace( $puncts_pattern, '\1', $str );

		//Strip ()
		$both_pattern = '/^[\(]([^\(|\)]+)[\)]$/';
		$trail_pattern = '/^([^\(]+)[\)]$/';
		$lead_pattern = '/^[\(]([^\)]+)$/';
		$str = preg_replace($lead_pattern, '\\1', preg_replace($trail_pattern,'\\1', preg_replace($both_pattern, '\\1', $str)));

		return $str;
	}

	public function meditor_sanitize_related( $val ){
		if( is_numeric( $val ) && get_permalink( absint( $val )) )
			return( absint( $val ) );

		if( $url = sanitize_url( $val) ){
			if( $post_id = url_to_postid( $url ) )
				return( $post_id );
			else
				return( $url );
		}

		return( FALSE );
	}

	public function meditor_strip_initial_articles( $content ) {
		// TODO: add more articles, such as those from here: http://www.loc.gov/marc/bibliographic/bdapndxf.html
		return( preg_replace( $this->initial_articles, '', $content ));
	}

	public function meditor_pre_save_filters( $content ) {
		if ( is_array( $_REQUEST['scrib_meditor'] )){
			switch( current_filter() ){
				case 'pre_post_title':
					return( apply_filters( 'scrib_meditor_pre_title', $content, $_REQUEST['scrib_meditor'] ));
					break;

				case 'pre_post_excerpt':
					return( apply_filters( 'scrib_meditor_pre_excerpt', $content, $_REQUEST['scrib_meditor'] ));
					break;

				case 'pre_post_content':
				default:
					return( apply_filters( 'scrib_meditor_pre_content', $content, $_REQUEST['scrib_meditor'] ));
			}
		}
		return( $content );
	}



	public function meditor_register_menus(){
		if( ( 'post-new.php' == basename( $_SERVER['PHP_SELF'] )) && ( isset( $_GET['posted'] ) ) && ( !isset( $_GET['scrib_meditor_add'] ) ) && ( $form = key( get_post_meta( $_GET['posted'], 'scrib_meditor_content', true )) ) ){
				$_GET['scrib_meditor_add'] = 'sibling';
				$_GET['scrib_meditor_from'] = $_GET['posted'];
				die( wp_redirect( admin_url( 'post-new.php' ) .'?'. http_build_query( $_GET ) ));
		}
	}

	public function meditor_register( $handle , $prototype ){
		add_action( 'admin_menu', array( &$this, 'meditor_register_menus' ));

		if( isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		$this->meditor_forms[ $handle ] = $prototype;
	}

	public function meditor_unregister( $handle ){
		if( !isset( $this->meditor_forms[ $handle ] ))
			return( FALSE );
		unset( $this->meditor_forms[ $handle ] );
	}

	public function meditor_footer_activatejs(){
?>
		<script type="text/javascript">
			scrib_meditor();
		</script>

		<script type="text/javascript">
			jQuery(function() {
				<?php echo implode( "\n\t\t\t\t", $this->meditor_suggest_js ) ."\n"; ?>
			});
		</script>
<?php
	}

	public function meditor_make_content_closable( $content ){
		return( '<div class="inside">'. $content .'</div>');
	}

	public function meditor_suggest_tags(){
		if ( isset( $_GET['tax'] )){
			$taxonomy = explode(',', $_GET['tax'] );
			$taxonomy = array_filter( array_map( 'sanitize_title', array_map( 'trim', $taxonomy )));
		}else{
			$taxonomy = $this->taxonomies_for_suggest;
		}

		$s = sanitize_title( trim( $_REQUEST['q'] ));
		if ( strlen( $s ) < 2 )
			$s = '';

		$cachekey = md5( $s . implode( $taxonomy ));

		if( !$suggestion = wp_cache_get( $cachekey , 'scrib_suggest_meditor' )){
			if ( empty( $s ) ){
				foreach( get_terms( $taxonomy, array( 'number' => 25, 'orderby' => 'count', 'order' => 'DESC' ) ) as $term )
					$suggestion[] = $term->name;

				$suggestion = implode( $suggestion, "\n" );
			}else{
				global $wpdb;

				$suggestion = implode( array_unique( $wpdb->get_col( "SELECT t.name, ((( 100 - t.len ) + 1 ) * tt.count ) AS hits
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
					LIMIT 11;
				")), "\n" );
			}

			wp_cache_set( $cachekey , $suggestion, 'scrib_suggest_meditor', 1800 );
		}

		echo $suggestion;
		die;
	}

	public function import_insert_harvest( &$bibr, $enriched = 0 ){
		global $wpdb;

		$wpdb->get_results("REPLACE INTO $this->harvest_table
			( source_id, harvest_date, imported, content, enriched )
			VALUES ( '". $wpdb->escape( $bibr['_sourceid'] ) ."', NOW(), 0, '". $wpdb->escape( serialize( $bibr )) ."', ". absint( $enriched ) ." )" );

		wp_cache_set( $bibr['_sourceid'], time() + 2500000, 'scrib_harvested', time() + 2500000 );
	}

	public function import_post_exists( $idnumbers ) {
		global $wpdb;

		$post_id = FALSE;
		$post_ids = $tt_ids = array();

		foreach( $idnumbers as $idnum )
			$tt_ids[] = get_term( (int) is_term( (string) $idnum['id'] ), $idnum['type'] );

		if( count( $tt_ids )){
			foreach( $tt_ids as $k => $tt_id )
				if( isset( $tt_id->term_taxonomy_id ))
					$tt_ids[ $k ] = (int) $tt_id->term_taxonomy_id;
				else
					unset( $tt_ids[ $k ] );

			if( !count( $tt_ids ))
				return( FALSE );

			$post_ids = $wpdb->get_col( "SELECT object_id, COUNT(*) AS hits
				FROM $wpdb->term_relationships
				WHERE term_taxonomy_id IN ('". implode( '\',\'', $tt_ids ) ."')
				GROUP BY object_id
				ORDER BY hits DESC
				LIMIT 100" );

			if( 1 < count( $post_ids )){
				// de-index the duplicate posts
				// TODO: what if they have comments? What if others have linked to them?
				$this->import_deindex_post( $post_ids );

//				usleep( 250000 ); // give the database a moment to settle
			}

			foreach( $post_ids as $post_id )
				if( get_post( $post_id ))
					return( $post_id );
		}

		return( FALSE );
	}

	public function import_deindex_post( $post_ids ){
		// sets a post's status to draft so that it no longer appears in searches
		// TODO: need to find a better status to hide it from searches,
		// but not invalidate incoming links or remove comments
		global $wpdb;

		foreach( (array) $post_ids as $post_id ){
			$post_id = absint( $post_id );
			if( !$post_id )
				continue;

			// set the post to draft (TODO: use a WP function instead of writing to DB)
			$wpdb->get_results( "UPDATE $wpdb->posts SET post_status = 'draft' WHERE ID = $post_id" );

			// clear the post/page cache
			clean_page_cache( $post_id );
			clean_post_cache( $post_id );

			// do the post transition
			wp_transition_post_status( 'draft', 'publish', $post_id );
		}
	}

	public function import_insert_post( $bibr ){
//		return(1);
		global $wpdb, $bsuite;

		if( !defined( 'DOING_AUTOSAVE' ) )
			define( 'DOING_AUTOSAVE', TRUE ); // prevents revision tracking
		wp_defer_term_counting( TRUE ); // may improve performance
		remove_filter( 'content_save_pre', array( &$bsuite, 'innerindex_nametags' )); // don't build an inner index for catalog records
		remove_filter( 'publish_post', '_publish_post_hook', 5, 1 ); // avoids pinging links in catalog records
		remove_filter( 'save_post', '_save_post_hook', 5, 2 ); // don't bother
//		kses_remove_filters(); // don't kses filter catalog records
		define( 'WP_IMPORTING', TRUE ); // may improve performance by preventing exection of some unknown hooks

		$postdata = array();
		if( $this->import_post_exists( $bibr['_idnumbers'] )){
			$postdata['ID'] = $this->import_post_exists( $bibr['_idnumbers'] );

			$oldrecord = get_post_meta( $postdata['ID'], 'scrib_meditor_content', true );


//TODO: setting post title and content at this point works, but it ignores the opportunity to merge data from the existing record.

			$postdata['post_title'] = apply_filters( 'scrib_meditor_pre_title', strlen( get_post_field( 'post_title', $postdata['ID'] )) ? get_post_field( 'post_title', $postdata['ID'] ) : $bibr['_title'], $bibr );

			$postdata['post_content'] = apply_filters( 'scrib_meditor_pre_content', strlen( get_post_field( 'post_content', $postdata['ID'] )) ? get_post_field( 'post_content', $postdata['ID'] ) : $bibr['_body'], $bibr );

			if( isset( $bibr['_acqdate'] ))
				$postdata['post_date'] =
				$postdata['post_date_gmt'] =
				$postdata['post_modified'] =
				$postdata['post_modified_gmt'] = strlen( get_post_field( 'post_date', $postdata['ID'] )) ? get_post_field( 'post_date', $postdata['ID'] ) : $bibr['_acqdate'];

			$postdata['post_author'] = 1 < get_post_field( 'post_author', $postdata['ID'] ) ? get_post_field( 'post_author', $postdata['ID'] ) : $bibr['_userid'];
		}else{

			$postdata['post_title'] = apply_filters( 'scrib_meditor_pre_title', $bibr['_title'], $bibr );

			$postdata['post_content'] = apply_filters( 'scrib_meditor_pre_content', $bibr['_body'], $bibr );

			if( isset( $bibr['_acqdate'] ))
				$postdata['post_date'] =
				$postdata['post_date_gmt'] =
				$postdata['post_modified'] =
				$postdata['post_modified_gmt'] = $bibr['_acqdate'];

			$postdata['post_author'] = $bibr['_userid'];
		}

		$postdata['comment_status'] = get_option('default_comment_status');
		$postdata['ping_status'] 	= get_option('default_ping_status');
		$postdata['post_status'] 	= 'publish';
		$postdata['post_type'] 		= 'post';

		if( isset( $bibr['_icon'] ))
			$the_icon = $bibr['_icon'];

		$nsourceid = $bibr['_sourceid'];
		$ncategory = $bibr['_category'];

		unset( $bibr['_title'] );
		unset( $bibr['_acqdate'] );
		unset( $bibr['_idnumbers'] );
		unset( $bibr['_sourceid'] );
		unset( $bibr['_icon'] );
		unset( $bibr['_category'] );
		unset( $bibr['_userid'] );

		$postdata['post_excerpt'] = '';

		if( empty( $postdata['post_title'] ))
			return( FALSE );

//echo "<h2>Pre</h2>";
//print_r( $bibr );
//die;

		// sanitize the input record
		$bibr = $this->meditor_sanitize_input( $bibr );

//echo "<h2>Sanitized</h2>";
//print_r( $bibr );

		// merge it with the old record
		if( is_array( $oldrecord ))
			$bibr = $this->meditor_merge_meta( $oldrecord, $bibr, $nsourceid );

//echo "<h2>Merged</h2>";
//print_r( $bibr );

//print_r( $postdata );

		$post_id = wp_insert_post( $postdata ); // insert the post
		if( $post_id )
		{

			if( ! empty( $ncategory ))
				wp_set_object_terms( $post_id, $ncategory , 'category', FALSE );

			add_post_meta( $post_id, 'scrib_meditor_content', $bibr, TRUE ) or update_post_meta( $post_id, 'scrib_meditor_content', $bibr );

			do_action( 'scrib_meditor_save_record', $post_id, $bibr );

			if( isset( $the_icon )){
				if( is_array( $the_icon ))
					add_post_meta( $post_id, 'bsuite_post_icon', $the_icon, TRUE ) or update_post_meta( $post_id, 'bsuite_post_icon', $the_icon );
				else if( is_string( $the_icon ))
					$bsuite->icon_resize( $the_icon, $post_id, TRUE );
			}


			return( $post_id );
		}
		return(FALSE);
	}

	public function import_harvest_tobepublished_count() {
		global $wpdb;
		return( $wpdb->get_var( 'SELECT COUNT(*) FROM '. $this->harvest_table .' WHERE imported = 0' ));
	}

	public function import_harvest_publish() {
		global $wpdb;

		$interval = 25;

		if( isset( $_GET[ 'n' ] ) == false ) {
			$n = 0;
		} else {
			$n = absint( $_GET[ 'n' ] );
		}

		$posts = $wpdb->get_results('SELECT * FROM '. $this->harvest_table .' WHERE imported = 0 ORDER BY enriched DESC LIMIT 0,'. $interval, ARRAY_A);

		if( is_array( $posts )) {
			echo "<p>Fetching records in batches of $interval...publishing them...making coffee. Please be patient.<br /><br /></p>";
			echo '<ol>';
			foreach( $posts as $post ) {
				set_time_limit( 900 );

				$r = array();
				$post_id = FALSE;

				$r = unserialize( $post['content'] );
				if( !count( $r ))
					continue;

				$post_id = $this->import_insert_post( $r );
				if( $post_id ){
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = 1, content = "" WHERE source_id = "'. $post['source_id'] .'"' );
					echo '<li><a href="'. get_permalink( $post_id ) .'" target="_blank">'. get_the_title( $post_id ) .'</a> from '. $post['source_id'] .'</li>';
					flush();
				}else{
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post['source_id'] .'"' );
					echo '<li>Failed to publish '. $post['source_id'] .'</li>';
				}
			}
			echo '</ol>';

			wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier

			?>
			<p><?php _e("If your browser doesn't start loading the next page automatically click this link:"); ?> <a href="?page=<?php echo plugin_basename( dirname( __FILE__ )); ?>/scriblio.php&command=<?php _e('Publish Harvested Records', 'Scriblio') ?>&n=<?php echo ( $n + $interval) ?>"><?php _e("Next Posts"); ?></a> </p>
			<script language='javascript'>
			<!--

			function nextpage() {
				location.href="?page=<?php echo plugin_basename( dirname( __FILE__ )); ?>/scriblio.php&command=<?php _e('Publish Harvested Records', 'Scriblio') ?>&n=<?php echo ( $n + $interval) ?>";
			}
			setTimeout( "nextpage()", 1250 );

			//-->
			</script>
			<?php
			echo '<p>'. $this->import_harvest_tobepublished_count() .' records remain to be published.</p>';
		} else {

			// update the term taxonomy counts
			$wpdb->get_results('
				UPDATE '. $wpdb->term_taxonomy .' tt
				SET tt.count = (
					SELECT COUNT(*)
					FROM '. $wpdb->term_relationships .' tr
					WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
				)'
			);

			echo '<p>That&#039;s all folks. kthnxbye.</p>';
		}

		echo '<pre>';
		print_r( $wpdb->queries );
		echo '</pre>';
		?><?php echo get_num_queries(); ?> queries. <?php timer_stop(1); ?> seconds. <?php
	}

	public function import_harvest_passive(){
		global $wpdb, $bsuite;

		if( !$bsuite->get_lock( 'scrib_harvest_passive' ))
			return( FALSE );

		$posts = $wpdb->get_results('SELECT * FROM '. $this->harvest_table .' WHERE imported = 0 ORDER BY enriched DESC LIMIT 25', ARRAY_A);

		if( is_array( $posts )) {
			foreach( $posts as $post ) {
				set_time_limit( 900 );

				$r = unserialize( $post['content'] );
				if( !is_array( $r ))
					continue;

				$post_id = $this->import_insert_post( $r );

				if( $post_id ){
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = 1, content = "" WHERE source_id = "'. $post['source_id'] .'"' );
				}else{
					$wpdb->get_var( 'UPDATE '. $this->harvest_table .' SET imported = -1 WHERE source_id = "'. $post['source_id'] .'"' );
				}
			}

			wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier

		}

		wp_defer_term_counting( FALSE ); // now update the term counts that we'd defered earlier
	}

	public function import_create_harvest_table()
	{
		global $wpdb, $bsuite;

		// create tables
		$charset_collate = '';
		if ( version_compare( mysql_get_server_info(), '4.1.0', '>=' )) {
			if ( ! empty( $wpdb->charset ))
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty( $wpdb->collate ))
				$charset_collate .= " COLLATE $wpdb->collate";
		}

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta("
			CREATE TABLE $this->harvest_table (
			source_id varchar(85) NOT NULL,
			harvest_date date NOT NULL,
			imported tinyint(1) default '0',
			content longtext NOT NULL,
			enriched tinyint(1) default '0',
			PRIMARY KEY  (source_id),
			KEY imported (imported),
			KEY enriched (enriched)
			) $charset_collate
		");

	}



	public function shortcode_availability( $arg ){
		// [scrib_availability sourceid="ll1292675"]

		$arg = shortcode_atts( array(
			'sourceid' => FALSE
		), $arg );

global $id, $scribiii_import;
return( $scribiii_import->iii_availability( $id, $arg['sourceid'] ));

		if( function_exists( 'scrib_availability' ) )
			return( scrib_availability( $arg['sourceid'] ));
		else
			return( '<span id="gbs_'. $this->the_gbs_id() .'" class="gbs_link"></span>' );
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

/* old, innefficient way:
			$results = $wpdb->get_results( "SELECT t.name, tt.taxonomy, LENGTH(t.name) AS len
				FROM $wpdb->term_taxonomy AS tt
				INNER JOIN $wpdb->terms AS t ON tt.term_id = t.term_id
				WHERE tt.taxonomy IN('" . implode( "','", $taxonomy ). "')
				AND t.slug LIKE ('" . $s . "%')
				ORDER BY len ASC, tt.count DESC
				LIMIT 50;
			");
*/

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
/*
		print_r( $wpdb->queries );
		echo get_num_queries(); ?> queries. <?php timer_stop(1); ?> seconds. <?php
*/
		die;
	}

	public function suggest_search_fixlong( $suggestion ){
		if( strlen( $suggestion )  > 54)
			return( $suggestion . '*');
		return( $suggestion );
	}


	public function array_unique_deep( $array ) {
		$uniquer = array();
		foreach( $array as $val ){
			$key = $val;
			if( is_array( $val )){
				if( isset( $key['src'] ))
					unset( $key['src'] );
				if( isset( $key['suppress'] ))
					unset( $key['suppress'] );
			}

			$uniquer[ md5( strtolower( serialize( $key ))) ] = $val;
		}
		return( array_values( $uniquer ));
	}

	public function make_utf8( $text ){
		if( function_exists( 'mb_convert_encoding' ))
			return( mb_convert_encoding( $text, 'UTF-8', 'LATIN1, ASCII, ISO-8859-1, UTF-8'));

		return( $text );
	}

	public function strip_cdata( $input ){
		return trim( preg_replace( '/<!\[CDATA\[(.+?)\]\]>/is', '\1', (string) $input ));
	}

}


// now instantiate this object
$scrib = new Scrib;

