<?php
/**
 * Kunena Component
 * @package Kunena.Site
 * @subpackage Views
 *
 * @copyright (C) 2008 - 2011 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

/**
 * Topic View
 */
class KunenaViewTopic extends KunenaView {
	public $topicButtons = null;
	public $messageButtons = null;

	var $poll = null;
	var $mmm = 0;
	var $cache = true;

	public function displayDefault($tpl = null) {
		$this->layout = $this->state->get('layout');
		if ($this->layout == 'flat') $this->layout = 'default';
		$this->setLayout($this->layout);

		$this->category = $this->get ( 'Category' );
		$this->topic = $this->get ( 'Topic' );

		$channels = $this->category->getChannels();
		if ($this->category->id && ! $this->category->authorise('read')) {
			// User is not allowed to see the category
			$this->setError($this->category->getError());

		} elseif (! $this->topic) {
			// Moved topic loop detected (1 -> 2 -> 3 -> 2)
			$this->setError(JText::_('COM_KUNENA_VIEW_TOPIC_ERROR_LOOP'));

		} elseif (! $this->topic->authorise('read')) {
			// User is not allowed to see the topic
			$this->setError($this->topic->getError());

		} elseif ($this->state->get('item.id') != $this->topic->id
				|| ($this->category->id != $this->topic->category_id && !isset($channels[$this->topic->category_id]))
				|| ($this->state->get('layout') != 'threaded' && $this->state->get('item.mesid'))) {
			// We need to redirect: message has been moved or we have permalink
			$mesid = $this->state->get('item.mesid');
			if (!$mesid) {
				$mesid = $this->topic->first_post_id;
			}
			$message = KunenaForumMessageHelper::get($mesid);
			if ($message->exists()) $this->app->redirect($message->getUrl(null, false));
		}

		$errors = $this->getErrors();
		if ($errors) {
			return $this->displayNoAccess($errors);
		}

		$this->messages	=& $this->get ( 'Messages' ) ;
		$this->total	= $this->get ( 'Total' );

		// If page does not exist, redirect to the last page
		if ($this->total <= $this->state->get('list.start')) {
			$this->app->redirect($this->topic->getUrl(null, false, (int)($this->total / $this->state->get('list.limit'))));
		}

		// Run events
		$params = new JParameter( '' );
		$params->set('ksource', 'kunena');
		$params->set('kunena_view', 'topic');
		$params->set('kunena_layout', 'default');

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin('kunena');

		$dispatcher->trigger('onKunenaPrepare', array ('kunena.topic', &$this->topic, &$params, 0));
		$dispatcher->trigger('onKunenaPrepare', array ('kunena.messages', &$this->messages, &$params, 0));

		$this->moderators = $this->get ( 'Moderators' );
		$this->usertopic = $this->topic->getUserTopic();
		$this->headerText =  JText::_('COM_KUNENA_MENU_LATEST_DESC');
		$this->title = JText::_('COM_KUNENA_ALL_DISCUSSIONS');
		$this->pagination = $this->getPagination ( 5 );

		// Mark topic read
		$this->topic->markRead ();
		$this->topic->hit ();

		// Check is subscriptions have been sent and reset the value
		if ($this->topic->authorise('subscribe')) {
			$usertopic = $this->topic->getUserTopic();
			if ($usertopic->subscribed == 2) {
				$usertopic->subscribed = 1;
				$usertopic->save();
			}
		}

		// Get keywords, captcha & quick reply
		$this->captcha = KunenaSpamRecaptcha::getInstance();
		$this->quickreply = ($this->topic->authorise('reply',null, false) && $this->me->exists() && !$this->captcha->enabled());
		$this->keywords = $this->topic->getKeywords(false, ', ');

		//meta description and keywords
		$page = intval ( $this->state->get('list.start') / $this->state->get('list.limit') ) + 1;
		$pages = intval ( ($this->total-1) / $this->state->get('list.limit') ) + 1;

		// TODO: use real keywords, too
		$metaKeys = $this->escape ( "{$this->topic->subject}, {$this->category->getParent()->name}, {$this->config->board_title}, " . JText::_('COM_KUNENA_GEN_FORUM') . ', ' . $this->app->getCfg ( 'sitename' ) );

		// Create Meta Description form the content of the first message
		// better for search results display but NOT for search ranking!
		$metaDesc = KunenaHtmlParser::stripBBCode($this->topic->first_post_message);
		$metaDesc = preg_replace('/\s+/', ' ', $metaDesc); // remove newlines
		$metaDesc = preg_replace('/^[^\w0-9]+/', '', $metaDesc); // remove characters at the beginning that are not letters or numbers
		$metaDesc = trim($metaDesc); // Remove trailing spaces and beginning

		// remove multiple spaces
		while (strpos($metaDesc, '  ') !== false){
			$metaDesc = str_replace('  ', ' ', $metaDesc);
		}

		// limit to 185 characters - google will cut off at ~150
		if (strlen($metaDesc) > 185){
			$metaDesc = rtrim(JString::substr($metaDesc, 0, 182)).'...';
		}

		$this->document->setMetadata ( 'keywords', $metaKeys );
		$this->document->setDescription ( $this->escape($metaDesc) );

		$this->setTitle(JText::sprintf('COM_KUNENA_VIEW_TOPICS_DEFAULT', $this->topic->subject) . " ({$page}/{$pages})");

		$this->display($tpl);
	}

