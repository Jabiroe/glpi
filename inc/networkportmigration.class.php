<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2012 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file: Damien Touraine
// Purpose of file:
// ----------------------------------------------------------------------

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/// NetworkPortMigration class : class of unknown objects defined inside the NetworkPort before 0.84
/// @TODO we may have to add massive action for changing the type of a networkport ...
/// @since 0.84
class NetworkPortMigration extends CommonDBChild {

   // From CommonDBChild
   public $itemtype  = 'itemtype';
   public $items_id  = 'items_id';


   static function getTypeName($nb=0) {
      return __('Network port migration');
   }

   function canCreate() {
      return false;
   }


   function canView() {

      if (isset($this->fields['itemtype'])) {
         if ($item = getItemForItemtype($this->fields['itemtype'])) {
            return $item->canView();
         }
      }
      return true;
   }


   function canUpdate() {

      if (isset($this->fields['itemtype'])) {
         if ($item = getItemForItemtype($this->fields['itemtype'])) {
            return $item->canUpdate();
         }
      }
      return true;
   }


   function canDelete() {
      return true;
   }


   private function cleanDatabase() {
      global $DB;

      $networkport = new NetworkPort();

      if ($networkport->getFromDB($this->getID())) {

         if (!in_array($networkport->fields['instantiation_type'],
                       NetworkPort::getNetworkPortInstantiations())) {

            $networkport->delete($networkport->fields);
         }
      }

      if (countElementsInTable($this->getTable()) == 0) {
         $query = "DROP TABLE `".$this->getTable()."`";
         $DB->query($query);
      }

   }


   function post_purgeItem() {
      $this->cleanDatabase();
   }


   function post_deleteItem() {
      $this->cleanDatabase();
   }


   static function getMotives() {

      return array( 'unknown_interface_type'
                              => __('Undefined interface'),
                    'invalid_network'
                              => __('Invalid network (already defined or with invalid addresses)'),
                    'invalid_gateway'
                              => __('Gateway not include inside the network'),
                    'invalid_address'
                              => __('Invalid IP address') );
   }


