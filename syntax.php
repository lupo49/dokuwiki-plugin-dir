<?php
/**
 * Dir Plugin: Shows pages in one or namespaces in a table or list
 *
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Jacobus Geluk <Jacobus.Geluk@gmail.com>
 * @based_on "pageindex" plugin by Kite <Kite@puzzlers.org>
 * @based_on "externallink" plugin by Otto Vainio <plugins@valjakko.net>
 * @based_on "pagelist" plugin by Esther Brunner <wikidesign@gmail.com>
 *
 * Contributions by:
 *
 * - Jean-Philippe Prade
 * - Gunther Hartmann
 * - Sebastian Menge
 * - Matthias Schulte
 * - Geert Janssens
 * - Gerry Weißbach
 */

if(!defined('DOKU_INC')) {
    define ('DOKU_INC', realpath(dirname(__FILE__).'/../../').'/');
}
if(!defined('DOKU_PLUGIN')) {
    define ('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');
}

require_once (DOKU_PLUGIN.'syntax.php');
require_once (DOKU_INC.'inc/search.php');
require_once (DOKU_INC.'inc/pageutils.php');

define ("DIR_PLUGIN_PATTERN", "DIR");

/**
 * The main DIR plugin class...
 */
class syntax_plugin_dir extends DokuWiki_Syntax_Plugin {
    var $debug = false;
    var $plugins = Array();
    var $opts = Array();
    var $cols = Array();
    var $hdrs = Array();
    var $pages = Array();
    var $includeTags = Array();
    var $excludeTags = Array();
    var $hasTags = false;
    var $style = "default";
    var $rdr = NULL;
    var $rdrMode = NULL;
    var $start = "start";
    var $dformat = NULL;
    var $sortKeys = Array();
    var $nbrOfSortKeys = 0;
    var $useDefaultTitle = true;
    var $modeIsXHTML = false;
    var $modeIsLatex = false;
    var $processedLatex = false;
    var $rowNumber = 0;
    var $ucnames = false;

    /**
     * Constructor
     */
    function syntax_plugin_dir() {
        global $conf;

        //
        // In the config you can set allowdebug, but you can also
        // specify the debug attribute in the ~~DIR~~ line...
        //
        if($conf ["allowdebug"] == 1)
            $this->debug = true;

        $this->start   = $conf ["start"];
        $this->dformat = $conf ["dformat"];
        $this->style   = $this->getConf("style");
    }

    /**
     * return some info
     */
    function getInfo() {
        return array(
            'author' => 'Jacobus Geluk',
            'email'  => 'Jacobus.Geluk@gmail.com',
            'date'   => '2008-06-28',
            'name'   => 'Dir Plugin',
            'desc'   => 'Shows pages in one or namespaces in a table or list',
            'url'    => 'http://www.dokuwiki.org/plugin:dir',
        );
    }

    /**
     * What kind of syntax are we?
     */
    function getType() {
        return "substition";
    }

    /**
     * Just before build in links
     */
    function getSort() {
        return 299;
    }

    /**
     * What about paragraphs?
     */
    function getPType() {
        return "block";
    }

