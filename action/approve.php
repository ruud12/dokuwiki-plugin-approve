<?php

if(!defined('DOKU_INC')) die();
define(APPROVED, 'Approved');
define(READY_FOR_APPROVAL, 'Ready for approval');

define(METADATA_VERSION_KEY, 'plugin_approve_version');

class action_plugin_approve_approve extends DokuWiki_Action_Plugin {

    private $hlp;
    function __construct(){
        $this->hlp = plugin_load('helper', 'approve');
    }

    function register(Doku_Event_Handler $controller) {
		
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, handle_approve, array());
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, handle_viewer, array());
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, handle_diff_accept, array());
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, handle_display_banner, array());
        $controller->register_hook('HTML_SHOWREV_OUTPUT', 'BEFORE', $this, handle_showrev, array());
        // ensure a page revision is created when summary changes:
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'handle_pagesave_before');
    }
	
	function handle_diff_accept(Doku_Event $event, $param) {
		global $ID;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;
		
		if ($event->data == 'diff' && isset($_GET['approve'])) {
			ptln('<a href="'.DOKU_URL.'doku.php?id='.$_GET['id'].'&approve=approve">'.$this->getLang('approve').'</a>');
		}

        if ($event->data == 'diff' && isset($_GET['ready_for_approval']) && $this->getConf('ready_for_approval') === 1) {
			ptln('<a href="'.DOKU_URL.'doku.php?id='.$_GET['id'].'&ready_for_approval=ready_for_approval">'.$this->getLang('approve_ready').'</a>');
		}
	}

	function handle_showrev(Doku_Event $event, $param) {
		global $ID, $REV;

		$last = $this->find_lastest_approved();
		if ($last == $REV)
			$event->preventDefault();
	}

	function can_approve() {
		global $ID;
		return auth_quickaclcheck($ID) >= AUTH_DELETE;
	}

		function can_edit() {
		global $ID;
		return auth_quickaclcheck($ID) >= AUTH_EDIT;
	}

	function handle_approve(Doku_Event $event, $param) {
		global $ID, $REV, $INFO;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;
		
		if ($event->data == 'show' && isset($_GET['approve'])) {
		    if ( ! $this->can_approve()) return;

		    //create new page revison
            saveWikiText($ID, rawWiki($ID), APPROVED);

			header('Location: ?id='.$ID);
		}

		if ($event->data == 'show' && isset($_GET['ready_for_approval'])) {
		    if ( ! $this->can_edit()) return;
		    
			//change last commit comment to Approved
			$meta = p_read_metadata($ID);
			$meta[current][last_change][sum] = $meta[persistent][last_change][sum] = READY_FOR_APPROVAL;
			$meta[current][last_change][user] = $meta[persistent][last_change][user] = $INFO[client];
			if (!array_key_exists($INFO[client], $meta[current][contributor])) {
			    $meta[current][contributor][$INFO[client]] = $INFO[userinfo][name];
			    $meta[persistent][contributor][$INFO[client]] = $INFO[userinfo][name];
			}
			p_save_metadata($ID, $meta);
			//update changelog
			//remove last line from file
			$changelog_file = metaFN($ID, '.changes');
			$changes = file($changelog_file, FILE_SKIP_EMPTY_LINES);
			$lastLogLine = array_pop($changes);
			$info = parseChangelogLine($lastLogLine);
			
			$info[user] = $INFO[client];
			$info[sum] = APPROVED;
			
			$logline = implode("\t", $info)."\n";
			array_push($changes, $logline);
			
			io_saveFile($changelog_file, implode('', $changes));
			
			header('Location: ?id='.$ID);
		}		
	}
    function handle_viewer(Doku_Event $event, $param) {
        global $REV, $ID;
        if ($event->data != 'show') return;
        if (auth_quickaclcheck($ID) > AUTH_READ || ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID))) return;
        
	    $last = $this->find_lastest_approved();
	    //no page is approved
		if ($last == -1) return;
		//approved page is the newest page
		if ($last == 0) return;
		
		//if we are viewing lastest revision, show last approved
		if ($REV == 0) header("Location: ?id=$ID&rev=$last");
	}
	function find_lastest_approved() {
		global $ID;
		$m = p_get_metadata($ID);
		$sum = $m['last_change']['sum'];
		if ($sum == APPROVED)
			return 0;

		$changelog = new PageChangeLog($ID);
		//wyszukaj najnowszej zatwierdzonej
		//poszukaj w dół
		$chs = $changelog->getRevisions(0, 10000);
		foreach ($chs as $rev) {
			$ch = $changelog->getRevisionInfo($rev);
			if ($ch['sum'] == APPROVED)
				return $rev;
		}
		return -1;
	}

    function handle_display_banner(Doku_Event $event, $param) {
		global $ID, $REV, $INFO;
		
		if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $ID)) return;
        if ($event->data != 'show') return;
		if (!$INFO['exists']) return;
		
		$sum = $this->hlp->page_sum($ID, $REV);

		ptln('<div class="approval '.($sum == APPROVED ? 'approved_yes' : ($sum == READY_FOR_APPROVAL && $this->getConf('ready_for_approval') === 1 ? 'approved_ready' :'approved_no')).'">');

		tpl_pageinfo();
		ptln(' | ');
		$last_approved_rev = $this->find_lastest_approved();
		if ($sum == APPROVED) {
		    $version = p_get_metadata($ID, METADATA_VERSION_KEY);
		    if (!$version) {
		        $version = $this->calculateVersion($ID);
		        p_set_metadata($ID, array(METADATA_VERSION_KEY => $version));
            }

			ptln('<span>'.$this->getLang('approved').'</span> (' . $this->getLang('version') .  ': ' . $version
                 . ')');
			if ($REV != 0 && auth_quickaclcheck($ID) > AUTH_READ) {
				ptln('<a href="'.wl($ID).'">');
				ptln($this->getLang(p_get_metadata($ID, 'last_change sum') == APPROVED ? 'newest_approved' : 'newest_draft'));
				ptln('</a>');
			} else if ($REV != 0 && $REV != $last_approved_rev) {
				ptln('<a href="'.wl($ID).'">');
				ptln($this->getLang('newest_approved'));
				ptln('</a>');
			}
		} else {
			ptln('<span>'.$this->getLang('draft').'</span>');

			if ($sum == READY_FOR_APPROVAL && $this->getConf('ready_for_approval') === 1) {
				ptln('<span>| '.$this->getLang('marked_approve_ready').'</span>');
			}


			if ($last_approved_rev == -1) {
			    if ($REV != 0) {
				    ptln('<a href="'.wl($ID).'">');
				    	ptln($this->getLang('newest_draft'));
				    ptln('</a>');
				}
			} else {
				if ($last_approved_rev != 0)
					ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev)).'">');
				else
					ptln('<a href="'.wl($ID).'">');

					ptln($this->getLang('newest_approved'));
				ptln('</a>');
			}

			if ($REV == 0 && $this->can_edit() && $sum != READY_FOR_APPROVAL && $this->getConf('ready_for_approval') === 1) {
				ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev, 'do' => 'diff',
				'ready_for_approval' => 'ready_for_approval')).'">');
					ptln($this->getLang('approve_ready'));
				ptln('</a>');
			}

			//można zatwierdzać tylko najnowsze strony
			if ($REV == 0 && $this->can_approve()) {
				ptln('<a href="'.wl($ID, array('rev' => $last_approved_rev, 'do' => 'diff',
				'approve' => 'approve')).'">');
					ptln($this->getLang('approve'));
				ptln('</a>');
			}


		}
		ptln('</div>');
	}

    /**
     * Check if the page has to be changed
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_pagesave_before(Doku_Event $event, $param) {
        $id = $event->data['id'];
        if ($this->hlp->in_namespace($this->getConf('no_apr_namespaces'), $id)) return;

        //save page if summary is provided
        if($event->data['summary'] == APPROVED) {
            $event->data['contentChanged'] = true;

            $version = p_get_metadata($id, METADATA_VERSION_KEY);

            //calculate current version
            if (!$version) {
                $version = $this->calculateVersion($id);
            } else {
                $version += 1;
            }

            p_set_metadata($id, array(METADATA_VERSION_KEY => $version));
        }
    }

    /**
     * Calculate current version
     *
     * @param $id
     * @return int
     */
    protected function calculateVersion($id) {
        $version = 1;

        $changelog = new PageChangeLog($id);
        $first = 0;
        $num = 100;
        while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
            foreach ($revs as $rev) {
                $revInfo = $changelog->getRevisionInfo($rev);
                if ($revInfo['sum'] == APPROVED) {
                    $version += 1;
                }
            }
            $first += $num;
        }

        return $version;
    }

}