	public function displayFlat($tpl = null) {
		$this->state->set('layout', 'default');
		$this->me->setTopicLayout ( 'flat' );
		$this->displayDefault($tpl);
	}

	public function displayThreaded($tpl = null) {
		$this->state->set('layout', 'threaded');
		$this->me->setTopicLayout ( 'threaded' );
		$this->displayDefault($tpl);
	}

	public function displayIndented($tpl = null) {
		$this->state->set('layout', 'indented');
		$this->me->setTopicLayout ( 'indented' );
		$this->displayDefault($tpl);
	}

	protected function DisplayCreate($tpl = null) {
		$this->setLayout('edit');

		// Get captcha
		$captcha = KunenaSpamRecaptcha::getInstance();
		if ($captcha->enabled()) {
			$this->captchaHtml = $captcha->getHtml();
			if ( !$this->captchaHtml ) {
				$this->app->enqueueMessage ( $captcha->getError(), 'error' );
				$this->redirectBack ();
			}
		}

		// Get saved message
		$saved = $this->app->getUserState('com_kunena.postfields');

		// Get topic icons if allowed
		if ($this->config->topicicons) {
			$this->topicIcons = $this->ktemplate->getTopicIcons(false, $saved ? $saved['icon_id'] : 0);
		}

		$categories = KunenaForumCategoryHelper::getCategories();
		$arrayanynomousbox = array();
		$arraypollcatid = array();
		foreach ($categories as $category) {
			if (!$category->isSection() && $category->allow_anonymous) {
				$arrayanynomousbox[] = '"'.$category->id.'":'.$category->post_anonymous;
			}
			if (!$category->isSection() && $category->allow_polls) {
				$arraypollcatid[] = '"'.$category->id.'":1';
			}
		}
		$arrayanynomousbox = implode(',',$arrayanynomousbox);
		$arraypollcatid = implode(',',$arraypollcatid);
		$this->document->addScriptDeclaration('var arrayanynomousbox={'.$arrayanynomousbox.'}');
		$this->document->addScriptDeclaration('var pollcategoriesid = {'.$arraypollcatid.'};');

		$cat_params = array ('ordering' => 'ordering',
			'toplevel' => 0,
			'sections' => 0,
			'direction' => 1,
			'hide_lonely' => 1,
			'action' => 'topic.create');

		$this->catid = $this->state->get('item.catid');
		$this->category = KunenaForumCategoryHelper::get($this->catid);
		list ($this->topic, $this->message) = $this->category->newTopic($saved);

		if (!$this->topic->category_id) {
			$msg = JText::sprintf ( 'COM_KUNENA_POST_NEW_TOPIC_NO_PERMISSIONS', $this->topic->getError());
			$this->app->enqueueMessage ( $msg, 'notice' );
			return false;
		}

		$options = array();
		$selected = $this->topic->category_id;
		if ( $this->config->pickup_category ) {
			$options[] = JHTML::_ ( 'select.option', '', JText::_('COM_KUNENA_SELECT_CATEGORY'), 'value', 'text' );
			$selected = 0;
		}
		if ($saved) $selected = $saved['catid'];

		$this->selectcatlist = JHTML::_('kunenaforum.categorylist', 'catid', $this->catid, $options, $cat_params, 'class="inputbox required"', 'value', 'text', $selected, 'postcatid');

		$this->title = JText::_ ( 'COM_KUNENA_POST_NEW_TOPIC' );
		$this->action = 'post';

		$this->allowedExtensions = KunenaForumMessageAttachmentHelper::getExtensions($this->category);

		if ($arraypollcatid) $this->poll = $this->topic->getPoll();

		$this->post_anonymous = $saved ? $saved['anonymous'] : ! empty ( $this->category->post_anonymous );
		$this->subscriptionschecked = $saved ? $saved['subscribe'] : $this->config->subscriptionschecked == 1;
		$this->app->setUserState('com_kunena.postfields', null);

		$this->display($tpl);
	}

