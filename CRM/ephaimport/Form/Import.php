<?php

use CRM_ephaimport_ExtensionUtil as E;

class CRM_ephaimport_Form_Import extends CRM_Core_Form {
  private $queue;
  private $queueName = 'ephaimport';

  public function __construct() {
    // create the queue
    $this->queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => $this->queueName,
      'reset' => FALSE, //do not flush queue upon creation
    ]);

    parent::__construct();
  }

  public function buildQuickForm() {
    $maintenanceMenuOptions = [
      'delq' => 'Delete Queue (items: ~' . $this->queue->numberOfItems() . ')',
      'config' => 'Create Configuration',
    ];
    $this->addRadio('maintenance', 'Maintenance:', $maintenanceMenuOptions, NULL, '<br>');

    $importMenuOptions = [
      'tmpepha_events' => 'Import Events',
      'tmpepha_org_list' => 'Import Organizations',
      'tmpepha_main_list' => 'Import Main List',
      'tmpepha_press_list' => 'Import Press List',
    ];
    $this->addRadio('import', 'Import:', $importMenuOptions, NULL, '<br>');

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();

    if ($values['maintenance'] == 'delq') {
      $this->queue->deleteQueue();
    }
    elseif ($values['maintenance'] == 'config') {

    }
    elseif ($values['import'] !== '') {
      // put items in the queue
      $sql = "select id from " . $values['import'];
      $dao = CRM_Core_DAO::executeQuery($sql);
      while ($dao->fetch()) {
        $method = 'process_' . $values['import'] . '_task';
        $task = new CRM_Queue_Task(['CRM_ephaimport_Helper', $method], [$dao->id]);
        $this->queue->createItem($task);
      }

      // run the queue
      $runner = new CRM_Queue_Runner([
        'title' => 'EPHA Import',
        'queue' => $this->queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_CONTINUE,
        'onEnd' => ['CRM_ephaimport_Helper', 'onEnd'],
        'onEndUrl' => CRM_Utils_System::url('civicrm/ephaimport', 'reset=1'),
      ]);
      $runner->runAllViaWeb();
    }

    // explicit redirect to this same form, rather than the implicit redirect, to make sure the constructor is re-executed
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/ephaimport', 'reset=1'));
    parent::postProcess();
  }

  public function getRenderableElementNames() {
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }


}
