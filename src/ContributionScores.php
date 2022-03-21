<?php
/** \file
 * \brief Contains code for the ContributionScores Class (extends SpecialPage).
 */

use MediaWiki\Extension\ArticleScores\ArticleScores;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

/// Special page class for the Contribution Scores extension
/**
 * Special page that generates a list of wiki contributors based
 * on edit diversity (unique pages edited) and edit volume (total
 * number of edits.
 *
 * @ingroup Extensions
 * @author Tim Laqua <t.laqua@gmail.com>
 */
class ContributionScores extends IncludableSpecialPage {
    const CONTRIBUTIONSCORES_MAXINCLUDELIMIT = 50;

    const CONTRIBUTIONSCORES_VALIDMETRICS = [
        'score',
        'changes',
        'pages',
        'creations',
        'characters',
        'changeswithcomments',
        'score2'
    ];

    public function __construct() {
        parent::__construct( 'ContributionScores' );
    }

    public static function onPageSaveComplete( WikiPage $wikiPage, UserIdentity $user ) {
        foreach( self::CONTRIBUTIONSCORES_VALIDMETRICS as $metric ) {
            MediaWikiServices::getInstance()->getMainWANObjectCache()->delete(
                self::getWANObjectCacheKey( $user, $metric )
            );
        }
    }

    public static function onParserFirstCallInit( Parser $parser ) {
        $parser->setFunctionHook( 'cscore', [ self::class, 'efContributionScoresRender' ] );
    }

