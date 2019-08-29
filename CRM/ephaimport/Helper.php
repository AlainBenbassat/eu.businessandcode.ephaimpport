<?php

class CRM_ephaimport_Helper {
  public static function onEnd(CRM_Queue_TaskContext $ctx) {
    CRM_Core_Session::setStatus('Done.', 'Queue', 'success');
  }

  public static function createMepsConfig() {
    // create the parent group EP Countries
    $params = [
      'name' => 'EP_Countries',
      'sequential' => 1,
    ];
    $result = civicrm_api3('Group', 'get', $params);
    if ($result['count'] == 0) {
      // create the group
      $params['title'] = 'EP Countries';
      $result = civicrm_api3('Group', 'create', $params);
      $epCountries = $result['id'];
    }
    else {
      $epCountries = $result['values'][0]['id'];
    }

    // create the country groups
    $sql = "select distinct country from tmpepha_import_meps";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      // check if the group exists
      $params = [
        'name' => 'mep_country_' . $dao->country,
        'sequential' => 1,
      ];
      $result = civicrm_api3('Group', 'get', $params);
      if ($result['count'] == 0) {
        // create the group
        $params['title'] = 'MEP Country - ' . $dao->country;
        $result = civicrm_api3('Group', 'create', $params);
        $groupID = $result['id'];
      }
      else {
        $groupID = $result['values'][0]['id'];
      }

      // make group child of EP countries
      // for some reason the API does not work
      $id = CRM_Core_DAO::singleValueQuery("select id from civicrm_group_nesting where child_group_id = $groupID and parent_group_id = $epCountries");
      if ($id > 0) {
        // do nothing
      }
      else {
        $sql = "insert into civicrm_group_nesting (child_group_id, parent_group_id) values ($groupID, $epCountries)";
        CRM_Core_DAO::executeQuery($sql);
      }
    }

    // create the parent group EP Political Groups
    $params = [
      'name' => 'EP_Political_Groups',
      'sequential' => 1,
    ];
    $result = civicrm_api3('Group', 'get', $params);
    if ($result['count'] == 0) {
      // create the group
      $params['title'] = 'EP Political Groups';
      $result = civicrm_api3('Group', 'create', $params);
      $epPolGroups = $result['id'];
    }
    else {
      $epPolGroups = $result['values'][0]['id'];
    }

    // create the political groups
    $sql = "select distinct eu_group from tmpepha_import_meps";
    $dao = CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      // check if the group exists
      $params = [
        'title' => $dao->eu_group,
        'sequential' => 1,
      ];
      $result = civicrm_api3('Group', 'get', $params);
      if ($result['count'] == 0) {
        // create the group
        civicrm_api3('Group', 'create', $params);
        $groupID = $result['id'];
      }
      else {
        $groupID = $result['values'][0]['id'];
      }