	protected function DisplayReply($tpl = null) {
		$this->setLayout('edit');

		$captcha = KunenaSpamRecaptcha::getInstance();
		if ($captcha->enabled()) {
			$this->captchaHtml = $captcha->getHtml();
			if ( !$this->captchaHtml ) {
				$this->app->enqueueMessage ( $captcha->getError(), 'error' );
				$this->redirectBack ();
			}
		}

		$saved = $this->app->getUserState('com_kunena.postfields');

		$this->catid = $this->state->get('item.catid');
		$this->mesid = $this->state->get('item.mesid');
		if (!$this->mesid) {
			$this->topic = KunenaForumTopicHelper::get($this->state->get('item.id'));
			$parent = KunenaForumMessageHelper::get($this->topic->first_post_id);
		} else {
			$parent = KunenaForumMessageHelper::get($this->mesid);
			$this->topic = $parent->getTopic();
		}

		if (!$parent->authorise('reply')) {
			$this->app->enqueueMessage ( $parent->getError(), 'notice' );
			return false;
		}

		// Run events
		$params = new JParameter( '' );
		$params->set('ksource', 'kunena');
		$params->set('kunena_view', 'topic');
		$params->set('kunena_layout', 'reply');

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin('kunena');

		$dispatcher->trigger('onKunenaPrepare', array ('kunena.topic', &$this->topic, &$params, 0));

		$quote = (bool) JRequest::getBool ( 'quote', false );
		$this->category = $this->topic->getCategory();
		if ($this->config->topicicons && $this->topic->authorise('edit', null, false)) {
			$this->topicIcons = $this->ktemplate->getTopicIcons(false, $saved ? $saved['icon_id'] : 0);
		}
		list ($this->topic, $this->message) = $parent->newReply($saved ? $saved : $quote);
		$this->title = JText::_ ( 'COM_KUNENA_POST_REPLY_TOPIC' ) . ' ' . $this->topic->subject;
		$this->action = 'post';

		$this->allowedExtensions = KunenaForumMessageAttachmentHelper::getExtensions($this->category);

		$this->post_anonymous = $saved ? $saved['anonymous'] : ! empty ( $this->category->post_anonymous );
		$this->subscriptionschecked = $saved ? $saved['subscribe'] : $this->config->subscriptionschecked == 1;
		$this->app->setUserState('com_kunena.postfields', null);

		$this->display($tpl);
	}

	protected function displayEdit($tpl = null) {
		$this->catid = $this->state->get('item.catid');
		$mesid = $this->state->get('item.mesid');
		$document = JFactory::getDocument();

		$saved = $this->app->getUserState('com_kunena.postfields');

		$this->message = KunenaForumMessageHelper::get($mesid);
		if (!$this->message->authorise('edit')) {
			$this->app->enqueueMessage ( $this->message->getError(), 'notice' );
			return false;
		}
		$this->topic = $this->message->getTopic();
		$this->category = $this->topic->getCategory();
		if ($this->config->topicicons && $this->topic->authorise('edit', null, false)) {
			$this->topicIcons = $this->ktemplate->getTopicIcons(false, $saved ? $saved['icon_id'] : $this->topic->icon_id);
		}

		// Run events
		$params = new JParameter( '' );
		$params->set('ksource', 'kunena');
		$params->set('kunena_view', 'topic');
		$params->set('kunena_layout', 'reply');

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin('kunena');

		$dispatcher->trigger('onKunenaPrepare', array ('kunena.topic', &$this->topic, &$params, 0));

		$this->title = JText::_ ( 'COM_KUNENA_POST_EDIT' ) . ' ' . $this->topic->subject;
		$this->action = 'edit';

		// Get attachments
		$this->attachments = $this->message->getAttachments();

		// Get poll
		if ($this->message->parent == 0 && ($this->topic->authorise('poll.create', null, false) || $this->topic->authorise('poll.edit', null, false))) {
			$this->poll = $this->topic->getPoll();
		}

		$this->allowedExtensions = KunenaForumMessageAttachmentHelper::getExtensions($this->category);

		if ($saved) {
			// Update message contents
			$this->message->edit ( $saved );
		}
		$this->post_anonymous = $saved ? $saved['anonymous'] : ! empty ( $this->category->post_anonymous );
		$this->subscriptionschecked = $saved ? $saved['subscribe'] : $this->config->subscriptionschecked == 1;
		$this->modified_reason = $saved ? $saved['modified_reason'] : '';
		$this->app->setUserState('com_kunena.postfields', null);

		$this->display($tpl);
	}

	function displayVote($tpl = null) {
		// TODO: need to check if poll is allowed in this category
		// TODO: need to check if poll is still active
		$this->category = $this->get ( 'Category' );
		$this->topic = $this->get ( 'Topic' );

		if (!$this->config->pollenabled || !$this->topic->poll_id || !$this->category->allow_polls) {
			return false;
		}

		$this->poll = $this->get('Poll');
		$this->usercount = $this->get('PollUserCount');
		$this->usersvoted = $this->get('PollUsers');
		$this->voted = $this->get('MyVotes');

		$this->display($tpl);
	}

