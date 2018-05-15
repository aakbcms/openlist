<?php
/**
 * OpenList API Class
 */

/**
 * OpenList API class
 *
 * ## About the OpenList service
 * OpenList is a general purpose micro storage for cross site sharing of user data
 * for danish library services.
 *
 * ## List types
 * Currently OpenList supports the following predefined *list types* per user (owner):
 * - follow_author : Followed authors (CQL)
 * - user_searches : Followed search strings (CQL)
 * - lists_list : (OpenList List ID)
 * - ratings : Rated materials (TingMaterial ID)
 * - user_loan_history : Loan history (TingMaterial ID)
 * - remember: Read later materials (TingMaterial ID)
 * - books_read : Read materials (TingMaterial ID)
 * - user_list : Personal material list (TingMaterial ID)
 *
 * Clients can define custom list types
 *
 * ## Identification of users
 *
 * Users are identified in OpenList by the *owner*-id which should be a sha256 hash of a local id salted
 * by a local prefix.
 *
 * Example (PHP).
 * ```php
 * hash('sha512', $local_prefix . $local_id);
 * ```
 * In ding2 the openlist module (https://github.com/ding2/ding2/tree/master/modules/p2/ting_openlist)
 * implements a PHP client to the OpenList service and syncronizes with local Drupal entities.
 * From the local unique Drupal user name the OpenList identifier (owner) is created via prefix salting:
 * https://github.com/ding2/ding2/blob/master/modules/p2/ting_openlist/ting_openlist.module#L466
 *
 * Do not use CPR, Drupal uid, Cicero/Alma Loaner Id or similar local id's for user identification.
 *
 * ## Testing the service
 *
 * The API functions can be tested in the OpenList test client: http://test.openlist.ddbcms.dk/tools/client/
 *
 */
class OpenList {
  /**
   * The span between weights.
   * @ignore
   */
  const WEIGHT_SPAN = 32;

  /**
   * Singleton instance during full request.
   * @ignore
   */
  public static $instance = NULL;

  /**
   * Constructor.
   *
   * @ignore
   *
   */
  public function __construct() {
    self::$instance = $this;
  }

  /**
   * A simple check if all arguments exists or throw an error function.
   *
   * @param array $args
   *   An associative array with the argument name => argument value.
   */
  private static function errorCheckArguments($args) {
    $i = 0;
    foreach ($args as $title => $arg) {
      $i++;
      if (empty($arg)) {
        self::error('The ' . $title . ' argument (argument ' . $i . ') is required.');
      }
      // Require strong
      if($title == 'owner' && strlen($arg) < 50) {
        self::error('The owner-id must be longer than 50 characters.');
      }
    }
  }

  /**
   * Triggers an E_USER_ERROR, with the error message.
   *
   * The SoapServer will automatically catch this error and pass it on to the
   * client.
   *
   * @param string $msg
   *   The custom message given to the client.
   * @param bool $log_it
   *   If TRUE the error message and the last database query will be saved
   *   to the errorlog table.
   */
  private static function error($msg = '', $log_it = FALSE) {
    EventHandler::trigger(__FUNCTION__, array($msg, $log_it));

    if ($msg === '') {
      $msg = 'Unknown error.';
    }

    if ($log_it) {
      // When logging it we take the last sql send by the DB, and log this
      // along with the custom error message.
      $result = DB::q('
INSERT INTO errorlog
(message, data, type)
VALUES ("@msg", "@sql", "openlist")
      ', array(
        '@msg' => $msg,
        '@sql' => serialize(DB::getHistory()),
      ));

      // The user will get the id of the error in our error log. This way we
      // can use this issue_id as a reference to the error when debugging
      // someones error.
      $msg = '[issue_id: ' . DB::insert_id() . '] ' . $msg;
    }

