<?php

class CRM_Volunteer_BAO_NeedSearch {

  /**
   * @var array
   *   Holds project data for the Needs matched by the search. Keyed by project ID.
   */
  private $projects = array();

  /**
   * @var array
   *   See  getDefaultSearchParams() for format.
   */
  private $searchParams = array();

  /**
   * @var array
   *   An array of needs. The results of the search, which will ultimately be returned.
   */
  private $searchResults = array();

  /**
   * @param array $userSearchParams
   *   See setSearchParams();
   */
  public function __construct ($userSearchParams) {
    $this->searchParams = $this->getDefaultSearchParams();
    $this->setSearchParams($userSearchParams);
  }

  /**
   * Convenience static method for searching without instantiating the class.
   *
   * Invoked from the API layer.
   *
   * @param array $userSearchParams
   *   See setSearchParams();
   * @return array $this->searchResults
   */
  public static function doSearch ($userSearchParams) {
    $searcher = new self($userSearchParams);
    return $searcher->search();
  }

  /**
   * @return array
   *   Used as the starting point for $this->searchParams.
   */
  private function getDefaultSearchParams() {
    return array(
      'project' => array(
        'is_active' => 1,
      ),
      'need' => array(
        'role_id' => array(),
      ),
    );
  }

  /**
   * Performs the search.
   *
   * Stashes the results in $this->searchResults.
   *
   * @return array $this->searchResults
   */
  public function search() {
    $projects = CRM_Volunteer_BAO_Project::retrieve($this->searchParams['project']);
    foreach ($projects as $project) {
      $results = array();

      $flexibleNeed = civicrm_api3('VolunteerNeed', 'getsingle', array(
        'id' => $project->flexible_need_id,
      ));
      if ($flexibleNeed['visibility_id'] === CRM_Core_OptionGroup::getValue('visibility', 'public', 'name')) {
        $needId = $flexibleNeed['id'];
        $results[$needId] = $flexibleNeed;
      }

      $openNeeds = $project->open_needs;
      foreach ($openNeeds as $key => $need) {
        if ($this->needFitsSearchCriteria($need)) {
          $results[$key] = $need;
        }
      }

      if (!empty($results)) {
        $this->projects[$project->id] = array();
      }

      $this->searchResults += $results;
    }

    $this->getSearchResultsProjectData();
    usort($this->searchResults, array($this, "usortDateAscending"));
    return $this->searchResults;
  }

  /**
   * Returns TRUE if the need matches the dates in the search criteria, else FALSE.
   *
   * Assumptions:
   *   - Need start_time is never empty. (Only in exceptional cases should this
   *     assumption be false for non-flexible needs. Flexible needs are excluded
   *     from $project->open_needs.)
   *
   * @param array $need
   * @return boolean
   */
  private function needFitsDateCriteria(array $need) {
    $needStartTime = strtotime(CRM_Utils_Array::value('start_time', $need));
    $needEndTime = strtotime(CRM_Utils_Array::value('end_time', $need));

    // There are no date-related search criteria, so we're done here.
    if ($this->searchParams['need']['date_start'] === FALSE && $this->searchParams['need']['date_end'] === FALSE) {
      return TRUE;
    }

    // The search window has no end time. We need to verify only that the need
    // has dates after the start time.
    if ($this->searchParams['need']['date_end'] === FALSE) {
      return $needStartTime >= $this->searchParams['need']['date_start'] || $needEndTime >= $this->searchParams['need']['date_start'];
    }

    // The search window has no start time. We need to verify only that the need
    // starts before the end of the window.
    if ($this->searchParams['need']['date_start'] === FALSE) {
      return $needStartTime <= $this->searchParams['need']['date_end'];
    }

    // The need does not have fuzzy dates, and both ends of the search
    // window have been specified. We need to verify only that the need
    // starts in the search window.
    if ($needEndTime === FALSE) {
      return $needStartTime >= $this->searchParams['need']['date_start'] && $needStartTime <= $this->searchParams['need']['date_end'];
    }

    // The need has fuzzy dates, and both endpoints of the search window were
    // specified:
    return
      // Does the need start in the provided window...
      ($needStartTime >= $this->searchParams['need']['date_start'] && $needStartTime <= $this->searchParams['need']['date_end'])
      // or does the need end in the provided window...
      || ($needEndTime >= $this->searchParams['need']['date_start'] && $needEndTime <= $this->searchParams['need']['date_end'])
      // or are the endpoints of the need outside the provided window?
      || ($needStartTime <= $this->searchParams['need']['date_start'] && $needEndTime >= $this->searchParams['need']['date_end']);
  }

