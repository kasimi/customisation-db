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

if (!class_exists('titania_database_object'))
{
	require TITANIA_ROOT . 'includes/core/object_database.' . PHP_EXT;
}

/**
* Class to abstract titania ratings.
* @package Titania
*/
class titania_rating extends titania_database_object
{
	/**
	 * SQL Table
	 *
	 * @var string
	 */
	protected $sql_table			= TITANIA_RATINGS_TABLE;

	/**
	 * SQL identifier field
	 *
	 * @var string
	 */
	protected $sql_id_field			= 'rating_id';

	/**
	 * Rating Type
	 * Check Rating Type Constants
	 *
	 * @var int
	 */
	protected $rating_type_id		= 0;

	/**
	 * Cache Table
	 * Rating table where the cache will be stored
	 *
	 * @var string
	 */
	protected $cache_table			= '';

	/**
	 * Cache Rating
	 * The field to update with the rating value cache
	 *
	 * @var string
	 */
	protected $cache_rating			= '';

	/**
	 * Cache Rating Count
	 * The field to update with the rating count cache
	 *
	 * @var string
	 */
	protected $cache_rating_count	= '';

	/**
	 * Object column
	 * The rating item primary key field (ex: user_id for rating authors)
	 *
	 * @var string
	 */
	protected $object_column		= '';

	/**
	 * Object ID
	 * The rating item ID (ex: user_id for rating authors)
	 *
	 * @var int
	 */
	protected $rating_object_id			= 0;

	/**
	 * Rating
	 * The rating of the item
	 *
	 * @var decimal
	 */
	protected $rating				= 0;

	/**
	 * Rating Count
	 * The number of ratings for the item
	 *
	 * @var int
	 */
	protected $rating_count			= 0;

	/**
	 * Rating Type
	 * The rating type
	 *
	 * @var string
	 */
	protected $rating_type			= 0;

	/**
	 * Constructor class for titania authors
	 *
	 * @param string $type The type of rating ('author', 'contrib')
	 * @param object $object The object we will be rating (author/contrib object)
	 */
	public function __construct($type = '', $object = false)
	{
		// Configure object properties
		$this->object_config = array_merge($this->object_config, array(
			'rating_id'				=> array('default' => 0),
			'rating_type_id'		=> array('default' => 0),
			'rating_user_id'		=> array('default' => phpbb::$user->data['user_id']),
			'rating_object_id'		=> array('default' => 0),
			'rating_value'			=> array('default' => 0.0),
		));

		$this->rating_type	= ($type) ? $type : request_var('type', '');
		$this->set_rating_type($this->rating_type);

		if ($object)
		{
			$this->set_rating_object($object);
		}
	}

	/**
	 * Set the rating object params
	 *
	 * @param object $object
	 */
	public function set_rating_object($object)
	{
		// Get the rating, rating count, and item id
		$this->rating = $object->{$this->cache_rating};
		$this->rating_count = $object->{$this->cache_rating_count};
		$this->rating_object_id = $object->{$this->object_column};
	}

	/**
	* Get the current user's rating
	*/
	public function load()
	{
		if (!phpbb::$user->data['is_registered'] || phpbb::$user->data['is_bot'])
		{
			return;
		}

		$sql = 'SELECT * FROM ' . $this->sql_table . '
			WHERE rating_type_id = ' . (int) $this->rating_type_id . '
				AND rating_user_id = ' . (int) phpbb::$user->data['user_id'] . '
				AND rating_object_id = ' . (int) $this->rating_object_id;
		$result = phpbb::$db->sql_query($sql);
		$this->sql_data = phpbb::$db->sql_fetchrow($result);
		phpbb::$db->sql_freeresult($result);

		if ($this->sql_data)
		{
			foreach ($this->sql_data as $key => $value)
			{
				$this->$key = $value;
			}
		}
	}