	protected function displayReport($tpl = null) {
		$this->catid = $this->state->get('item.catid');
		$this->id = $this->state->get('item.id');
		$this->mesid = $this->state->get('item.mesid');

		if (!$this->me->exists() || $this->config->reportmsg == 0) {
			// Deny access if report feature has been disabled or user is guest
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_NO_ACCESS' ), 'notice' );
			return;
		}
		if (!$this->mesid) {
			$this->topic = KunenaForumTopicHelper::get($this->id);
			if (!$this->topic->authorise('read')) {
				$this->app->enqueueMessage ( $this->topic->getError(), 'notice' );
				return;
			}
		} else {
			$this->message = KunenaForumMessageHelper::get($this->mesid);
			if (!$this->message->authorise('read')) {
				$this->app->enqueueMessage ( $this->message->getError(), 'notice' );
				return;
			}
			$this->topic = $this->message->getTopic();
		}
		$this->display($tpl);
	}

	protected function displayModerate($tpl = null) {
		$this->mesid = JRequest::getInt('mesid', 0);
		$this->id = $this->state->get('item.id');
		$this->catid = $this->state->get('item.catid');

		if (!$this->mesid) {
			$this->topic = KunenaForumTopicHelper::get($this->id);
			if (!$this->topic->authorise('move')) {
				$this->app->enqueueMessage ( $this->topic->getError(), 'notice' );
				return;
			}
		} else {
			$this->message = KunenaForumMessageHelper::get($this->mesid);
			if (!$this->message->authorise('move')) {
				$this->app->enqueueMessage ( $this->message->getError(), 'notice' );
				return;
			}
			$this->topic = $this->message->getTopic();
		}
		$this->category = $this->topic->getCategory();

		$options =array ();
		if (!$this->mesid) {
			$options [] = JHTML::_ ( 'select.option', 0, JText::_ ( 'COM_KUNENA_MODERATION_MOVE_TOPIC' ) );
		} else {
			$options [] = JHTML::_ ( 'select.option', 0, JText::_ ( 'COM_KUNENA_MODERATION_CREATE_TOPIC' ) );
		}
		$options [] = JHTML::_ ( 'select.option', -1, JText::_ ( 'COM_KUNENA_MODERATION_ENTER_TOPIC' ) );

		$db = JFactory::getDBO();
		$params = array(
			'orderby'=>'tt.last_post_time DESC',
			'where'=>" AND tt.id != {$db->Quote($this->topic->id)} ");
		list ($total, $topics) = KunenaForumTopicHelper::getLatestTopics($this->catid, 0, 30, $params);
		foreach ( $topics as $cur ) {
			$options [] = JHTML::_ ( 'select.option', $cur->id, $this->escape ( $cur->subject ) );
		}
		$this->topiclist = JHTML::_ ( 'select.genericlist', $options, 'targettopic', 'class="inputbox"', 'value', 'text', 0, 'kmod_topics' );

		$options = array ();
		$cat_params = array ('sections'=>0, 'catid'=>0);
		$this->assignRef ( 'categorylist', JHTML::_('kunenaforum.categorylist', 'targetcategory', 0, $options, $cat_params, 'class="inputbox kmove_selectbox"', 'value', 'text', $this->catid, 'kmod_categories'));
		if (isset($this->message)) $this->user = KunenaFactory::getUser($this->message->userid);

		if ($this->mesid) {
			// Get thread and reply count from current message:
			$query = "SELECT COUNT(mm.id) AS replies FROM #__kunena_messages AS m
				INNER JOIN #__kunena_messages AS t ON m.thread=t.id
				LEFT JOIN #__kunena_messages AS mm ON mm.thread=m.thread AND mm.time > m.time
				WHERE m.id={$db->Quote($this->mesid)}";
			$db->setQuery ( $query, 0, 1 );
			$this->replies = $db->loadResult ();
			if (KunenaError::checkDatabaseError()) return;
		}

		$this->display($tpl);
	}

	function displayPoll() {
		// need to check if poll is allowed in this category
		if (!$this->config->pollenabled || !$this->topic->poll_id || !$this->category->allow_polls) {
			return false;
		}
		if ($this->getLayout() == 'poll') {
			$this->category = $this->get ( 'Category' );
			$this->topic = $this->get ( 'Topic' );
		}
		$this->poll = $this->get('Poll');
		$this->usercount = $this->get('PollUserCount');
		$this->usersvoted = $this->get('PollUsers');
		$this->voted = $this->get('MyVotes');

		$this->users_voted_list = array();
		if($this->config->pollresultsuserslist && !empty($this->usersvoted)) {
			$i = 0;
			foreach($this->usersvoted as $userid=>$vote) {
				if ( $i <= '4' ) $this->users_voted_list[] = KunenaFactory::getUser(intval($userid))->getLink();
				else $this->users_voted_morelist[] = KunenaFactory::getUser(intval($userid))->getLink();
				$i++;
			}
		}

		if ($this->voted) echo $this->loadTemplateFile("pollresults");
		else echo $this->loadTemplateFile("poll");
	}