  /**
   * @param array $need
   * @return boolean
   */
  private function needFitsSearchCriteria(array $need) {
    return
      $this->needFitsDateCriteria($need)
      && (
        // Either no role was specified in the search...
        empty($this->searchParams['need']['role_id'])
        // or the need role is in the list of searched-by roles.
        || in_array($need['role_id'], $this->searchParams['need']['role_id'])
      );
  }

  /**
   * @param array $userSearchParams
   *   Supported parameters:
   *     - beneficiary: mixed - an int-like string, a comma-separated list
   *         thereof, or an array representing one or more contact IDs
   *     - project: int-like string representing project ID
   *     - proximity: array - see CRM_Volunteer_BAO_Project::buildProximityWhere
   *     - role_id: mixed - an int-like string, a comma-separated list thereof, or
   *         an array representing one or more role IDs
   *     - date_start: See setSearchDateParams()
   *     - date_end: See setSearchDateParams()
   */
  private function setSearchParams($userSearchParams) {
    $this->setSearchDateParams($userSearchParams);

    $projectId = CRM_Utils_Array::value('project', $userSearchParams);
    if (CRM_Utils_Type::validate($projectId, 'Positive', FALSE)) {
      $this->searchParams['project']['id'] = $projectId;
    }

    $proximity = CRM_Utils_Array::value('proximity', $userSearchParams);
    if (is_array($proximity)) {
      $this->searchParams['project']['proximity'] = $proximity;
    }

    $beneficiary = CRM_Utils_Array::value('beneficiary', $userSearchParams);
    if ($beneficiary) {
      if (!array_key_exists('project_contacts', $this->searchParams['project'])) {
        $this->searchParams['project']['project_contacts'] = array();
      }
      $beneficiary = is_array($beneficiary) ? $beneficiary : explode(',', $beneficiary);
      $this->searchParams['project']['project_contacts']['volunteer_beneficiary'] = $beneficiary;
    }

    $role = CRM_Utils_Array::value('role_id', $userSearchParams);
    if ($role) {
      $this->searchParams['need']['role_id'] = is_array($role) ? $role : explode(',', $role);
    }
  }

  /**
   * Sets date_start and date_need in $this->searchParams to a timestamp or to
   * boolean FALSE if invalid values were supplied.
   *
   * @param array $userSearchParams
   *   Supported parameters:
   *     - date_start: date
   *     - date_end: date
   */
  private function setSearchDateParams($userSearchParams) {
    $this->searchParams['need']['date_start'] = strtotime(CRM_Utils_Array::value('date_start', $userSearchParams));
    $this->searchParams['need']['date_end'] = strtotime(CRM_Utils_Array::value('date_end', $userSearchParams));
  }