    public static function getMetricValue( User $user, $metric = 'score', $dateStart = 0, $dateEnd = 0 ) {
        global $wgContribScoreDisableCache;

        if( !$user->isRegistered() ) {
            return false;
        }

        $callback = function( $oldValue, &$ttl, array &$setOpts ) use ( $user, $metric, $dateStart, $dateEnd ) {
            global $wgContribScoreIncludeNamespaces;

            $metricValue = 0;

            $dbr = wfGetDB( DB_REPLICA );
            $setOpts += Database::getCacheSetOptions( $dbr );

            // Use the current alias instead of the revision table name if the metric is 'characters'
            $revTable = $metric != 'characters' ? 'revision' : 'current';

            $revWhere = ActorMigration::newMigration()->getWhere( $dbr, 'rev_user', $user );

            if( !is_array( $revWhere[ 'conds' ] ) ) {
                $revWhere[ 'conds' ] = [ $revWhere[ 'conds' ] ];
            }

            // Add table prefix if potentially ambiguous
            if( $revTable != 'revision' && isset( $revWhere[ 'joins' ][ 'temp_rev_user' ] ) ) {
                $revWhere[ 'joins' ][ 'temp_rev_user' ][ 1 ] = 'temp_rev_user.revactor_rev = ' . $revTable . '.rev_id';
            }

            if( !empty( $wgContribScoreIncludeNamespaces ) ) {
                $revWhere[ 'tables' ][ 'temp_page' ] = 'page';

                $revWhere[ 'joins' ][ 'temp_page' ] = [
                    'JOIN',
                    'temp_page.page_id = ' . $revTable . '.rev_page'
                ];

                $sanitizedNamespaces = array_map( [ $dbr, 'addQuotes' ], $wgContribScoreIncludeNamespaces );

                $revWhere[ 'conds' ][] = 'temp_page.page_namespace IN (' . implode( ', ', $sanitizedNamespaces ) . ')';
            }

            if( $dateStart > 0 ) {
                $sanitizedDateStart = $dbr->addQuotes( $dateStart );

                $revWhere[ 'conds' ][] = $revTable . '.rev_timestamp > \'' . $dbr->timestamp( $sanitizedDateStart ) . '\'';
            }

            if( $dateEnd > 0 ) {
                $sanitizedDateEnd = $dbr->addQuotes( $dateEnd );

                $revWhere[ 'conds' ][] = $revTable . '.rev_timestamp <= \'' . $dbr->timestamp( $sanitizedDateEnd ) . '\'';
            }

            if( $metric === 'score' ) {
                $res = $dbr->select(
                    [ $revTable ] + $revWhere[ 'tables' ],
                    'COUNT(DISTINCT rev_page)+SQRT(COUNT(rev_id)-COUNT(DISTINCT rev_page))*2 AS wiki_rank',
                    $revWhere[ 'conds' ],
                    __METHOD__,
                    [],
                    $revWhere[ 'joins' ]
                );
                $row = $dbr->fetchObject( $res );
                $metricValue = round( $row->wiki_rank, 0 );
            } elseif( $metric === 'changes' ) {
                $res = $dbr->select(
                    [ $revTable ] + $revWhere[ 'tables' ],
                    'COUNT(rev_id) AS rev_count',
                    $revWhere[ 'conds' ],
                    __METHOD__,
                    [],
                    $revWhere[ 'joins' ]
                );
                $row = $dbr->fetchObject( $res );
                $metricValue = $row->rev_count;
            } elseif( $metric === 'pages' ) {
                $res = $dbr->select(
                    [ $revTable ] + $revWhere[ 'tables' ],
                    'COUNT(DISTINCT rev_page) AS page_count',
                    $revWhere[ 'conds' ],
                    __METHOD__,
                    [],
                    $revWhere[ 'joins' ]
                );
                $row = $dbr->fetchObject( $res );
                $metricValue = $row->page_count;
            } elseif( $metric === 'creations' ) {
                $res = $dbr->select(
                    [ $revTable ] + $revWhere[ 'tables' ],
                    'COUNT(rev_id) AS page_count',
                    array_merge( $revWhere[ 'conds' ], [
                        'revision.rev_parent_id = 0'
                    ] ),
                    __METHOD__,
                    [],
                    $revWhere[ 'joins' ]
                );
                $row = $dbr->fetchObject( $res );
                $metricValue = $row->page_count;
            } elseif( $metric === 'characters' ) {
                // Calculate the number of characters from creations
                $charactersChangedCreationsSubquery = $dbr->buildSelectSubquery(
                    [ $revTable => 'revision' ] + $revWhere[ 'tables' ],
                    [
                        'rev_len' => 'CAST( current.rev_len as SIGNED )'
                    ],
                    array_merge( $revWhere[ 'conds' ], [
                        'current.rev_parent_id = 0'
                    ] ),
                    __METHOD__,
                    [],
                    $revWhere[ 'joins' ]
                );

                $res = $dbr->select(
                    [ 'characters_changed' => $charactersChangedCreationsSubquery ],
                    [ 'characters_added' => 'SUM( rev_len )' ],
                    [ 'rev_len > 0' ]
                );

                $row = $dbr->fetchObject( $res );
                $metricValue = $row->characters_added;

                $charactersChangedEditsSubquery = $dbr->buildSelectSubquery(
                    [
                        $revTable => 'revision',
                        'previous' => 'revision'
                    ] + $revWhere[ 'tables' ],
                    [
                        'rev_len_diff' => 'CAST( current.rev_len as SIGNED ) - CAST( previous.rev_len AS SIGNED )'
                    ],
                    array_merge( $revWhere[ 'conds' ], [
                        'current.rev_parent_id > 0'
                    ] ),
                    __METHOD__,
                    [],
                    [
                        'previous' => [
                            'JOIN',
                            'current.rev_parent_id = previous.rev_id'
                        ]
                    ] + $revWhere[ 'joins' ]
                );

                $res = $dbr->select(
                    [ 'characters_changed' => $charactersChangedEditsSubquery ],
                    [ 'characters_added' => 'SUM( rev_len_diff )' ],
                    [ 'rev_len_diff > 0' ]
                );

                $row = $dbr->fetchObject( $res );
                $metricValue = $metricValue + $row->characters_added;
            } elseif( $metric === 'changeswithcomments' ) {
                // TODO this will need migrated when revision_comment_temp goes away
                // Not if revision_comment_temp.revcomment_comment_id = 1 definitionally means a null comment...
                $res = $dbr->select(
                    [ $revTable, 'revision_comment_temp'
                    ] + $revWhere[ 'tables' ],
                    'COUNT(rev_id) AS rev_comment_count',
                    array_merge( $revWhere[ 'conds' ], [
                        'revision_comment_temp.revcomment_comment_id > 1'
                    ] ),
                    __METHOD__,
                    [],
                    $revWhere[ 'joins' ] + [
                        'revision_comment_temp' => [
                            'JOIN',
                            'revision_comment_temp.revcomment_rev = revision.rev_id'
                        ]
                    ]
                );

                $row = $dbr->fetchObject( $res );
                $metricValue = $row->rev_comment_count;
            } elseif( $metric === 'score2' ) {
                // cscore2 = 0.5 * pages_created + 0.1 * (total_edits + pages_edited + 0.1 * total_edits_with_comments) + 0.001 * characters_added;
                $metricValue = 0.5 * self::getMetricValue( $user, 'creations', $dateStart, $dateEnd )
                    + 0.1 * ( self::getMetricValue( $user, 'changes', $dateStart, $dateEnd )
                        + self::getMetricValue( $user, 'pages', $dateStart, $dateEnd )
                        + 0.1 * self::getMetricValue( $user, 'changeswithcomments', $dateStart, $dateEnd )
                    )
                    + 0.001 * self::getMetricValue( $user, 'characters', $dateStart, $dateEnd );

                $metricValue = round( $metricValue );
            }
            \MediaWiki\Logger\LoggerFactory::getInstance( 'ContributionScores' )->info(
                'Calculating "{metric}" value for {user}', [
                    'metric' => $metric,
                    'user' => $user->getName()
                ]
            );
            return $metricValue;
        };

        if( $wgContribScoreDisableCache || $dateStart || $dateEnd ) {
            $ttl = null;
            $setOpts = [];

            $metricValue = $callback( null, $ttl, $setOpts );
        } else {
            $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
            $cacheKey = self::getWANObjectCacheKey( $user, $metric );

            $metricValue = $cache->getWithSetCallback(
                $cacheKey,
                WANObjectCache::TTL_DAY,
                $callback
            );
        }

        return $metricValue;
    }