	function displayMessageProfile() {
		echo $this->getMessageProfileBox();
	}

	function getMessageProfileBox() {
		static $profiles = array ();

		$key = $this->profile->userid.'.'.$this->profile->username;
		if (! isset ( $profiles [$key] )) {
			// Modify profile values by integration
			$triggerParams = array ('userid' => $this->profile->userid, 'userinfo' => &$this->profile );
			$integration = KunenaFactory::getProfile();
			$integration->trigger ( 'profileIntegration', $triggerParams );

			//karma points and buttons
			$this->userkarma_title = $this->userkarma_minus = $this->userkarma_plus = '';
			if ($this->config->showkarma && $this->profile->userid) {
				$this->userkarma_title = JText::_ ( 'COM_KUNENA_KARMA' ) . ": " . $this->profile->karma;
				if ($this->me->userid && $this->me->userid != $this->profile->userid) {
					$this->userkarma_minus = ' ' . CKunenaLink::GetKarmaLink ( 'decrease', $this->topic->category_id, $this->message->id, $this->profile->userid, '<span class="kkarma-minus" alt="Karma-" border="0" title="' . JText::_ ( 'COM_KUNENA_KARMA_SMITE' ) . '"> </span>' );
					$this->userkarma_plus = ' ' . CKunenaLink::GetKarmaLink ( 'increase', $this->topic->category_id, $this->message->id, $this->profile->userid, '<span class="kkarma-plus" alt="Karma+" border="0" title="' . JText::_ ( 'COM_KUNENA_KARMA_APPLAUD' ) . '"> </span>' );
				}
			}

			// FIXME: we need to change how profilebox integration works
			/*
			$integration = KunenaFactory::getProfile();
			$triggerParams = array(
				'username' => &$this->username,
				'messageobject' => &$this->msg,
				'subject' => &$this->subjectHtml,
				'messagetext' => &$this->messageHtml,
				'signature' => &$this->signatureHtml,
				'karma' => &$this->userkarma_title,
				'karmaplus' => &$this->userkarma_plus,
				'karmaminus' => &$this->userkarma_minus,
				'layout' => $direction
			);

			$profileHtml = $integration->showProfile($this->msg->userid, $triggerParams);
			*/
			$profileHtml = '';
			if ($profileHtml) {
				// Use integration
				$profiles [$key] = $profileHtml;
			} else {
				$usertype = $this->profile->getType($this->category->id, true);
				if ($this->me->exists() && $this->message->userid == $this->me->userid) $usertype = 'me';

				// TODO: add context (options, template) to caching
				$cache = JFactory::getCache('com_kunena', 'output');
				$cachekey = "profile.{$this->getTemplateMD5()}.{$this->profile->userid}.{$usertype}";
				$cachegroup = 'com_kunena.messages';

				$contents = $cache->get($cachekey, $cachegroup);
				if (!$contents) {
					$this->userkarma = "{$this->userkarma_title} {$this->userkarma_minus} {$this->userkarma_plus}";
					// Use kunena profile
					if ($this->config->showuserstats) {
						if ($this->config->userlist_usertype) {
							$this->usertype = $this->profile->getType ( $this->topic->category_id );
						} else {
							$this->usertype = null;
						}
						$this->userrankimage = $this->profile->getRank ( $this->topic->category_id, 'image' );
						$this->userranktitle = $this->profile->getRank ( $this->topic->category_id, 'title' );
						$this->userposts = $this->profile->posts;
						$activityIntegration = KunenaFactory::getActivityIntegration ();
						$this->userthankyou = $this->profile->thankyou;
						$this->userpoints = $activityIntegration->getUserPoints ( $this->profile->userid );
						$this->usermedals = $activityIntegration->getUserMedals ( $this->profile->userid );
					} else {
						$this->usertype = null;
						$this->userrankimage = null;
						$this->userranktitle = null;
						$this->userposts = null;
						$this->userthankyou = null;
						$this->userpoints = null;
						$this->usermedals = null;
					}
					$this->personalText = KunenaHtmlParser::parseText ( $this->profile->personalText );

					$contents = $this->loadTemplateFile('profile');
					if ($this->cache) $cache->store($contents, $cachekey, $cachegroup);
				}
				$profiles [$key] = $contents;
			}
		}
		return $profiles [$key];
	}

	function displayMessageContents() {
		echo $this->loadTemplateFile('message');
	}

	function displayTopicActions($location=0) {
		echo $this->getTopicActions($location);
	}