    trigger_error($msg, E_USER_ERROR);
  }

  /**
   * Set the default value to a variable.
   *
   * @param mixed $variable
   *   The variable.
   * @param mixed $value
   *   The default value.
   */
  private static function setDefault(&$variable, $value) {
    if ($variable === NULL) {
      $variable = $value;
    }
  }

  /**
   * Update the user_provider table.
   *
   * @param string $owner
   *   The user.
   */
  public static function updateUserProvider($owner) {
    DB::q('
INSERT IGNORE INTO user_provider
(owner, library_code)
VALUES ("@owner", "@library_code")
    ', array(
      '@owner' => $owner,
      '@library_code' => $GLOBALS['library_code'],
    ));
  }

  /**
   * Call a public module method.
   *
   * Modules are plugins that add sematics to the incoming lists and elements
   * Currently implemented modules:
   * @see TingObjectRating
   * @see LoanHistory
   *
   * @param string $module_name
   *   The exact name of the module (case sensitive).
   * @param string $method
   *   The method name.
   * @param array $args
   *   The arguments to pass on through to the module.
   *
   * @return mixed
   *   Returns the result from the module method.
   */
  public function callModule($module_name, $method, $args = array()) {
    $module = Module::getModule($module_name);

    if ($module !== FALSE) {
      if (method_exists($module, $method)) {
        self::setDefault($args, array());
        try {
          return call_user_func_array(array($module, $method), $args);
        }
        catch (Exception $e) {
          self::error($e->getMessage(), TRUE);
        }
      }

      return self::error("Unknown function");
    }

    return self::error("Module doesn't exist");
  }

  /**
   * Create an element, and attach it to a list.
   *
   * @param int $element_id
   *   The element id.
   * @param mixed $data
   *   The data you wish to save (this can be anything, it's serialized before
   *   saved to the database).
   *
   * @return bool
   *   Result.
   */
  public function editElement($element_id, $data) {
    self::errorCheckArguments(array(
      'element_id' => $element_id,
    ));

    $result = DB::q('
UPDATE elements
SET data = "@data", modified = UNIX_TIMESTAMP(), library_code = "@library_code"
WHERE
  element_id = %element_id
  AND library_code IN (?$library_access)
    ', array(
      '%element_id' => $element_id,
      '@data' => serialize($data),
      '@library_code' => $GLOBALS['library_code'],
      '?$library_access' => $GLOBALS['library_access'],
    ));

    if ($result) {
      EventHandler::trigger(__FUNCTION__, array($element_id, $data));
      return $result;
    }

    self::error('', TRUE);
  }

  /**
   * Create an element, and attach it to a list.
   *
   * @param int $list_id
   *   The list id.
   * @param string $title
   *   The new title.
   * @param mixed $data
   *   Data to save about the list.
   *
   * @return bool
   *   Update result.
   */
  public function editList($list_id, $title, $data = NULL) {
    self::errorCheckArguments(array(
      'list_id' => $list_id,
    ));

    if ($data !== NULL) {
      $extra_setter = ', data ="@data"';
    }

    $result = DB::q('
UPDATE lists
SET title = "@title", library_code = "@library_code", modified = UNIX_TIMESTAMP()' . $extra_setter . '
WHERE list_id = %list_id
    ', array(
      '%list_id' => $list_id,
      '@title' => $title,
      '@data' => serialize($data),
      '@library_code' => $GLOBALS['library_code'],
    ));

    if ($result) {
      EventHandler::trigger(__FUNCTION__, array($list_id, $title, $data));
      return $result;
    }

    self::error('', TRUE);
  }

  /**
   * Create a new list.
   *
   * @param string $owner
   *   The id of the new list owner.
   * @param string $title
   *   Title given to the new list.
   * @param string $type
   *   The type of the list. See predefined list types above.
   * @param mixed $data
   *   Data to save about the list.
   *
   * @return mixed
   *   The new list_id.
   */
  public function createList($owner, $title, $type = '', $data = '') {
    self::setDefault($type, '');
    self::setDefault($data, '');

    self::errorCheckArguments(array(
      'owner' => $owner,
      'title' => $title,
    ));

    self::updateUserProvider($owner);

    $result = DB::q('
INSERT INTO lists
(owner, title, type, modified, data, library_code)
VALUES ("@owner", "@title", "@type", UNIX_TIMESTAMP(), "@data", "@library_code")
    ', array(
      '@owner' => $owner,
      '@title' => $title,
      '@type' => $type,
      '@data' => serialize($data),
      '@library_code' => $GLOBALS['library_code'],
    ));

    if ($result) {
      $insert_id = DB::insert_id();

      EventHandler::trigger(__FUNCTION__, array(
        $insert_id,
        $owner,
        $title,
        $data,
      ));

      return $insert_id;
    }

    self::error('', TRUE);
  }

  /**
   * Create an element, and attach it to a list.
   *
   * @param int $list_id
   *   The list id to attach the element on.
   * @param mixed $data
   *   The data you wish to save (this can be anything, it's serialized before
   *   saved to the database).
   *
   * @return mixed
   *   The element_id for the newly created element.
   */
  public function createElement($list_id, $data, $return_element = FALSE) {
    self::errorCheckArguments(array(
      'list_id' => $list_id,
    ));

    $result = DB::q('
INSERT INTO elements
(list_id, data, modified, library_code)
VALUES (%list_id, "@data", UNIX_TIMESTAMP(), "@library_code")
    ', array(
      '%list_id' => $list_id,
      '%weight_span' => self::WEIGHT_SPAN,
      '@data' => serialize($data),
      '@library_code' => $GLOBALS['library_code'],
    ));

    if ($result) {
      $insert_id = DB::insert_id();

      // Set the weight and previous column for the newly created element.
      // We get the "Unsafe statement written to the binary log using statement
      // format since BINLOG_FORMAT = STATEMENT. The statement is unsafe
      // because it uses a LIMIT clause. This is unsafe because the set of rows
      // included cannot be predicted. Statement" warning here, this needs to
      // be fixed. Perhaps a unique index with pe.list_id, pe.weight will fix
      // this.
      DB::q('
UPDATE elements e, (
  SELECT
    pe.weight
    , pe.element_id
  FROM elements pe
  WHERE
    pe.list_id = %list_id
    AND status > 0
  ORDER BY weight DESC
  LIMIT 1
) AS pe
SET e.weight = IF(pe.element_id = e.element_id, -%weight_span, pe.weight) + %weight_span, e.previous = IF(pe.element_id = e.element_id, 0, pe.element_id), library_code = "@library_code"
WHERE e.element_id = %element_id
      ', array(
        '%list_id' => $list_id,
        '%weight_span' => self::WEIGHT_SPAN,
        '%element_id' => $insert_id,
        '@library_code' => $GLOBALS['library_code'],
      ));

      EventHandler::trigger(__FUNCTION__, array($insert_id, $list_id, $data));

      if ($return_element) {
        return self::createElementData($insert_id);
      }
      else {
        return $insert_id;
      }
    }

    switch (DB::errno()) {
      case 1452:
        self::error('No list with that id exists');
        break;
    }

    self::error('', TRUE);
  }

  /**
   * Delete a list.
   *
   * @param int $list_id
   *   The list id of the list to delete.
   *
   * @return bool
   *   Deleted or not
   */
  public function deleteList($list_id) {
    self::errorCheckArguments(array(
      'list_id' => $list_id,
    ));

    $result = DB::q('
UPDATE lists
SET status = 0, modified = UNIX_TIMESTAMP(), library_code = "@library_code"
WHERE list_id = %list_id
    ', array(
      '%list_id' => $list_id,
      '@library_code' => $GLOBALS['library_code'],
    ));

    if ($result) {
      if (DB::affected_rows() === 0) {
        return self::error('Unknown list id (' . $list_id . ')');
      }

      EventHandler::trigger(__FUNCTION__, array($list_id));
      return $result;
    }

    self::error('', TRUE);
  }

  /**
   * Delete an element.
   *
   * @param mixed $element_id
   *   The id of the element to delete.
   *
   * @return bool
   *   Deleted or not
   */
  public function deleteElement($element_id) {
    self::errorCheckArguments(array(
      'element_id' => $element_id,
    ));

    if (!is_array($element_id)) {
      $element_id = array($element_id);
    }

    $result = DB::q('
UPDATE elements one RIGHT JOIN elements e ON (one.previous = e.element_id)
SET
  one.previous = e.previous
  , e.status = 0
  , e.modified = UNIX_TIMESTAMP()
  , e.library_code = "@library_code"
  , one.library_code = "@library_code"
WHERE
  e.element_id IN (?%element_id)
  AND e.status > 0
    ', array(
      '?%element_id' => $element_id,
      '@library_code' => $GLOBALS['library_code'],
    ));

    if ($result) {
      if (DB::affected_rows() === 0) {
        return self::error('Unknown element id (' . implode(',', $element_id) . ')');
      }

      EventHandler::trigger(__FUNCTION__, array($element_id));
      return $result;
    }

    self::error('', TRUE);
  }

  /**
   * Get a list of all lists owned by the owner.
   *
   * @param string $owner
   *   The owner id.
   * @param int $from
   *   Only get lists changed since this unix timestamp
   *
   * @return array
   *   An array of all the lists.
   */
  public function getLists($owner, $from = 0) {
    self::setDefault($from, 0);
    self::errorCheckArguments(array(
      'owner' => $owner,
    ));

    self::updateUserProvider($owner);

    $result = DB::q('
SELECT list_id, type, title, modified, status, data
FROM lists
WHERE
  owner = "@owner"
  AND modified > %from
  AND library_code IN (?$library_access)
    ', array(
      '@owner' => $owner,
      '%from' => $from,
      '?$library_access' => $GLOBALS['library_access'],
    ));

    if ($result) {
      $lists = array();
      while ($row = $result->fetch_assoc()) {
        $row['data'] = unserialize($row['data']);

        $lists[] = $row;
      }
      return $lists;
    }

    self::error('', TRUE);
  }

  /**
   * Get all the elements connected to a user.
   *
   * @param string $owner
   *   The list id to attach the element on.
   * @param int $from
   *   Only get elements changed since this unix timestamp
   *
   * @return mixed
   *   An array of all the lists.
   */
  public function getUserElements($owner, $from) {
    self::setDefault($from, 0);
    self::errorCheckArguments(array(
      'owner' => $owner,
    ));

    self::updateUserProvider($owner);

    $result = DB::q('
SELECT e.*
FROM elements e JOIN lists l ON (e.list_id = l.list_id)
WHERE
  l.owner = "@owner"
  AND e.modified > %from
  AND e.library_code IN (?$library_access)
ORDER BY e.list_id, e.status ASC, e.weight
    ', array(
      '@owner' => $owner,
      '%from' => $from,
      '?$library_access' => $GLOBALS['library_access'],
    ));

    if ($result) {
      $tmp = array();
      while ($row = $result->fetch_assoc()) {
        $tmp[] = array(
          'previous' => $row['previous'],
          'element_id' => $row['element_id'],
          'list_id' => $row['list_id'],
          'status' => $row['status'],
          'modified' => $row['modified'],
          'data' => unserialize($row['data']),
        );
      }

      return $tmp;
    }

    self::error('', TRUE);
  }

  /**
   * Get all the elements in a list.
   *
   * @param int $list_id
   *   The list id to attach the element on.
   * @param int $from
   *   Only get elements changed since this unix timestamp
   *
   * @return array
   *   An array of all the lists.
   */
  public function getElements($list_id, $from) {
    self::setDefault($from, 0);
    self::errorCheckArguments(array(
      'list_id' => $list_id,
    ));

    $result = DB::q('
SELECT e.*
FROM elements e
WHERE
  e.list_id = %list_id
  AND e.modified > %from
  AND e.library_code IN (?$library_access)
ORDER BY weight ASC
    ', array(
      '%list_id' => $list_id,
      '%from' => $from,
      '?$library_access' => $GLOBALS['library_access'],
    ));

    if ($result) {
      $tmp = array();
      while ($row = $result->fetch_assoc()) {
        $tmp[] = array(
          'element_id' => $row['element_id'],
          'previous' => $row['previous'],
          'list_id' => $row['list_id'],
          'status' => $row['status'],
          'data' => unserialize($row['data']),
          'modified' => $row['modified'],
        );
      }

      return $tmp;
    }

    self::error('', TRUE);
  }

  /**
   * Set an element position in the list.
   *
   * @param int $element_id
   *   ID of the element to position.
   * @param int $previous_id
   *   The element that should precede the element moving.
   *   If this is 0, or not set it will be moved to the first element of the
   *   list.
   *
   * @return mixed
   *   If it was moved or not.
   */
  public function setElementAfter($element_id, $previous_id) {
    self::setDefault($previous_id, 0);

    self::errorCheckArguments(array(
      'element_id' => $element_id,
    ));

    // Some of these SQL queries are rather complicated, I've tried my best to
    // make them readable, but with no actual code of conduct for SQL it's not
    // that easy.
    //
    // Here's some of the abbreviations used:
    //
    // - e (element):
    // The actual element to move.
    // - pe (previous element):
    // The new previous element.
    // - ne (next element):
    // The element that will become the new next element of the element
    // being moved.
    // - ope (old previous element):
    // The element that used to be the element being moved previous element.
    // - one (old next element):
    // The element that used to be the element being moved next element.

    // Place the element at the beginning of the list if $previous_id is 0.
    if (empty($previous_id)) { // === 0) {
      $data = DB::q('
SELECT first.element_id as first_id, one.element_id as old_next_id
FROM elements first
  INNER JOIN elements e
    ON (first.list_id = e.list_id)
  LEFT JOIN elements one
    ON (
      one.list_id = e.list_id
      AND one.weight > e.weight
    )
WHERE e.element_id = %element_id
ORDER BY first.weight ASC
LIMIT 1
      ', array('%element_id' => $element_id))->fetch_assoc();

      if ($data['first_id'] == $element_id) {
        return TRUE;
      }

      if (!empty($data['old_next_id'])) {
        // Moving mid element to the first position.
        $result = DB::q('
UPDATE elements one, elements ne, elements e
SET
  one.previous = e.previous
  , ne.previous = e.element_id
  , e.weight = ne.weight - %next_weight
  , e.modified = UNIX_TIMESTAMP()
  , e.previous = 0
  , e.library_code = "@library_code"
  , one.library_code = "@library_code"
  , ne.library_code = "@library_code"
WHERE
  e.element_id = %element_id
  AND ne.element_id = %next_element_id
  AND one.element_id = %old_next_id
        ', array(
          '%element_id' => $element_id,
          '%next_element_id' => $data['first_id'],
          '%next_weight' => self::WEIGHT_SPAN,
          '%old_next_id' => $data['old_next_id'],
          '@library_code' => $GLOBALS['library_code'],
        ));
      }
      else {
        // Moving last element to the first position.
        $result = DB::q('
UPDATE elements ne, elements e
SET
  ne.previous = e.element_id
  , e.weight = ne.weight - %next_weight
  , e.modified = UNIX_TIMESTAMP()
  , e.previous = 0
  , e.library_code = "@library_code"
  , ne.library_code = "@library_code"
WHERE
  e.element_id = %element_id
  AND ne.element_id = %next_element_id
        ', array(
          '%element_id' => $element_id,
          '%next_element_id' => $data['first_id'],
          '%next_weight' => self::WEIGHT_SPAN,
          '%old_next_id' => $data['old_next_id'],
          '@library_code' => $GLOBALS['library_code'],
        ));
      }

      return $result;
    }

    // Get the next and previous weight, the list_id and the next element id.
    $data = DB::q('
SELECT pe.weight AS previous_weight, ne.weight AS next_weight, ne.list_id, ne.element_id as next_id, one.element_id AS old_next_id
FROM elements pe
  LEFT JOIN elements ne
    ON (
      ne.list_id = pe.list_id
      AND ne.weight > pe.weight
    )
  LEFT JOIN elements one
    ON (
      one.previous = %element_id
    )
WHERE pe.element_id = %previous_id
ORDER BY ne.weight
LIMIT 1
    ', array(
      '%previous_id' => $previous_id,
      '%element_id' => $element_id,
    ));

    // Send an error if the previous id didn't exist.
    // (Note that we also fetch the element into our $data variable).
    if (!$data || !($data = $data->fetch_assoc())) {
      return self::error('Unknown previous id (' . $previous_id . ')');
    }

    if (empty($data['next_id'])) {
      // Moving any element to the last position.
      $result = DB::q('
UPDATE elements one, elements e
SET
  one.previous = e.previous
  , e.weight = %next_weight
  , e.modified = UNIX_TIMESTAMP()
  , e.previous = %previous_id
  , e.library_code = "@library_code"
  , one.library_code = "@library_code"
WHERE
  e.element_id = %element_id
  AND one.previous = e.element_id
      ', array(
        '%element_id' => $element_id,
        '%previous_id' => $previous_id,
        '%next_weight' => $data['previous_weight'] + self::WEIGHT_SPAN,
        '@library_code' => $GLOBALS['library_code'],
      ));

      return $result;
    }
    elseif ($data['next_id'] != $element_id) {
      if (!empty($data['old_next_id'])) {
        // Moving a mid element to a mid position.
        $result = DB::q('
UPDATE elements ne, elements one, elements e
SET
  ne.previous = %element_id
  , one.previous = e.previous
  , e.weight = %next_weight
  , e.modified = UNIX_TIMESTAMP()
  , e.previous = %previous_id
  , e.library_code = "@library_code"
  , one.library_code = "@library_code"
  , ne.library_code = "@library_code"
WHERE
  e.element_id = %element_id
  AND one.element_id = %old_next_id
  AND ne.previous = %previous_id
        ', array(
          '%element_id' => $element_id,
          '%previous_id' => $previous_id,
          '%old_next_id' => $data['old_next_id'],
          '%next_weight' => ($data['previous_weight'] + $data['next_weight']) / 2,
          '@library_code' => $GLOBALS['library_code'],
        ));
      }
      else {
        // Moving the last element to a mid position.
        $result = DB::q('
UPDATE elements ne, elements e
SET
  ne.previous = %element_id
  , e.weight = %next_weight
  , e.modified = UNIX_TIMESTAMP()
  , e.previous = %previous_id
  , e.library_code = "@library_code"
  , ne.library_code = "@library_code"
WHERE
  e.element_id = %element_id
  AND ne.previous = %previous_id
        ', array(
          '%element_id' => $element_id,
          '%previous_id' => $previous_id,
          '%next_weight' => ($data['previous_weight'] + $data['next_weight']) / 2,
          '@library_code' => $GLOBALS['library_code'],
        ));
      }

      // No affected rows, means the $element_id didn't exist.
      if (DB::affected_rows() === 0) {
        return self::error('Unknown element id (' . $element_id . ')');
      }

      // If the average of the previous and next weights, are equal to or
      // less than 2, we need to normalize the list weights.
      if ($data['next_weight'] - $data['previous_weight'] <= 2) {
        Admin::normalizeElements($data['list_id'], self::WEIGHT_SPAN);
      }

      return $result;
    }
    else {
      return TRUE;
    }

    self::error('', TRUE);
  }

  /**
   * A simple ping/pong test.
   *
   * @return string
   *   Returns pong.
   */
  public function ping() {
    return 'pong';
  }

  public function getList($list_id) {
    self::errorCheckArguments(array(
      'list_id' => $list_id,
    ));

    $list = DB::q('
SELECT list_id, type, title, modified, owner, data
FROM lists
WHERE
  library_code IN (?$library_access)
  AND list_id = %list_id
  AND status = 1
LIMIT 1
    ', array(
      '%list_id' => $list_id,
      '?$library_access' => $GLOBALS['library_access'],
    ))->fetch_assoc();

    if ($list === NULL) {
      return FALSE;
    }

    return self::createListData($list);
  }

  public function getListsByType($types, $user, $expanded = FALSE) {
    self::errorCheckArguments(array(
      'user' => $user,
    ));

    $result = array();

    $types_where = '';
    if (!empty($types)) {
      $types_where = 'AND l.type IN (?$types)';
    } else {
      $types = array();
    }

    $lists = DB::q('
(SELECT l.list_id, l.type, l.title, l.modified, l.owner, l.data, GREATEST(l.modified, COALESCE(0, MAX(e.modified))) AS emod
FROM
  lists l
  JOIN m_list_user_permission up ON (up.list_id = l.list_id AND up.user = "@user" AND up.permission = "edit")
  LEFT JOIN elements e ON (e.list_id = l.list_id)
WHERE
  l.library_code IN (?$library_access)
  ' . $types_where . '
  AND l.status = 1
  AND l.type NOT IN ("follow_user", "follow")
GROUP BY
  l.list_id)
UNION
(SELECT l.list_id, l.type, l.title, l.modified, l.owner, l.data, GREATEST(l.modified, COALESCE(0, MAX(e.modified))) AS emod
FROM
  lists l
  LEFT JOIN elements e ON (e.list_id = l.list_id)
WHERE
  l.library_code IN (?$library_access)
  ' . $types_where . '
  AND l.owner = "@user"
  AND l.status = 1
  AND l.type NOT IN ("follow_user", "follow")
GROUP BY
  l.list_id)

ORDER BY
  emod DESC, modified DESC
    ', array(
      '?$types' => $types,
      '@user' => $user,
      '?$library_access' => $GLOBALS['library_access'],
    ));

    while ($list = $lists->fetch_assoc()) {
      $result[$list['list_id']] = self::createListData($list, $expanded);
    }

    return $result;
  }

  public static function createListData($list, $expanded = TRUE) {
    $list['elements'] = [];
    $data = unserialize($list['data']);

    // Old format.
    if (isset($data['more'])) {
      $list['data'] = [];

      if (!empty($data['fields'])) {
        foreach ($data['fields'] as $field) {
          switch ($field['name']) {
            case 'field_notes':
              $list['data']['note'] = $field['value'];
              break;

            case 'field_ding_list_status':
              $list['data']['visibility'] = $field['value'];
              break;
          }
        }
      }
    }
    else {
      $list['data'] = $data;
    }

    if ($expanded) {
      $elements = DB::q('
SELECT e.*
FROM elements e
WHERE
  e.list_id = %list_id
  AND e.library_code IN (?$library_access)
  AND e.status = 1
ORDER BY weight DESC
LIMIT 200
      ', array(
        '%list_id' => $list['list_id'],
        '?$library_access' => $GLOBALS['library_access'],
      ));

      while ($element = $elements->fetch_assoc()) {
        $list['elements'][$element['element_id']] = self::createElementData($element);
      }
    }

    return $list;
  }

  public static function createElementData($element_data) {
    $element = array();

    if (is_numeric($element_data)) {
      $element_data = DB::q(
        'SELECT * FROM elements e WHERE element_id = %element_id',
        array('%element_id' => $element_data)
      )->fetch_assoc();
    }

    $data = unserialize($element_data['data']);
    $element = array(
      'list_id' => $element_data['list_id'],
      'element_id' => $element_data['element_id'],
      'weight' => $element_data['weight'],
      'value' => $data['value'],
      'type' => $data['type'],
      'modified' => $element_data['modified'],
      'created' => $element_data['created'],
      'data' => array_diff_key($data, array_flip(['value', 'type'])),
    );

    return $element;
  }

  /**
   * Load a list.
   *
   * @param int $id
   *   The list id
   *
   * @return array
   *   The result array containing the list
   */
  private function loadList($id) {

    // @todo Static caching? Multiple loading?
    $result = DB::q('
SELECT list_id, type, title, modified, status, data
FROM lists
WHERE
  list_id = "@id"
    ', array(
      '@owner' => $id,
    ));
  }
}