  /**
   * Adds 'project' key to each need in $this->searchResults, containing data
   * related to the project, campaign, location, and project contacts.
   */
  private function getSearchResultsProjectData() {
    // api.VolunteerProject.get does not support the 'IN' operator, so we loop
    foreach ($this->projects as $id => &$project) {
      $api = civicrm_api3('VolunteerProject', 'getsingle', array(
        'id' => $id,
        'api.Campaign.getvalue' => array(
          'return' => 'title',
        ),
        'api.LocBlock.getsingle' => array(
          'api.Address.getsingle' => array(),
        ),
        'api.VolunteerProjectContact.get' => array(
          'options' => array('limit' => 0),
          'relationship_type_id' => 'volunteer_beneficiary',
          'api.Contact.get' => array(
            'options' => array('limit' => 0),
          ),
        ),
      ));

      $project['description'] = $api['description'];
      $project['id'] = $api['id'];
      $project['title'] = $api['title'];

      // Because of CRM-17327, the chained "get" may improperly report its result,
      // so we check the value we're chaining off of to decide whether or not
      // to trust the result.
      $project['campaign_title'] = empty($api['campaign_id']) ? NULL : $api['api.Campaign.getvalue'];

      // CRM-17327
      if (empty($api['loc_block_id']) || empty($api['api.LocBlock.getsingle']['address_id'])) {
        $project['location'] = array(
          'city' => NULL,
          'country' => NULL,
          'postal_code' => NULL,
          'state_provice' => NULL,
          'street_address' => NULL,
        );
      } else {
        $countryId = $api['api.LocBlock.getsingle']['api.Address.getsingle']['country_id'];
        $country = $countryId ? CRM_Core_PseudoConstant::country($countryId) : NULL;

        $stateProvinceId = $api['api.LocBlock.getsingle']['api.Address.getsingle']['state_province_id'];
        $stateProvince = $stateProvinceId ? CRM_Core_PseudoConstant::stateProvince($stateProvinceId) : NULL;

        $project['location'] = array(
          'city' => $api['api.LocBlock.getsingle']['api.Address.getsingle']['city'],
          'country' => $country,
          'postal_code' => $api['api.LocBlock.getsingle']['api.Address.getsingle']['postal_code'],
          'state_province' => $stateProvince,
          'street_address' => $api['api.LocBlock.getsingle']['api.Address.getsingle']['street_address'],
        );
      }

      foreach ($api['api.VolunteerProjectContact.get']['values'] as $projectContact) {
        if (!array_key_exists('beneficiaries', $project)) {
          $project['beneficiaries'] = array();
        }

        $project['beneficiaries'][] = array(
          'id' => $projectContact['contact_id'],
          'display_name' => $projectContact['api.Contact.get']['values'][0]['display_name'],
        );
      }
    }

    foreach ($this->searchResults as &$need) {
      $projectId = (int) $need['project_id'];
      $need['project'] = $this->projects[$projectId];
    }
  }

  /**
   * Callback for usort.
   */
  private static function usortDateAscending($a, $b) {
    $startTimeA = strtotime($a['start_time']);
    $startTimeB = strtotime($b['start_time']);

    if ($startTimeA === $startTimeB) {
      return 0;
    }
    return ($startTimeA < $startTimeB) ? -1 : 1;
  }

  /**
   * Recommend Needs for Contact
   */
  public static function recommendedNeeds($cid, $dates = NULL) {

    //Fetch Needs
    //$needs = civicrm_api3('VolunteerNeed', 'get')['values'];

//    * Fetch Contact Properties
//    - Skills
//    - Interests
//    - Availability
//
//   * filter Orgs by Interests/Impacts
    $schemaImpact = self::getCustomFieldSchema('Primary_Impact_Area');
    $schemaInterests = self::getCustomFieldSchema('Interests');

//    return array($schemaImpact['option_group']['name'], $schemaInterests['option_group']['name']);

//    return self:: getCustomFieldSchema('Background_Check_Opt_In');
//    return self::autoMatchFieldByOptionGroup(self::fieldsToRecommendOn());
    return self::fetchOrgsByImpactArea('Literacy', 'Advocacy', 'Environment');

  }

  static function fieldsToRecommendOn() {
    return array(
      'Interests',
      'Primary_Impact_Area',
      'Background_Check_Opt_In',
      'Spoken_Languages',
      'Agreed_to_Waiver',
      'Group_Volunteer_Interest',
      'Availability',
      'Board_Service_Opt_In',
      'How_Often',
      'Volunteer_Emergency_Support_Team_Opt_In',
      'Other_Skills',
      'Local_Arlington_Civic_Association_Opt_In',
      'Spoken_Languages_Other_',
    );
  }

