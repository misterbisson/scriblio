<?php
/*  
** Sphinx configuration directives 
** only necessary if you're using the Sphinx fulltext index engine
** more info: http://www.sphinxsearch.com/
** Sphinx home: http://www.sphinxsearch.com/
*/

// host settings
$sphinx_host = 'hostname.domain.org';
$sphinx_port = 3312; // 3312 is default port
$sphinx_index = 'index_name';

// record counts
$sphinx_recstart = 0;
$sphinx_reclimit = 1000;
$sphinx_recoffset = 0;

// search and sort modes
$sphinx_matchmode = SPH_MATCH_ALL;
$sphinx_sortby = '@rank DESC, catdate DESC, @id DESC';
$sphinx_sortmode = SPH_SORT_EXTENDED;
$sphinx_weights = array ( 150, 50, 75, 500 );

?>
