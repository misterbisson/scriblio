<?php

class Scrib_Facets_Widget extends WP_Widget
{

	function Scrib_Facets_Widget()
	{
		$this->WP_Widget( 'scriblio_facets', 'Scriblio Facets', array( 'description' => 'Displays facets related to the displayed set of posts' ));
	}

	function widget( $args, $instance )
	{
		global $facets;
		extract( $args );

		$title = apply_filters( 'widget_title' , empty( $instance['title'] ) ? '' : $instance['title'] );
		$orderby = ( in_array( $instance['orderby'], array( 'count', 'name', 'custom' )) ? $instance['orderby'] : 'name' );
		$order = ( in_array( $instance['order'], array( 'ASC', 'DESC' )) ? $instance['order'] : 'ASC' );

		// configure how it's displayed
		$display_options = array(
			'smallest' => floatval( $instance['format_font_small'] ), 
			'largest' => floatval( $instance['format_font_large'] ),
			'unit' => 'em',
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
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_corpus();
		}
		else if( is_singular() )
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_post( get_the_ID() );
		}
		else if( is_search() || $facets->is_browse() )
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_found_set();
			if( empty( $facet_list ))
				$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_corpus();
		}
		else
		{
			$facet_list = $facets->facets->{$instance['facet']}->get_terms_in_corpus();
		}

		echo $before_widget;
		if( ! empty( $title ))
		{
			echo $before_title . $title . $after_title;
		}
		echo convert_chars( wptexturize( $facets->generate_tag_cloud( $facet_list , $display_options )));
		echo $after_widget;
	}

	function update( $new_instance, $old_instance )
	{
		global $facets;

		$instance = $old_instance;
		$instance['title'] = wp_filter_nohtml_kses( $new_instance['title'] );
		$instance['facet'] = in_array( $new_instance['facet'] , array_keys( (array) $facets->facets )) ? $new_instance['facet'] : FALSE;
		$instance['format'] = in_array( $new_instance['format'], array( 'list', 'cloud' )) ? $new_instance['format']: '';
		$instance['format_font_small'] = floatval( '1' );
		$instance['format_font_large'] = floatval( '2.25' );
		$instance['count'] = absint( $new_instance['count'] );
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
				'count' => 25,
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
			<label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Number of terms to show:'); ?></label> <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo absint( $instance['count'] ); ?>" />
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
		global $facets;

		$facet_list = array_keys( (array) $facets->facets );

		// Sort templates by name  
		$names = array();
		foreach( $facet_list as $info )
			$names[] = $info['name']; 
		array_multisort( $facet_list , $names );

		foreach ( $facet_list as $facet )
			if( ! isset( $facets->facets->$facet->exclude_from_widget ))
				echo "\n\t<option value=\"". $facet .'" '. selected( $default , $facet , FALSE ) .'>'. ( isset( $facets->facets->$facet->label ) ? $facets->facets->$facet->label : $facet ) .'</option>';
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

		global $wp_query, $facets;

		if( ! ( is_search() || $facets->is_browse() ))
			return;

		$subsmatch = array(
			'[scrib_hit_count]',
			'[scrib_search_suggestions]',
		);

		$subsreplace = array(
			'',
			'',
		);

		$title = $instance['title'];
		$context_top = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['context-top'] ));
		$context_bottom = str_replace( $subsmatch, $subsreplace, apply_filters( 'widget_text', $instance['context-bottom'] ));

		echo $before_widget;

		if ( ! empty( $title ) )
			echo $before_title . $title . $after_title;
		if ( ! empty( $context_top ) )
			echo '<div class="textwidget scrib_search_edit">' . $context_top . '</div>';
		echo '<ul>'. $facets->editsearch() .'</ul>';
		if ( ! empty( $context_bottom ) )
			echo '<div class="textwidget scrib_search_edit">' . $context_bottom . '</div>';

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