    public static function efContributionScoresRender( $parser, $usertext, $metric = 'score' ) {
        global $wgContribScoreDisableCache;

        if ( $wgContribScoreDisableCache ) {
            $parser->getOutput()->updateCacheExpiry( 0 );
        }

        $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $usertext );

        if ( $user instanceof User && $user->isRegistered() ) {
            if( in_array( $metric, self::CONTRIBUTIONSCORES_VALIDMETRICS ) ) {
                global $wgLang;

                $output = $wgLang->formatNum( self::getMetricValue( $user, $metric ) );
            } else {
                $output = wfMessage( 'contributionscores-invalidmetric' )->text();
            }
        } else {
            $output = wfMessage( 'contributionscores-invalidusername' )->text();
        }
        return $parser->insertStripItem( $output, $parser->mStripState );
    }

    /// Generates a "Contribution Scores" table for a given LIMIT and date range

    /**
     * Function generates Contribution Scores tables in HTML format (not wikiText)
     *
     * @param int $days Days in the past to run report for
     * @param int $limit Maximum number of users to return (default 50)
     * @param string|null $title The title of the table
     * @param array $options array of options (default none; nosort/notools)
     * @return string Html Table representing the requested Contribution Scores.
     */
    function genContributionScoreTable( $days, $limit, $title = null, $options = 'none' ) {
        global $wgContribScoreIgnoreBots, $wgContribScoreIgnoreBlockedUsers,
               $wgContribScoreIgnoreUsernames, $wgContribScoreIncludeNamespaces,
               $wgContribScoreMetric, $wgContribScoresUseRealName;

        $opts = explode( ',', strtolower( $options ) );

        $dbr = wfGetDB( DB_REPLICA );

        $revQuery = ActorMigration::newMigration()->getJoin( 'rev_user' );
        $revQuery['tables'] = array_merge( [ 'revision' ], $revQuery['tables'] );

        $revUser = $revQuery['fields']['rev_user'];
        $revUsername = $revQuery['fields']['rev_user_text'];

        $sqlWhere = [];

        $date = 0;
        if ( $days > 0 ) {
            $date = time() - ( 60 * 60 * 24 * $days );
            $sqlWhere[] = 'rev_timestamp > ' . $dbr->addQuotes( $dbr->timestamp( $date ) );
        }

        if ( $wgContribScoreIgnoreBlockedUsers ) {
            $sqlWhere[] = "{$revUser} NOT IN " .
                $dbr->buildSelectSubquery( 'ipblocks', 'ipb_user', 'ipb_user <> 0', __METHOD__ );
        }

        if ( $wgContribScoreIgnoreBots ) {
            $sqlWhere[] = "{$revUser} NOT IN " .
                $dbr->buildSelectSubquery( 'user_groups', 'ug_user', [
                    'ug_group' => 'bot',
                    'ug_expiry IS NULL OR ug_expiry >= ' . $dbr->addQuotes( $dbr->timestamp() )
                ], __METHOD__ );
        }

        if ( count( $wgContribScoreIgnoreUsernames ) ) {
            $listIgnoredUsernames = $dbr->makeList( $wgContribScoreIgnoreUsernames );
            $sqlWhere[] = "{$revUsername} NOT IN ($listIgnoredUsernames)";
        }

        if( !empty( $wgContribScoreIncludeNamespaces ) ) {
            $revQuery[ 'tables' ][ 'temp_page' ] = 'page';

            $revQuery[ 'joins' ][ 'temp_page' ] = [
                'JOIN',
                'temp_page.page_id = rev_page'
            ];

            $sanitizedNamespaces = array_map( [ $dbr, 'addQuotes' ], $wgContribScoreIncludeNamespaces );

            $sqlWhere[] = 'temp_page.page_namespace IN (' . implode( ', ', $sanitizedNamespaces ) . ')';
        }

        if ( $dbr->unionSupportsOrderAndLimit() ) {
            $order = [
                'GROUP BY' => 'rev_user',
                'ORDER BY' => 'page_count DESC',
                'LIMIT' => $limit
            ];
        } else {
            $order = [ 'GROUP BY' => 'rev_user' ];
        }

        $sqlMostPages = $dbr->selectSQLText(
            $revQuery['tables'],
            [
                'rev_user'   => $revUser,
                'page_count' => 'COUNT(DISTINCT rev_page)',
                'creation_count' => 'COUNT(CASE WHEN revision.rev_parent_id = 0 then 1 end)',
                'rev_count'  => 'COUNT(rev_id)',
            ],
            $sqlWhere,
            __METHOD__,
            $order,
            $revQuery['joins']
        );

        if ( $dbr->unionSupportsOrderAndLimit() ) {
            $order['ORDER BY'] = 'rev_count DESC';
        }

        $sqlMostRevs = $dbr->selectSQLText(
            $revQuery['tables'],
            [
                'rev_user' => $revUser,
                'page_count' => 'COUNT(DISTINCT rev_page)',
                'creation_count' => 'COUNT(CASE WHEN revision.rev_parent_id = 0 then 1 end)',
                'rev_count' => 'COUNT(rev_id)',
            ],
            $sqlWhere,
            __METHOD__,
            $order,
            $revQuery['joins']
        );

        $sqlMostPagesOrRevs = $dbr->unionQueries( [ $sqlMostPages, $sqlMostRevs ], false );
        $res = $dbr->select(
            [
                'u' => 'user',
                's' => new Wikimedia\Rdbms\Subquery( $sqlMostPagesOrRevs ),
            ],
            [
                'user_id',
                'user_name',
                'user_real_name',
                'page_count',
                'creation_count',
                'rev_count',
                'wiki_rank' => 'page_count+SQRT(rev_count-page_count)*2',
            ],
            [],
            __METHOD__,
            [
                'ORDER BY' => 'wiki_rank DESC',
                'GROUP BY' => 'user_name',
                'LIMIT' => $limit,
            ],
            [
                's' => [
                    'JOIN',
                    'user_id=rev_user'
                ]
            ]
        );

        // Need to process and store results in a temporary array, since using an alternate scoring metric
        // may result in misranking since the results are sorted by the original contribution score (wiki_rank).
        // TODO actually query the database using the correct metric
        $rows = [];
        foreach ( $res as $row ) {
            if( $wgContribScoreMetric === 'score' ) {
                $row->score = round( $row->wiki_rank );
            } else {
                $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $row->user_name );
                $row->score = self::getMetricValue( $user, $wgContribScoreMetric, $date );
            }

            $rows[] = $row;
        }

        usort( $rows, function( $a, $b ) {
            if( $a->score == $b->score ) {
                return
                    $a->page_count + $a->creation_count + $a->rev_count < $b->page_count + $b->creation_count + $b->rev_count;
            } else {
                return $a->score < $b->score;
            }
        } );

        $sortable = in_array( 'nosort', $opts ) ? '' : ' sortable';

        $output = "<table class=\"wikitable contributionscores plainlinks{$sortable}\" >\n" .
            "<tr class='header'>\n" .
            Html::element( 'th', [], $this->msg( 'contributionscores-rank' )->text() ) .
            Html::element( 'th', [], $this->msg( 'contributionscores-score' )->text() ) .
            Html::element( 'th', [], $this->msg( 'contributionscores-pages' )->text() ) .
            Html::element( 'th', [], $this->msg( 'contributionscores-creations' )->text() ) .
            Html::element( 'th', [], $this->msg( 'contributionscores-changes' )->text() ) .
            Html::element( 'th', [], $this->msg( 'contributionscores-username' )->text() );

        $altrow = '';
        $user_rank = 1;

        $lang = $this->getLanguage();
        foreach ( $rows as $row ) {
            // Use real name if option used and real name present.
            if ( $wgContribScoresUseRealName && $row->user_real_name !== '' ) {
                $userLink = Linker::userLink(
                    $row->user_id,
                    $row->user_name,
                    $row->user_real_name
                );
            } else {
                $userLink = Linker::userLink(
                    $row->user_id,
                    $row->user_name
                );
            }

            $output .= Html::closeElement( 'tr' );
            $output .= "<tr class='{$altrow}'>\n" .
                "<td class='content' style='padding-right:10px;text-align:right;'>" .
                $lang->formatNum( round( $user_rank, 0 ) ) .
                "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
                $lang->formatNum( $row->score ) .
                "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
                $lang->formatNum( $row->page_count ) .
                "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
                $lang->formatNum( $row->creation_count ) .
                "\n</td><td class='content' style='padding-right:10px;text-align:right;'>" .
                $lang->formatNum( $row->rev_count ) .
                "\n</td><td class='content'>" .
                $userLink;

            # Option to not display user tools
            if ( !in_array( 'notools', $opts ) ) {
                $output .= Linker::userToolLinks( $row->user_id, $row->user_name );
            }

            $output .= Html::closeElement( 'td' ) . "\n";

            if ( $altrow == '' && empty( $sortable ) ) {
                $altrow = 'odd ';
            } else {
                $altrow = '';
            }

            $user_rank++;
        }
        $output .= Html::closeElement( 'tr' );
        $output .= Html::closeElement( 'table' );

        $dbr->freeResult( $res );

        if ( !empty( $title ) ) {
            $output = Html::rawElement( 'table',
                [
                    'style' => 'border-spacing: 0; padding: 0',
                    'class' => 'contributionscores-wrapper',
                    'lang' => htmlspecialchars( $lang->getCode() ),
                    'dir' => $lang->getDir()
                ],
                "\n" .
                "<tr>\n" .
                "<td style='padding: 0px;'>{$title}</td>\n" .
                "</tr>\n" .
                "<tr>\n" .
                "<td style='padding: 0px;'>{$output}</td>\n" .
                "</tr>\n"
            );
        }

        return $output;
    }

    function execute( $par ) {
        $this->setHeaders();

        if ( $this->including() ) {
            $this->showInclude( $par );
        } else {
            $this->showPage();
        }

        return true;
    }

    /**
     * Called when being included on a normal wiki page.
     * Cache is disabled so it can depend on the user language.
     * @param string|null $par A subpage give to the special page
     */
    function showInclude( $par ) {
        $days = null;
        $limit = null;
        $options = 'none';

        if ( !empty( $par ) ) {
            $params = explode( '/', $par );

            $limit = intval( $params[0] );

            if ( isset( $params[1] ) ) {
                $days = intval( $params[1] );
            }

            if ( isset( $params[2] ) ) {
                $options = $params[2];
            }
        }

        if ( empty( $limit ) || $limit < 1 || $limit > self::CONTRIBUTIONSCORES_MAXINCLUDELIMIT ) {
            $limit = 10;
        }
        if ( $days === null || $days < 0 ) {
            $days = 7;
        }

        if ( $days > 0 ) {
            $reportTitle = $this->msg( 'contributionscores-days' )->numParams( $days )->text();
        } else {
            $reportTitle = $this->msg( 'contributionscores-allrevisions' )->text();
        }
        $reportTitle .= ' ' . $this->msg( 'contributionscores-top' )->numParams( $limit )->text();
        $title = Xml::element( 'h4',
                [ 'class' => 'contributionscores-title' ],
                $reportTitle
            ) . "\n";
        $this->getOutput()->addHTML( $this->genContributionScoreTable(
            $days,
            $limit,
            $title,
            $options
        ) );
    }

    /**
     * Show the special page
     */
    function showPage() {
        global $wgContribScoreReports;

        if ( !is_array( $wgContribScoreReports ) ) {
            $wgContribScoreReports = [
                [ 7, 50 ],
                [ 30, 50 ],
                [ 0, 50 ]
            ];
        }

        $out = $this->getOutput();
        $out->addWikiMsg( 'contributionscores-info' );

        foreach ( $wgContribScoreReports as $scoreReport ) {
            list( $days, $revs ) = $scoreReport;
            if ( $days > 0 ) {
                $reportTitle = $this->msg( 'contributionscores-days' )->numParams( $days )->text();
            } else {
                $reportTitle = $this->msg( 'contributionscores-allrevisions' )->text();
            }
            $reportTitle .= ' ' . $this->msg( 'contributionscores-top' )->numParams( $revs )->text();
            $title = Xml::element( 'h2',
                    [ 'class' => 'contributionscores-title' ],
                    $reportTitle
                ) . "\n";
            $out->addHTML( $title );
            $out->addHTML( $this->genContributionScoreTable( $days, $revs ) );
        }
    }

    /**
     * @inheritDoc
     */
    protected function getGroupName() {
        return 'wiki';
    }

    protected static function getWANObjectCacheKey( User $user, string $metric = 'score' ): string {
        return MediaWikiServices::getInstance()->getMainWANObjectCache()->makeKey(
            self::class,
            $user->getName(),
            $metric
        );
    }
}