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

      // see if this contact registered for an event
      if ($dao->AC17 == 'Y') {
        self::registerPaticipant('AC 17', $contact['id']);
      }
      if ($dao->Event) {
        self::registerPaticipant($dao->Event, $contact['id']);
      }
    }

    return TRUE;
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
    return TRUE;
  }

}