<?php
/**
*
* @package Titania
* @version $Id$
* @copyright (c) 2008 phpBB Customisation Database Team
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

class topics_overlord
{
	/**
	* Topics array
	* Stores [id] => topic row
	*
	* @var array
	*/
	public static $topics = array();

	public static $sort_by = array(
		'a' => array('AUTHOR', 't.topic_first_post_username'),
		't' => array('POST_TIME', 't.topic_last_post_time', true),
		's' => array('SUBJECT', 't.topic_subject'),
		//'r' => array('REPLIES', ),
		'v' => array('VIEWS', 't.topic_views'),
	);

	/**
	 * Generate the permissions stuff for sql queries to the topics table (handles topic_access, topict_deleted, topic_approved)
	 *
	 * @param <string> $prefix prefix for the query
	 * @param <bool> $where true to use WHERE, false if you already did use WHERE
	 * @return <string>
	 */
	public static function sql_permissions($prefix = 't.', $where = false, $no_where = false)
	{
		$sql = ($no_where) ? '' : (($where) ? ' WHERE' : ' AND');
		$sql .= " ({$prefix}topic_access >= " . titania::$access_level . " OR {$prefix}topic_first_post_user_id = " . phpbb::$user->data['user_id'] . ')';

		if (phpbb::$auth->acl_get('u_titania_mod_post_mod'))
		{
			$sql .= " AND {$prefix}topic_approved = 1";
		}

		return $sql;
	}

	/**
	* Load a topic from a post
	*
	* @param int $post_id
	* @return mixed false if post does not exist, topic_id if it does
	* @param bool $no_auth_check True to not check for authorization, false to check
	*/
	public static function load_topic_from_post($post_id, $no_auth_check = false)
	{
		$sql_ary = array(
			'SELECT' => 't.*',

			'FROM'		=> array(
				TITANIA_POSTS_TABLE		=> 'p',
				TITANIA_TOPICS_TABLE	=> 't',
			),

			'WHERE' => 'p.post_id = ' . (int) $post_id . '
				AND t.topic_id = p.topic_id',
		);

		// Sometimes we must check the auth later on
		if (!$no_auth_check)
		{
			$sql_ary['WHERE'] .= self::sql_permissions('t.');
		}

		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);
		$result = phpbb::$db->sql_query($sql);
		$row = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);

		if ($row)
		{
			self::$topics[$row['topic_id']] = $row;
			return $row['topic_id'];
		}

		return false;
	}

	/**
	* Load topic(s) from topic id(s)
	*
	* @param int|array $topic_id topic_id or an array of topic_ids
	* @param bool $no_auth_check True to not check for authorization, false to check
	*/
	public static function load_topic($topic_id, $no_auth_check = false)
	{
		if (!is_array($topic_id))
		{
			$topic_id = array($topic_id);
		}

		// Only get the rows for those we have not gotten already
		$topic_id = array_diff($topic_id, array_keys(self::$topics));

		if (!sizeof($topic_id))
		{
			return;
		}

		$sql_ary = array(
			'SELECT' => '*',

			'FROM'		=> array(
				TITANIA_TOPICS_TABLE	=> 't',
			),

			'WHERE' => phpbb::$db->sql_in_set('t.topic_id', array_map('intval', $topic_id)),
		);

		// Sometimes we must check the auth later on
		if (!$no_auth_check)
		{
			$sql_ary['WHERE'] .= self::sql_permissions('t.');
		}

		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		$result = phpbb::$db->sql_query($sql);

		while($row = phpbb::$db->sql_fetchrow($result))
		{
			self::$topics[$row['topic_id']] = $row;
		}

		phpbb::$db->sql_freeresult($result);
	}

	/**
	 * Get the topic object
	 *
	 * @param <int> $topic_id
	 * @return <object|bool> False if the topic does not exist in the self::$topics array (load it first!) topic object if it exists
	 */
	public static function get_topic_object($topic_id)
	{
		if (!isset(self::$topics[$topic_id]))
		{
			return false;
		}

		$topic = new titania_topic();
		$topic->__set_array(self::$topics[$topic_id]);

		return $topic;
	}

	/**
	* Do everything we need to display the forum like page
	*
	* @param string $type
	* @param mixed $object
	*/
	public static function display_forums_complete($type, $object = false)
	{
/*
user_topic_show_days

$limit_topic_days = array(0 => $user->lang['ALL_TOPICS'], 1 => $user->lang['1_DAY'], 7 => $user->lang['7_DAYS'], 14 => $user->lang['2_WEEKS'], 30 => $user->lang['1_MONTH'], 90 => $user->lang['3_MONTHS'], 180 => $user->lang['6_MONTHS'], 365 => $user->lang['1_YEAR']);
*/
		phpbb::$user->add_lang('viewforum');

		self::display_forums($type, $object);
		self::assign_common();

		phpbb::$template->assign_vars(array(
			'S_TOPIC_LIST'			=> true,
		));
	}

	/**
	* Display "forum" like section for support/tracker/etc
	*
	* @param string $type The type (support, review, queue, tracker, author_support, author_tracker) author_ for displaying posts from the areas the given author is involved in (either an author/co-author)
	* @param object|boolean $object The object (for contrib related (support, review, queue, tracker) and author_ modes)
	* @param object|boolean $sort The sort object (includes/tools/sort.php)
	* @param object|boolean $pagination The pagination object (includes/tools/pagination.php)
	*/
	public static function display_forums($type, $object = false, $sort = false, $pagination = false)
	{
		if ($sort === false)
		{
			// Setup the sort tool
			$sort = new titania_sort();
			$sort->set_sort_keys(self::$sort_by);
			if (isset(self::$sort_by[phpbb::$user->data['user_topic_sortby_type']]))
			{
				$sort->default_key = phpbb::$user->data['user_topic_sortby_type'];
			}
			$sort->default_dir = phpbb::$user->data['user_topic_sortby_dir'];
		}

		if ($pagination === false)
		{
			// Setup the pagination tool
			$pagination = new titania_pagination();
			$pagination->default_limit = phpbb::$config['topics_per_page'];
			$pagination->request();
		}
		$pagination->result_lang = 'TOTAL_TOPICS';

		$topic_ids = array();

		$sql_ary = array(
			'SELECT' => '*',

			'FROM'		=> array(
				TITANIA_TOPICS_TABLE	=> 't',
			),

			'WHERE' => self::sql_permissions('t.', false, true),

			'ORDER_BY'	=> 't.topic_sticky DESC, ' . $sort->get_order_by(),
		);

		titania_tracking::get_track_sql($sql_ary, TITANIA_TOPIC, 't.topic_id');

		// Setup the topic we will use for parsing the output (before the switch so we are able to do type specific things for it)
		$topic = new titania_topic();

		// type specific things
		switch ($type)
		{
			case 'tracker' :
				$page_url = $object->get_url('tracker');
				$sql_ary['WHERE'] .= ' AND t.parent_id = ' . (int) $object->contrib_id;
				$sql_ary['WHERE'] .= ' AND t.topic_type = ' . TITANIA_TRACKER;

				if (isset($options['category']))
				{
					$sql_ary['WHERE'] .= ' AND t.topic_category = ' . (int) $options['category'];
				}
			break;

			case 'queue' :
				$page_url = titania_url::build_url('manage/queue');
				$sql_ary['WHERE'] .= ' AND t.topic_type = ' . TITANIA_QUEUE;
			break;

			case 'queue_discussion' :
				$page_url = titania_url::build_url('manage/queue_discussion');
				$sql_ary['WHERE'] .= ' AND t.topic_type = ' . TITANIA_QUEUE_DISCUSSION;

				// Only display those in which the users are authed
				$authed = titania_types::find_authed('queue_discussion');
				if (!sizeof($authed))
				{
					return;
				}
				$sql_ary['WHERE'] .= ' AND ' . phpbb::$db->sql_in_set('t.topic_category', $authed);

				// Additional tracking fields
				titania_tracking::get_track_sql($sql_ary, TITANIA_QUEUE_DISCUSSION, 0, 'tqt');
				$topic->additional_unread_fields[] = array('type' => TITANIA_QUEUE_DISCUSSION, 'id' => 0, 'type_match' => true);
				titania_tracking::get_track_sql($sql_ary, TITANIA_SUPPORT, 't.parent_id', 'tst');
				$topic->additional_unread_fields[] = array('type' => TITANIA_SUPPORT, 'parent_match' => true);
			break;

			case 'author_support' :
				$page_url = $object->get_url('support');
				$contrib_ids = titania::$cache->get_author_contribs($object->user_id);
				$sql_ary['WHERE'] .= ' AND ' . phpbb::$db->sql_in_set('t.parent_id', array_map('intval', $contrib_ids));

				// We also display the queue discussion topic between validators and authors in the support area
				$sql_ary['WHERE'] .= ' AND (t.topic_type = ' . TITANIA_SUPPORT . ' OR t.topic_type = ' . TITANIA_QUEUE_DISCUSSION . ')';

				// Additional tracking fields
				titania_tracking::get_tracks(TITANIA_SUPPORT, $contrib_ids);
				$topic->additional_unread_fields[] = array('type' => TITANIA_SUPPORT, 'parent_match' => true);

				// Track the queue stuff too if applicable
				if (titania_types::find_authed('queue_discussion'))
				{
					titania_tracking::get_track_sql($sql_ary, TITANIA_QUEUE_DISCUSSION, 0, 'tqt');
					$topic->additional_unread_fields[] = array('type' => TITANIA_QUEUE_DISCUSSION, 'id' => 0, 'type_match' => true);
				}
			break;

			case 'author_tracker' :
				$page_url = $object->get_url('tracker');
				$contrib_ids = titania::$cache->get_author_contribs($object->user_id);
				$sql_ary['WHERE'] .= ' AND ' . phpbb::$db->sql_in_set('t.parent_id', array_map('intval', $contrib_ids));

				$sql_ary['WHERE'] .= ' AND t.topic_type = ' . TITANIA_TRACKER;
			break;

			case 'support' :
			default :
				$page_url = $object->get_url('support');
				$sql_ary['WHERE'] .= ' AND t.parent_id = ' . (int) $object->contrib_id;

				// We also display the queue discussion topic between validators and authors in the support area
				if ($object->is_author ||$object->is_active_coauthor || titania_types::$types[$object->contrib_type]->acl_get('queue_discussion'))
				{
					$sql_ary['WHERE'] .= ' AND (t.topic_type = ' . TITANIA_SUPPORT . ' OR t.topic_type = ' . TITANIA_QUEUE_DISCUSSION . ')';
				}
				else
				{
					$sql_ary['WHERE'] .= ' AND t.topic_type = ' . TITANIA_SUPPORT;
				}

				// Additional tracking field (to allow marking all support/discussion as read)
				titania_tracking::get_track_sql($sql_ary, TITANIA_SUPPORT, $object->contrib_id, 'tst');
				$topic->additional_unread_fields[] = array('type' => TITANIA_SUPPORT, 'parent_match' => true);

				// Track the queue stuff too if applicable
				if (titania_types::$types[$object->contrib_type]->acl_get('queue_discussion'))
				{
					titania_tracking::get_track_sql($sql_ary, TITANIA_QUEUE_DISCUSSION, 0, 'tqt');
					$topic->additional_unread_fields[] = array('type' => TITANIA_QUEUE_DISCUSSION, 'id' => 0, 'type_match' => true);
				}
			break;
		}

		// Main SQL Query
		$sql = phpbb::$db->sql_build_query('SELECT', $sql_ary);

		// Handle pagination
		$pagination->sql_count($sql_ary, 't.topic_id');
		$pagination->build_pagination($page_url);

		$last_was_sticky = false;

		// Get the data
		$result = phpbb::$db->sql_query_limit($sql, $pagination->limit, $pagination->start);

		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			// Store the tracking info we grabbed from the DB
			titania_tracking::store_from_db($row);

			self::$topics[$row['topic_id']] = $row;

			$topic->__set_array($row);

			phpbb::$template->assign_block_vars('topics', array_merge($topic->assign_details(), array(
				'S_TOPIC_TYPE_SWITCH'		=> ($last_was_sticky && !$topic->topic_sticky) ? true : false,
			)));

			$last_was_sticky = $topic->topic_sticky;
		}
		phpbb::$db->sql_freeresult($result);

		unset($topic);
	}

	public static function assign_common()
	{
		phpbb::$template->assign_vars(array(
			'REPORTED_IMG'		=> phpbb::$user->img('icon_topic_reported', 'TOPIC_REPORTED'),
			'UNAPPROVED_IMG'	=> phpbb::$user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
			'NEWEST_POST_IMG'	=> phpbb::$user->img('icon_topic_newest', 'VIEW_NEWEST_POST'),
			'LAST_POST_IMG'		=> phpbb::$user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
		));
	}
}
