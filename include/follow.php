<?php /** @file */


//
// Takes a $uid and the channel associated with the uid, and a url/handle and adds a new channel

// Returns an array
//  $return['success'] boolean true if successful
//  $return['abook'] Address book entry joined with xchan if successful
//  $return['message'] error text if success is false.

require_once('include/zot.php');

function new_contact($uid,$url,$channel,$interactive = false, $confirm = false) {



	$result = array('success' => false,'message' => '');

	$a = get_app();
	$is_red = false;
	$is_http = ((strpos($url,'://') !== false) ? true : false);

	if($is_http && substr($url,-1,1) === '/')
		$url = substr($url,0,-1);

	if(! allowed_url($url)) {
		$result['message'] = t('Channel is blocked on this site.');
		return $result;
	}

	if(! $url) {
		$result['message'] = t('Channel location missing.');
		return $result;
	}


	// check service class limits

	$r = q("select count(*) as total from abook where abook_channel = %d and abook_self = 0 ",
		intval($uid)
	);
	if($r)
		$total_channels = $r[0]['total'];

	if(! service_class_allows($uid,'total_channels',$total_channels)) {
		$result['message'] = upgrade_message();
		return $result;
	}


	$arr = array('url' => $url, 'channel' => array());

	call_hooks('follow', $arr);

	if($arr['channel']['success']) 
		$ret = $arr['channel'];
	elseif(! $is_http)
		$ret = zot_finger($url,$channel);

	if($ret && $ret['success']) {
		$is_red = true;
		$j = json_decode($ret['body'],true);
	}

	$my_perms = get_channel_default_perms($uid);

	$role = get_pconfig($uid,'system','permissions_role');
	if($role) {
		$x = get_role_perms($role);
		if($x['perms_follow'])
			$my_perms = $x['perms_follow'];
	}


	if($is_red && $j) {

		logger('follow: ' . $url . ' ' . print_r($j,true), LOGGER_DEBUG);


		if(! ($j['success'] && $j['guid'])) {
			$result['message'] = t('Response from remote channel was incomplete.');
			logger('mod_follow: ' . $result['message']);
			return $result;
		}

		// Premium channel, set confirm before callback to avoid recursion

		if(array_key_exists('connect_url',$j) && ($interactive) && (! $confirm))
			goaway(zid($j['connect_url']));


		// do we have an xchan and hubloc?
		// If not, create them.	

		$x = import_xchan($j);

		if(array_key_exists('deleted',$j) && intval($j['deleted'])) {
			$result['message'] = t('Channel was deleted and no longer exists.');
			return $result;
		}

		if(! $x['success']) 
			return $x;

		$xchan_hash = $x['hash'];

		$their_perms = 0;

		$global_perms = get_perms();

		if( array_key_exists('permissions',$j) && array_key_exists('data',$j['permissions'])) {
			$permissions = crypto_unencapsulate(array(
				'data' => $j['permissions']['data'],
				'key'  => $j['permissions']['key'],
				'iv'   => $j['permissions']['iv']),
				$channel['channel_prvkey']);
			if($permissions)
				$permissions = json_decode($permissions,true);
			logger('decrypted permissions: ' . print_r($permissions,true), LOGGER_DATA);
		}
		else
			$permissions = $j['permissions'];


		foreach($permissions as $k => $v) {
			if($v) {
				$their_perms = $their_perms | intval($global_perms[$k][1]);
			}
		}
	}
	else {

		$their_perms = 0;
		$xchan_hash = '';


		$r = q("select * from xchan where xchan_hash = '%s' or xchan_url = '%s' limit 1",
			dbesc($url),
			dbesc($url)
		);


		if(! $r) {
			// attempt network auto-discovery
			if(strpos($url,'@') && (! $is_http)) {
				$d = discover_by_webbie($url);
			}
			elseif($is_http) {
				if(get_config('system','feed_contacts'))
					$d = discover_by_url($url);
				else {
					$result['message'] = t('Protocol disabled.');
					return $result;
				}
			}
			if($d) {
				$r = q("select * from xchan where xchan_hash = '%s' or xchan_url = '%s' limit 1",
					dbesc($url),
					dbesc($url)
				);
			}
		}
		if($r) {
			$xchan = $r[0];
			$xchan_hash = $r[0]['xchan_hash'];
			$their_perms = 0;
		}
	}


	if(! $xchan_hash) {
		$result['message'] = t('Channel discovery failed.');
		logger('follow: ' . $result['message']);
		return $result;
	}

	$allowed = (($r[0]['xchan_network'] === 'zot' || $r[0]['xchan_network'] === 'rss') ? 1 : 0);

	$x = array('channel_id' => $uid, 'follow_address' => $url, 'xchan' => $r[0], 'allowed' => $allowed, 'singleton' => 0);

	call_hooks('follow_allow',$x);

	if(! $x['allowed']) {
		$result['message'] = t('Protocol disabled.');
		return $result;
	}
	$singleton = intval($x['singleton']);

	if((local_channel()) && $uid == local_channel()) {
		$aid = get_account_id();
		$hash = get_observer_hash();
		$ch = $a->get_channel();
		$default_group = $ch['channel_default_group'];
	}
	else {
		$r = q("select * from channel where channel_id = %d limit 1",
			intval($uid)
		);
		if(! $r) {
			$result['message'] = t('local account not found.');
			return $result;
		}
		$aid = $r[0]['channel_account_id'];
		$hash = $r[0]['channel_hash'];			
		$default_group = $r[0]['channel_default_group'];
	}


	if($is_http) {


		$r = q("select count(*) as total from abook where abook_account = %d and abook_feed = 1 ",
			intval($aid)
		);
		if($r)
			$total_feeds = $r[0]['total'];

		if(! service_class_allows($uid,'total_feeds',$total_feeds)) {
			$result['message'] = upgrade_message();
			return $result;
		}
	}

	if($hash == $xchan_hash) {
		$result['message'] = t('Cannot connect to yourself.');
		return $result;
	}

	$r = q("select abook_xchan, abook_instance from abook where abook_xchan = '%s' and abook_channel = %d limit 1",
		dbesc($xchan_hash),
		intval($uid)
	);


	if($r) {
		$abook_instance = $r[0]['abook_instance'];

		if(($singleton) && strpos($abook_instance,z_root()) === false) {
			if($abook_instance)
				$abook_instance .= ',';
			$abook_instance .= z_root();
		}

		$x = q("update abook set abook_their_perms = %d, abook_instance = '%s' where abook_id = %d",
			intval($their_perms),
			dbesc($abook_instance),
			intval($r[0]['abook_id'])
		);		
	}
	else {
		$closeness = get_pconfig($uid,'system','new_abook_closeness');
		if($closeness === false)
			$closeness = 80;

		$r = q("insert into abook ( abook_account, abook_channel, abook_closeness, abook_xchan, abook_feed, abook_their_perms, abook_my_perms, abook_created, abook_updated, abook_instance )
			values( %d, %d, %d, '%s', %d, %d, %d, '%s', '%s', '%s' ) ",
			intval($aid),
			intval($uid),
			intval($closeness),
			dbesc($xchan_hash),
			intval(($is_http) ? 1 : 0),
			intval(($is_http) ? $their_perms|PERMS_R_STREAM|PERMS_A_REPUBLISH : $their_perms),
			intval($my_perms),
			dbesc(datetime_convert()),
			dbesc(datetime_convert()),
			dbesc(($singleton) ? z_root() : '')
		);
	}

	if(! $r)
		logger('mod_follow: abook creation failed');

	$r = q("select abook.*, xchan.* from abook left join xchan on abook_xchan = xchan_hash 
		where abook_xchan = '%s' and abook_channel = %d limit 1",
		dbesc($xchan_hash),
		intval($uid)
	);

	if($r) {
		$result['abook'] = $r[0];
		proc_run('php', 'include/notifier.php', 'permission_create', $result['abook']['abook_id']);
	}

	$arr = array('channel_id' => $uid, 'abook' => $result['abook']);

	call_hooks('follow', $arr);

	/** If there is a default group for this channel, add this member to it */

	if($default_group) {
		require_once('include/group.php');
		$g = group_rec_byhash($uid,$default_group);
		if($g)
			group_add_member($uid,'',$xchan_hash,$g['id']);
	}

	$result['success'] = true;
	return $result;
}
