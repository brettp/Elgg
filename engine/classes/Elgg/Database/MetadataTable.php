<?php
namespace Elgg\Database;


use Elgg\Database;
use Elgg\Database\EntityTable;
use Elgg\EventsService as Events;
use ElggSession as Session;
use Elgg\Cache\MetadataCache as Cache;

/**
 * WARNING: API IN FLUX. DO NOT USE DIRECTLY.
 *
 * @access private
 *
 * @package    Elgg.Core
 * @subpackage Database
 * @since      1.10.0
 */
class MetadataTable extends ExtenderTable {

	use \Elgg\TimeUsing;

	/** @var array */
	protected $independents = array();

	/** @var Cache */
	protected $cache;
	
	/** @var Database */
	protected $db;
	
	/** @var EntityTable */
	protected $entityTable;
	
	/** @var Events */
	protected $events;
	
	/** @var Session */
	protected $session;
	
	/** @var string */
	protected $table;

	protected $extenderType = "metadata";

	/**
	 * Constructor
	 * 
	 * @param Cache            $cache            A cache for this table
	 * @param Database         $db               The Elgg database
	 * @param EntityTable      $entityTable      The entities table
	 * @param Events           $events           The events registry
	 * @param Session          $session          The session
	 */
	public function __construct(
			Cache $cache,
			Database $db,
			EntityTable $entityTable,
			Events $events,
			Session $session) {
		$this->cache = $cache;
		$this->db = $db;
		$this->entityTable = $entityTable;
		$this->events = $events;
		$this->session = $session;
		$this->table = $this->db->prefix . "metadata";
	}

	/**
	 * Get a specific metadata object by its id.
	 * If you want multiple metadata objects, use
	 * {@link elgg_get_metadata()}.
	 *
	 * @param int $id The id of the metadata object being retrieved.
	 *
	 * @return \ElggMetadata|false  false if not found
	 */
	function get($id) {
		return _elgg_get_metastring_based_object_from_id($id, 'metadata');
	}
	
	/**
	 * Deletes metadata using its ID.
	 *
	 * @param int $id The metadata ID to delete.
	 * @return bool
	 */
	function delete($id) {
		$metadata = $this->get($id);

		return $metadata ? $metadata->delete() : false;
	}
	
	/**
	 * Create a new metadata object, or update an existing one.
	 *
	 * Metadata can be an array by setting allow_multiple to true, but it is an
	 * indexed array with no control over the indexing.
	 *
	 * @param int    $entity_guid    The entity to attach the metadata to
	 * @param string $name           Name of the metadata
	 * @param string $value          Value of the metadata
	 * @param string $value_type     'text', 'integer', or '' for automatic detection
	 * @param int    $owner_guid     GUID of entity that owns the metadata. Default is logged in user.
	 * @param int    $access_id      Default is ACCESS_PRIVATE
	 * @param bool   $allow_multiple Allow multiple values for one key. Default is false
	 *
	 * @return int|false id of metadata or false if failure
	 */
	function create($entity_guid, $name, $value, $value_type = '', $owner_guid = 0, $access_id = ACCESS_PRIVATE, $allow_multiple = false) {

		$entity_guid = (int)$entity_guid;
		// name and value are encoded in add_metastring()
		$value_type = detect_extender_valuetype($value, $this->db->sanitizeString(trim($value_type)));
		$time = $this->getCurrentTime()->getTimestamp();
		$owner_guid = (int)$owner_guid;
		$allow_multiple = (boolean)$allow_multiple;
	
		if (!isset($value)) {
			return false;
		}
	
		if ($owner_guid == 0) {
			$owner_guid = $this->session->getLoggedInUserGuid();
		}
	
		$access_id = (int) $access_id;
	
		$query = "SELECT * FROM {$this->table}
			WHERE entity_guid = :entity_guid and name_id = :name_id LIMIT 1";

		$params = [
			':entity_guid' => $entity_guid,
			':name' => $name
		];

		$existing = $this->db->getDataRow($query, null, $params);
		if ($existing && !$allow_multiple) {
			$id = (int)$existing->id;
			$result = $this->update($id, $name, $value, $value_type, $owner_guid, $access_id);
	
			if (!$result) {
				return false;
			}
		} else {
			// Support boolean types
			if (is_bool($value)) {
				$value = (int)$value;
			}
	
			// If ok then add it
			$query = "INSERT INTO {$this->table}
				(entity_guid, name, value, value_type, owner_guid, time_created, access_id)
				VALUES (:entity_guid, :name, :value, :value_type, :owner_guid, :time_created, :access_id)";

			$params = [
				':entity_guid' => $entity_guid,
				':name' => $name,
				':value' => $value,
				':value_type' => $value_type,
				':owner_guid' => $owner_guid,
				':time_created' => $time,
				':access_id' => $access_id,
			];
			
			$id = $this->db->insertData($query, $params);
			
			if ($id !== false) {
				$obj = $this->get($id);
				if ($this->events->trigger('create', 'metadata', $obj)) {

					$this->cache->clear($entity_guid);
	
					return $id;
				} else {
					$this->delete($id);
				}
			}
		}
	
		return $id;
	}
	
