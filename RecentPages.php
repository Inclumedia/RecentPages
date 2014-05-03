<?php
/**
 * RecentPages extension - Provides a parser hook to list recently created
 * or random pages
 * 
 * @version 0.1.12 - 2014-04-21
 * 
 * @link https://www.mediawiki.org/wiki/Extension:RecentPages Documentation
 * @link https://www.mediawiki.org/wiki/Extension_talk:RecentPages Support
 * @link https://github.com/leucosticte/RecentPages Source code
 *
 * @author Nathon Larson (Leucosticte)
 * @copyright (C) 2013 Nathan Larson (Leucosticte)
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */

// Prevent direct calls
if ( !defined( 'MEDIAWIKI' ) ) {
        die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}

// Version of the extension
define( 'RP_VERSION', '0.1.13' );

// Extension credits that show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
    'path' => __FILE__,
    'name' => 'Recently Created Page List',
    'url' => 'https://www.mediawiki.org/wiki/Extension:Recent_Pages',
    'version' => RP_VERSION,
    'author' => 'Nathan Larson',
    'descriptionmsg' => 'recentpages-desc'
);

// Minimum page length of a randomly-selected article
$wgRecentPagesDefaultMinimumLength = 0;
// Default number of articles to pull back
$wgRecentPagesDefaultLimit = 6;
// Maximum number of attempts to get a unique random article
$wgRecentPagesMaxAttempts = 1000;
// Due to a glitch, leave this set to true
$wgRecentPagesDisableOtherNamespaces = true;
// Shall we sort by default?
$wgRecentPagesDefaultSort = false;

// Paths to files
$wgExtensionMessagesFiles['RecentPages'] = __DIR__ . '/RecentPages.i18n.php';

// Avoid unstubbing $wgParser on setHook() too early on modern (1.12+) MW versions, as
// per r35980
if ( defined( 'MW_SUPPORTS_PARSERFIRSTCALLINIT' ) ) {
    $wgHooks['ParserFirstCallInit'][] = 'rpInit';
} else {
    $wgExtensionFunctions[] = 'rpInit';
}

function rpInit() {
    // TODO: Remove global
    global $wgParser;
    $wgParser->setHook ( 'recent', 'RecentPages::showRecentPages' );
    $wgParser->setHook ( 'random', 'RecentPages::showRandomPages' );
    return true;
}

