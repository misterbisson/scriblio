<?php
/**
* The options/admin control panel for WPopac 
*
* This file is released under the GNU Public License
* @author Casey Bisson <casey.bisson@gmail.com> 
* @version 0.1 - Oct 21 2006
*/

//print_r($_POST);

global $wpdb, $wp_rewrite;

$this->activate();

$options = $newoptions = get_option('scrib');

if(empty($options['browse_id']) || $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE ID = ". intval( $options['browse_id'] ) .' AND post_status = "publish" AND post_type = "page" ') == FALSE){		
	// create the default browse page
	$postdata['post_title'] = 'Browse';
	$postdata['post_name'] = 'browse';
	$postdata['comment_status'] = 0;
	$postdata['ping_status'] 	= 0;
	$postdata['post_status'] 	= 'publish';
	$postdata['post_type'] 		= 'page';
	$postdata['post_content']	= 'Browse new titles.';
	$postdata['post_excerpt']	= 'Browse new titles.';
	$postdata['post_author'] = 0;
	$post_id = wp_insert_post($postdata); // insert the post

	// set the options with this new page	
	$options['browse_id'] = (int) $post_id;	
	update_option('scrib', $options);
	echo '<div class="updated"><p>'.__('New Browse page created.', 'Scriblio').'</p></div>';
	$options = $newoptions = get_option('scrib');
}

if( empty( $options['catalog_category_id'] ) || !get_category( $options['catalog_category_id'] ) ){		
	wp_insert_category( array( 'cat_name' => __( 'Catalog', 'Scriblio' )));
	$options['catalog_category_id'] = get_cat_ID( __( 'Catalog', 'Scriblio' ));
	update_option('scrib', $options);
	echo '<div class="updated"><p>'.__('New category created for catalog records.', 'Scriblio').'</p></div>';
	$options = $newoptions = get_option('scrib');
}

if ($_REQUEST['command'] == __('Publish Harvested Records', 'Scriblio')){
	$this->import_harvest_publish();
}



if ($_POST['scrib-submit']) {
	$newoptions['sphinxsearch'] = (int) $_POST['sphinxsearch'];
	$newoptions['browse_id'] = (int) $_POST['browse_id'];
	$newoptions['catalog_author_id'] = (int) $_POST['catalog_author_id'];
	$newoptions['catalog_category_id'] = (int) $_POST['catalog_category_id'];

	unset($newoptions['taxonomies_for_related']);
	unset($newoptions['taxonomies_for_suggest']);
	foreach($this->taxonomies_getall() as $taxonomy){
		if(!empty($_POST['taxonomy_use_' . $taxonomy ]))
			$newoptions['taxonomies'][$taxonomy] = $_POST['taxonomy_name_' . $taxonomy ];
		else if( isset( $newoptions['taxonomies'][ $taxonomy ] ))
			unset( $newoptions['taxonomies'][ $taxonomy ] );
		if(!empty($_POST['taxonomy_for_related_' . $taxonomy ]))
			$newoptions['taxonomies_for_related'][] = $taxonomy;
		if(!empty($_POST['taxonomy_for_suggest_' . $taxonomy ]))
			$newoptions['taxonomies_for_suggest'][] = $taxonomy;
	}

	array_filter($newoptions);
}

if ( $options != $newoptions ) {
	$options = $newoptions;
	update_option('scrib', $options);
	echo '<div class="updated"><p>'.__('Options updated.', 'Scriblio').'</p></div>';

}

foreach($this->taxonomies_getall() as $taxonomy){
	$taxonomy_name[$taxonomy] = attribute_escape($options['taxonomies'][$taxonomy]); 
}

?> 
<div class="wrap">
		<h2><?php _e('Scriblio Options', 'Scriblio'); ?></h2>
		<div class="narrow">
		<form method="post">
		<input type="hidden" name="scrib-submit" id="scrib-submit" value="1" />
		<h3>Facets:</h3>
		<table>
		<th><td>Name:</td><td>Use?</td><td>Related?</td><td>Suggest?</td></th>
<?php
foreach($this->taxonomies_getall() as $taxonomy){
?>
		<tr><td><label for="taxonomy_name_<?php echo $taxonomy; ?>"><?php echo $taxonomy; ?>:</label></td><td><input type="text" style="width:100px" id="taxonomy_name_<?php echo $taxonomy; ?>" name="taxonomy_name_<?php echo $taxonomy; ?>" value="<?php echo $taxonomy_name[$taxonomy]; ?>" /></td>
		<td align="center"><input type="checkbox" id="taxonomy_use_<?php echo $taxonomy; ?>" name="taxonomy_use_<?php echo $taxonomy; ?>" value="1" <?php if(!empty($taxonomy_name[$taxonomy])) echo 'CHECKED'; ?> /></td>
		<td align="center"><input type="checkbox" id="taxonomy_for_related_<?php echo $taxonomy; ?>" name="taxonomy_for_related_<?php echo $taxonomy; ?>" value="<?php echo $taxonomy; ?>" <?php if(in_array($taxonomy, $options['taxonomies_for_related'])) echo 'CHECKED'; ?> /></td>
		<td align="center"><input type="checkbox" id="taxonomy_for_suggest_<?php echo $taxonomy; ?>" name="taxonomy_for_suggest_<?php echo $taxonomy; ?>" value="<?php echo $taxonomy; ?>" <?php if(in_array($taxonomy, $options['taxonomies_for_suggest'])) echo 'CHECKED'; ?> /></td></tr>
<?php
}
?>
		</table>

		<h3>Options:</h3>
		<p><label for="catalog_author_id"><?php _e('Owner for catalog entries', 'Scriblio'); ?>: <?php $users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users ORDER BY user_login"); ?><select name="catalog_author_id" id="catalog_author_id"><?php foreach ($users as $user) { $selected = $user->ID == (int)$newoptions['catalog_author_id'] ? ' SELECTED ': ''; echo '<option value="'. $user->ID .'"'. $selected .'>'.$user->user_login.'</option>'; } ?></select></label></p>

		<p><label for="catalog_category_id"><?php _e('Default category for catalog entries', 'Scriblio'); ?>: <select name="catalog_category_id" id="catalog_category_id"><?php foreach( get_all_category_ids() as $catid ) { $selected = $catid == (int)$newoptions['catalog_category_id'] ? ' SELECTED ': ''; echo '<option value="'. $catid .'"'. $selected .'>'. get_cat_name( $catid ) .'</option>'; } ?></select></label></p>

		<p><label for="browse_id"><?php _e('Browse base', 'Scriblio'); ?>: <select id="browse_id" name="browse_id"><option value="0"><?php _e('Main Page (no parent)'); ?></option><?php parent_dropdown($options['browse_id']); ?></select></label></p>

		<p><label for="sphinxsearch"><?php _e('Use Sphinx Keyword Indexing Engine?', 'Scriblio'); ?> <input type="checkbox" id="sphinxsearch" name="sphinxsearch" value="1" <?php if(!empty($options['sphinxsearch'])) echo 'CHECKED'; ?> /></label></p>

		<div class="submit"><input type="submit" name="submit" value="<?php _e('Update Options', 'Scriblio'); ?>" /></div>

		<p><a href="http://about.scriblio.net/wiki"><?php _e('Scriblio Documentation', 'Scriblio'); ?></a> &middot; <a href="http://groups.google.com/group/scriblio"><?php _e('Scriblio Mail List', 'Scriblio'); ?></a></p>

	</form>
	<h3>Commands:</h3>
	<form method="post" name="scrib_commands" name="scrib_commands">
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