	/**
	 * Set the rating type properties
	 *
	 * @param string $type Rating type (author|contrib)
	 */
	public function set_rating_type($type)
	{
		switch ($type)
		{
			case 'author':
				$this->rating_type_id = TITANIA_RATING_AUTHOR;
				$this->cache_table = TITANIA_AUTHORS_TABLE;
				$this->cache_rating = 'author_rating';
				$this->cache_rating_count = 'author_rating_count';
				$this->object_column = 'user_id';
			break;

			case 'contrib':
				$this->rating_type_id = TITANIA_RATING_CONTRIB;
				$this->cache_table = TITANIA_CONTRIBS_TABLE;
				$this->cache_rating = 'contrib_rating';
				$this->cache_rating_count = 'contrib_rating_count';
				$this->object_column = 'contrib_id';
			break;

			default:
				trigger_error('BAD_RATING');
			break;
		}
	}

	/**
	 * Rate an Author
	 *
	 * @param int $id
	 *
	 * @return Return the redirect URL
	 */
	public function set_rate_author($id)
	{
		titania::load_object('author');
		$object = new titania_author();
		$object->load($id);

		if (!$object)
		{
			trigger_error('AUTHOR_NOT_FOUND');
		}

		$this->set_rating_object($object);

		return $object->get_url();
	}

	/**
	 * Rate a contrib
	 *
	 * @param int $id
	 *
	 * @return Return the redirect URL
	 */
	public function set_rate_contrib($id, $object = false)
	{
		titania::load_object('contribution');
		$object = new titania_contribution();
		$object->load($id);

		if (!$object)
		{
			trigger_error('CONTRIB_NOT_FOUND');
		}

		$this->set_rating_object($object);

		return $object->get_url();
	}

	/**
	 * Determine the rating type and setup the objects for rating.
	 * This is mostly a wrapper method.
	 *
	 * @param mixed $id Contrib or Author ID being rated.
	 */
	public function determine_rating($id = false)
	{
		$id		= ($id !== false) ? $id : request('id', 0);

		switch ($this->rating_type)
		{
			case 'author':
				$redirect = $this->set_rate_author($id);
			break;

			case 'contrib':
				$redirect = $this->set_rate_contrib($id);
			break;
		}

		$this->load();
		$this->set_rating();
	}

	/**
	 * Set/Run the rating value
	 *
	 * @param mixed $value
	 */
	public function set_rating($value = false)
	{
		$value	= ($value !== false) ? $value : request_var('value', -1.0);

		// Add or remove rating depending on value
		$result = ($value == -1) ? $this->delete_rating() : $this->add_rating($value);
		if ($result)
		{
			// Redirect to the specified URL by the rating object
			redirect($redirect);
		}
		else
		{
			trigger_error('BAD_RATING');
		}
	}

	/**
	* Get rating string
	*
	* @return string The rating string ready for output
	*/
	public function get_rating_string()
	{
		$can_rate = (phpbb::$user->data['is_registered'] && phpbb::$auth->acl_get('titania_rate') && !$this->rating_id) ? true : false;
		$rate_url = titania::$url->build_url('rate', array('type' => $this->rating_type, 'id' => $this->rating_object_id));

		// If it has not had any ratings yet, give it 1/2 the max for the rating
		if ($this->rating_count == 0)
		{
			$this->rating = round(titania::$config->max_rating / 2, 1);
		}

		phpbb::$template->set_filenames(array(
			'rate'	=> 'misc/rate.html',
		));

		phpbb::$template->assign_vars(array(
			'OBJECT_ID'				=> $this->rating_object_id,
			'OBJECT_RATING'			=> round($this->rating),

			'RATE_URL'				=> $rate_url,

			'S_HAS_RATED'			=> ($this->rating_id) ? true : false,

			'UA_GREY_STAR_SRC'		=> titania::$theme_path . '/images/star_grey.gif',
			'UA_GREEN_STAR_SRC'		=> titania::$theme_path . '/images/star_green.gif',
			'UA_RED_STAR_SRC'		=> titania::$theme_path . '/images/star_red.gif',
			'UA_ORANGE_STAR_SRC'	=> titania::$theme_path . '/images/star_orange.gif',
			'UA_REMOVE_STAR_SRC'	=> titania::$theme_path . '/images/star_remove.gif',
			'UA_MAX_RATING'			=> titania::$config->max_rating,
		));

		// reset the stars block
		phpbb::$template->destroy_block_vars('stars');

		for ($i = 1; $i <= titania::$config->max_rating; $i++)
		{
			phpbb::$template->assign_block_vars('stars', array(
				'ALT'		=> (($this->rating_value) ? $this->rating_value : $i) . '/' . titania::$config->max_rating,
				'ID'		=> $i,
				'RATE_URL'	=> titania::$url->append_url($rate_url, array('value' => $i)),
			));
		}

		return phpbb::$template->assign_display('rate', '', true);
	}

