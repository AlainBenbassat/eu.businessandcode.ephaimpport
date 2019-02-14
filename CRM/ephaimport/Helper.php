<?php

class CRM_ephaimport_Helper {
  public static function onEnd(CRM_Queue_TaskContext $ctx) {
    CRM_Core_Session::setStatus('Done.', 'Queue', 'success');
  }

  public static function createConfig() {
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
        'group_id' => 15,
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