	/**
	 * Update a specific piece of metadata.
	 *
	 * @param int    $id         ID of the metadata to update
	 * @param string $name       Metadata name
	 * @param string $value      Metadata value
	 * @param string $value_type Value type
	 * @param int    $owner_guid Owner guid
	 * @param int    $access_id  Access ID
	 *
	 * @return bool
	 */
	function update($id, $name, $value, $value_type, $owner_guid, $access_id) {
		$id = (int)$id;
	
		if (!$md = $this->get($id)) {
			return false;
		}
		if (!$md->canEdit()) {
			return false;
		}
	
		$value_type = detect_extender_valuetype($value, $this->db->sanitizeString(trim($value_type)));
	
		$owner_guid = (int)$owner_guid;
		if ($owner_guid == 0) {
			$owner_guid = $this->session->getLoggedInUserGuid();
		}
	
		$access_id = (int)$access_id;
	
		// Support boolean types (as integers)
		if (is_bool($value)) {
			$value = (int)$value;
		}

		// If ok then add it
		$query = "UPDATE {$this->table}
			SET name = :name,
			    value = :value,
				value_type = :value_type,
				access_id = :access_id,
			    owner_guid = :owner_guid
			WHERE id = :id";

		$params = [
			':name' => $name,
			':value' => $value,
			':value_type' => $value_type,
			':access_id' => $access_id,
			':owner_guid' => $owner_guid,
			':id' => $id,
		];
		
		$result = $this->db->updateData($query, false, $params);
		
		if ($result !== false) {
	
			$this->cache->clear($md->entity_guid);
	
			// @todo this event tells you the metadata has been updated, but does not
			// let you do anything about it. What is needed is a plugin hook before
			// the update that passes old and new values.
			$obj = $this->get($id);
			$this->events->trigger('update', 'metadata', $obj);
		}
	
		return $result;
	}
	
	/**
	 * This function creates metadata from an associative array of "key => value" pairs.
	 *
	 * To achieve an array for a single key, pass in the same key multiple times with
	 * allow_multiple set to true. This creates an indexed array. It does not support
	 * associative arrays and there is no guarantee on the ordering in the array.
	 *
	 * @param int    $entity_guid     The entity to attach the metadata to
	 * @param array  $name_and_values Associative array - a value can be a string, number, bool
	 * @param string $value_type      'text', 'integer', or '' for automatic detection
	 * @param int    $owner_guid      GUID of entity that owns the metadata
	 * @param int    $access_id       Default is ACCESS_PRIVATE
	 * @param bool   $allow_multiple  Allow multiple values for one key. Default is false
	 *
	 * @return bool
	 */
	function createFromArray($entity_guid, array $name_and_values, $value_type, $owner_guid,
			$access_id = ACCESS_PRIVATE, $allow_multiple = false) {
	
		foreach ($name_and_values as $k => $v) {
			$result = $this->create($entity_guid, $k, $v, $value_type, $owner_guid,
				$access_id, $allow_multiple);
			if (!$result) {
				return false;
			}
		}
		return true;
	}
	
