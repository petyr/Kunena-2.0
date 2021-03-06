<?php
/**
 * Kunena Component
 * @package Kunena.Framework
 * @subpackage Integration
 *
 * @copyright (C) 2008 - 2011 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

class KunenaProfile
{
	protected static $instance = false;

	static public function getInstance($integration = null) {
		if (self::$instance === false) {
			JPluginHelper::importPlugin('kunena');
			$dispatcher = JDispatcher::getInstance();
			$classes = $dispatcher->trigger('onKunenaGetProfile');
			foreach ($classes as $class) {
				if (!is_object($class)) continue;
				self::$instance = $class;
				break;
			}
			if (!self::$instance) {
				self::$instance = new KunenaProfile();
			}
		}
		return self::$instance;
	}

	public function getTopHits($limit=0) {
		if (!$limit) $limit = KunenaFactory::getConfig ()->popusercount;
		return $this->_getTopHits($limit);
	}

	// TODO: remove these when we have right event
	public function open() {}
	public function close() {}
	public function trigger() {}

	public function getUserListURL($action='', $xhtml = true) {}
	public function getProfileURL($user, $task='', $xhtml = true) {}
	public function showProfile($userid, &$msg_params) {}
	protected function _getTopHits($limit=0) {}
}