	/**
	* Add a Rating for an item
	*
	* @param mixed $rating The rating
	*/
	public function add_rating($rating)
	{
		if (!phpbb::$user->data['is_registered'] || !phpbb::$auth->acl_get('titania_rate'))
		{
			return false;
		}

		if ($rating < 0 || $rating > titania::$config->max_rating || $this->rating_id)
		{
			return false;
		}

		$this->rating_value = $rating;

		parent::submit();

		// This is accurate enough as long as we have at least 2 decimal places
		$sql = "UPDATE {$this->cache_table} SET
			{$this->cache_rating} = ({$this->cache_rating} * {$this->rating_count} + {$this->rating_value}) / ({$this->rating_count} + 1),
			{$this->cache_rating_count} = {$this->cache_rating_count} + 1
			WHERE {$this->object_column} = {$this->rating_object_id}";
		phpbb::$db->sql_query($sql);

		return true;
	}

	/**
	* Delete the user's own rating
	*/
	public function delete_rating()
	{
		if (!phpbb::$user->data['is_registered'] || !$this->rating_id)
		{
			return false;
		}

		parent::delete();

		if ($this->rating_count == 1)
		{
			$sql_ary = array(
				$this->cache_rating			=> 0,
				$this->cache_rating_count	=> 0,
			);

			$sql = 'UPDATE ' . $this->cache_table . ' SET ' . phpbb::$db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE ' . $this->object_column . ' = ' . $this->rating_object_id;
			phpbb::$db->sql_query($sql);
		}
		else
		{
			// This is accurate enough as long as we have at least 2 decimal places
			$sql = "UPDATE {$this->cache_table} SET
				{$this->cache_rating} = ({$this->cache_rating} * {$this->rating_count} - {$this->rating_value}) / ({$this->rating_count} - 1),
				{$this->cache_rating_count} = {$this->cache_rating_count} - 1
				WHERE {$this->object_column} = {$this->rating_object_id}";
			phpbb::$db->sql_query($sql);
		}

		return true;
	}

	/**
	* Resync the cache table
	*/
	public function resync()
	{
		$cnt = $total = 0;
		$sql = 'SELECT rating_value FROM ' . $this->sql_table . '
			WHERE rating_type_id = ' . (int) $this->rating_type_id . '
				AND rating_object_id = ' . (int) $this->rating_object_id;
		$result = phpbb::$db->sql_query($sql);
		while ($row = phpbb::$db->sql_fetchrow($result))
		{
			$cnt++;
			$total += $row['rating_value'];
		}
		phpbb::$db->sql_freeresult($result);

		$sql_ary = array(
			$this->cache_rating			=> ($cnt > 0) ? round($total / $cnt, 2) : 0,
			$this->cache_rating_count	=> $cnt,
		);

		$sql = 'UPDATE ' . $this->cache_table . ' SET ' . phpbb::$db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE ' . $this->object_column . ' = ' . $this->rating_object_id;
		phpbb::$db->sql_query($sql);
	}

	/**
	* Reset the rating for this object
	*/
	public function reset_rating()
	{
		if (!phpbb::$auth->acl_get('titania_rate_reset'))
		{
			return false;
		}

		$sql = 'DELETE FROM ' . $this->sql_table . '
			WHERE rating_type_id = ' . (int) $this->rating_type_id . '
				AND rating_object_id = ' . (int) $this->rating_object_id;
		phpbb::$db->sql_query($sql);


		$sql_ary = array(
			$this->cache_rating			=> 0,
			$this->cache_rating_count	=> 0,
		);

		$sql = 'UPDATE ' . $this->cache_table . ' SET ' . phpbb::$db->sql_build_array('UPDATE', $sql_ary) . '
			WHERE ' . $this->object_column . ' = ' . $this->rating_object_id;
		phpbb::$db->sql_query($sql);

		return true;
	}
}