	function getTopicActions($location=0) {
		static $locations = array('top', 'bottom');

		$catid = $this->state->get('item.catid');
		$id = $this->state->get('item.id');

		$task = "index.php?option=com_kunena&view=topic&task=%s&catid={$catid}&id={$id}&" . JUtility::getToken() . '=1';
		$layout = "index.php?option=com_kunena&view=topic&layout=%s&catid={$catid}&id={$id}";

		$this->topicButtons = new JObject();

		// Reply topic
		if ($this->topic->authorise('reply')) {
			// this user is allowed to reply to this topic
			$this->topicButtons->set('reply', $this->getButton(sprintf($layout, 'reply'), 'reply', 'topic', 'communication'));
		}

		// Subscribe topic
		if ($this->usertopic->subscribed) {
			// this user is allowed to unsubscribe
			$this->topicButtons->set('subscribe', $this->getButton(sprintf($task, 'unsubscribe'), 'unsubscribe', 'topic', 'user'));
		} elseif ($this->topic->authorise('subscribe')) {
			// this user is allowed to subscribe
			$this->topicButtons->set('subscribe', $this->getButton(sprintf($task, 'subscribe'), 'subscribe', 'topic', 'user'));
		}

		// Favorite topic
		if ($this->usertopic->favorite) {
			// this user is allowed to unfavorite
			$this->topicButtons->set('favorite', $this->getButton(sprintf($task, 'unfavorite'), 'unfavorite', 'topic', 'user'));
		} elseif ($this->topic->authorise('favorite')) {
			// this user is allowed to add a favorite
			$this->topicButtons->set('favorite', $this->getButton(sprintf($task, 'favorite'), 'favorite', 'topic', 'user'));
		}

		// Moderator specific buttons
		if ($this->category->authorise('moderate')) {
			$sticky = $this->topic->ordering ? 'unsticky' : 'sticky';
			$lock = $this->topic->locked ? 'unlock' : 'lock';

			$this->topicButtons->set('sticky', $this->getButton(sprintf($task, $sticky), $sticky, 'topic', 'moderation'));
			$this->topicButtons->set('lock', $this->getButton(sprintf($task, $lock), $lock, 'topic', 'moderation'));
			$this->topicButtons->set('moderate', $this->getButton(sprintf($layout, 'moderate'), 'moderate', 'topic', 'moderation'));
			$this->topicButtons->set('delete', $this->getButton(sprintf($task, 'delete'), 'delete', 'topic', 'moderation'));
		}

		if ($this->config->enable_threaded_layouts) {

			$url = "index.php?option=com_kunena&view=user&task=change&topic_layout=%s&" . JUtility::getToken() . '=1';
			if ($this->layout != 'default') {
				$this->topicButtons->set('flat', $this->getButton ( sprintf($url, 'flat'), 'flat', 'layout', 'user'));
			}
			if ($this->layout != 'threaded') {
				$this->topicButtons->set('threaded', $this->getButton ( sprintf($url, 'threaded'), 'threaded', 'layout', 'user'));
			}
			if ($this->layout != 'indented') {
				$this->topicButtons->set('indented', $this->getButton ( sprintf($url, 'indented'), 'indented', 'layout', 'user'));
			}
		}
		$location ^= 1;
		$this->goto = '<a name="forum'.$locations[$location].'"></a>';
		$this->goto .= CKunenaLink::GetSamePageAnkerLink ( 'forum'.$locations[$location], $this->getIcon ( 'kforum'.$locations[$location], JText::_('COM_KUNENA_GEN_GOTO'.$locations[$location] ) ), 'nofollow', 'kbuttongoto');

		return $this->loadTemplateFile('actions');
	}

	function displayMessageActions() {
		echo $this->getMessageActions();
	}

