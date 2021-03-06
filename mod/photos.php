<?php
require_once('include/Photo.php');
require_once('include/items.php');
require_once('include/acl_selectors.php');
require_once('include/bbcode.php');
require_once('include/security.php');
require_once('include/redir.php');
require_once('include/tags.php');

function photos_init(&$a) {

	if($a->argc > 1)
		auto_redir($a, $a->argv[1]);

	if((get_config('system','block_public')) && (! local_user()) && (! remote_user())) {
		return;
	}

	$o = '';

	if($a->argc > 1) {
		$nick = $a->argv[1];
		$r = q("SELECT * FROM `user` WHERE `nickname` = '%s' AND `blocked` = 0 LIMIT 1",
			dbesc($nick)
		);

		if(! count($r))
			return;

		$a->data['user'] = $r[0];

		$o .= '<div class="vcard">';
		$o .= '<div class="fn">' . $a->data['user']['username'] . '</div>';
		$o .= '<div id="profile-photo-wrapper"><img class="photo" style="width: 175px; height: 175px;" src="' . $a->get_cached_avatar_image($a->get_baseurl() . '/photo/profile/' . $a->data['user']['uid'] . '.jpg') . '" alt="' . $a->data['user']['username'] . '" /></div>';
		$o .= '</div>';


		$sql_extra = permissions_sql($a->data['user']['uid']);

		$albums = q("SELECT distinct(`album`) AS `album` FROM `photo` WHERE `uid` = %d $sql_extra order by created desc",
			intval($a->data['user']['uid'])
		);

		if(count($albums)) {
			$a->data['albums'] = $albums;

			$albums_visible = ((intval($a->data['user']['hidewall']) && (! local_user()) && (! remote_user())) ? false : true);	

			if($albums_visible) {
				$o .= '<div id="side-bar-photos-albums" class="widget">';
				$o .= '<h3>' . '<a href="' . $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '">' . t('Photo Albums') . '</a></h3>';
					
				$o .= '<ul>';
				foreach($albums as $album) {

					// don't show contact photos. We once translated this name, but then you could still access it under
					// a different language setting. Now we store the name in English and check in English (and translated for legacy albums).

					if((! strlen($album['album'])) || ($album['album'] === 'Contact Photos') || ($album['album'] === t('Contact Photos')))
						continue;
					$o .= '<li>' . '<a href="photos/' . $a->argv[1] . '/album/' . bin2hex($album['album']) . '" >' . $album['album'] . '</a></li>'; 
				}
				$o .= '</ul>';
			}
			if(local_user() && $a->data['user']['uid'] == local_user()) {
				$o .= '<div id="photo-albums-upload-link"><a href="' . $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/upload" >' .t('Upload New Photos') . '</a></div>';
			}

			$o .= '</div>';
		}

		if(! x($a->page,'aside'))
			$a->page['aside'] = '';
		$a->page['aside'] .= $o;


		$tpl = get_markup_template("photos_head.tpl");
		$a->page['htmlhead'] .= replace_macros($tpl,array(
			'$ispublic' => t('everybody')
		));

	}

	return;
}



