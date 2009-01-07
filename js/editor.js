// renumbers form names/ids in a sortable/editable list
// used some hints from here: http://bennolan.com/?p=35 http://bennolan.com/?p=21
jQuery.fn.scrib_renumber = function() {
	jQuery(this).parent().parent().parent().each( function(){
		var i = 0;
		jQuery(this).children('li.repeatable').each( function(){
			jQuery(this).find( 'input,select,textarea' ).attr("id", function(){
				return( jQuery(this).attr("id").replace(/\d+/, i) );
			});
			jQuery(this).find( 'input,select,textarea' ).attr("name", function(){
				return( jQuery(this).attr("name").replace(/\d+/, i) );
			});
			i++;
		});
	});
};


function scrib_meditor() {
	jQuery("div.wrap h2:first").text("Record Details");

	jQuery("#bsuite_machinetags").hide();
	jQuery("#categorydiv").hide();
	jQuery("#trackbacksdiv").hide();
	jQuery("#tagsdiv").hide();
	jQuery("#postcustom").hide();
	jQuery("#passworddiv").hide();
	jQuery("#postexcerpt").hide();
	jQuery("#postdivrich").hide();

	jQuery("#titlediv #title").css({ marginLeft:"5px", width:"98%" });
	jQuery("#titlediv").addClass("postbox");
	jQuery("#titlediv #titlewrap").before('<h3 class="hndle"><label for="title">Primary Title</label></h3>');

	jQuery("#scrib_meditor_div h3").text("Details");
	jQuery("#scrib_meditor_div").removeClass('closed');
	jQuery("#scrib_meditor_div").insertAfter("#titlediv");

	jQuery("#bsuite_post_icon h3").text("Image");
	jQuery("#bsuite_post_icon").removeClass('closed');
	jQuery("#bsuite_post_icon").insertAfter('#titlediv');
}

jQuery(document).ready(function(){
	// make the list sortable
	// http://docs.jquery.com/UI/Sortables
	jQuery("#scrib_meditor ul.sortable").sortable({
		handle: ".sortable-handle",
		stop: function(){
			jQuery(this).scrib_renumber();
		}
	});

	// add a handle to the begining of each line 
	// http://docs.jquery.com/Manipulation/before
	jQuery("#scrib_meditor li.repeatable").prepend("<div class='sortable-handle'><br />&uarr;&darr;</li> ");

	// add a delete and clone button to the end of each line 
	// http://docs.jquery.com/Manipulation/after
	jQuery("#scrib_meditor li.repeatable").prepend("<div class='repeatable-buttons'><br /><button class='add' type='button'>+</button> <button class='del' type='button'>-</button></li>");

 	// make that button clone the line
 	// http://docs.jquery.com/Manipulation/clone
	jQuery("#scrib_meditor button.add").click(function(){
		jQuery(this).parent().parent().clone(true).insertAfter( jQuery(this).parent().parent() )
		jQuery(this).scrib_renumber();
	});

	jQuery("#scrib_meditor button.del").click(function(){ 
		jQuery(this).parent().parent().remove();
		jQuery(this).scrib_renumber();
	});

	// remove the default tabindexes
	jQuery( "select#post_status,input#post_status_private,select#mm,input#jj,input#aa,input#hh,input#mn,input#save-post.button,input#publish.button,input#title,textarea#content,a#content_formatselect_open.mceOpen,input#tagadd.button,input#tags-input.tags-input,a#category-add-toggle.hide-if-no-js,input#newcat.form-required,select#newcat_parent.postform,input#category-add-sumbit.add:categorychecklist:category-add,textarea#excerpt,input#trackback,select#metakeyselect,input#metakeyinput,textarea#metavalue,input#addmetasub" ).removeTabindex();	

/*
	// remove the default tabindexes, set new ones on the meditor array
	jQuery( "*[tabindex]" ).removeTabindex();	
	var i = 1;
	jQuery( "#scrib_meditor *" ).find( 'input, select, textarea' ).each( function(){
		jQuery( this ).tabindex(i);
		i++;
	});
*/
});

