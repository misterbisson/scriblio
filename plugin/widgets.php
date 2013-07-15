<?php

class Scrib_Facets_Widget extends WP_Widget
{

	function Scrib_Facets_Widget()
	{
		$this->WP_Widget( 'scriblio_facets', 'Scriblio Facets', array( 'description' => 'Displays facets related to the displayed set of posts' ));

		add_filter( 'wijax-actions' , array( $this , 'wijax_actions' ) );
	}

	function wijax_actions( $actions )
	{
		global $mywijax;

		$instances = get_option( 'widget_scriblio_facets' );

		foreach( $instances as $k => $v )
		{
			if( ! is_int( $k ))
				continue;

			$actions[ $mywijax->encoded_name( 'scriblio_facets-'. $k ) ] = (object) array( 'key' => 'scriblio_facets-'. $k , 'type' => 'widget');
		}

		return $actions;
	}

	function widget( $args, $instance )
	{
		global $mywijax;
		extract( $args );

		$title = apply_filters( 'widget_title' , empty( $instance['title'] ) ? '' : $instance['title'] );
		$orderby = ( in_array( $instance['orderby'], array( 'count', 'name', 'custom' )) ? $instance['orderby'] : 'name' );
		$order = ( in_array( $instance['order'], array( 'ASC', 'DESC' )) ? $instance['order'] : 'ASC' );

		// wijax requests get the whole thing
		if( TRUE || is_wijax() )
		{

			// configure how it's displayed
			$display_options = array(
				'smallest' => floatval( $instance['format_font_small'] ),
				'largest' => floatval( $instance['format_font_large'] ),
				'unit' => 'em',
				'number' => $instance['number'],
				'orderby' => $orderby,
				'order' => $order,
				'order_custom' => $instance['order_custom'],
			);

			// list and cloud specific display options
			if( 'list' == $instance['format'] )
			{
				$display_options['format'] = 'list';
			}
			else
			{
				$display_options['format'] = 'flat';
			}

			// select what's displayed
			if( 'corpus' == $instance['format_font_large'] )
			{
				$facet_list = scriblio()->facets()->facets->{$instance['facet']}->get_terms_in_corpus();
			}
			else if( is_singular() )
			{
				$facet_list = scriblio()->facets()->facets->{$instance['facet']}->get_terms_in_post( get_the_ID() );
			}
			else if( is_search() || scriblio()->facets()->is_browse() )
			{
				$facet_list = scriblio()->facets()->facets->{$instance['facet']}->get_terms_in_found_set();
				if( empty( $facet_list ))
					$facet_list = scriblio()->facets()->facets->{$instance['facet']}->get_terms_in_corpus();
			}
			else
			{
				$facet_list = scriblio()->facets()->facets->{$instance['facet']}->get_terms_in_corpus();
			}

 			if ( ! count( $facet_list ) )
			{
				return;
			}//end if

			// and now we wrap it all up for echo later
			$content = scriblio()->facets()->generate_tag_cloud( $facet_list , $display_options );
		}
		else
		{

			$wijax_source = trailingslashit( home_url( '/wijax/' . $mywijax->encoded_name( $this->id )));

			preg_match( '/<([\S]*)/' , $before_title , $title_element );
			$title_element = trim( (string) $title_element[1] , '<>');

			preg_match( '/class.*?=.*?(\'|")(.+?)(\'|")/' , $before_title , $title_class );
			$title_class = (string) $title_class[2];

			$varname_string = json_encode( array(
				'source' => $wijax_source ,
				'varname' => $mywijax->varname( $wijax_source ) ,
				'title_element' => $title_element ,
				'title_class' => $title_class ,
				'title_before' => rawurlencode( $before_title ),
				'title_after' => rawurlencode( $after_title ),
			));

			$content = '
				<span class="wijax-loading">
					<img src="'. $mywijax->path_web .'/components/img/loading-gray.gif' .'" alt="loading external resource" />
					<a href="'. $wijax_source .'" class="wijax-source wijax-onload" rel="nofollow"></a>
					<span class="wijax-opts" style="display: none;">'. $varname_string .'</span>
				</span>
			';
		}

		echo $before_widget;
		if(( ! is_wijax() ) && ( ! empty( $title )))
		{
			echo $before_title . $title . $after_title;
		}
		echo $content;
		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{
		$instance = $old_instance;
		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['facet'] = in_array( $new_instance['facet'] , array_keys( (array) scriblio()->facets()->facets )) ? $new_instance['facet'] : FALSE;
		$instance['format'] = in_array( $new_instance['format'], array( 'list', 'cloud' )) ? $new_instance['format']: '';
		$instance['format_font_small'] = floatval( '1' );
		$instance['format_font_large'] = floatval( '2.25' );
		$instance['number'] = absint( $new_instance['number'] );
		$instance['orderby'] = in_array( $new_instance['orderby'], array( 'count', 'name', 'custom' )) ? $new_instance['orderby']: '';
		$instance['order'] = ( 'count' == $instance['orderby'] ? 'DESC' : 'ASC' );

		return $instance;
	}

	function form( $instance )
	{
		//Defaults
		$instance = wp_parse_args( (array) $instance,
			array(
				'title' => '',
				'facet' => FALSE,
				'format' => 'cloud',
				'number' => 25,
				'orderby' => 'name',
			)
		);

		$title = esc_attr( $instance['title'] );
?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('facet'); ?>"><?php _e( 'Facet:' ); ?></label>
			<select name="<?php echo $this->get_field_name('facet'); ?>" id="<?php echo $this->get_field_id('facet'); ?>" class="widefat">
				<?php $this->control_facets( $instance['facet'] ); ?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('format'); ?>"><?php _e( 'Format:' ); ?></label>
			<select name="<?php echo $this->get_field_name('format'); ?>" id="<?php echo $this->get_field_id('format'); ?>" class="widefat">
				<option value="list" <?php selected( $instance['format'], 'list' ); ?>><?php _e('List'); ?></option>
				<option value="cloud" <?php selected( $instance['format'], 'cloud' ); ?>><?php _e('Cloud'); ?></option>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('number'); ?>"><?php _e('Number of terms to show:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number'); ?>" type="text" value="<?php echo absint( $instance['number'] ); ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('orderby'); ?>"><?php _e( 'Order By:' ); ?></label>
			<select name="<?php echo $this->get_field_name('orderby'); ?>" id="<?php echo $this->get_field_id('orderby'); ?>" class="widefat">
				<option value="count" <?php selected( $instance['orderby'], 'count' ); ?>><?php _e('Count'); ?></option>
				<option value="name" <?php selected( $instance['orderby'], 'name' ); ?>><?php _e('Name'); ?></option>
				<!-- <option value="custom" <?php selected( $instance['orderby'], 'custom' ); ?>><?php _e('Custom (see below)'); ?></option> -->
			</select>
		</p>

<?php
	}

	function control_facets( $default = '' )
	{
		$facet_list = array_keys( (array) scriblio()->facets()->facets );

		// Sort templates by name
		$names = array();
		foreach( $facet_list as $info )
			$names[] = $info['name'];
		array_multisort( $facet_list , $names );

		foreach ( $facet_list as $facet )
			if( ! isset( scriblio()->facets()->facets->$facet->exclude_from_widget ))
				echo "\n\t<option value=\"". $facet .'" '. selected( $default , $facet , FALSE ) .'>'. ( isset( scriblio()->facets()->facets->$facet->label ) ? scriblio()->facets()->facets->$facet->label : $facet ) .'</option>';
	}

}// end Scrib_Facets_Widget

class Scrib_Searcheditor_Widget extends WP_Widget {