function photos_post(&$a) {

	logger('mod-photos: photos_post: begin' , LOGGER_DEBUG);


	logger('mod_photos: REQUEST ' . print_r($_REQUEST,true), LOGGER_DATA);
	logger('mod_photos: FILES '   . print_r($_FILES,true), LOGGER_DATA);

	$phototypes = Photo::supportedTypes();

	$can_post  = false;
	$visitor   = 0;

	$page_owner_uid = $a->data['user']['uid'];
	$community_page = (($a->data['user']['page-flags'] == PAGE_COMMUNITY) ? true : false);

	if((local_user()) && (local_user() == $page_owner_uid))
		$can_post = true;
	else {
		if($community_page && remote_user()) {
			$cid = 0;
			if(is_array($_SESSION['remote'])) {
				foreach($_SESSION['remote'] as $v) {
					if($v['uid'] == $page_owner_uid) {
						$cid = $v['cid'];
						break;
					}
				}
			}
			if($cid) {

				$r = q("SELECT `uid` FROM `contact` WHERE `blocked` = 0 AND `pending` = 0 AND `id` = %d AND `uid` = %d LIMIT 1",
					intval($cid),
					intval($page_owner_uid)
				);
				if(count($r)) {
					$can_post = true;
					$visitor = $cid;
				}
			}
		}
	}

	if(! $can_post) {
		notice( t('Permission denied.') . EOL );
		killme();
	}

	$r = q("SELECT `contact`.*, `user`.`nickname` FROM `contact` LEFT JOIN `user` ON `user`.`uid` = `contact`.`uid` 
		WHERE `user`.`uid` = %d AND `self` = 1 LIMIT 1",
		intval($page_owner_uid)
	);

	if(! count($r)) {
		notice( t('Contact information unavailable') . EOL);
		logger('photos_post: unable to locate contact record for page owner. uid=' . $page_owner_uid);
		killme();
	}

	$owner_record = $r[0];	


	if(($a->argc > 3) && ($a->argv[2] === 'album')) {
		$album = hex2bin($a->argv[3]);

		if($album === t('Profile Photos') || $album === 'Contact Photos' || $album === t('Contact Photos')) {
			goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
			return; // NOTREACHED
		}

		$r = q("SELECT count(*) FROM `photo` WHERE `album` = '%s' AND `uid` = %d",
			dbesc($album),
			intval($page_owner_uid)
		);
		if(! count($r)) {
			notice( t('Album not found.') . EOL);
			goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
			return; // NOTREACHED
		}

		// Check if the user has responded to a delete confirmation query
		if($_REQUEST['canceled']) {
			goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
		}

		$newalbum = notags(trim($_POST['albumname']));
		if($newalbum != $album) {
			q("UPDATE `photo` SET `album` = '%s' WHERE `album` = '%s' AND `uid` = %d",
				dbesc($newalbum),
				dbesc($album),
				intval($page_owner_uid)
			);
			$newurl = str_replace(bin2hex($album),bin2hex($newalbum),$_SESSION['photo_return']);
			goaway($a->get_baseurl() . '/' . $newurl);
			return; // NOTREACHED
		}


		if($_POST['dropalbum'] == t('Delete Album')) {

			// Check if we should do HTML-based delete confirmation
			if($_REQUEST['confirm']) {
				$drop_url = $a->query_string;
				$extra_inputs = array(
					array('name' => 'albumname', 'value' => $_POST['albumname']),
				);
				$a->page['content'] = replace_macros(get_markup_template('confirm.tpl'), array(
					'$method' => 'post',
					'$message' => t('Do you really want to delete this photo album and all its photos?'),
					'$extra_inputs' => $extra_inputs,
					'$confirm' => t('Delete Album'),
					'$confirm_url' => $drop_url,
					'$confirm_name' => 'dropalbum', // Needed so that confirmation will bring us back into this if statement
					'$cancel' => t('Cancel'),
				));
				$a->error = 1; // Set $a->error so the other module functions don't execute
				return;
			}

			$res = array();

			// get the list of photos we are about to delete

			if($visitor) {
				$r = q("SELECT distinct(`resource-id`) as `rid` FROM `photo` WHERE `contact-id` = %d AND `uid` = %d AND `album` = '%s'",
					intval($visitor),
					intval($page_owner_uid),
					dbesc($album)
				);
			}
			else {
				$r = q("SELECT distinct(`resource-id`) as `rid` FROM `photo` WHERE `uid` = %d AND `album` = '%s'",
					intval(local_user()),
					dbesc($album)
				);
			}
			if(count($r)) {
				foreach($r as $rr) {
					$res[] = "'" . dbesc($rr['rid']) . "'" ;
				}
			}
			else {
				goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
				return; // NOTREACHED
			}

			$str_res = implode(',', $res);

			// remove the associated photos

			q("DELETE FROM `photo` WHERE `resource-id` IN ( $str_res ) AND `uid` = %d",
				intval($page_owner_uid)
			);

			// find and delete the corresponding item with all the comments and likes/dislikes

			$r = q("SELECT `parent-uri` FROM `item` WHERE `resource-id` IN ( $str_res ) AND `uid` = %d",
				intval($page_owner_uid)
			);
			if(count($r)) {
				foreach($r as $rr) {
					q("UPDATE `item` SET `deleted` = 1, `changed` = '%s' WHERE `parent-uri` = '%s' AND `uid` = %d",
						dbesc(datetime_convert()),
						dbesc($rr['parent-uri']),
						intval($page_owner_uid)
					);
					create_tags_from_itemuri($rr['parent-uri'], $page_owner_uid);

					$drop_id = intval($rr['id']);

					// send the notification upstream/downstream as the case may be

					if($rr['visible'])
						proc_run('php',"include/notifier.php","drop","$drop_id");
				}
			}
		}
		goaway($a->get_baseurl() . '/photos/' . $a->data['user']['nickname']);
		return; // NOTREACHED
	}


	// Check if the user has responded to a delete confirmation query for a single photo
	if(($a->argc > 2) && $_REQUEST['canceled']) {
		goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
	}

	if(($a->argc > 2) && (x($_POST,'delete')) && ($_POST['delete'] == t('Delete Photo'))) {

		// same as above but remove single photo

		// Check if we should do HTML-based delete confirmation
		if($_REQUEST['confirm']) {
			$drop_url = $a->query_string;
			$a->page['content'] = replace_macros(get_markup_template('confirm.tpl'), array(
				'$method' => 'post',
				'$message' => t('Do you really want to delete this photo?'),
				'$extra_inputs' => array(),
				'$confirm' => t('Delete Photo'),
				'$confirm_url' => $drop_url,
				'$confirm_name' => 'delete', // Needed so that confirmation will bring us back into this if statement
				'$cancel' => t('Cancel'),
			));
			$a->error = 1; // Set $a->error so the other module functions don't execute
			return;
		}

		if($visitor) {
			$r = q("SELECT `id`, `resource-id` FROM `photo` WHERE `contact-id` = %d AND `uid` = %d AND `resource-id` = '%s' LIMIT 1",
				intval($visitor),
				intval($page_owner_uid),
				dbesc($a->argv[2])
			);
		}
		else {
			$r = q("SELECT `id`, `resource-id` FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s' LIMIT 1",
				intval(local_user()),
				dbesc($a->argv[2])
			);
		}
		if(count($r)) {
			q("DELETE FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s'",
				intval($page_owner_uid),
				dbesc($r[0]['resource-id'])
			);
			$i = q("SELECT * FROM `item` WHERE `resource-id` = '%s' AND `uid` = %d LIMIT 1",
				dbesc($r[0]['resource-id']),
				intval($page_owner_uid)
			);
			if(count($i)) {
				q("UPDATE `item` SET `deleted` = 1, `edited` = '%s', `changed` = '%s' WHERE `parent-uri` = '%s' AND `uid` = %d",
					dbesc(datetime_convert()),
					dbesc(datetime_convert()),
					dbesc($i[0]['uri']),
					intval($page_owner_uid)
				);
				create_tags_from_itemuri($i[0]['uri'], $page_owner_uid);

				$url = $a->get_baseurl();
				$drop_id = intval($i[0]['id']);

				if($i[0]['visible'])
					proc_run('php',"include/notifier.php","drop","$drop_id");
			}
		}

		goaway($a->get_baseurl() . '/photos/' . $a->data['user']['nickname']);
		return; // NOTREACHED
	}

	if(($a->argc > 2) && ((x($_POST,'desc') !== false) || (x($_POST,'newtag') !== false)) || (x($_POST,'albname') !== false)) {


		$desc        = ((x($_POST,'desc'))    ? notags(trim($_POST['desc']))    : '');
		$rawtags     = ((x($_POST,'newtag'))  ? notags(trim($_POST['newtag']))  : '');
		$item_id     = ((x($_POST,'item_id')) ? intval($_POST['item_id'])       : 0);
		$albname     = ((x($_POST,'albname')) ? notags(trim($_POST['albname'])) : '');
		$str_group_allow   = perms2str($_POST['group_allow']);
		$str_contact_allow = perms2str($_POST['contact_allow']);
		$str_group_deny    = perms2str($_POST['group_deny']);
		$str_contact_deny  = perms2str($_POST['contact_deny']);

		$resource_id = $a->argv[2];

		if(! strlen($albname))
			$albname = datetime_convert('UTC',date_default_timezone_get(),'now', 'Y');


		if((x($_POST,'rotate') !== false) && 
		   ( (intval($_POST['rotate']) == 1) || (intval($_POST['rotate']) == 2) )) {
			logger('rotate');

			$r = q("select * from photo where `resource-id` = '%s' and uid = %d and scale = 0 limit 1",
				dbesc($resource_id),
				intval($page_owner_uid)
			);
			if(count($r)) {
				$ph = new Photo($r[0]['data'], $r[0]['type']);
				if($ph->is_valid()) {
					$rotate_deg = ( (intval($_POST['rotate']) == 1) ? 270 : 90 );
					$ph->rotate($rotate_deg);

					$width  = $ph->getWidth();
					$height = $ph->getHeight();

					$x = q("update photo set data = '%s', height = %d, width = %d where `resource-id` = '%s' and uid = %d and scale = 0 limit 1",
						dbesc($ph->imageString()),
						intval($height),
						intval($width),
						dbesc($resource_id),
						intval($page_owner_uid)
					);

					if($width > 640 || $height > 640) {
						$ph->scaleImage(640);
						$width  = $ph->getWidth();
						$height = $ph->getHeight();

						$x = q("update photo set data = '%s', height = %d, width = %d where `resource-id` = '%s' and uid = %d and scale = 1 limit 1",
							dbesc($ph->imageString()),
							intval($height),
							intval($width),
							dbesc($resource_id),
							intval($page_owner_uid)
						);
					}

					if($width > 320 || $height > 320) {
						$ph->scaleImage(320);
						$width  = $ph->getWidth();
						$height = $ph->getHeight();

						$x = q("update photo set data = '%s', height = %d, width = %d where `resource-id` = '%s' and uid = %d and scale = 2 limit 1",
							dbesc($ph->imageString()),
							intval($height),
							intval($width),
							dbesc($resource_id),
							intval($page_owner_uid)
						);
					}
				}
			}
		}

		$p = q("SELECT * FROM `photo` WHERE `resource-id` = '%s' AND `uid` = %d ORDER BY `scale` DESC",
			dbesc($resource_id),
			intval($page_owner_uid)
		);
		if(count($p)) {
			$ext = $phototypes[$p[0]['type']];
			$r = q("UPDATE `photo` SET `desc` = '%s', `album` = '%s', `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s' WHERE `resource-id` = '%s' AND `uid` = %d",
				dbesc($desc),
				dbesc($albname),
				dbesc($str_contact_allow),
				dbesc($str_group_allow),
				dbesc($str_contact_deny),
				dbesc($str_group_deny),
				dbesc($resource_id),
				intval($page_owner_uid)
			);
		}

		/* Don't make the item visible if the only change was the album name */

		$visibility = 0;
		if($p[0]['desc'] !== $desc || strlen($rawtags))
			$visibility = 1;
		
		if(! $item_id) {

			// Create item container

			$title = '';
			$uri = item_new_uri($a->get_hostname(),$page_owner_uid);
			
			$arr = array();

			$arr['uid']           = $page_owner_uid;
			$arr['uri']           = $uri;
			$arr['parent-uri']    = $uri; 
			$arr['type']          = 'photo';
			$arr['wall']          = 1;
			$arr['resource-id']   = $p[0]['resource-id'];
			$arr['contact-id']    = $owner_record['id'];
			$arr['owner-name']    = $owner_record['name'];
			$arr['owner-link']    = $owner_record['url'];
			$arr['owner-avatar']  = $owner_record['thumb'];
			$arr['author-name']   = $owner_record['name'];
			$arr['author-link']   = $owner_record['url'];
			$arr['author-avatar'] = $owner_record['thumb'];
			$arr['title']         = $title;
			$arr['allow_cid']     = $p[0]['allow_cid'];
			$arr['allow_gid']     = $p[0]['allow_gid'];
			$arr['deny_cid']      = $p[0]['deny_cid'];
			$arr['deny_gid']      = $p[0]['deny_gid'];
			$arr['last-child']    = 1;
			$arr['visible']       = $visibility;
			$arr['origin']        = 1;
			
			$arr['body']          = '[url=' . $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/image/' . $p[0]['resource-id'] . ']' 
						. '[img]' . $a->get_baseurl() . '/photo/' . $p[0]['resource-id'] . '-' . $p[0]['scale'] . '.'. $ext . '[/img]' 
						. '[/url]';
		
			$item_id = item_store($arr);

		}

		if($item_id) {
			$r = q("SELECT * FROM `item` WHERE `id` = %d AND `uid` = %d LIMIT 1",
				intval($item_id),
				intval($page_owner_uid)
			);
		}
		if(count($r)) {
			$old_tag    = $r[0]['tag'];
			$old_inform = $r[0]['inform'];
		}

		if(strlen($rawtags)) {

			$str_tags = '';
			$inform   = '';

			// if the new tag doesn't have a namespace specifier (@foo or #foo) give it a hashtag

			$x = substr($rawtags,0,1);
			if($x !== '@' && $x !== '#')
				$rawtags = '#' . $rawtags;

			$taginfo = array();
			$tags = get_tags($rawtags);

			if(count($tags)) {
				foreach($tags as $tag) {
					if(isset($profile))
						unset($profile);
					if(strpos($tag,'@') === 0) {
						$name = substr($tag,1);
						if((strpos($name,'@')) || (strpos($name,'http://'))) {
							$newname = $name;
							$links = @lrdd($name);
							if(count($links)) {
								foreach($links as $link) {
									if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page')
        		            			$profile = $link['@attributes']['href'];
									if($link['@attributes']['rel'] === 'salmon') {
										$salmon = '$url:' . str_replace(',','%sc',$link['@attributes']['href']);
										if(strlen($inform))
											$inform .= ',';
                    					$inform .= $salmon;
									}
								}
							}
							$taginfo[] = array($newname,$profile,$salmon);
						}
						else {
							$newname = $name;
							$alias = '';
							$tagcid = 0;
							if(strrpos($newname,'+'))
								$tagcid = intval(substr($newname,strrpos($newname,'+') + 1));

							if($tagcid) {
								$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
									intval($tagcid),
									intval($profile_uid)
								);
							}
							else {
								$newname = str_replace('_',' ',$name);

								//select someone from this user's contacts by name
								$r = q("SELECT * FROM `contact` WHERE `name` = '%s' AND `uid` = %d LIMIT 1",
										dbesc($newname),
										intval($page_owner_uid)
								);

								if(! $r) {
									//select someone by attag or nick and the name passed in
									$r = q("SELECT * FROM `contact` WHERE `attag` = '%s' OR `nick` = '%s' AND `uid` = %d ORDER BY `attag` DESC LIMIT 1",
											dbesc($name),
											dbesc($name),
											intval($page_owner_uid)
									);
								}
							}
/*							elseif(strstr($name,'_') || strstr($name,' ')) {
								$newname = str_replace('_',' ',$name);
								$r = q("SELECT * FROM `contact` WHERE `name` = '%s' AND `uid` = %d LIMIT 1",
									dbesc($newname),
									intval($page_owner_uid)
								);
							}
							else {
								$r = q("SELECT * FROM `contact` WHERE `attag` = '%s' OR `nick` = '%s' AND `uid` = %d ORDER BY `attag` DESC LIMIT 1",
									dbesc($name),
									dbesc($name),
									intval($page_owner_uid)
								);
							}*/
							if(count($r)) {
								$newname = $r[0]['name'];
								$profile = $r[0]['url'];
								$notify = 'cid:' . $r[0]['id'];
								if(strlen($inform))
									$inform .= ',';
								$inform .= $notify;
							}
						}
						if($profile) {
							if(substr($notify,0,4) === 'cid:')
								$taginfo[] = array($newname,$profile,$notify,$r[0],'@[url=' . str_replace(',','%2c',$profile) . ']' . $newname	. '[/url]');
							else
								$taginfo[] = array($newname,$profile,$notify,null,$str_tags .= '@[url=' . $profile . ']' . $newname	. '[/url]');
							if(strlen($str_tags))
								$str_tags .= ',';
							$profile = str_replace(',','%2c',$profile);
							$str_tags .= '@[url=' . $profile . ']' . $newname	. '[/url]';
						}
					}
				}
			}

			$newtag = $old_tag;
			if(strlen($newtag) && strlen($str_tags)) 
				$newtag .= ',';
			$newtag .= $str_tags;

			$newinform = $old_inform;
			if(strlen($newinform) && strlen($inform))
				$newinform .= ',';
			$newinform .= $inform;

			$r = q("UPDATE `item` SET `tag` = '%s', `inform` = '%s', `edited` = '%s', `changed` = '%s' WHERE `id` = %d AND `uid` = %d LIMIT 1",
				dbesc($newtag),
				dbesc($newinform),
				dbesc(datetime_convert()),
				dbesc(datetime_convert()),
				intval($item_id),
				intval($page_owner_uid)
			);
			create_tags_from_item($item_id);

			$best = 0;
			foreach($p as $scales) {
				if(intval($scales['scale']) == 2) {
					$best = 2;
					break;
				}
				if(intval($scales['scale']) == 4) {
					$best = 4;
					break;
				}
			}

			if(count($taginfo)) {
				foreach($taginfo as $tagged) {
		
					$uri = item_new_uri($a->get_hostname(),$page_owner_uid);

					$arr = array();

					$arr['uid']           = $page_owner_uid;
					$arr['uri']           = $uri;
					$arr['parent-uri']    = $uri;
					$arr['type']          = 'activity';
					$arr['wall']          = 1;
					$arr['contact-id']    = $owner_record['id'];
					$arr['owner-name']    = $owner_record['name'];
					$arr['owner-link']    = $owner_record['url'];
					$arr['owner-avatar']  = $owner_record['thumb'];
					$arr['author-name']   = $owner_record['name'];
					$arr['author-link']   = $owner_record['url'];
					$arr['author-avatar'] = $owner_record['thumb'];
					$arr['title']         = '';
					$arr['allow_cid']     = $p[0]['allow_cid'];
					$arr['allow_gid']     = $p[0]['allow_gid'];
					$arr['deny_cid']      = $p[0]['deny_cid'];
					$arr['deny_gid']      = $p[0]['deny_gid'];
					$arr['last-child']    = 1;
					$arr['visible']       = 1;
					$arr['verb']          = ACTIVITY_TAG;
					$arr['object-type']   = ACTIVITY_OBJ_PERSON;
					$arr['target-type']   = ACTIVITY_OBJ_PHOTO;
					$arr['tag']           = $tagged[4];
					$arr['inform']        = $tagged[2];
					$arr['origin']        = 1;
					$arr['body']          = sprintf( t('%1$s was tagged in %2$s by %3$s'), '[url=' . $tagged[1] . ']' . $tagged[0] . '[/url]', '[url=' . $a->get_baseurl() . '/photos/' . $owner_record['nickname'] . '/image/' . $p[0]['resource-id'] . ']' . t('a photo') . '[/url]', '[url=' . $owner_record['url'] . ']' . $owner_record['name'] . '[/url]') ;
					$arr['body'] .= "\n\n" . '[url=' . $a->get_baseurl() . '/photos/' . $owner_record['nickname'] . '/image/' . $p[0]['resource-id'] . ']' . '[img]' . $a->get_baseurl() . "/photo/" . $p[0]['resource-id'] . '-' . $best . '.' . $ext . '[/img][/url]' . "\n" ;

					$arr['object'] = '<object><type>' . ACTIVITY_OBJ_PERSON . '</type><title>' . $tagged[0] . '</title><id>' . $tagged[1] . '/' . $tagged[0] . '</id>';
					$arr['object'] .= '<link>' . xmlify('<link rel="alternate" type="text/html" href="' . $tagged[1] . '" />' . "\n");
					if($tagged[3])
						$arr['object'] .= xmlify('<link rel="photo" type="'.$p[0]['type'].'" href="' . $tagged[3]['photo'] . '" />' . "\n");
					$arr['object'] .= '</link></object>' . "\n";

					$arr['target'] = '<target><type>' . ACTIVITY_OBJ_PHOTO . '</type><title>' . $p[0]['desc'] . '</title><id>'
						. $a->get_baseurl() . '/photos/' . $owner_record['nickname'] . '/image/' . $p[0]['resource-id'] . '</id>';
					$arr['target'] .= '<link>' . xmlify('<link rel="alternate" type="text/html" href="' . $a->get_baseurl() . '/photos/' . $owner_record['nickname'] . '/image/' . $p[0]['resource-id'] . '" />' . "\n" . '<link rel="preview" type="'.$p[0]['type'].'" href="' . $a->get_baseurl() . "/photo/" . $p[0]['resource-id'] . '-' . $best . '.' . $ext . '" />') . '</link></target>';

					$item_id = item_store($arr);
					if($item_id) {
						q("UPDATE `item` SET `plink` = '%s' WHERE `uid` = %d AND `id` = %d LIMIT 1",
							dbesc($a->get_baseurl() . '/display/' . $owner_record['nickname'] . '/' . $item_id),
							intval($page_owner_uid),
							intval($item_id)
						);

						proc_run('php',"include/notifier.php","tag","$item_id");
					}
				}

			}

		}
		goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
		return; // NOTREACHED
	}


	/**
	 * default post action - upload a photo
	 */

	call_hooks('photo_post_init', $_POST);

	/**
	 * Determine the album to use
	 */

	$album    = notags(trim($_REQUEST['album']));
	$newalbum = notags(trim($_REQUEST['newalbum']));

	logger('mod/photos.php: photos_post(): album= ' . $album . ' newalbum= ' . $newalbum , LOGGER_DEBUG);

	if(! strlen($album)) {
		if(strlen($newalbum))
			$album = $newalbum;
		else
			$album = datetime_convert('UTC',date_default_timezone_get(),'now', 'Y');
	}

	/**
	 *
	 * We create a wall item for every photo, but we don't want to
	 * overwhelm the data stream with a hundred newly uploaded photos.
	 * So we will make the first photo uploaded to this album in the last several hours
	 * visible by default, the rest will become visible over time when and if
	 * they acquire comments, likes, dislikes, and/or tags 
	 *
	 */

	$r = q("SELECT * FROM `photo` WHERE `album` = '%s' AND `uid` = %d AND `created` > UTC_TIMESTAMP() - INTERVAL 3 HOUR ",
		dbesc($album),
		intval($page_owner_uid)
	);
	if((! count($r)) || ($album == t('Profile Photos')))
		$visible = 1;
	else
		$visible = 0;
	
	if(intval($_REQUEST['not_visible']) || $_REQUEST['not_visible'] === 'true')
		$visible = 0;

	$str_group_allow   = perms2str(((is_array($_REQUEST['group_allow']))   ? $_REQUEST['group_allow']   : explode(',',$_REQUEST['group_allow'])));
	$str_contact_allow = perms2str(((is_array($_REQUEST['contact_allow'])) ? $_REQUEST['contact_allow'] : explode(',',$_REQUEST['contact_allow'])));
	$str_group_deny    = perms2str(((is_array($_REQUEST['group_deny']))    ? $_REQUEST['group_deny']    : explode(',',$_REQUEST['group_deny'])));
	$str_contact_deny  = perms2str(((is_array($_REQUEST['contact_deny']))  ? $_REQUEST['contact_deny']  : explode(',',$_REQUEST['contact_deny'])));

	$ret = array('src' => '', 'filename' => '', 'filesize' => 0, 'type' => '');

	call_hooks('photo_post_file',$ret);

	if(x($ret,'src') && x($ret,'filesize')) {
		$src      = $ret['src'];
		$filename = $ret['filename'];
		$filesize = $ret['filesize'];
		$type     = $ret['type'];
	}
	else {
		$src        = $_FILES['userfile']['tmp_name'];
		$filename   = basename($_FILES['userfile']['name']);
		$filesize   = intval($_FILES['userfile']['size']);
		$type       = $_FILES['userfile']['type'];
	}
	if ($type=="") $type=guess_image_type($filename);

	logger('photos: upload: received file: ' . $filename . ' as ' . $src . ' ('. $type . ') ' . $filesize . ' bytes', LOGGER_DEBUG);

	$maximagesize = get_config('system','maximagesize');

	if(($maximagesize) && ($filesize > $maximagesize)) {
		notice( t('Image exceeds size limit of ') . $maximagesize . EOL);
		@unlink($src);
		$foo = 0;
		call_hooks('photo_post_end',$foo);
		return;
	}

	if(! $filesize) {
		notice( t('Image file is empty.') . EOL);
		@unlink($src);
		$foo = 0;
		call_hooks('photo_post_end',$foo);
		return;
	}

	logger('mod/photos.php: photos_post(): loading the contents of ' . $src , LOGGER_DEBUG);

	$imagedata = @file_get_contents($src);



	$r = q("select sum(octet_length(data)) as total from photo where uid = %d and scale = 0 and album != 'Contact Photos' ",
		intval($a->data['user']['uid'])
	);

	$limit = service_class_fetch($a->data['user']['uid'],'photo_upload_limit');

	if(($limit !== false) && (($r[0]['total'] + strlen($imagedata)) > $limit)) {
		notice( upgrade_message() . EOL );
		@unlink($src);
		$foo = 0;
		call_hooks('photo_post_end',$foo);
		killme();
	}
		

	$ph = new Photo($imagedata, $type);

	if(! $ph->is_valid()) {
		logger('mod/photos.php: photos_post(): unable to process image' , LOGGER_DEBUG);
		notice( t('Unable to process image.') . EOL );
		@unlink($src);
		$foo = 0;
		call_hooks('photo_post_end',$foo);
		killme();
	}

	$ph->orient($src);
	@unlink($src);

	$max_length = get_config('system','max_image_length');
	if(! $max_length)
		$max_length = MAX_IMAGE_LENGTH;
	if($max_length > 0)
		$ph->scaleImage($max_length);

	$width  = $ph->getWidth();
	$height = $ph->getHeight();

	$smallest = 0;

	$photo_hash = photo_new_resource();

	$r = $ph->store($page_owner_uid, $visitor, $photo_hash, $filename, $album, 0 , 0, $str_contact_allow, $str_group_allow, $str_contact_deny, $str_group_deny);

	if(! $r) {
		logger('mod/photos.php: photos_post(): image store failed' , LOGGER_DEBUG);
		notice( t('Image upload failed.') . EOL );
		killme();
	}

	if($width > 640 || $height > 640) {
		$ph->scaleImage(640);
		$ph->store($page_owner_uid, $visitor, $photo_hash, $filename, $album, 1, 0, $str_contact_allow, $str_group_allow, $str_contact_deny, $str_group_deny);
		$smallest = 1;
	}

	if($width > 320 || $height > 320) {
		$ph->scaleImage(320);
		$ph->store($page_owner_uid, $visitor, $photo_hash, $filename, $album, 2, 0, $str_contact_allow, $str_group_allow, $str_contact_deny, $str_group_deny);
		$smallest = 2;
	}
	
	$basename = basename($filename);
	$uri = item_new_uri($a->get_hostname(), $page_owner_uid);

	// Create item container

	$arr = array();

	$arr['uid']           = $page_owner_uid;
	$arr['uri']           = $uri;
	$arr['parent-uri']    = $uri;
	$arr['type']          = 'photo';
	$arr['wall']          = 1;
	$arr['resource-id']   = $photo_hash;
	$arr['contact-id']    = $owner_record['id'];
	$arr['owner-name']    = $owner_record['name'];
	$arr['owner-link']    = $owner_record['url'];
	$arr['owner-avatar']  = $owner_record['thumb'];
	$arr['author-name']   = $owner_record['name'];
	$arr['author-link']   = $owner_record['url'];
	$arr['author-avatar'] = $owner_record['thumb'];
	$arr['title']         = '';
	$arr['allow_cid']     = $str_contact_allow;
	$arr['allow_gid']     = $str_group_allow;
	$arr['deny_cid']      = $str_contact_deny;
	$arr['deny_gid']      = $str_group_deny;
	$arr['last-child']    = 1;
	$arr['visible']       = $visible;
	$arr['origin']        = 1;

	$arr['body']          = '[url=' . $a->get_baseurl() . '/photos/' . $owner_record['nickname'] . '/image/' . $photo_hash . ']' 
				. '[img]' . $a->get_baseurl() . "/photo/{$photo_hash}-{$smallest}.".$ph->getExt() . '[/img]' 
				. '[/url]';

	$item_id = item_store($arr);

	if($item_id) {
		q("UPDATE `item` SET `plink` = '%s' WHERE `uid` = %d AND `id` = %d LIMIT 1",
			dbesc($a->get_baseurl() . '/display/' . $owner_record['nickname'] . '/' . $item_id),
			intval($page_owner_uid),
			intval($item_id)
		);
	}

	if($visible)
		proc_run('php', "include/notifier.php", 'wall-new', $item_id);

	call_hooks('photo_post_end',intval($item_id));

	// addon uploaders should call "killme()" [e.g. exit] within the photo_post_end hook
	// if they do not wish to be redirected

	goaway($a->get_baseurl() . '/' . $_SESSION['photo_return']);
	// NOTREACHED
}