    /**
     * Register the ~~DIR~~ verb...
     * Supported signatures:
     *
     * 1. ~~DIR~~
     * 2. ~~DIR:...~~
     * 3. ~~DIR?...~~
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('~~'.DIR_PLUGIN_PATTERN.'~~', $mode, 'plugin_dir');
        $this->Lexer->addSpecialPattern('~~'.DIR_PLUGIN_PATTERN.'[:?][^~]*~~', $mode, 'plugin_dir');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        return preg_replace("%~~".DIR_PLUGIN_PATTERN.":(=(.*))?~~%", "\\2", $match);
    }

    /**
     * Initialize the current object for each rendering pass
     */
    function _initRender($mode, &$renderer) {
        $rc                = FALSE;
        $this->rowNumber   = 0;
        $this->opts        = Array();
        $this->cols        = Array();
        $this->hdrs        = Array();
        $this->pages       = Array();
        $this->hasTags     = false;
        $this->excludeTags = Array();
        $this->includeTags = Array();
        $this->rdr         =& $renderer;
        $this->rdrMode     = $mode;

        switch($mode) {
            case 'latex':
                $this->modeIsXHTML = false;
                $this->modeIsLatex = true;
                $rc                = TRUE;
                break;
            case 'xhtml':
                $this->modeIsXHTML = true;
                $this->modeIsLatex = false;
                $rc                = TRUE;
                break;
            default:
                $this->modeIsXHTML = false;
                $this->modeIsLatex = false;
        }

        return $rc;
    }

    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $data) {
        if(!$this->_initRender($mode, $renderer)) return false;

        $rc = $this->_dir($data);

        if($this->modeIsLatex) $this->processedLatex = true;

        $this->_showDebugMsg("Leaving syntax_plugin_dir.render()");

        return $rc;
    }

    /**
     * Put a debug message on screen...
     */
    function _showDebugMsg($msg) {
        if(!$this->debug) return;

        if(is_array($msg)) {
            foreach($msg as $index => $m) {
                $this->_showDebugMsg("Array [$index]: ".$m);
            }
            return;
        }

        $this->_putNewLine();

        switch($this->rdrMode) {
            case 'xhtml':
                $this->_put(DOKU_LF."<span style=\"color:red;\">~~");
                $this->_put(DIR_PLUGIN_PATTERN."~~: ".hsc($msg)."</span>");
                break;
            case 'latex':
                $this->_put(DOKU_LF."~~");
                $this->_put(DIR_PLUGIN_PATTERN."~~: ".$msg);
                break;
        }
    }

    /**
     * Load the specified plugin (like the tag or discussion plugin)
     */
    function _loadPlugin($plugin) {
        if(plugin_isdisabled($plugin))
            return false;

        $plug = plugin_load('helper', $plugin);

        if(!$plug) {
            $this->_showDebugMsg("Plugin \"$plugin\" NOT loaded!");
            return false;
        }

        $this->plugins [$plugin] = $plug;
        $this->_showDebugMsg("Plugin \"$plugin\" loaded!");

        return true;
    }

    /**
     * Let another plugin generate the content...
     */
    function _pluginCell($plugin, $id) {
        $plug = $this->plugins [$plugin];

        if(!$plug)
            return 'Plugin '.$plugin.' not loaded!';

        $html = $plug->td(cleanID($id));

        return $html;
    }

    /**
     * Shows parsed options (in debug mode)
     */
    function _parseOptionsShow($data, $dir, $ns) {
        if(!$this->debug) return;

        $this->_put(DOKU_LF."<xmp style=\"font-family: Courier; color: red;\">");
        $this->_put(DOKU_LF."  data = $data");
        $this->_put(DOKU_LF."  dir  = $dir");
        $this->_put(DOKU_LF."  ns   = $ns");

        foreach($this->opts as $key => $opt) {
            if(is_array($opt)) {
                foreach($opt as $optkey => $optval) {
                    $this->_put(DOKU_LF."  opts[$key][$optkey] = $optval");
                }
            } else if(is_bool($opt)) {
                $this->_put(DOKU_LF."  opts[$key] = ".($opt ? "true" : "false"));
            } else {
                $this->_put(DOKU_LF."  opts[$key] = $opt");
            }
        }
        $this->_put(DOKU_LF.DOKU_LF."date: ".date("D M j G:i:s T Y"));
        $this->_put(DOKU_LF."</xmp>".DOKU_LF);
    }

    /**
     * Get the namespace of the parent directory
     * (always prefixed and postfixed with a colon, root is ':')
     */
    function _getParentNS($id) {
        // global $ID ;
        $curNS = getNS($id);

        if($curNS == '') return ':';

        if(substr($curNS, 0, 1) != ':') {
            $curNS = ':'.$curNS;
        }

        return $curNS.':';
    }

    /**
     * Create a fully qualified namespace from the specified one.
     * The second parameter must be true when the given namespace
     * is never a page id. In that case, the returned namespace
     * always ends with a colon.
     */
    function _parseNS($ns, $mustBeNSnoPage) {
        global $ID;

        if(substr($ns, 0, 2) == '.:') {
            $ns = ':'.getNS($ID).substr($ns, 1);
        } elseif(substr($ns, 0, 3) == '..:') {
            $ns = $this->_getParentNS($ID).substr($ns, 3);
        } elseif($ns == '..') {
            $ns = $this->_getParentNS($ID);
        } elseif(substr($ns, 0, 1) == ':') {
        } elseif($ns == '.' || $ns == '*') {
            $ns = ':'.getNS($ID);
        } else {
            $ns = ':'.getNS($ID).':'.$ns;
        }

        if($mustBeNSnoPage && substr($ns, -1) <> ':') $ns .= ':';

        return $ns;
    }

    /**
     * Convert namespace to its path
     */
    function _ns2path($ns) {
        global $conf;

        if($ns == ':' || $ns == '') return $conf ['datadir'];

        $ns = trim($ns, ':');

        $path = $conf ['datadir'].'/'.utf8_encodeFN(str_replace(':', '/', $ns));

        return $path;
    }

    /**
     * Initialize the opts array...
     */
    function _initOpts($flags) {
        $this->opts                   = array();
        $this->opts ["noheader"]      = false;
        $this->opts ["collapse"]      = false;
        $this->opts ["ego"]           = false;
        $this->opts ["namespacename"] = false;

        $flags = explode('&', $flags);

        foreach($flags as $index => $par) {
            $tmp = explode("=", $par);
            $key = $tmp [0];
            $val = $tmp [1];

            switch($key) {
                case "skip":
                case "cols":
                case "hdrs":
                case "sort":
                case "tag":
                    $val = explode(';', trim($val, ';'));
                    $this->_loadPlugin("tag");
                    break;
                case "noheader":
                case "nohead":
                case "nohdr":
                    $key = "noheader";
                    $val = true;
                    break;
                case "showheader":
                case "header":
                    $key = "noheader";
                    $val = false;
                    break;
                case "collapse":
                    $key = "collapse";
                    $val = true;
                    break;
                case "ego":
                    $key = "ego";
                    $val = true;
                    break;
                case "nodefaulttitle":
                case "ndt":
                    $key                   = "nodefaulttitle";
                    $val                   = true;
                    $this->useDefaultTitle = false;
                    break;
                case "widedesc":
                    $val = true;
                    break;
                case "table":
                    $this->style = "table";
                    break;
                case "list":
                    $this->style = "list";
                    break;
                case "namespacename":
                    $key = "namespacename";
                    $val = true;
                    break;
                case "ucnames":
                    $this->ucnames = true;
                    break;
                    
                case "debug":
                    $this->debug = true;
		    break;
                case  "last":
		    $key = 'maxrows';
		    $val = intval($val);
		    break;
            }
            $this->opts [$key] = $val;
        }
    }

    /**
     * Check the supplied column names
     */
    function _parseColumnNames() {
        if(is_array($this->opts ["cols"])) {
            $this->cols = $this->opts ["cols"];
        } else {
            $this->cols = Array("page");
        }

        if(count($this->cols) == 0) {
            $cols [] = "page";
            $cols [] = "desc";
        }

        $newCols = Array();

        foreach($this->cols as $index => $col) {
            switch($col) {
                case "page":
                case "desc":
                case "user":
                case "userid":
                case "mdate":
                case "cdate":
                case "rowno":
                    break;
                case "comments":
                    $this->_loadPlugin("discussion");
                    break;
                case "tags":
                    $this->_loadPlugin("tag");
                    break;
                case "date":
                    $col = "mdate";
                    break;
                case "description":
                    $col = "desc";
                    break;
                default:
                    $this->_showDebugMsg("Unrecognized column name: \"$col\"");
                    $col = '';
            }
            if($col != '') {
                $this->_showDebugMsg("Recognized column name: $col");
                $newCols [] = $col;
            }
        }
        $this->cols = $newCols;
        if(count($this->opts ["hdrs"]) != count($this->cols)) {
            $this->_showDebugMsg(
                "The number of specified headers (".count($this->opts ["hdrs"]).
                    ") is not equal to the number of specified columns (".
                    count($this->cols).")!"
            );
        }
    }

    /**
     * Check the supplied tags
     */
    function _parseTags() {
        $this->hasTags = false;

        if(is_array($this->opts ["tag"])) {
            foreach($this->opts ["tag"] as $tag) {
                if($tag == NULL || $tag == '')
                    continue;
                $tag = mb_convert_case($tag, MB_CASE_LOWER, "UTF-8");
                if(substr($tag, 0, 1) == '!') {
                    $this->excludeTags [] = substr($tag, 1);
                } else {
                    $this->includeTags [] = $tag;
                }
                $this->hasTags = true;
            }
            foreach($this->excludeTags as $tag) {
                $this->_showDebugMsg("Specified exclude tag: $tag");
            }
            foreach($this->includeTags as $tag) {
                $this->_showDebugMsg("Specified include tag: $tag");
            }
        }
    }

    /**
     * Check the supplied sort keys
     */
    function _parseSortKeys() {
        if(is_array($this->opts ["sort"])) {
            $this->sortKeys = $this->opts ["sort"];
        }

        $sortKeys = Array();

        foreach($this->sortKeys as $index => $sortKey) {

            $array = explode('-', strtolower($sortKey));
            if(count($array) == 1) {
                $array = Array($sortKey, "a");
            }

            switch($array [1]) {
                case NULL:
                case "a":
                case "asc":
                case "ascending":
                    $array [1] = false;
                    break;
                case "d":
                case "desc":
                case "descending":
                    $array [1] = true;
                    break;
                default:
                    $this->_showDebugMsg(
                        "Unrecognized sort column name modifier: ".
                            $array [1]
                    );
                    $array [1] = false;
            }

            switch($array [0]) {
                case "page":
                case "desc":
                case "user":
                case "userid":
                case "mdate":
                case "cdate":
                case "rowno":
                    break;
                case "comments":
                    $this->_loadPlugin("discussion");
                    break;
                case "tags":
                    $this->_loadPlugin("tag");
                    break;
                case "date":
                    $array [0] = "mdate";
                    break;
                case "description":
                    $array [0] = "desc";
                    break;
                default:
                    $this->_showDebugMsg(
                        "Unrecognized sort column name: ".$array [0]
                    );
                    $array [0] = NULL;
            }
            if($array [0]) {
                $this->_showDebugMsg(
                    "Sort column ".$array [0]." ".
                        ($array [1] ? "descending" : "ascending")
                );
                $sortKeys [] = $array;
            }
        }

        $this->sortKeys      = $sortKeys;
        $this->nbrOfSortKeys = count($this->sortKeys);
    }

    /**
     * Add a page to the collection of $pages. Check first if it should
     * not be skipped...
     */
    function _addFoundPage(&$data, $ns, $id, $type, $level) {
        global $ID;
        $fqid = $ns.$id; // Fully qualified id...

        //
        // If this file or directory should be skipped, do so
        //
        switch($type) {
            case "f":
                if(($fqid == ':'.$ID) && !$this->opts ["ego"]) // If we found ourself, skip it
                    return false;
                $pageName = noNS($id);
                if($pageName == $this->start)
                    return false;
                foreach($this->opts ["skipfqid"] as $index => $skipitem) {
                    if($skipitem) {
                        if($skipitem == $fqid) {
                            //
                            // Remove the skip rule, it has no use any more...
                            //
                            $this->opts ["skipfqid"] [$index] = NULL;
                            $this->_showDebugMsg("Skipping $fqid due to skip rule $skipitem");
                            return false;
                        }
                    }
                }
                if($this->opts ["collapse"]) {
                    // With collapse, only show:
                    // - pages within the same namespace as the current page
                    if($this->_getParentNS($fqid) != $this->_getParentNS($ID)) {
                        return false;
                    }
                }
                $linkid = $fqid;
                break;
            case "d":
                $fqid .= ':';
                foreach($this->opts ["skipns"] as $skipitem) {
                    if($skipitem == $fqid) {
                        $this->_showDebugMsg("Skipping $fqid due to skip rule $skipitem");
                        return false;
                    }
                }

                // Don't add startpages the user isn't authorized to read
                if(auth_quickaclcheck(substr($linkid, 1)) < AUTH_READ)
                    return false;

                if($this->opts ["collapse"]) {
                    // With collapse, only show:
                    // - sibling namespaces of the current namespace and it's ancestors
                    $curPathSplit  = explode(":", trim(getNS($ID), ":"));
                    $fqidPathSplit = explode(":", trim(getNS($fqid), ":"));

                    // Find the last parent namespace that matches
                    // If there is only one more child namespace in the namespace under evaluation,
                    // Then this is a sibling of one of the parent namespaces of the current page.
                    // Siblings are ok, grandchild namespaces and below should be skipped (for collapse).
                    $clevel = 0;
                    if(count($curPathSplit) > 0) {
                        while(($clevel < count($fqidPathSplit) - 1) && ($clevel < count($curPathSplit))) {
                            if($curPathSplit[$clevel] == $fqidPathSplit[$clevel]) {
                                $clevel++;
                            } else {
                                break;
                            }
                        }
                    }
                    if(count($fqidPathSplit) > $clevel + 1) {
                        return false;
                    }
                }

                $linkid = $fqid.$this->start;
                break;
        }

        //  $this->_showDebugMsg ("$level $type $ns$id:");
        
        if($this->ucnames) {
            $fqid =  str_replace('_'," ",$fqid);
           // $fqid = ltrim($fqid , ':');  
            $fqid = preg_replace_callback(
                '|:\w|',
                function ($matches) {
                    return strtoupper($matches[0]);
                },
                $fqid
            );   
            $fqid = ucwords($fqid);
        }
        $data [] = array(
            'id'         =>  $fqid,
            'type'       => $type,
            'level'      => $level,
            'linkid'     => $linkid,
            'timestamp'  => NULL
        );

        return true;
    }

    /**
     * Callback method for the search function in _parseOptions
     */
    function _searchDir(&$data, $base, $file, $type, $level, $opts) {
        global $ID;
        $ns = $opts ["ns"];

        switch($type) {
            case "d":
                return $this->_addFoundPage($data, $ns, pathID($file), $type, $level);
            case "f":
                if(!preg_match('#\.txt$#', $file))
                    return false;
                //check ACL
                $id = pathID($file);
                if(auth_quickaclcheck($id) < AUTH_READ)
                    return false;
                $this->_addFoundPage($data, $ns, $id, $type, $level);
        }

        return false;
    }

    /**
     * Parse the options after the ~~DIR: string and
     * return true if the table can be generated...
     *
     * A namespace specifaction should start with a colon and the flags
     * should start with a question mark, like this:
     *
     * ~~DIR[[:<namespace>][?<flags>]]~~
     *
     * To not break pages created with an older version of this plugin, this
     * syntax is also supported:
     *
     * ~~DIR:<flags>~~
     *
     * This assumes that no other colon is put in <flags>
     */
    function _parseOptions($data) {
        global $conf;
        global $ID;
        $ns    = '.';
        $flags = trim($data, '~');
        $flags = substr($flags, strlen(DIR_PLUGIN_PATTERN));
        $flags = trim($flags);

        $this->_showDebugMsg("specified arguments=".$flags);

        if(
            substr($flags, 0, 1) == ':' &&
            strpos(substr($flags, 1), '?') === FALSE &&
            strpos(substr($flags, 1), ':') === FALSE
        ) {
            //
            // This is the "old" syntax where flags do not start with a question mark
            //
            $this->_showDebugMsg("parseOptions A");
            $flags = substr($flags, 1);
        } else if(
            substr($flags, 0, 1) == ':' &&
            strpos(substr($flags, 1), '?') === FALSE
        ) {
            //
            // There is no questionmark so it's all namespace specification
            //
            $this->_showDebugMsg("parseOptions B");
            $ns    = substr($flags, 1);
            $flags = '';
        } else if(substr($flags, 0, 1) == '?') {
            $this->_showDebugMsg("parseOptions C");
            $flags = substr($flags, 1);
        } else if(strlen($flags) == 0) {
            $this->_showDebugMsg("parseOptions D");
        } else if(
            strpos(substr($flags, 1), '?') !== FALSE
        ) {
            $this->_showDebugMsg("parseOptions E");
            $tmp = explode('?', $flags);

            if(count($tmp) == 2) {
                $ns    = substr($tmp [0], 1);
                $flags = $tmp [1];
            } else {
                $this->_showDebugMsg("ERROR: Multiple questionmarks are not supported");
                $flags = '';
            }
        } else {
            $this->_showDebugMsg("parseOptions E");
            $ns    = $flags;
            $flags = '';
        }

        $this->_showDebugMsg("specified namespace=$ns");
        $this->_showDebugMsg("specified flags=$flags");

        $ns = $this->_parseNS($ns, true);

        $path = $this->_ns2path($ns);
        $this->_showDebugMsg("path=$path");

        $this->_initOpts($flags);
        $this->_parseColumnNames();
        $this->_parseSortKeys();
        $this->_parseTags();

        //
        // Check the column headers
        //
        $this->hdrs = $this->cols;
        if(is_array($this->opts ["hdrs"])) {
            foreach($this->opts ["hdrs"] as $index => $hdr) {
                $this->hdrs [$index] = $hdr;
            }
        }

        //
        // Check the skip items
        //
        $this->opts ["skipfqid"] = Array();
        $this->opts ["skipns"]   = Array();
        if(is_array($this->opts ["skip"])) {
            foreach($this->opts ["skip"] as $skipitem) {
                $item = $this->_parseNS($skipitem, false);
                if(substr($item, -1) == ":") {
                    $this->opts ["skipns"] [] = $item;
                } else {
                    $this->opts ["skipfqid"] [] = $item;
                }
            }
        }

        $this->_parseOptionsShow($data, $path, $ns);

        //
        // Search the directory $dir, only if the pages array
        // is empty, since we can pass here several times (xhtml, latex).
        //
        $this->_showDebugMsg("Search directory $path");
        $this->_showDebugMsg("for namespace $ns");

        if(count($this->pages) == 0) {
            search(
                $this->pages, // results
                $path, // folder root
                array($this, '_searchDir'), // handler
                array('ns' => $ns) // namespace
            );
        }
        $count = count($this->pages);

        $this->_showDebugMsg("Found ".$count." pages!");

        if($count == 0) {
            $this->_put(DOKU_LF."\t<p>There are no documents to show.</p>".DOKU_LF);
            return false;
        }

        $this->_sortResult();
	    if ( !empty($this->opts['maxrows']) && $this->opts['maxrows'] > 0 ) {
		    $this->pages = array_slice($this->pages, 0, $this->opts['maxrows']);
	    }
	    
        return true;
    }

    /**
     * Sort the found pages according to the settings
     */
    function _sortResult() {

        if($this->nbrOfSortKeys == 0)
            return;

        usort($this->pages, array($this, "_sortPage"));
    }

    /**
     * Compare function for usort
     */
    function _sortPage($a, $b) {
        return $this->_sortPageByKey($a, $b, 0);
    }

    function _sortPageByKey(&$a, &$b, $index) {
        if($index >= $this->nbrOfSortKeys)
            return 0;

        $keyType  = $this->sortKeys [$index];
        $sortKeyA = $this->_getSortKey($a, $keyType [0]);
        $sortKeyB = $this->_getSortKey($b, $keyType [0]);

        if($sortKeyA == $sortKeyB)
            return $this->_sortPageByKey($a, $b, $index + 1);

        if($keyType [1]) {
            $tmp      = $sortKeyA;
            $sortKeyA = $sortKeyB;
            $sortKeyB = $tmp;
        }

        return ($sortKeyA < $sortKeyB) ? -1 : 1;
    }

    /**
     * Produces a sortable key
     */
    function _getSortKey(&$page, $keyType) {
        switch($keyType) {
            case "page":
                return html_wikilink($page ["id"]);
            case "desc":
            case "widedesc":
                return $this->_getMeta($page, "description", "abstract");
            case "mdate":
                return $this->_getMeta($page, "date", "modified");
            case "cdate":
                return $this->_getMeta($page, "date", "created");
            case "user":
                $users = $this->_getMeta($page, "contributor");
                if(is_array($users)) {
                    $index = 0;
                    foreach($users as $userid => $user) {
                        if($user && $user <> "") {
                            return $user;
                        }
                    }
                }
                return $users;
            case "userid":
                $users = $this->_getMeta($page, "contributor");
                if(is_array($users)) {
                    $index = 0;
                    foreach($users as $userid => $user) {
                        if($userid && $userid <> "") {
                            return $userid;
                        }
                    }
                }
                return $users;
            case "comments":
                return $this->_pluginCell("discussion", $page ["linkid"]);
            case "tags":
                return $this->_pluginCell("tag", $page ["linkid"]);
            case "rowno":
                return '0';
        }

        return NULL;
    }

    /**
     * Generate the content for the cell with the page link...
     */
    function _tableCellContentID(&$page) {
        $fqid        = $page ["id"];
        $tmplvl      = $page ["level"] - 1;
        $spacerWidth = $tmplvl * 20;
        $pageid      = $fqid;
        $name        = NULL;

        if($page ["type"] == 'd') {
            if($this->opts ["namespacename"]) {
                $pieces = explode(':', trim($pageid, ':'));
                $name   = array_pop($pieces);
            }
            $pageid .= ':'.$this->start;
        }

        if(!$this->useDefaultTitle) {
            $name = explode(':', $fqid);
            $name = ucfirst($name [count($name) - 1]);
        }

        switch($this->rdrMode) {
            case 'latex':
                $this->rdr->internallink($pageid, $name);
                break;
            case 'xhtml':
                if($spacerWidth > 0) {
                    $this->_put('<div style="margin-left: '.$spacerWidth.'px;">');
                }
                 
                if($page ["type"] == 'd' && $this->ucnames) {                   
                   $dirlnk = html_wikilink($pageid, $name);
                   $dirlnk = str_replace('wikilink2', 'wikilink',$dirlnk);
                   $this->_put($dirlnk);
                }
                else $this->_put(html_wikilink($pageid, $name));
                if($spacerWidth > 0) {
                    $this->_put('</div>');
                }
                break;
        }
    }

    /**
     * Get default value for an unset element
     */
    function _getMeta(&$page, $key1, $key2 = NULL) {
        if(!isset ($page ["meta"]))
            $page ["meta"] = p_get_metadata($page ["linkid"], false, true);

        //
        // Use "created" instead of "modified" if null
        //
        if(
            $key1 == "date" &&
            $key2 == "modified" &&
            !isset ($page ["meta"]["date"]["modified"])
        ) {
            $key2 = "created";
        }
        //
        // Return "creator" if "contributor" is null
        //
        if($key1 == "contributor" && !isset ($page ["meta"]["contributor"])) {
            $key1 = "creator";
        }

        if(is_string($key2)) return $page ["meta"] [$key1] [$key2];

        return $page ["meta"] [$key1];
    }

    /**
     * Generate the table cell content...
     */
    function _tableCellContent(&$page, $col) {
        switch($col) {
            case "page":
                $this->_tableCellContentID($page);
                break;
            case "desc":
            case "widedesc":
                $this->_put($this->_getMeta($page, "description", "abstract"));
                break;
            case "mdate":
                $this->_putDate($this->_getMeta($page, "date", "modified"));
                break;
            case "cdate":
                $this->_putDate($this->_getMeta($page, "date", "created"));
                break;
            case "user":
                $users = $this->_getMeta($page, "contributor");
                if(is_array($users)) {
                    $index = 0;
                    foreach($users as $userid => $user) {
                        if($user && $user <> '') {
                            if($index++ > 0) {
                                $this->_putNewLine();
                            }
                            $this->_put($user);
                        }
                    }
                }
                break;
            case "userid":
                $users = $this->_getMeta($page, "contributor");
                if(is_array($users)) {
                    $index = 0;
                    foreach($users as $userid => $user) {
                        if($userid && $userid <> '') {
                            if($index++ > 0) {
                                $this->_putNewLine();
                            }
                            $this->_put($userid);
                        }
                    }
                }
                break;
            case "comments":
                if(!$this->modeIsLatex)
                    $this->_put($this->_pluginCell("discussion", $page ["linkid"]));
                break;
            case "tags":
                if(!$this->modeIsLatex)
                    $this->_put($this->_pluginCell("tag", $page ["linkid"]));
                break;
            case "rowno":
                $this->_put($this->rowNumber);
                break;
            default:
                $this->_put($col);
        }
    }

    /**
     * Rewrite of renderer->table_open () because of class
     */
    function _tableOpen() {

        if($this->modeIsLatex) {
            $rdr = $this->rdr;
            $rdr->_counter['row_counter'] = 0;
            $rdr->_current_tab_cols = 0;
            if($rdr->info ['usetablefigure'] == "on") {
                $this->_putCmdNl("begin{figure}[h]");
            } else {
                $this->_putCmdNl("vspace{0.8em}");
            }
            $rdr->putcmd("begin{tabular}");
            $rdr->put("{");
            foreach($this->hdrs as $index => $hdr) {
                $rdr->put("l");
                if($index + 1 < sizeof($this->hdrs))
                    $rdr->put('|');
            }
            $rdr->putnl("}");
            return;
        }

        switch($this->style) {
            case "table":
                $class = "inline";
                break;
            case "list":
                $class = "ul";
                break;
            default:
                $class = "pagelist";
        }
        $this->_showDebugMsg("Style=".$this->style." table class=$class");
        $this->rdr->table_open (null, null, null, $class) ;
    }

    /**
     * Rewrite of renderer->table_close ()
     */
    function _tableClose() {
        if($this->modeIsLatex) {
            $this->rdr->tabular_close();
            return;
        }

        $this->rdr->table_close () ;
    }

    /**
     * Rewrite of renderer->tableheader_open () because of class
     */
    function _tableHeaderCellOpen($class) {
        if($this->modeIsLatex)
            return;

        $this->_put(DOKU_LF.DOKU_TAB.DOKU_TAB.'<th class="'.$class.'">');
    }

    /**
     * Rewrite of renderer->tableheader_close ()
     */
    function _tableHeaderCellClose($index) {
        if($this->modeIsLatex) {
            if(($index + 1) == sizeof($this->hdrs)) {
                $this->rdr->putnl('\\\\');
            } else {
                $this->rdr->put('&');
            }
            return;
        }
        return $this->rdr->tableheader_close();
    }

    /**
     * Rewrite of renderer->tablecell_open () because of class
     */
    function _tableCellOpen($colspan, $class) {
        if($this->modeIsLatex) return;

        $this->_put(DOKU_LF.DOKU_TAB.DOKU_TAB.'<td class="'.$class.'"');

        if($colspan > 1)
            $this->_put(' colspan="'.$colspan.'"');

        $this->_put(">");
    }

    /**
     * Rewrite of renderer->tablecell_close () because of class
     */
    function _tableCellClose($index) {
        if($this->modeIsLatex) {
            if(($index + 1) == sizeof($this->hdrs)) {
                $this->rdr->putnl('\\\\');
            } else {
                $this->rdr->put('&');
            }
            return;
        }

        return $this->rdr->tablecell_close();
    }

    /**
     * Return the class name to be used for the <td> showing $col.
     */
    function _getCellClassForCol($col) {
        switch($col) {
            case "page":
                return "dpage";
            case "date":
            case "user":
            case "desc":
            case "comments":
            case "tags":
            case "rowno":
                return $col;
            case "mdate":
            case "cdate":
                return "date";
            case "userid":
                return "user";
            case "":
                return "";
        }
        $this->_showDebugMsg("Unknown style class for col $col");
        return "desc";
    }

    function _tableHeaderRowOpen() {
        if($this->modeIsLatex) {
            $this->_putCmdNl('hline');
            return;
        }

        $this->rdr->tablerow_open();
    }

    function _tableHeaderRowClose() {
        if($this->modeIsLatex) {
            $this->_putCmdNl('hline');
            return;
        }

        $this->rdr->tablerow_close();
    }

    function _tableRowOpen() {
        if($this->modeIsLatex)
            return;

        $this->rdr->tablerow_open();
    }

    function _tableRowClose() {
        if($this->modeIsLatex)
            return;

        $this->rdr->tablerow_close();
    }

    /**
     * Return true if the tag plugin is not loaded,
     * if the ~~DIR~~ line has no tag attribute or
     * if the given page has one of the specified tags.
     */
    function _hasTag($page) {
        if(!$this->hasTags) return true;

        $plug = $this->plugins ['tag'];
        if(!$plug) return true;

        // Get the tags of the current page
        $tmp = $this->_getMeta($page, "subject");

        if(!is_array($tmp)) return false;

        $tags = Array();

        // Convert them to lowercase
        foreach($tmp as $tag) {
            $tags [] = mb_convert_case($tag, MB_CASE_LOWER, "UTF-8");
        }

        #
        # If there is an intersection with the exclude tags then we can not show
        # the current document
        #
        if(count($this->excludeTags) > 0) {
            if(count(array_intersect($tags, $this->excludeTags)) > 0) {
                $this->_showDebugMsg('skip');
                return false;
            }
        }

        # If the intersection with the include tags is not equal (in size) to the
        # array of include tags, we must skip the current document.
        if(count($this->includeTags) > 0) {
            $intersection = array_intersect($tags, $this->includeTags);
            if(count($intersection) != count($this->includeTags)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate the actual table content...
     */
    function _tableContent() {
        $doWideDesc = $this->opts ["widedesc"];

        if(!$this->opts ["noheader"]) {
            $this->_tableHeaderRowOpen();
            foreach($this->hdrs as $index => $hdr) {
                $this->_tableHeaderCellOpen(
                    $this->_getCellClassForCol($this->cols [$index])
                );
                $this->_put($hdr);
                $this->_tableHeaderCellClose($index);
            }
            $this->_tableHeaderRowClose();
        }

        foreach($this->pages as $page) {

            if(!$this->_hasTag($page)) continue;

            $this->rowNumber += 1;

            $this->_tableRowOpen();
            foreach($this->cols as $index => $col) {
                $this->_tableCellOpen(1, $this->_getCellClassForCol($col));
                $this->_tableCellContent($page, $col);
                $this->_tableCellClose($index);
            }
            $this->_tableRowClose();
            if($doWideDesc) {
                $this->_tableRowOpen();
                $this->_tableCellOpen(count($this->cols), "desc");
                $this->_tableCellContent($page, "widedesc");
                $this->_tableCellClose(0);
                $this->_tableRowClose();
            }
        }
    }

    /**
     * Write data to the output stream
     */
    function _put($data) {
        if($data == NULL || $data == '')
            return;

        switch($this->rdrMode) {
            case 'xhtml':
                $this->rdr->doc .= $data;
                break;
            case 'latex':
                $this->rdr->put($data);
                break;
        }
    }

    /**
     * Write a date to the output stream
     */
    function _putDate($date) {
        $this->_put(strftime($this->dformat, $date));
    }

    function _putCmdNl($cmd) {
        $this->rdr->putcmdnl($cmd);
    }

    function _putNewLine() {
        if($this->modeIsLatex) {
            $this->_putCmdNl('newline');
        } else {
            $this->_put('<br />');
        }
    }

    /**
     * Do the real work
     */
    function _dir($data) {
        if(!$this->_parseOptions($data))
            return false;

        //
        // If we already did the latex pass, skip the xhtml pass
        //
        if($this->processedLatex && $this->rdrMode == 'xhtml') {
            //return false ;
        }

        //
        // Generate the actual table...
        //
        $this->_tableOpen();
        $this->_tableContent();
        $this->_tableClose();

        return true;
    }

} // syntax_plugin_dir

//Setup VIM: ex: et ts=2 enc=utf-8 :