	function Scrib_Searcheditor_Widget()
	{
		$this->WP_Widget( 'scrib_searcheditor', 'Scriblio Search Editor', array( 'description' => 'Edit search and browse criteria' ));
	}

	function widget( $args, $instance )
	{
		extract( $args );

		global $wp_query;

			if( ! ( is_search() || scriblio()->facets()->is_browse() ))
			return;

		$title = apply_filters( 'widget_title' , $instance['title'] );
		$context_top = do_shortcode( apply_filters( 'widget_text', $instance['context-top'] ) );
		$context_bottom = do_shortcode( apply_filters( 'widget_text', $instance['context-bottom'] ) );

		echo $before_widget;

		if ( ! empty( $title ) )
			echo $before_title . $title . $after_title;
		if ( ! empty( $context_top ) )
			echo '<div class="textwidget scrib_search_edit context-top">' . $context_top . '</div>';
		echo '<ul class="facets">'. scriblio()->facets()->editsearch() .'</ul>';
		if ( ! empty( $context_bottom ) )
			echo '<div class="textwidget scrib_search_edit context-bottom">' . $context_bottom . '</div>';

		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{
		$instance = $old_instance;

		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['context-top'] = wp_filter_post_kses( $new_instance['context-top'] );
		$instance['context-bottom'] = wp_filter_post_kses( $new_instance['context-bottom'] );

		return $instance;
	}

	function form( $instance )
	{

		//Defaults
		$instance = wp_parse_args( (array) $instance,
			array(
				'title' => 'Searching Our Collection',
				'context-top' => 'Your search found [scrib_hit_count] items with all of the following terms:',
				'context-bottom' => 'Click [x] to remove a term, or use the facets in the sidebar to narrow your search.',
			)
		);
?>

		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('context-top'); ?>"><?php _e('Text above:'); ?></label>
			<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('context-top'); ?>" name="<?php echo $this->get_field_name('context-top'); ?>"><?php echo format_to_edit( $instance['context-top'] ); ?></textarea>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('context-bottom'); ?>"><?php _e('Text below:'); ?></label>
			<textarea class="widefat" rows="7" cols="20" id="<?php echo $this->get_field_id('context-bottom'); ?>" name="<?php echo $this->get_field_name('context-bottom'); ?>"><?php echo format_to_edit( $instance['context-bottom'] ); ?></textarea>
		</p>

<?php

	}
}// end Scrib_Searcheditor_Widget



function scrib_widgets_init()
{
	register_widget( 'Scrib_Facets_Widget' );
	register_widget( 'Scrib_Searcheditor_Widget' );
}
add_action( 'widgets_init' , 'scrib_widgets_init' , 1 );