      // make group child of EP countries
      // for some reason the API does not work
      $id = CRM_Core_DAO::singleValueQuery("select id from civicrm_group_nesting where child_group_id = $groupID and parent_group_id = $epPolGroups");
      if ($id > 0) {
        // do nothing
      }
      else {
        $sql = "insert into civicrm_group_nesting (child_group_id, parent_group_id) values ($groupID, $epPolGroups)";
        CRM_Core_DAO::executeQuery($sql);
      }
    }

    // create the sub type Politician
    $params = [
      'name' => 'Politician',
    ];
    $result = civicrm_api3('ContactType', 'get', $params);
    if ($result['count'] == 0) {
      // create the sub type
      $params['label'] = 'Politician';
      $params['parent_id'] = 1;
      civicrm_api3('ContactType', 'create', $params);
    }

    // create the custom group
    $params = [
      "name" => "Politician_Details",
      'sequential' => 1,
    ];
    $result = civicrm_api3('CustomGroup', 'get', $params);
    if ($result['count'] == 0) {
      $params = [
        "name" => "Politician_Details",
        "title" => "Politician Details",
        "extends" => "Individual",
        "extends_entity_column_value" => ["Politician"],
        "style" => "Inline",
        "collapse_display" => "0",
        "is_active" => "1",
        "table_name" => "civicrm_value_politician_details",
        "is_multiple" => "0",
        "collapse_adv_display" => "0",
        "is_public" => "0",
        'sequential' => 1,
      ];
      $result = civicrm_api3('CustomGroup', 'Create', $params);
    }

    // create the custom fields
    $params = [
      'custom_group_id' => "Politician_Details",
      'name' => 'Political_Party',
      'sequential' => 1,
    ];
    $result = civicrm_api3('CustomField', 'get', $params);
    if ($result['count'] == 0) {
      // create the option group
      $optionGroup = civicrm_api3('OptionGroup', 'create', ['sequential' => 1, 'name' => 'political_parties', 'title' => 'Political Parties']);
      $sql = "select distinct concat(country, ' - ', party) party from tmpepha_import_meps where party not in ('[NULL]', '-') order by 1";
      $dao = CRM_Core_DAO::executeQuery($sql);
      $i = 1;
      while ($dao->fetch()) {
        $paramsValue = [
          "option_group_id" => $optionGroup['id'],
          "label" => $dao->party,
          "value" => $i++,
        ];
        civicrm_api3('OptionValue', 'create', $paramsValue);
      }

      // create the field
      $params = [
        "custom_group_id" => "Politician_Details",
        "name" => "Political_Party",
        "label" => "Political Party",
        "data_type" => "Int",
        "html_type" => "Select",
        "is_required" => "0",
        "is_searchable" => "1",
        "is_search_range" => "0",
        "weight" => "1",
        "is_active" => "1",
        "is_view" => "0",
        "text_length" => "255",
        "note_columns" => "60",
        "note_rows" => "4",
        "column_name" => "political_party",
        "option_group_id" => $optionGroup['id'],
        "in_selector" => "0"
      ];
      civicrm_api3('CustomField', 'create', $params);
    }

    $params = [
      'custom_group_id' => "Politician_Details",
      'name' => 'Constituency',
      'sequential' => 1,
    ];
    $result = civicrm_api3('CustomField', 'get', $params);
    if ($result['count'] == 0) {
      $params = [
        "custom_group_id" => "Politician_Details",
        "name" => "Constituency",
        "label" => "Constituency",
        "data_type" => "String",
        "html_type" => "Text",
        "is_required" => "0",
        "is_searchable" => "1",
        "is_search_range" => "0",
        "weight" => "2",
        "is_active" => "1",
        "is_view" => "0",
        "text_length" => "255",
        "note_columns" => "60",
        "note_rows" => "4",
        "column_name" => "constituency",
        "in_selector" => "0"
      ];
      civicrm_api3('CustomField', 'create', $params);
    }
  }

  public static function process_tmpepha_import_meps_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmpepha_import_meps
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      $params = [];
      $contactID = 0;

      // check if the mep exists
      $contact = civicrm_api3('Contact', 'get', ['external_identifier' => $dao->id, 'sequential' => 1]);
      if ($contact['count'] > 0) {
        $contactID = $contact['values'][0]['id'];
      }
      else {
        // try to find by name
        $contact = civicrm_api3('Contact', 'get', ['first_name' => $dao->first_name, 'last_name' => $dao->last_name, 'sequential' => 1]);
        $contactID = $contact['id'];
      }

      // update or create the contact
      $params = [
        'sequential' => 1,
        'external_identifier' => $dao->id,
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
        'source' => 'MEP Import August 2019',
        'employer_id' => 455,
        'job_title' => 'MEP',
        'contact_type' => 'Individual',
        'contact_sub_type' => ['Politician'],
      ];

      if ($contactID > 0) {
        $params['id'] = $contactID;
      }

      if ($dao->prefix == 'Mr') {
        $params['prefix_id'] = 3;
      }
      elseif ($dao->prefix == 'Ms') {
        $params['prefix_id'] = 2;
      }
      elseif ($dao->prefix == 'Mrs') {
        $params['prefix_id'] = 1;
      }
      elseif ($dao->prefix == 'Dr') {
        $params['prefix_id'] = 4;
      }
      elseif ($dao->prefix == 'Prof') {
        $params['prefix_id'] = 5;
      }

      // create or update the contact
      $result = civicrm_api3('Contact', 'create', $params);
      $contactID = $result['id'];

      // update the start date of the employer/employee relationship
      if ($dao->empl_start_date) {
        $sql = "update civicrm_relationship set start_date = %1 where contact_id_a = %2 and contact_id_b = 455 and relationship_type_id = 5";
        $sqlParams = [
          1 => [$dao->empl_start_date, 'String'],
          2 => [$contactID, 'Integer'],
        ];
        CRM_Core_DAO::executeQuery($sql, $sqlParams);
      }

      // add to the country group, and political group
      self::add_to_group($contactID, 'MEP Country - ' . $dao->country);
      self::add_to_group($contactID, $dao->eu_group);

      // add the email address
      self::add_email($contactID, $dao->work_email, 2);
      self::add_email($contactID, $dao->other_email, 4);

      // add the phone
      self::add_phone($contactID, $dao->phone);

      // add the twitter url
      self::add_url($contactID, $dao->twitter);

      // add the poltical party and constituency
      self::add_local_politics($contactID, $dao->constituency, $dao->country . ' - ' . $dao->party);

      // add the EP committees relationships
      $rels = ['AGRI', 'EMPL', 'ENVI', 'IMCO', 'INTA', 'ITRE', 'TRAN'];
      foreach ($rels as $rel) {
        self::add_ep_comm($contactID, $dao->$rel, $dao->{strtolower($rel) . '_rel1'});
        self::add_ep_comm($contactID, $dao->$rel, $dao->{strtolower($rel) . '_rel2'});
      }
    }

    return TRUE;
  }

  public static function add_ep_comm($contactID, $committee, $role) {
    if ($role) {
      // get the committee ID
      $params = [
        'organization_name' => $committee,
        'contact_type' => 'Organization',
        'contact_sub_type' => ['EP_Committee'],
        'sequential' => 1,
      ];
      $comm = civicrm_api3('Contact', 'get', $params);
      if ($comm['count'] > 0) {
        $commID = $comm['values'][0]['id'];
      }
      else {
        // create the committee
        $comm = civicrm_api3('Contact', 'create', $params);
        $commID = $comm['id'];
      }

      // get the relationship ID
      $params = [
        'name_a_b' => "EP_{$role}_Committee_",
        'sequential' => 1,
      ];
      $rel = civicrm_api3('RelationshipType', 'get', $params);
      if ($rel['count'] > 0) {
        $relID = $rel['values'][0]['id'];
      }
      else {
        $params["label_a_b"] = "EP {$role} (Committee)";
        $params["name_b_a"] = "EP_{$role}_Committee_";
        $params["label_b_a"] = "EP {$role} (Committee)";
        $params["contact_type_a"] = "Individual";
        $params["contact_type_b"] = "Organization";
        $params["contact_sub_type_b"] = "EP_Committee";
        $params["is_active"] = "1";
        $rel = civicrm_api3('RelationshipType', 'create', $params);
        $relID = $rel['id'];
      }

      // check if the relationship exists
      $params = [
        "contact_id_a" => $contactID,
        "contact_id_b" => $commID,
        'relationship_type_id' => $relID,
        'is_active' => 1,
      ];
      $rel = civicrm_api3('Relationship', 'get', $params);
      if ($rel['count'] == 0) {
        // create it
        $rel = civicrm_api3('Relationship', 'create', $params);
      }
    }
  }

  public static function add_local_politics($contactID, $constituency, $party) {
    if (empty($constituency)) {
      $constituency = 'N/A';
    }
    if (empty($party)) {
      $party = '-';
    }

    // get the id of the political party
    $sql = "
      select 
        v.value 
      from
        civicrm_option_group g 
      inner join
        civicrm_option_value v on g.id = v.option_group_id
      WHERE
        g.name = 'political_parties'
      and 
        v.label = %1
    ";
    $sqlParams = [
      1 => [$party, 'String'],
    ];
    $polID = CRM_Core_DAO::singleValueQuery($sql, $sqlParams);

    if ($polID) {
      // check if we have a record
      $sql = "select id from civicrm_value_politician_details where entity_id = $contactID";
      $id = CRM_Core_DAO::singleValueQuery($sql);

      if ($id) {
        $sql = "
        update
          civicrm_value_politician_details
        set
          political_party = %1
          , constituency = %2
        where
          id = $id
      ";
        $sqlParams = [
          1 => [$polID, 'Integer'],
          2 => [$constituency, 'String'],
        ];
        CRM_Core_DAO::executeQuery($sql, $sqlParams);
      }
      else {
        $sql = "
        insert into
          civicrm_value_politician_details
        (entity_id, political_party, constituency)
        values
           (%3, %1, %2)      
      ";
        $sqlParams = [
          1 => [$polID, 'Integer'],
          2 => [$constituency, 'String'],
          3 => [$contactID, 'Integer'],
        ];
        CRM_Core_DAO::executeQuery($sql, $sqlParams);
      }
    }
  }

  public static function add_url($contactID, $url) {
    if ($url && $url != '-') {
      // check if we have that twitter url
      $params = [
        'website_type_id' => 11,
        'contact_id' => $contactID,
        'sequential' => 1,
      ];
      $result = civicrm_api3('Website', 'get', $params);
      if ($result['count'] >= 1) {
        $params['id'] = $result['values'][0]['id'];
      }

      $params['url'] = $url;

      // create or update the Website
      civicrm_api3('Website', 'create', $params);
    }
  }

  public static function add_phone($contactID, $phone) {
    if ($phone) {
      // check if we have a work phone
      $params = [
        'location_type_id' => 2,
        'phone_type_id' => 1,
        'contact_id' => $contactID,
        'sequential' => 1,
      ];
      $result = civicrm_api3('Phone', 'get', $params);
      if ($result['count'] >= 1) {
        $params['id'] = $result['values'][0]['id'];
      }

      $params['phone'] = $phone;

      // create or update the phone
      civicrm_api3('Phone', 'create', $params);
    }
  }

  public static function add_email($contactID, $email, $locationTypeID) {
    if ($email) {
      // check if we have that email address
      $params = [
        'location_type_id' => $locationTypeID,
        'contact_id' => $contactID,
        'sequential' => 1,
      ];
      $result = civicrm_api3('Email', 'get', $params);
      if ($result['count'] >= 1) {
        $params['id'] = $result['values'][0]['id'];
      }

      $params['email'] = $email;

      // create or update the email
      civicrm_api3('Email', 'create', $params);
    }
  }

  public static function add_to_group($contactID, $group) {
    // get the group id
    $groupID = CRM_Core_DAO::singleValueQuery("select id from civicrm_group where title = %1", [1 => [$group, 'String']]);
    if (!$groupID) {
      throw new Exception("$group not found");
    }

    // check if the contact is in that group
    $params = [
      'group_id' => $groupID,
      'contact_id' => $contactID,
      'status' => 'Added',
    ];
    $result = civicrm_api3('GroupContact', 'get', $params);
    if ($result['count'] == 0) {
      unset($params["status"]);

      // add the contact to the group
      civicrm_api3('GroupContact', 'create', $params);
    }
  }

  public static function process_tmpepha_events_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmpepha_events
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      $params = [];

      // check if the event exists
      $event = civicrm_api3('Event', 'get', ['title' => $dao->title, 'sequential' => 1]);
      if ($event['count'] > 0) {
        // store the ID
        $params['id'] = $event['values'][0]['id'];
      }

      $params['title'] = $dao->title;
      $params['event_type_id'] = 1;
      $params['start_date'] = $dao->start_date . ' 09:00';
      $params['end_date'] = $dao->start_date . ' 17:00';
      civicrm_api3('Event', 'create', $params);
    }

    return TRUE;
  }

  public static function process_tmpepha_org_list_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmpepha_org_list
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      $params = [];

      $orgID = self::getOrCreateOrganization($dao->organization, $dao->country, $dao->city, TRUE);
    }

    return TRUE;
  }

  public static function createAddress($contactID, $country, $city) {
    $params = [];

    $searchParams = [
      'sequential' => 1,
      'contact_id' => $contactID,
      'is_primary' => 1,
    ];
    $searchAddress = civicrm_api3('Address', 'get', $searchParams);
    if ($searchAddress['count'] > 0) {
      // store the ID
      $params['id'] = $searchAddress['values'][0]['id'];
    }
    else {
      $params['location_type_id'] = 2;
    }

    // create or update the address
    $params['contact_id'] = $contactID;
    $params['city'] = $city ? $city : '';
    $params['country'] = $country ? CRM_Core_DAO::singleValueQuery('select iso_code from civicrm_country where name = %1', [1 => [$country, 'String']]) : '';

    civicrm_api3('Address', 'create', $params);
  }

  public static function process_tmpepha_main_list_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmpepha_main_list
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      if (self::onBlacklist($dao->email)) {
        return TRUE;
      }

      $params = [];

      // get the organization id (org will be created if it does not exist)
      if ($dao->organization) {
        $employer_id = self::getOrCreateOrganization($dao->organization, $dao->country, '', FALSE);
      }
      else {
        $employer_id = 0;
      }

      // check if the person exists via email lookup
      $sqlSearch = "
        select
          max(c.id)
        from
          civicrm_contact c
        inner join 
          civicrm_email e on e.contact_id = c.id
        where
          c.contact_type = 'Individual'
        and 
          e.email = %1
      ";
      $cid = CRM_Core_DAO::singleValueQuery($sqlSearch, [1 => [$dao->email, 'String']]);
      if ($cid) {
        $params['id'] = $cid;
      }

      if ($dao->first_name || $dao->last_name) {
        $params['first_name'] = $dao->first_name;
        $params['last_name'] = $dao->last_name;
      }
      else {
        // fix contacts without name
        $params['first_name'] = $dao->email;
      }

      $params['job_title'] = $dao->job_title;
      $params['contact_type'] = 'Individual';
      $params['source'] = 'ephaimport';
      $params['api.email.create'] = [
        'email' => $dao->email,
        'location_Type_id' => 2,
      ];

      if ($employer_id) {
        $params['employer_id'] = $employer_id;
      }

      // process newsletter preferences
      if ($dao->newsletters) {
        self::addNewsletterPreferences($dao->newsletters, $params);
      }

      // create or update the contact
      $contact = civicrm_api3('Contact', 'create', $params);

      // for new contacts:
      if (!$cid) {
        // see if this contact registered for an event
        if ($dao->AC17 == 'Y') {
          self::registerPaticipant('AC 17', $contact['id']);
        }
        if ($dao->Gamechangers == 'Y') {
          self::registerPaticipant('Game changers', $contact['id']);
        }
        if ($dao->Event) {
          self::registerPaticipant($dao->Event, $contact['id']);
        }

        // create the opt-in relationship
        self::createActivity($contact['id'], 55, 'MailChimp opt-in', '', $dao->OPTIN_TIME);

        // add note
        if ($dao->NOTES) {
          self::createNote($contact['id'], $dao->NOTES);
        }
      }
    }

    return TRUE;
  }

  public static function createNote($contactID, $note) {
    $params = [
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contactID,
      'note' => $note,
      'modified_date' => '2019-01-01',
      'subject' => 'Imported from MailChimp',
    ];
    civicrm_api3('Note', 'create', $params);
  }

  public static function createActivity($contactID, $activityID, $subject, $details, $date) {
    // create an activity
    $params = [
      'activity_type_id' => $activityID,
      'subject' => $subject,
      'activity_date_time' => $date,
      'is_test' => 0,
      'status_id' => 2,
      'priority_id' => 2,
      'details' => $details,
      'source_contact_id' => $contactID,
      'target_contact_id' => $contactID,
    ];
    CRM_Activity_BAO_Activity::create($params);
  }

  public static function getOrCreateOrganization($organization, $country, $city, $overwriteAddress) {
    // check if the contact exists
    $searchParams = [
      'sequential' => 1,
      'contact_type' => 'Organization',
      'organization_name' => $organization,
    ];
    $contact = civicrm_api3('Contact', 'get', $searchParams);
    if ($contact['count'] > 0) {
      // store the ID
      $params['id'] = $contact['values'][0]['id'];
    }

    // create or update the contact
    $params['organization_name'] = $organization;
    $params['contact_type'] = 'Organization';
    $params['source'] = 'ephaimport';
    $contact = civicrm_api3('Contact', 'create', $params);

    // create or update the address
    if ($overwriteAddress && ($city || $country)) {
      self::createAddress($contact['id'], $country, $city);
    }

    return $contact['id'];
  }

  public static function registerPaticipant($event, $contactID) {
    // get event id
    $eventID = CRM_Core_DAO::singleValueQuery("select id from civicrm_event where title = %1", [1 => [$event, 'String']]);

    if ($eventID) {
      // make sure we don't have it yet
      $n = CRM_Core_DAO::singleValueQuery("select count(*) from civicrm_participant where contact_id = $contactID and event_id = $eventID");
      if ($n == 0) {
        $params = [
          'event_id' => $eventID,
          'contact_id' => $contactID,
          'role_id' => 1,
          'status_id' => 2,
          'register_date' => '2000-01-01 00:00',
        ];
        civicrm_api3('Participant', 'create', $params);
      }
    }
    else {
      watchdog('alain', "cannot find event = $event");
    }
  }

  public static function addNewsletterPreferences($newsletters, &$params) {
    $NEWSLETTER = 'custom_1';
    $FOOD = 'custom_2';
    $AMR = 'custom_3';
    $EVENTS = 'custom_4';

    if (strpos($newsletters, 'EPHA Newsletter') !== FALSE) {
      $params[$NEWSLETTER] = 1;
    }
    if (strpos($newsletters, 'EPHA Food Feed') !== FALSE) {
      $params[$FOOD] = 1;
    }
    if (strpos($newsletters, 'EPHA AMR Feed') !== FALSE) {
      $params[$AMR] = 1;
    }
    if (strpos($newsletters, 'EPHA Events') !== FALSE) {
      $params[$EVENTS] = 1;
    }
  }

  public static function process_tmpepha_press_list_task(CRM_Queue_TaskContext $ctx, $id) {
    $params = [];

    $sql = "
      SELECT
        *
      FROM
        tmpepha_press_list
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      if (self::onBlacklist($dao->email)) {
        return TRUE;
      }

      // check if the person exists via email lookup
      $sqlSearch = "
        select
          max(c.id)
        from
          civicrm_contact c
        inner join 
          civicrm_email e on e.contact_id = c.id
        where
          c.contact_type = 'Individual'
        and 
          e.email = %1
      ";
      $cid = CRM_Core_DAO::singleValueQuery($sqlSearch, [1 => [$dao->email, 'String']]);
      if ($cid) {
        // existing contact!

        $params['id'] = $cid;

        // overwrite the first name (if we have one)
        if ($dao->first_name) {
          $params['first_name'] = $dao->first_name;
        }

        // overwrite the last name (if we have one)
        if ($dao->last_name) {
          $params['last_name'] = $dao->last_name;
        }

        if (count($params) > 1) {
          // update the contact
          $contact = civicrm_api3('Contact', 'create', $params);
        }
        else {
          $contact = [];
          $contact['id'] = $cid;
        }
      }
      else {
        // new contact!

        if ($dao->first_name || $dao->last_name) {
          $params['first_name'] = $dao->first_name;
          $params['last_name'] = $dao->last_name;
        }
        else {
          // fix contacts without name
          $params['first_name'] = $dao->email;
        }

        $params['contact_type'] = 'Individual';
        $params['source'] = 'ephaimport';
        $params['api.email.create'] = [
          'email' => $dao->email,
          'location_Type_id' => 2,
        ];

        // create the contact
        $contact = civicrm_api3('Contact', 'create', $params);
      }

      // add the contact to the press group
      civicrm_api3("GroupContact", 'create', [
        'contact_id' => $contact['id'],
        'group_id' => 2,
      ]);
    }

    return TRUE;
  }

  public static function onBlacklist($email) {
    $split = explode('@', $email);
    $sql = "
      select
        count(*)
      from
        tmpepha_blacklist
      where 
        email_domain = %1
    ";
    $sqlParams = [
      1 => ['@' . $split[1], 'String'],
    ];
    if (CRM_Core_DAO::singleValueQuery($sql, $sqlParams)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

}