	function getMessageActions() {
		$catid = $this->state->get('item.catid');
		$id = $this->topic->id;
		$mesid = $this->message->id;

		$task = "index.php?option=com_kunena&view=topic&task=%s&catid={$catid}&id={$id}&mesid={$mesid}&" . JUtility::getToken() . '=1';
		$layout = "index.php?option=com_kunena&view=topic&layout=%s&catid={$catid}&id={$id}&mesid={$mesid}";

		$this->messageButtons = new JObject();
		$this->message_closed = null;

		// Reply / Quote
		if ($this->message->authorise('reply')) {
			$this->quickreply ? $this->messageButtons->set('quickreply', $this->getButton ( sprintf($layout, 'reply'), 'quickreply', 'message', 'communication', "kreply{$mesid}")) : null;
			$this->messageButtons->set('reply', $this->getButton ( sprintf($layout, 'reply'), 'reply', 'message', 'communication'));
			$this->messageButtons->set('quote', $this->getButton ( sprintf($layout, 'reply&quote=1'), 'quote', 'message', 'communication'));

		} elseif (!$this->me->isModerator ( $this->topic->category_id )) {
			// User is not allowed to write a post
			$this->message_closed = $this->topic->locked ? JText::_('COM_KUNENA_POST_LOCK_SET') : JText::_('COM_KUNENA_VIEW_DISABLED');
		}

		// Thank you
		$thankyou = $this->message->getThankyou();
		if($this->message->authorise('thankyou') && !$thankyou->exists($this->me->userid)) {
			$this->messageButtons->set('thankyou', $this->getButton ( sprintf($task, 'thankyou'), 'thankyou', 'message', 'user'));
		}

		// Report this
		if ($this->config->reportmsg && $this->me->exists()) {
			$this->messageButtons->set('report', $this->getButton ( sprintf($layout, 'report'), 'report', 'message', 'user'));
		}

		if ($this->me->isModerator ( $this->topic->category_id )) {
			// Moderator buttons
			$this->messageButtons->set('edit', $this->getButton ( sprintf($layout, 'edit'), 'edit', 'message', 'moderation'));
			$this->messageButtons->set('moderate', $this->getButton ( sprintf($layout, 'moderate'), 'moderate', 'message', 'moderation'));
			$this->message->hold == 1 ? $this->messageButtons->set('publish', $this->getButton ( sprintf($task, 'approve'), 'approve', 'message', 'moderation')) : null;

			if ($this->message->hold == 2 || $this->message->hold == 3) {
				$this->messageButtons->set('undelete', $this->getButton ( sprintf($task, 'undelete'), 'undelete', 'message', 'moderation'));
				$this->messageButtons->set('permdelete', $this->getButton ( sprintf($task, 'permdelete'), 'permdelete', 'message', 'permanent'));
			} else {
				$this->messageButtons->set('delete', $this->getButton ( sprintf($task, 'delete'), 'delete', 'message', 'moderation'));
			}

		} else {
			// Allow user to edit and delete his post
			$this->message->authorise('edit') ? $this->messageButtons->set('edit', $this->getButton ( sprintf($layout, 'edit'), 'edit', 'message', 'moderation')) : null;
			$this->message->authorise('delete') ? $this->messageButtons->set('delete', $this->getButton ( sprintf($task, 'delete'), 'delete', 'message', 'moderation')) : null;
		}

		return $this->loadTemplateFile("message_actions");
	}

	function displayMessage($id, $message, $template=null) {
		$layout = $this->getLayout();
		if (!$template) {
			$template = $this->state->get('profile.location');
			$this->setLayout('default');
		}

		$this->mmm ++;
		$this->message = $message;
		$this->profile = $this->message->getAuthor();
		$this->replynum = $id;
		$usertype = $this->me->getType($this->category->id, true);
		if ($usertype == 'user' && $this->message->userid == $this->profile->userid) $usertype = 'owner';

		// Thank you info and buttons
		$this->thankyou = array();
		if ($this->config->showthankyou && $this->profile->userid) {
			$task = "index.php?option=com_kunena&view=topic&task=%s&catid={$this->category->id}&id={$this->topic->id}&mesid={$this->message->id}&" . JUtility::getToken() . '=1';
			$thankyou = $this->message->getThankyou();
			//TODO: for normal users, show only limited number of thankyou (config->thankyou_max)
			foreach( $thankyou->getList() as $userid=>$time){
				$thankyou_delete = $this->me->isModerator() ? ' <a title="'.JText::_('COM_KUNENA_BUTTON_THANKYOU_REMOVE_LONG').'" href="'
					. KunenaRoute::_(sprintf($task, "unthankyou&userid={$userid}")).'"><img src="'.$this->ktemplate->getImagePath('icons/publish_x.png').'" title="" alt="" /></a>' : '';
				$this->thankyou[] = KunenaFactory::getUser(intval($userid))->getLink().$thankyou_delete;
			}
		}

		// TODO: add context (options, template) to caching
		$cache = JFactory::getCache('com_kunena', 'output');
		$cachekey = "message.{$this->getTemplateMD5()}.{$layout}.{$template}.{$usertype}.c{$this->category->id}.m{$this->message->id}.{$this->message->modified_time}";
		$cachegroup = 'com_kunena.messages';

		$contents = $cache->get($cachekey, $cachegroup);
		if (!$contents) {

			//Show admins the IP address of the user:
			if ($this->message->ip && ($this->category->authorise('admin') || ($this->category->authorise('moderate') && !$this->config->hide_ip))) {
				$this->ipLink = CKunenaLink::GetMessageIPLink ( $this->message->ip );
			}
			$this->signatureHtml = KunenaHtmlParser::parseBBCode ( $this->profile->signature );
			$this->attachments = $this->message->getAttachments();

			// Link to individual message
			if ($this->config->ordering_system == 'replyid') {
				$this->numLink = CKunenaLink::GetSamePageAnkerLink( $message->id, '#[K=REPLYNO]' );
			} else {
				$this->numLink = CKunenaLink::GetSamePageAnkerLink ( $message->id, '#' . $message->id );
			}

			if ($this->message->hold == 0) {
				$this->class = 'kmsg';
			} elseif ($this->message->hold == 1) {
				$this->class = 'kmsg kunapproved';
			} else if ($this->message->hold == 2 || $this->message->hold == 3) {
				$this->class = 'kmsg kdeleted';
			}

			// New post suffix for class
			$this->msgsuffix = '';
			if ($this->message->isNew()) {
				$this->msgsuffix = '-new';
			}

			$contents = $this->loadTemplateFile($template);
			if ($usertype == 'guest') $contents = preg_replace_callback('|\[K=(\w+)(?:\:(\w+))?\]|', array($this, 'fillMessageInfo'), $contents);
			if ($this->cache) $cache->store($contents, $cachekey, $cachegroup);
		} elseif ($usertype == 'guest') {
			echo $contents;
			$this->setLayout($layout);
			return;
		}
		$contents = preg_replace_callback('|\[K=(\w+)(?:\:(\w+))?\]|', array($this, 'fillMessageInfo'), $contents);
		echo $contents;
		$this->setLayout($layout);
	}