   function showForm($ID, $options=array()) {
      global $CFG_GLPI, $DB;

      if (!Session::haveRight("networking", "r")) {
         return false;
      }

      $this->check($ID,'r');

      $recursiveItems = $this->recursivelyGetItems();
      if (count($recursiveItems) > 0) {
         $lastItem             = $recursiveItems[count($recursiveItems) - 1];
         $lastItem_entities_id = $lastItem->getField('entities_id');
      } else {
         $lastItem_entities_id = $_SESSION['glpiactive_entity'];
      }

      $options['entities_id'] = $lastItem_entities_id;
      $this->showFormHeader($options);

      $options['canedit'] = false;
      $options['candel'] = false;

      $number_errors = 0;
      foreach (self::getMotives() as $key => $name) {
         if ($this->fields[$key] == 1) {
            $number_errors ++;
         }
      }

      $motives = self::getMotives();

      $interface_cell = "td";
      $address_cell   = "td";
      $network_cell   = "td";
      $gateway_cell   = "td";

      $address = new IPAddress();
      $netmask = new IPNetmask();

      $number_real_errors = 0;

      if ((!$address->setAddressFromString($this->fields['ip']))
          || (!$netmask->setNetmaskFromString($this->fields['netmask'],
                                              $address->getVersion()))) {
         unset ($address);
         unset ($netmask);
      } else {
         $network = new IPNetwork();

         $params = array("address" => $address,
                         "netmask" => $netmask);
         if (isset($this->fields["address"])) {
            $params["exclude IDs"] = $this->fields["address"];
         }

         if (isset($this->fields["entities_id"])) {
            $entity = $this->fields["entities_id"];
         } else {
            $entity = -1;
         }
         $networkports_ids = IPNetwork::searchNetworks("equals", $params, $entity, false);

         if (count($networkports_ids) == 0) {
            unset($network);
         } else {
            $network->getFromDB($networkports_ids[0]);
         }
      }

      if ($this->fields['unknown_interface_type'] == 1) {
         $options['canedit'] = true;
         $number_real_errors ++;
         $interface_cell = "th";

         echo "<tr class='tab_bg_1'><th>". $motives['unknown_interface_type'] ."</th>\n".
              "<td>" .__('Transform this network port to');
         echo "</td><td colspan=2>";
         Dropdown::showItemTypes('transform_to', NetworkPort::getNetworkPortInstantiations(),
                                 array('value' => "NetworkPortEthernet"));

         echo "</td></tr>\n";
      }

      if ($this->fields['invalid_network'] == 1) {
         $number_real_errors ++;
         $network_cell = "th";
         $address_cell = "th";
         echo "<tr class='tab_bg_1'><th>" .$motives['invalid_network'] ."</th>\n<td colspan=3>";
         if (isset($network)) {
            printf(__('Network port information conflicting with %s'), $network->getLink());
         } else {
            if (!isset($address) || !isset($netmask)) {
               _e('Invalid address or netmask');
            } else {
               _e('No conflicting network');
            }
            echo "&nbsp;<a href='".Toolbox::getItemTypeFormURL('IPNetwork')."'>" .
                  __('you may have to add a network')."</a>";
         }
         echo "</td></tr>\n";
       }

      if ($this->fields['invalid_gateway'] == 1) {
         $number_real_errors ++;
         $gateway_cell = "th";
         echo "<tr class='tab_bg_1'><th>" . $motives['invalid_gateway'] ."</th>\n<td colspan=3>";
         if (isset($network)) {
            printf(__('Append a correct gateway to the network %s'), $network->getLink());
         } else {
            printf(__('%1$s: %2$s'), __('Unknown network'),
                   "<a href='".Toolbox::getItemTypeFormURL('IPNetwork')."'>".__('Add a network')."
                    </a>");
         }
         echo "</td></tr>\n";
      }

      if ($this->fields['invalid_address'] == 1) {
         $number_real_errors ++;
         $address_cell = "th";
         echo "<tr class='tab_bg_1'><th>" .$motives['invalid_address'] ."</th>\n<td colspan=3>";
         $networkPort = new NetworkPort();
         if ($networkPort->getFromDB($this->getID())) {
            $number_real_errors ++;
            echo "<a href='".$networkPort->getLinkURL()."'>".
                   __('Add a correct IP to the network port') . "</a>";
         } else {
            _e('Unknown network port');
         }
         echo "</td></tr>\n";
      }

       if ($number_real_errors == 0) {
         echo "<tr class='tab_bg_1'><th colspan=4>" .
              __('I don\'t understand why this migration error is not deleted.') .
              "<a href='".$this->getLinkURL()."&delete=1'>" .
              __('You can delete this migration error') . "</a></th></tr>\n";
       } else {
          echo "<tr class='tab_bg_1'><th>" . __('At all events') . "</th>\n";
          echo "<td colspan=3>" .
               "<a href='".$this->getLinkURL()."&delete=1'>" .
               __('You can delete this migration error') . "</a></td></tr>\n";
       }

      echo "<tr class='tab_bg_1'><td colspan='4'>&nbsp;</td></tr>\n";

      echo "<tr class='tab_bg_1'><th colspan='4'>" .__('Original network port information') ."</th>".
           "</tr>\n";

      echo "<tr class='tab_bg_1'><td>";
      $this->displayRecursiveItems($recursiveItems, 'Type');
      echo "</td>\n<td>";
      $this->displayRecursiveItems($recursiveItems, "Link");
      echo "</td>\n";

      echo "<td>".__('Comments')."</td>";
      echo "<td class='middle'>" . $this->fields["comment"] . "</td></tr>\n";

      echo "<tr class='tab_bg_1'><td>". __('Network address') ."</td>\n";
      echo "<$network_cell>" . $this->fields['subnet'] . "</$network_cell>\n";

      echo "<td>". IPNetmask::getTypeName(1) ."</td>\n";
      echo "<$network_cell>" . $this->fields['netmask'] . "</$network_cell></tr>\n";

      echo "<tr class='tab_bg_1'><td>". IPAddress::getTypeName(1) ."</td>\n";
      echo "<$address_cell>" . $this->fields['ip'] . "</$address_cell>\n";

      echo "<td>". __('Gateway') ."</td>\n";
      echo "<$gateway_cell>" . $this->fields['gateway'] . "</$gateway_cell></tr>\n";

      echo "<tr class='tab_bg_1'><td>". __('Network interface') ."</td><$interface_cell>\n";
      if (TableExists('glpi_networkinterfaces')) {
         $query = "SELECT `name`
                   FROM `glpi_networkinterfaces`
                   WHERE `id`='".$this->fields['networkinterfaces_id']."'";
         $result = $DB->query($query);
         if ($DB->numrows($result) > 0) {
            $row = $DB->fetch_assoc($result);
            echo $row['name'];
         } else {
            _e('Unknown interface');
         }
      }
      echo "</$interface_cell></tr>\n";

      $this->showFormButtons($options);
      $this->addDivForTabs();
   }


   function getSearchOptions() {
      global $CFG_GLPI;

      $tab         = parent::getSearchOptions();

      $optionIndex = 10;
      // From 10 to 14
      foreach (self::getMotives() as $motive => $name) {
         $tab[$optionIndex]['table']      = $this->getTable();
         $tab[$optionIndex]['field']      = $motive;
         $tab[$optionIndex]['name']       = $name;
         $tab[$optionIndex]['datatype']   = 'bool';

         $optionIndex ++;
      }

      $tab[20]['table']         = $this->getTable();
      $tab[20]['field']         = 'ip';
      $tab[20]['name']          = IPAddress::getTypeName(1);

      $tab[21]['table']         = $this->getTable();
      $tab[21]['field']         = 'netmask';
      $tab[21]['name']          = IPNetmask::getTypeName(1);

      $tab[22]['table']         = $this->getTable();
      $tab[22]['field']         = 'subnet';
      $tab[22]['name']          = __('Network address');

      $tab[23]['table']         = $this->getTable();
      $tab[23]['field']         = 'gateway';
      $tab[23]['name']          = IPAddress::getTypeName(1);

      if (TableExists('glpi_networkinterfaces')) {
         $tab[24]['table']         = 'glpi_networkinterfaces';
         $tab[24]['field']         = 'name';
         $tab[24]['name']          = __('Network interface');
      }

      return $tab;
   }


   /**
    * @param $group           HTMLTable_Group object
    * @param $super           HTMLTable_SuperHeader object
    * @param $options   array
   **/
   static function getInstantiationHTMLTable_Headers(HTMLTable_Group $group,
                                                     HTMLTable_SuperHeader $super,
                                                     $options=array()) {
   }

}
?>