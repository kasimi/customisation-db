<?php
/**
*
* @package Titania
* @copyright (c) 2008 phpBB Group
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2
*
*/

/**
* @ignore
*/
if (!defined('IN_TITANIA'))
{
	exit;
}

class titania_types
{	
	/**
	* Store the types we've setup
	*
	* @var array(type_id => type_class)
	*/
	public static $types = array();

	/**
	* Load the types into the $types array
	*/
	public static function load_types()
	{
		$dh = @opendir(TITANIA_ROOT . 'includes/types/');

		if (!$dh)
		{
			trigger_error('Could not open the types directory');
		}

		while (($fname = readdir($dh)) !== false)
		{
			if (strpos($fname, '.' . PHP_EXT) && substr($fname, 0, 1) != '_' && $fname != 'base.' . PHP_EXT)
			{
				include(TITANIA_ROOT . 'includes/types/' . $fname);

				$class_name = 'titania_type_' . substr($fname, 0, strpos($fname, '.' . PHP_EXT));

				titania::add_lang('types/' . substr($fname, 0, strpos($fname, '.' . PHP_EXT)));

				$class = new $class_name;
				self::$types[$class->id] = $class;
			}
		}

		closedir($dh);

		ksort(self::$types);
	}

	/**
	* Get the type_id from the url string
	*
	* @param mixed $url
	*/
	public static function type_from_url($url)
	{
		foreach (self::$types as $type_id => $class)
		{
			if ($class->url == $url)
			{
				return $type_id;
			}
		}

		return false;
	}

	/**
	* Get the types this user is authorized to perform actions on
	*
	* @param mixed $type
	*/
	public static function find_authed($type = 'view')
	{
		$authed = array();

		foreach (self::$types as $type_id => $class)
		{
			if ($class->acl_get($type))
			{
				$authed[] = $type_id;
			}
		}

		return $authed;
	}

	/**
	* Get the types that do not require validation
	*/
	public static function find_validation_free()
	{
		$free = array();

		foreach (self::$types as $type_id => $class)
		{
			if (!$class->require_validation)
			{
				$free[] = $type_id;
			}
		}

		return $free;
	}
	
	/**
	* Get the types that require an upload
	*/
	public static function require_upload()
	{
		$free = array();

		foreach (self::$types as $type_id => $class)
		{
			if ($class->require_upload)
			{
				$strict[] = $type_id;
			}
		}
		
		return $strict;
	}

	/**
	* Get the types that use Composer
	*/
	public static function use_composer($negate = false)
	{
		$types = array();

		foreach (self::$types as $type_id => $class)
		{
			$include = ($negate) ? (!$class->create_composer_packages) : ($class->create_composer_packages);

			if ($include)
			{
				$types[] = $type_id;
			}
		}
		return $types;
	}
}

/**
* Base class for types
*/
class titania_type_base
{
	/**
	 * For the url slug
	 *
	 * @var string portion to be used in the URL slug
	 */
	public $url = 'contribution';

	/**
	 * The type name
	 *
	 * @var string (any lang key that includes the type should match this value)
	 */
	public $name = '';

	/**
	 * The language for this type, initialize in constructor ($langs is for the plural forms of the language variables, used in category management)
	 *
	 * @var string
	 */
	public $lang = '';
	public $langs = '';

	/**
	* Additional steps/functions to call when uploading
	*
	* @var array Ex: array('function_one', 'function_two')
	*	can use special keywords such as: array(array('contrib_type', 'function_one'), array('contrib_tools', 'function_two'))
	*	for calling the function in the contrib_type class or contrib_tools class respectively
	*/
	public $upload_steps = array();

	/**
	* Additional language keys (just the language key, not in the current user's language)
	*
	* @var string
	*/
	public $validation_subject = '';
	public $validation_message_approve = '';
	public $validation_message_deny = '';
	public $create_public = '';
	public $reply_public = '';
	public $update_public = '';
	public $upload_agreement = '';

	/**
	* Require validation/use queue for this type?
	* FALSE on either this or the require_validation config setting means validation is not required for the type
	*
	* @var bool
	*/
	public $require_validation = true;
	public $use_queue = true;

	/**
	* Can we upload extra files (on revisions) for this type?
	*
	* @var bool
	*/
	public $extra_upload = true;

	/**
	* Run MPV/Automod Test for this type?
	*
	* @var bool
	*/
	public $mpv_test = false;
	public $automod_test = false;

	/**
	 * Should the package be validated as a translation?
	 *
	 * @var bool
	 */
	public $validate_translation = false;

	/**
	 * Should the type require a file upload?
	 *
	 * @var bool
	 */
	public $require_upload = true;

	/**
	 * Create Composer packages for the type
	 *
	 * @var bool
	 */
	public $create_composer_packages = true;

	/**
	 * Allow revisions for a future release to be submitted
	 *
	 * @var bool
	 */
	public $prerelease_submission_allowed = false;

	/**
	* Find the root of the install package for this type?  If so, what to search for (see contrib_tools::find_root())?
	*
	* @var mixed
	*/
	public $clean_and_restore_root = false;
	public $root_search = false;
	public $root_not_found_key = 'COULD_NOT_FIND_ROOT';

	/**
	 * The forum_database and forum_robot, initialize in constructor
	 *
	 * @var int
	 */
	public $forum_database = 0;
	public $forum_robot = 0;

	/**
	* Array of available licenses for this type of contribution
	*/
	public $license_options = array();
	public $license_allow_custom = false;

	/** Custom revision fields */
	public $revision_fields = array();

	/** Custom contribution fields */
	public $contribution_fields = array();

	/**
	* Function that will be run when a revision of this type is uploaded
	*
	* @param $revision_attachment titania_attachment
	* @return array (error array, empty for no errors)
	*/
	public function upload_check($revision_attachment)
	{
		return array();
	}

	/**
	* Function to fix package name to ensure naming convention is followed
	*
	* @param $contrib Contribution object
	* @param $revision Revision object
	* @param $revision_attachment Attachment object
	* @param $root_dir Package root directory
	*
	* @return New root dir name
	*/	
	public function fix_package_name($contrib, $revision, $revision_attachment, $root_dir = false)
	{
		return false;
	}

	/**
	* Validate contribution fields.
	*
	* @return array Returns array containing any errors found.
	*/
	public function validate_contrib_fields($fields)
	{
		return array();
	}

	/**
	* Validate revision fields.
	*
	* @return array Returns array containing any errors found.
	*/
	public function validate_revision_fields($fields)
	{
		return array();
	}
}