function photos_content(&$a) {

	// URLs:
	// photos/name
	// photos/name/upload
	// photos/name/upload/xxxxx (xxxxx is album name)
	// photos/name/album/xxxxx
	// photos/name/album/xxxxx/edit
	// photos/name/image/xxxxx
	// photos/name/image/xxxxx/edit


	if((get_config('system','block_public')) && (! local_user()) && (! remote_user())) {
		notice( t('Public access denied.') . EOL);
		return;
	}
	
	
	require_once('include/bbcode.php');
	require_once('include/security.php');
	require_once('include/conversation.php');

	if(! x($a->data,'user')) {
		notice( t('No photos selected') . EOL );
		return;
	}

	$phototypes = Photo::supportedTypes();

	$_SESSION['photo_return'] = $a->cmd;

	//
	// Parse arguments 
	//

	if($a->argc > 3) {
		$datatype = $a->argv[2];
		$datum = $a->argv[3];
	}
	elseif(($a->argc > 2) && ($a->argv[2] === 'upload'))
		$datatype = 'upload';
	else
		$datatype = 'summary';

	if($a->argc > 4)
		$cmd = $a->argv[4];
	else
		$cmd = 'view';

	//
	// Setup permissions structures
	//

	$can_post       = false;
	$visitor        = 0;
	$contact        = null;
	$remote_contact = false;
	$contact_id     = 0;

	$owner_uid = $a->data['user']['uid'];

	$community_page = (($a->data['user']['page-flags'] == PAGE_COMMUNITY) ? true : false);

	if((local_user()) && (local_user() == $owner_uid))
		$can_post = true;
	else {
		if($community_page && remote_user()) {
			if(is_array($_SESSION['remote'])) {
				foreach($_SESSION['remote'] as $v) {
					if($v['uid'] == $owner_uid) {
						$contact_id = $v['cid'];
						break;
					}
				}
			}
			if($contact_id) {

				$r = q("SELECT `uid` FROM `contact` WHERE `blocked` = 0 AND `pending` = 0 AND `id` = %d AND `uid` = %d LIMIT 1",
					intval($contact_id),
					intval($owner_uid)
				);
				if(count($r)) {
					$can_post = true;
					$contact = $r[0];
					$remote_contact = true;
					$visitor = $cid;
				}
			}
		}
	}

	// perhaps they're visiting - but not a community page, so they wouldn't have write access

	if(remote_user() && (! $visitor)) {
		$contact_id = 0;
		if(is_array($_SESSION['remote'])) {
			foreach($_SESSION['remote'] as $v) {
				if($v['uid'] == $owner_uid) {
					$contact_id = $v['cid'];
					break;
				}
			}
		}
		if($contact_id) {
			$groups = init_groups_visitor($contact_id);
			$r = q("SELECT * FROM `contact` WHERE `blocked` = 0 AND `pending` = 0 AND `id` = %d AND `uid` = %d LIMIT 1",
				intval($contact_id),
				intval($owner_uid)
			);
			if(count($r)) {
				$contact = $r[0];
				$remote_contact = true;
			}
		}
	}

	if(! $remote_contact) {
		if(local_user()) {
			$contact_id = $_SESSION['cid'];
			$contact = $a->contact;
		}
	}

	if($a->data['user']['hidewall'] && (local_user() != $owner_uid) && (! $remote_contact)) {
		notice( t('Access to this item is restricted.') . EOL);
		return;
	}

	$sql_extra = permissions_sql($owner_uid,$remote_contact,$groups);

	$o = "";

	// tabs
	$_is_owner = (local_user() && (local_user() == $owner_uid));
	$o .= profile_tabs($a,$_is_owner, $a->data['user']['nickname']);	

	//
	// dispatch request
	//


	if($datatype === 'upload') {
		if(! ($can_post)) {
			notice( t('Permission denied.'));
			return;
		}


		$selname = (($datum) ? hex2bin($datum) : '');


		$albumselect = '';

		
		$albumselect .= '<option value="" ' . ((! $selname) ? ' selected="selected" ' : '') . '>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</option>';
		if(count($a->data['albums'])) {
			foreach($a->data['albums'] as $album) {
				if(($album['album'] === '') || ($album['album'] === 'Contact Photos') || ($album['album'] === t('Contact Photos')))
					continue;
				$selected = (($selname === $album['album']) ? ' selected="selected" ' : '');
				$albumselect .= '<option value="' . $album['album'] . '"' . $selected . '>' . $album['album'] . '</option>';
			}
		}

		$celeb = ((($a->user['page-flags'] == PAGE_SOAPBOX) || ($a->user['page-flags'] == PAGE_COMMUNITY)) ? true : false);

		$uploader = '';

		$ret = array('post_url' => $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'],
				'addon_text' => $uploader,
				'default_upload' => true);


		call_hooks('photo_upload_form',$ret);

		$default_upload_box = replace_macros(get_markup_template('photos_default_uploader_box.tpl'), array());
		$default_upload_submit = replace_macros(get_markup_template('photos_default_uploader_submit.tpl'), array(
			'$submit' => t('Submit'),
		));

		$usage_message = '';
		$limit = service_class_fetch($a->data['user']['uid'],'photo_upload_limit');
		if($limit !== false) {

			$r = q("select sum(datasize) as total from photo where uid = %d and scale = 0 and album != 'Contact Photos' ",
				intval($a->data['user']['uid'])
			);
			$usage_message = sprintf( t("You have used %1$.2f Mbytes of %2$.2f Mbytes photo storage."), $r[0]['total'] / 1024000, $limit / 1024000 );
		}


		// Private/public post links for the non-JS ACL form
		$private_post = 1;
		if($_REQUEST['public'])
			$private_post = 0;

		$query_str = $a->query_string;
		if(strpos($query_str, 'public=1') !== false)
			$query_str = str_replace(array('?public=1', '&public=1'), array('', ''), $query_str);

		// I think $a->query_string may never have ? in it, but I could be wrong
		// It looks like it's from the index.php?q=[etc] rewrite that the web
		// server does, which converts any ? to &, e.g. suggest&ignore=61 for suggest?ignore=61
		if(strpos($query_str, '?') === false)
			$public_post_link = '?public=1';
		else
			$public_post_link = '&public=1';



		$tpl = get_markup_template('photos_upload.tpl');

		if($a->theme['template_engine'] === 'internal') {
			$albumselect_e = template_escape($albumselect);
			$aclselect_e = (($visitor) ? '' : template_escape(populate_acl($a->user, $celeb)));
		}
		else {
			$albumselect_e = $albumselect;
			$aclselect_e = (($visitor) ? '' : populate_acl($a->user, $celeb));
		}

		$o .= replace_macros($tpl,array(
			'$pagename' => t('Upload Photos'),
			'$sessid' => session_id(),
			'$usage' => $usage_message,
			'$nickname' => $a->data['user']['nickname'],
			'$newalbum' => t('New album name: '),
			'$existalbumtext' => t('or existing album name: '),
			'$nosharetext' => t('Do not show a status post for this upload'),
			'$albumselect' => $albumselect_e,
			'$permissions' => t('Permissions'),
			'$aclselect' => $aclselect_e,
			'$alt_uploader' => $ret['addon_text'],
			'$default_upload_box' => (($ret['default_upload']) ? $default_upload_box : ''),
			'$default_upload_submit' => (($ret['default_upload']) ? $default_upload_submit : ''),
			'$uploadurl' => $ret['post_url'],

			// ACL permissions box
			'$acl_data' => construct_acl_data($a, $a->user), // For non-Javascript ACL selector
			'$group_perms' => t('Show to Groups'),
			'$contact_perms' => t('Show to Contacts'),
			'$private' => t('Private Photo'),
			'$public' => t('Public Photo'),
			'$is_private' => $private_post,
			'$return_path' => $query_str,
			'$public_link' => $public_post_link,

		));

		return $o; 
	}

	if($datatype === 'album') {

		$album = hex2bin($datum);

		$r = q("SELECT `resource-id`, max(`scale`) AS `scale` FROM `photo` WHERE `uid` = %d AND `album` = '%s' 
			AND `scale` <= 4 $sql_extra GROUP BY `resource-id`",
			intval($owner_uid),
			dbesc($album)
		);
		if(count($r)) {
			$a->set_pager_total(count($r));
			$a->set_pager_itemspage(20);
		}

		if($_GET['order'] === 'posted')
			$order = 'ASC';
		else
			$order = 'DESC';

		$r = q("SELECT `resource-id`, `id`, `filename`, type, max(`scale`) AS `scale`, `desc` FROM `photo` WHERE `uid` = %d AND `album` = '%s' 
			AND `scale` <= 4 $sql_extra GROUP BY `resource-id` ORDER BY `created` $order LIMIT %d , %d",
			intval($owner_uid),
			dbesc($album),
			intval($a->pager['start']),
			intval($a->pager['itemspage'])
		);

		$o .= '<h3>' . $album . '</h3>';
		
		if($cmd === 'edit') {		
			if(($album !== t('Profile Photos')) && ($album !== 'Contact Photos') && ($album !== t('Contact Photos'))) {
				if($can_post) {
					$edit_tpl = get_markup_template('album_edit.tpl');

					if($a->theme['template_engine'] === 'internal') {
						$album_e = template_escape($album);
					}
					else {
						$album_e = $album;
					}

					$o .= replace_macros($edit_tpl,array(
						'$nametext' => t('New album name: '),
						'$nickname' => $a->data['user']['nickname'],
						'$album' => $album_e,
						'$hexalbum' => bin2hex($album),
						'$submit' => t('Submit'),
						'$dropsubmit' => t('Delete Album')
					));
				}
			}
		}
		else {
			if(($album !== t('Profile Photos')) && ($album !== 'Contact Photos') && ($album !== t('Contact Photos'))) {
				if($can_post) {
					$o .= '<div id="album-edit-link"><a href="'. $a->get_baseurl() . '/photos/' 
						. $a->data['user']['nickname'] . '/album/' . bin2hex($album) . '/edit' . '">' 
						. t('Edit Album') . '</a></div>';
 				}
			}
		}

		if($_GET['order'] === 'posted')
			$o .=  '<div class="photos-upload-link" ><a href="' . $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/album/' . bin2hex($album) . '" >' . t('Show Newest First') . '</a></div>';
		else
			$o .= '<div class="photos-upload-link" ><a href="' . $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/album/' . bin2hex($album) . '?f=&order=posted" >' . t('Show Oldest First') . '</a></div>';


		if($can_post) {
			$o .= '<div class="photos-upload-link" ><a href="' . $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/upload/' . bin2hex($album) . '" >' . t('Upload New Photos') . '</a></div>';
		}


		$tpl = get_markup_template('photo_album.tpl');
		if(count($r))
			$twist = 'rotright';
			foreach($r as $rr) {
				if($twist == 'rotright')
					$twist = 'rotleft';
				else
					$twist = 'rotright';
				
				$ext = $phototypes[$rr['type']];

				if($a->theme['template_engine'] === 'internal') {
					$imgalt_e = template_escape($rr['filename']);
					$desc_e = template_escape($rr['desc']);
				}
				else {
					$imgalt_e = $rr['filename'];
					$desc_e = $rr['desc'];
				}

				$o .= replace_macros($tpl,array(
					'$id' => $rr['id'],
					'$twist' => ' ' . $twist . rand(2,4),
					'$photolink' => $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/image/' . $rr['resource-id']
						. (($_GET['order'] === 'posted') ? '?f=&order=posted' : ''),
					'$phototitle' => t('View Photo'),
					'$imgsrc' => $a->get_baseurl() . '/photo/' . $rr['resource-id'] . '-' . $rr['scale'] . '.' .$ext,
					'$imgalt' => $imgalt_e,
					'$desc'=> $desc_e
				));

		}
		$o .= '<div id="photo-album-end"></div>';
		$o .= paginate($a);

		return $o;

	}	


	if($datatype === 'image') {



		//$o = '';
		// fetch image, item containing image, then comments

		$ph = q("SELECT * FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s' 
			$sql_extra ORDER BY `scale` ASC ",
			intval($owner_uid),
			dbesc($datum)
		);

		if(! count($ph)) {
			$ph = q("SELECT `id` FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s' 
				LIMIT 1",
				intval($owner_uid),
				dbesc($datum)
			);
			if(count($ph)) 
				notice( t('Permission denied. Access to this item may be restricted.'));
			else
				notice( t('Photo not available') . EOL );
			return;
		}

		$prevlink = '';
		$nextlink = '';

		if($_GET['order'] === 'posted')
			$order = 'ASC';
		else
			$order = 'DESC';


		$prvnxt = q("SELECT `resource-id` FROM `photo` WHERE `album` = '%s' AND `uid` = %d AND `scale` = 0 
			$sql_extra ORDER BY `created` $order ",
			dbesc($ph[0]['album']),
			intval($owner_uid)
		); 

		if(count($prvnxt)) {
			for($z = 0; $z < count($prvnxt); $z++) {
				if($prvnxt[$z]['resource-id'] == $ph[0]['resource-id']) {
					$prv = $z - 1;
					$nxt = $z + 1;
					if($prv < 0)
						$prv = count($prvnxt) - 1;
					if($nxt >= count($prvnxt))
						$nxt = 0;
					break;
				}
			}
			$edit_suffix = ((($cmd === 'edit') && ($can_post)) ? '/edit' : '');
			$prevlink = $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/image/' . $prvnxt[$prv]['resource-id'] . $edit_suffix . (($_GET['order'] === 'posted') ? '?f=&order=posted' : '');
			$nextlink = $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/image/' . $prvnxt[$nxt]['resource-id'] . $edit_suffix . (($_GET['order'] === 'posted') ? '?f=&order=posted' : '');
 		}


		if(count($ph) == 1)
			$hires = $lores = $ph[0];
		if(count($ph) > 1) {
			if($ph[1]['scale'] == 2) {
				// original is 640 or less, we can display it directly
				$hires = $lores = $ph[0];
			}
			else {
			$hires = $ph[0];
			$lores = $ph[1];
			}
		}

		$album_link = $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/album/' . bin2hex($ph[0]['album']);
 		$tools = Null;
 		$lock = Null;
 
		if($can_post && ($ph[0]['uid'] == $owner_uid)) {
			$tools = array(
				'edit'	=> array($a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/image/' . $datum . (($cmd === 'edit') ? '' : '/edit'), (($cmd === 'edit') ? t('View photo') : t('Edit photo'))),
				'profile'=>array($a->get_baseurl() . '/profile_photo/use/'.$ph[0]['resource-id'], t('Use as profile photo')),
			);

			// lock
			$lock = ( ( ($ph[0]['uid'] == local_user()) && (strlen($ph[0]['allow_cid']) || strlen($ph[0]['allow_gid']) 
					|| strlen($ph[0]['deny_cid']) || strlen($ph[0]['deny_gid'])) ) 
					? t('Private Message')
					: Null);
	  		
			
		}

		if( $cmd === 'edit') {
			$tpl = get_markup_template('photo_edit_head.tpl');
			$a->page['htmlhead'] .= replace_macros($tpl,array(
				'$prevlink' => $prevlink,
				'$nextlink' => $nextlink
			));
		}

		if($prevlink)
			$prevlink = array($prevlink, '<div class="icon prev"></div>') ;

		$photo = array(
			'href' => $a->get_baseurl() . '/photo/' . $hires['resource-id'] . '-' . $hires['scale'] . '.' . $phototypes[$hires['type']],
			'title'=> t('View Full Size'),
			'src'  => $a->get_baseurl() . '/photo/' . $lores['resource-id'] . '-' . $lores['scale'] . '.' . $phototypes[$lores['type']] . '?f=&_u=' . datetime_convert('','','','ymdhis')
		);

		if($nextlink)
			$nextlink = array($nextlink, '<div class="icon next"></div>');


		// Do we have an item for this photo?

		// FIXME! - replace following code to display the conversation with our normal 
		// conversation functions so that it works correctly and tracks changes
		// in the evolving conversation code.
		// The difference is that we won't be displaying the conversation head item
		// as a "post" but displaying instead the photo it is linked to

		$linked_items = q("SELECT * FROM `item` WHERE `resource-id` = '%s' $sql_extra LIMIT 1",
			dbesc($datum)
		);
		if(count($linked_items)) {
			$link_item = $linked_items[0];
			$r = q("SELECT COUNT(*) AS `total`
				FROM `item` LEFT JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
				WHERE `parent-uri` = '%s' AND `uri` != '%s' AND `item`.`deleted` = 0 and `item`.`moderated` = 0
				AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0
				AND `item`.`uid` = %d 
				$sql_extra ",
				dbesc($link_item['uri']),
				dbesc($link_item['uri']),
				intval($link_item['uid'])

			);

			if(count($r))
				$a->set_pager_total($r[0]['total']);


			$r = q("SELECT `item`.*, `item`.`id` AS `item_id`, 
				`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`network`, 
				`contact`.`rel`, `contact`.`thumb`, `contact`.`self`, 
				`contact`.`id` AS `cid`, `contact`.`uid` AS `contact-uid`
				FROM `item` LEFT JOIN `contact` ON `contact`.`id` = `item`.`contact-id`
				WHERE `parent-uri` = '%s' AND `uri` != '%s' AND `item`.`deleted` = 0 and `item`.`moderated` = 0
				AND `contact`.`blocked` = 0 AND `contact`.`pending` = 0
				AND `item`.`uid` = %d
				$sql_extra
				ORDER BY `parent` DESC, `id` ASC LIMIT %d ,%d ",
				dbesc($link_item['uri']),
				dbesc($link_item['uri']),
				intval($link_item['uid']),
				intval($a->pager['start']),
				intval($a->pager['itemspage'])

			);

			if((local_user()) && (local_user() == $link_item['uid'])) {
				q("UPDATE `item` SET `unseen` = 0 WHERE `parent` = %d and `uid` = %d",
					intval($link_item['parent']),
					intval(local_user())
				);
			}
		}

		$tags=Null;

		if(count($linked_items) && strlen($link_item['tag'])) {
			$arr = explode(',',$link_item['tag']);
			// parse tags and add links
			$tag_str = '';
			foreach($arr as $t) {
				if(strlen($tag_str))
					$tag_str .= ', ';
				$tag_str .= bbcode($t);
			} 
			$tags = array(t('Tags: '), $tag_str);
			if($cmd === 'edit') {
				$tags[] = $a->get_baseurl() . '/tagrm/' . $link_item['id'];
				$tags[] = t('[Remove any tag]');
			}
		}


		$edit = Null;
		if(($cmd === 'edit') && ($can_post)) {
			$edit_tpl = get_markup_template('photo_edit.tpl');

			// Private/public post links for the non-JS ACL form
			$private_post = 1;
			if($_REQUEST['public'])
				$private_post = 0;

			$query_str = $a->query_string;
			if(strpos($query_str, 'public=1') !== false)
				$query_str = str_replace(array('?public=1', '&public=1'), array('', ''), $query_str);

			// I think $a->query_string may never have ? in it, but I could be wrong
			// It looks like it's from the index.php?q=[etc] rewrite that the web
			// server does, which converts any ? to &, e.g. suggest&ignore=61 for suggest?ignore=61
			if(strpos($query_str, '?') === false)
				$public_post_link = '?public=1';
			else
				$public_post_link = '&public=1';


			if($a->theme['template_engine'] === 'internal') {
				$album_e = template_escape($ph[0]['album']);
				$caption_e = template_escape($ph[0]['desc']);
				$aclselect_e = template_escape(populate_acl($ph[0]));
			}
			else {
				$album_e = $ph[0]['album'];
				$caption_e = $ph[0]['desc'];
				$aclselect_e = populate_acl($ph[0]);
			}

			$edit = replace_macros($edit_tpl, array(
				'$id' => $ph[0]['id'],
				'$rotatecw' => t('Rotate CW (right)'),
				'$rotateccw' => t('Rotate CCW (left)'),
				'$album' => $album_e,
				'$newalbum' => t('New album name'), 
				'$nickname' => $a->data['user']['nickname'],
				'$resource_id' => $ph[0]['resource-id'],
				'$capt_label' => t('Caption'),
				'$caption' => $caption_e,
				'$tag_label' => t('Add a Tag'),
				'$tags' => $link_item['tag'],
				'$permissions' => t('Permissions'),
				'$aclselect' => $aclselect_e,
				'$help_tags' => t('Example: @bob, @Barbara_Jensen, @jim@example.com, #California, #camping'),
				'$item_id' => ((count($linked_items)) ? $link_item['id'] : 0),
				'$submit' => t('Submit'),
				'$delete' => t('Delete Photo'),

				// ACL permissions box
				'$acl_data' => construct_acl_data($a, $ph[0]), // For non-Javascript ACL selector
				'$group_perms' => t('Show to Groups'),
				'$contact_perms' => t('Show to Contacts'),
				'$private' => t('Private photo'),
				'$public' => t('Public photo'),
				'$is_private' => $private_post,
				'$return_path' => $query_str,
				'$public_link' => $public_post_link,
			));
		}

		if(count($linked_items)) {

			$cmnt_tpl = get_markup_template('comment_item.tpl');
			$tpl = get_markup_template('photo_item.tpl');
			$return_url = $a->cmd;

			$like_tpl = get_markup_template('like_noshare.tpl');

			$likebuttons = '';

			if($can_post || can_write_wall($a,$owner_uid)) {
				$likebuttons = replace_macros($like_tpl,array(
					'$id' => $link_item['id'],
					'$likethis' => t("I like this \x28toggle\x29"),
					'$nolike' => (feature_enabled(local_user(), 'dislike') ? t("I don't like this \x28toggle\x29") : ''),
					'$share' => t('Share'),
					'$wait' => t('Please wait'),
					'$return_path' => $a->query_string,
				));
			}

			$comments = '';
			if(! count($r)) {
				if($can_post || can_write_wall($a,$owner_uid)) {
					if($link_item['last-child']) {
						$comments .= replace_macros($cmnt_tpl,array(
							'$return_path' => '', 
							'$jsreload' => $return_url,
							'$type' => 'wall-comment',
							'$id' => $link_item['id'],
							'$parent' => $link_item['id'],
							'$profile_uid' =>  $owner_uid,
							'$mylink' => $contact['url'],
							'$mytitle' => t('This is you'),
							'$myphoto' => $contact['thumb'],
							'$comment' => t('Comment'),
							'$submit' => t('Submit'),
							'$preview' => t('Preview'),
							'$sourceapp' => t($a->sourcename),
							'$ww' => '',
							'$rand_num' => random_digits(12)
						));
					}
				}
			}

			$alike = array();
			$dlike = array();
			
			$like = '';
			$dislike = '';



			// display comments
			if(count($r)) {

				foreach($r as $item) {
					like_puller($a,$item,$alike,'like');
					like_puller($a,$item,$dlike,'dislike');
				}

				$like    = ((isset($alike[$link_item['id']])) ? format_like($alike[$link_item['id']],$alike[$link_item['id'] . '-l'],'like',$link_item['id']) : '');
				$dislike = ((isset($dlike[$link_item['id']])) ? format_like($dlike[$link_item['id']],$dlike[$link_item['id'] . '-l'],'dislike',$link_item['id']) : '');



				if($can_post || can_write_wall($a,$owner_uid)) {
					if($link_item['last-child']) {
						$comments .= replace_macros($cmnt_tpl,array(
							'$return_path' => '',
							'$jsreload' => $return_url,
							'$type' => 'wall-comment',
							'$id' => $link_item['id'],
							'$parent' => $link_item['id'],
							'$profile_uid' =>  $owner_uid,
							'$mylink' => $contact['url'],
							'$mytitle' => t('This is you'),
							'$myphoto' => $contact['thumb'],
							'$comment' => t('Comment'),
							'$submit' => t('Submit'),
							'$preview' => t('Preview'),
							'$sourceapp' => t($a->sourcename),
							'$ww' => '',
							'$rand_num' => random_digits(12)
						));
					}
				}


				foreach($r as $item) {
					$comment = '';
					$template = $tpl;
					$sparkle = '';

					if(((activity_match($item['verb'],ACTIVITY_LIKE)) || (activity_match($item['verb'],ACTIVITY_DISLIKE))) && ($item['id'] != $item['parent']))
						continue;

					$redirect_url = $a->get_baseurl() . '/redir/' . $item['cid'] ;
			

					if(local_user() && ($item['contact-uid'] == local_user()) 
						&& ($item['network'] == 'dfrn') && (! $item['self'] )) {
						$profile_url = $redirect_url;
						$sparkle = ' sparkle';
					}
					else {
						$profile_url = $item['url'];
						$sparkle = '';
					}
 
					$diff_author = (($item['url'] !== $item['author-link']) ? true : false);

					$profile_name   = (((strlen($item['author-name']))   && $diff_author) ? $item['author-name']   : $item['name']);
					$profile_avatar = (((strlen($item['author-avatar'])) && $diff_author) ? $item['author-avatar'] : $item['thumb']);

					$profile_link = $profile_url;

					$drop = '';

					if(($item['contact-id'] == $contact_id) || ($item['uid'] == local_user()))
						$drop = replace_macros(get_markup_template('photo_drop.tpl'), array('$id' => $item['id'], '$delete' => t('Delete')));


					if($a->theme['template_engine'] === 'internal') {
						$name_e = template_escape($profile_name);
						$title_e = template_escape($item['title']);
						$body_e = template_escape(bbcode($item['body']));
					}
					else {
						$name_e = $profile_name;
						$title_e = $item['title'];
						$body_e = bbcode($item['body']);
					}

					$comments .= replace_macros($template,array(
						'$id' => $item['item_id'],
						'$profile_url' => $profile_link,
						'$name' => $name_e,
						'$thumb' => $profile_avatar,
						'$sparkle' => $sparkle,
						'$title' => $title_e,
						'$body' => $body_e,
						'$ago' => relative_date($item['created']),
						'$indent' => (($item['parent'] != $item['item_id']) ? ' comment' : ''),
						'$drop' => $drop,
						'$comment' => $comment
					));

					if($can_post || can_write_wall($a,$owner_uid)) {

						if($item['last-child']) {
							$comments .= replace_macros($cmnt_tpl,array(
								'$return_path' => '',
								'$jsreload' => $return_url,
								'$type' => 'wall-comment',
								'$id' => $item['item_id'],
								'$parent' => $item['parent'],
								'$profile_uid' =>  $owner_uid,
								'$mylink' => $contact['url'],
								'$mytitle' => t('This is you'),
								'$myphoto' => $contact['thumb'],
								'$comment' => t('Comment'),
								'$submit' => t('Submit'),
								'$preview' => t('Preview'),
								'$sourceapp' => t($a->sourcename),
								'$ww' => '',
								'$rand_num' => random_digits(12)
							));
						}
					}
				}
			}

			$paginate = paginate($a);
		}
		
		$photo_tpl = get_markup_template('photo_view.tpl');

		if($a->theme['template_engine'] === 'internal') {
			$album_e = array($album_link,template_escape($ph[0]['album']));
			$tags_e = template_escape($tags);
			$like_e = template_escape($like);
			$dislike_e = template_escape($dislike);
		}
		else {
			$album_e = array($album_link,$ph[0]['album']);
			$tags_e = $tags;
			$like_e = $like;
			$dislike_e = $dislike;
		}

		$o .= replace_macros($photo_tpl, array(
			'$id' => $ph[0]['id'],
			'$album' => $album_e,
			'$tools' => $tools,
			'$lock' => $lock,
			'$photo' => $photo,
			'$prevlink' => $prevlink,
			'$nextlink' => $nextlink,
			'$desc' => $ph[0]['desc'],
			'$tags' => $tags_e,
			'$edit' => $edit,	
			'$likebuttons' => $likebuttons,
			'$like' => $like_e,
			'$dislike' => $dikslike_e,
			'$comments' => $comments,
			'$paginate' => $paginate,
		));
		
		return $o;
	}

	// Default - show recent photos with upload link (if applicable)
	//$o = '';

	$r = q("SELECT `resource-id`, max(`scale`) AS `scale` FROM `photo` WHERE `uid` = %d AND `album` != '%s' AND `album` != '%s' 
		$sql_extra GROUP BY `resource-id`",
		intval($a->data['user']['uid']),
		dbesc('Contact Photos'),
		dbesc( t('Contact Photos'))
	);
	if(count($r)) {
		$a->set_pager_total(count($r));
		$a->set_pager_itemspage(20);
	}

	$r = q("SELECT `resource-id`, `id`, `filename`, type, `album`, max(`scale`) AS `scale` FROM `photo`
		WHERE `uid` = %d AND `album` != '%s' AND `album` != '%s'  
		$sql_extra GROUP BY `resource-id` ORDER BY `created` DESC LIMIT %d , %d",
		intval($a->data['user']['uid']),
		dbesc('Contact Photos'),
		dbesc( t('Contact Photos')),
		intval($a->pager['start']),
		intval($a->pager['itemspage'])
	);



	$photos = array();
	if(count($r)) {
		$twist = 'rotright';
		foreach($r as $rr) {
			if($twist == 'rotright')
				$twist = 'rotleft';
			else
				$twist = 'rotright';
			$ext = $phototypes[$rr['type']];
			
			if($a->theme['template_engine'] === 'internal') {
				$alt_e = template_escape($rr['filename']);
				$name_e = template_escape($rr['album']);
			}
			else {
				$alt_e = $rr['filename'];
				$name_e = $rr['album'];
			}

			$photos[] = array(
				'id'       => $rr['id'],
				'twist'    => ' ' . $twist . rand(2,4),
				'link'  	=> $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/image/' . $rr['resource-id'],
				'title' 	=> t('View Photo'),
				'src'     	=> $a->get_baseurl() . '/photo/' . $rr['resource-id'] . '-' . ((($rr['scale']) == 6) ? 4 : $rr['scale']) . '.' . $ext,
				'alt'     	=> $alt_e,
				'album'	=> array(
					'link'  => $a->get_baseurl() . '/photos/' . $a->data['user']['nickname'] . '/album/' . bin2hex($rr['album']),
					'name'  => $name_e,
					'alt'   => t('View Album'),
				),
				
			);
		}
	}
	
	$tpl = get_markup_template('photos_recent.tpl'); 
	$o .= replace_macros($tpl, array(
		'$title' => t('Recent Photos'),
		'$can_post' => $can_post,
		'$upload' => array(t('Upload New Photos'), $a->get_baseurl().'/photos/'.$a->data['user']['nickname'].'/upload'),
		'$photos' => $photos,
	));

	
	$o .= paginate($a);
	return $o;
}