	/**
	 * Returns metadata.  Accepts all elgg_get_entities() options for entity
	 * restraints.
	 *
	 * @see elgg_get_entities
	 *
	 * @warning 1.7's find_metadata() didn't support limits and returned all metadata.
	 *          This function defaults to a limit of 25. There is probably not a reason
	 *          for you to return all metadata unless you're exporting an entity,
	 *          have other restraints in place, or are doing something horribly
	 *          wrong in your code.
	 *
	 * @param array $options Array in format:
	 *
	 * metadata_names               => null|ARR metadata names
	 * metadata_values              => null|ARR metadata values
	 * metadata_ids                 => null|ARR metadata ids
	 * metadata_case_sensitive      => BOOL Overall Case sensitive
	 * metadata_owner_guids         => null|ARR guids for metadata owners
	 * metadata_created_time_lower  => INT Lower limit for created time.
	 * metadata_created_time_upper  => INT Upper limit for created time.
	 * metadata_calculation         => STR Perform the MySQL function on the metadata values returned.
	 *                                   The "metadata_calculation" option causes this function to
	 *                                   return the result of performing a mathematical calculation on
	 *                                   all metadata that match the query instead of returning
	 *                                   \ElggMetadata objects.
	 *
	 * @return \ElggMetadata[]|mixed
	 */
	function getAll(array $options = array()) {
	
		// @todo remove support for count shortcut - see #4393
		// support shortcut of 'count' => true for 'metadata_calculation' => 'count'
		if (isset($options['count']) && $options['count']) {
			$options['metadata_calculation'] = 'count';
			unset($options['count']);
		}
	
		$options['metastring_type'] = 'metadata';
		return self::getObjects($options);
	}
	
	/**
	 * Deletes metadata based on $options.
	 *
	 * @warning Unlike elgg_get_metadata() this will not accept an empty options array!
	 *          This requires at least one constraint: metadata_owner_guid(s),
	 *          metadata_name(s), metadata_value(s), or guid(s) must be set.
	 *
	 * @param array $options An options array. {@link elgg_get_metadata()}
	 * @return bool|null true on success, false on failure, null if no metadata to delete.
	 */
	function deleteAll(array $options) {
		if (!_elgg_is_valid_options_for_batch_operation($options, 'metadata')) {
			return false;
		}
		$options['metastring_type'] = 'metadata';
		$result = _elgg_batch_metastring_based_objects($options, 'elgg_batch_delete_callback', false);
	
		// This moved last in case an object's constructor sets metadata. Currently the batch
		// delete process has to create the entity to delete its metadata. See #5214
		$this->cache->invalidateByOptions($options);
	
		return $result;
	}
	
	/**
	 * Disables metadata based on $options.
	 *
	 * @warning Unlike elgg_get_metadata() this will not accept an empty options array!
	 *
	 * @param array $options An options array. {@link elgg_get_metadata()}
	 * @return bool|null true on success, false on failure, null if no metadata disabled.
	 */
	function disableAll(array $options) {
		if (!_elgg_is_valid_options_for_batch_operation($options, 'metadata')) {
			return false;
		}
	
		$this->cache->invalidateByOptions($options);
	
		// if we can see hidden (disabled) we need to use the offset
		// otherwise we risk an infinite loop if there are more than 50
		$inc_offset = access_get_show_hidden_status();
	
		$options['metastring_type'] = 'metadata';
		return _elgg_batch_metastring_based_objects($options, 'elgg_batch_disable_callback', $inc_offset);
	}
	
	/**
	 * Enables metadata based on $options.
	 *
	 * @warning Unlike elgg_get_metadata() this will not accept an empty options array!
	 *
	 * @warning In order to enable metadata, you must first use
	 * {@link access_show_hidden_entities()}.
	 *
	 * @param array $options An options array. {@link elgg_get_metadata()}
	 * @return bool|null true on success, false on failure, null if no metadata enabled.
	 */
	function enableAll(array $options) {
		if (!$options || !is_array($options)) {
			return false;
		}
	
		$this->cache->invalidateByOptions($options);
	
		$options['metastring_type'] = 'metadata';
		return _elgg_batch_metastring_based_objects($options, 'elgg_batch_enable_callback');
	}
	
