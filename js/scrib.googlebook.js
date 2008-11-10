jQuery.GBDisplay = function(books){
	for(book in books)
	{
		var info = books[book];
		if(info)
		{
			//if(info.preview == 'full' || info.preview == 'partial')
			//{
				//jQuery('.isbn:contains('+info.bib_key+')').parent('.fullrecord').append('<li class="google-book"><h3>Google Book Search</h3><ul><li><a href="'+info.preview_url+'">Browse on Google</a></li></ul></li>');
				info.bib_key = info.bib_key.replace(/:/,"_");
				info.bib_key = info.bib_key.replace(/ /,"_");
				jQuery('#gbs_'+info.bib_key).html('<a href="'+info.preview_url+'">Browse on Google</a> &middot; ').parents('.availability').show();
				
			//}//end if
		}//end if
	}//end foreach
};
