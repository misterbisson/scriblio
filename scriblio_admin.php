<?php



function scrib_control_taxonomies()
{
	global $wpdb, $wp_taxonomies;

	$options = get_option('scrib_taxonomies');

	foreach( (array) $wpdb->get_results( "SELECT taxonomy , COUNT(*) AS hits FROM $wpdb->term_taxonomy GROUP BY taxonomy" ) as $count)
		$counts[ $count->taxonomy ] = $count->hits;

	ksort( $wp_taxonomies );
	$taxonomies = array_merge( array( 's' => (object) array( 'label' => 'Keyword' , 'object_type' => 'post' )) , $wp_taxonomies );

?>
<table class="form-table">
	<thead>
		<tr>
			<td>Taxonomy</td>
			<td>Label</td>
			<td align="right">Term<br />Count</td>
			<td align="center">Searchable</td>
			<td align="center">Suggest<br />Terms</td>
			<td align="center">Relate<br />Records</td>
		</tr>
	</thead>
	<tbody>
<?php
	foreach( $taxonomies as $taxonomy => $v )
	{
		if( $v->object_type <> 'post' )
			continue;
?>
			<tr>
				<td>
					<label for="taxonomy_name_<?php echo $taxonomy; ?>"><?php echo $taxonomy; ?></label>
				</td>
				<td>
					<input type="text" style="width:100px" id="taxonomy_name_<?php echo $taxonomy; ?>" name="scrib_taxonomies[name][<?php echo $taxonomy; ?>]" value="<?php echo esc_html( wp_filter_nohtml_kses( empty( $options['name'][ $k ] ) ? $v->label : $options['name'][ $k ] )); ?>" />
				</td>
				<td align="right">
					<?php if( $counts[ $taxonomy ] ): ?>
						<a href="<?php echo admin_url( '/edit-tags.php?taxonomy='. $taxonomy ); ?>"><?php echo number_format( $counts[ $taxonomy ] ); ?></a>
					<?php else: ?>
						0
					<?php endif; ?>
				</td>
				<td align="center">
					<input type="checkbox" id="taxonomy_for_search_<?php echo $taxonomy; ?>" name="scrib_taxonomies[search][<?php echo $taxonomy; ?>]" value="1" <?php if( in_array( $taxonomy, $options['search'] )) echo 'CHECKED'; ?> />
				</td>
				<td align="center">
					<input type="checkbox" id="taxonomy_for_related_<?php echo $taxonomy; ?>" name="scrib_taxonomies[related][<?php echo $taxonomy; ?>]" value="1" <?php if( in_array( $taxonomy, $options['related'] )) echo 'CHECKED'; ?> />
				</td>
				<td align="center">
					<input type="checkbox" id="taxonomy_for_suggest_<?php echo $taxonomy; ?>" name="scrib_taxonomies[suggest][<?php echo $taxonomy; ?>]" value="1" <?php if( in_array( $taxonomy, $options['suggest'] )) echo 'CHECKED'; ?> />
				</td>
			</tr>
<?php
	}
?>
</tbody>
</table>
<?php
}

function scrib_control_categories()
{
	global $wpdb, $wp_taxonomies;

	$categories = get_categories();
	$options = get_option('scrib_categories');

?>
<table class="form-table">
	<thead>
		<tr>
			<td>Category</td>
			<td align="center">Default in browse</td>
			<td align="center">Hide from front</td>
		</tr>
	</thead>
	<tbody>
<?php
	foreach( $categories as $category )
	{
?>
		<tr>
			<td>
				<?php echo $category->name; ?>
			</td>
			<td align="center">
				<input type="checkbox" id="category_browse_<?php echo $category->slug; ?>" name="scrib_categories[browse][<?php echo $category->slug; ?>]" value="1" <?php if( in_array( $category->slug, $options['browse'] )) echo 'CHECKED'; ?> />
			</td>
			<td align="center">
				<input type="checkbox" id="category_hide_<?php echo $category->slug; ?>" name="scrib_categories[hide][<?php echo $category->slug; ?>]" value="1" <?php if( in_array( $category->slug, $options['hide'] )) echo 'CHECKED'; ?> />
			</td>
		</tr>
<?php
	}
?>
</tbody>
</table>
<?php
}


/**
* The options/admin control panel for WPopac 
*
* This file is released under the GNU Public License
* @author Casey Bisson <casey.bisson@gmail.com> 
* @version 0.1 - Oct 21 2006
*/

global $wpdb, $wp_rewrite;

$this->activate();

?> 
<div class="wrap">
	<h2><?php _e('Scriblio Options', 'Scrib'); ?></h2>
	<div class="narrow">
	<form method="post" action="options.php">
	    <?php settings_fields( 'Scrib' ); ?>

		<h3><?php _e('Facets', 'Scrib'); ?></h3>
		<?php scrib_control_taxonomies(); ?>

		<h3><?php _e('Categories', 'Scrib'); ?></h3>
		<?php scrib_control_categories(); ?>

	    <p class="submit">
	    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
	    </p>
	
		<p><a href="http://about.scriblio.net/wiki/"><?php _e('Scriblio Documentation', 'Scriblio'); ?></a> &middot; <a href="http://groups.google.com/group/scriblio"><?php _e('Scriblio Mail List', 'Scriblio'); ?></a></p>

		<h3>Commands:</h3>

		<div class="submit"><input type="submit" name="command" value="<?php _e('Publish Harvested Records', 'Scriblio'); ?>" /> (<?php echo $this->import_harvest_tobepublished_count() ?> records remain to be published)</div>
	</form>

</div></div>

<?php
/*
echo '<pre>';

global $wp_rewrite;
$wp_rewrite->flush_rules();
print_r($wp_rewrite->wp_rewrite_rules());
*/

?>
