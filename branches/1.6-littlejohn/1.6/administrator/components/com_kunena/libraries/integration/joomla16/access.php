<?php
/**
 * @version $Id: kunena.session.class.php 2071 2010-03-17 11:27:58Z mahagr $
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2010 Kunena Team All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.com
 *
 **/
//
// Dont allow direct linking
defined( '_JEXEC' ) or die('');

class KunenaAccessJoomla16 extends KunenaAccess {
	function isAdmin($uid = null) {
		static $instances = null;

		// Avoid loading instances if it is possible
		$my = JFactory::getUser();
		if ($uid === null || (is_numeric($uid) && $uid == $my->id)){
			$uid = $my;
		}
		if ($uid instanceof JUser) {
			$usertype = $uid->get('usertype');
			return ($usertype == 'Administrator' || $usertype == 'Super Administrator');
		}
		if (!is_numeric($uid) || $uid == 0) return false;

		if ($instances === null) {
			user_error('Admin detection not implemented in Joomla 1.6!');
			$kunena_db = &JFactory::getDBO();
			$kunena_db->setQuery ("SELECT u.id FROM #__users AS u"
				." WHERE u.block='0' "
				." AND u.usertype IN ('Administrator', 'Super Administrator')");
			$instances = (array)$kunena_db->loadResultArray();
			check_dberror("Unable to load administrators.");
		}

		if (in_array($uid, $instances)) return true;
		return false;
	}

	function isModerator($uid=null, $catid=0) {
		static $instances = null;

		$catid = (int)$catid;

		$my = JFactory::getUser();
		if ($uid === null || (is_numeric($uid) && $uid == $my->id)){
			$uid = $my;
		}
		// Administrators are always moderators
		if (self::isAdmin($uid)) return true;
		if ($uid instanceof JUser) {
			$uid = $uid->id;
		}
		// Visitors cannot be moderators
		if (!is_numeric($uid) || $uid == 0) return false;

		if (!$instances) {
			$kunena_db = &JFactory::getDBO();
			$kunena_db->setQuery ("SELECT u.id AS uid, m.catid FROM #__users AS u"
				." LEFT JOIN #__fb_users AS p ON u.id=p.userid"
				." LEFT JOIN #__fb_moderation AS m ON u.id=m.userid"
				." LEFT JOIN #__fb_categories AS c ON m.catid=c.id"
				." WHERE u.block='0' AND p.moderator='1' AND (m.catid IS NULL OR c.moderated='1')");
			$list = $kunena_db->loadObjectList();
			check_dberror("Unable to load moderators.");
			foreach ($list as $item) $instances[$item->uid][] = $item->catid;
		}

		if (isset($instances[$uid])) {
			// Is user a global moderator?
			if (in_array(null, $instances[$uid], true)) return true;
			// Is user moderator in any category?
			if (!$catid && count($instances[$uid])) return true;
			// Is user moderator in the category?
			if ($catid && in_array($catid, $instances[$uid])) return true;
		}
		return false;
	}

	function getAllowedCategories($userid) {
		user_error('Permission control not implemented in Joomla 1.6!');
		$db = JFactory::getDBO ();

		$query = "SELECT c.id, c.pub_access, c.pub_recurse, c.admin_access, c.admin_recurse
				FROM #__fb_categories c
				WHERE published='1'";
		$db->setQuery ( $query );
		$rows = $db->loadObjectList ();
		if (CKunenaTools::checkDatabaseError()) return array();
		$catlist = array();
		foreach ( $rows as $row ) {
			$catlist[] = $row->id;
		}
		return implode(',', $catlist);
	}

	function getSubscribers($catid, $thread, $subscriptions = false, $moderators = false, $admins = false, $excludeList = '0') {
		$catid = intval ( $catid );
		$thread = intval ( $thread );
		if (! $catid || ! $thread)
			return array();

		// Make sure that category exists and fetch access info
		$kunena_db = &JFactory::getDBO ();
		$query = "SELECT pub_access, pub_recurse, admin_access, admin_recurse FROM #__fb_categories WHERE id={$catid}";
		$kunena_db->setQuery ($query);
		$access = $kunena_db->loadObject ();
		check_dberror ( "Unable to load category access rights." );
		if (!$access) return array();

		// TODO: add groups handling to here
		$groups = '';

		$querysel = "SELECT u.id, u.name, u.username, u.email,
					IF( s.thread IS NOT NULL, 1, 0 ) AS subscription,
					IF( c.moderated=1 AND p.moderator=1 AND ( m.catid IS NULL OR m.catid={$catid}), 1, 0 ) AS moderator,
					0 AS admin
					FROM #__users AS u
					LEFT JOIN #__fb_users AS p ON u.id=p.userid
					LEFT JOIN #__fb_categories AS c ON c.id=$catid
					LEFT JOIN #__fb_moderation AS m ON u.id=m.userid AND m.catid=c.id
					LEFT JOIN #__fb_subscriptions AS s ON u.id=s.userid AND s.thread=$thread
					LEFT JOIN #__fb_subscriptions_categories AS sc ON u.id=sc.userid AND sc.catid=c.id";

		$where = array ();
		if ($subscriptions)
			$where [] = " ( s.thread IS NOT NULL" . ($groups ? " AND {$groups}" : '') . " ) ";
		if ($moderators)
			$where [] = " ( c.moderated=1 AND p.moderator=1 AND ( m.catid IS NULL OR m.catid={$catid} ) ) ";
//		if ($admins)
//			$where [] = " ( u.gid IN (24, 25) ) ";

		$subsList = array ();
		if (count ($where)) {
			$where = " AND (" . implode ( ' OR ', $where ) . ")";
			$query = $querysel . " WHERE u.block=0 AND u.id NOT IN ($excludeList) $where GROUP BY u.id";
			$kunena_db->setQuery ( $query );
			$subsList = $kunena_db->loadObjectList ();
			check_dberror ( "Unable to load email list." );
		}
		return $subsList;
	}
}