  public static function matchingCustomFieldsMap() {
    return array(
      'Interests' => 'Primary_Impact_Area',
      'Background_Check_Opt_In' => 'Background_Check_Opt_In',
      'Spoken_Languages' => 'Spoken_Languages',
      'Agreed_to_Waiver' => 'Agreed_to_Waiver',
      'Group_Volunteer_Interest' => 'Group_Volunteer_Interest',
      'Availability' => 'Availability',
      'Board_Service_Opt_In' => 'Board_Service_Opt_In',
      'How_Often' => 'How_Often',
      'Volunteer_Emergency_Support_Team_Opt_In' => 'Volunteer_Emergency_Support_Team_Opt_In',
      'Other_Skills' => 'Other_Skills',
      'Local_Arlington_Civic_Association_Opt_In' => 'Local_Arlington_Civic_Association_Opt_In',
      'Spoken_Languages_Other_' => 'Spoken_Languages_Other_',
    );
  }

  static function fetchOrgsByImpactArea($interests=array()) {
    $impactSchema = self::getCustomFieldSchema('Primary_Impact_Area');
//return $impactSchema;

//[custom_group] => Array
//        (
//            [id] => 5
//            [name] => Organization_Information
//            [extends] => Organization
//            [weight] => 6
//            [is_active] => 1
//            [table_name] => civicrm_value_organization_information_5
//            [is_multiple] => 0
//        )
//    [id] => 44
//    [custom_group_id] => 5
//    [name] => Primary_Impact_Area
//    [column_name] => primary_impact_area_44
//    [option_group_id] => 102

//    select civicrm_contact.id, civicrm_contact.display_name, civicrm_contact.contact_type
//    from civicrm_contact inner join civicrm_value_organization_information_5
//    ON civicrm_contact.id = civicrm_value_organization_information_5.entity_id where civicrm_contact.id = 6772;


//    $where = array(array('field' => 'civicrm_contact.id', 'value' => '6772'));

//    $result = civicrm_api3('Contact', 'get', array(
//      'contact_type' => 'Organization',
//      'return' => 'id',
//      'custom_44' => array('IN' => $interests),
//    ));

    $select = array('civicrm_contact' => array('id'));
    $tables = array('civicrm_contact', 'civicrm_value_organization_information_5' );
    $joins = array(array('tables' => $tables, 'join' => 'INNER JOIN', 'on' => 'civicrm_contact.id = civicrm_value_organization_information_5.entity_id') );
    $where = array(array('field' => 'civicrm_value_organization_information_5.primary_impact_area_44', 'value' => 'Homelessness'));

    $query = self::createSqlSelectStatement(
      array(
        'SELECTS' => $select,
        'JOINS' => $joins,
        'WHERES' => $where,
      ));

    $dao = CRM_Core_DAO::executeQuery($query['sql'], $query['params']);
//    $query = 'SELECT civicrm_contact.id FROM civicrm_contact INNER JOIN civicrm_value_organization_information_5 on civicrm_contact.id = civicrm_value_organization_information_5.entity_id WHERE civicrm_contact.id = %0';
//    $params = array(array('6772', 'String'));
//    $dao = CRM_Core_DAO::executeQuery($query, $params);
    while ($dao->fetch()) {
      $result[] = $dao->id;
    }

    return $result;
  }