	function fillMessageInfo($matches) {
		switch ($matches[1]) {
			case 'ROW':
				return $this->mmm & 1 ? 'odd' : 'even';
			case 'DATE':
				$date = new KunenaDate($matches[2]);
				return $date->toSpan('config_post_dateformat', 'config_post_dateformat_hover');
			case 'NEW':
				return $this->message->isNew() ? 'new' : 'old';
			case 'REPLYNO':
				return $this->replynum;
			case 'MESSAGE_PROFILE':
				return $this->getMessageProfileBox();
			case 'MESSAGE_ACTIONS':
				return $this->getMessageActions();
		}
	}

	function displayMessages($template = null) {
		foreach ( $this->messages as $id=>$message ) {
			$this->displayMessage($id, $message, $template);
		}
	}

	function getPagination($maxpages) {
		$uri = KunenaRoute::normalize(null, true);
		$uri->delVar('mesid');
		$pagination = new KunenaHtmlPagination ( $this->total, $this->state->get('list.start'), $this->state->get('list.limit') );
		$pagination->setDisplay($maxpages, $uri);
		return $pagination->getPagesLinks();
	}

	// Helper functions

	function hasThreadHistory() {
		if (! $this->config->showhistory || !$this->topic->exists())
			return false;
		return true;
	}

	function displayThreadHistory() {
		if (! $this->hasThreadHistory())
			return;

		$db = JFactory::getDBO();
		$this->history = KunenaForumMessageHelper::getMessagesByTopic($this->topic, 0, (int) $this->config->historylimit, $ordering='DESC');
		$this->historycount = count ( $this->history );
		KunenaForumMessageAttachmentHelper::getByMessage($this->history);
		$userlist = array();
		foreach ($this->history as $message) {
			$userlist[(int) $message->userid] = (int) $message->userid;
		}
		KunenaUserHelper::loadUsers($userlist);

		// Run events
		$params = new JParameter( '' );
		$params->set('ksource', 'kunena');
		$params->set('kunena_view', 'topic');
		$params->set('kunena_layout', 'history');

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin('kunena');

		$dispatcher->trigger('onKunenaPrepare', array ('kunena.messages', &$this->history, &$params, 0));

		echo $this->loadTemplateFile ( 'history' );
	}

	function redirectBack() {
		$httpReferer = JRequest::getVar ( 'HTTP_REFERER', JURI::base ( true ), 'server' );
		$this->app->redirect ( $httpReferer );
	}

	public function getNumLink($mesid, $replycnt) {
		if ($this->config->ordering_system == 'replyid') {
			$this->numLink = CKunenaLink::GetSamePageAnkerLink ( $mesid, '#' . $replycnt );
		} else {
			$this->numLink = CKunenaLink::GetSamePageAnkerLink ( $mesid, '#' . $mesid );
		}

		return $this->numLink;
	}

	function displayAttachments($message=null) {
		if ($message instanceof KunenaForumMessage) {
			$this->attachments = $message->getAttachments();
			if (!empty($this->attachments)) echo $this->loadTemplateFile ( 'attachments' );
		} else {
			echo JText::_('COM_KUNENA_ATTACHMENTS_ERROR_NO_MESSAGE');
		}
	}

	function displayMessageField($name) {
		return $this->message->displayField($name);
	}
	function displayTopicField($name) {
		return $this->topic->displayField($name);
	}
	function displayCategoryField($name) {
		return $this->category->displayField($name);
	}

	function canSubscribe() {
		if (! $this->me->userid || ! $this->config->allowsubscriptions || $this->config->topic_subscriptions == 'disabled')
			return false;
		return ! $this->topic->getUserTopic()->subscribed;
	}
}