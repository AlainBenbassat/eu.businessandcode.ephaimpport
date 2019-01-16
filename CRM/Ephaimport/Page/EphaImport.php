<?php
use CRM_Ephaimport_ExtensionUtil as E;

class CRM_Ephaimport_Page_EphaImport extends CRM_Core_Page {

  /**
   * This is the import main page.
   * The corresponding .tpl file contains the menu.
   */
  public function run() {
    CRM_Utils_System::setTitle(E::ts('Epha Import'));

    parent::run();
  }

}