  /**
   * Prepare query and params for CRM_Core_DAO::executeQuery().
   *
   * At minnimum, components must have SELECTS and (TABLES or JOINS).
   * You may also provide components for WHERES, ORDER_BYS, and GROUP_BYS.
   *
   * More details in parseWHEREs().
   *
   * createSqlStatement( array(
   * 'SELECTS' => array('civicrm_contact' => array('id', 'display_name')),
   * 'JOINS' => array(
   *    array(
   *      'tables' => array('civicrm_contact', 'civicrm_value_organization_information_5' ),
   *      'join' => 'INNER JOIN',
   *      'on' => 'civicrm_contact.id = civicrm_value_organization_information_5.entity_id')
   *    )
   * ),
   * 'WHERES' => array(
   *    array('field' => 'civicrm_contact.first', 'value' => array($value, $type), 'conj' => 'AND'),
   *    array('field' => 'civicrm_contact.last', 'value' => array($value, $type), 'conj' => 'AND')
   *   )
   * );
   *
   *
   * @param array $components
   * @return array( 'sql' => ..., 'params' => ... ) for invocation of CRM_Core_DAO::executeQuery()
   */
  static function createSqlSelectStatement($components=array()){
    if (array_key_exists('SELECTS', $components) &&
       (array_key_exists('TABLES', $components) || array_key_exists('JOINS', $components))
      ) { // good
    }else {
      // please come again
      throw new CRM_Exception('minnimum components missing: '.__FILE__.':'.__LINE__);
    }
    $SELECTS = CRM_Utils_Array::value('SELECTS', $components);
    $TABLES = CRM_Utils_Array::value('TABLES', $components, array());
    $JOINS = CRM_Utils_Array::value('JOINS', $components, array());
    $WHERES = CRM_Utils_Array::value('WHERES', $components, array());
    $GROUP_BYS = CRM_Utils_Array::value('GROUP_BYS', $components, array());
    $ORDER_BYS = CRM_Utils_Array::value('ORDER_BYS', $components, array());

    if (is_array($SELECTS)) {
      foreach ($SELECTS as $table => $columns) {
        $tmp = array();
        foreach ($columns as $col) {
          $tmp[] = "{$table}.{$col}";
        }
        if (isset($clzSelect)) {
          $clzSelect .= ', ';
        }
        $clzSelect .= join(', ', $tmp);
      }
    } else {
      $clzSelect = $SELECTS;
    }

    foreach($JOINS as $join) {
      $clzFrom =
        implode(' '.$join['join'].' ', $join['tables'])
        .' on '.$join['on'];
    }

    if (!isset($clzFrom)) {
      $clzFrom = implode(', ', $TABLES);
    }

    $parseWHERE = self::parseWHEREs($WHERES);
    $clzWhere = $parseWHERE['WHERE'];
    $params = $parseWHERE['params'];

    if (count($GROUP_BYS)) {
      $clzGroupBy = join(', ', $GROUP_BYS);
    }
    if (count($ORDER_BYS)) {
      $clzOrderBy = join(', ', $ORDER_BYS);
    }

    return array( 'sql' =>
      "SELECT {$clzSelect} FROM {$clzFrom}"
      . ((isset($clzWhere))? " WHERE {$clzWhere}" : '')
      . ((isset($clzGroupBy))? " GROUP BY {$clzGroupBy}" : '')
      . ((isset($clzOrderBy))? " ORDER BY {$clzOrderBy}" : ''),
      'params' => $params
    );
  }

  /**
   * creates SQL WHERE clause and params for CRM_Core_DAO::executeQuery();
   *
   * WHERE clause types should conform to CRM_Core_Util_Type::validate()
   *
   * SAMPLE input
   * $WHERES = array(
   *     array(
   *       'field' => 'civicrm_contact.first',
   *       'value' => $value,
   *       'type' =>  'String',
   *       'conj' => 'AND'
   *     ),
   *     array(
   *       'field' => 'civicrm_contact.last',
   *       'value' => $value,
   *       'type' =>  'String',
   *       'conj' => 'AND'
   *     )
   *   );
   *
   * Input-array items indexed by 'AND' or 'OR' will be recursively parsed (for nested clauses).
   *
   * SAMPLE output
   * $params = array( 1 => array( 'value', 'type')) // see CRM_Utils_Type::validate() re type
   *
   * @param type $WHERES
   * @param int $n starting index for params array (needed for recursion)
   *
   * @return array('WHERE' => '...', 'params' => array())
   * @throws CRM_Exception
   */
  static function parseWHEREs($WHERES, $n=0) {
    $params = array();
    foreach ($WHERES as $key => &$where) {
      if (!is_int($key) && (strtoupper($key) == 'AND' || strtoupper($key) == 'OR')) {
        $parsed = self::parseWHERES($where, $n);
        $where = "({$parsed['WHERE']})";
        $params = array_merge($params, $parsed['params']);
        $conj = $key;
      }
      if (is_array($where)) {
        if (!array_key_exists('field', $where)) {
          throw new CRM_Exception("'field' is required: ".__FILE__.':'.__LINE__);
        }
        if (!array_key_exists('value', $where)) {
          throw new CRM_Exception("'value' array required: ".__FILE__.':'.__LINE__);
        }
        if (!array_key_exists('conj', $where)) {
          $where['conj'] = 'AND';
        }
        if (!array_key_exists('comp', $where)) {
          $where['comp'] = '=';
        }
        if (!array_key_exists($where['type'])) {
          $where['type'] = 'String';
        }

        $clause = "{$where['field']} {$where['comp']} %{$n}";
        $params[$n] = array($where['value'], $where['type']);
        $conj = strtoupper(trim($where['conj']));
      }

      if (!isset($conj)) {
        $conj = 'AND';
      }

      $clzWhere .= (isset($clzWhere))? " $conj $clause" : $clause;

      $n++;
    }

    return (isset($clzWhere))? array('WHERE' => $clzWhere, 'params' => $params): NULL;
  }