	/**
	 * Returns entities based upon metadata.  Also accepts all
	 * options available to elgg_get_entities().  Supports
	 * the singular option shortcut.
	 *
	 * @note Using metadata_names and metadata_values results in a
	 * "names IN (...) AND values IN (...)" clause.  This is subtly
	 * differently than default multiple metadata_name_value_pairs, which use
	 * "(name = value) AND (name = value)" clauses.
	 *
	 * When in doubt, use name_value_pairs.
	 *
	 * To ask for entities that do not have a metadata value, use a custom
	 * where clause like this:
	 *
	 * 	$options['wheres'][] = "NOT EXISTS (
	 *			SELECT 1 FROM {$dbprefix}metadata md
	 *			WHERE md.entity_guid = e.guid
	 *				AND md.name_id = $name_metastring_id
	 *				AND md.value_id = $value_metastring_id)";
	 *
	 * Note the metadata name and value has been denormalized in the above example.
	 *
	 * @see elgg_get_entities
	 *
	 * @param array $options Array in format:
	 *
	 * 	metadata_names => null|ARR metadata names
	 *
	 * 	metadata_values => null|ARR metadata values
	 *
	 * 	metadata_name_value_pairs => null|ARR (
	 *                                         name => 'name',
	 *                                         value => 'value',
	 *                                         'comparison' => '=',
	 *                                         'case_sensitive' => true
	 *                                        )
	 *                               Currently if multiple values are sent via
	 *                               an array (value => array('value1', 'value2')
	 *                               the pair's comparison will be forced to "IN".
	 *                               If passing "IN" as the comparison and a string as the value,
	 *                               the value must be a properly quoted and escaped string.
	 *
	 * 	metadata_name_value_pairs_operator => null|STR The operator to use for combining
	 *                                        (name = value) OPERATOR (name = value); default AND
	 *
	 * 	metadata_case_sensitive => BOOL Overall Case sensitive
	 *
	 *  order_by_metadata => null|ARR array(
	 *                                      'name' => 'metadata_text1',
	 *                                      'direction' => ASC|DESC,
	 *                                      'as' => text|integer
	 *                                     )
	 *                                Also supports array('name' => 'metadata_text1')
	 *
	 *  metadata_owner_guids => null|ARR guids for metadata owners
	 *
	 * @return \ElggEntity[]|mixed If count, int. If not count, array. false on errors.
	 */
	function getEntities(array $options = array()) {
		$defaults = array(
			'metadata_names'                     => ELGG_ENTITIES_ANY_VALUE,
			'metadata_values'                    => ELGG_ENTITIES_ANY_VALUE,
			'metadata_name_value_pairs'          => ELGG_ENTITIES_ANY_VALUE,
	
			'metadata_name_value_pairs_operator' => 'AND',
			'metadata_case_sensitive'            => true,
			'order_by_metadata'                  => array(),
	
			'metadata_owner_guids'               => ELGG_ENTITIES_ANY_VALUE,
		);
	
		$options = array_merge($defaults, $options);
	
		$singulars = array('metadata_name', 'metadata_value',
			'metadata_name_value_pair', 'metadata_owner_guid');
	
		$options = $this->getOptions(_elgg_normalize_plural_options_array($options, $singulars));

		if (!$options) {
			return false;
		}
	
		return $this->entityTable->getEntities($options);
	}

	/**
	 * Get the URL for this metadata
	 *
	 * By default this links to the export handler in the current view.
	 *
	 * @param int $id Metadata ID
	 *
	 * @return mixed
	 */
	function getUrl($id) {
		$extender = $this->get($id);

		return $extender ? $extender->getURL() : false;
	}

	/**
	 * Mark entities with a particular type and subtype as having access permissions
	 * that can be changed independently from their parent entity
	 *
	 * @param string $type    The type - object, user, etc
	 * @param string $subtype The subtype; all subtypes by default
	 *
	 * @return void
	 */
	function registerMetadataAsIndependent($type, $subtype = '*') {
		if (!isset($this->independents[$type])) {
			$this->independents[$type] = array();
		}
		
		$this->independents[$type][$subtype] = true;
	}
	
	/**
	 * Determines whether entities of a given type and subtype should not change
	 * their metadata in line with their parent entity
	 *
	 * @param string $type    The type - object, user, etc
	 * @param string $subtype The entity subtype
	 *
	 * @return bool
	 */
	function isMetadataIndependent($type, $subtype) {
		if (empty($this->independents[$type])) {
			return false;
		}

		return !empty($this->independents[$type][$subtype])
			|| !empty($this->independents[$type]['*']);
	}
	
	/**
	 * When an entity is updated, resets the access ID on all of its child metadata
	 *
	 * @param string      $event       The name of the event
	 * @param string      $object_type The type of object
	 * @param \ElggEntity $object      The entity itself
	 *
	 * @return true
	 * @access private Set as private in 1.9.0
	 */
	function handleUpdate($event, $object_type, $object) {
		if ($object instanceof \ElggEntity) {
			if (!$this->isMetadataIndependent($object->getType(), $object->getSubtype())) {
				$access_id = (int)$object->access_id;
				$guid = (int)$object->getGUID();
				$query = "update {$this->table} set access_id = {$access_id} where entity_guid = {$guid}";
				$this->db->updateData($query);
			}
		}
		return true;
	}

}