class RecentPages {
    public static function showRecentPages( $text, $args, $parser ) {
        global $wgBedellPenDragonResident;
        global $wgUser;
        global $wgContentNamespaces;
        global $wgRecentPagesDefaultMinimumLength;
        global $wgRecentPagesDefaultLimit;
        global $wgRecentPagesMaxAttempts;
        global $wgRecentPagesDisableOtherNamespaces;
        global $wgRecentPagesDefaultSort;

        # Prevent caching
        $parser->disableCache();

        $skin = $wgUser->getSkin();

        $ret = "";

        $sort = $wgRecentPagesDefaultSort;
        if ( isset ( $args['sort'] ) ) {
            $sort = true;
        }
        // "limit" is what to limit the database query to
        $limit = $wgRecentPagesDefaultLimit;
        if ( isset( $args['limit'] ) ) {
            if ( is_numeric( $args['limit'] ) ) {
                $limit = $args['limit'];
            }
        }
        $bulletChar = "*";
        if ( isset ( $args['bulletchar'] ) ) {
            $bulletChar = $args['bulletchar'];
        }
        $liChar = "<li>";
        if ( isset ( $args['lichar'] ) ) {
            $liChar = $args['lichar'];
        }
        $endliChar = "</li>";
        if ( isset ( $args['endlichar'] ) ) {
            $endliChar = $args['endlichar'];
        }
        $ulChar = "<ul>";
        if ( isset ( $args['ulchar'] ) ) {
            $ulChar = $args['ulchar'];
        }
        $endulChar = "</ul>";
        if ( isset ( $args['endulchar'] ) ) {
            $endulChar = $args['endulchar'];
        }
        $endChar = "\n";
        if ( isset ( $args['endchar'] ) ) {
            $endChar = $args['endchar'];
        }
        $parsedEndChar = "";
        if ( isset ( $args['parsedendchar'] ) ) {
            $parsedEndChar = $args['parsedendchar'];
        }
        $excludeCat = "";
        if ( isset ( $args['excludecat'] ) ) {
            $excludeCat = $args['excludecat'];
        }
        // "minimum" is the minimum page length
        $minimum = $wgRecentPagesDefaultMinimumLength;
        if ( isset( $args['minimum'] ) ) {
            if ( is_numeric( $args['minimum'] ) ) {
                $minimum = $args['minimum'];
            }
        }
        $prop = array();
        $displayTitles = array();
        // "maxresults" is what to limit the results to
        $maxResults = $limit;
        if ( isset ( $wgBedellPenDragonResident ) ) {
            if ( !isset ( $args['maxresults'] ) ) {
                $maxResults = $limit;
            } else {
                $maxResults = $args['maxresults'];
            }
        }
        if ( isset( $args['random'] ) ) {
            if ( ! isset ( $args[ 'limit' ] ) ) {
                $args['limit'] = $wgRecentPagesDefaultLimit;
            }
            $namespace = MWNamespace::getValidNamespaces();
            if ( isset( $args['namespace'] ) ) {
                switch ( $args[ 'namespace' ] ) {
                    case 'all':
                        $namespace = MWNamespace::getValidNamespaces();
                        break;
                    case 'content':
                        $namespace = MWNamespace::getContentNamespaces();
                        break;
                    default:
                        $namespace = array ( RecentPages::rpGetNSID ( $args['namespace'] ) );
                        if ( trim( strtolower( $args['namespace'] ) ) === 'main' ) {
                            $namespace = array( 0 );
                        }
                        if ( !$namespace && $namespace !== array ( 0 ) ) {
                            // If an invalid namespace name was given, use all possible
                            // namespaces
                            $namespace = MWNamespace::getValidNamespaces();
                        } else {
                            $setTheNamespace = true;
                        }
                }
            }
            $attempts = 0;
            $retArrayPageId = array();
            for ( $count = 0; $count < $args['limit']; $count++ ) {
                // Avoid infinite loops
                $titleCandidate = false;
                while ( $attempts < $wgRecentPagesMaxAttempts && !$titleCandidate ) {
                    $titleCandidate = false;
                    while ( !$titleCandidate && $attempts < $wgRecentPagesMaxAttempts ) {
                        $attempts++;
                        $randomPage = new RecentPagesRandomPageWithMinimumLength (
                            $minimum, $namespace );
                        $titleCandidate = $randomPage->getRandomTitle();
                        if ( in_array ( $titleCandidate->getArticleID(), $retArrayPageId ) ) {
                            $titleCandidate = false;
                        }
                        if ( $titleCandidate ) {
                            if ( ( !$wgRecentPagesDisableOtherNamespaces
                            || $namespace === array ( 0 ) ) &&
                            !in_array ( $titleCandidate->getNamespace(), $namespace ) ) {
                                $titleCandidate = false;
                            } elseif ( isset ( $args['prop'] ) && isset (
                                $wgBedellPenDragonResident ) ) {
                                $titleFullText = $titleCandidate->getFullText();
                                $propValue = BedellPenDragon::renderGetBpdProp( $parser,
                                    $titleFullText, $args['prop'], true );
                                if ( $propValue == BPD_NOPROPSET ) {
                                    $titleCandidate = false;
                                }
                            }
                        }
                    }
                    if ( $titleCandidate && $attempts < $wgRecentPagesMaxAttempts ) {
                        $retArrayPageId [ $count ] = $titleCandidate->getArticleID();
                        $retArray[ $count ] = $titleCandidate;
                    }
                }
            }
            if ( isset ( $retArray ) ) {
                $numRows = count ( $retArray );
            }
            $nextArray = array();
            foreach ( $retArray as $retArrayElement ) {
                $nextArray[] = array(
                    'page_namespace' => $retArrayElement->getNamespace(),
                    'page_title' => $retArrayElement->getText(),
                    'page_id' => $retArrayElement->getArticleID()
                );
            }
            $retArray = $nextArray;
        } else {
            $limitArr = array( "ORDER BY" => "page_id desc limit $wgRecentPagesDefaultLimit" );
            if ( isset( $args['limit'] ) ) {
                if ( is_numeric( $args['limit'] ) ) {
                        $limitArr = array( "ORDER BY" => "page_id desc limit " . $args['limit'] );
                } elseif ( $args['limit'] == "none" ) {
                    $limitArr = "";
                }
            }
            if ( isset( $args['namespace'] ) ) {
                switch ( $args['namespace'] ) {
                    case 'all':
                        $where = array ( "page_is_redirect" => 0 );
                        if ( $minimum > 0 ) {
                            $where[] = "page_len>{$minimum}";
                        }
                        break;
                    case 'content':
                        $where = "page_is_redirect=0 AND (";
                        $isFirstOne = true;
                        foreach ( $wgContentNamespaces as $thisNameSpace ) {
                            if ( !$isFirstOne ) {
                                $where .= " OR ";
                            }
                            $isFirstOne = false;
                            $where .= "page_namespace = $thisNameSpace";
                        }
                        $where .= ")";
                        if ( $minimum > 0 ) {
                                $where .= " AND page_len>{$minimum}";
                        }
                        break;
                    default:
                        $where = array (
                            "page_namespace" => RecentPages::rpGetNSID( $args['namespace'] ),
                            "page_is_redirect" => 0
                        );
                        if ( $minimum > 0 ) {
                            $where[] = "page_len>{$minimum}";
                        }
                        break;
                }
            } else {
                $where = array (
                    "page_namespace" => 0,
                    "page_is_redirect" => 0
                );
                if ( $minimum > 0 ) {
                        $where[] = "page_len>{$minimum}";
                }
            }
            $tables = array( 'page', 'page_props' );
            $fields = array( 'page_id', 'page_title', 'page_namespace', 'pp_page', 'pp_propname',
                'pp_value' );
            if ( isset( $args['prop'] ) ) {
                $typeJoin = 'INNER JOIN';
            } else {
                $typeJoin = 'LEFT JOIN';
            }
            $join = array( 'page_props' => array( $typeJoin, array(
                    'page_id=pp_page' ) ) );
            $dbr = wfGetDB( DB_SLAVE );
            $res = $dbr->select(
                $tables,
                $fields,
                $where,
                __METHOD__,
                $limitArr,
                $join
            );
            if ( $res ) {
                $numRows = $dbr->numRows( $res );
            }
        }
        if ( !isset ( $retArray ) ) {
            $retArray = array();
        }

        if ( isset ( $res ) ) {
                $excludeThesePageIds = array();
                if ( $excludeCat ) {
                        $category = Category::newFromName( $excludeCat );
                        $categoryMembers = $category->getMembers();
                        foreach( $categoryMembers as $categoryMember ) {
                                $excludeThesePageIds[] = $categoryMember->getArticleID();
                        }
                }
            $numRows = 0;
            foreach ( $res as $row ) {
                if( !in_array( $row->page_id, $excludeThesePageIds ) ) {
                        $title = array(
                            'page_id' => $row->page_id,
                            'page_namespace' => $row->page_namespace,
                            'page_title' => $row->page_title
                        );
                        if ( isset ( $args['prop'] ) ) {
                            if ( $row->pp_propname == 'bpd_' . $args['prop'] ) {
                                $prop[RecentPages::getFullText( $title )] = $parser->recursiveTagParse (
                                    BedellPenDragon::stripRefTags ( $row->pp_value ) );
                                $retArray[] = $title;
                                $numRows++;
                            }
                        } else {
                            if ( !in_array( $title, $retArray ) ) {
                                $retArray[] = $title;
                                $numRows++;
                            }
                        }
                        if ( $row->pp_propname == 'displaytitle' ) {
                            $displayTitles[$row->page_id] = $row->pp_value;
                        }
                        if ( $numRows == $maxResults ) {
                                break;
                        }
                }
            }
            #$args['random'] = true;

        }
        if ( $sort ) {
            #if ( isset ( $args['random'] ) ) {
            #    usort ( $retArray, 'RecentPages::cmpTitle' );
            #} else {
                usort ( $retArray, 'RecentPages::cmpTitleArray' );
            #}
        }
        if ( $retArray ) {
            // Handle situations where we're getting a property
            if ( isset ( $args['prop'] ) && isset ( $wgBedellPenDragonResident )
                && isset( $args['random'] ) ) {
                $numRows = 0;
                $newRetArray = array();
                foreach ( $retArray as $retArrayElement ) {
                    $retArrayElementFullText = RecentPages::getFullText ( $retArrayElement );
                    $propValue = BedellPenDragon::renderGetBpdProp( $parser,
                        $retArrayElementFullText,
                        $args['prop'], true );
                    if ( ( $propValue != BPD_NOPROPSET && !isset ( $args['invertprop'] ) ) ||
                          ( $propValue == BPD_NOPROPSET && isset ( $args['invertprop'] ) ) ) {
                        $newRetArray[] = $retArrayElement;
                        $prop[$retArrayElementFullText] = $propValue;
                        $numRows++;
                    }
                    if ( $numRows == $maxResults ) {
                        break;
                    }
                }
                $retArray = $newRetArray;
            }
            // Display differently depending on how many columns there are
            if ( !isset ( $args['columns'] ) ) {
                $args['columns'] = 1;
            }
            if ( $args['columns'] == 3 && $numRows > 2 ) {
                $ret = "{|\n|-\n| valign=\"top\" style=\"width:33%\"|\n";
                for ( $i = 1; $i <= ceil ( $numRows / 3 ); $i++ ) {
                    $title = $retArray[ $i - 1 ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                    $ret .= $bulletChar . $parser->internalParse ( '[[' . RecentPages::getFullText ( $title )
                        . '|' . $html . ']]' ) . $endChar
                        . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "| valign=\"top\"|\n";
                for ( $i = ceil ( $numRows / 3 ); $i < $numRows * ( 2 / 3); $i++ ) {
                    $title = $retArray[ $i ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                    $ret .= $bulletChar . $parser->internalParse ( '[[' . RecentPages::getFullText ( $title )
                        . '|' . $html . ']]' ) . $endChar
                        . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "| valign=\"top\"|\n";
                for ( $i = ceil ( $numRows * ( 2 / 3 ) ); $i < $numRows; $i++ ) {
                    $title = $retArray[ $i ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                    $ret .= $bulletChar . $parser->internalParse ( '[[' . RecentPages::getFullText ( $title )
                        . '|' . $html . ']]' ) . $endChar
                        . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "|}";
                $ret = $parser->doTableStuff ( $ret );
            } elseif ( $args['columns'] != 2 || $numRows == 1 ) {
                $ret = "<div id='recentpages'><ul>";
                for ( $i = 1; $i <= $numRows; $i++ ) {
                    $title = $retArray[ $i - 1 ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                        $fullText = RecentPages::getFullText( $title );
                        $urlEncoded = wfUrlencode ( $fullText );
                        $ret .= $liChar;
                        if ( isset ( $args['stripfromfront'] ) ) {
                            if ( substr ( $html, 0, strlen ( $args['stripfromfront'] ) ) ==
                                $args['stripfromfront'] ) {
                                $html = preg_replace( '/' . $args['stripfromfront'] . '/',
                                    '', $html, 1 );
                            }
                        }
                        if ( isset ( $args['prop'] ) && isset ( $wgBedellPenDragonResident ) ) {
                            if ( isset ( $args['str_replace_title'] ) ) {
                                $str_replaced = str_replace ( '$1', $fullText,
                                    $args['str_replace_title'] );
                                $str_replaced = str_replace ( '$2', $html,
                                    $str_replaced );
                                $ret .= $parser->internalParse ( $str_replaced );
                            } else {
                                #$ret .= $parser->internalParse ( $fullText );
                            }
                            if ( isset ( $args['spaces_between'] ) ) {
                                $ret .= ' ';
                            }
                            if ( isset ( $args['str_replace_prop'] ) ) {
                                $ret .= str_replace ( '$1', $prop[$fullText],
                                    $args['str_replace_prop'] );
                            } else {
                                $ret .= $prop[$fullText];
                            }
                        } else {
                            $ret .= $parser->internalParse ( '[[' . $fullText
                            . '|' . $html . ']]' );
                        }
                        if ( isset ( $args['editlink'] ) ) {
                            $replacedItWith = $parser->internalParse( str_replace ( '$1',
                                $urlEncoded, $args['editlink'] ) );
                            $ret .= $replacedItWith;
                        }
                        $ret .= $endliChar . $endChar
                            . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "</ul></div>\n";
            } else {
                $ret = "{|\n|-\n| valign=\"top\" style=\"width:50%\"|\n";
                for ( $i = 1; $i <= ceil ( $numRows / 2 ); $i++ ) {
                    $title = $retArray[ $i - 1 ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                        $ret .= $bulletChar . $parser->internalParse ( '[[' . RecentPages::getFullText ( $title )
                            . '|' . $html . ']]' ) . $endChar
                            . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "| valign=\"top\"|\n";
                for ( $i = ceil ( $numRows / 2 ); $i < $numRows; $i++ ) {
                    $title = $retArray[ $i ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                        $ret .= $bulletChar . $parser->internalParse ( '[[' . RecentPages::getFullText ( $title )
                            . '|' . $html . ']]' ) . $endChar
                            . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "|}";
                $ret = $parser->doTableStuff ( $ret );
            }
        }
        return $ret;
    }

    // Function to get namespace id from name
    public static function rpGetNSID( $namespace ) {
        if ( $namespace == "" ) {
            return 0;
        } else {
            $ns = new MWNamespace();
            return $ns->getCanonicalIndex( trim( strtolower( $namespace ) ) );
        }
    }

    public static function getDisplayTitle ( $title, $args, $displayTitles ) {
        $id = $title['page_id'];
        if ( !isset ( $args['random'] ) ) {
            if ( isset( $displayTitles[$id] ) ) {
                return $displayTitles[$id];
            }
        } else {
            $dbr = wfGetDB( DB_SLAVE );
            $row = $dbr->selectRow ( 'page_props', array ( 'pp_value' ),
                array ( 'pp_page' => $id, 'pp_propname' => 'displaytitle' ) );
            if ( $row ) {
                return $row->pp_value;
            }
        }
        return RecentPages::getFullText ( $title );
    }

    // Get some random pages
    public static function showRandomPages ( $text, $args, $parser ) {
        $args[ 'random' ] = true;
        return self::showRecentPages ( $text, $args, $parser );
    }

    // Alphabetize arrays of titles
    public static function cmpTitleArray ( $a, $b ) {
        return strcmp ( RecentPages::getFullText ( $a ), RecentPages::getFullText ( $b ) );
    }

    public static function cmpTitle ( $a, $b ) {
        return strcmp ( $a->getPrefixedText(), $b->getPrefixedText );
    }

    public static function getFullText( $title ) {
        $namespaces = MWNamespace::getCanonicalNamespaces();
        $output = '';
        if ( $title['page_namespace'] != 0 ) {
            $output = $namespaces[$title['page_namespace']] . ':';
        }
        return $output . str_replace('_', ' ', $title['page_title'] );
    }
}

class RecentPagesRandomPageWithMinimumLength extends RandomPage {
    public function __construct ( $minimumLength = 0, $selNamespaces = array() ) {
        $this->extra = array ( 'page_len >= ' . $minimumLength );
        parent::__construct( 'Randompage' );
    }
}
