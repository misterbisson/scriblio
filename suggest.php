<?php
require('../../../wp-config.php');

function scrib_suggest_fixlong( $suggestion ){
	if( strlen( $suggestion )  > 54)
		return( $suggestion . '*');
	return( $suggestion );
}

function scrib_suggest($q, $template = '<span class="taxonomy_name">%%taxonomy%%</span> <a href="%%link%%">%%term%%</a>'){
	$qclean = ereg_replace('[^a-z|0-9| ]', '', strtolower(remove_accents($q)));
	$key = md5( $qclean );

	if(!$suggestion = wp_cache_get( $key , 'scrib_suggest' )){
		global $wpdb, $scrib;
		$in_taxonomies = "'" . implode("', '", $scrib->taxonomies_for_suggest) . "'";
		$suggestion = array();
		$suggestion[] = 'Search for "<a href="'. $scrib->get_search_link(array('s' => array($q))) .'">'. $q .'</a>"';
	
		$metaphone = $term_ids = array();

/*
		// get exact matches
		$term_ids = array_merge( $term_ids, ($wpdb->get_col("SELECT term_id FROM $scrib->suggest_table WHERE term_name = '$qclean' ORDER BY term_rank DESC LIMIT 15")));
	
		if(0 < count($term_ids)){
			foreach($term_ids as $term_id){
				$terms = $wpdb->get_results("SELECT t.name, tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.taxonomy IN ($in_taxonomies) AND t.term_id = '$term_id'");
				foreach($terms as $term){
					if('hint' == $term->taxonomy)
						$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $scrib->taxonomy_name['s'], $scrib->get_search_link(array( 's' => array( scrib_suggest_fixlong( $term->name ))))), $template);
					else
						$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $scrib->taxonomy_name[$term->taxonomy], $scrib->get_search_link(array($term->taxonomy => array( scrib_suggest_fixlong( $term->name ))))), $template);
				}	
			}
		}
*/

		// get begins with
		$old_count = count($term_ids);
		$term_ids = array_merge( $term_ids, ($wpdb->get_col( "SELECT term_id FROM $scrib->suggest_table WHERE term_name LIKE '$qclean%' ORDER BY term_rank DESC LIMIT 50" )));
	
		if(0 < count(array_slice($term_ids, $old_count))){
			$active_taxonomies = $suggestion_temp = array();
			foreach(array_slice($term_ids, $old_count) as $term_id){
				$terms = $wpdb->get_results("SELECT t.name, tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.taxonomy IN ($in_taxonomies) AND t.term_id = '$term_id'");
				foreach($terms as $term){
					$active_taxonomies[] = $term->taxonomy;
		
					if('hint' == $term->taxonomy)
						$suggestion_temp[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $scrib->taxonomy_name['s'], $scrib->get_search_link(array('s' => array( scrib_suggest_fixlong( $term->name ))))), $template);
					else
						$suggestion_temp[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $scrib->taxonomy_name[$term->taxonomy], $scrib->get_search_link(array($term->taxonomy => array( scrib_suggest_fixlong( $term->name ))))), $template);
				}	
			}
			$suggestion = array_merge($suggestion, array_slice($suggestion_temp, 0, 5));
		
			// add in a "if x begins with y..."
			if(3 < count($suggestion_temp)){
				foreach(array_unique($active_taxonomies) as $taxonomy){
					if('hint' <> $taxonomy)
						$suggestion[] = $scrib->taxonomy_name[$taxonomy] .' begins with "<a href="'. $scrib->get_search_link(array($taxonomy => array(substr( $term->name, 0 , strlen( $qclean ) ). '*'))) .'">'. $q .'</a>"';
			
				}
			}
		}
	
		$old_count = count($term_ids);
		if(count($term_ids) < 5){
			$term_ids = ($wpdb->get_col("SELECT term_id FROM $scrib->suggest_table WHERE term_name NOT LIKE '$qclean%' AND term_name LIKE '%$qclean%' ORDER BY term_rank DESC LIMIT 5"));
			foreach($term_ids as $term_id){
				$terms = $wpdb->get_results("SELECT t.name, tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.taxonomy IN ($in_taxonomies) AND t.term_id = '$term_id'");
				foreach($terms as $term){
					if('hint' == $term->taxonomy)
						$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $scrib->taxonomy_name['s'], $scrib->get_search_link(array('s' => array( scrib_suggest_fixlong( $term->name ))))), $template);
					else
						$suggestion[] = str_replace(array('%%term%%','%%taxonomy%%','%%link%%'), array($term->name, $scrib->taxonomy_name[$term->taxonomy], $scrib->get_search_link(array($term->taxonomy => array( scrib_suggest_fixlong( $term->name ))))), $template);
				}	
			}
		}
		wp_cache_add( $key , $suggestion, 'scrib_suggest' );
	}
	return($suggestion);
}

if($suggest = scrib_suggest($_REQUEST['q'].$_REQUEST['s']))
	echo implode($suggest, "\n");

//print_r($wpdb->queries);

?>