  static function autoMatchFieldByOptionGroup($fields=array()) {
    foreach ($fields as $field) {
      $schema = self::getCustomFieldSchema($field);
      if (array_key_exists('option_group_id', $schema)) {
        $meta[$field] = $schema['option_group']['name'];
      }
    }

    $matches = array();
    foreach ($meta as $field => $opt_grp) {
      $match = array_intersect($meta,array($opt_grp));
      if (count($match) > 1) {
        $a = array_keys($match);
        $b = array_reverse($a);
        $fields = array_combine($a, $b);
      }

      end($fields);
      do  {
        $field_b = current($fields);
        $field_a = key($fields);
        if (array_key_exists($field_b, $fields) === true) {
          // de-dupe:
          unset($fields[$field_a]);
        } else {
          $matches[$field_a] = $field_b;
        }
        ;
      } while (prev($fields) !== false);

    }

    return $fields;
  }

  static function getCustomFieldSchema($field) {
    $result = civicrm_api3('CustomField', 'get',
      array(
        'sequential' => 0,
        'name' => $field,
        'is_active' => '1',
        'return' => array(
          'column_name',
          'custom_group_id',
          'id',
          'name',
          'label',
          'data_type',
          'html_type',
          'is_required',
          'option_group_id',
        ),
        'api.CustomGroup.get' => array('id' => '$value.custom_group_id'),
        'api.OptionGroup.get' => array('id' => '$value.option_group_id'),
        'api.OptionValue.get' => array(
          'option_group_id' => '$value.option_group_id',
          'is_active' => 1,
          'sequential' => 0,
          'return' => array('name','value')
        )
      )
    );

    $schema = self::extractFields($result,
      array(
        'column_name',
        'custom_group_id',
        'id',
        'name',
        'label',
        'data_type',
        'html_type',
        'is_required',
        'option_group_id',
      )
    );

    $schema['custom_group'] = self::extractChainedApi('api.CustomGroup.get', $result,
      array(
        'id',
        'name',
       'table_name',
        'extends',
        'weight',
        'is_multiple',
        'is_active'
      )
    );

    if (array_key_exists('option_group_id', $schema)) {
      $schema['option_group'] = self::extractChainedApi('api.OptionGroup.get', $result);
      $schema['option_group']['options'] = self::extractChainedApi('api.OptionValue.get', $result);
    }

    return $schema;
 }

 /**
  * fetch the values array from an api chain call
  *
  * @param string $chainKey identifies the API-Chain call
  * @param api $result civicrm api result
  * @param array $keys (optional) fields to return, all if empty
  * @return array api $result['values']
  */
  static function extractChainedApi($chainKey, $result, $keys=array()) {
    $chained = array_pop(self::extractFields($result, array($chainKey)));
    return self::extractFields($chained, $keys);
  }

  /**
   * returns the fields specified, from an api $result.
   * Can only return fields that are siblings.
   * Uses 'values' array if it is present.
   *
   * @param api civicrm api result
   * @param array $keys (optional) fields to return, all if empty
   * @return array $result['values']
   */
  static function extractFields($result, $keys=array()) {
    if (!is_array($result)) {
      return array();
    }
    $_keys = array_flip($keys);
    $items = (array_key_exists('values', $result)) ? $result['values'] : $result;
    $return = array();
    foreach ( $items as $key => $item ) {
      $return[$key] = ( count($_keys)>0 )
        ? array_intersect_key($item, $_keys)
        : $item ;
    }
    return (count($return) == 1 && is_array(current($return)))
      ? array_pop($return) : $return;
  }

}
